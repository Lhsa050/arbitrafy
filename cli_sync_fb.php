<?php
/**
 * CLI Facebook Sync - Self-contained script
 * Runs in background, bypasses PHP built-in server timeout
 * Supports multiple OAuth connections (fb_connections) with manual token fallback.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(300);
ini_set('display_errors', 1);

// Fake session for auth
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION['user'] = 'admin';

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

echo "=== FB CLI Sync ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

$version = getSetting('fb_api_version', 'v24.0');

// ============================================
// Get token sources (OAuth first, then manual)
// ============================================
$tokenSources = [];

// Priority 1: OAuth connections
try {
    $connections = fetchAll("SELECT * FROM fb_connections WHERE status = 'active'");
    foreach ($connections as $conn) {
        $selectedAccounts = json_decode($conn['selected_accounts'], true) ?: [];
        if (!empty($selectedAccounts) && !empty($conn['access_token'])) {
            $tokenSources[] = [
                'token' => $conn['access_token'],
                'accounts' => $selectedAccounts,
                'source' => 'OAuth: ' . ($conn['fb_user_name'] ?? 'Unknown'),
                'connection_id' => $conn['id'],
            ];
        }
    }
} catch (Exception $e) {
    echo "Note: fb_connections table not found, using manual token.\n";
}

// Priority 2: Manual token fallback
if (empty($tokenSources)) {
    $token = getSetting('fb_access_token', '');
    $accountsJson = getSetting('fb_ad_accounts', '[]');
    $accounts = json_decode($accountsJson, true) ?: [];

    if (empty($token)) {
        echo "FATAL: Nenhum token configurado (OAuth ou manual)\n";
        exit(1);
    }

    if (empty($accounts)) {
        echo "FATAL: Nenhuma conta de anúncio configurada\n";
        exit(1);
    }

    $tokenSources[] = [
        'token' => $token,
        'accounts' => $accounts,
        'source' => 'Manual Token',
        'connection_id' => null,
    ];
}

echo "Sources: " . count($tokenSources) . "\n";
foreach ($tokenSources as $i => $src) {
    echo "  [{$i}] {$src['source']} — " . count($src['accounts']) . " contas\n";
}
echo "API Version: {$version}\n\n";

$totalImported = 0;
$errors = [];

$since = date('Y-m-d', strtotime('-30 days'));
$until = date('Y-m-d');

foreach ($tokenSources as $source) {
    $token = $source['token'];
    echo "--- Source: {$source['source']} ---\n";

    foreach ($source['accounts'] as $account) {
        $accountId = $account['id'];
        $accountName = $account['name'];

        // Ensure act_ prefix
        if (strpos($accountId, 'act_') !== 0) {
            $accountId = 'act_' . $accountId;
        }

        echo "Syncing: {$accountName} ({$accountId})\n";
        echo "  Period: {$since} to {$until}\n";

        $url = "https://graph.facebook.com/{$version}/{$accountId}/insights?" . http_build_query([
            'level' => 'campaign',
            'fields' => 'date_start,date_stop,campaign_id,campaign_name,spend,impressions,inline_link_clicks,actions,conversions,cost_per_action_type,cost_per_conversion,cost_per_inline_link_click,inline_link_click_ctr',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'limit' => 500,
            'access_token' => $token,
        ]);

        $allData = [];
        $pageUrl = $url;
        $page = 1;

        while ($pageUrl) {
            echo "  Fetching page {$page}...\n";
            $result = fbRequestCli($pageUrl);

            if (isset($result['error'])) {
                $errorMsg = $result['error']['message'] ?? 'Unknown error';
                $errorCode = $result['error']['code'] ?? 0;
                echo "  ERROR: {$errorMsg}\n";
                $errors[] = "{$accountName}: {$errorMsg}";
                
                // Mark connection as expired on token error
                if ($errorCode == 190 && $source['connection_id']) {
                    try {
                        update('fb_connections', [
                            'status' => 'expired',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ], 'id = ?', [$source['connection_id']]);
                        echo "  ⚠ Token marked as expired.\n";
                    } catch (Exception $e) {}
                }

                insert('fb_logs', [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'conta' => $accountId,
                    'http_code' => $errorCode,
                    'mensagem' => $errorMsg,
                    'url' => substr($url, 0, 200),
                ]);
                break;
            }

            $data = $result['data'] ?? [];
            $allData = array_merge($allData, $data);

            // Pagination
            $pageUrl = $result['paging']['next'] ?? null;
            $page++;
        }

        echo "  Got " . count($allData) . " rows\n";

        foreach ($allData as $row) {
            $vizLp = 0;
            $results = 0;
            $costPerResult = 0;

            // Detect "Resultados": custom conversions first (e.g. clicouAd), then actions fallback
            $bestAction = null;
            $bestSource = 'actions';

            // Pass 1: Check 'conversions' array (custom pixel events from Events Manager)
            foreach ($row['conversions'] ?? [] as $conv) {
                if (!$bestAction) {
                    $bestAction = $conv['action_type'];
                    $results = (int)$conv['value'];
                    $bestSource = 'conversions';
                }
            }

            // Pass 2: Check 'actions' for custom events (offsite_conversion.custom.*)
            if (!$bestAction) {
                foreach ($row['actions'] ?? [] as $act) {
                    if ($act['action_type'] === 'landing_page_view') {
                        $vizLp = (int)$act['value'];
                    }
                    if (!$bestAction && strpos($act['action_type'], 'offsite_conversion.custom') === 0) {
                        $bestAction = $act['action_type'];
                        $results = (int)$act['value'];
                    }
                }
            } else {
                // Still extract viz_lp
                foreach ($row['actions'] ?? [] as $act) {
                    if ($act['action_type'] === 'landing_page_view') {
                        $vizLp = (int)$act['value'];
                        break;
                    }
                }
            }

            // Pass 3: Fallback to standard actions
            if (!$bestAction) {
                $fallback = ['link_click', 'landing_page_view', 'offsite_conversion.fb_pixel_purchase', 'offsite_conversion.fb_pixel_lead', 'page_engagement'];
                foreach ($fallback as $fb) {
                    foreach ($row['actions'] ?? [] as $act) {
                        if ($act['action_type'] === $fb) {
                            $bestAction = $fb;
                            $results = (int)$act['value'];
                            break 2;
                        }
                    }
                }
            }

            // Get cost per result from the matching source
            if ($bestAction) {
                $costArray = ($bestSource === 'conversions')
                    ? ($row['cost_per_conversion'] ?? [])
                    : ($row['cost_per_action_type'] ?? []);
                foreach ($costArray as $cpa) {
                    if ($cpa['action_type'] === $bestAction) {
                        $costPerResult = (float)$cpa['value'];
                        break;
                    }
                }
            }

            $invest = (float)($row['spend'] ?? 0);
            $impressoes = (int)($row['impressions'] ?? 0);
            $cliques = (int)($row['inline_link_clicks'] ?? 0);
            $cpm = $impressoes > 0 ? ($invest / $impressoes) * 1000 : 0;

            $insertData = [
                'account_name' => $accountName,
                'date' => $row['date_start'],
                'campaign_id' => $row['campaign_id'],
                'campaign_name' => $row['campaign_name'] ?? '',
                'investimento' => $invest,
                'impressoes' => $impressoes,
                'cliques' => $cliques,
                'viz_lp' => $vizLp,
                'cpc_ads' => (float)($row['cost_per_inline_link_click'] ?? 0),
                'ctr_ads' => (float)($row['inline_link_click_ctr'] ?? 0),
                'cpm' => $cpm,
                'conv_pct' => $results,
                'cr_pct' => $costPerResult,
                'receita_usd' => 0,
                'receita_brl' => 0,
                'roas' => 0,
                'rpc' => 0,
                'profit' => 0,
            ];

            upsert('fb_campaigns', $insertData, ['account_name', 'date', 'campaign_id']);
            $totalImported++;
        }

        echo "  Imported: {$totalImported} total\n";
    }
}

echo "\nImported: {$totalImported}\n";
if (!empty($errors)) {
    echo "Errors: " . implode('; ', $errors) . "\n";
}
echo "=== DONE ===\n";

function fbRequestCli($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => ['message' => "cURL: {$error}", 'code' => 0]];
    }

    return json_decode($response, true) ?: ['error' => ['message' => 'Invalid JSON response', 'code' => 0]];
}
