<?php
// webhook.php?loc=ID&k=SECRET — Open Dental calls this automatically when data changes
// for that location (Open Dental "API Events"), and the change is pushed to GHL.
//
//   Appointment created / moved / completed / broken  -> GHL appointment created or updated
//   Appointment deleted in Open Dental                -> GHL appointment cancelled
//   Patient created / edited                          -> GHL contact created or updated

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/engine.php';

header('Content-Type: application/json');
set_time_limit(300);

$locId = (int)($_GET['loc'] ?? 0);
$loc   = $locId ? location_get($locId) : null;

// Security: the secret in the URL must match this location
if (!$loc || !hash_equals($loc['webhook_secret'], (string)($_GET['k'] ?? ''))) {
    json_out(['error' => 'unauthorized'], 401);
}
if (!$loc['is_active']) {
    json_out(['ok' => true, 'note' => 'location inactive, ignored']);
}

$ctx = engine_start($locId);

// Open Dental also sends its Customer API Key in the Authorization header
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth !== '' && !hash_equals($ctx['od_customer'], trim(str_ireplace(['ODFHIR', 'Bearer'], '', $auth)))) {
    wlog('Rejected: Authorization header did not match the Customer Key');
    json_out(['error' => 'unauthorized'], 401);
}

$eventType = $_SERVER['HTTP_EVENT_TYPE'] ?? '';
$rows = json_decode(file_get_contents('php://input'), true);
if (!is_array($rows)) json_out(['ok' => true, 'note' => 'empty or invalid body']);
if ($rows && array_keys($rows) !== range(0, count($rows) - 1)) $rows = [$rows];

function row_kind($row, $eventType) {
    if (stripos($eventType, 'AppointmentDeleted') !== false) return 'appointment_deleted';
    if (stripos($eventType, 'Appointment') !== false)        return 'appointment';
    if (stripos($eventType, 'Patient') !== false)            return 'patient';
    if (isset($row['AptNum']) && !isset($row['AptStatus']))  return 'appointment_deleted';
    if (isset($row['AptNum']))                               return 'appointment';
    if (isset($row['PatNum']) && isset($row['LName']))       return 'patient';
    return 'unknown';
}

try {
    engine_require(['ghl', 'calendar']);
} catch (Exception $e) {
    wlog('ERROR ' . $e->getMessage());
    json_out(['ok' => true, 'note' => $e->getMessage()]);
}

$result = with_lock(function () use ($rows, $eventType) {
    global $MAP;
    $done = 0; $errors = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $kind = row_kind($row, $eventType);

        // Several computers send the same change: handle each version only once
        $id      = $kind === 'patient' ? ($row['PatNum'] ?? '') : ($row['AptNum'] ?? '');
        $version = $row['DateTStamp'] ?? md5(json_encode($row));
        $seenKey = $kind . ':' . $id;
        if ($id !== '' && ($MAP['seen'][$seenKey] ?? null) === $version) continue;

        try {
            switch ($kind) {
                case 'appointment':
                    [$res] = push_appointment($row, false);
                    wlog("Apt #{$row['AptNum']} Pat #{$row['PatNum']} {$row['AptStatus']} {$row['AptDateTime']} -> $res");
                    break;
                case 'appointment_deleted':
                    wlog("Apt #{$row['AptNum']} deleted -> " . cancel_deleted_appointment($row['AptNum']));
                    break;
                case 'patient':
                    if (!SYNC_ALL_PATIENT_CHANGES && empty($MAP['patients'][(string)$row['PatNum']])) {
                        wlog("Pat #{$row['PatNum']} changed -> skipped (not in GHL yet)");
                        break;
                    }
                    [, $res] = push_patient($row, false);
                    wlog("Pat #{$row['PatNum']} " . ($row['FName'] ?? '') . ' ' . ($row['LName'] ?? '') . " -> $res");
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
    return ['processed' => $done, 'errors' => $errors];
});

// Always 200 so Open Dental doesn't resend the same batch forever; errors are in the Activity log.
json_out(['ok' => true] + $result);
