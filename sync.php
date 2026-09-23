<?php
// sync.php — copies Open Dental data into GoHighLevel (GHL).
//
//   Patients      -> GHL contacts (created or updated)
//   Appointments  -> GHL calendar appointments (created or updated, incl. cancellations)
//
// Called by sync.html (needs the SYNC_SECRET in the X-Sync-Key header)
// and by webhook.php for automatic sync.
//
// Links between Open Dental and GHL records are saved in data/sync_map.json,
// so running it again updates the same GHL records instead of making duplicates.

require __DIR__ . '/config.php';

const GHL_BASE      = 'https://services.leadconnectorhq.com/';
const MAP_FILE      = __DIR__ . '/data/sync_map.json';
const STATE_FILE    = __DIR__ . '/data/sync_state.json';
const BATCH_SIZE    = 25;   // records per web request (keeps shared hosting under its time limit)

$IS_CLI = false;
set_time_limit(120);

if (defined('SYNC_LIB_ONLY')) {
    // Included by webhook.php: only load the functions below
} else {
    header('Content-Type: application/json');
    $key = $_SERVER['HTTP_X_SYNC_KEY'] ?? '';
    if (SYNC_SECRET === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' || !hash_equals(SYNC_SECRET, $key)) {
        http_response_code(401);
        echo json_encode(['error' => 'Wrong or missing sync password (set SYNC_SECRET in config.php).']);
        exit;
    }
}

// ============================================================ HTTP helpers

function http_json($method, $url, $headers, $body = null) {
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    if (defined('OD_CA_FILE') && OD_CA_FILE !== '') {
        $opts[CURLOPT_CAINFO] = OD_CA_FILE;
    }

    // Retry when rate-limited (HTTP 429)
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new Exception("cURL error: $err");
        if ($code == 429) { sleep(2 + $attempt * 2); continue; }
        if ($code == 400 && strpos($raw, 'Calendar is inactive') !== false) {
            throw new Exception('GHL says the calendar is inactive. Click "Check calendar" on the sync page: the calendar needs a team member (owner) with availability, or set GHL_ASSIGNED_USER_ID in config.php.');
        }
        if ($code < 200 || $code >= 300) {
            throw new Exception("HTTP $code from " . parse_url($url, PHP_URL_HOST) . ": " . substr($raw, 0, 500));
        }
        return $raw === '' ? [] : json_decode($raw, true);
    }
    throw new Exception('Rate limited too many times, try again in a minute.');
}

function od($path, $params = []) {
    $url = OD_BASE_URL . $path . ($params ? '?' . http_build_query($params) : '');
    return http_json('GET', $url, [
        'Authorization: ODFHIR ' . OD_DEVELOPER_KEY . '/' . OD_CUSTOMER_KEY,
        'Content-Type: application/json',
    ]);
}

function od_send($method, $path, $body = null) {
    return http_json($method, OD_BASE_URL . $path, [
        'Authorization: ODFHIR ' . OD_DEVELOPER_KEY . '/' . OD_CUSTOMER_KEY,
        'Content-Type: application/json',
    ], $body);
}

function od_all($path, $params = []) {
    $all = [];
    $offset = 0;
    for ($i = 0; $i < 300; $i++) {
        $params['Offset'] = $offset;
        $page = od($path, $params);
        if (!is_array($page) || !$page) break;
        $all = array_merge($all, $page);
        if (count($page) < 100) break;
        $offset += count($page);
    }
    return $all;
}

