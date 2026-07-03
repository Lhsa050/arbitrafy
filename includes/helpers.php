<?php
/**
 * Helper Functions
 */

function formatMoney($value, $currency = 'BRL') {
    if ($value === null || $value === '') return '-';
    $prefix = $currency === 'USD' ? '$' : 'R$';
    return $prefix . ' ' . number_format((float)$value, 2, ',', '.');
}

function formatPercent($value) {
    if ($value === null || $value === '') return '-';
    return number_format((float)$value * 100, 2, ',', '.') . '%';
}

function formatPercentRaw($value) {
    if ($value === null || $value === '') return '-';
    return number_format((float)$value, 2, ',', '.') . '%';
}

function formatNumber($value, $decimals = 0) {
    if ($value === null || $value === '') return '-';
    return number_format((float)$value, $decimals, ',', '.');
}

function formatDate($date) {
    if (!$date) return '-';
    return date('d/m/Y', strtotime($date));
}

function formatDateTime($date) {
    if (!$date) return '-';
    return date('d/m/Y H:i', strtotime($date));
}

function roiClass($roi) {
    if ($roi === null || $roi === '') return '';
    $roi = (float)$roi;
    if ($roi > 0) return 'positive';
    if ($roi < 0) return 'negative';
    return 'neutral';
}

function roiIcon($roi) {
    if ($roi === null || $roi === '') return '';
    $roi = (float)$roi;
    if ($roi > 0) return '↑';
    if ($roi < 0) return '↓';
    return '→';
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect($url) {
    header("Location: {$url}");
    exit;
}

function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input ?? ''), ENT_QUOTES, 'UTF-8');
}

function getMonthName($num) {
    $months = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $months[$num] ?? '';
}

function getMonthNum($name) {
    $months = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'abril' => 4,
        'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8,
        'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12
    ];
    return $months[mb_strtolower($name)] ?? 0;
}

function calculateROI($investimento, $receita) {
    if (empty($investimento) || $investimento == 0) return 0;
    return ($receita - $investimento) / $investimento;
}

function calculateRoas($investimento, $receita) {
    if (empty($investimento) || $investimento == 0) return 0;
    return (($receita - $investimento) / $investimento) * 100;
}

function calculateProfit($receita, $investimento) {
    return $receita - $investimento;
}

function csvToArray($filePath, $delimiter = ',') {
    $data = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $header = fgetcsv($handle, 0, $delimiter);
        if ($header) {
            // Clean BOM
            $header[0] = preg_replace('/[\x{FEFF}]/u', '', $header[0]);
            $header = array_map('trim', $header);
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (count($row) === count($header)) {
                    $data[] = array_combine($header, $row);
                }
            }
        }
        fclose($handle);
    }
    return $data;
}

function isAjax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function currentPage() {
    return $_GET['page'] ?? 'dashboard';
}

/**
 * Get USD/BRL exchange rate — auto-fetched from AwesomeAPI, cached for 6 hours.
 * Falls back to manual setting if API is unreachable.
 */
function getCotacaoDolar() {
    $cached = getSetting('cotacao_dolar_cached', '');
    $cachedAt = getSetting('cotacao_dolar_cached_at', '');

    // Use cache if less than 6 hours old
    if ($cached && $cachedAt) {
        $age = time() - strtotime($cachedAt);
        if ($age < 21600 && (float)$cached > 0) { // 6h = 21600s
            return (float)$cached;
        }
    }

    // Try fetching from AwesomeAPI (free, no auth required)
    try {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $json = @file_get_contents('https://economia.awesomeapi.com.br/json/last/USD-BRL', false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            $rate = (float)($data['USDBRL']['bid'] ?? 0);
            if ($rate > 0) {
                setSetting('cotacao_dolar_cached', (string)$rate);
                setSetting('cotacao_dolar_cached_at', date('Y-m-d H:i:s'));
                setSetting('cotacao_dolar', (string)$rate); // Keep manual field in sync
                return $rate;
            }
        }
    } catch (Exception $e) {
        // API failed, use fallback
    }

    // Fallback: manual setting
    return (float)getSetting('cotacao_dolar', '5.80');
}

/**
 * Ensure ga4_sessions table exists (compatible with SQLite and MySQL)
 */
