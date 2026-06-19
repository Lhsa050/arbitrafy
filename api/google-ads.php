<?php
/**
 * Google Ads API Integration
 * Uses OAuth2 (Client ID + Client Secret + Refresh Token) + Google Ads REST API
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'sync':
        syncGoogleAds();
        break;
    case 'test':
        testGoogleAds();
        break;
    default:
        jsonResponse(['error' => 'Ação inválida'], 400);
}

function getGadsConfig() {
    return [
        'customer_id' => str_replace('-', '', getSetting('gads_customer_id', '')),
        'developer_token' => getSetting('gads_developer_token', ''),
        'client_id' => getSetting('gads_client_id', ''),
        'client_secret' => getSetting('gads_client_secret', ''),
        'refresh_token' => getSetting('gads_refresh_token', ''),
    ];
}

/**
 * Get all active Google connections with their selected Google Ads accounts.
 * Falls back to manual config if no OAuth connections exist.
 */
function getActiveGoogleAdsConnections() {
    $result = [];
    $devToken = getSetting('gads_developer_token', '');

    // Priority 1: OAuth connections
    try {
        $connections = fetchAll("SELECT * FROM google_connections WHERE status = 'active'");
        foreach ($connections as $conn) {
            $selected = json_decode($conn['gads_selected'], true) ?: [];
            if (!empty($selected) && !empty($conn['refresh_token'])) {
                $result[] = [
                    'refresh_token' => $conn['refresh_token'],
                    'accounts' => $selected,
                    'source' => 'oauth',
                    'connection_id' => $conn['id'],
                    'user_name' => $conn['google_user_name'] ?? 'OAuth',
                    'developer_token' => $devToken,
                    // OAuth connections use the global OAuth client for token refresh
                    'client_id' => getSetting('google_oauth_client_id', ''),
                    'client_secret' => getSetting('google_oauth_client_secret', ''),
                ];
            }
        }
    } catch (Exception $e) {}

    // Priority 2: Manual fallback
    if (empty($result)) {
        $config = getGadsConfig();
        if (!empty($config['customer_id']) && !empty($config['refresh_token'])) {
            $result[] = [
                'refresh_token' => $config['refresh_token'],
                'accounts' => [['id' => $config['customer_id'], 'name' => 'Manual']],
                'source' => 'manual',
                'connection_id' => null,
                'user_name' => 'Token Manual',
                'developer_token' => $devToken,
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ];
        }
    }

    return $result;
}

function validateGadsConfig($config) {
    if (empty($config['customer_id'])) return 'Customer ID não configurado.';
    if (empty($config['developer_token'])) return 'Developer Token não configurado.';
    if (empty($config['client_id'])) return 'Client ID não configurado.';
    if (empty($config['client_secret'])) return 'Client Secret não configurado.';
    if (empty($config['refresh_token'])) return 'Refresh Token não configurado.';
    return null;
}

function getGadsAccessToken($config) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $config['refresh_token'],
        ]),
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("cURL error: {$curlError}");
    }

    if ($httpCode !== 200 || empty($response['access_token'])) {
        $errMsg = $response['error_description'] ?? $response['error'] ?? "HTTP {$httpCode}";
        throw new Exception("OAuth2 error: {$errMsg}");
    }

    return $response['access_token'];
}

