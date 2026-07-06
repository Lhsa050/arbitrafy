<?php
/**
 * Sync API - Calls sync functions directly (no exec() needed)
 * Compatible with shared hosting where exec() is disabled
 */
error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(300);
ignore_user_abort(true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Ensure sync_logs table exists (auto-create if missing)
try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS sync_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        source VARCHAR(20) NOT NULL,
        level VARCHAR(20) DEFAULT 'ERROR',
        step VARCHAR(100),
        message TEXT,
        details TEXT,
        http_code INTEGER,
        duration_ms INTEGER
    )");
} catch (Exception $e) {
}

// Fallback: define logSync here if db.php doesn't have it yet
if (!function_exists('logSync')) {
    function logSync($source, $level, $step, $message, $details = '', $httpCode = null, $durationMs = null)
    {
        try {
            insert('sync_logs', [
                'timestamp' => date('Y-m-d H:i:s'),
                'source' => $source,
                'level' => $level,
                'step' => $step,
                'message' => $message,
                'details' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : $details,
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
            ]);
        } catch (Exception $e) {
        }
    }
}

if (ob_get_level())
    ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'sync_fb':
        doSyncFB();
        break;
    case 'sync_gam':
        doSyncGAM();
        break;
    case 'sync_google_ads':
        doSyncGoogleAds();
        break;
    case 'sync_ga4':
        doSyncGA4();
        break;
    case 'cross_ref':
        doCrossRefOnly();
        break;
    default:
        echo json_encode(['error' => 'Ação inválida. Use: sync_fb, sync_gam, sync_google_ads, sync_ga4 ou cross_ref']);
        exit;
}

function shouldSkipCrossRef()
{
    return ($_GET['skip_cross_ref'] ?? '') === '1';
}

function extractFBInsightResultMetrics($row)
{
    $vizLp = 0;
    $custoVizLp = 0;
    $results = 0;
    $costPerResult = 0;
    $bestAction = null;
    $bestSource = 'actions';

    foreach ($row['conversions'] ?? [] as $conv) {
        if (!$bestAction) {
            $bestAction = $conv['action_type'] ?? 'conversion';
            $results = (int) ($conv['value'] ?? 0);
            $bestSource = 'conversions';
        }
    }

    foreach ($row['actions'] ?? [] as $act) {
        if (($act['action_type'] ?? '') === 'landing_page_view') {
            $vizLp = (int) ($act['value'] ?? 0);
        }

        if (!$bestAction && strpos($act['action_type'] ?? '', 'offsite_conversion.custom') === 0) {
            $bestAction = $act['action_type'];
            $results = (int) ($act['value'] ?? 0);
        }
    }

    if (!$bestAction) {
        $fallback = ['link_click', 'landing_page_view', 'offsite_conversion.fb_pixel_purchase', 'offsite_conversion.fb_pixel_lead', 'page_engagement'];
        foreach ($fallback as $fb) {
            foreach ($row['actions'] ?? [] as $act) {
                if (($act['action_type'] ?? '') === $fb) {
                    $bestAction = $fb;
                    $results = (int) ($act['value'] ?? 0);
                    break 2;
                }
            }
        }
    }

    if ($bestAction) {
        $costArray = ($bestSource === 'conversions')
            ? ($row['cost_per_conversion'] ?? [])
            : ($row['cost_per_action_type'] ?? []);
        foreach ($costArray as $cpa) {
            if (($cpa['action_type'] ?? '') === $bestAction) {
                $costPerResult = (float) ($cpa['value'] ?? 0);
                break;
            }
        }
    }

    foreach ($row['cost_per_action_type'] ?? [] as $cpa) {
        if (($cpa['action_type'] ?? '') === 'landing_page_view') {
            $custoVizLp = (float) ($cpa['value'] ?? 0);
            break;
        }
    }

    return [
        'viz_lp' => $vizLp,
        'custo_viz_lp' => $custoVizLp,
        'results' => $results,
        'cost_per_result' => $costPerResult,
    ];
}

function parseFBHourlyStart($label)
{
    if (preg_match('/^(\d{1,2})/', (string) $label, $matches)) {
        $hour = (int) $matches[1];
        return max(0, min(23, $hour));
    }
    return 0;
}

function getFBSyncAccounts()
{
    $syncAccounts = [];

    try {
        $connections = fetchAll("SELECT * FROM fb_connections WHERE status = 'active'");
    } catch (Exception $e) {
        $connections = [];
    }

    foreach ($connections as $conn) {
        $token = $conn['access_token'] ?? '';
        if (empty($token)) {
            continue;
        }

        if (!empty($conn['token_expires_at']) && strtotime($conn['token_expires_at']) <= time()) {
            try {
                update('fb_connections', [
                    'status' => 'expired',
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$conn['id']]);
            } catch (Exception $e) {
            }
            logSync('FB', 'WARNING', 'oauth_expired', 'Conexao Facebook expirada: ' . ($conn['fb_user_name'] ?? 'OAuth'));
            continue;
        }

        $selectedAccounts = json_decode($conn['selected_accounts'] ?? '[]', true) ?: [];
        foreach ($selectedAccounts as $account) {
            $accountId = $account['id'] ?? $account['account_id'] ?? '';
            if ($accountId === '') {
                continue;
            }

            $syncAccounts[] = [
                'id' => $accountId,
                'name' => $account['name'] ?? $accountId,
                'token' => $token,
                'source' => 'oauth',
                'source_name' => $conn['fb_user_name'] ?? 'Facebook OAuth',
                'connection_id' => $conn['id'] ?? null,
            ];
        }
    }

    if (!empty($syncAccounts)) {
        return $syncAccounts;
    }

    $manualToken = getSetting('fb_access_token', '');
    $manualAccounts = json_decode(getSetting('fb_ad_accounts', '[]'), true) ?: [];
    foreach ($manualAccounts as $account) {
        $accountId = $account['id'] ?? $account['account_id'] ?? '';
        if ($manualToken === '' || $accountId === '') {
            continue;
        }

        $syncAccounts[] = [
            'id' => $accountId,
            'name' => $account['name'] ?? $accountId,
            'token' => $manualToken,
            'source' => 'manual',
            'source_name' => 'Token manual',
            'connection_id' => null,
        ];
    }

    return $syncAccounts;
}

function markFBSyncAccountExpired($syncAccount, $errorMessage = '')
{
    if (($syncAccount['source'] ?? '') !== 'oauth' || empty($syncAccount['connection_id'])) {
        return;
    }

    try {
        update('fb_connections', [
            'status' => 'expired',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$syncAccount['connection_id']]);
        logSync('FB', 'WARNING', 'oauth_expired', 'Conexao Facebook marcada como expirada: ' . ($syncAccount['source_name'] ?? 'OAuth'), $errorMessage);
    } catch (Exception $e) {
    }
}

