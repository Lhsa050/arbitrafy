<?php
/**
 * CLI GAM Sync v3 - Two-pass approach for accurate revenue
 * Pass 1: DATE-only report for total daily revenue (matches GAM UI)
 * Pass 2: DATE+CUSTOM_CRITERIA report for campaign breakdown
 */
error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(300);
ini_set('display_errors', 1);

session_start();
$_SESSION['user'] = 'admin';

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

echo "=== GAM CLI Sync v3 ===\n\n";

$token = null;
$networkCode = '';

// Priority 1: OAuth connections from google_connections
try {
    $connections = fetchAll("SELECT * FROM google_connections WHERE status = 'active'");
    foreach ($connections as $conn) {
        $gamSelected = json_decode($conn['gam_selected'], true) ?: [];
        if (!empty($gamSelected) && !empty($conn['refresh_token'])) {
            echo "Using OAuth: " . ($conn['google_user_name'] ?? 'Unknown') . "\n";
            
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
            $tokenResponse = json_decode(curl_exec($ch), true);
            curl_close($ch);
            
            if (!empty($tokenResponse['access_token'])) {
                $token = $tokenResponse['access_token'];
                $networkCode = $gamSelected[0]['network_code'] ?? getSetting('gam_network_code', '');
                echo "Token: OK (OAuth)\n";
                break;
            } else {
                echo "OAuth token refresh failed, trying Service Account...\n";
            }
        }
    }
} catch (Exception $e) {
    echo "Note: google_connections not available, trying Service Account...\n";
}

// Priority 2: Service Account fallback
if (!$token) {
    $networkCode = getSetting('gam_network_code', '');
    $path = getSetting('gam_service_account_path', '');
    if (empty($path)) $path = getSetting('gam_service_account_json', '');

    if (empty($networkCode) || empty($path)) {
        echo "FATAL: GAM network code or credentials not configured\n";
        exit(1);
    }

    $fullPath = __DIR__ . '/' . $path;
    if (!file_exists($fullPath)) {
        echo "FATAL: Service account file not found: {$fullPath}\n";
        exit(1);
    }

    $credentials = json_decode(file_get_contents($fullPath), true);

    echo "Getting OAuth token (Service Account)...\n";
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
    $tokenResponse = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($tokenResponse['access_token'])) {
        echo "FATAL: Failed to get OAuth token\n";
        print_r($tokenResponse);
        exit(1);
    }

    $token = $tokenResponse['access_token'];
    echo "Token: OK (Service Account)\n";
}

$apiVersion = 'v202602';
$soapUrl = "https://ads.google.com/apis/ads/publisher/{$apiVersion}/ReportService";
$since = date('Y-m-d', strtotime('-30 days'));
$until = date('Y-m-d');

$sinceY = date('Y', strtotime($since));
$sinceM = date('n', strtotime($since));
$sinceD = date('j', strtotime($since));
$untilY = date('Y', strtotime($until));
$untilM = date('n', strtotime($until));
$untilD = date('j', strtotime($until));

echo "Period: {$since} to {$until}\n\n";

// ====================================================
// PASS 1: DATE-only report for total daily revenue
// This matches the GAM UI Overview exactly
// ====================================================
echo "=== PASS 1: Total Daily Revenue ===\n";

$reportXml1 = <<<XML
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
          <ns:columns>AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE</ns:columns>
          <ns:columns>AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS</ns:columns>
          <ns:columns>AD_EXCHANGE_AD_REQUESTS</ns:columns>
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

$csv1 = runAndDownloadReport($soapUrl, $reportXml1, $token, $networkCode, $apiVersion);
if (!$csv1) {
    echo "FATAL: Failed to get daily revenue report\n";
    exit(1);
}

// Parse daily totals
$dailyTotals = [];
$lines1 = explode("\n", trim($csv1));
echo "Daily report lines: " . count($lines1) . "\n";
echo "Headers: {$lines1[0]}\n\n";

for ($i = 1; $i < count($lines1); $i++) {
    $line = trim($lines1[$i]);
    if (empty($line) || strpos($line, 'Total') !== false) continue;
    
    $cols = str_getcsv($line, ',', '"', '');
    if (count($cols) < 2) continue;
    
    $date = $cols[0];
    $revenueMicros = (float)$cols[1];
    $impressions = isset($cols[2]) ? (int)$cols[2] : 0;
    $adRequests = isset($cols[3]) ? (int)$cols[3] : 0;
    $revenueUsd = $revenueMicros / 1000000;
    
    $dailyTotals[$date] = [
        'revenue_usd' => $revenueUsd,
        'impressions' => $impressions,
        'ad_requests' => $adRequests,
    ];
    echo "  {$date}: \${$revenueUsd} ({$impressions} imp, {$adRequests} ad_req)\n";
}

echo "\nTotal days with revenue: " . count($dailyTotals) . "\n";

// ====================================================
// PASS 2: DATE+CUSTOM_CRITERIA for campaign breakdown
// ====================================================
echo "\n=== PASS 2: Campaign Breakdown ===\n";

$reportXml2 = <<<XML
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
          <ns:columns>AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS</ns:columns>
          <ns:columns>AD_EXCHANGE_AD_REQUESTS</ns:columns>
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

$csv2 = runAndDownloadReport($soapUrl, $reportXml2, $token, $networkCode, $apiVersion);

