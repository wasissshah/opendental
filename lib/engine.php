<?php
// lib/engine.php — the Open Dental → GHL sync, run for one location at a time.
// Call engine_start($locationId) first; it loads that location's keys and saved links.

const WATCH_TABLES = ['Appointment', 'AppointmentDeleted', 'Patient'];
const BATCH_SIZE   = 25;

$CTX = null;   // current location settings
$MAP = null;   // saved links Open Dental id -> GHL id for this location

function engine_start($locationId) {
    global $CTX, $MAP;
    $CTX = location_context($locationId);
    $MAP = load_json(map_file(), ['patients' => [], 'appointments' => [], 'seen' => []]);
    return $CTX;
}

function map_file()  { global $CTX; return $CTX['data_dir'] . '/sync_map.json'; }
function log_file()  { global $CTX; return $CTX['data_dir'] . '/webhook.log'; }
function lock_file() { global $CTX; return $CTX['data_dir'] . '/sync.lock'; }

function engine_require($what) {
    global $CTX;
    if (in_array('ghl', $what) && ($CTX['ghl_token'] === '' || $CTX['ghl_location'] === '')) {
        throw new Exception('GHL is not set up for this location. Add it on the location\'s Configuration Page.');
    }
    if (in_array('calendar', $what) && $CTX['ghl_calendar'] === '') {
        throw new Exception('GHL Calendar ID is missing on the location\'s Configuration Page.');
    }
    if (in_array('od', $what) && ($CTX['od_customer'] === '' || $CTX['od_developer'] === '')) {
        throw new Exception('Open Dental is not set up for this location. Add it on the location\'s Configuration Page.');
    }
}

// ============================================================ API calls

function od($path, $params = []) {
    global $CTX;
    $url = OD_BASE_URL . $path . ($params ? '?' . http_build_query($params) : '');
    return http_json('GET', $url, [
        'Authorization: ODFHIR ' . $CTX['od_developer'] . '/' . $CTX['od_customer'],
        'Content-Type: application/json',
    ]);
}

function od_send($method, $path, $body = null) {
    global $CTX;
    return http_json($method, OD_BASE_URL . $path, [
        'Authorization: ODFHIR ' . $CTX['od_developer'] . '/' . $CTX['od_customer'],
        'Content-Type: application/json',
    ], $body);
}

// Open Dental returns results in pages; keep asking with Offset
function od_all($path, $params = []) {
    $all = [];
    $offset = 0;
    for ($i = 0; $i < 300; $i++) {
        $params['Offset'] = $offset;
        $page = od($path, $params);
        if (!is_array($page) || !$page) break;
        if (array_keys($page) !== range(0, count($page) - 1)) { $all[] = $page; break; } // single object
        $all = array_merge($all, $page);
        if (count($page) < 100) break;
        $offset += count($page);
    }
    return $all;
}

function ghl($method, $path, $body = null, $version = '2021-07-28') {
    global $CTX;
    usleep(150000); // stay well under GHL's 100 requests / 10 seconds
    return http_json($method, GHL_BASE_URL . $path, [
        'Authorization: Bearer ' . $CTX['ghl_token'],
        'Version: ' . $version,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $body);
}

// ============================================================ Saved links

function load_json($file, $default) {
    if (!file_exists($file)) return $default;
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d + $default : $default;
}

function save_json($file, $data) {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode($data), LOCK_EX);
}

function save_map() {
    global $MAP;
    if (!empty($MAP['seen']) && count($MAP['seen']) > 20000) {
        $MAP['seen'] = array_slice($MAP['seen'], -10000, null, true);
    }
    save_json(map_file(), $MAP);
}

