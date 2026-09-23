<?php
// api.php — server-side proxy to the Open Dental API.
// Only the resources and filters listed below can be requested from the page.

require __DIR__ . '/config.php';

header('Content-Type: application/json');
set_time_limit(300);

// resource key => [endpoint, allowed filters, paged?]
$RESOURCES = [
    'appointments' => [
        'endpoint' => 'appointments',
        'params'   => ['date', 'dateStart', 'dateEnd', 'AptStatus', 'PatNum', 'Op', 'ClinicNum', 'AppointmentTypeNum'],
        'paged'    => true,
    ],
    'asap' => [
        'endpoint' => 'appointments/ASAP',
        'params'   => ['ClinicNum', 'ProvNum'],
        'paged'    => true,
    ],
    'slots' => [
        'endpoint' => 'appointments/Slots',
        'params'   => ['date', 'dateStart', 'dateEnd', 'lengthMinutes', 'ProvNum', 'OpNum'],
        'paged'    => false,
    ],
    'patients' => [
        'endpoint' => 'patients/Simple',
        'params'   => ['LName', 'FName', 'PatStatus', 'Gender', 'Birthdate', 'PriProv', 'ClinicNum'],
        'paged'    => true,
    ],
    'providers' => [
        'endpoint' => 'providers',
        'params'   => ['ClinicNum'],
        'paged'    => false,
    ],
    'operatories' => [
        'endpoint' => 'operatories',
        'params'   => ['ClinicNum'],
        'paged'    => false,
    ],
    'clinics' => [
        'endpoint' => 'clinics',
        'params'   => [],
        'paged'    => false,
    ],
    'appointmenttypes' => [
        'endpoint' => 'appointmenttypes',
        'params'   => [],
        'paged'    => false,
    ],
];

function od_get($endpoint, $params = []) {
    $url = OD_BASE_URL . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ODFHIR ' . OD_DEVELOPER_KEY . '/' . OD_CUSTOMER_KEY,
            'Content-Type: application/json',
        ],
    ];
    if (defined('OD_CA_FILE') && OD_CA_FILE !== '') {
        $opts[CURLOPT_CAINFO] = OD_CA_FILE;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new Exception('cURL error: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new Exception("Open Dental returned HTTP $code: $body");
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [];
    }
    // Some endpoints return a single object instead of a list
    return array_keys($json) === range(0, count($json) - 1) ? $json : [$json];
}

try {
    $key = $_GET['resource'] ?? 'appointments';
    if (!isset($RESOURCES[$key])) {
        throw new Exception('Unknown resource: ' . $key);
    }
    $res = $RESOURCES[$key];

    // Keep only allowed, non-empty filters
    $params = [];
    foreach ($res['params'] as $p) {
        if (isset($_GET[$p]) && $_GET[$p] !== '') {
            $params[$p] = $_GET[$p];
        }
    }

    $all = [];
    if ($res['paged']) {
        // Open Dental returns up to 100 rows per call; keep going with Offset
        $pageSize = 100;
        $offset   = 0;
        $maxLoops = 300; // safety limit (30,000 rows)
        while ($maxLoops-- > 0) {
            $params['Offset'] = $offset;
            $page = od_get($res['endpoint'], $params);
            $all  = array_merge($all, $page);
            if (count($page) < $pageSize) break;
            $offset += count($page);
        }
    } else {
        $all = od_get($res['endpoint'], $params);
    }

    echo json_encode(['data' => $all]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'data' => []]);
}