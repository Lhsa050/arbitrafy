<?php
/**
 * GAM (Google Ad Manager) API Integration
 * Uses Service Account (JWT) OAuth2 + Ad Manager SOAP API
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'sync':
        syncGAM();
        break;
    case 'test':
        testGAM();
        break;
    default:
        jsonResponse(['error' => 'Ação inválida'], 400);
}

function getServiceAccountPath() {
    // Try both possible setting keys
    $path = getSetting('gam_service_account_path', '');
    if (empty($path)) {
        $path = getSetting('gam_service_account_json', '');
    }
    if (empty($path)) return null;
    
    // Resolve relative to project root
    $fullPath = __DIR__ . '/../' . $path;
    if (file_exists($fullPath)) return $fullPath;
    
    // Try absolute path
    if (file_exists($path)) return $path;
    
    return null;
}

/**
 * Get GAM token - OAuth connections first, Service Account fallback.
 * Returns [token, networkCode] or null on failure.
 */
function getGAMAuth() {
    // Priority 1: OAuth connections from google_connections
    try {
        $connections = fetchAll("SELECT * FROM google_connections WHERE status = 'active'");
        foreach ($connections as $conn) {
            $gamSelected = json_decode($conn['gam_selected'], true) ?: [];
            if (!empty($gamSelected) && !empty($conn['refresh_token'])) {
                // Refresh the access token
                $clientId = getSetting('google_oauth_client_id', '');
                $clientSecret = getSetting('google_oauth_client_secret', '');
                
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
                    $networkCode = $gamSelected[0]['network_code'] ?? getSetting('gam_network_code', '');
                    return [
                        'token' => $response['access_token'],
                        'network_code' => $networkCode,
                        'source' => 'oauth',
                        'user_name' => $conn['google_user_name'] ?? 'OAuth',
                    ];
                }
            }
        }
    } catch (Exception $e) {}

    // Priority 2: Service Account fallback
    $networkCode = getSetting('gam_network_code', '');
    $saPath = getServiceAccountPath();

    if (!empty($networkCode) && $saPath) {
        $credentials = json_decode(file_get_contents($saPath), true);
        if ($credentials && isset($credentials['private_key'])) {
            try {
                $token = getGAMToken($credentials);
                if ($token) {
                    return [
                        'token' => $token,
                        'network_code' => $networkCode,
                        'source' => 'service_account',
                        'user_name' => $credentials['client_email'] ?? 'Service Account',
                    ];
                }
            } catch (Exception $e) {}
        }
    }

    return null;
}

function syncGAM() {
    set_time_limit(300);
    if (ob_get_level()) ob_clean();
    
    $auth = getGAMAuth();

    if (!$auth) {
        jsonResponse(['error' => 'GAM não configurado. Conecte via Google Social Login ou configure Service Account + Network Code.'], 400);
    }

    $token = $auth['token'];
    $networkCode = $auth['network_code'];

    if (empty($networkCode)) {
        jsonResponse(['error' => 'Network Code do GAM não configurado.'], 400);
    }

    try {
        if (function_exists('ensureRevenueTableSchema')) {
            ensureRevenueTableSchema();
        }

        // Step 2: Create report job via SOAP API
        $since = date('Y-m-d', strtotime('-30 days'));
        $until = date('Y-m-d');

        $debug = isset($_GET['debug']);
        $reportData = fetchGAMReportSOAP($networkCode, $token, $since, $until, $debug);

        // Step 3: Import data
        $imported = 0;
        foreach ($reportData['rows'] as $row) {
            $date = $row['date'] ?? null;
            $utm = $row['utm_campaign'] ?? '';
            $receita = (float)($row['revenue'] ?? 0);

            // Extract campaign ID from utm_campaign key-value
            $campaignId = $utm;
            // Strip common prefixes
            $campaignId = preg_replace('/^(utm_campaign\s*=\s*|utm_campaign\s*:?\s*)/', '', $campaignId);
            $campaignId = trim($campaignId);

            if ($date && $campaignId && $receita > 0) {
                try {
                    upsert('revenue', [
                        'date' => $date,
                        'campaign_id' => $campaignId,
                        'utm_campaign' => $campaignId,
                        'receita_usd' => $receita,
                        'site_name' => '',
                    ], ['date', 'campaign_id', 'site_name']);
                    $imported++;
                } catch (Exception $e) {}
            }
        }

        // Step 4: Cross-reference com campanhas FB
        crossReferenceAfterGAM();

        $response = [
            'success' => true,
            'message' => "Importados {$imported} registros do GAM (últimos 30 dias).",
            'imported' => $imported,
        ];
        
        // Include debug info if requested or if no records imported
        if ($debug || $imported === 0) {
            $response['csv_preview'] = substr($reportData['csv'] ?? '', 0, 2000);
            $response['csv_lines'] = count(explode("\n", trim($reportData['csv'] ?? '')));
            $response['parsed_rows'] = count($reportData['rows']);
        }
        
        jsonResponse($response);

    } catch (Exception $e) {
        jsonResponse(['error' => 'Erro GAM: ' . $e->getMessage()], 500);
    }
}