// Only one sync at a time per location, so saved links are never overwritten
function with_lock($fn) {
    global $MAP;
    $fp = fopen(lock_file(), 'c');
    flock($fp, LOCK_EX);
    try {
        $MAP = load_json(map_file(), ['patients' => [], 'appointments' => [], 'seen' => []]); // fresh copy
        $out = $fn();
        save_map();
        return $out;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function wlog($msg) {
    $file = log_file();
    file_put_contents($file, gmdate('Y-m-d H:i:s') . '  ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    if (filesize($file) > 500000) {
        $lines = file($file);
        file_put_contents($file, implode('', array_slice($lines, -2000)), LOCK_EX);
    }
}

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
        'firstName'   => ($p['Preferred'] ?? '') ?: ($p['FName'] ?? ''),
        'lastName'    => $p['LName'] ?? '',
        'email'       => filter_var($p['Email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
        'phone'       => $phone,
        'dateOfBirth' => clean_date($p['Birthdate'] ?? null),
        'address1'    => $p['Address'] ?? null,
        'city'        => $p['City'] ?? null,
        'state'       => $p['State'] ?? null,
        'postalCode'  => $p['Zip'] ?? null,
        'source'      => CONTACT_SOURCE,
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
    global $CTX;
    $d = new DateTime($localDateTime, new DateTimeZone($CTX['timezone']));
    if ($minutes) $d->modify("+$minutes minutes");
    return $d->format('c');
}

// Pattern is 5-minute blocks, e.g. "//XXXX//" = 40 minutes
function apt_minutes($pattern) {
    $m = strlen((string)$pattern) * 5;
    return $m > 0 ? $m : 30;
}

// ============================================================ Sync steps

// Create or update one patient as a GHL contact. Returns [contactId, result].
function push_patient($p, $dryRun) {
    global $MAP, $CTX;
    $patNum   = (string)$p['PatNum'];
    $contact  = patient_to_contact($p);
    $existing = $MAP['patients'][$patNum] ?? null;

    if ($dryRun) return [$existing, $existing ? 'would update' : 'would create'];

    if ($existing) {
        try {
            ghl('PUT', "contacts/$existing", $contact);
            ghl('POST', "contacts/$existing/tags", ['tags' => [CONTACT_TAG]]);
            return [$existing, 'updated'];
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'HTTP 404') === false && strpos($e->getMessage(), 'HTTP 400') === false) throw $e;
            // Contact was deleted in GHL, so fall through and create it again
        }
    }

    $res = ghl('POST', 'contacts/upsert', $contact + ['locationId' => $CTX['ghl_location']]);
    $id  = $res['contact']['id'] ?? null;
    if (!$id) throw new Exception('GHL did not return a contact id');
    ghl('POST', "contacts/$id/tags", ['tags' => [CONTACT_TAG]]);

    $MAP['patients'][$patNum] = $id;
    return [$id, !empty($res['new']) ? 'created' : 'matched existing'];
}

function ensure_contact($patNum) {
    global $MAP;
    if (!empty($MAP['patients'][(string)$patNum])) return $MAP['patients'][(string)$patNum];
    [$id] = push_patient(od("patients/$patNum"), false);
    return $id;
}

// The user appointments are assigned to (Personal calendars need one)
function calendar_user_id() {
    global $CTX;
    static $cache = [];
    $key = $CTX['ghl_calendar'];
    if (isset($cache[$key])) return $cache[$key];
    $res = ghl('GET', 'calendars/' . rawurlencode($key), null, '2021-04-15');
    foreach (($res['calendar']['teamMembers'] ?? []) as $m) {
        if (!empty($m['userId'])) return $cache[$key] = $m['userId'];
    }
    return $cache[$key] = '';
}

function push_appointment($a, $dryRun) {
    global $MAP, $CTX;
    $aptNum   = (string)$a['AptNum'];
    $status   = ghl_status($a['AptStatus'] ?? '');
    $existing = $MAP['appointments'][$aptNum] ?? null;
    $when     = clean_date($a['AptDateTime'] ?? null);

    if (!$status)             return ['skipped', 'status ' . ($a['AptStatus'] ?? '?') . ' is not synced'];
    if (!$existing && $status === 'cancelled' && !$when) return ['skipped', 'cancelled before it was ever synced'];
    if (!$when && !$existing) return ['skipped', 'no date/time'];

    if ($dryRun) return [$existing ? 'would update' : 'would create', $status];

    $contactId = ensure_contact($a['PatNum']);

    $body = [
        'calendarId'               => $CTX['ghl_calendar'],
        'appointmentStatus'        => $status,
        'title'                    => trim(($a['ProcDescript'] ?? '') ?: 'Dental appointment'),
        'ignoreDateRange'          => true,
        'ignoreFreeSlotValidation' => true,
        'toNotify'                 => false, // no automatic GHL emails/SMS
    ];
    if ($when) {
        $body['startTime'] = to_iso($when);
        $body['endTime']   = to_iso($when, apt_minutes($a['Pattern'] ?? ''));
    }
    if (!empty($a['Note'])) $body['description'] = $a['Note'];
    $userId = calendar_user_id();
    if ($userId !== '') $body['assignedUserId'] = $userId;

    $result = null;
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
                   $body + ['locationId' => $CTX['ghl_location'], 'contactId' => $contactId], '2021-04-15');
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

function cancel_deleted_appointment($aptNum) {
    global $MAP, $CTX;
    $eventId = $MAP['appointments'][(string)$aptNum] ?? null;
    if (!$eventId) return 'skipped (never synced)';
    ghl('PUT', "calendars/events/appointments/$eventId", [
        'calendarId'        => $CTX['ghl_calendar'],
        'appointmentStatus' => 'cancelled',
        'toNotify'          => false,
    ], '2021-04-15');
    return 'cancelled (deleted in Open Dental)';
}

// ============================================================ Manual sync (sync page)

function apt_label($a) {
    return 'Apt #' . $a['AptNum'] . ' · Pat #' . $a['PatNum'] . ' · ' . ($a['AptDateTime'] ?? '') . ' · ' . ($a['AptStatus'] ?? '');
}

// Download the list once per run, reuse it for the next batches
function cached_list($name, $params, $start, $fetch) {
    global $CTX;
    $file = $CTX['data_dir'] . '/list_' . $name . '_' . md5(json_encode($params)) . '.json';
    if ($start > 0 && file_exists($file) && filemtime($file) > time() - 3600) return load_json($file, []);
    foreach (glob($CTX['data_dir'] . '/list_' . $name . '_*.json') ?: [] as $old) @unlink($old);
    $list = $fetch();
    save_json($file, $list);
    return $list;
}

function apply_limit($list, $limit) {
    return $limit > 0 ? array_slice($list, 0, $limit) : $list;
}

function run_batch($list, $start, $fn) {
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
    }
    return ['total' => $total, 'next' => $end < $total ? $end : null, 'log' => $log];
}

