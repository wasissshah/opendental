<?php
// sync.php — copies Open Dental data into GoHighLevel (GHL).
//
//   Patients      -> GHL contacts (created or updated)
//   Appointments  -> GHL calendar appointments (created or updated, incl. cancellations)
//
// Web:  called by sync.html (needs the SYNC_SECRET in the X-Sync-Key header)
// Cron: php sync.php action=appointments days=30
//       php sync.php action=patients
//
// Links between Open Dental and GHL records are saved in data/sync_map.json,
// so running it again updates the same GHL records instead of making duplicates.

require __DIR__ . '/config.php';

const GHL_BASE      = 'https://services.leadconnectorhq.com/';
const MAP_FILE      = __DIR__ . '/data/sync_map.json';
const STATE_FILE    = __DIR__ . '/data/sync_state.json';
const BATCH_SIZE    = 25;   // records per web request (keeps shared hosting under its time limit)

$IS_CLI = (PHP_SAPI === 'cli');
set_time_limit($IS_CLI ? 0 : 120);

if ($IS_CLI) {
    // Allow "php sync.php action=appointments days=30"
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
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
        'source'      => 'Open Dental',
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

// Create or update one patient in GHL. Returns [contactId, 'created'|'updated'|'dry-run'].
function push_patient($p, $dryRun) {
    global $MAP;
    $patNum  = (string)$p['PatNum'];
    $contact = patient_to_contact($p);
    $existing = $MAP['patients'][$patNum] ?? null;

    if ($dryRun) return [$existing, $existing ? 'would update' : 'would create'];

    if ($existing) {
        try {
            ghl('PUT', "contacts/$existing", $contact);
            return [$existing, 'updated'];
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'HTTP 404') === false && strpos($e->getMessage(), 'HTTP 400') === false) throw $e;
            // Contact was deleted in GHL, so fall through and create it again
        }
    }

    $res = ghl('POST', 'contacts/upsert', $contact + ['locationId' => GHL_LOCATION_ID]);
    $id  = $res['contact']['id'] ?? null;
    if (!$id) throw new Exception('GHL did not return a contact id');

    ghl('POST', "contacts/$id/tags", ['tags' => ['open-dental']]);

    $MAP['patients'][$patNum] = $id;
    return [$id, !empty($res['new']) ? 'created' : 'matched existing'];
}

function ensure_contact($patNum, $dryRun) {
    global $MAP;
    if (!empty($MAP['patients'][(string)$patNum])) return $MAP['patients'][(string)$patNum];
    $p = od("patients/$patNum");
    [$id] = push_patient($p, $dryRun);
    return $id;
}