function ghl($method, $path, $body = null, $version = '2021-07-28') {
    usleep(150000); // stay well under GHL's 100 requests / 10 seconds
    return http_json($method, GHL_BASE . $path, [
        'Authorization: Bearer ' . GHL_TOKEN,
        'Version: ' . $version,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $body);
}

// ============================================================ Saved links

function load_json($file, $default) {
    if (!file_exists($file)) return $default;
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : $default;
}
function save_json($file, $data) {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

$MAP = load_json(MAP_FILE, ['patients' => [], 'appointments' => []]);

// ============================================================ Mapping

function clean_date($d) {
    return ($d && strpos($d, '0001-01-01') !== 0) ? $d : null;
}

function phone_e164($p) {
    $digits = preg_replace('/\D+/', '', (string)$p);
    if (strlen($digits) === 10) return '+1' . $digits;
    if (strlen($digits) === 11 && $digits[0] === '1') return '+' . $digits;
    return $digits ? '+' . $digits : null;
}

function patient_to_contact($p) {
    $phone = phone_e164($p['WirelessPhone'] ?? '') ?: phone_e164($p['HmPhone'] ?? '') ?: phone_e164($p['WkPhone'] ?? '');
    $c = [
        'firstName'   => $p['Preferred'] ?: ($p['FName'] ?? ''),
        'lastName'    => $p['LName'] ?? '',
        'email'       => filter_var($p['Email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
        'phone'       => $phone,
        'dateOfBirth' => clean_date($p['Birthdate'] ?? null),
        'address1'    => $p['Address'] ?? null,
        'city'        => $p['City'] ?? null,
        'state'       => $p['State'] ?? null,
        'postalCode'  => $p['Zip'] ?? null,
        'source'      => defined('CONTACT_SOURCE') ? CONTACT_SOURCE : 'opendental',
    ];
    return array_filter($c, fn($v) => $v !== null && $v !== '');
}

// Open Dental status -> GHL appointment status
function ghl_status($aptStatus) {
    switch ($aptStatus) {
        case 'Scheduled':   return 'confirmed';
        case 'Complete':    return 'showed';
        case 'Broken':
        case 'UnschedList': return 'cancelled';
        default:            return null; // Planned, PtNote etc. are not real bookings
    }
}

function to_iso($localDateTime, $minutes = 0) {
    $d = new DateTime($localDateTime, new DateTimeZone(PRACTICE_TIMEZONE));
    if ($minutes) $d->modify("+$minutes minutes");
    return $d->format('c'); // e.g. 2026-09-25T09:00:00-06:00
}

// Pattern is 5-minute blocks, e.g. "//XXXX//" = 40 minutes
function apt_minutes($pattern) {
    $m = strlen((string)$pattern) * 5;
    return $m > 0 ? $m : 30;
}

// ============================================================ Sync steps

// Create or update one patient in GHL. Returns [contactId, 'created'|'updated'|'matched existing'].
function contact_tag() {
    return defined('CONTACT_TAG') ? CONTACT_TAG : 'opendental';
}

function push_patient($p) {
    global $MAP;
    $patNum  = (string)$p['PatNum'];
    $contact = patient_to_contact($p);
    $existing = $MAP['patients'][$patNum] ?? null;

    if ($existing) {
        try {
            ghl('PUT', "contacts/$existing", $contact);
            ghl('POST', "contacts/$existing/tags", ['tags' => [contact_tag()]]);
            return [$existing, 'updated'];
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'HTTP 404') === false && strpos($e->getMessage(), 'HTTP 400') === false) throw $e;
            // Contact was deleted in GHL, so fall through and create it again
        }
    }

    $res = ghl('POST', 'contacts/upsert', $contact + ['locationId' => GHL_LOCATION_ID]);
    $id  = $res['contact']['id'] ?? null;
    if (!$id) throw new Exception('GHL did not return a contact id');

    ghl('POST', "contacts/$id/tags", ['tags' => [contact_tag()]]);

    $MAP['patients'][$patNum] = $id;
    return [$id, !empty($res['new']) ? 'created' : 'matched existing'];
}

function ensure_contact($patNum) {
    global $MAP;
    if (!empty($MAP['patients'][(string)$patNum])) return $MAP['patients'][(string)$patNum];
    $p = od("patients/$patNum");
    [$id] = push_patient($p);
    return $id;
}

// The user the appointments are assigned to.
// Personal calendars need this, or GHL answers "Calendar is inactive".
function calendar_user_id() {
    static $uid = null;
    if ($uid !== null) return $uid;
    if (defined('GHL_ASSIGNED_USER_ID') && GHL_ASSIGNED_USER_ID !== '') return $uid = GHL_ASSIGNED_USER_ID;
    $res = ghl('GET', 'calendars/' . GHL_CALENDAR_ID, null, '2021-04-15');
    $members = $res['calendar']['teamMembers'] ?? [];
    foreach ($members as $m) {
        if (!empty($m['userId'])) return $uid = $m['userId'];
    }
    return $uid = '';
}

function push_appointment($a) {
    global $MAP;
    $aptNum   = (string)$a['AptNum'];
    $status   = ghl_status($a['AptStatus'] ?? '');
    $existing = $MAP['appointments'][$aptNum] ?? null;
    $when     = clean_date($a['AptDateTime'] ?? null);

    if (!$status)               return ['skipped', 'status ' . $a['AptStatus'] . ' is not synced'];
    if (!$existing && $status === 'cancelled' && !$when)
        return ['skipped', 'cancelled before it was ever synced'];
    if (!$when && !$existing)   return ['skipped', 'no date/time'];

    $contactId = ensure_contact($a['PatNum']);

    $title = trim(($a['ProcDescript'] ?? '') ?: 'Dental appointment');
    $body = [
        'calendarId'               => GHL_CALENDAR_ID,
        'appointmentStatus'        => $status,
        'title'                    => $title,
        'ignoreDateRange'          => true,
        'ignoreFreeSlotValidation' => true,
        'toNotify'                 => false, // don't send GHL reminders/emails automatically
    ];
    if ($when) {
        $body['startTime'] = to_iso($when);
        $body['endTime']   = to_iso($when, apt_minutes($a['Pattern'] ?? ''));
    }
    if (!empty($a['Note'])) $body['description'] = $a['Note'];
    $userId = calendar_user_id();
    if ($userId !== '') $body['assignedUserId'] = $userId;

    if ($existing) {
        try {
            ghl('PUT', "calendars/events/appointments/$existing", $body, '2021-04-15');
            $result = 'updated';
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'HTTP 404') === false) throw $e;
            $existing = null; // deleted in GHL, recreate below
        }
    }
    if (!$existing) {
        $res = ghl('POST', 'calendars/events/appointments',
            $body + ['locationId' => GHL_LOCATION_ID, 'contactId' => $contactId], '2021-04-15');
        $id = $res['id'] ?? ($res['event']['id'] ?? null);
        if (!$id) throw new Exception('GHL did not return an appointment id');
        $MAP['appointments'][$aptNum] = $id;
        $result = 'created';
    }

    if ($status === 'cancelled') {
        ghl('POST', "contacts/$contactId/tags", ['tags' => ['od-cancelled']]);
    }
    return [$result, $status];
}

