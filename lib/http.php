<?php
// lib/http.php — JSON HTTP calls to Open Dental and GHL.

function http_json($method, $url, $headers, $body = null) {
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    if (defined('OD_CA_FILE') && OD_CA_FILE !== '') $opts[CURLOPT_CAINFO] = OD_CA_FILE;

    // Retry when rate-limited (HTTP 429)
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new Exception("Connection error: $err");
        if ($code == 429) { sleep(2 + $attempt * 2); continue; }
        if ($code == 400 && strpos($raw, 'Calendar is inactive') !== false) {
            throw new Exception('GHL says the calendar is inactive. Use "Check calendar": the calendar needs a team member with availability.');
        }
        if ($code < 200 || $code >= 300) {
            throw new Exception("HTTP $code from " . parse_url($url, PHP_URL_HOST) . ': ' . substr($raw, 0, 400));
        }
        return $raw === '' ? [] : json_decode($raw, true);
    }
    throw new Exception('Rate limited too many times, try again in a minute.');
}
