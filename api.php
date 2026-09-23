<?php
// api.php?loc=ID&resource=... — read-only Open Dental data for the data viewer.
// Admins and staff assigned to the location can use it.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/engine.php';

[$user, $locId] = require_location_access(true);
set_time_limit(300);

$RESOURCES = [
    'appointments'     => ['endpoint' => 'appointments',       'params' => ['date', 'dateStart', 'dateEnd', 'AptStatus', 'PatNum', 'Op', 'ClinicNum', 'AppointmentTypeNum'], 'paged' => true],
    'asap'             => ['endpoint' => 'appointments/ASAP',  'params' => ['ClinicNum', 'ProvNum'], 'paged' => true],
    'slots'            => ['endpoint' => 'appointments/Slots', 'params' => ['date', 'dateStart', 'dateEnd', 'lengthMinutes', 'ProvNum', 'OpNum'], 'paged' => false],
    'patients'         => ['endpoint' => 'patients/Simple',    'params' => ['LName', 'FName', 'PatStatus', 'Gender', 'Birthdate', 'PriProv', 'ClinicNum'], 'paged' => true],
    'providers'        => ['endpoint' => 'providers',          'params' => ['ClinicNum'], 'paged' => false],
    'operatories'      => ['endpoint' => 'operatories',        'params' => ['ClinicNum'], 'paged' => false],
    'clinics'          => ['endpoint' => 'clinics',            'params' => [], 'paged' => false],
    'appointmenttypes' => ['endpoint' => 'appointmenttypes',   'params' => [], 'paged' => false],
];

$CANCEL_MAP = [
    'cancelled'   => ['Broken', 'UnschedList'],
    'broken'      => ['Broken'],
    'unscheduled' => ['UnschedList'],
    'active'      => ['Scheduled', 'Complete'],
    'upcoming'    => ['Scheduled'],
];

try {
    engine_start($locId);
    engine_require(['od']);

    $key = $_GET['resource'] ?? 'appointments';
    if (!isset($RESOURCES[$key])) throw new Exception('Unknown resource');
    $res = $RESOURCES[$key];

    $params = [];
    foreach ($res['params'] as $p) {
        if (isset($_GET[$p]) && $_GET[$p] !== '') $params[$p] = $_GET[$p];
    }

    $fetch = function ($params) use ($res) {
        if (!$res['paged']) {
            $r = od($res['endpoint'], $params);
            return is_array($r) && array_keys($r) !== range(0, count($r) - 1) ? [$r] : (array)$r;
        }
        return od_all($res['endpoint'], $params);
    };

    $cancel = $_GET['cancelFilter'] ?? '';
    if ($key === 'appointments' && isset($CANCEL_MAP[$cancel])) {
        $all = [];
        foreach ($CANCEL_MAP[$cancel] as $status) {
            $all = array_merge($all, $fetch(['AptStatus' => $status] + $params));
        }
    } else {
        $all = $fetch($params);
    }
    json_out(['data' => $all]);
} catch (Exception $e) {
    json_out(['error' => $e->getMessage(), 'data' => []], 500);
}