// ============================================================ Actions

function action_calendars() {
    $res = ghl('GET', 'calendars/?locationId=' . urlencode(GHL_LOCATION_ID), null, '2021-04-15');
    $out = [];
    foreach (($res['calendars'] ?? []) as $c) {
        $out[] = ['id' => $c['id'], 'name' => $c['name'] ?? '', 'active' => $c['isActive'] ?? null];
    }
    return ['calendars' => $out];
}

function action_checkcal() {
    $res = ghl('GET', 'calendars/' . GHL_CALENDAR_ID, null, '2021-04-15');
    $c = $res['calendar'] ?? [];
    $members = array_map(fn($m) => ($m['userId'] ?? '?') . (isset($m['isPrimary']) && $m['isPrimary'] ? ' (primary)' : ''), $c['teamMembers'] ?? []);
    return ['info' => [
        ['k' => 'Name',          'v' => $c['name'] ?? ''],
        ['k' => 'Type',          'v' => $c['calendarType'] ?? ''],
        ['k' => 'Active (API)',  'v' => isset($c['isActive']) ? ($c['isActive'] ? 'yes' : 'NO') : 'unknown'],
        ['k' => 'Team members',  'v' => $members ? implode(', ', $members) : 'NONE: add yourself as the owner/team member'],
        ['k' => 'Assigned user used by sync', 'v' => calendar_user_id() ?: 'none'],
    ]];
}

// ---------- Automatic sync (Open Dental API Events) ----------

function webhook_url() {
    if (defined('WEBHOOK_URL') && WEBHOOK_URL !== '') return WEBHOOK_URL;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return $https . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/webhook.php?k=' . urlencode(SYNC_SECRET);
}

const WATCH_TABLES = ['Appointment', 'AppointmentDeleted', 'Patient'];

// Computers at the practice that run Open Dental, most recently active first
function action_computers() {
    $list = (array)od_send('GET', 'computers');
    usort($list, fn($a, $b) => strcmp($b['LastHeartBeat'] ?? '', $a['LastHeartBeat'] ?? ''));
    $out = [];
    foreach ($list as $c) {
        $hb = $c['LastHeartBeat'] ?? '';
        $out[] = ['name' => $c['CompName'] ?? '', 'lastSeen' => strpos($hb, '0001-01-01') === 0 ? 'never / unknown' : $hb,
            'heartbeat' => strpos($hb, '0001-01-01') === 0 ? '' : $hb];
    }
    return ['computers' => $out];
}

