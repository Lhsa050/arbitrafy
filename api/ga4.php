<?php
/**
 * GA4 (Google Analytics 4) API Integration
 * Uses Google Analytics Data API v1beta to fetch sessions by utm_source + utm_campaign
 * 
 * Actions:
 *   sync  → Fetch sessions for last 30 days, filtered by utm_source
 *   test  → Test connection to GA4 property
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Ensure ga4_sessions table exists
ensureGA4Table();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'sync':
        syncGA4();
        break;
    case 'test':
        testGA4();
        break;
    case 'save_settings':
        saveGA4Settings();
        break;
    default:
        jsonResponse(['error' => 'Ação inválida. Use: sync, test ou save_settings'], 400);
}

/**
 * Get GA4 access token from google_connections (OAuth)
 */
function getGA4Auth() {
    try {
        $connections = fetchAll("SELECT * FROM google_connections WHERE status = 'active'");
        foreach ($connections as $conn) {
            if (!empty($conn['refresh_token'])) {
                $clientId = getSetting('google_oauth_client_id', '');
                $clientSecret = getSetting('google_oauth_client_secret', '');
                
                if (empty($clientId) || empty($clientSecret)) continue;

                $ch = curl_init('https://oauth2.googleapis.com/token');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'grant_type' => 'refresh_token',
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'refresh_token' => $conn['refresh_token'],
                    ]),
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $response = json_decode(curl_exec($ch), true);
                curl_close($ch);

                if (!empty($response['access_token'])) {
                    return [
                        'token' => $response['access_token'],
                        'source' => 'oauth',
                        'user_name' => $conn['google_user_name'] ?? 'OAuth',
                    ];
                }
            }
        }
    } catch (Exception $e) {}

    return null;
}

/**
 * Sync GA4 sessions for last 30 days
 */
function syncGA4() {
    set_time_limit(120);

    $propertyId = getSetting('ga4_property_id', '');
    $utmSource = getSetting('ga4_utm_source', 'facebook');

    if (empty($propertyId)) {
        jsonResponse(['error' => 'GA4 Property ID não configurado. Vá em Conectar GA4 e informe o Property ID.'], 400);
    }

    $auth = getGA4Auth();
    if (!$auth) {
        jsonResponse(['error' => 'Nenhuma conexão Google ativa. Conecte sua conta Google primeiro.'], 400);
    }

    $token = $auth['token'];
    $since = date('Y-m-d', strtotime('-30 days'));
    $until = date('Y-m-d');

    // GA4 Data API v1beta - runReport
    $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";

    $requestBody = buildGA4SessionsRequestBody($since, $until, $utmSource);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 60,
    ]);
    $responseText = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        logSync('GA4', 'ERROR', 'api_request', "cURL error: {$curlError}");
        jsonResponse(['error' => "Erro de conexão com GA4: {$curlError}"], 500);
    }

    $data = json_decode($responseText, true);

    if ($httpCode >= 400 || isset($data['error'])) {
        $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
        logSync('GA4', 'ERROR', 'api_request', "GA4 API error: {$errMsg}", $responseText, $httpCode);
        jsonResponse(['error' => "Erro GA4: {$errMsg}"], 500);
    }

    // Parse rows
    $rows = $data['rows'] ?? [];
    $campaignLookup = buildFBCampaignLookup($since, $until);
    [$sessionRows, $stats] = parseGA4SessionRows($rows, $campaignLookup);
    [$sessionRows, $stats, $fallbackReports] = addGA4SessionFallbackRows(
        $propertyId,
        $token,
        $since,
        $until,
        $utmSource,
        $campaignLookup,
        $sessionRows,
        $stats
    );

    if (!empty($rows) || !empty($sessionRows)) {
        query("DELETE FROM ga4_sessions WHERE date >= ? AND utm_source = ?", [$since, $utmSource]);

        foreach ($sessionRows as $sessionRow) {
            upsert('ga4_sessions', [
                'date' => $sessionRow['date'],
                'campaign_id' => $sessionRow['campaign_id'],
                'utm_source' => $utmSource,
                'sessions' => $sessionRow['sessions'],
            ], ['date', 'campaign_id', 'utm_source']);
        }
    }

    $details = [
        'api_rows' => count($rows),
        'imported_rows' => count($sessionRows),
        'sessions' => $stats['sessions'],
        'match_modes' => [
            'numeric' => $stats['numeric'],
            'fb_name_date' => $stats['fb_name_date'],
            'fb_name' => $stats['fb_name'],
            'raw_name' => $stats['raw_name'],
        ],
        'unmatched' => $stats['unmatched'],
        'unmatched_samples' => $stats['unmatched_samples'],
        'fallback_reports' => $fallbackReports,
        'unfiltered_fallback' => getSetting('ga4_allow_unfiltered_fallback', '0') === '1' ? 'enabled' : 'disabled',
    ];
    logSync('GA4', 'INFO', 'sync_complete', "GA4 sync OK: " . count($sessionRows) . " registros, {$stats['sessions']} sessoes (utm_source={$utmSource})", $details, $httpCode);
    $imported = count($sessionRows);

    jsonResponse([
        'success' => true,
        'message' => "GA4 sincronizado! {$imported} registros de sessões (utm_source={$utmSource}).",
        'imported' => count($sessionRows),
        'sessions' => $stats['sessions'],
        'total_rows' => count($rows),
        'match_modes' => $details['match_modes'],
        'unmatched' => $stats['unmatched'],
        'fallback_reports' => $fallbackReports,
    ]);
}

