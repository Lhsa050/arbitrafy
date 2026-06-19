<?php
/**
 * Cron Sync Endpoint - Syncs FB + GAM without login
 * Works on both VPS (exec) and shared hosting (direct calls)
 * 
 * Usage (cron job on Hostinger shared hosting):
 *   /usr/bin/php /home/u123/public_html/api/cron.php --token=YOUR_TOKEN
 * 
 * Usage (URL - Hostinger cron URL):
 *   https://yoursite.com/api/cron.php?token=YOUR_TOKEN
 */
error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(600);
ignore_user_abort(true);

// Fake session
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION['logged_in'] = true;
$_SESSION['username'] = 'cron';

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// --- Auth via token ---
$token = $_GET['token'] ?? '';

// CLI mode: parse --token=xxx
if (php_sapi_name() === 'cli') {
    foreach ($argv ?? [] as $arg) {
        if (strpos($arg, '--token=') === 0) {
            $token = substr($arg, 8);
        }
    }
}

$expectedToken = getSetting('cron_secret_token', '');

if (empty($expectedToken)) {
    $expectedToken = bin2hex(random_bytes(16));
    upsert('settings', [
        'setting_key' => 'cron_secret_token',
        'setting_value' => $expectedToken,
    ], ['setting_key']);
}

if ($token !== $expectedToken) {
    http_response_code(403);
    $msg = json_encode(['error' => 'Token inválido.', 'hint' => 'Consulte cron_secret_token na tabela settings.']);
    if (php_sapi_name() === 'cli') echo $msg . "\n";
    else { header('Content-Type: application/json'); echo $msg; }
    exit;
}

// --- Cooldown (10 min) ---
$lastSync = getSetting('last_cron_sync', '');
if ($lastSync && (time() - strtotime($lastSync)) < 600) {
    $msg = json_encode(['success' => true, 'skipped' => true, 'message' => 'Sync recente. Aguarde 10 min.', 'last_sync' => $lastSync]);
    if (php_sapi_name() === 'cli') echo $msg . "\n";
    else { header('Content-Type: application/json'); echo $msg; }
    exit;
}

// --- Check if exec() is available ---
function cronExecAvailable() {
    $disabled = explode(',', ini_get('disable_functions'));
    $disabled = array_map('trim', $disabled);
    return function_exists('exec') && !in_array('exec', $disabled);
}

// --- Run syncs ---
$results = [];

// ===== SYNC FACEBOOK =====
try {
    if (cronExecAvailable()) {
        $phpBin = PHP_BINARY ?: '/usr/bin/php';
        $script = realpath(__DIR__ . '/../cli_sync_fb.php');
        if ($script && file_exists($script)) {
            $output = [];
            $exitCode = 0;
            exec('"' . $phpBin . '" "' . $script . '" 2>&1', $output, $exitCode);
            $outputStr = implode("\n", $output);
            $imported = 0;
            if (preg_match('/Imported:\s*(\d+)/i', $outputStr, $m)) $imported = (int)$m[1];
            $results['fb'] = ['success' => stripos($outputStr, 'FATAL') === false, 'imported' => $imported, 'method' => 'exec'];
        }
    }
    
    if (empty($results['fb'])) {
        // Direct call fallback
        ob_start();
        require_once __DIR__ . '/facebook.php';
        $fbOutput = ob_get_clean();
        $fbData = json_decode($fbOutput, true);
        $results['fb'] = [
            'success' => $fbData['success'] ?? false,
            'imported' => $fbData['imported'] ?? 0,
            'method' => 'direct',
        ];
    }
} catch (Exception $e) {
    $results['fb'] = ['success' => false, 'error' => $e->getMessage()];
}

// ===== SYNC GAM =====
try {
    if (cronExecAvailable()) {
        $phpBin = PHP_BINARY ?: '/usr/bin/php';
        $script = realpath(__DIR__ . '/../cli_sync_gam.php');
        if ($script && file_exists($script)) {
            $output = [];
            $exitCode = 0;
            exec('"' . $phpBin . '" "' . $script . '" 2>&1', $output, $exitCode);
            $outputStr = implode("\n", $output);
            $imported = 0;
            if (preg_match('/Imported:\s*(\d+)/i', $outputStr, $m)) $imported = (int)$m[1];
            $results['gam'] = ['success' => stripos($outputStr, 'FATAL') === false, 'imported' => $imported, 'method' => 'exec'];
        }
    }
    
    if (empty($results['gam'])) {
        ob_start();
        $_GET['action'] = 'sync';
        require_once __DIR__ . '/gam.php';
        $gamOutput = ob_get_clean();
        $gamData = json_decode($gamOutput, true);
        $results['gam'] = [
            'success' => $gamData['success'] ?? false,
            'imported' => $gamData['imported'] ?? 0,
            'method' => 'direct',
        ];
    }
} catch (Exception $e) {
    $results['gam'] = ['success' => false, 'error' => $e->getMessage()];
}

// ===== SYNC GOOGLE ADS =====
$hasGoogleOAuth = false;
try {
    $googleConns = fetchAll("SELECT * FROM google_connections WHERE status = 'active'");
    foreach ($googleConns as $gc) {
        $gSel = json_decode($gc['gads_selected'], true) ?: [];
        if (!empty($gSel)) { $hasGoogleOAuth = true; break; }
    }
} catch (Exception $e) { $googleConns = []; }