function doSyncFB()
{
    $syncStart = microtime(true);
    $version = getSetting('fb_api_version', 'v24.0');
    $fbAccounts = getFBSyncAccounts();
    if (empty($fbAccounts)) {
        logSync('FB', 'ERROR', 'config', 'Nenhuma conta Facebook ativa configurada.');
        echo json_encode(['success' => false, 'error' => 'Nenhuma conta Facebook ativa configurada. Reconecte o Facebook ou atualize o token manual.']);
        exit;
    }
    $accounts = $fbAccounts;
    $token = '';

    $totalImported = 0;
    $errors = [];
    $mainErrorCount = 0;
    $since = date('Y-m-d', strtotime('-30 days'));
    $until = date('Y-m-d');

    // Ensure custo_viz_lp column exists
    try {
        getDB()->exec("ALTER TABLE fb_campaigns ADD COLUMN custo_viz_lp REAL DEFAULT 0");
    } catch (Exception $e) {
        // Column already exists
    }

    foreach ($accounts as $account) {
        $accountId = $account['id'];
        $accountName = $account['name'];
        $token = $account['token'] ?? $token;

        // Sync all configured accounts (no name filter)
        if (strpos($accountId, 'act_') !== 0)
            $accountId = 'act_' . $accountId;

        $url = "https://graph.facebook.com/{$version}/{$accountId}/insights?" . http_build_query([
            'level' => 'campaign',
            'fields' => 'date_start,date_stop,campaign_id,campaign_name,spend,impressions,inline_link_clicks,actions,conversions,cost_per_action_type,cost_per_conversion,cost_per_inline_link_click,inline_link_click_ctr',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'limit' => 500,
            'access_token' => $token,
        ]);

        $result = curlGet($url);

        if (isset($result['error'])) {
            $errMsg = $accountName . ': ' . ($result['error']['message'] ?? 'Erro');
            $errors[] = $errMsg;
            $mainErrorCount++;
            if (($result['error']['code'] ?? 0) == 190) {
                markFBSyncAccountExpired($account, $errMsg);
            }
            logSync('FB', 'ERROR', 'api_request', $errMsg, [
                'account' => $accountName,
                'error_type' => $result['error']['type'] ?? '',
                'error_code' => $result['error']['code'] ?? '',
            ]);
            continue;
        }

        $allData = $result['data'] ?? [];

        // Pagination
        while (isset($result['paging']['next'])) {
            $result = curlGet($result['paging']['next']);
            if (isset($result['data'])) {
                $allData = array_merge($allData, $result['data']);
            }
        }

        foreach ($allData as $row) {
            $metrics = extractFBInsightResultMetrics($row);
            $vizLp = $metrics['viz_lp'];
            $custoVizLp = $metrics['custo_viz_lp'];
            $results = $metrics['results'];
            $costPerResult = $metrics['cost_per_result'];

            $invest = (float) ($row['spend'] ?? 0);
            $impressoes = (int) ($row['impressions'] ?? 0);
            $cpm = $impressoes > 0 ? ($invest / $impressoes) * 1000 : 0;

            upsert('fb_campaigns', [
                'account_name' => $accountName,
                'date' => $row['date_start'],
                'campaign_id' => $row['campaign_id'],
                'campaign_name' => $row['campaign_name'] ?? '',
                'investimento' => $invest,
                'impressoes' => $impressoes,
                'cliques' => (int) ($row['inline_link_clicks'] ?? 0),
                'viz_lp' => $vizLp,
                'custo_viz_lp' => $custoVizLp,
                'cpc_ads' => (float) ($row['cost_per_inline_link_click'] ?? 0),
                'ctr_ads' => (float) ($row['inline_link_click_ctr'] ?? 0),
                'cpm' => $cpm,
                'conv_pct' => $results,
                'cr_pct' => $costPerResult,
            ], ['account_name', 'date', 'campaign_id']);
            $totalImported++;
        }
    }

    if ($totalImported === 0 && $mainErrorCount >= count($accounts) && !empty($errors)) {
        $durationMs = round((microtime(true) - $syncStart) * 1000);
        $message = 'Facebook nao sincronizou. Reconecte o Facebook ou atualize o token manual.';
        logSync('FB', 'ERROR', 'sync_failed', $message, implode("\n", $errors), null, $durationMs);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'message' => $message,
            'imported' => 0,
            'placements' => 0,
            'devices' => 0,
            'hourly' => 0,
            'warnings' => $errors,
        ]);
        exit;
    }

    // Cross-reference with revenue unless a final batched crossRef will run after parallel sync.
    if (!shouldSkipCrossRef()) {
        crossRef();
    }

    // ====================================================
    // Placement breakdown sync (spend per placement per campaign)
    // Uses FB API breakdowns=publisher_platform,platform_position
    // ====================================================
    $placementCount = 0;
    try {
        ensureFBPlacementsTable();
        
        foreach ($accounts as $account) {
            $accountId = $account['id'];
            $accountName = $account['name'];
            $token = $account['token'] ?? $token;
            if (strpos($accountId, 'act_') !== 0)
                $accountId = 'act_' . $accountId;

            $plUrl = "https://graph.facebook.com/{$version}/{$accountId}/insights?" . http_build_query([
                'level' => 'campaign',
                'fields' => 'date_start,campaign_id,campaign_name,spend,impressions,inline_link_clicks,inline_link_click_ctr,cost_per_inline_link_click',
                'breakdowns' => 'publisher_platform,platform_position',
                'time_increment' => 1,
                'time_range' => json_encode(['since' => $since, 'until' => $until]),
                'limit' => 500,
                'access_token' => $token,
            ]);

            $plResult = curlGet($plUrl);
            if (isset($plResult['error'])) {
                if (($plResult['error']['code'] ?? 0) == 190) {
                    markFBSyncAccountExpired($account, $plResult['error']['message'] ?? '');
                }
                logSync('FB', 'WARNING', 'placement_api', $accountName . ': ' . ($plResult['error']['message'] ?? 'Erro no placement breakdown'));
                continue;
            }

            $plAllData = $plResult['data'] ?? [];
            while (isset($plResult['paging']['next'])) {
                $plResult = curlGet($plResult['paging']['next']);
                if (isset($plResult['data'])) {
                    $plAllData = array_merge($plAllData, $plResult['data']);
                }
            }

            foreach ($plAllData as $plRow) {
                $spend = (float)($plRow['spend'] ?? 0);
                if ($spend <= 0) continue;

                $publisher = $plRow['publisher_platform'] ?? '';
                $position = $plRow['platform_position'] ?? '';
                $placementName = mapFBPlacementToUtm($publisher, $position);

                $impressions = (int)($plRow['impressions'] ?? 0);
                $clicks = (int)($plRow['inline_link_clicks'] ?? 0);
                $cpc = (float)($plRow['cost_per_inline_link_click'] ?? 0);
                $ctr = (float)($plRow['inline_link_click_ctr'] ?? 0);
                $cpm = $impressions > 0 ? ($spend / $impressions) * 1000 : 0;

                upsert('fb_placements', [
                    'date' => $plRow['date_start'],
                    'campaign_id' => $plRow['campaign_id'],
                    'campaign_name' => $plRow['campaign_name'] ?? '',
                    'account_name' => $accountName,
                    'placement' => $placementName,
                    'spend' => $spend,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'cpc' => $cpc,
                    'ctr' => $ctr,
                    'cpm' => $cpm,
                ], ['date', 'campaign_id', 'placement']);
                $placementCount++;
            }
        }
        logSync('FB', 'INFO', 'placement_sync', "FB placement sync: {$placementCount} registros importados");
    } catch (Exception $e) {
        logSync('FB', 'WARNING', 'placement_error', 'Erro no placement sync: ' . $e->getMessage());
    }

    // ====================================================
    // Device breakdown sync (spend per device per campaign)
    // Uses FB API breakdowns=impression_device
    // ====================================================
    $deviceCount = 0;
    try {
        if (!function_exists('ensureFBDevicesTable')) {
            logSync('FB', 'WARNING', 'device_sync', 'Função ensureFBDevicesTable não encontrada. Atualize o helpers.php.');
            throw new Exception('ensureFBDevicesTable não disponível');
        }
        ensureFBDevicesTable();
        
        foreach ($accounts as $account) {
            $accountId = $account['id'];
            $accountName = $account['name'];
            $token = $account['token'] ?? $token;
            if (strpos($accountId, 'act_') !== 0)
                $accountId = 'act_' . $accountId;

            $devUrl = "https://graph.facebook.com/{$version}/{$accountId}/insights?" . http_build_query([
                'level' => 'campaign',
                'fields' => 'date_start,campaign_id,campaign_name,spend,impressions,inline_link_clicks,inline_link_click_ctr,cost_per_inline_link_click',
                'breakdowns' => 'impression_device',
                'time_increment' => 1,
                'time_range' => json_encode(['since' => $since, 'until' => $until]),
                'limit' => 500,
                'access_token' => $token,
            ]);

            $devResult = curlGet($devUrl);
            if (isset($devResult['error'])) {
                if (($devResult['error']['code'] ?? 0) == 190) {
                    markFBSyncAccountExpired($account, $devResult['error']['message'] ?? '');
                }
                logSync('FB', 'WARNING', 'device_api', $accountName . ': ' . ($devResult['error']['message'] ?? 'Erro no device breakdown'));
                continue;
            }

            $devAllData = $devResult['data'] ?? [];
            while (isset($devResult['paging']['next'])) {
                $devResult = curlGet($devResult['paging']['next']);
                if (isset($devResult['data'])) {
                    $devAllData = array_merge($devAllData, $devResult['data']);
                }
            }

            foreach ($devAllData as $devRow) {
                $spend = (float)($devRow['spend'] ?? 0);
                if ($spend <= 0) continue;

                $deviceOS = $devRow['impression_device'] ?? 'Unknown';

                $impressions = (int)($devRow['impressions'] ?? 0);
                $clicks = (int)($devRow['inline_link_clicks'] ?? 0);
                $cpc = (float)($devRow['cost_per_inline_link_click'] ?? 0);
                $ctr = (float)($devRow['inline_link_click_ctr'] ?? 0);
                $cpm = $impressions > 0 ? ($spend / $impressions) * 1000 : 0;

                upsert('fb_devices', [
                    'date' => $devRow['date_start'],
                    'campaign_id' => $devRow['campaign_id'],
                    'campaign_name' => $devRow['campaign_name'] ?? '',
                    'account_name' => $accountName,
                    'device_os' => $deviceOS,
                    'spend' => $spend,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'cpc' => $cpc,
                    'ctr' => $ctr,
                    'cpm' => $cpm,
                ], ['date', 'campaign_id', 'device_os']);
                $deviceCount++;
            }
        }
        logSync('FB', 'INFO', 'device_sync', "FB device sync: {$deviceCount} registros importados");
    } catch (Exception $e) {
        logSync('FB', 'WARNING', 'device_error', 'Erro no device sync: ' . $e->getMessage());
    }

    // ====================================================
    // Hourly breakdown sync (advertiser timezone)
    // Uses FB API breakdowns=hourly_stats_aggregated_by_advertiser_time_zone
    // ====================================================
    $hourlyCount = 0;
    try {
        if (!function_exists('ensureFBHourlyTable')) {
            logSync('FB', 'WARNING', 'hourly_sync', 'Funcao ensureFBHourlyTable nao encontrada. Atualize o helpers.php.');
            throw new Exception('ensureFBHourlyTable nao disponivel');
        }
        ensureFBHourlyTable();

        foreach ($accounts as $account) {
            $accountId = $account['id'];
            $accountName = $account['name'];
            $token = $account['token'] ?? $token;
            if (strpos($accountId, 'act_') !== 0)
                $accountId = 'act_' . $accountId;

            $hourFields = 'date_start,campaign_id,campaign_name,spend,impressions,inline_link_clicks,actions,conversions,cost_per_action_type,cost_per_conversion,cost_per_inline_link_click,inline_link_click_ctr';
            $hourUrl = "https://graph.facebook.com/{$version}/{$accountId}/insights?" . http_build_query([
                'level' => 'campaign',
                'fields' => $hourFields,
                'breakdowns' => 'hourly_stats_aggregated_by_advertiser_time_zone',
                'time_increment' => 1,
                'time_range' => json_encode(['since' => $since, 'until' => $until]),
                'limit' => 500,
                'access_token' => $token,
            ]);

            $hourResult = curlGet($hourUrl);
            if (isset($hourResult['error'])) {
                if (($hourResult['error']['code'] ?? 0) == 190) {
                    markFBSyncAccountExpired($account, $hourResult['error']['message'] ?? '');
                }
                logSync('FB', 'WARNING', 'hourly_api_full', $accountName . ': tentando sync horario sem conversoes - ' . ($hourResult['error']['message'] ?? 'Erro no hourly breakdown'));

                $leanHourFields = 'date_start,campaign_id,campaign_name,spend,impressions,inline_link_clicks,inline_link_click_ctr,cost_per_inline_link_click';
                $hourUrl = "https://graph.facebook.com/{$version}/{$accountId}/insights?" . http_build_query([
                    'level' => 'campaign',
                    'fields' => $leanHourFields,
                    'breakdowns' => 'hourly_stats_aggregated_by_advertiser_time_zone',
                    'time_increment' => 1,
                    'time_range' => json_encode(['since' => $since, 'until' => $until]),
                    'limit' => 500,
                    'access_token' => $token,
                ]);
                $hourResult = curlGet($hourUrl);
            }

            if (isset($hourResult['error'])) {
                if (($hourResult['error']['code'] ?? 0) == 190) {
                    markFBSyncAccountExpired($account, $hourResult['error']['message'] ?? '');
                }
                logSync('FB', 'WARNING', 'hourly_api', $accountName . ': ' . ($hourResult['error']['message'] ?? 'Erro no hourly breakdown'));
                continue;
            }

            $hourAllData = $hourResult['data'] ?? [];
            while (isset($hourResult['paging']['next'])) {
                $hourResult = curlGet($hourResult['paging']['next']);
                if (isset($hourResult['data'])) {
                    $hourAllData = array_merge($hourAllData, $hourResult['data']);
                }
            }

            foreach ($hourAllData as $hourRow) {
                $metrics = extractFBInsightResultMetrics($hourRow);
                $spend = (float) ($hourRow['spend'] ?? 0);
                $impressions = (int) ($hourRow['impressions'] ?? 0);
                $clicks = (int) ($hourRow['inline_link_clicks'] ?? 0);
                $results = (int) ($metrics['results'] ?? 0);

                if ($spend <= 0 && $impressions <= 0 && $clicks <= 0 && $results <= 0) {
                    continue;
                }

                $hourLabel = $hourRow['hourly_stats_aggregated_by_advertiser_time_zone'] ?? '';
                $hourStart = parseFBHourlyStart($hourLabel);
                if ($hourLabel === '') {
                    $hourLabel = sprintf('%02d:00:00 - %02d:59:59', $hourStart, $hourStart);
                }

                $cpc = (float) ($hourRow['cost_per_inline_link_click'] ?? 0);
                if ($cpc <= 0 && $clicks > 0) {
                    $cpc = $spend / $clicks;
                }
                $ctr = (float) ($hourRow['inline_link_click_ctr'] ?? 0);
                if ($ctr <= 0 && $impressions > 0) {
                    $ctr = ($clicks / $impressions) * 100;
                }
                $cpm = $impressions > 0 ? ($spend / $impressions) * 1000 : 0;

                upsert('fb_hourly', [
                    'date' => $hourRow['date_start'],
                    'hour_start' => $hourStart,
                    'hour_label' => $hourLabel,
                    'campaign_id' => $hourRow['campaign_id'],
                    'campaign_name' => $hourRow['campaign_name'] ?? '',
                    'account_name' => $accountName,
                    'spend' => $spend,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'results' => $results,
                    'cpc' => $cpc,
                    'ctr' => $ctr,
                    'cpm' => $cpm,
                ], ['date', 'hour_start', 'campaign_id', 'account_name']);
                $hourlyCount++;
            }
        }
        logSync('FB', 'INFO', 'hourly_sync', "FB hourly sync: {$hourlyCount} registros importados");
    } catch (Exception $e) {
        logSync('FB', 'WARNING', 'hourly_error', 'Erro no hourly sync: ' . $e->getMessage());
    }

    $durationMs = round((microtime(true) - $syncStart) * 1000);
    $fbSyncFailed = ($totalImported === 0 && $mainErrorCount >= count($accounts) && !empty($errors));

    $response = [
        'success' => !$fbSyncFailed,
        'message' => "FB sincronizado! {$totalImported} registros, {$placementCount} placements, {$deviceCount} devices, {$hourlyCount} horas.",
        'imported' => $totalImported,
        'placements' => $placementCount,
        'devices' => $deviceCount,
        'hourly' => $hourlyCount,
    ];
    if ($fbSyncFailed) {
        $response['error'] = 'Facebook nao sincronizou. Reconecte o Facebook ou atualize o token manual.';
        $response['message'] = $response['error'];
    }
    if (!empty($errors)) {
        $response['warnings'] = $errors;
        logSync('FB', 'WARNING', 'sync_complete', "FB sync com avisos: {$totalImported} registros, {$placementCount} placements, {$deviceCount} devices, {$hourlyCount} horas, " . count($errors) . " erros", implode("\n", $errors), null, $durationMs);
    } else {
        logSync('FB', 'INFO', 'sync_complete', "FB sync OK: {$totalImported} registros, {$placementCount} placements, {$deviceCount} devices, {$hourlyCount} horas", null, null, $durationMs);
    }
    echo json_encode($response);
    exit;
}