function sync_patients($opts, $start, $dryRun) {
    $params = [];
    if (!empty($opts['since']))     $params['DateTStamp'] = $opts['since'] . ' 00:00:00';
    if (!empty($opts['PatStatus'])) $params['PatStatus']  = $opts['PatStatus'];
    $list = cached_list('patients', $params, $start, fn() => od_all('patients/Simple', $params));
    $list = apply_limit($list, (int)($opts['limit'] ?? 0));
    return run_batch($list, $start, function ($p) use ($dryRun) {
        [$id, $result] = push_patient($p, $dryRun);
        return ['od' => 'Patient #' . $p['PatNum'] . ' ' . trim(($p['FName'] ?? '') . ' ' . ($p['LName'] ?? '')),
                'result' => $result, 'ghl' => $id];
    });
}

function sync_appointments($opts, $start, $dryRun) {
    if (empty($opts['dateStart']) || empty($opts['dateEnd'])) throw new Exception('Pick a From and To date.');
    $params = ['dateStart' => $opts['dateStart'], 'dateEnd' => $opts['dateEnd']];
    $list = cached_list('appointments', $params, $start, function () use ($params) {
        return array_values(array_filter(od_all('appointments', $params), fn($a) => ghl_status($a['AptStatus'] ?? '') !== null));
    });
    $list = apply_limit($list, (int)($opts['limit'] ?? 0));
    return run_batch($list, $start, function ($a) use ($dryRun) {
        global $MAP;
        $seenKey = 'appointment:' . $a['AptNum'];
        $version = $a['DateTStamp'] ?? null;
        if ($version && !empty($MAP['appointments'][(string)$a['AptNum']]) && ($MAP['seen'][$seenKey] ?? null) === $version) {
            return ['od' => apt_label($a), 'result' => 'unchanged', 'ghl' => 'already up to date'];
        }
        [$result, $status] = push_appointment($a, $dryRun);
        if (!$dryRun && $version && in_array($result, ['created', 'updated'], true)) $MAP['seen'][$seenKey] = $version;
        return ['od' => apt_label($a), 'result' => $result, 'ghl' => $status];
    });
}

// ============================================================ Tools

