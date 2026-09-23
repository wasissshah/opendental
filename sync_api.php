<?php
// sync_api.php?loc=ID&action=... — used by sync.php (admin only).
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/engine.php';

require_admin(true);
[$user, $locId] = require_location_access(true);
check_csrf(true);
set_time_limit(120);

$in     = $_POST;
$action = $in['action'] ?? '';
$start  = max(0, (int)($in['start'] ?? 0));
$dryRun = !empty($in['dryRun']) && $in['dryRun'] !== '0';

try {
    engine_start($locId);
    switch ($action) {
        case 'test':
            engine_require(['ghl', 'od']);
            od('providers');
            ghl('GET', 'contacts/?locationId=' . urlencode($GLOBALS['CTX']['ghl_location']) . '&limit=1');
            $out = ['message' => 'Both connections work: Open Dental and GHL are reachable.'];
            break;
        case 'calendars':     engine_require(['ghl']);             $out = tool_calendars(); break;
        case 'checkcal':      engine_require(['ghl', 'calendar']); $out = tool_checkcal(); break;
        case 'computers':     engine_require(['od']);              $out = tool_computers(); break;
        case 'subscriptions': engine_require(['od']);              $out = tool_subscriptions(); break;
        case 'subscribe':     engine_require(['od', 'ghl', 'calendar']); $out = tool_subscribe($in['workstation'] ?? '', $in['seconds'] ?? 60); break;
        case 'unsubscribe':   engine_require(['od']);              $out = tool_unsubscribe(); break;
        case 'log':           $out = tool_log(); break;
        case 'patients':
            engine_require(['od', 'ghl']);
            $out = with_lock(fn() => sync_patients($in, $start, $dryRun));
            break;
        case 'appointments':
            engine_require(['od', 'ghl', 'calendar']);
            $out = with_lock(fn() => sync_appointments($in, $start, $dryRun));
            break;
        default:
            throw new Exception('Unknown action.');
    }
    json_out($out);
} catch (Exception $e) {
    json_out(['error' => $e->getMessage()], 500);
}