/**
 * Get GAM auth — OAuth connections first, Service Account fallback.
 * Returns array with 'token', 'network_code', 'source', 'user_name' or null on failure.
 */
function getGAMSyncAuth()
{
    // Priority 1: OAuth connections from google_connections table
    try {
        $connections = fetchAll("SELECT * FROM google_connections WHERE status = 'active'");
        foreach ($connections as $conn) {
            $gamSelected = json_decode($conn['gam_selected'], true) ?: [];
            if (!empty($gamSelected) && !empty($conn['refresh_token'])) {
                $clientId = getSetting('google_oauth_client_id', '');
                $clientSecret = getSetting('google_oauth_client_secret', '');

                if (empty($clientId) || empty($clientSecret)) {
                    logSync('GAM', 'WARNING', 'oauth_config', 'OAuth Client ID/Secret não configurados para conexão OAuth');
                    continue;
                }

                $oauthStart = microtime(true);
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
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $oauthMs = round((microtime(true) - $oauthStart) * 1000);

                if (!empty($response['access_token'])) {
                    $networkCode = $gamSelected[0]['network_code'] ?? getSetting('gam_network_code', '');
                    logSync('GAM', 'INFO', 'oauth', "Token OAuth2 obtido via conexão de {$conn['google_user_name']} ({$oauthMs}ms)", null, $httpCode, $oauthMs);
                    return [
                        'token' => $response['access_token'],
                        'network_code' => $networkCode,
                        'source' => 'oauth',
                        'user_name' => $conn['google_user_name'] ?? 'OAuth',
                    ];
                } else {
                    $errMsg = $response['error_description'] ?? ($response['error'] ?? 'Erro desconhecido');
                    logSync('GAM', 'WARNING', 'oauth', "Falha ao obter token OAuth para {$conn['google_user_name']}: {$errMsg}", json_encode($response), $httpCode, $oauthMs);
                }
            }
        }
    } catch (Exception $e) {
        logSync('GAM', 'WARNING', 'oauth', 'Erro ao tentar conexões OAuth: ' . $e->getMessage());
    }

    // Priority 2: Service Account JSON fallback
    $networkCode = getSetting('gam_network_code', '');
    $saPath = getSetting('gam_service_account_path', '');
    if (empty($saPath))
        $saPath = getSetting('gam_service_account_json', '');

    if (empty($networkCode)) {
        logSync('GAM', 'WARNING', 'config', 'GAM Network Code não configurado (fallback Service Account)');
        return null;
    }

    if (empty($saPath)) {
        logSync('GAM', 'WARNING', 'config', 'Nenhum caminho de Service Account configurado e nenhuma conexão OAuth disponível');
        return null;
    }

    $fullPath = realpath(__DIR__ . '/../' . $saPath);
    if (!$fullPath || !file_exists($fullPath)) {
        logSync('GAM', 'ERROR', 'config', 'Service Account JSON não encontrado.', "Path: {$saPath}");
        return null;
    }

    $credentials = json_decode(file_get_contents($fullPath), true);
    if (!$credentials || !isset($credentials['private_key'])) {
        logSync('GAM', 'ERROR', 'config', 'Credenciais inválidas no JSON.', "Path: {$fullPath}");
        return null;
    }

    try {
        $oauthStart = microtime(true);
        $token = getGAMOAuthToken($credentials);
        $oauthMs = round((microtime(true) - $oauthStart) * 1000);
        logSync('GAM', 'INFO', 'oauth', "Token OAuth2 obtido via Service Account ({$oauthMs}ms)", null, null, $oauthMs);
        return [
            'token' => $token,
            'network_code' => $networkCode,
            'source' => 'service_account',
            'user_name' => $credentials['client_email'] ?? 'Service Account',
        ];
    } catch (Exception $e) {
        logSync('GAM', 'ERROR', 'oauth', 'Falha Service Account: ' . $e->getMessage());
        return null;
    }
}