// Parse campaign-level data
$campaignRevenue = []; // [date][campaign_id] => ['revenue_usd' => ..., 'impressions' => ...]
if ($csv2) {
    $lines2 = explode("\n", trim($csv2));
    echo "Campaign report lines: " . count($lines2) . "\n\n";
    
    for ($i = 1; $i < count($lines2); $i++) {
        $line = trim($lines2[$i]);
        if (empty($line) || strpos($line, 'Total') !== false) continue;
        
        $cols = str_getcsv($line, ',', '"', '');
        if (count($cols) < 3) continue;
        
        $date = $cols[0];
        $keyValue = $cols[1];
        $revenue = (float)$cols[2];
        $impressions = isset($cols[3]) ? (int)$cols[3] : 0;
        $adRequests = isset($cols[4]) ? (int)$cols[4] : 0;
        
        // Only process utm_campaign entries
        if (preg_match('/^utm_campaign\s*=\s*(\d+)$/', $keyValue, $m)) {
            $campaignId = $m[1];
            $revenueDollars = $revenue / 1000000;
            
            if (!isset($campaignRevenue[$date])) $campaignRevenue[$date] = [];
            $campaignRevenue[$date][$campaignId] = [
                'revenue_usd' => $revenueDollars,
                'impressions' => $impressions,
                'ad_requests' => $adRequests,
            ];
        }
    }
}

// ====================================================
// IMPORT TO DB
// ====================================================
echo "\n=== IMPORTING TO DB ===\n";

// Clear old revenue data first
query("DELETE FROM revenue");
echo "Cleared old revenue data\n";

$imported = 0;

foreach ($dailyTotals as $date => $data) {
    $totalDayRevenue = $data['revenue_usd'];
    $totalDayImpressions = $data['impressions'];
    $totalDayAdRequests = $data['ad_requests'];
    $campaigns = $campaignRevenue[$date] ?? [];
    
    if (empty($campaigns)) {
        // No campaign breakdown - import as single "TOTAL" entry
        if ($totalDayRevenue > 0) {
            upsert('revenue', [
                'date' => $date,
                'campaign_id' => 'TOTAL',
                'utm_campaign' => 'TOTAL',
                'receita_usd' => $totalDayRevenue,
                'gam_impressions' => $totalDayImpressions,
                'gam_ad_requests' => $totalDayAdRequests,
            ], ['date', 'campaign_id']);
            $imported++;
            echo "  {$date}: \${$totalDayRevenue} ({$totalDayImpressions} imp, {$totalDayAdRequests} req, no breakdown)\n";
        }
    } else {
        // Calculate how much revenue is attributed to campaigns vs unattributed
        $campaignSum = array_sum(array_column($campaigns, 'revenue_usd'));
        $campaignImpSum = array_sum(array_column($campaigns, 'impressions'));
        $campaignReqSum = array_sum(array_column($campaigns, 'ad_requests'));
        
        // Import each campaign's share
        foreach ($campaigns as $cid => $cdata) {
            $rev = $cdata['revenue_usd'];
            $imp = $cdata['impressions'];
            $req = $cdata['ad_requests'];
            
            // Scale up proportionally so campaign revenues sum to total
            $scaledRev = $totalDayRevenue > 0 && $campaignSum > 0 
                ? $rev * ($totalDayRevenue / $campaignSum) 
                : $rev;
            $scaledImp = $totalDayImpressions > 0 && $campaignImpSum > 0
                ? (int)round($imp * ($totalDayImpressions / $campaignImpSum))
                : $imp;
            $scaledReq = $totalDayAdRequests > 0 && $campaignReqSum > 0
                ? (int)round($req * ($totalDayAdRequests / $campaignReqSum))
                : $req;
            
            upsert('revenue', [
                'date' => $date,
                'campaign_id' => $cid,
                'utm_campaign' => $cid,
                'receita_usd' => round($scaledRev, 6),
                'gam_impressions' => $scaledImp,
                'gam_ad_requests' => $scaledReq,
            ], ['date', 'campaign_id']);
            $imported++;
        }
        
        echo "  {$date}: \${$totalDayRevenue} total ({$totalDayImpressions} imp, {$totalDayAdRequests} req) | scaled\n";
    }
}

echo "\nImported: {$imported} records\n";
echo "=== DONE ===\n";


// ====================================================
// Helper functions
// ====================================================

function runAndDownloadReport($soapUrl, $runXml, $token, $networkCode, $apiVersion) {
    $runResult = soapRequestCli($soapUrl, $runXml, $token);
    preg_match('/<id>(\d+)<\/id>/', $runResult, $matches);
    $reportJobId = $matches[1] ?? '';
    if (!$reportJobId) {
        echo "  Failed to create report: " . substr($runResult, 0, 300) . "\n";
        return null;
    }
    echo "  Report Job ID: {$reportJobId}\n";
    
    // Poll for completion
    for ($i = 0; $i < 30; $i++) {
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
        $statusResult = soapRequestCli($soapUrl, $statusXml, $token);
        if (strpos($statusResult, 'COMPLETED') !== false) { echo "  COMPLETED!\n"; break; }
        if (strpos($statusResult, 'FAILED') !== false) { echo "  FAILED!\n"; return null; }
        echo "  Polling {$i}...\n";
    }
    
    // Download CSV
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
    $downloadResult = soapRequestCli($soapUrl, $downloadXml, $token);
    preg_match('/<rval>(.*?)<\/rval>/', $downloadResult, $urlMatches);
    $downloadUrl = html_entity_decode($urlMatches[1] ?? '');
    
    if (empty($downloadUrl)) {
        echo "  No download URL!\n";
        return null;
    }
    
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',
    ]);
    $csvContent = curl_exec($ch);
    curl_close($ch);
    
    if (substr($csvContent, 0, 2) === "\x1f\x8b") {
        $csvContent = gzdecode($csvContent);
    }
    
    echo "  CSV downloaded: " . strlen($csvContent) . " bytes\n";
    return $csvContent;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function soapRequestCli($url, $xml, $token) {
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
    return curl_exec($ch);
}