function push_appointment($a, $dryRun) {
    global $MAP;
    $aptNum   = (string)$a['AptNum'];
    $status   = ghl_status($a['AptStatus'] ?? '');
    $existing = $MAP['appointments'][$aptNum] ?? null;
    $when     = clean_date($a['AptDateTime'] ?? null);

    if (!$status)               return ['skipped', 'status ' . $a['AptStatus'] . ' is not synced'];
    if (!$existing && $status === 'cancelled' && !$when)
                                return ['skipped', 'cancelled before it was ever synced'];
    if (!$when && !$existing)   return ['skipped', 'no date/time'];

    if ($dryRun) return [$existing ? 'would update' : 'would create', $status];

    $contactId = ensure_contact($a['PatNum'], false);

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

function action_test() {
    $od  = od('patients/Simple', ['Offset' => 0]);
    $ghl = ghl('GET', 'contacts/?locationId=' . urlencode(GHL_LOCATION_ID) . '&limit=1');
    return ['message' => 'Both connections work. Open Dental returned ' . count($od) . ' patients on the first page; GHL contacts reachable.'];
}

function action_patients($start, $dryRun) {
    $params = [];
    if (!empty($_GET['since'])) $params['DateTStamp'] = $_GET['since'] . ' 00:00:00';
    if (!empty($_GET['PatStatus'])) $params['PatStatus'] = $_GET['PatStatus'];

    $list = od_all('patients/Simple', $params);
    return run_batch($list, $start, $dryRun, function ($p) use ($dryRun) {
        [$id, $result] = push_patient($p, $dryRun);
        return [
            'od'     => 'Patient #' . $p['PatNum'] . ' ' . trim(($p['FName'] ?? '') . ' ' . ($p['LName'] ?? '')),
            'result' => $result,
            'ghl'    => $id,
        ];
    });
}

function action_appointments($start, $dryRun) {
    $params = [];
    if (!empty($_GET['days'])) {
        // Cron shortcut: yesterday up to N days ahead
        $params['dateStart'] = date('Y-m-d', strtotime('-1 day'));
        $params['dateEnd']   = date('Y-m-d', strtotime('+' . (int)$_GET['days'] . ' days'));
    }
    if (!empty($_GET['dateStart'])) $params['dateStart'] = $_GET['dateStart'];
    if (!empty($_GET['dateEnd']))   $params['dateEnd']   = $_GET['dateEnd'];
    if (empty($params['dateStart']) || empty($params['dateEnd'])) {
        throw new Exception('Pick a From and To date for appointments.');
    }

    $list = od_all('appointments', $params);
    // Only statuses we sync
    $list = array_values(array_filter($list, fn($a) => ghl_status($a['AptStatus'] ?? '') !== null));

    return run_batch($list, $start, $dryRun, function ($a) use ($dryRun) {
        [$result, $status] = push_appointment($a, $dryRun);
        return [
            'od'     => 'Apt #' . $a['AptNum'] . ' · Pat #' . $a['PatNum'] . ' · ' . ($a['AptDateTime'] ?? '') . ' · ' . $a['AptStatus'],
            'result' => $result,
            'ghl'    => $status,
        ];
    });
}

function run_batch($list, $start, $dryRun, $fn) {
    global $IS_CLI, $MAP;
    $total = count($list);
    $end   = $IS_CLI ? $total : min($total, $start + BATCH_SIZE);
    $log   = [];

    for ($i = $start; $i < $end; $i++) {
        try {
            $log[] = $fn($list[$i]);
        } catch (Exception $e) {
            $log[] = ['od' => json_encode(array_intersect_key($list[$i], ['PatNum' => 1, 'AptNum' => 1])),
                      'result' => 'error', 'ghl' => $e->getMessage()];
        }
        if (!$dryRun && $i % 5 === 0) save_json(MAP_FILE, $MAP); // save progress often
    }
    if (!$dryRun) save_json(MAP_FILE, $MAP);

    return ['total' => $total, 'next' => $end < $total ? $end : null, 'log' => $log];
}

// ============================================================ Run

try {
    $action = $_GET['action'] ?? '';
    $start  = max(0, (int)($_GET['start'] ?? 0));
    $dryRun = !empty($_GET['dryRun']) && $_GET['dryRun'] !== '0';

    if (strpos(GHL_TOKEN, 'PASTE') === 0) throw new Exception('Add your GHL token to config.php.');
    if ($action === 'appointments' && strpos(GHL_CALENDAR_ID, 'PASTE') === 0) {
        throw new Exception('Add GHL_CALENDAR_ID to config.php. Use "List GHL calendars" to find it.');
    }

    switch ($action) {
        case 'test':         $out = action_test(); break;
        case 'calendars':    $out = action_calendars(); break;
        case 'patients':     $out = action_patients($start, $dryRun); break;
        case 'appointments': $out = action_appointments($start, $dryRun); break;
        default: throw new Exception('Unknown action. Use test, calendars, patients or appointments.');
    }

    $state = load_json(STATE_FILE, []);
    if (in_array($action, ['patients', 'appointments']) && !$dryRun && empty($out['next'])) {
        $state['last_' . $action] = date('c');
        save_json(STATE_FILE, $state);
    }
    $out['lastRun'] = $state;

    if ($IS_CLI) {
        foreach (($out['log'] ?? []) as $l) echo str_pad($l['result'], 14) . $l['od'] . '  ' . $l['ghl'] . PHP_EOL;
        if (isset($out['message']))   echo $out['message'] . PHP_EOL;
        if (isset($out['calendars'])) foreach ($out['calendars'] as $c) echo $c['id'] . '  ' . $c['name'] . PHP_EOL;
        if (isset($out['total']))     echo "Done: {$out['total']} records." . PHP_EOL;
    } else {
        echo json_encode($out);
    }

} catch (Exception $e) {
    if ($IS_CLI) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}