function ensureGA4Table() {
    static $created = false;
    if ($created) return;
    
    try {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            getDB()->exec("CREATE TABLE IF NOT EXISTS ga4_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                utm_source VARCHAR(100) DEFAULT 'facebook',
                sessions INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_session (date, campaign_id, utm_source)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            getDB()->exec("CREATE TABLE IF NOT EXISTS ga4_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                utm_source VARCHAR(100) DEFAULT 'facebook',
                sessions INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(date, campaign_id, utm_source)
            )");
        }
        $created = true;
    } catch (Exception $e) {
        // Silently fail
    }
}

/**
 * Ensure revenue table supports GAM site breakdowns and newer metrics.
 * Older installs created revenue with UNIQUE(date, campaign_id), which
 * breaks imports once site_name is included in the upsert key.
 */
function ensureRevenueTableSchema() {
    static $ensured = false;
    if ($ensured) return;

    try {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            getDB()->exec("CREATE TABLE IF NOT EXISTS revenue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                utm_campaign VARCHAR(255),
                receita_usd DECIMAL(12,6) DEFAULT 0,
                gam_impressions INT DEFAULT 0,
                gam_ad_requests INT DEFAULT 0,
                site_name VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_revenue_date_campaign_site (date, campaign_id, site_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $columns = array_column(fetchAll("SHOW COLUMNS FROM revenue"), 'Field');
            if (!in_array('gam_impressions', $columns, true)) {
                getDB()->exec("ALTER TABLE revenue ADD COLUMN gam_impressions INT DEFAULT 0");
            }
            if (!in_array('gam_ad_requests', $columns, true)) {
                getDB()->exec("ALTER TABLE revenue ADD COLUMN gam_ad_requests INT DEFAULT 0");
            }
            if (!in_array('site_name', $columns, true)) {
                getDB()->exec("ALTER TABLE revenue ADD COLUMN site_name VARCHAR(255) NOT NULL DEFAULT ''");
            } else {
                try {
                    getDB()->exec("UPDATE revenue SET site_name = '' WHERE site_name IS NULL");
                    getDB()->exec("ALTER TABLE revenue MODIFY site_name VARCHAR(255) NOT NULL DEFAULT ''");
                } catch (Exception $e) {
                }
            }

            ensureMySQLRevenueUniqueIndex();
        } else {
            getDB()->exec("CREATE TABLE IF NOT EXISTS revenue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                utm_campaign VARCHAR(255),
                receita_usd DECIMAL(12,6) DEFAULT 0,
                gam_impressions INTEGER DEFAULT 0,
                gam_ad_requests INTEGER DEFAULT 0,
                site_name VARCHAR(255) DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(date, campaign_id, site_name)
            )");

            $columns = array_column(fetchAll("PRAGMA table_info(revenue)"), 'name');
            if (!in_array('gam_impressions', $columns, true)) {
                getDB()->exec("ALTER TABLE revenue ADD COLUMN gam_impressions INTEGER DEFAULT 0");
            }
            if (!in_array('gam_ad_requests', $columns, true)) {
                getDB()->exec("ALTER TABLE revenue ADD COLUMN gam_ad_requests INTEGER DEFAULT 0");
            }
            if (!in_array('site_name', $columns, true)) {
                getDB()->exec("ALTER TABLE revenue ADD COLUMN site_name VARCHAR(255) DEFAULT ''");
            }
            getDB()->exec("UPDATE revenue SET site_name = '' WHERE site_name IS NULL");

            if (!sqliteRevenueHasUniqueSiteIndex()) {
                migrateSQLiteRevenueTableToSiteUnique();
            }
        }

        $ensured = true;
    } catch (Exception $e) {
        if (function_exists('logSync')) {
            logSync('GAM', 'ERROR', 'revenue_schema', 'Falha ao preparar tabela revenue para sync GAM: ' . $e->getMessage());
        }
        throw $e;
    }
}

function ensureMySQLRevenueUniqueIndex() {
    try {
        $indexes = fetchAll("SHOW INDEX FROM revenue WHERE Non_unique = 0");
    } catch (Exception $e) {
        return;
    }

    $grouped = [];
    foreach ($indexes as $idx) {
        $name = $idx['Key_name'] ?? '';
        if ($name === '' || strtoupper($name) === 'PRIMARY') {
            continue;
        }
        $seq = (int)($idx['Seq_in_index'] ?? 0);
        $grouped[$name][$seq] = $idx['Column_name'] ?? '';
    }

    $hasDesired = false;
    foreach ($grouped as $name => $colsBySeq) {
        ksort($colsBySeq);
        $cols = array_values(array_filter($colsBySeq));
        if ($cols === ['date', 'campaign_id', 'site_name']) {
            $hasDesired = true;
            continue;
        }
        if ($cols === ['date', 'campaign_id']) {
            $safeName = str_replace('`', '``', $name);
            try {
                getDB()->exec("ALTER TABLE revenue DROP INDEX `{$safeName}`");
            } catch (Exception $e) {
            }
        }
    }

    if (!$hasDesired) {
        try {
            getDB()->exec("ALTER TABLE revenue ADD UNIQUE KEY unique_revenue_date_campaign_site (date, campaign_id, site_name)");
        } catch (Exception $e) {
        }
    }
}

function sqliteRevenueHasUniqueSiteIndex() {
    $indexes = fetchAll("PRAGMA index_list('revenue')");
    foreach ($indexes as $idx) {
        if ((int)($idx['unique'] ?? 0) !== 1) {
            continue;
        }

        $indexName = $idx['name'] ?? '';
        if ($indexName === '') {
            continue;
        }

        $quoted = getDB()->quote($indexName);
        $info = getDB()->query("PRAGMA index_info({$quoted})")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($info, 'name');
        if ($cols === ['date', 'campaign_id', 'site_name']) {
            return true;
        }
    }

    return false;
}

function migrateSQLiteRevenueTableToSiteUnique() {
    $db = getDB();
    $tmp = 'revenue_migration_' . time();

    $db->beginTransaction();
    try {
        $db->exec("CREATE TABLE {$tmp} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date DATE NOT NULL,
            campaign_id VARCHAR(50) NOT NULL,
            utm_campaign VARCHAR(255),
            receita_usd DECIMAL(12,6) DEFAULT 0,
            gam_impressions INTEGER DEFAULT 0,
            gam_ad_requests INTEGER DEFAULT 0,
            site_name VARCHAR(255) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(date, campaign_id, site_name)
        )");

        $db->exec("INSERT INTO {$tmp} (
                date, campaign_id, utm_campaign, receita_usd,
                gam_impressions, gam_ad_requests, site_name, created_at
            )
            SELECT
                date,
                campaign_id,
                COALESCE(MAX(NULLIF(utm_campaign, '')), campaign_id),
                COALESCE(SUM(receita_usd), 0),
                COALESCE(SUM(gam_impressions), 0),
                COALESCE(SUM(gam_ad_requests), 0),
                COALESCE(site_name, ''),
                COALESCE(MIN(created_at), datetime('now'))
            FROM revenue
            GROUP BY date, campaign_id, COALESCE(site_name, '')");

        $db->exec("DROP TABLE revenue");
        $db->exec("ALTER TABLE {$tmp} RENAME TO revenue");
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Ensure revenue_placements table exists (compatible with SQLite and MySQL)
 */
function ensurePlacementsTable() {
    static $created = false;
    if ($created) return;
    
    try {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            getDB()->exec("CREATE TABLE IF NOT EXISTS revenue_placements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                placement VARCHAR(100) NOT NULL DEFAULT '',
                receita_usd DECIMAL(12,6) DEFAULT 0,
                gam_impressions INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_placement (date, campaign_id, placement)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            getDB()->exec("CREATE TABLE IF NOT EXISTS revenue_placements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                placement VARCHAR(100) NOT NULL DEFAULT '',
                receita_usd DECIMAL(12,6) DEFAULT 0,
                gam_impressions INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(date, campaign_id, placement)
            )");
        }
        $created = true;
    } catch (Exception $e) {
        // Silently fail
    }
}

/**
 * Ensure fb_placements table exists (actual FB spend per placement)
 */
function ensureFBPlacementsTable() {
    static $created = false;
    if ($created) return;
    
    try {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            getDB()->exec("CREATE TABLE IF NOT EXISTS fb_placements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                campaign_name VARCHAR(255) DEFAULT '',
                account_name VARCHAR(100) DEFAULT '',
                placement VARCHAR(100) NOT NULL DEFAULT '',
                spend DECIMAL(12,2) DEFAULT 0,
                impressions INT DEFAULT 0,
                clicks INT DEFAULT 0,
                cpc DECIMAL(10,6) DEFAULT 0,
                ctr DECIMAL(10,6) DEFAULT 0,
                cpm DECIMAL(10,6) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_fb_placement (date, campaign_id, placement)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            getDB()->exec("CREATE TABLE IF NOT EXISTS fb_placements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                campaign_name VARCHAR(255) DEFAULT '',
                account_name VARCHAR(100) DEFAULT '',
                placement VARCHAR(100) NOT NULL DEFAULT '',
                spend DECIMAL(12,2) DEFAULT 0,
                impressions INTEGER DEFAULT 0,
                clicks INTEGER DEFAULT 0,
                cpc DECIMAL(10,6) DEFAULT 0,
                ctr DECIMAL(10,6) DEFAULT 0,
                cpm DECIMAL(10,6) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(date, campaign_id, placement)
            )");
        }
        $created = true;
    } catch (Exception $e) {
        // Silently fail
    }
}

/**
 * Ensure fb_devices table exists (actual FB spend per device OS)
 */
function ensureFBDevicesTable() {
    static $created = false;
    if ($created) return;
    
    try {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            getDB()->exec("CREATE TABLE IF NOT EXISTS fb_devices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                campaign_name VARCHAR(255) DEFAULT '',
                account_name VARCHAR(100) DEFAULT '',
                device_os VARCHAR(100) NOT NULL DEFAULT '',
                spend DECIMAL(12,2) DEFAULT 0,
                impressions INT DEFAULT 0,
                clicks INT DEFAULT 0,
                cpc DECIMAL(10,6) DEFAULT 0,
                ctr DECIMAL(10,6) DEFAULT 0,
                cpm DECIMAL(10,6) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_fb_device (date, campaign_id, device_os)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            getDB()->exec("CREATE TABLE IF NOT EXISTS fb_devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                campaign_name VARCHAR(255) DEFAULT '',
                account_name VARCHAR(100) DEFAULT '',
                device_os VARCHAR(100) NOT NULL DEFAULT '',
                spend DECIMAL(12,2) DEFAULT 0,
                impressions INTEGER DEFAULT 0,
                clicks INTEGER DEFAULT 0,
                cpc DECIMAL(10,6) DEFAULT 0,
                ctr DECIMAL(10,6) DEFAULT 0,
                cpm DECIMAL(10,6) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(date, campaign_id, device_os)
            )");
        }
        $created = true;
    } catch (Exception $e) {
        // Silently fail
    }
}

/**
 * Ensure fb_hourly table exists (Facebook campaign performance per hour)
 */
function ensureFBHourlyTable() {
    static $created = false;
    if ($created) return;

    try {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            getDB()->exec("CREATE TABLE IF NOT EXISTS fb_hourly (
                id INT AUTO_INCREMENT PRIMARY KEY,
                date DATE NOT NULL,
                hour_start TINYINT NOT NULL DEFAULT 0,
                hour_label VARCHAR(30) NOT NULL DEFAULT '',
                campaign_id VARCHAR(50) NOT NULL,
                campaign_name VARCHAR(255) DEFAULT '',
                account_name VARCHAR(100) DEFAULT '',
                spend DECIMAL(12,2) DEFAULT 0,
                impressions INT DEFAULT 0,
                clicks INT DEFAULT 0,
                results INT DEFAULT 0,
                cpc DECIMAL(10,6) DEFAULT 0,
                ctr DECIMAL(10,6) DEFAULT 0,
                cpm DECIMAL(10,6) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_fb_hourly (date, hour_start, campaign_id, account_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            getDB()->exec("CREATE TABLE IF NOT EXISTS fb_hourly (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date DATE NOT NULL,
                hour_start INTEGER NOT NULL DEFAULT 0,
                hour_label VARCHAR(30) NOT NULL DEFAULT '',
                campaign_id VARCHAR(50) NOT NULL,
                campaign_name VARCHAR(255) DEFAULT '',
                account_name VARCHAR(100) DEFAULT '',
                spend DECIMAL(12,2) DEFAULT 0,
                impressions INTEGER DEFAULT 0,
                clicks INTEGER DEFAULT 0,
                results INTEGER DEFAULT 0,
                cpc DECIMAL(10,6) DEFAULT 0,
                ctr DECIMAL(10,6) DEFAULT 0,
                cpm DECIMAL(10,6) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(date, hour_start, campaign_id, account_name)
            )");
        }
        $created = true;
    } catch (Exception $e) {
        // Silently fail
    }
}

/**
 * Ensure revenue_devices table exists (GAM revenue per campaign per device category)
 */
function ensureRevenueDevicesTable() {
    static $created = false;
    if ($created) return;
    try {
        if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
            getDB()->exec("CREATE TABLE IF NOT EXISTS revenue_devices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                device_category VARCHAR(50) NOT NULL DEFAULT '',
                receita_usd DECIMAL(12,6) DEFAULT 0,
                gam_impressions INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_rev_device (date, campaign_id, device_category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            getDB()->exec("CREATE TABLE IF NOT EXISTS revenue_devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date DATE NOT NULL,
                campaign_id VARCHAR(50) NOT NULL,
                device_category VARCHAR(50) NOT NULL DEFAULT '',
                receita_usd DECIMAL(12,6) DEFAULT 0,
                gam_impressions INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(date, campaign_id, device_category)
            )");
        }
        $created = true;
    } catch (Exception $e) {}
}

/**
 * Map FB API publisher_platform + platform_position to the {{placement}} macro format
 * used in utm_content (which GAM receives)
 */
function mapFBPlacementToUtm($publisher, $position) {
    $map = [
        // Facebook placements
        'facebook|feed' => 'Facebook_Mobile_Feed',
        'facebook|right_hand_column' => 'Facebook_Right_Column',
        'facebook|instant_article' => 'Facebook_Instant_Articles',
        'facebook|instream_video' => 'Facebook_Instream_Vid',
        'facebook|marketplace' => 'Facebook_Marketplace',
        'facebook|video_feeds' => 'Facebook_Instream_Vid',
        'facebook|story' => 'Facebook_Stories',
        'facebook|stories' => 'Facebook_Stories',
        'facebook|facebook_stories' => 'Facebook_Stories',
        'facebook|search_results' => 'Facebook_Search',
        'facebook|search' => 'Facebook_Search',
        'facebook|facebook_reels' => 'Facebook_Mobile_Reels',
        'facebook|reels' => 'Facebook_Mobile_Reels',
        'facebook|reels_overlay' => 'Facebook_Mobile_Reels',
        'facebook|home' => 'Facebook_Mobile_Feed',
        // Instagram placements
        'instagram|stream' => 'Instagram_Feed',
        'instagram|feed' => 'Instagram_Feed',
        'instagram|story' => 'Instagram_Stories',
        'instagram|stories' => 'Instagram_Stories',
        'instagram|instagram_stories' => 'Instagram_Stories',
        'instagram|explore' => 'Instagram_Explore',
        'instagram|explore_home' => 'Instagram_Explore',
        'instagram|ig_reels' => 'Instagram_Reels',
        'instagram|reels' => 'Instagram_Reels',
        'instagram|instagram_reels' => 'Instagram_Reels',
        'instagram|reels_overlay' => 'Instagram_Reels',
        'instagram|shop' => 'Instagram_Shop',
        'instagram|instagram_search' => 'Instagram_Explore',
        'instagram|profile_feed' => 'Instagram_Feed',
        // Audience Network
        'audience_network|classic' => 'Audience_Network',
        'audience_network|rewarded_video' => 'Audience_Network_Rewarded',
        'audience_network|instream_video' => 'Audience_Network',
        // Messenger
        'messenger|inbox' => 'Messenger_Inbox',
        'messenger|messenger_stories' => 'Messenger_Stories',
        'messenger|story' => 'Messenger_Stories',
        'messenger|stories' => 'Messenger_Stories',
        'messenger|sponsored_messages' => 'Messenger_Inbox',
    ];
    
    $key = strtolower($publisher) . '|' . strtolower($position);
    if (isset($map[$key])) return $map[$key];
    
    // Fallback: strip publisher prefix from position if duplicated
    // e.g. instagram + instagram_stories → Instagram_Stories (not Instagram_Instagram_Stories)
    $cleanPosition = strtolower($position);
    $publisherLower = strtolower($publisher);
    if (strpos($cleanPosition, $publisherLower . '_') === 0) {
        $cleanPosition = substr($cleanPosition, strlen($publisherLower) + 1);
    }
    
    return ucfirst($publisher) . '_' . str_replace(' ', '_', ucwords(str_replace('_', ' ', $cleanPosition)));
}
