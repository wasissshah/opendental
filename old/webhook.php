<?php
// webhook.php — Open Dental calls this URL automatically when data changes
// (Open Dental "API Events"). It pushes the change into GoHighLevel right away.
//
//   Appointment created / moved / completed / broken  -> GHL appointment created or updated
//   Appointment deleted in Open Dental                -> GHL appointment cancelled
//   Patient created / edited                          -> GHL contact created or updated
//
// Switch it on from sync.html > "3. Automatic sync".

define('SYNC_LIB_ONLY', true);
require __DIR__ . '/sync.php';

header('Content-Type: application/json');
set_time_limit(300);

const LOG_FILE  = __DIR__ . '/data/webhook.log';
const LOCK_FILE = __DIR__ . '/data/sync.lock';

function wlog($msg) {
    $line = date('Y-m-d H:i:s') . '  ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    // Keep the log small
    if (filesize(LOG_FILE) > 500000) {
        $lines = file(LOG_FILE);
        file_put_contents(LOG_FILE, implode('', array_slice($lines, -2000)), LOCK_EX);
    }
}

// ---------- Security: only Open Dental with our secret may call this ----------
$k = $_GET['k'] ?? '';
if (SYNC_SECRET === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' || !hash_equals(SYNC_SECRET, $k)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}
// Open Dental also sends the Customer API Key in the Authorization header
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth !== '' && !hash_equals(OD_CUSTOMER_KEY, trim(str_ireplace(['ODFHIR', 'Bearer'], '', $auth)))) {
    http_response_code(401);
    wlog('Rejected: Authorization header did not match the Customer Key');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

// ---------- Read the event ----------
$eventType = $_SERVER['HTTP_EVENT_TYPE'] ?? '';
$raw  = file_get_contents('php://input');
$rows = json_decode($raw, true);

if (!is_array($rows)) {
    http_response_code(200); // nothing to do, but don't make Open Dental retry
    echo json_encode(['ok' => true, 'note' => 'empty or invalid body']);
    exit;
}
if ($rows && array_keys($rows) !== range(0, count($rows) - 1)) $rows = [$rows]; // single object

// Work out which table the rows came from
function row_kind($row, $eventType) {
    if (stripos($eventType, 'AppointmentDeleted') !== false) return 'appointment_deleted';
    if (stripos($eventType, 'Appointment') !== false)        return 'appointment';
    if (stripos($eventType, 'Patient') !== false)            return 'patient';
    if (isset($row['AptNum']) && !isset($row['AptStatus']))  return 'appointment_deleted';
    if (isset($row['AptNum']))                               return 'appointment';
    if (isset($row['PatNum']) && isset($row['LName']))       return 'patient';
    return 'unknown';
}

// Cancel the GHL appointment when the Open Dental appointment is deleted
function cancel_deleted_appointment($aptNum) {
    global $MAP;
    $eventId = $MAP['appointments'][(string)$aptNum] ?? null;
    if (!$eventId) return 'skipped (never synced)';
    ghl('PUT', "calendars/events/appointments/$eventId", [
        'calendarId'        => GHL_CALENDAR_ID,
        'appointmentStatus' => 'cancelled',
        'toNotify'          => false,
    ], '2021-04-15');
    return 'cancelled (deleted in Open Dental)';
}

// Only one webhook at a time, so the saved links file is never written twice at once
$lock = fopen(LOCK_FILE, 'c');
flock($lock, LOCK_EX);
$MAP = load_json(MAP_FILE, ['patients' => [], 'appointments' => []]);

$done = 0; $errors = 0;
foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $kind = row_kind($row, $eventType);

    // Several computers send the same change. Skip it if we already handled this exact version.
    $id = $kind === 'patient' ? ($row['PatNum'] ?? '') : ($row['AptNum'] ?? '');
    $version = $row['DateTStamp'] ?? md5(json_encode($row));
    $seenKey = $kind . ':' . $id;
    if ($id !== '' && ($MAP['seen'][$seenKey] ?? null) === $version) {
        continue;
    }

    try {
        switch ($kind) {
            case 'appointment':
                [$result, $status] = push_appointment($row, false);
                wlog("Apt #{$row['AptNum']} Pat #{$row['PatNum']} {$row['AptStatus']} {$row['AptDateTime']} -> $result");
                break;
            case 'appointment_deleted':
                $result = cancel_deleted_appointment($row['AptNum']);
                wlog("Apt #{$row['AptNum']} deleted -> $result");
                break;
            case 'patient':
                if (!(defined('SYNC_ALL_PATIENT_CHANGES') ? SYNC_ALL_PATIENT_CHANGES : true) && empty($MAP['patients'][(string)$row['PatNum']])) {
                    wlog("Pat #{$row['PatNum']} changed -> skipped (not in GHL yet)");
                    break;
                }
                [$cid, $result] = push_patient($row, false);
                wlog("Pat #{$row['PatNum']} {$row['FName']} {$row['LName']} -> $result");
                break;
            default:
                wlog("Unknown event ($eventType): " . substr(json_encode($row), 0, 200));
        }
        $done++;
        if ($id !== '') $MAP['seen'][$seenKey] = $version;
    } catch (Exception $e) {
        $errors++;
        wlog("ERROR $kind " . json_encode(array_intersect_key($row, ['AptNum' => 1, 'PatNum' => 1])) . ': ' . $e->getMessage());
    }
}

// Keep the "already handled" list from growing forever
if (!empty($MAP['seen']) && count($MAP['seen']) > 20000) {
    $MAP['seen'] = array_slice($MAP['seen'], -10000, null, true);
}
save_json(MAP_FILE, $MAP);
flock($lock, LOCK_UN);
fclose($lock);

// Always answer 200 so Open Dental doesn't resend the same batch forever.
// Failed rows are listed in data/webhook.log, and the backup cron job picks them up.
http_response_code(200);
echo json_encode(['ok' => true, 'processed' => $done, 'errors' => $errors]);