function syncGoogleAds() {
    set_time_limit(120);
    $syncStart = microtime(true);

    $connections = getActiveGoogleAdsConnections();

    if (empty($connections)) {
        // Try legacy manual config
        $config = getGadsConfig();
        $error = validateGadsConfig($config);
        if ($error) {
            logSync('GADS', 'ERROR', 'config', $error);
            jsonResponse(['success' => false, 'error' => 'Nenhuma conta Google Ads configurada. Conecte via login social ou configure manualmente.'], 400);
        }
        // Legacy: single account sync
        $connections = [[
            'refresh_token' => $config['refresh_token'],
            'accounts' => [['id' => $config['customer_id'], 'name' => 'Manual']],
            'source' => 'manual',
            'connection_id' => null,
            'user_name' => 'Manual',
            'developer_token' => $config['developer_token'],
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
        ]];
    }

    $totalImported = 0;
    $errors = [];
    $since = date('Y-m-d', strtotime('-30 days'));
    $until = date('Y-m-d');

    foreach ($connections as $conn) {
        try {
            $accessToken = getGadsAccessToken([
                'client_id' => $conn['client_id'],
                'client_secret' => $conn['client_secret'],
                'refresh_token' => $conn['refresh_token'],
            ]);

            $devToken = $conn['developer_token'];

            foreach ($conn['accounts'] as $account) {
                $customerId = str_replace('-', '', $account['id']);
                $accountName = $account['name'] ?? $customerId;

                $query = "SELECT "
                    . "campaign.id, "
                    . "campaign.name, "
                    . "campaign.status, "
                    . "segments.date, "
                    . "metrics.cost_micros, "
                    . "metrics.impressions, "
                    . "metrics.clicks, "
                    . "metrics.average_cpc, "
                    . "metrics.ctr, "
                    . "metrics.conversions, "
                    . "metrics.cost_per_conversion "
                    . "FROM campaign "
                    . "WHERE segments.date BETWEEN '{$since}' AND '{$until}' "
                    . "AND campaign.status != 'REMOVED' "
                    . "ORDER BY segments.date DESC";

                $url = "https://googleads.googleapis.com/v18/customers/{$customerId}/googleAds:searchStream";

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $accessToken,
                        'developer-token: ' . $devToken,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 60,
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    $errors[] = "{$accountName}: cURL {$curlError}";
                    continue;
                }

                $data = json_decode($response, true);

                if ($httpCode >= 400) {
                    $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
                    $errors[] = "{$accountName}: {$errMsg}";
                    continue;
                }

                $results = [];
                if (is_array($data)) {
                    foreach ($data as $batch) {
                        if (isset($batch['results'])) {
                            $results = array_merge($results, $batch['results']);
                        }
                    }
                }

                foreach ($results as $row) {
                    $campaign = $row['campaign'] ?? [];
                    $segments = $row['segments'] ?? [];
                    $metrics = $row['metrics'] ?? [];

                    $date = $segments['date'] ?? null;
                    $campaignId = $campaign['id'] ?? null;
                    $campaignName = $campaign['name'] ?? '';
                    $status = $campaign['status'] ?? 'UNKNOWN';

                    $statusMap = [
                        'ENABLED' => 'Ativo',
                        'PAUSED' => 'Pausado',
                        'REMOVED' => 'Removido',
                    ];
                    $statusPt = $statusMap[$status] ?? $status;

                    $costMicros = (float)($metrics['costMicros'] ?? 0);
                    $cost = $costMicros / 1000000;
                    $impressions = (int)($metrics['impressions'] ?? 0);
                    $clicks = (int)($metrics['clicks'] ?? 0);
                    $avgCpc = (float)($metrics['averageCpc'] ?? 0) / 1000000;
                    $ctr = (float)($metrics['ctr'] ?? 0);
                    $conversions = (float)($metrics['conversions'] ?? 0);
                    $cpm = $impressions > 0 ? ($cost / $impressions) * 1000 : 0;
                    $conversionRate = $clicks > 0 ? $conversions / $clicks : 0;

                    if (!$date || !$campaignId) continue;

                    upsert('google_ads', [
                        'date' => $date,
                        'campaign_id' => $campaignId,
                        'campaign_name' => $campaignName,
                        'cost' => round($cost, 2),
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'avg_cpc' => round($avgCpc, 6),
                        'ctr' => round($ctr, 6),
                        'conversion_rate' => round($conversionRate, 6),
                        'cpm' => round($cpm, 6),
                        'conversions' => round($conversions, 2),
                        'status' => $statusPt,
                        'last_updated' => date('Y-m-d H:i:s'),
                    ], ['date', 'campaign_id']);

                    $totalImported++;
                }
            }
        } catch (Exception $e) {
            $errors[] = $conn['user_name'] . ': ' . $e->getMessage();
        }
    }

    $durationMs = round((microtime(true) - $syncStart) * 1000);
    logSync('GADS', 'INFO', 'sync_complete', "Google Ads sync OK: {$totalImported} registros ({$durationMs}ms)", null, null, $durationMs);

    $response = [
        'success' => true,
        'message' => "Google Ads sincronizado! {$totalImported} registros importados.",
        'imported' => $totalImported,
    ];
    if (!empty($errors)) {
        $response['warnings'] = $errors;
    }
    jsonResponse($response);
}

function testGoogleAds() {
    $config = getGadsConfig();
    $error = validateGadsConfig($config);

    if ($error) {
        jsonResponse(['error' => $error], 400);
    }

    try {
        $accessToken = getGadsAccessToken($config);

        // Try a simple query to verify everything works
        $customerId = $config['customer_id'];
        $query = "SELECT customer.descriptive_name, customer.id FROM customer LIMIT 1";

        $url = "https://googleads.googleapis.com/v18/customers/{$customerId}/googleAds:searchStream";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'developer-token: ' . $config['developer_token'],
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new Exception($errMsg);
        }

        $customerName = '';
        if (isset($data[0]['results'][0]['customer']['descriptiveName'])) {
            $customerName = $data[0]['results'][0]['customer']['descriptiveName'];
        }

        jsonResponse([
            'success' => true,
            'message' => 'Conexão Google Ads OK!',
            'customer_id' => $customerId,
            'customer_name' => $customerName,
        ]);

    } catch (Exception $e) {
        jsonResponse(['error' => 'Falha na autenticação: ' . $e->getMessage()], 400);
    }
}