function action_subscriptions() {
    $subs = od_send('GET', 'subscriptions');
    $out = [];
    foreach ((array)$subs as $s) {
        $url = $s['EndPointUrl'] ?? '';
        $out[] = [
            'num'   => $s['SubscriptionNum'] ?? '',
            'what'  => ($s['WatchTable'] ?? '') ?: ($s['UiEventType'] ?? ''),
            'every' => $s['PollingSeconds'] ?? '',
            'pc'    => $s['Workstation'] ?? '',
            'ours'  => strpos($url, 'webhook.php') !== false,
            'url'   => preg_replace('/k=[^&]+/', 'k=***', $url),
        ];
    }
    return ['subscriptions' => $out];
}

function action_subscribe() {
    // One or more computers, separated by commas
    $pcs = array_values(array_unique(array_filter(array_map('trim', explode(',', $_GET['workstation'] ?? '')))));
    if (!$pcs) throw new Exception('Enter at least one Workstation (practice computer name).');
    if (count($pcs) > 15) throw new Exception('Please pick 15 computers or fewer.');
    $seconds = max(15, (int)($_GET['seconds'] ?? 60));

    // Remove our old subscriptions first so there are no duplicates
    foreach ((array)od_send('GET', 'subscriptions') as $s) {
        if (strpos($s['EndPointUrl'] ?? '', 'webhook.php') !== false) {
            od_send('DELETE', 'subscriptions/' . $s['SubscriptionNum']);
        }
    }

    $count = 0;
    foreach ($pcs as $pc) {
        foreach (WATCH_TABLES as $table) {
            od_send('POST', 'subscriptions', [
                'EndPointUrl'    => webhook_url(),
                'Workstation'    => $pc,
                'WatchTable'     => $table,
                'PollingSeconds' => $seconds,
                'Note'           => 'GHL sync',
            ]);
            $count++;
        }
    }
    return ['message' => "Automatic sync switched on for " . count($pcs) . " computer(s): " . implode(', ', $pcs) .
        " ($count subscriptions, checking every $seconds seconds). It keeps working as long as any of them is on."];
}

function action_unsubscribe() {
    $n = 0;
    foreach ((array)od_send('GET', 'subscriptions') as $s) {
        if (strpos($s['EndPointUrl'] ?? '', 'webhook.php') !== false) {
            od_send('DELETE', 'subscriptions/' . $s['SubscriptionNum']);
            $n++;
        }
    }
    return ['message' => "Automatic sync switched off ($n subscriptions removed)."];
}

function action_webhooklog() {
    $file = __DIR__ . '/data/webhook.log';
    $lines = file_exists($file) ? array_slice(file($file, FILE_IGNORE_NEW_LINES), -100) : [];
    return ['lines' => array_reverse($lines)];
}

function action_test() {
    $od  = od('patients/Simple', ['Offset' => 0]);
    $ghl = ghl('GET', 'contacts/?locationId=' . urlencode(GHL_LOCATION_ID) . '&limit=1');
    return ['message' => 'Both connections work. Open Dental returned ' . count($od) . ' patients on the first page; GHL contacts reachable.'];
}

function action_patients($start) {
    $params = [];
    if (!empty($_GET['since'])) $params['DateTStamp'] = $_GET['since'] . ' 00:00:00';
    if (!empty($_GET['PatStatus'])) $params['PatStatus'] = $_GET['PatStatus'];

    $list = cached_list('patients', $params, $start, fn() => od_all('patients/Simple', $params));
    $list = apply_limit($list);
    return run_batch($list, $start, function ($p) {
        [$id, $result] = push_patient($p);
        return [
            'od'     => 'Patient #' . $p['PatNum'] . ' ' . trim(($p['FName'] ?? '') . ' ' . ($p['LName'] ?? '')),
            'result' => $result,
            'ghl'    => $id,
        ];
    });
}

