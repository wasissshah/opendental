<?php
// api.php — calls the Open Dental API from the server and returns JSON to the browser.
// Keys stay on the server and are never exposed in the page.

require __DIR__ . '/config.php';

header('Content-Type: application/json');
set_time_limit(300);

function od_get($endpoint, $params = []) {
    $url = OD_BASE_URL . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ODFHIR ' . OD_DEVELOPER_KEY . '/' . OD_CUSTOMER_KEY,
            'Content-Type: application/json',
        ],
        // If you get an SSL certificate error on WAMP, see the note in the chat.
        // Quick local-only workaround (do NOT use on a live server):
        // CURLOPT_SSL_VERIFYPEER => false,
    ]);

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
    return json_decode($body, true);
}

try {
    // Optional date filter from the page, e.g. api.php?dateStart=2026-01-01&dateEnd=2026-12-31
    $params = [];
    if (!empty($_GET['dateStart'])) $params['dateStart'] = $_GET['dateStart'];
    if (!empty($_GET['dateEnd']))   $params['dateEnd']   = $_GET['dateEnd'];

    // The API returns results in pages, so keep asking with Offset until nothing comes back.
    $all    = [];
    $offset = 0;
    $maxLoops = 200; // safety limit

    while ($maxLoops-- > 0) {
        $params['Offset'] = $offset;
        $page = od_get('appointments', $params);

        if (!is_array($page) || count($page) === 0) break;

        $all = array_merge($all, $page);
        $offset += count($page);
    }

    echo json_encode(['data' => $all]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'data' => []]);
}