function doSyncGAM()
{
    $syncStart = microtime(true);
    logSync('GAM', 'INFO', 'sync_start', 'Iniciando sincronização GAM...');

    // Try to get auth: OAuth connections first, then Service Account fallback
    $authResult = getGAMSyncAuth();

    if (!$authResult) {
        $durationMs = round((microtime(true) - $syncStart) * 1000);
        logSync('GAM', 'ERROR', 'config', 'GAM não configurado. Conecte via Google Social Login ou configure Service Account + Network Code.', null, null, $durationMs);
        echo json_encode(['success' => false, 'error' => 'GAM não configurado. Conecte via Google Social Login ou configure Service Account + Network Code.']);
        exit;
    }

    $networkCode = $authResult['network_code'];
    logSync('GAM', 'INFO', 'auth', "Autenticação GAM via {$authResult['source']} ({$authResult['user_name']}), Network: {$networkCode}");

    try {
        $token = $authResult['token'];

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

        // Load GAM sites for site-level filtering
        try {
            getDB()->exec("CREATE TABLE IF NOT EXISTS gam_sites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_name VARCHAR(255) NOT NULL,
                ad_unit_pattern VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            $gamSites = fetchAll("SELECT * FROM gam_sites ORDER BY site_name");
        } catch (Exception $e) {
            $gamSites = [];
        }
        $hasSites = !empty($gamSites);

        if (function_exists('ensureRevenueTableSchema')) {
            ensureRevenueTableSchema();
        }

        // ====================================================
        // PASS 1: Daily totals (with AD_UNIT_NAME if sites configured)
        // ====================================================
        $adUnitDimension = $hasSites ? '<ns:dimensions>AD_UNIT_NAME</ns:dimensions>' : '';
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
          {$adUnitDimension}
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

        $report1Start = microtime(true);
        $csv1 = runGAMReport($soapUrl, $reportXml1, $token, $networkCode, $apiVersion, 'pass1_daily_totals');
        $report1Ms = round((microtime(true) - $report1Start) * 1000);

        if (!$csv1) {
            logSync('GAM', 'ERROR', 'pass1_daily_totals', 'Falha ao obter relatório de receita diária (Pass 1 retornou null)', null, null, $report1Ms);
            throw new Exception('Falha ao obter relatório de receita diária.');
        }
        logSync('GAM', 'INFO', 'pass1_daily_totals', "Relatório diário obtido ({$report1Ms}ms, " . strlen($csv1) . " bytes)", null, null, $report1Ms);

        // Parse daily totals (with optional site breakdown)
        $dailyTotals = [];       // [date] => revenue_usd (when no sites)
        $dailySiteTotals = [];   // [date][site_name] => revenue_usd (when sites configured)
        $unmatchedAdUnitSamples = [];
        $lines1 = explode("\n", trim($csv1));
        $headers1 = str_getcsv($lines1[0] ?? '', ',', '"', '');

        for ($i = 1; $i < count($lines1); $i++) {
            $line = trim($lines1[$i]);
            if (empty($line) || strpos($line, 'Total') !== false)
                continue;
            $cols = str_getcsv($line, ',', '"', '');
            if (count($cols) < 2)
                continue;

            $date = $cols[0];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
                continue;

            if ($hasSites && count($cols) >= 3) {
                // Format: DATE, AD_UNIT_NAME, REVENUE, IMPRESSIONS, AD_REQUESTS
                $adUnitName = $cols[1];
                $revenueMicros = (float) $cols[2] / 1000000;
                $impressions = isset($cols[3]) ? (int) $cols[3] : 0;
                $adRequests = isset($cols[4]) ? (int) $cols[4] : 0;

                // Map ad unit to site name. Patterns can contain multiple aliases.
                $siteName = matchGAMAdUnitSite($adUnitName, $gamSites);
                if (empty($siteName)) {
                    $siteName = 'Outros';
                    if (count($unmatchedAdUnitSamples) < 8) {
                        $unmatchedAdUnitSamples[] = $adUnitName;
                    }
                }

                if (!isset($dailySiteTotals[$date]))
                    $dailySiteTotals[$date] = [];
                if (!isset($dailySiteTotals[$date][$siteName]))
                    $dailySiteTotals[$date][$siteName] = 0;
                $dailySiteTotals[$date][$siteName] += $revenueMicros;

                // Also accumulate flat daily total
                if (!isset($dailyTotals[$date]))
                    $dailyTotals[$date] = ['revenue' => 0, 'impressions' => 0, 'ad_requests' => 0];
                $dailyTotals[$date]['revenue'] += $revenueMicros;
                $dailyTotals[$date]['impressions'] += $impressions;
                $dailyTotals[$date]['ad_requests'] += $adRequests;
            } else {
                // No sites - simple DATE + REVENUE + IMPRESSIONS + AD_REQUESTS
                $revenueMicros = (float) $cols[1] / 1000000;
                $impressions = isset($cols[2]) ? (int) $cols[2] : 0;
                $adRequests = isset($cols[3]) ? (int) $cols[3] : 0;
                $dailyTotals[$date] = [
                    'revenue' => $revenueMicros,
                    'impressions' => $impressions,
                    'ad_requests' => $adRequests,
                ];
            }
        }

        if ($hasSites && !empty($unmatchedAdUnitSamples)) {
            logSync('GAM', 'WARNING', 'ad_unit_unmatched', 'Ad Units sem match em gam_sites: ' . implode(' || ', array_unique($unmatchedAdUnitSamples)));
        }

        // ====================================================
        // PASS 2: Lookup utm_campaign key ID + Custom Dimension report
        // ====================================================

        // Step 2a: Find the custom targeting key ID for 'utm_campaign'
        $ctUrl = "https://ads.google.com/apis/ads/publisher/{$apiVersion}/CustomTargetingService";
        $ctXml = <<<XML
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
    <ns:getCustomTargetingKeysByStatement>
      <ns:filterStatement>
        <ns:query>WHERE name = 'utm_campaign' LIMIT 1</ns:query>
      </ns:filterStatement>
    </ns:getCustomTargetingKeysByStatement>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        $utmKeyId = null;
        $ch = curl_init($ctUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $ctXml,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=UTF-8',
                "Authorization: Bearer {$token}",
                'SOAPAction: ""',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $ctResponse = curl_exec($ch);
        curl_close($ch);

        if ($ctResponse && preg_match('/<id>(\d+)<\/id>/', $ctResponse, $km)) {
            $utmKeyId = $km[1];
            logSync('GAM', 'INFO', 'utm_key_lookup', "utm_campaign key ID encontrado: {$utmKeyId}");
        } else {
            logSync('GAM', 'WARNING', 'utm_key_lookup', 'utm_campaign key não encontrado no GAM. Revenue por campanha indisponível.');
        }

        // Step 2b: Run campaign + placement custom criteria reports.
        // Placements depend on utm_content, so CUSTOM_CRITERIA must run even
        // when the narrower utm_campaign customDimension report succeeds.
        $campaignRevenue = []; // [date][campaign_id] => ['revenue' => ..., 'impressions' => ..., 'ad_requests' => ...]
        $placementImported = 0;

        if ($utmKeyId) {
            // Try customDimensionKeyIds first (ideal approach)
            $pass2Success = false;
            try {
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
          <ns:columns>AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE</ns:columns>
          <ns:columns>AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS</ns:columns>
          <ns:columns>AD_EXCHANGE_AD_REQUESTS</ns:columns>
          <ns:customDimensionKeyIds>{$utmKeyId}</ns:customDimensionKeyIds>
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
                $report2Start = microtime(true);
                $csv2 = runGAMReport($soapUrl, $reportXml2, $token, $networkCode, $apiVersion, 'pass2_customdim');
                $report2Ms = round((microtime(true) - $report2Start) * 1000);

                if ($csv2) {
                    $pass2Success = true;
                    logSync('GAM', 'INFO', 'pass2_customdim', "Relatório customDimension obtido ({$report2Ms}ms)", null, null, $report2Ms);
                }
            } catch (Exception $e) {
                logSync('GAM', 'WARNING', 'pass2_customdim_fail', 'customDimensionKeyIds falhou: ' . $e->getMessage() . ' — tentando CUSTOM_CRITERIA...');
            }

            // Always run CUSTOM_CRITERIA so utm_content placement rows are available.
            if ($utmKeyId) {
                try {
                    $reportXml2b = <<<XML
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
                    $report2Start = microtime(true);
                    $csv2 = runGAMReport($soapUrl, $reportXml2b, $token, $networkCode, $apiVersion, 'pass2_criteria');
                    $report2Ms = round((microtime(true) - $report2Start) * 1000);

                    if ($csv2) {
                        $pass2Success = true;
                        logSync('GAM', 'INFO', 'pass2_criteria', "Relatório CUSTOM_CRITERIA obtido ({$report2Ms}ms)", null, null, $report2Ms);
                        // Log first 5 data lines to debug
                        $debugLines = explode("\n", trim($csv2));
                        $debugSample = array_slice($debugLines, 0, 6);
                        logSync('GAM', 'INFO', 'pass2_csv_sample', 'CSV sample (first 5 rows): ' . implode(' | ', $debugSample));
                    }
                } catch (Exception $e) {
                    logSync('GAM', 'WARNING', 'pass2_criteria_fail', 'CUSTOM_CRITERIA também falhou: ' . $e->getMessage());
                }
            }

            // Parse CSV (works for both approaches)
            // Also captures utm_content placement data in the same loop
            ensurePlacementsTable();

            if ($pass2Success && $csv2) {
                $lines2 = explode("\n", trim($csv2));
                $headerLine = $lines2[0] ?? '';
                logSync('GAM', 'INFO', 'pass2_headers', 'CSV Headers: ' . $headerLine);

                // Dynamic column detection from headers
                $headers = str_getcsv($headerLine, ',', '"', '');
                $colMap = [];
                foreach ($headers as $idx => $h) {
                    $h = trim($h);
                    if (strpos($h, 'DATE') !== false) $colMap['date'] = $idx;
                    elseif (strpos($h, 'CUSTOM_CRITERIA') !== false && strpos($h, 'VALUE_ID') === false) $colMap['criteria'] = $idx;
                    elseif (strpos($h, 'REVENUE') !== false) $colMap['revenue'] = $idx;
                    elseif (strpos($h, 'IMPRESSIONS') !== false) $colMap['impressions'] = $idx;
                    elseif (strpos($h, 'AD_REQUESTS') !== false) $colMap['ad_requests'] = $idx;
                }

                logSync('GAM', 'INFO', 'pass2_colmap', 'Column map: ' . json_encode($colMap));

                $dateCol = $colMap['date'] ?? 0;
                $criteriaCol = $colMap['criteria'] ?? 1;
                $revCol = $colMap['revenue'] ?? 3;
                $impCol = $colMap['impressions'] ?? 4;
                $reqCol = $colMap['ad_requests'] ?? 5;

                // Clear old placement data for re-import
                query("DELETE FROM revenue_placements WHERE date >= ?", [$since]);
                $utmContentSamples = [];

                for ($i = 1; $i < count($lines2); $i++) {
                    $line = trim($lines2[$i]);
                    if (empty($line) || strpos($line, 'Total') !== false)
                        continue;
                    $cols = str_getcsv($line, ',', '"', '');
                    if (count($cols) < $revCol + 1)
                        continue;

                    $date = $cols[$dateCol];
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
                        continue;

                    $utmValue = trim($cols[$criteriaCol]);
                    $revenue = (float) $cols[$revCol] / 1000000;
                    $impressions = isset($cols[$impCol]) ? (int) $cols[$impCol] : 0;
                    $adRequests = isset($cols[$reqCol]) ? (int) $cols[$reqCol] : 0;

                    // ---- utm_content → placement data ----
                    $contentValue = null;
                    if (preg_match('/^utm_content\s*=\s*(.+)$/', $utmValue, $cm)) {
                        $contentValue = trim($cm[1]);
                    }
                    if ($contentValue && preg_match('/^(\d{5,})_(.+)$/', $contentValue, $pm)) {
                        $plCampaignId = $pm[1];
                        $plPlacement = $pm[2];
                        if ($revenue > 0 || $impressions > 0) {
                            upsert('revenue_placements', [
                                'date' => $date,
                                'campaign_id' => $plCampaignId,
                                'placement' => $plPlacement,
                                'receita_usd' => round($revenue, 6),
                                'gam_impressions' => $impressions,
                            ], ['date', 'campaign_id', 'placement']);
                            $placementImported++;
                            if (count($utmContentSamples) < 5) {
                                $utmContentSamples[] = "{$date}: {$plCampaignId} | {$plPlacement} | USD" . round($revenue, 4) . " | {$impressions}imp";
                            }
                        }
                        continue; // utm_content lines are NOT campaign revenue
                    }

                    // ---- utm_campaign → campaign revenue data ----
                    $campaignId = null;
                    if (preg_match('/^\d{5,}$/', $utmValue)) {
                        $campaignId = $utmValue;
                    } elseif (preg_match('/utm_campaign\s*=\s*(\d{5,})/', $utmValue, $m)) {
                        $campaignId = $m[1];
                    }

                    if (!$campaignId) continue;

                    if (!isset($campaignRevenue[$date]))
                        $campaignRevenue[$date] = [];
                    if (!isset($campaignRevenue[$date][$campaignId])) {
                        $campaignRevenue[$date][$campaignId] = [
                            'revenue' => 0,
                            'impressions' => 0,
                            'ad_requests' => 0,
                        ];
                    }
                    $campaignRevenue[$date][$campaignId]['revenue'] += $revenue;
                    $campaignRevenue[$date][$campaignId]['impressions'] += $impressions;
                    $campaignRevenue[$date][$campaignId]['ad_requests'] += $adRequests;
                }

                // Log parsed results
                $totalParsed = 0;
                foreach ($campaignRevenue as $d => $camps) $totalParsed += count($camps);
                logSync('GAM', 'INFO', 'pass2_parsed', "Parsed {$totalParsed} campaign-day entries + {$placementImported} placements from CSV");
                if (!empty($utmContentSamples)) {
                    logSync('GAM', 'INFO', 'placement_samples', 'Placement samples: ' . implode(' || ', $utmContentSamples));
                }
            }
        }

        // ====================================================
        // IMPORT: Use Pass 1 totals as source of truth
        // Scale campaign revenues to match exact daily total
        // Now supports site_name dimension
        // ====================================================
        query("DELETE FROM revenue");
        $imported = 0;

        if ($hasSites && !empty($dailySiteTotals)) {
            // Import with site breakdown
            foreach ($dailySiteTotals as $date => $sites) {
                foreach ($sites as $siteName => $siteRevenue) {
                    if ($siteRevenue <= 0)
                        continue;

                    $campaigns = $campaignRevenue[$date] ?? [];
                    $totalDayData = $dailyTotals[$date] ?? ['revenue' => 0, 'impressions' => 0, 'ad_requests' => 0];
                    $totalDayRevenue = is_array($totalDayData) ? $totalDayData['revenue'] : $totalDayData;

                    if (empty($campaigns) || $totalDayRevenue <= 0) {
                        upsert('revenue', [
                            'date' => $date,
                            'campaign_id' => 'TOTAL',
                            'utm_campaign' => 'TOTAL',
                            'receita_usd' => $siteRevenue,
                            'gam_impressions' => 0,
                            'gam_ad_requests' => 0,
                            'site_name' => $siteName,
                        ], ['date', 'campaign_id', 'site_name']);
                        $imported++;
                    } else {
                        // Scale campaign revenues, distribute this site's share
                        $siteShare = $siteRevenue / $totalDayRevenue;
                        $campaignRevenueSum = array_sum(array_column($campaigns, 'revenue'));

                        foreach ($campaigns as $cid => $cdata) {
                            $rev = $cdata['revenue'];
                            $scaledRev = ($campaignRevenueSum > 0)
                                ? $rev * ($totalDayRevenue / $campaignRevenueSum) * $siteShare
                                : $rev * $siteShare;

                            upsert('revenue', [
                                'date' => $date,
                                'campaign_id' => $cid,
                                'utm_campaign' => $cid,
                                'receita_usd' => round($scaledRev, 6),
                                'gam_impressions' => $cdata['impressions'] ?? 0,
                                'gam_ad_requests' => $cdata['ad_requests'] ?? 0,
                                'site_name' => $siteName,
                            ], ['date', 'campaign_id', 'site_name']);
                            $imported++;
                        }
                    }
                }
            }
        } else {
            // Original behavior: no site breakdown
            foreach ($dailyTotals as $date => $dayData) {
                $totalDayRevenue = is_array($dayData) ? $dayData['revenue'] : $dayData;
                $totalDayImp = is_array($dayData) ? ($dayData['impressions'] ?? 0) : 0;
                $totalDayReq = is_array($dayData) ? ($dayData['ad_requests'] ?? 0) : 0;
                if ($totalDayRevenue <= 0)
                    continue;

                $campaigns = $campaignRevenue[$date] ?? [];

                if (empty($campaigns)) {
                    upsert('revenue', [
                        'date' => $date,
                        'campaign_id' => 'TOTAL',
                        'utm_campaign' => 'TOTAL',
                        'receita_usd' => $totalDayRevenue,
                        'gam_impressions' => $totalDayImp,
                        'gam_ad_requests' => $totalDayReq,
                        'site_name' => '',
                    ], ['date', 'campaign_id', 'site_name']);
                    $imported++;
                } else {
                    $campaignRevenueSum = array_sum(array_column($campaigns, 'revenue'));
                    $campaignImpSum = array_sum(array_column($campaigns, 'impressions'));
                    $campaignReqSum = array_sum(array_column($campaigns, 'ad_requests'));

                    foreach ($campaigns as $cid => $cdata) {
                        $rev = $cdata['revenue'];
                        $imp = $cdata['impressions'] ?? 0;
                        $req = $cdata['ad_requests'] ?? 0;

                        $scaledRev = ($campaignRevenueSum > 0)
                            ? $rev * ($totalDayRevenue / $campaignRevenueSum)
                            : $rev;
                        $scaledImp = ($totalDayImp > 0 && $campaignImpSum > 0)
                            ? (int)round($imp * ($totalDayImp / $campaignImpSum))
                            : $imp;
                        $scaledReq = ($totalDayReq > 0 && $campaignReqSum > 0)
                            ? (int)round($req * ($totalDayReq / $campaignReqSum))
                            : $req;

                        upsert('revenue', [
                            'date' => $date,
                            'campaign_id' => $cid,
                            'utm_campaign' => $cid,
                            'receita_usd' => round($scaledRev, 6),
                            'gam_impressions' => $scaledImp,
                            'gam_ad_requests' => $scaledReq,
                            'site_name' => '',
                        ], ['date', 'campaign_id', 'site_name']);
                        $imported++;
                    }
                }
            }
        }

        if (!shouldSkipCrossRef()) {
            crossRef();
        }

        // ====================================================
        // PASS 3: Device Category × utm_campaign revenue
        // ====================================================
        $deviceImported = 0;
        if ($utmKeyId) {
            try {
                if (function_exists('ensureRevenueDevicesTable')) {
                    ensureRevenueDevicesTable();
                }

                $reportXml3 = <<<XML
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
          <ns:dimensions>DEVICE_CATEGORY_NAME</ns:dimensions>
          <ns:columns>AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE</ns:columns>
          <ns:columns>AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS</ns:columns>
          <ns:customDimensionKeyIds>{$utmKeyId}</ns:customDimensionKeyIds>
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
                $report3Start = microtime(true);
                $csv3 = runGAMReport($soapUrl, $reportXml3, $token, $networkCode, $apiVersion, 'pass3_device');
                $report3Ms = round((microtime(true) - $report3Start) * 1000);

                if ($csv3) {
                    logSync('GAM', 'INFO', 'pass3_device', "Relatório device obtido ({$report3Ms}ms)", null, null, $report3Ms);

                    $lines3 = explode("\n", trim($csv3));
                    $headers3 = str_getcsv($lines3[0] ?? '', ',', '"', '');

                    // Dynamic column detection
                    $col3 = [];
                    foreach ($headers3 as $idx => $h) {
                        $h = trim($h);
                        if (strpos($h, 'DATE') !== false && !isset($col3['date'])) $col3['date'] = $idx;
                        elseif (strpos($h, 'DEVICE') !== false) $col3['device'] = $idx;
                        elseif (strpos($h, 'CUSTOM') !== false && strpos($h, 'VALUE_ID') === false) $col3['utm'] = $idx;
                        elseif (strpos($h, 'REVENUE') !== false) $col3['rev'] = $idx;
                        elseif (strpos($h, 'IMPRESSIONS') !== false) $col3['imp'] = $idx;
                    }
                    logSync('GAM', 'INFO', 'pass3_colmap', 'Device col map: ' . json_encode($col3));

                    // Clear old data for re-import
                    query("DELETE FROM revenue_devices WHERE date >= ?", [$since]);

                    for ($i = 1; $i < count($lines3); $i++) {
                        $line = trim($lines3[$i]);
                        if (empty($line) || strpos($line, 'Total') !== false) continue;
                        $cols = str_getcsv($line, ',', '"', '');

                        $date = $cols[$col3['date'] ?? 0] ?? '';
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

                        $deviceCat = trim($cols[$col3['device'] ?? 1] ?? 'Unknown');
                        $utmVal = trim($cols[$col3['utm'] ?? 2] ?? '');
                        $rev = (float)($cols[$col3['rev'] ?? 3] ?? 0) / 1000000;
                        $imp = (int)($cols[$col3['imp'] ?? 4] ?? 0);

                        // Extract campaign_id from utm value
                        $campaignId = null;
                        if (preg_match('/^\d{5,}$/', $utmVal)) {
                            $campaignId = $utmVal;
                        } elseif (preg_match('/utm_campaign\s*=\s*(\d{5,})/', $utmVal, $m3)) {
                            $campaignId = $m3[1];
                        }
                        if (!$campaignId || $rev <= 0) continue;

                        upsert('revenue_devices', [
                            'date' => $date,
                            'campaign_id' => $campaignId,
                            'device_category' => $deviceCat,
                            'receita_usd' => round($rev, 6),
                            'gam_impressions' => $imp,
                        ], ['date', 'campaign_id', 'device_category']);
                        $deviceImported++;
                    }
                    logSync('GAM', 'INFO', 'pass3_complete', "Device revenue: {$deviceImported} registros importados");
                } else {
                    logSync('GAM', 'WARNING', 'pass3_device', 'Relatório device retornou null');
                }
            } catch (Exception $e) {
                logSync('GAM', 'WARNING', 'pass3_error', 'Erro no device sync: ' . $e->getMessage());
            }
        }

        $durationMs = round((microtime(true) - $syncStart) * 1000);
        logSync('GAM', 'INFO', 'sync_complete', "GAM sync OK: {$imported} registros revenue, {$placementImported} placements, {$deviceImported} devices, " . count($dailyTotals) . " dias ({$durationMs}ms)", null, null, $durationMs);

        echo json_encode([
            'success' => true,
            'message' => "GAM sincronizado! {$imported} registros revenue, {$placementImported} placements, {$deviceImported} devices.",
            'imported' => $imported,
            'placements_imported' => $placementImported,
            'devices_imported' => $deviceImported,
        ]);

    } catch (Exception $e) {
        $durationMs = round((microtime(true) - $syncStart) * 1000);
        logSync('GAM', 'ERROR', 'exception', 'Erro GAM: ' . $e->getMessage(), $e->getTraceAsString(), null, $durationMs);
        echo json_encode(['success' => false, 'error' => 'Erro GAM: ' . $e->getMessage()]);
    }
    exit;
}

function runGAMReport($soapUrl, $runXml, $token, $networkCode, $apiVersion, $reportName = 'report')
{
    // Run report
    $runResult = soapReq($soapUrl, $runXml, $token, $networkCode);

    // Check for SOAP fault
    if (strpos($runResult, 'faultstring') !== false) {
        preg_match('/<faultstring>(.*?)<\/faultstring>/s', $runResult, $faultMatch);
        $faultMsg = $faultMatch[1] ?? 'Unknown SOAP fault';
        logSync('GAM', 'ERROR', $reportName . '_run', "SOAP Fault ao criar relatório: {$faultMsg}", mb_substr($runResult, 0, 2000));
        return null;
    }

    preg_match('/<id>(\d+)<\/id>/', $runResult, $matches);
    if (empty($matches[1])) {
        logSync('GAM', 'ERROR', $reportName . '_run', 'Não foi possível extrair o Report Job ID da resposta SOAP', mb_substr($runResult, 0, 2000));
        return null;
    }
    $reportJobId = $matches[1];

    // Poll for completion
    $pollStart = microtime(true);
    $finalStatus = 'TIMEOUT';
    for ($i = 0; $i < 30; $i++) {
        sleep(2);
        $statusXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="https://www.google.com/apis/ads/publisher/{$apiVersion}">
  <soapenv:Header><ns:RequestHeader><ns:networkCode>{$networkCode}</ns:networkCode><ns:applicationName>BussolaDoTrafego</ns:applicationName></ns:RequestHeader></soapenv:Header>
  <soapenv:Body><ns:getReportJobStatus><ns:reportJobId>{$reportJobId}</ns:reportJobId></ns:getReportJobStatus></soapenv:Body>
</soapenv:Envelope>
XML;
        $statusResult = soapReq($soapUrl, $statusXml, $token, $networkCode);
        if (strpos($statusResult, 'COMPLETED') !== false) {
            $finalStatus = 'COMPLETED';
            break;
        }
        if (strpos($statusResult, 'FAILED') !== false) {
            $finalStatus = 'FAILED';
            break;
        }
    }

    $pollMs = round((microtime(true) - $pollStart) * 1000);

    if ($finalStatus !== 'COMPLETED') {
        logSync('GAM', 'ERROR', $reportName . '_poll', "Relatório {$reportName} terminou com status: {$finalStatus} após {$pollMs}ms (job #{$reportJobId})", mb_substr($statusResult ?? '', 0, 2000), null, $pollMs);
        return null;
    }

    // Download CSV
    $downloadXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:ns="https://www.google.com/apis/ads/publisher/{$apiVersion}">
  <soapenv:Header><ns:RequestHeader><ns:networkCode>{$networkCode}</ns:networkCode><ns:applicationName>BussolaDoTrafego</ns:applicationName></ns:RequestHeader></soapenv:Header>
  <soapenv:Body><ns:getReportDownloadURL><ns:reportJobId>{$reportJobId}</ns:reportJobId><ns:exportFormat>CSV_DUMP</ns:exportFormat></ns:getReportDownloadURL></soapenv:Body>
</soapenv:Envelope>
XML;
    $downloadResult = soapReq($soapUrl, $downloadXml, $token, $networkCode);
    preg_match('/<rval>(.*?)<\/rval>/', $downloadResult, $urlMatches);
    $downloadUrl = html_entity_decode($urlMatches[1] ?? '');
    if (empty($downloadUrl)) {
        logSync('GAM', 'ERROR', $reportName . '_download_url', "Não foi possível obter URL de download do relatório (job #{$reportJobId})", mb_substr($downloadResult, 0, 2000));
        return null;
    }

    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 60,
    ]);
    $csvContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        logSync('GAM', 'ERROR', $reportName . '_download', "cURL erro ao baixar CSV: {$curlError}", null, $httpCode);
        return null;
    }

    if ($httpCode >= 400) {
        logSync('GAM', 'ERROR', $reportName . '_download', "HTTP {$httpCode} ao baixar CSV do relatório", mb_substr($csvContent, 0, 1000), $httpCode);
        return null;
    }

    if (substr($csvContent, 0, 2) === "\x1f\x8b") {
        $csvContent = gzdecode($csvContent);
    }

    if (empty($csvContent)) {
        logSync('GAM', 'WARNING', $reportName . '_download', "CSV vazio retornado para relatório (job #{$reportJobId})", null, $httpCode);
    }

    return $csvContent;
}