function action_appointments($start) {
    $params = [];
    if (!empty($_GET['dateStart'])) $params['dateStart'] = $_GET['dateStart'];
    if (!empty($_GET['dateEnd']))   $params['dateEnd']   = $_GET['dateEnd'];
    if (empty($params['dateStart']) || empty($params['dateEnd'])) {
        throw new Exception('Pick a From and To date for appointments.');
    }

    $list = cached_list('appointments', $params, $start, function () use ($params) {
        $all = od_all('appointments', $params);
        // Only statuses we sync
        return array_values(array_filter($all, fn($a) => ghl_status($a['AptStatus'] ?? '') !== null));
    });
    $list = apply_limit($list);

    return run_batch($list, $start, function ($a) {
        global $MAP;
        // Skip appointments that haven't changed since they were last sent to GHL
        $seenKey = 'appointment:' . $a['AptNum'];
        $version = $a['DateTStamp'] ?? null;
        if ($version && !empty($MAP['appointments'][(string)$a['AptNum']]) && ($MAP['seen'][$seenKey] ?? null) === $version) {
            return ['od' => 'Apt #' . $a['AptNum'] . ' · Pat #' . $a['PatNum'] . ' · ' . ($a['AptDateTime'] ?? '') . ' · ' . $a['AptStatus'],
                'result' => 'unchanged', 'ghl' => 'already up to date'];
        }
        [$result, $status] = push_appointment($a);
        if ($version && in_array($result, ['created', 'updated'])) $MAP['seen'][$seenKey] = $version;
        return [
            'od'     => 'Apt #' . $a['AptNum'] . ' · Pat #' . $a['PatNum'] . ' · ' . ($a['AptDateTime'] ?? '') . ' · ' . $a['AptStatus'],
            'result' => $result,
            'ghl'    => $status,
        ];
    });
}

// Optional "limit" (e.g. limit=10) to sync only the first N records, for testing
function apply_limit($list) {
    $limit = (int)($_GET['limit'] ?? 0);
    return $limit > 0 ? array_slice($list, 0, $limit) : $list;
}

// Download the list from Open Dental once at the start of a run, then reuse it
// for the following batches instead of downloading it again every time.
function cached_list($name, $params, $start, $fetch) {
    $file = __DIR__ . '/data/list_' . $name . '_' . md5(json_encode($params)) . '.json';
    if ($start > 0 && file_exists($file) && filemtime($file) > time() - 3600) {
        return load_json($file, []);
    }
    foreach (glob(__DIR__ . '/data/list_' . $name . '_*.json') ?: [] as $old) @unlink($old);
    $list = $fetch();
    save_json($file, $list);
    return $list;
}

function run_batch($list, $start, $fn) {
    global $MAP;
    $total = count($list);
    $end   = min($total, $start + BATCH_SIZE);
    $log   = [];

    for ($i = $start; $i < $end; $i++) {
        try {
            $log[] = $fn($list[$i]);
        } catch (Exception $e) {
            $log[] = ['od' => json_encode(array_intersect_key($list[$i], ['PatNum' => 1, 'AptNum' => 1])),
                'result' => 'error', 'ghl' => $e->getMessage()];
        }
        if ($i % 5 === 0) save_json(MAP_FILE, $MAP); // save progress often
    }
    save_json(MAP_FILE, $MAP);

    return ['total' => $total, 'next' => $end < $total ? $end : null, 'log' => $log];
}

if (defined('SYNC_LIB_ONLY')) return;

// ============================================================ Run

try {
    $action = $_GET['action'] ?? '';
    $start  = max(0, (int)($_GET['start'] ?? 0));

    if (strpos(GHL_TOKEN, 'PASTE') === 0) throw new Exception('Add your GHL token to config.php.');
    if ($action === 'appointments' && strpos(GHL_CALENDAR_ID, 'PASTE') === 0) {
        throw new Exception('Add GHL_CALENDAR_ID to config.php. Use "List GHL calendars" to find it.');
    }

    switch ($action) {
        case 'test':         $out = action_test(); break;
        case 'calendars':    $out = action_calendars(); break;
        case 'checkcal':     $out = action_checkcal(); break;
        case 'subscriptions':$out = action_subscriptions(); break;
        case 'computers':    $out = action_computers(); break;
        case 'subscribe':    $out = action_subscribe(); break;
        case 'unsubscribe':  $out = action_unsubscribe(); break;
        case 'webhooklog':   $out = action_webhooklog(); break;
        case 'patients':     $out = action_patients($start); break;
        case 'appointments': $out = action_appointments($start); break;
        default: throw new Exception('Unknown action. Use test, calendars, patients or appointments.');
    }

    $state = load_json(STATE_FILE, []);
    if (in_array($action, ['patients', 'appointments']) && empty($out['next'])) {
        $state['last_' . $action] = date('c');
        save_json(STATE_FILE, $state);
    }
    $out['lastRun'] = $state;

    echo json_encode($out);


} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}