/**
 * Test GA4 connection
 */
function testGA4() {
    $propertyId = getSetting('ga4_property_id', '');
    $utmSource = getSetting('ga4_utm_source', 'facebook');

    if (empty($propertyId)) {
        jsonResponse(['error' => 'GA4 Property ID não configurado.'], 400);
    }

    $auth = getGA4Auth();
    if (!$auth) {
        jsonResponse(['error' => 'Nenhuma conexão Google ativa.'], 400);
    }

    $token = $auth['token'];

    // Simple test: fetch last 7 days sessions count
    $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";

    $requestBody = [
        'dateRanges' => [
            ['startDate' => '7daysAgo', 'endDate' => 'today']
        ],
        'metrics' => [
            ['name' => 'sessions']
        ],
        'limit' => 1
    ];
    $sourceFilter = buildGA4SourceFilter($utmSource);
    if ($sourceFilter) {
        $requestBody['dimensionFilter'] = $sourceFilter;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestBody),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $responseText = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($responseText, true);

    if ($httpCode >= 400 || isset($data['error'])) {
        $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
        jsonResponse([
            'success' => false,
            'error' => "GA4: {$errMsg}",
            'hint' => 'Verifique se o Property ID está correto e se a conta Google tem acesso ao GA4.'
        ]);
    }

    $totalSessions = 0;
    foreach ($data['rows'] ?? [] as $row) {
        $totalSessions += (int)($row['metricValues'][0]['value'] ?? 0);
    }

    jsonResponse([
        'success' => true,
        'message' => "Conexão GA4 OK! {$totalSessions} sessões nos últimos 7 dias (utm_source={$utmSource}).",
        'property_id' => $propertyId,
        'utm_source' => $utmSource,
        'sessions_7d' => $totalSessions,
        'auth_source' => $auth['source'],
        'auth_user' => $auth['user_name'],
    ]);
}

/**
 * Save GA4 settings (POST)
 */
function saveGA4Settings() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    if (isset($input['ga4_property_id'])) {
        // Clean: remove "properties/" prefix if user pasted it
        $propId = preg_replace('/^properties\//', '', trim($input['ga4_property_id']));
        setSetting('ga4_property_id', $propId);
    }
    if (isset($input['ga4_utm_source'])) {
        setSetting('ga4_utm_source', trim($input['ga4_utm_source']));
    }

    jsonResponse(['success' => true, 'message' => 'Configurações GA4 salvas!']);
}