function getGAMToken($credentials) {
    $now = time();
    $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = base64url_encode(json_encode([
        'iss' => $credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/admanager',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));

    $signatureInput = "{$header}.{$claim}";
    $privateKey = openssl_pkey_get_private($credentials['private_key']);
    
    if (!$privateKey) {
        throw new Exception('Chave privada inválida no JSON.');
    }
    
    openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $jwt = "{$signatureInput}." . base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $responseText = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("cURL error getting token: {$curlError}");
    }

    $response = json_decode($responseText, true);
    
    if ($httpCode !== 200 || !isset($response['access_token'])) {
        $errMsg = $response['error_description'] ?? $response['error'] ?? "HTTP {$httpCode}";
        throw new Exception("OAuth2 token error: {$errMsg}");
    }

    return $response['access_token'];
}

function fetchGAMReportSOAP($networkCode, $token, $since, $until, $debug = false) {
    // GAM uses SOAP API for Report Service
    $apiVersion = 'v202602';
    $soapUrl = "https://ads.google.com/apis/ads/publisher/{$apiVersion}/ReportService";
    
    // Build date components
    $sinceY = date('Y', strtotime($since));
    $sinceM = date('n', strtotime($since));
    $sinceD = date('j', strtotime($since));
    $untilY = date('Y', strtotime($until));
    $untilM = date('n', strtotime($until));
    $untilD = date('j', strtotime($until));

    // Step 1: Run Report Job
    $runReportXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="https://www.google.com/apis/ads/publisher/{$apiVersion}">
  <soapenv:Header>
    <ns:RequestHeader>
      <ns:networkCode>{$networkCode}</ns:networkCode>
      <ns:applicationName>BussolaDoTrafego</ns:applicationName>
    </ns:RequestHeader>
  </soapenv:Header>
  <soapenv:Body>
    <ns:runReportJob>
      <ns:reportJob>
        <ns:reportQuery>
          <ns:dimensions>DATE</ns:dimensions>
          <ns:dimensions>CUSTOM_CRITERIA</ns:dimensions>
          <ns:columns>AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE</ns:columns>
          <ns:startDate>
            <ns:year>{$sinceY}</ns:year>
            <ns:month>{$sinceM}</ns:month>
            <ns:day>{$sinceD}</ns:day>
          </ns:startDate>
          <ns:endDate>
            <ns:year>{$untilY}</ns:year>
            <ns:month>{$untilM}</ns:month>
            <ns:day>{$untilD}</ns:day>
          </ns:endDate>
          <ns:dateRangeType>CUSTOM_DATE</ns:dateRangeType>
        </ns:reportQuery>
      </ns:reportJob>
    </ns:runReportJob>
  </soapenv:Body>
</soapenv:Envelope>
XML;

    $runResult = soapRequest($soapUrl, $runReportXml, $token, $networkCode);
    
    // Extract report job ID
    preg_match('/<id>(\d+)<\/id>/', $runResult, $matches);
    if (empty($matches[1])) {
        // Check for error
        if (strpos($runResult, 'NOT_FOUND') !== false || strpos($runResult, 'PERMISSION_DENIED') !== false) {
            throw new Exception('Sem permissão no GAM. Verifique se o Service Account tem acesso.');
        }
        throw new Exception('Falha ao criar report job. Resposta: ' . substr($runResult, 0, 500));
    }
    $reportJobId = $matches[1];

    // Step 2: Poll until report is ready
    $maxAttempts = 30;
    $reportReady = false;
    for ($i = 0; $i < $maxAttempts; $i++) {
        sleep(2);
        $statusXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="https://www.google.com/apis/ads/publisher/{$apiVersion}">
  <soapenv:Header>
    <ns:RequestHeader>
      <ns:networkCode>{$networkCode}</ns:networkCode>
      <ns:applicationName>BussolaDoTrafego</ns:applicationName>
    </ns:RequestHeader>
  </soapenv:Header>
  <soapenv:Body>
    <ns:getReportJobStatus>
      <ns:reportJobId>{$reportJobId}</ns:reportJobId>
    </ns:getReportJobStatus>
  </soapenv:Body>
</soapenv:Envelope>
XML;
        $statusResult = soapRequest($soapUrl, $statusXml, $token, $networkCode);
        
        if (strpos($statusResult, 'COMPLETED') !== false) {
            $reportReady = true;
            break;
        }
        if (strpos($statusResult, 'FAILED') !== false) {
            throw new Exception('Report job falhou no GAM.');
        }
    }

    if (!$reportReady) {
        throw new Exception('Timeout aguardando report do GAM.');
    }

    // Step 3: Get download URL
    $downloadXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="https://www.google.com/apis/ads/publisher/{$apiVersion}">
  <soapenv:Header>
    <ns:RequestHeader>
      <ns:networkCode>{$networkCode}</ns:networkCode>
      <ns:applicationName>BussolaDoTrafego</ns:applicationName>
    </ns:RequestHeader>
  </soapenv:Header>
  <soapenv:Body>
    <ns:getReportDownloadURL>
      <ns:reportJobId>{$reportJobId}</ns:reportJobId>
      <ns:exportFormat>CSV_DUMP</ns:exportFormat>
    </ns:getReportDownloadURL>
  </soapenv:Body>
</soapenv:Envelope>
XML;
    $downloadResult = soapRequest($soapUrl, $downloadXml, $token, $networkCode);
    
    // Extract URL
    preg_match('/<rval>(.*?)<\/rval>/', $downloadResult, $urlMatches);
    if (empty($urlMatches[1])) {
        throw new Exception('Falha ao obter URL de download do report.');
    }
    $downloadUrl = html_entity_decode($urlMatches[1]);

    // Step 4: Download CSV
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',  // Auto-detect and decompress
    ]);
    $csvContent = curl_exec($ch);
    curl_close($ch);

    // Manual gzip fallback
    if (substr($csvContent, 0, 2) === "\x1f\x8b") {
        $csvContent = gzdecode($csvContent);
    }

    // Step 5: Parse CSV
    $rows = parseGAMCsv($csvContent);
    return ['rows' => $rows, 'csv' => $csvContent];
}

