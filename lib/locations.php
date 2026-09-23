<?php
// lib/locations.php — locations and their encrypted GHL / Open Dental settings.

require_once __DIR__ . '/http.php';

const OD_BASE_URL  = 'https://api.opendental.com/api/v1/';
const GHL_BASE_URL = 'https://services.leadconnectorhq.com/';

// Fields per provider. 'secret' fields are never sent back to the browser in full.
const INTEGRATION_FIELDS = [
    'ghl' => [
        'api_key'     => ['label' => 'API key (Private Integration token)', 'secret' => true],
        'location_id' => ['label' => 'Location ID', 'secret' => false],
        'calendar_id' => ['label' => 'Calendar ID', 'secret' => false],
    ],
    'open_dental' => [
        'customer_key'  => ['label' => 'Customer API key', 'secret' => true],
        'developer_key' => ['label' => 'Developer API key', 'secret' => true],
    ],
];

function location_get($id) {
    return q_one('SELECT * FROM locations WHERE id = ?', [(int)$id]);
}

// Decrypted settings + status for one provider
function integration_get($locationId, $provider) {
    $row = q_one('SELECT * FROM location_integrations WHERE location_id = ? AND provider = ?', [(int)$locationId, $provider]);
    $cfg = [];
    if ($row) {
        $json = decrypt_str($row['config_json']);
        $cfg  = $json ? (json_decode($json, true) ?: []) : [];
    }
    return [
        'config'  => $cfg,
        'status'  => $row['status'] ?? 'not_set',
        'message' => $row['status_message'] ?? '',
        'tested'  => $row['last_tested_at'] ?? null,
        'exists'  => (bool)$row,
    ];
}

// Save one provider's settings. Blank secret fields keep the old value.
function integration_save($locationId, $provider, $input) {
    $fields = INTEGRATION_FIELDS[$provider];
    $old = integration_get($locationId, $provider)['config'];
    $new = [];
    $missing = [];
    foreach ($fields as $key => $f) {
        $val = trim((string)($input[$key] ?? ''));
        if ($f['secret'] && $val === '') $val = $old[$key] ?? '';
        if ($val === '') $missing[] = $f['label'];
        $new[$key] = $val;
    }
    if ($missing) throw new Exception('Required: ' . implode(', ', $missing));

    $enc = encrypt_str(json_encode($new));
    q('INSERT INTO location_integrations (location_id, provider, config_json, status, status_message)
       VALUES (?, ?, ?, "not_tested", NULL)
       ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), status = "not_tested", status_message = NULL',
      [(int)$locationId, $provider, $enc]);
}

// Settings safe to show in the page: secrets masked
function integration_public($locationId, $provider) {
    $i = integration_get($locationId, $provider);
    $out = [];
    foreach (INTEGRATION_FIELDS[$provider] as $key => $f) {
        $v = $i['config'][$key] ?? '';
        $out[$key] = $f['secret'] ? mask_secret($v) : $v;
    }
    $i['config'] = $out;
    return $i;
}

// Lightweight API call to check the saved settings. Saves the result.
function integration_test($locationId, $provider) {
    $cfg = integration_get($locationId, $provider)['config'];
    try {
        if (!$cfg) throw new Exception('Not configured yet.');
        if ($provider === 'ghl') {
            $res = http_json('GET', GHL_BASE_URL . 'calendars/' . rawurlencode($cfg['calendar_id']), [
                'Authorization: Bearer ' . $cfg['api_key'], 'Version: 2021-04-15', 'Accept: application/json',
            ]);
            $cal = $res['calendar'] ?? [];
            if (($cal['locationId'] ?? '') !== '' && $cal['locationId'] !== $cfg['location_id']) {
                throw new Exception('The calendar belongs to a different GHL Location ID (' . $cal['locationId'] . ').');
            }
            $msg = 'Connected. Calendar: ' . ($cal['name'] ?? 'found');
        } else {
            $res = http_json('GET', OD_BASE_URL . 'providers', [
                'Authorization: ODFHIR ' . $cfg['developer_key'] . '/' . $cfg['customer_key'], 'Content-Type: application/json',
            ]);
            $msg = 'Connected. ' . (is_array($res) ? count($res) : 0) . ' providers found.';
        }
        $status = 'connected';
    } catch (Exception $e) {
        $status = 'failed';
        $msg = $e->getMessage();
    }
    q('UPDATE location_integrations SET status = ?, status_message = ?, last_tested_at = ? WHERE location_id = ? AND provider = ?',
      [$status, mb_substr($msg, 0, 500), now_utc(), (int)$locationId, $provider]);
    return ['status' => $status, 'message' => $msg];
}

function status_badge($status) {
    $map = [
        'connected'  => ['Connected', 'ok'],
        'failed'     => ['Failed', 'bad'],
        'not_tested' => ['Not tested', 'warn'],
        'not_set'    => ['Not set up', 'muted'],
    ];
    [$label, $cls] = $map[$status] ?? [$status, 'muted'];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

// Everything the sync engine needs for one location
function location_context($locationId) {
    $loc = location_get($locationId);
    if (!$loc) throw new Exception('Location not found.');
    $ghl = integration_get($locationId, 'ghl')['config'];
    $od  = integration_get($locationId, 'open_dental')['config'];
    $dir = DATA_DIR . '/loc_' . (int)$locationId;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return [
        'id'             => (int)$loc['id'],
        'name'           => $loc['name'],
        'timezone'       => $loc['timezone'] ?: 'America/Denver',
        'webhook_secret' => $loc['webhook_secret'],
        'ghl_token'      => $ghl['api_key'] ?? '',
        'ghl_location'   => $ghl['location_id'] ?? '',
        'ghl_calendar'   => $ghl['calendar_id'] ?? '',
        'od_customer'    => $od['customer_key'] ?? '',
        'od_developer'   => $od['developer_key'] ?? '',
        'data_dir'       => $dir,
    ];
}

function webhook_url_for($ctx) {
    return rtrim(APP_URL, '/') . '/webhook.php?loc=' . $ctx['id'] . '&k=' . urlencode($ctx['webhook_secret']);
}