function tool_calendars() {
    global $CTX;
    $res = ghl('GET', 'calendars/?locationId=' . urlencode($CTX['ghl_location']), null, '2021-04-15');
    $out = [];
    foreach (($res['calendars'] ?? []) as $c) {
        $out[] = ['id' => $c['id'], 'name' => $c['name'] ?? '', 'active' => $c['isActive'] ?? null];
    }
    return ['calendars' => $out];
}

function tool_checkcal() {
    global $CTX;
    $res = ghl('GET', 'calendars/' . rawurlencode($CTX['ghl_calendar']), null, '2021-04-15');
    $c = $res['calendar'] ?? [];
    $members = array_map(fn($m) => ($m['userId'] ?? '?'), $c['teamMembers'] ?? []);
    return ['info' => [
        ['k' => 'Name',         'v' => $c['name'] ?? ''],
        ['k' => 'Type',         'v' => $c['calendarType'] ?? ''],
        ['k' => 'Active (API)', 'v' => isset($c['isActive']) ? ($c['isActive'] ? 'yes' : 'NO') : 'unknown'],
        ['k' => 'Team members', 'v' => $members ? implode(', ', $members) : 'NONE: add a team member with availability'],
        ['k' => 'Assigned user used by sync', 'v' => calendar_user_id() ?: 'none'],
    ]];
}

function tool_computers() {
    $list = (array)od_send('GET', 'computers');
    usort($list, fn($a, $b) => strcmp($b['LastHeartBeat'] ?? '', $a['LastHeartBeat'] ?? ''));
    $out = [];
    foreach ($list as $c) {
        $hb = $c['LastHeartBeat'] ?? '';
        $never = strpos($hb, '0001-01-01') === 0 || $hb === '';
        $out[] = ['name' => $c['CompName'] ?? '', 'lastSeen' => $never ? 'never / unknown' : $hb, 'heartbeat' => $never ? '' : $hb];
    }
    return ['computers' => $out];
}

function is_our_subscription($s) {
    return strpos($s['EndPointUrl'] ?? '', 'webhook.php') !== false;
}

function tool_subscriptions() {
    $out = [];
    foreach ((array)od_send('GET', 'subscriptions') as $s) {
        $out[] = [
            'num'   => $s['SubscriptionNum'] ?? '',
            'what'  => ($s['WatchTable'] ?? '') ?: ($s['UiEventType'] ?? ''),
            'every' => $s['PollingSeconds'] ?? '',
            'pc'    => $s['Workstation'] ?? '',
            'ours'  => is_our_subscription($s),
            'url'   => preg_replace('/k=[^&]+/', 'k=***', $s['EndPointUrl'] ?? ''),
        ];
    }
    return ['subscriptions' => $out];
}

function tool_subscribe($workstations, $seconds) {
    global $CTX;
    $pcs = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$workstations)))));
    if (!$pcs) throw new Exception('Enter at least one Workstation (practice computer name).');
    if (count($pcs) > 15) throw new Exception('Please pick 15 computers or fewer.');
    $seconds = max(15, (int)$seconds);

    foreach ((array)od_send('GET', 'subscriptions') as $s) {
        if (is_our_subscription($s)) od_send('DELETE', 'subscriptions/' . $s['SubscriptionNum']);
    }
    $count = 0;
    foreach ($pcs as $pc) {
        foreach (WATCH_TABLES as $table) {
            od_send('POST', 'subscriptions', [
                'EndPointUrl'    => webhook_url_for($CTX),
                'Workstation'    => $pc,
                'WatchTable'     => $table,
                'PollingSeconds' => $seconds,
                'Note'           => 'GHL sync',
            ]);
            $count++;
        }
    }
    return ['message' => 'Automatic sync is ON for ' . count($pcs) . ' computer(s): ' . implode(', ', $pcs) .
                         " ($count subscriptions, every $seconds seconds). It keeps working as long as any of them is on."];
}

function tool_unsubscribe() {
    $n = 0;
    foreach ((array)od_send('GET', 'subscriptions') as $s) {
        if (is_our_subscription($s)) { od_send('DELETE', 'subscriptions/' . $s['SubscriptionNum']); $n++; }
    }
    return ['message' => "Automatic sync switched off ($n subscriptions removed)."];
}

function tool_log() {
    $file = log_file();
    $lines = file_exists($file) ? array_slice(file($file, FILE_IGNORE_NEW_LINES), -150) : [];
    return ['lines' => array_reverse($lines)];
}
