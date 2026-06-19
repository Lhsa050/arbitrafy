<?php
/**
 * Facebook Ads API Integration
 * Supports multiple connections via OAuth (fb_connections) with manual token fallback.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'sync':
        syncFacebookAds();
        break;
    case 'test':
        testConnection();
        break;
    default:
        jsonResponse(['error' => 'Ação inválida'], 400);
}

/**
 * Get all active connections and their selected accounts.
 * Falls back to manual token if no OAuth connections exist.
 * 
 * Returns array of: ['token' => string, 'accounts' => [['id', 'name'], ...], 'source' => string]
 */
function getActiveTokensAndAccounts() {
    $result = [];
    $version = getSetting('fb_api_version', 'v24.0');

    // Priority 1: OAuth connections from fb_connections
    try {
        $connections = fetchAll("SELECT * FROM fb_connections WHERE status = 'active'");
    } catch (Exception $e) {
        $connections = [];
    }

    foreach ($connections as $conn) {
        $selectedAccounts = json_decode($conn['selected_accounts'], true) ?: [];
        if (!empty($selectedAccounts) && !empty($conn['access_token'])) {
            $result[] = [
                'token' => $conn['access_token'],
                'accounts' => $selectedAccounts,
                'source' => 'oauth',
                'connection_id' => $conn['id'],
                'user_name' => $conn['fb_user_name'] ?? 'OAuth',
            ];
        }
    }

    // Priority 2: Manual token fallback
    if (empty($result)) {
        $token = getSetting('fb_access_token', '');
        $accountsJson = getSetting('fb_ad_accounts', '[]');
        $accounts = json_decode($accountsJson, true) ?: [];

        if (!empty($token) && !empty($accounts)) {
            $result[] = [
                'token' => $token,
                'accounts' => $accounts,
                'source' => 'manual',
                'connection_id' => null,
                'user_name' => 'Token Manual',
            ];
        }
    }

    return $result;
}

function syncFacebookAds() {
    $version = getSetting('fb_api_version', 'v24.0');
    $tokenSources = getActiveTokensAndAccounts();

    if (empty($tokenSources)) {
        jsonResponse(['error' => 'Nenhuma conta Facebook configurada. Conecte via login social ou configure um token manual em Configurações.'], 400);
    }

    $totalImported = 0;
    $errors = [];

    // Sync last 30 days
    $since = date('Y-m-d', strtotime('-30 days'));
    $until = date('Y-m-d');

    foreach ($tokenSources as $source) {
        $token = $source['token'];
        $accounts = $source['accounts'];
        $sourceName = $source['user_name'];

        foreach ($accounts as $account) {
            $accountId = $account['id'];
            $accountName = $account['name'];

            // Ensure account ID has 'act_' prefix
            if (strpos($accountId, 'act_') !== 0) {
                $accountId = 'act_' . $accountId;
            }

            $url = "https://graph.facebook.com/{$version}/{$accountId}/insights?" . http_build_query([
                'level' => 'campaign',
                'fields' => 'date_start,date_stop,campaign_id,campaign_name,spend,impressions,inline_link_clicks,actions,cost_per_inline_link_click,inline_link_click_ctr',
                'time_increment' => 1,
                'time_range' => json_encode(['since' => $since, 'until' => $until]),
                'limit' => 500,
                'access_token' => $token,
            ]);

            $result = fbRequest($url);

            if (isset($result['error'])) {
                $errorMsg = $result['error']['message'] ?? 'Erro desconhecido';
                $errorCode = $result['error']['code'] ?? 0;
                $errors[] = "{$accountName} ({$sourceName}): {$errorMsg}";

                // If token expired (code 190), mark connection as expired
                if ($errorCode == 190 && $source['connection_id']) {
                    try {
                        update('fb_connections', [
                            'status' => 'expired',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ], 'id = ?', [$source['connection_id']]);
                    } catch (Exception $e) {}
                }

                // Log the error
                insert('fb_logs', [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'conta' => $accountId,
                    'http_code' => $errorCode,
                    'mensagem' => $errorMsg,
                    'url' => $url,
                ]);
                continue;
            }

            $data = $result['data'] ?? [];

            foreach ($data as $row) {
                // Extract landing_page_view from actions array (same as Google Apps Script)
                $vizLp = extractActionValue($row['actions'] ?? [], 'landing_page_view');

                $insertData = [
                    'account_name' => $accountName,
                    'date' => $row['date_start'],
                    'campaign_id' => $row['campaign_id'],
                    'campaign_name' => $row['campaign_name'] ?? '',
                    'investimento' => (float)($row['spend'] ?? 0),
                    'impressoes' => (int)($row['impressions'] ?? 0),
                    'cliques' => (int)($row['inline_link_clicks'] ?? 0),
                    'viz_lp' => $vizLp,
                    'cpc_ads' => (float)($row['cost_per_inline_link_click'] ?? 0),
                    'ctr_ads' => (float)($row['inline_link_click_ctr'] ?? 0),
                ];

                try {
                    upsert('fb_campaigns', $insertData, ['date', 'campaign_id', 'account_name']);
                    $totalImported++;
                } catch (Exception $e) {
                    // Skip
                }
            }

            // Handle pagination
            while (isset($result['paging']['next'])) {
                $result = fbRequest($result['paging']['next']);
                if (isset($result['data'])) {
                    foreach ($result['data'] as $row) {
                        $vizLp = extractActionValue($row['actions'] ?? [], 'landing_page_view');
                        $insertData = [
                            'account_name' => $accountName,
                            'date' => $row['date_start'],
                            'campaign_id' => $row['campaign_id'],
                            'campaign_name' => $row['campaign_name'] ?? '',
                            'investimento' => (float)($row['spend'] ?? 0),
                            'impressoes' => (int)($row['impressions'] ?? 0),
                            'cliques' => (int)($row['inline_link_clicks'] ?? 0),
                            'viz_lp' => $vizLp,
                            'cpc_ads' => (float)($row['cost_per_inline_link_click'] ?? 0),
                            'ctr_ads' => (float)($row['inline_link_click_ctr'] ?? 0),
                        ];
                        try {
                            upsert('fb_campaigns', $insertData, ['date', 'campaign_id', 'account_name']);
                            $totalImported++;
                        } catch (Exception $e) {}
                    }
                }
            }
        }
    }

    // After sync, cross-reference with revenue data
    crossReferenceRevenue();

    // Update compass daily
    updateCompassDaily();

    $sourceCount = 0;
    foreach ($tokenSources as $s) {
        $sourceCount += count($s['accounts']);
    }

    $response = [
        'success' => true,
        'message' => "Sincronizados {$totalImported} registros de {$sourceCount} contas.",
        'imported' => $totalImported,
    ];

    if (!empty($errors)) {
        $response['warnings'] = $errors;
        $response['message'] .= " Avisos: " . implode('; ', $errors);
    }

    jsonResponse($response);
}