$gadsCustomerId = getSetting('gads_customer_id', '');
$hasManualGads = !empty($gadsCustomerId) && !empty(getSetting('gads_refresh_token', ''));

if ($hasGoogleOAuth || $hasManualGads) {
    try {
        $gadsConns = [];
        $devToken = getSetting('gads_developer_token', '');

        if ($hasGoogleOAuth) {
            foreach ($googleConns as $gc) {
                $sel = json_decode($gc['gads_selected'], true) ?: [];
                if (!empty($sel) && !empty($gc['refresh_token'])) {
                    $gadsConns[] = [
                        'refresh_token' => $gc['refresh_token'],
                        'accounts' => $sel,
                        'client_id' => getSetting('google_oauth_client_id', ''),
                        'client_secret' => getSetting('google_oauth_client_secret', ''),
                        'developer_token' => $devToken,
                    ];
                }
            }
        }

        if (empty($gadsConns) && $hasManualGads) {
            $gadsConns[] = [
                'refresh_token' => getSetting('gads_refresh_token', ''),
                'accounts' => [['id' => $gadsCustomerId, 'name' => 'Manual']],
                'client_id' => getSetting('gads_client_id', ''),
                'client_secret' => getSetting('gads_client_secret', ''),
                'developer_token' => $devToken,
            ];
        }

        $gadsImported = 0;
        $since = date('Y-m-d', strtotime('-30 days'));
        $until = date('Y-m-d');

        foreach ($gadsConns as $gc) {
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'refresh_token', 'client_id' => $gc['client_id'],
                    'client_secret' => $gc['client_secret'], 'refresh_token' => $gc['refresh_token'],
                ]),
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $tokenResp = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (empty($tokenResp['access_token'])) continue;
            $accessToken = $tokenResp['access_token'];

            foreach ($gc['accounts'] as $account) {
                $custId = str_replace('-', '', $account['id']);
                $query = "SELECT campaign.id, campaign.name, campaign.status, segments.date, "
                    . "metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.average_cpc, "
                    . "metrics.ctr, metrics.conversions FROM campaign "
                    . "WHERE segments.date BETWEEN '{$since}' AND '{$until}' AND campaign.status != 'REMOVED'";

                $ch = curl_init("https://googleads.googleapis.com/v18/customers/{$custId}/googleAds:searchStream");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $accessToken,
                        'developer-token: ' . $gc['developer_token'],
                        'Content-Type: application/json',
                    ],
                    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 60,
                ]);
                $response = curl_exec($ch);
                curl_close($ch);

                $data = json_decode($response, true);
                $allResults = [];
                if (is_array($data)) {
                    foreach ($data as $batch) {
                        if (isset($batch['results'])) $allResults = array_merge($allResults, $batch['results']);
                    }
                }
                $statusMap = ['ENABLED' => 'Ativo', 'PAUSED' => 'Pausado'];
                foreach ($allResults as $row) {
                    $camp = $row['campaign'] ?? []; $seg = $row['segments'] ?? []; $met = $row['metrics'] ?? [];
                    $d = $seg['date'] ?? null; $cid = $camp['id'] ?? null;
                    if (!$d || !$cid) continue;
                    $cost = (float)($met['costMicros'] ?? 0) / 1000000;
                    $imp = (int)($met['impressions'] ?? 0);
                    $clicks = (int)($met['clicks'] ?? 0);
                    $convs = (float)($met['conversions'] ?? 0);
                    upsert('google_ads', [
                        'date' => $d, 'campaign_id' => $cid, 'campaign_name' => $camp['name'] ?? '',
                        'cost' => round($cost, 2), 'impressions' => $imp, 'clicks' => $clicks,
                        'avg_cpc' => round((float)($met['averageCpc'] ?? 0) / 1000000, 6),
                        'ctr' => round((float)($met['ctr'] ?? 0), 6),
                        'conversion_rate' => $clicks > 0 ? round($convs / $clicks, 6) : 0,
                        'cpm' => $imp > 0 ? round(($cost / $imp) * 1000, 6) : 0,
                        'conversions' => round($convs, 2),
                        'status' => $statusMap[$camp['status'] ?? ''] ?? 'Ativo',
                        'last_updated' => date('Y-m-d H:i:s'),
                    ], ['date', 'campaign_id']);
                    $gadsImported++;
                }
            }
        }
        $results['google_ads'] = ['success' => true, 'imported' => $gadsImported];
        logSync('GADS', 'INFO', 'cron_sync', "Google Ads cron sync: {$gadsImported} registros");
    } catch (Exception $e) {
        $results['google_ads'] = ['success' => false, 'error' => $e->getMessage()];
    }
}

// Save last sync time
$now = date('Y-m-d H:i:s');
upsert('settings', [
    'setting_key' => 'last_cron_sync',
    'setting_value' => $now,
], ['setting_key']);

$response = json_encode([
    'success' => true,
    'message' => 'Sync automático concluído.',
    'timestamp' => $now,
    'results' => $results,
]);

if (php_sapi_name() === 'cli') {
    echo $response . "\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo $response;
}