function soapRequest($url, $xml, $token, $networkCode) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml; charset=UTF-8',
            'Authorization: Bearer ' . $token,
            'SOAPAction: ""',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("cURL SOAP error: {$curlError}");
    }
    
    if ($httpCode >= 400) {
        // Extract SOAP fault
        preg_match('/<faultstring>(.*?)<\/faultstring>/s', $response, $faultMatch);
        $fault = $faultMatch[1] ?? "HTTP {$httpCode}";
        throw new Exception("GAM SOAP error: {$fault}");
    }

    return $response;
}

function parseGAMCsv($csvContent) {
    $data = [];
    if (empty($csvContent)) return $data;

    $lines = explode("\n", trim($csvContent));
    if (count($lines) < 2) return $data;

    // First line is header - fix PHP 8.4 deprecation by providing escape param
    $headers = str_getcsv($lines[0], ',', '"', '');
    $headers = array_map('trim', $headers);
    
    // Find column indexes dynamically
    $dateCol = null;
    $targetingCol = null;
    $revenueCols = [];
    
    foreach ($headers as $i => $h) {
        $hLower = strtolower($h);
        // Date column
        if ((strpos($hLower, 'date') !== false || strpos($hLower, 'dimension.date') !== false) && $dateCol === null) {
            $dateCol = $i;
        }
        // Custom targeting / criteria / value ID column
        if (strpos($hLower, 'targeting') !== false || strpos($hLower, 'criteria') !== false || 
            strpos($hLower, 'custom') !== false || strpos($hLower, 'value') !== false) {
            $targetingCol = $i;
        }
        // All revenue columns (we'll sum them)
        if (strpos($hLower, 'revenue') !== false) {
            $revenueCols[] = $i;
        }
    }

    // Fallback column positions
    if ($dateCol === null) $dateCol = 0;
    if ($targetingCol === null) $targetingCol = 1;
    if (empty($revenueCols)) {
        // Last columns are likely revenue
        $revenueCols = [count($headers) - 1];
        if (count($headers) >= 4) $revenueCols[] = count($headers) - 2;
    }

    $maxCol = max(array_merge([$dateCol, $targetingCol], $revenueCols));

    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line) || strpos($line, 'Total') !== false) continue;
        
        // Fix PHP 8.4 deprecation
        $cols = str_getcsv($line, ',', '"', '');
        if (count($cols) <= $maxCol) continue;

        $dateVal = trim($cols[$dateCol] ?? '');
        $targeting = trim($cols[$targetingCol] ?? '');

        // Sum all revenue columns
        $totalRevenue = 0;
        foreach ($revenueCols as $rc) {
            $val = trim($cols[$rc] ?? '0');
            $totalRevenue += (float)str_replace(',', '', $val);
        }

        // Parse date (GAM usually gives YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateVal)) {
            $date = $dateVal;
        } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dateVal)) {
            $parts = explode('/', $dateVal);
            $date = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
        } else {
            continue;
        }

        // GAM CSV_DUMP revenue values are in micros (1/1,000,000 of currency)
        $revenueFloat = $totalRevenue / 1000000;

        // The targeting value from CUSTOM_CRITERIA can be various key-values
        // Only keep entries that are pure numeric IDs (campaign IDs)
        // Skip entries like utm_source=facebook, utm_content=..., adseleto_price_floor=..., etc.
        $utmCampaign = $targeting;
        
        // Strip utm_campaign= prefix if present
        $utmCampaign = preg_replace('/^(utm_campaign\s*=\s*)/', '', $utmCampaign);
        $utmCampaign = trim($utmCampaign);

        // Only import rows where the value is a pure numeric campaign ID
        if (!empty($utmCampaign) && preg_match('/^\d{10,}$/', $utmCampaign) && $revenueFloat > 0) {
            $data[] = [
                'date' => $date,
                'utm_campaign' => $utmCampaign,
                'revenue' => $revenueFloat,
            ];
        }
    }

    return $data;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function crossReferenceAfterGAM() {
    $cotacao = (float)getSetting('cotacao_dolar', '5.80');
    $revenues = fetchAll("
        SELECT date, campaign_id, SUM(receita_usd) as total_receita
        FROM revenue GROUP BY date, campaign_id
    ");

    foreach ($revenues as $r) {
        $receitaBrl = $r['total_receita'] * $cotacao;
        $campaign = fetchOne("
            SELECT id, investimento FROM fb_campaigns
            WHERE date = ? AND campaign_id = ?
        ", [$r['date'], $r['campaign_id']]);

        if ($campaign) {
            $invest = (float)$campaign['investimento'];
            $roas = $invest > 0 ? (($receitaBrl - $invest) / $invest) * 100 : 0;
            $profit = $receitaBrl - $invest;

            update('fb_campaigns', [
                'receita_usd' => $r['total_receita'],
                'receita_brl' => $receitaBrl,
                'roas' => $roas,
                'profit' => $profit,
            ], 'id = ?', [$campaign['id']]);
        }
    }
}

function testGAM() {
    $auth = getGAMAuth();

    if (!$auth) {
        jsonResponse(['error' => 'GAM não configurado. Conecte via Google Social Login ou configure Service Account.'], 400);
    }

    jsonResponse([
        'success' => true,
        'message' => 'Conexão GAM OK!',
        'network_code' => $auth['network_code'],
        'source' => $auth['source'],
        'auth_user' => $auth['user_name'],
        'token_obtained' => true,
    ]);
}