function testConnection() {
    $tokenSources = getActiveTokensAndAccounts();
    
    if (empty($tokenSources)) {
        jsonResponse(['error' => 'Nenhuma conta configurada. Conecte via Facebook ou configure um token manual.'], 400);
    }

    $version = getSetting('fb_api_version', 'v24.0');
    $token = $tokenSources[0]['token'];
    $source = $tokenSources[0]['source'];

    $url = "https://graph.facebook.com/{$version}/me?access_token={$token}";
    $result = fbRequest($url);

    if (isset($result['error'])) {
        jsonResponse(['error' => $result['error']['message'] ?? 'Erro de conexão'], 400);
    }

    jsonResponse([
        'success' => true, 
        'user' => $result['name'] ?? 'OK',
        'source' => $source,
        'accounts' => count($tokenSources[0]['accounts']),
    ]);
}

function fbRequest($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => ['message' => "cURL error: {$error}", 'code' => 0]];
    }

    $data = json_decode($response, true);
    if ($httpCode >= 400) {
        return ['error' => $data['error'] ?? ['message' => "HTTP {$httpCode}", 'code' => $httpCode]];
    }

    return $data;
}

/**
 * Extract action value from Facebook's actions array
 * Mirrors the Google Apps Script extractActionValue_ function
 */
function extractActionValue($actions, $actionType) {
    if (!is_array($actions)) return 0;
    foreach ($actions as $action) {
        if (isset($action['action_type']) && $action['action_type'] === $actionType) {
            return (int)($action['value'] ?? 0);
        }
    }
    return 0;
}

function crossReferenceRevenue() {
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

function updateCompassDaily() {
    $impostoFb = (float)getSetting('imposto_facebook', '0.125');
    $impostoOutros = (float)getSetting('imposto_outros', '0.16');
    $cotacao = (float)getSetting('cotacao_dolar', '5.80');

    // Get daily FB spend
    $dailySpend = fetchAll("
        SELECT date, SUM(investimento) as total_invest
        FROM fb_campaigns
        GROUP BY date
    ");

    // Get daily revenue
    $dailyRevenue = fetchAll("
        SELECT date, SUM(receita_usd) as total_revenue
        FROM revenue
        GROUP BY date
    ");

    $revenueMap = [];
    foreach ($dailyRevenue as $r) {
        $revenueMap[$r['date']] = (float)$r['total_revenue'];
    }

    foreach ($dailySpend as $d) {
        $date = $d['date'];
        $invest = (float)$d['total_invest'];
        $receita = $revenueMap[$date] ?? 0;
        $receitaBrl = $receita * $cotacao;
        $lucro = $receitaBrl - $invest;
        $imposto = $invest * $impostoFb;
        $roi = $invest > 0 ? ($receitaBrl - $invest) / $invest : 0;
        $lucroLiq = $lucro - $imposto;
        $roiLiq = $invest > 0 ? ($lucroLiq) / $invest : 0;

        $year = (int)date('Y', strtotime($date));

        $compassData = [
            'year' => $year,
            'date' => $date,
            'month_name' => getMonthName((int)date('n', strtotime($date))),
            'investimento' => $invest,
            'receita_usd' => $receita,
            'lucro_bruto' => $lucro,
            'roi_bruto' => $roi,
            'imposto' => $imposto,
            'lucro_liquido' => $lucroLiq,
            'roi_liquido' => $roiLiq,
        ];

        upsert('compass_daily', $compassData, ['year', 'date']);
    }
}