function matchGAMAdUnitSite($adUnitName, array $gamSites)
{
    foreach ($gamSites as $site) {
        $pattern = trim($site['ad_unit_pattern'] ?? '');
        if ($pattern === '') {
            continue;
        }

        $aliases = preg_split('/[\r\n,;|]+/', $pattern);
        foreach ($aliases as $alias) {
            $alias = trim($alias);
            if ($alias === '') {
                continue;
            }

            if (stripos($alias, 'regex:') === 0) {
                $regex = substr($alias, 6);
                if ($regex !== '' && @preg_match('/' . str_replace('/', '\/', $regex) . '/i', $adUnitName)) {
                    return $site['site_name'];
                }
                continue;
            }

            if (strpos($alias, '*') !== false) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($alias, '/')) . '$/i';
                if (preg_match($regex, $adUnitName)) {
                    return $site['site_name'];
                }
                continue;
            }

            if (stripos($adUnitName, $alias) !== false) {
                return $site['site_name'];
            }
        }
    }

    return '';
}

// === Helper functions ===

function curlGet($url)
{
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
    if ($error)
        return ['error' => ['message' => "cURL: {$error}"]];
    return json_decode($response, true) ?: ['error' => ['message' => 'Resposta inválida']];
}

function getGAMOAuthToken($credentials)
{
    $now = time();
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $claim = rtrim(strtr(base64_encode(json_encode([
        'iss' => $credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/admanager',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ])), '+/', '-_'), '=');

    $signatureInput = "{$header}.{$claim}";
    openssl_sign($signatureInput, $signature, openssl_pkey_get_private($credentials['private_key']), OPENSSL_ALGO_SHA256);
    $jwt = "{$signatureInput}." . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($resp['access_token'])) {
        $errDetail = $resp['error_description'] ?? ($resp['error'] ?? 'Erro desconhecido');
        logSync('GAM', 'ERROR', 'oauth', "Falha OAuth2: {$errDetail}", json_encode($resp));
        throw new Exception('Falha OAuth2: ' . $errDetail);
    }
    return $resp['access_token'];
}

function soapReq($url, $xml, $token, $networkCode)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=UTF-8', 'Authorization: Bearer ' . $token, 'SOAPAction: ""'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError) {
        logSync('GAM', 'ERROR', 'soap_request', "SOAP cURL erro: {$curlError}", null, $httpCode);
        throw new Exception("SOAP cURL: {$curlError}");
    }
    if ($httpCode >= 500) {
        logSync('GAM', 'ERROR', 'soap_request', "SOAP HTTP {$httpCode}", mb_substr($response, 0, 2000), $httpCode);
        throw new Exception("SOAP HTTP error: {$httpCode}");
    }
    return $response;
}

function crossRef()
{
    $cotacao = getCotacaoDolar();
    $revenues = fetchAll("SELECT date, campaign_id, SUM(receita_usd) as total_receita FROM revenue GROUP BY date, campaign_id");

    foreach ($revenues as $r) {
        if ($r['campaign_id'] === 'TOTAL') {
            // Distribute TOTAL revenue proportionally across all campaigns for this date
            $totalReceitaUsd = (float) $r['total_receita'];
            $dayCampaigns = fetchAll("SELECT id, investimento FROM fb_campaigns WHERE date = ?", [$r['date']]);
            $totalInvest = 0;
            foreach ($dayCampaigns as $dc)
                $totalInvest += (float) $dc['investimento'];

            if ($totalInvest > 0 && !empty($dayCampaigns)) {
                foreach ($dayCampaigns as $dc) {
                    $share = (float) $dc['investimento'] / $totalInvest;
                    $campReceitaUsd = $totalReceitaUsd * $share;
                    $campReceitaBrl = $campReceitaUsd * $cotacao;
                    $invest = (float) $dc['investimento'];
                    $roas = $invest > 0 ? (($campReceitaBrl - $invest) / $invest) * 100 : 0;
                    $profit = $campReceitaBrl - $invest;

                    update('fb_campaigns', [
                        'receita_usd' => $campReceitaUsd,
                        'receita_brl' => $campReceitaBrl,
                        'roas' => $roas,
                        'profit' => $profit,
                    ], 'id = ?', [$dc['id']]);
                }
            }
            continue;
        }

        $receitaBrl = $r['total_receita'] * $cotacao;
        $campaign = fetchOne("SELECT id, investimento FROM fb_campaigns WHERE date = ? AND campaign_id = ?", [$r['date'], $r['campaign_id']]);

        if ($campaign) {
            $invest = (float) $campaign['investimento'];
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

function doCrossRefOnly()
{
    $syncStart = microtime(true);

    try {
        crossRef();
        $durationMs = round((microtime(true) - $syncStart) * 1000);
        logSync('SYNC', 'INFO', 'cross_ref', "Cross-reference final OK ({$durationMs}ms)", null, null, $durationMs);
        echo json_encode([
            'success' => true,
            'message' => 'Cross-reference finalizado.',
            'duration_ms' => $durationMs,
        ]);
    } catch (Exception $e) {
        $durationMs = round((microtime(true) - $syncStart) * 1000);
        logSync('SYNC', 'ERROR', 'cross_ref', 'Erro no cross-reference final: ' . $e->getMessage(), $e->getTraceAsString(), null, $durationMs);
        echo json_encode([
            'success' => false,
            'error' => 'Erro no cross-reference final: ' . $e->getMessage(),
        ]);
    }

    exit;
}

function doSyncGoogleAds()
{
    $syncStart = microtime(true);
    $customerId = str_replace('-', '', getSetting('gads_customer_id', ''));
    $devToken = getSetting('gads_developer_token', '');
    $clientId = getSetting('gads_client_id', '');
    $clientSecret = getSetting('gads_client_secret', '');
    $refreshToken = getSetting('gads_refresh_token', '');

    if (empty($customerId) || empty($devToken) || empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
        echo json_encode(['success' => false, 'error' => 'Google Ads não configurado. Configure em Configurações.']);
        exit;
    }

    try {
        // Get access token via refresh token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
            ]),
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $tokenResp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (empty($tokenResp['access_token'])) {
            $errMsg = $tokenResp['error_description'] ?? $tokenResp['error'] ?? 'Falha OAuth2';
            throw new Exception("OAuth2: {$errMsg}");
        }
        $accessToken = $tokenResp['access_token'];

        // Query campaigns for last 30 days
        $since = date('Y-m-d', strtotime('-30 days'));
        $until = date('Y-m-d');
        $query = "SELECT campaign.id, campaign.name, campaign.status, segments.date, "
            . "metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.average_cpc, "
            . "metrics.ctr, metrics.conversions, metrics.cost_per_conversion "
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
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode >= 400) {
            $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new Exception("Google Ads API: {$errMsg}");
        }

        $imported = 0;
        $results = [];
        if (is_array($data)) {
            foreach ($data as $batch) {
                if (isset($batch['results'])) {
                    $results = array_merge($results, $batch['results']);
                }
            }
        }

        $statusMap = ['ENABLED' => 'Ativo', 'PAUSED' => 'Pausado', 'REMOVED' => 'Removido'];

        foreach ($results as $row) {
            $campaign = $row['campaign'] ?? [];
            $segments = $row['segments'] ?? [];
            $metrics = $row['metrics'] ?? [];
            $date = $segments['date'] ?? null;
            $campaignId = $campaign['id'] ?? null;
            if (!$date || !$campaignId)
                continue;

            $costMicros = (float) ($metrics['costMicros'] ?? 0);
            $cost = $costMicros / 1000000;
            $impressions = (int) ($metrics['impressions'] ?? 0);
            $clicks = (int) ($metrics['clicks'] ?? 0);
            $cpm = $impressions > 0 ? ($cost / $impressions) * 1000 : 0;
            $conversions = (float) ($metrics['conversions'] ?? 0);
            $conversionRate = $clicks > 0 ? $conversions / $clicks : 0;

            upsert('google_ads', [
                'date' => $date,
                'campaign_id' => $campaignId,
                'campaign_name' => $campaign['name'] ?? '',
                'cost' => round($cost, 2),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'avg_cpc' => round((float) ($metrics['averageCpc'] ?? 0) / 1000000, 6),
                'ctr' => round((float) ($metrics['ctr'] ?? 0), 6),
                'conversion_rate' => round($conversionRate, 6),
                'cpm' => round($cpm, 6),
                'conversions' => round($conversions, 2),
                'status' => $statusMap[$campaign['status'] ?? ''] ?? ($campaign['status'] ?? 'Ativo'),
                'last_updated' => date('Y-m-d H:i:s'),
            ], ['date', 'campaign_id']);
            $imported++;
        }

        $durationMs = round((microtime(true) - $syncStart) * 1000);
        logSync('GADS', 'INFO', 'sync_complete', "Google Ads sync OK: {$imported} registros ({$durationMs}ms)", null, null, $durationMs);
        echo json_encode(['success' => true, 'message' => "Google Ads sincronizado! {$imported} registros.", 'imported' => $imported]);

    } catch (Exception $e) {
        $durationMs = round((microtime(true) - $syncStart) * 1000);
        logSync('GADS', 'ERROR', 'exception', 'Erro Google Ads: ' . $e->getMessage(), $e->getTraceAsString(), null, $durationMs);
        echo json_encode(['success' => false, 'error' => 'Erro Google Ads: ' . $e->getMessage()]);
    }
    exit;
}

/**
 * Sync GA4 sessions (Google Analytics 4)
 * Fetches sessions by utm_source + utm_campaign from GA4 Data API
 */
function doSyncGA4()
{
    $syncStart = microtime(true);

    $propertyId = getSetting('ga4_property_id', '');
    $utmSource = getSetting('ga4_utm_source', 'facebook');

    if (empty($propertyId)) {
        echo json_encode(['success' => false, 'error' => 'GA4 Property ID não configurado.']);
        exit;
    }

    // Get access token from google_connections
    $token = null;
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
                    $token = $response['access_token'];
                    break;
                }
            }
        }
    } catch (Exception $e) {}

    if (!$token) {
        echo json_encode(['success' => false, 'error' => 'Nenhuma conexão Google ativa para GA4.']);
        exit;
    }

    ensureGA4Table();

    $since = date('Y-m-d', strtotime('-30 days'));
    $until = date('Y-m-d');

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
    curl_close($ch);

    $data = json_decode($responseText, true);

    if ($httpCode >= 400 || isset($data['error'])) {
        $errMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
        logSync('GA4', 'ERROR', 'api_request', "GA4 API error: {$errMsg}", $responseText, $httpCode);
        echo json_encode(['success' => false, 'error' => "Erro GA4: {$errMsg}"]);
        exit;
    }

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

    $durationMs = round((microtime(true) - $syncStart) * 1000);
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
    ];
    logSync('GA4', 'INFO', 'sync_complete', "GA4 sync OK: " . count($sessionRows) . " registros, {$stats['sessions']} sessoes ({$durationMs}ms)", $details, null, $durationMs);

    echo json_encode([
        'success' => true,
        'message' => "GA4 sincronizado! " . count($sessionRows) . " registros, {$stats['sessions']} sessoes.",
        'imported' => count($sessionRows),
        'sessions' => $stats['sessions'],
        'total_rows' => count($rows),
        'match_modes' => $details['match_modes'],
        'unmatched' => $stats['unmatched'],
        'fallback_reports' => $fallbackReports,
    ]);
    exit;
}
