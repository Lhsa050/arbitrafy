<?php
/**
 * Heavy creative and latency audit.
 *
 * This page does not inject code into WordPress or touch SpunMidia, GPT,
 * preloader, ad iframes, cache, SEO or layout. It only reads local data and
 * optionally imports CSV exports for advertiser/demand partner analysis.
 */

$cotacao = getCotacaoDolar();
if (function_exists('ensureRevenueTableSchema')) ensureRevenueTableSchema();
if (function_exists('ensurePlacementsTable')) ensurePlacementsTable();
if (function_exists('ensureFBAdsTable')) ensureFBAdsTable();

list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();
$sourceFilter = $_GET['source'] ?? '';
$search = trim($_GET['search'] ?? '');
$message = '';
$error = '';

function cl_num($value) {
    if ($value === null || $value === '') return 0.0;
    $value = trim((string)$value);
    $value = str_replace(['R$', '$', 'USD', 'BRL', ' ', '%'], '', $value);
    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (strpos($value, ',') !== false) {
        $value = str_replace(',', '.', $value);
    }
    return is_numeric($value) ? (float)$value : 0.0;
}

function cl_percent($value) {
    $raw = trim((string)$value);
    $num = cl_num($raw);
    if ($num > 0 && $num <= 1 && strpos($raw, '%') === false) {
        return $num * 100;
    }
    return $num;
}

function cl_rate($num, $den) {
    $den = (float)$den;
    return $den > 0 ? ((float)$num / $den) * 100 : 0;
}

function cl_ms($value) {
    $num = cl_num($value);
    if ($num > 0 && $num < 20) {
        return $num * 1000;
    }
    return $num;
}

function cl_norm_header($header) {
    $header = trim((string)$header);
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header);
        if ($converted !== false) {
            $header = $converted;
        }
    }
    $header = strtolower($header);
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    return trim($header, '_');
}

function cl_pick($row, $keys, $default = '') {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return $default;
}

function cl_detect_delimiter($line) {
    $commas = substr_count($line, ',');
    $semis = substr_count($line, ';');
    return $semis > $commas ? ';' : ',';
}

function cl_ensure_table() {
    if (function_exists('ensureCreativeLatencyAuditTable')) {
        ensureCreativeLatencyAuditTable();
    }
}

function cl_assess($row, $hasLatency = true) {
    $score = 0;
    $flags = [];
    $sizeKb = cl_num($row['creative_size_kb'] ?? 0);
    $avgRender = cl_num($row['avg_render_ms'] ?? 0);
    $p95Render = cl_num($row['p95_render_ms'] ?? 0);
    $avgLoad = cl_num($row['avg_load_ms'] ?? 0);
    $p95Load = cl_num($row['p95_load_ms'] ?? 0);
    $heavy = cl_num($row['heavy_events'] ?? 0);
    $slowLoadPct = cl_num($row['slow_load_pct'] ?? 0);
    $verySlowLoadPct = cl_num($row['very_slow_load_pct'] ?? 0);
    $unviewedBeforeLoadedPct = cl_num($row['unviewed_before_loaded_pct'] ?? 0);
    $viewability = cl_num($row['viewability_pct'] ?? 0);
    $impressions = cl_num($row['impressions'] ?? 0);
    $requests = cl_num($row['ad_requests'] ?? 0);
    $empty = cl_num($row['empty_count'] ?? 0);

    $fillRate = cl_rate($impressions, $requests);
    $emptyRate = cl_rate($empty, max($requests, $impressions + $empty));

    if ($sizeKb >= 500) {
        $score += 30;
        $flags[] = ['level' => 'high', 'label' => 'Criativo muito pesado'];
    } elseif ($sizeKb >= 300) {
        $score += 15;
        $flags[] = ['level' => 'medium', 'label' => 'Criativo pesado'];
    }

    if ($avgRender >= 2000 || $p95Render >= 3500) {
        $score += 28;
        $flags[] = ['level' => 'high', 'label' => 'Render lento'];
    } elseif ($avgRender >= 1000 || $p95Render >= 2200) {
        $score += 14;
        $flags[] = ['level' => 'medium', 'label' => 'Render acima do ideal'];
    }

    if ($avgLoad >= 3000 || $p95Load >= 5000) {
        $score += 24;
        $flags[] = ['level' => 'high', 'label' => 'Load de criativo lento'];
    } elseif ($avgLoad >= 1500 || $p95Load >= 3000) {
        $score += 12;
        $flags[] = ['level' => 'medium', 'label' => 'Load acima do ideal'];
    }

    if ($heavy > 0) {
        $score += min(30, 10 + $heavy * 3);
        $flags[] = ['level' => 'high', 'label' => 'Evento de criativo pesado'];
    }

    if ($verySlowLoadPct >= 8) {
        $score += 20;
        $flags[] = ['level' => 'high', 'label' => 'Muitos loads acima de 4s'];
    } elseif ($slowLoadPct >= 20) {
        $score += 12;
        $flags[] = ['level' => 'medium', 'label' => 'Load lento recorrente'];
    }

    if ($unviewedBeforeLoadedPct >= 10) {
        $score += 12;
        $flags[] = ['level' => 'medium', 'label' => 'Usuario sai antes do ad carregar'];
    }

    if ($viewability > 0 && $viewability < 45) {
        $score += 12;
        $flags[] = ['level' => 'medium', 'label' => 'Viewability baixa'];
    }

    if ($requests >= 500 && $fillRate > 0 && $fillRate < 45) {
        $score += 12;
        $flags[] = ['level' => 'medium', 'label' => 'Fill/render rate baixo'];
    }

    if ($emptyRate >= 20) {
        $score += 10;
        $flags[] = ['level' => 'medium', 'label' => 'Muitos slots vazios'];
    }

    if (!$hasLatency && $sizeKb <= 0 && $avgRender <= 0 && $avgLoad <= 0) {
        $flags[] = ['level' => 'info', 'label' => 'Sem medicao de latencia'];
    }

    $level = 'low';
    if ($score >= 40) $level = 'high';
    elseif ($score >= 20) $level = 'medium';

    return [
        'risk_score' => min(100, (int)round($score)),
        'risk_level' => $level,
        'flags' => $flags,
        'fill_rate' => $fillRate,
        'empty_rate' => $emptyRate,
    ];
}

function cl_badge($level) {
    if ($level === 'high') return 'badge-red';
    if ($level === 'medium') return 'badge-yellow';
    if ($level === 'info') return 'badge-blue';
    return 'badge-green';
}

function cl_label($level) {
    if ($level === 'high') return 'Alto';
    if ($level === 'medium') return 'Atencao';
    if ($level === 'info') return 'Info';
    return 'OK';
}

function cl_meta_ad_flags($row) {
    $flags = [];
    $impressions = cl_num($row['impressions'] ?? 0);
    $clicks = cl_num($row['clicks'] ?? 0);
    $spend = cl_num($row['spend'] ?? 0);
    $revenue = cl_num($row['revenue_brl'] ?? 0);
    $ctr = cl_num($row['ctr'] ?? 0);
    $results = cl_num($row['results'] ?? 0);
    $roi = $spend > 0 ? (($revenue - $spend) / $spend) * 100 : 0;

    if ($impressions >= 1000 && $ctr >= 12) {
        $flags[] = ['level' => 'high', 'label' => 'CTR anormal'];
    } elseif ($impressions >= 1000 && $ctr >= 8) {
        $flags[] = ['level' => 'medium', 'label' => 'CTR alto'];
    }
    if ($spend >= 30 && $clicks > 0 && $results <= 0) {
        $flags[] = ['level' => 'medium', 'label' => 'Sem resultado'];
    }
    if ($revenue > 0 && $roi < -20) {
        $flags[] = ['level' => 'medium', 'label' => 'ROI negativo'];
    }
    if ($impressions >= 500 && $clicks <= 0) {
        $flags[] = ['level' => 'medium', 'label' => 'Sem clique'];
    }

    return $flags;
}

cl_ensure_table();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_latency_csv') {
    try {
        if (empty($_FILES['latency_csv']['tmp_name'])) {
            throw new Exception('Selecione um CSV.');
        }
        $tmp = $_FILES['latency_csv']['tmp_name'];
        $fh = fopen($tmp, 'r');
        if (!$fh) throw new Exception('Nao foi possivel abrir o CSV.');

        $firstLine = fgets($fh);
        if ($firstLine === false) throw new Exception('CSV vazio.');
        $delimiter = cl_detect_delimiter($firstLine);
        rewind($fh);

        $headersRaw = fgetcsv($fh, 0, $delimiter, '"', '');
        if (!$headersRaw) throw new Exception('Cabecalho do CSV nao encontrado.');
        $headers = array_map('cl_norm_header', $headersRaw);

        $imported = 0;
        while (($cols = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            if (count(array_filter($cols, fn($v) => trim((string)$v) !== '')) === 0) continue;
            $row = [];
            foreach ($headers as $idx => $header) {
                $row[$header] = $cols[$idx] ?? '';
            }

            $date = cl_pick($row, ['date', 'data', 'day']);
            if ($date !== '' && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $dm)) {
                $date = "{$dm[3]}-{$dm[2]}-{$dm[1]}";
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = $dateFrom;
            }

            $load24 = cl_percent(cl_pick($row, ['creative_load_time_2_4_s_percent', 'load_2_4_s_percent', 'load_2_4_pct', 'load_2s_4s_pct'], 0));
            $load48 = cl_percent(cl_pick($row, ['creative_load_time_4_8_s_percent', 'load_4_8_s_percent', 'load_4_8_pct', 'load_4s_8s_pct'], 0));
            $load8 = cl_percent(cl_pick($row, ['creative_load_time_greater_than_8_s_percent', 'creative_load_time_8_s_percent', 'load_gt_8_s_percent', 'load_8s_plus_pct'], 0));
            $slowLoadPct = cl_percent(cl_pick($row, ['slow_load_pct', 'load_lento_pct', 'slow_creative_load_pct'], 0));
            if ($slowLoadPct <= 0) {
                $slowLoadPct = $load24 + $load48 + $load8;
            }
            $verySlowLoadPct = cl_percent(cl_pick($row, ['very_slow_load_pct', 'load_muito_lento_pct', 'very_slow_creative_load_pct'], 0));
            if ($verySlowLoadPct <= 0) {
                $verySlowLoadPct = $load48 + $load8;
            }
            $unviewedBeforeLoadedPct = cl_percent(cl_pick($row, ['unviewed_before_loaded_pct', 'user_scrolled_before_ad_loaded_pct', 'unviewed_reason_user_scrolled_before_ad_loaded_percent'], 0));

            insert('creative_latency_audit', [
                'date' => $date,
                'advertiser' => trim(cl_pick($row, ['advertiser', 'anunciante', 'buyer', 'comprador'], '')),
                'demand_partner' => trim(cl_pick($row, ['demand_partner', 'demand', 'partner', 'demand_channel', 'ad_exchange', 'exchange', 'ssp', 'yield_partner'], '')),
                'creative_id' => trim(cl_pick($row, ['creative_id', 'creativeid', 'id_criativo', 'creative'], '')),
                'creative_name' => trim(cl_pick($row, ['creative_name', 'nome_criativo', 'creative_label', 'ad_name'], '')),
                'creative_dimensions' => trim(cl_pick($row, ['creative_dimensions', 'creative_size', 'ad_size', 'size', 'dimensions', 'tamanho_criativo'], '')),
                'source' => trim(cl_pick($row, ['source', 'fonte'], 'csv')),
                'impressions' => (int)cl_num(cl_pick($row, ['impressions', 'impressoes', 'impressions_adx'], 0)),
                'ad_requests' => (int)cl_num(cl_pick($row, ['ad_requests', 'requests', 'solicitacoes', 'ad_request'], 0)),
                'rendered' => (int)cl_num(cl_pick($row, ['rendered', 'ad_slot_rendered', 'renders'], 0)),
                'empty_count' => (int)cl_num(cl_pick($row, ['empty', 'empty_count', 'ad_slot_empty', 'vazios'], 0)),
                'heavy_events' => (int)cl_num(cl_pick($row, ['heavy_events', 'heavy_creatives', 'heavy', 'blocked_heavy_ads'], 0)),
                'avg_render_ms' => cl_ms(cl_pick($row, ['avg_render_ms', 'render_ms', 'latency_ms', 'avg_latency_ms'], 0)),
                'p95_render_ms' => cl_ms(cl_pick($row, ['p95_render_ms', 'p95_latency_ms', 'render_p95_ms'], 0)),
                'avg_load_ms' => cl_ms(cl_pick($row, ['avg_load_ms', 'load_ms', 'creative_load_ms'], 0)),
                'p95_load_ms' => cl_ms(cl_pick($row, ['p95_load_ms', 'load_p95_ms'], 0)),
                'creative_size_kb' => cl_num(cl_pick($row, ['creative_size_kb', 'size_kb', 'peso_kb', 'creative_weight_kb'], 0)),
                'slow_load_pct' => $slowLoadPct,
                'very_slow_load_pct' => $verySlowLoadPct,
                'unviewed_before_loaded_pct' => $unviewedBeforeLoadedPct,
                'viewability_pct' => cl_percent(cl_pick($row, ['viewability_pct', 'viewability', 'active_view', 'active_view_pct'], 0)),
                'ctr' => cl_percent(cl_pick($row, ['ctr', 'click_through_rate'], 0)),
                'revenue_usd' => cl_num(cl_pick($row, ['revenue_usd', 'revenue', 'receita_usd'], 0)),
                'notes' => trim(cl_pick($row, ['notes', 'observacoes', 'obs'], '')),
            ]);
            $imported++;
        }
        fclose($fh);
        $message = "CSV importado: {$imported} linhas.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$manualWhere = "WHERE date BETWEEN ? AND ?";
$manualParams = [$dateFrom, $dateTo];
if ($sourceFilter !== '') {
    $manualWhere .= " AND source = ?";
    $manualParams[] = $sourceFilter;
}
if ($search !== '') {
    $manualWhere .= " AND (advertiser LIKE ? OR demand_partner LIKE ? OR creative_id LIKE ? OR creative_name LIKE ?)";
    $manualParams[] = "%{$search}%";
    $manualParams[] = "%{$search}%";
    $manualParams[] = "%{$search}%";
    $manualParams[] = "%{$search}%";
}

$partnerRows = fetchAll("
    SELECT advertiser, demand_partner, source,
           SUM(impressions) as impressions,
           SUM(ad_requests) as ad_requests,
           SUM(rendered) as rendered,
           SUM(empty_count) as empty_count,
           SUM(heavy_events) as heavy_events,
           AVG(NULLIF(avg_render_ms, 0)) as avg_render_ms,
           MAX(p95_render_ms) as p95_render_ms,
           AVG(NULLIF(avg_load_ms, 0)) as avg_load_ms,
           MAX(p95_load_ms) as p95_load_ms,
           MAX(creative_size_kb) as creative_size_kb,
           MAX(creative_dimensions) as creative_dimensions,
           AVG(NULLIF(slow_load_pct, 0)) as slow_load_pct,
           AVG(NULLIF(very_slow_load_pct, 0)) as very_slow_load_pct,
           AVG(NULLIF(unviewed_before_loaded_pct, 0)) as unviewed_before_loaded_pct,
           AVG(NULLIF(viewability_pct, 0)) as viewability_pct,
           AVG(NULLIF(ctr, 0)) as ctr,
           SUM(revenue_usd) as revenue_usd,
           COUNT(*) as rows_count
    FROM creative_latency_audit
    {$manualWhere}
    GROUP BY advertiser, demand_partner, source
    ORDER BY impressions DESC, revenue_usd DESC
    LIMIT 100
", $manualParams);

$creativeRows = fetchAll("
    SELECT creative_id, creative_name, advertiser, demand_partner, source,
           SUM(impressions) as impressions,
           SUM(ad_requests) as ad_requests,
           SUM(empty_count) as empty_count,
           SUM(heavy_events) as heavy_events,
           AVG(NULLIF(avg_render_ms, 0)) as avg_render_ms,
           MAX(p95_render_ms) as p95_render_ms,
           AVG(NULLIF(avg_load_ms, 0)) as avg_load_ms,
           MAX(p95_load_ms) as p95_load_ms,
           MAX(creative_size_kb) as creative_size_kb,
           MAX(creative_dimensions) as creative_dimensions,
           AVG(NULLIF(slow_load_pct, 0)) as slow_load_pct,
           AVG(NULLIF(very_slow_load_pct, 0)) as very_slow_load_pct,
           AVG(NULLIF(unviewed_before_loaded_pct, 0)) as unviewed_before_loaded_pct,
           AVG(NULLIF(viewability_pct, 0)) as viewability_pct,
           AVG(NULLIF(ctr, 0)) as ctr,
           SUM(revenue_usd) as revenue_usd
    FROM creative_latency_audit
    {$manualWhere}
    GROUP BY creative_id, creative_name, advertiser, demand_partner, source
    ORDER BY impressions DESC, revenue_usd DESC
    LIMIT 100
", $manualParams);

foreach ($partnerRows as &$row) {
    $row = array_merge($row, cl_assess($row, true));
}
unset($row);
foreach ($creativeRows as &$row) {
    $row = array_merge($row, cl_assess($row, true));
}
unset($row);
usort($partnerRows, fn($a, $b) => ($b['risk_score'] <=> $a['risk_score']) ?: ((int)$b['impressions'] <=> (int)$a['impressions']));
usort($creativeRows, fn($a, $b) => ($b['risk_score'] <=> $a['risk_score']) ?: ((int)$b['impressions'] <=> (int)$a['impressions']));

$metaAdRows = [];
if ($sourceFilter === '' || $sourceFilter === 'facebook_ads') {
    try {
        $cotacaoSql = (float)$cotacao;
        $fbAdWhere = "WHERE fa.date BETWEEN ? AND ?";
        $fbAdParams = [$dateFrom, $dateTo];
        if ($search !== '') {
            $fbAdWhere .= " AND (fa.ad_name LIKE ? OR fa.ad_id LIKE ? OR fa.campaign_name LIKE ? OR fa.campaign_id LIKE ? OR fa.account_name LIKE ?)";
            $fbAdParams[] = "%{$search}%";
            $fbAdParams[] = "%{$search}%";
            $fbAdParams[] = "%{$search}%";
            $fbAdParams[] = "%{$search}%";
            $fbAdParams[] = "%{$search}%";
        }

        $metaAdRows = fetchAll("
            SELECT fa.ad_id,
                   COALESCE(MAX(NULLIF(fa.ad_name, '')), fa.ad_id) as ad_name,
                   fa.campaign_id,
                   COALESCE(MAX(NULLIF(fa.campaign_name, '')), fa.campaign_id) as campaign_name,
                   COALESCE(MAX(NULLIF(fa.account_name, '')), '-') as account_name,
                   SUM(fa.spend) as spend,
                   SUM(fa.impressions) as impressions,
                   SUM(fa.clicks) as clicks,
                   SUM(fa.results) as results,
                   CASE WHEN SUM(fa.clicks) > 0 THEN SUM(fa.spend) / SUM(fa.clicks) ELSE 0 END as cpc,
                   CASE WHEN SUM(fa.impressions) > 0 THEN (SUM(fa.clicks) * 100.0) / SUM(fa.impressions) ELSE 0 END as ctr,
                   CASE WHEN SUM(fa.impressions) > 0 THEN (SUM(fa.spend) * 1000.0) / SUM(fa.impressions) ELSE 0 END as cpm,
                   COALESCE(SUM(CASE
                       WHEN cs.campaign_spend > 0 THEN COALESCE(r.revenue_brl, 0) * fa.spend / cs.campaign_spend
                       ELSE 0
                   END), 0) as revenue_brl
            FROM fb_ads fa
            LEFT JOIN (
                SELECT date, campaign_id, SUM(spend) as campaign_spend
                FROM fb_ads
                WHERE date BETWEEN ? AND ?
                GROUP BY date, campaign_id
            ) cs ON cs.date = fa.date AND cs.campaign_id = fa.campaign_id
            LEFT JOIN (
                SELECT date, campaign_id, SUM(receita_usd) * {$cotacaoSql} as revenue_brl
                FROM revenue
                WHERE date BETWEEN ? AND ?
                GROUP BY date, campaign_id
            ) r ON r.date = fa.date AND r.campaign_id = fa.campaign_id
            {$fbAdWhere}
            GROUP BY fa.ad_id, fa.campaign_id
            ORDER BY spend DESC, impressions DESC
            LIMIT 100
        ", array_merge([$dateFrom, $dateTo, $dateFrom, $dateTo], $fbAdParams));
    } catch (Exception $e) {
        $metaAdRows = [];
    }
}

foreach ($metaAdRows as &$row) {
    $row['roi_pct'] = cl_num($row['spend']) > 0 ? ((cl_num($row['revenue_brl']) - cl_num($row['spend'])) / cl_num($row['spend'])) * 100 : 0;
    $row['cpa'] = cl_num($row['results']) > 0 ? cl_num($row['spend']) / cl_num($row['results']) : 0;
    $row['flags'] = cl_meta_ad_flags($row);
}
unset($row);

$sourceRows = fetchAll("SELECT DISTINCT source FROM creative_latency_audit WHERE source != '' ORDER BY source");
$hasFBAdsRows = 0;
try {
    $hasFBAdsRows = (int)(fetchOne("SELECT COUNT(*) as c FROM fb_ads")['c'] ?? 0);
} catch (Exception $e) {
    $hasFBAdsRows = 0;
}
if ($hasFBAdsRows > 0) {
    $sourceRows[] = ['source' => 'facebook_ads'];
}
usort($sourceRows, fn($a, $b) => strcmp($a['source'], $b['source']));

$siteRows = [];
try {
    $siteRows = fetchAll("
        SELECT COALESCE(NULLIF(site_name, ''), 'Sem site') as site_name,
               SUM(receita_usd) * {$cotacao} as revenue_brl,
               SUM(gam_impressions) as impressions,
               SUM(gam_ad_requests) as ad_requests
        FROM revenue
        WHERE date BETWEEN ? AND ?
        GROUP BY COALESCE(NULLIF(site_name, ''), 'Sem site')
        ORDER BY revenue_brl DESC
    ", [$dateFrom, $dateTo]);
} catch (Exception $e) {
    $siteRows = [];
}
foreach ($siteRows as &$row) {
    $row['source'] = 'GAM atual';
    $row['empty_count'] = 0;
    $row['heavy_events'] = 0;
    $row['creative_size_kb'] = 0;
    $row['avg_render_ms'] = 0;
    $row['avg_load_ms'] = 0;
    $row = array_merge($row, cl_assess($row, false));
    $row['ecpm'] = cl_num($row['impressions']) > 0 ? (cl_num($row['revenue_brl']) / cl_num($row['impressions'])) * 1000 : 0;
}
unset($row);

$placementRows = [];
try {
    $placementRows = fetchAll("
        SELECT placement,
               SUM(receita_usd) * {$cotacao} as revenue_brl,
               SUM(gam_impressions) as impressions
        FROM revenue_placements
        WHERE date BETWEEN ? AND ?
        GROUP BY placement
        ORDER BY revenue_brl DESC
        LIMIT 50
    ", [$dateFrom, $dateTo]);
} catch (Exception $e) {
    $placementRows = [];
}

$programmatic = [];
try {
    $programmatic = fetchOne("
        SELECT COALESCE(AVG(active_view_pct), 0) as active_view_pct,
               COALESCE(AVG(match_rate), 0) as match_rate,
               COALESCE(SUM(ad_requests), 0) as ad_requests,
               COALESCE(SUM(impressions), 0) as impressions,
               COALESCE(SUM(revenue_usd), 0) as revenue_usd
        FROM receita_programatica
        WHERE date BETWEEN ? AND ?
    ", [$dateFrom, $dateTo]) ?: [];
} catch (Exception $e) {
    $programmatic = [];
}

$totalImportedRows = fetchOne("SELECT COUNT(*) as c FROM creative_latency_audit")['c'] ?? 0;
$highRiskPartners = count(array_filter($partnerRows, fn($r) => $r['risk_level'] === 'high'));
$highRiskCreatives = count(array_filter($creativeRows, fn($r) => $r['risk_level'] === 'high'));
$manualImpressions = array_sum(array_map(fn($r) => cl_num($r['impressions']), $partnerRows));
$manualRevenue = array_sum(array_map(fn($r) => cl_num($r['revenue_usd']), $partnerRows));
$metaAdCount = count($metaAdRows);
$metaAdSpend = array_sum(array_map(fn($r) => cl_num($r['spend']), $metaAdRows));
$metaAdRevenue = array_sum(array_map(fn($r) => cl_num($r['revenue_brl']), $metaAdRows));
$metaAdAlerts = array_sum(array_map(fn($r) => count($r['flags'] ?? []), $metaAdRows));

function cl_url_params($overrides = []) {
    $params = array_merge($_GET, ['page' => 'creative-latency'], $overrides);
    return '?' . http_build_query($params);
}
?>

<?php
$currentPage = 'creative-latency';
ob_start();
?>
<input type="text" class="filter-input" style="max-width:220px;min-height:34px;padding:6px 10px;font-size:12px;" placeholder="Buscar anunciante, partner ou criativo..." value="<?= sanitize($search) ?>"
       onkeydown="if(event.key==='Enter'){var p=new URLSearchParams(location.search);p.set('page','creative-latency');p.set('search',this.value);location.href='?'+p.toString();}">
<select class="filter-input" style="max-width:180px;min-height:34px;padding:6px 10px;font-size:12px;" onchange="var p=new URLSearchParams(location.search);p.set('page','creative-latency');p.set('source',this.value);location.href='?'+p.toString()">
    <option value="">Todas as fontes</option>
    <?php foreach ($sourceRows as $src): ?>
    <option value="<?= sanitize($src['source']) ?>" <?= $sourceFilter === $src['source'] ? 'selected' : '' ?>><?= sanitize($src['source']) ?></option>
    <?php endforeach; ?>
</select>
<button class="btn-export" onclick="exportTableToCSV('creativePartnerTable', 'auditoria_demand_partner_<?= $dateFrom ?>_<?= $dateTo ?>')">Exportar CSV</button>
<button class="btn btn-primary btn-sm js-sync-creatives" id="btnSyncCreativeGam" onclick="syncCreatives()">Sync Criativos</button>
<?php $dateFilterExtra = ob_get_clean(); ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<?php if ($message): ?><div class="alert alert-success"><?= sanitize($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= sanitize($error) ?></div><?php endif; ?>

<div class="cl-note">
    <strong>Auditoria de criativos pesados e latencia.</strong>
    O Google Ad Manager recomenda minimizar latencia e evitar criativos pesados. Esta aba nao altera tags, SpunMidia, GPT, preloader ou anuncios; ela cruza dados locais, sincroniza relatorios GAM quando disponiveis e aceita CSV para campos externos como peso real em KB.
    <a href="https://support.google.com/admanager/answer/7485975?hl=en" target="_blank" rel="noopener" style="color:#64d2ff;">Fonte Google Ad Manager</a>.
</div>

<div class="cards-grid">
    <div class="card <?= $highRiskPartners > 0 ? 'card-red' : 'card-green' ?>">
        <div class="card-label">Partners com risco alto</div>
        <div class="card-value"><?= formatNumber($highRiskPartners) ?></div>
        <div class="card-change"><?= count($partnerRows) ?> parceiros/anunciantes filtrados</div>
    </div>
    <div class="card <?= $highRiskCreatives > 0 ? 'card-red' : 'card-purple' ?>">
        <div class="card-label">Criativos com risco alto</div>
        <div class="card-value"><?= formatNumber($highRiskCreatives) ?></div>
        <div class="card-change"><?= count($creativeRows) ?> criativos filtrados</div>
    </div>
    <div class="card <?= $metaAdAlerts > 0 ? 'card-yellow' : 'card-blue' ?>">
        <div class="card-label">Criativos Meta Ads</div>
        <div class="card-value"><?= formatNumber($metaAdCount) ?></div>
        <div class="card-change"><?= formatMoney($metaAdSpend) ?> investidos</div>
    </div>
    <div class="card card-blue">
        <div class="card-label">Impressoes importadas</div>
        <div class="card-value"><?= formatNumber($manualImpressions) ?></div>
        <div class="card-change"><?= formatNumber($totalImportedRows) ?> linhas historicas</div>
    </div>
    <div class="card card-green">
        <div class="card-label">Revenue importado</div>
        <div class="card-value"><?= formatMoney($manualRevenue, 'USD') ?></div>
        <div class="card-change">GAM/CSV/relatorios externos</div>
    </div>
</div>

<div class="cl-grid-2">
    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Importar relatorio por anunciante / demand partner</span>
        </div>
        <form method="post" enctype="multipart/form-data" class="cl-import">
            <input type="hidden" name="action" value="import_latency_csv">
            <input type="file" name="latency_csv" accept=".csv,text/csv" class="filter-input" required>
            <button class="btn btn-primary btn-sm" type="submit">Importar CSV</button>
        </form>
        <div class="cl-help">
            Colunas aceitas: <code>date</code>, <code>advertiser</code>, <code>demand_partner</code>, <code>creative_id</code>, <code>creative_name</code>, <code>creative_dimensions</code>, <code>impressions</code>, <code>ad_requests</code>, <code>avg_render_ms</code>, <code>p95_render_ms</code>, <code>avg_load_ms</code>, <code>creative_size_kb</code>, <code>slow_load_pct</code>, <code>very_slow_load_pct</code>, <code>viewability_pct</code>, <code>heavy_events</code>, <code>revenue_usd</code>.
        </div>
    </div>

    <div class="table-container">
        <div class="table-header"><span class="table-title">Sinais GAM/Spun ja disponiveis</span></div>
        <div class="table-scroll">
            <table class="cl-small-table">
                <thead><tr><th>Sinal</th><th>Valor</th><th>Uso na auditoria</th></tr></thead>
                <tbody>
                    <tr><td>Fill rate GAM</td><td><?= cl_num($programmatic['ad_requests'] ?? 0) > 0 ? formatNumber(cl_rate($programmatic['impressions'], $programmatic['ad_requests']), 1) . '%' : '-' ?></td><td>Proxy de request/render vazio</td></tr>
                    <tr><td>Active View</td><td><?= cl_num($programmatic['active_view_pct'] ?? 0) > 0 ? formatPercentRaw(cl_num($programmatic['active_view_pct']) * 100) : '-' ?></td><td>Proxy de viewability</td></tr>
                    <tr><td>Match rate</td><td><?= cl_num($programmatic['match_rate'] ?? 0) > 0 ? formatPercentRaw(cl_num($programmatic['match_rate']) * 100) : '-' ?></td><td>Risco de demanda fraca</td></tr>
                    <tr><td>Criativos Meta Ads</td><td><?= $metaAdCount > 0 ? formatNumber($metaAdCount) . ' anuncios' : '-' ?></td><td>Performance por criativo/anuncio do Facebook</td></tr>
                    <tr><td>Latencia real por criativo</td><td><?= $totalImportedRows > 0 ? formatNumber($totalImportedRows) . ' linhas' : '-' ?></td><td>GAM Ad Speed quando habilitado, CSV/Spun como complemento</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Criativos Meta Ads</span>
        <span class="badge <?= $metaAdCount > 0 ? 'badge-blue' : 'badge-yellow' ?>"><?= $metaAdCount > 0 ? formatNumber($metaAdCount) . ' anuncios' : 'aguardando sync' ?></span>
        <button class="btn btn-primary btn-sm js-sync-creatives" type="button" onclick="syncCreatives()">Sync Criativos</button>
    </div>
    <div class="table-scroll">
        <table id="metaCreativeTable" class="cl-table">
            <thead>
                <tr>
                    <th>ID</th><th>Criativo/anuncio</th><th>Campanha</th><th>Conta</th>
                    <th>Invest.</th><th>Receita atrib.</th><th>ROI</th><th>Imp.</th><th>Cliques</th>
                    <th>CTR</th><th>CPC</th><th>CPM</th><th>Resultados</th><th>CPA</th><th>Alertas</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($metaAdRows)): ?>
                <tr>
                    <td colspan="15" class="empty-state" style="padding:32px;">
                        Sem dados de criativos Meta Ads no periodo. Precisa de conexao Facebook ativa, conta com permissoes de Ads e anuncios com impressao/gasto dentro do filtro.
                        <br><br><button class="btn btn-primary btn-sm js-sync-creatives" type="button" onclick="syncCreatives()">Sincronizar agora</button>
                    </td>
                </tr>
            <?php else: foreach ($metaAdRows as $r):
                $hasHighFlag = count(array_filter($r['flags'], fn($flag) => $flag['level'] === 'high')) > 0;
                $rowClass = $hasHighFlag ? 'cl-risk-high' : (!empty($r['flags']) ? 'cl-risk-medium' : '');
                $hasRevenue = cl_num($r['revenue_brl']) > 0;
            ?>
                <tr class="<?= sanitize($rowClass) ?>">
                    <td>
                        <button type="button" class="cl-copy-btn" data-copy="<?= sanitize($r['ad_id']) ?>" onclick="copyCreativeId(this.dataset.copy)" title="Copiar ID">Copiar</button>
                        <span class="cl-id"><?= sanitize($r['ad_id']) ?></span>
                    </td>
                    <td title="<?= sanitize($r['ad_name']) ?>"><?= sanitize($r['ad_name'] ?: 'Sem nome') ?></td>
                    <td title="<?= sanitize($r['campaign_name']) ?>"><?= sanitize($r['campaign_name'] ?: $r['campaign_id']) ?></td>
                    <td><?= sanitize($r['account_name'] ?: '-') ?></td>
                    <td><?= formatMoney($r['spend']) ?></td>
                    <td><?= $hasRevenue ? formatMoney($r['revenue_brl']) : '-' ?></td>
                    <td class="<?= $hasRevenue ? roiClass($r['roi_pct']) : '' ?>"><?= $hasRevenue ? formatPercentRaw($r['roi_pct']) : '-' ?></td>
                    <td><?= formatNumber($r['impressions']) ?></td>
                    <td><?= formatNumber($r['clicks']) ?></td>
                    <td><?= cl_num($r['ctr']) > 0 ? formatPercentRaw($r['ctr']) : '-' ?></td>
                    <td><?= cl_num($r['cpc']) > 0 ? formatMoney($r['cpc']) : '-' ?></td>
                    <td><?= cl_num($r['cpm']) > 0 ? formatMoney($r['cpm']) : '-' ?></td>
                    <td><?= formatNumber($r['results']) ?></td>
                    <td><?= cl_num($r['cpa']) > 0 ? formatMoney($r['cpa']) : '-' ?></td>
                    <td>
                        <?php if (empty($r['flags'])): ?>
                            <span class="badge badge-green">OK</span>
                        <?php else: foreach (array_slice($r['flags'], 0, 3) as $flag): ?>
                            <span class="cl-flag cl-flag-<?= sanitize($flag['level']) ?>"><?= sanitize($flag['label']) ?></span>
                        <?php endforeach; endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="cl-help">
        Receita atribuida = receita da campanha/dia distribuida proporcionalmente pelo investimento de cada anuncio. Peso real em KB e latencia real ainda dependem do GAM Ad Speed, Spun ou CSV externo.
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Anunciante / demand partner</span>
        <span class="badge <?= empty($partnerRows) ? 'badge-yellow' : 'badge-blue' ?>"><?= empty($partnerRows) ? 'aguardando CSV' : count($partnerRows) . ' linhas' ?></span>
    </div>
    <div class="table-scroll">
        <table id="creativePartnerTable" class="cl-table">
            <thead>
                <tr>
                    <th>Risco</th><th>Score</th><th>Anunciante</th><th>Demand partner</th><th>Fonte</th>
                    <th>Imp.</th><th>Requests</th><th>Fill</th><th>Heavy</th><th>Peso max</th><th>Dimensao</th>
                    <th>Render medio</th><th>P95 render</th><th>Load medio</th><th>Load lento</th><th>&gt;4s</th><th>Viewability</th><th>Revenue</th><th>Alertas</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($partnerRows)): ?>
                <tr><td colspan="19" class="empty-state" style="padding:38px;">Ainda nao ha dados por anunciante/demand partner. Use Sync Criativos para tentar o GAM ou importe um CSV exportado do GAM, Spun, AdX ou monitoramento leve.</td></tr>
            <?php else: foreach ($partnerRows as $r): ?>
                <tr class="cl-risk-<?= sanitize($r['risk_level']) ?>">
                    <td><span class="badge <?= cl_badge($r['risk_level']) ?>"><?= cl_label($r['risk_level']) ?></span></td>
                    <td><span class="cl-score <?= $r['risk_score'] >= 40 ? 'cl-score-bad' : ($r['risk_score'] >= 20 ? 'cl-score-mid' : 'cl-score-good') ?>"><?= (int)$r['risk_score'] ?></span></td>
                    <td><?= sanitize($r['advertiser'] ?: 'Sem anunciante') ?></td>
                    <td><?= sanitize($r['demand_partner'] ?: 'Sem partner') ?></td>
                    <td><?= sanitize($r['source'] ?: 'csv') ?></td>
                    <td><?= formatNumber($r['impressions']) ?></td>
                    <td><?= formatNumber($r['ad_requests']) ?></td>
                    <td><?= $r['fill_rate'] > 0 ? formatNumber($r['fill_rate'], 1) . '%' : '-' ?></td>
                    <td><?= (int)$r['heavy_events'] > 0 ? formatNumber($r['heavy_events']) : '-' ?></td>
                    <td><?= $r['creative_size_kb'] > 0 ? formatNumber($r['creative_size_kb'], 0) . ' KB' : '-' ?></td>
                    <td><?= sanitize($r['creative_dimensions'] ?: '-') ?></td>
                    <td><?= $r['avg_render_ms'] > 0 ? formatNumber($r['avg_render_ms'], 0) . ' ms' : '-' ?></td>
                    <td><?= $r['p95_render_ms'] > 0 ? formatNumber($r['p95_render_ms'], 0) . ' ms' : '-' ?></td>
                    <td><?= $r['avg_load_ms'] > 0 ? formatNumber($r['avg_load_ms'], 0) . ' ms' : '-' ?></td>
                    <td><?= $r['slow_load_pct'] > 0 ? formatNumber($r['slow_load_pct'], 1) . '%' : '-' ?></td>
                    <td><?= $r['very_slow_load_pct'] > 0 ? formatNumber($r['very_slow_load_pct'], 1) . '%' : '-' ?></td>
                    <td><?= $r['viewability_pct'] > 0 ? formatNumber($r['viewability_pct'], 1) . '%' : '-' ?></td>
                    <td><?= formatMoney($r['revenue_usd'], 'USD') ?></td>
                    <td>
                        <?php foreach (array_slice($r['flags'], 0, 3) as $flag): ?>
                            <span class="cl-flag cl-flag-<?= sanitize($flag['level']) ?>"><?= sanitize($flag['label']) ?></span>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Criativos auditados</span>
        <span class="badge badge-blue"><?= count($creativeRows) ?> criativos</span>
    </div>
    <div class="table-scroll">
        <table class="cl-table">
            <thead>
                <tr>
                    <th>Risco</th><th>Score</th><th>Criativo</th><th>Anunciante</th><th>Partner</th>
                    <th>Imp.</th><th>Peso</th><th>Dimensao</th><th>Render medio</th><th>P95 render</th><th>Load medio</th><th>Load lento</th><th>&gt;4s</th><th>Viewability</th><th>Heavy</th><th>Revenue</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($creativeRows)): ?>
                <tr><td colspan="16" class="empty-state" style="padding:32px;">Sem criativos importados ainda.</td></tr>
            <?php else: foreach ($creativeRows as $r): ?>
                <tr class="cl-risk-<?= sanitize($r['risk_level']) ?>">
                    <td><span class="badge <?= cl_badge($r['risk_level']) ?>"><?= cl_label($r['risk_level']) ?></span></td>
                    <td><span class="cl-score <?= $r['risk_score'] >= 40 ? 'cl-score-bad' : ($r['risk_score'] >= 20 ? 'cl-score-mid' : 'cl-score-good') ?>"><?= (int)$r['risk_score'] ?></span></td>
                    <td title="<?= sanitize($r['creative_id']) ?>"><?= sanitize($r['creative_name'] ?: ($r['creative_id'] ?: 'Sem criativo')) ?></td>
                    <td><?= sanitize($r['advertiser'] ?: '-') ?></td>
                    <td><?= sanitize($r['demand_partner'] ?: '-') ?></td>
                    <td><?= formatNumber($r['impressions']) ?></td>
                    <td><?= $r['creative_size_kb'] > 0 ? formatNumber($r['creative_size_kb'], 0) . ' KB' : '-' ?></td>
                    <td><?= sanitize($r['creative_dimensions'] ?: '-') ?></td>
                    <td><?= $r['avg_render_ms'] > 0 ? formatNumber($r['avg_render_ms'], 0) . ' ms' : '-' ?></td>
                    <td><?= $r['p95_render_ms'] > 0 ? formatNumber($r['p95_render_ms'], 0) . ' ms' : '-' ?></td>
                    <td><?= $r['avg_load_ms'] > 0 ? formatNumber($r['avg_load_ms'], 0) . ' ms' : '-' ?></td>
                    <td><?= $r['slow_load_pct'] > 0 ? formatNumber($r['slow_load_pct'], 1) . '%' : '-' ?></td>
                    <td><?= $r['very_slow_load_pct'] > 0 ? formatNumber($r['very_slow_load_pct'], 1) . '%' : '-' ?></td>
                    <td><?= $r['viewability_pct'] > 0 ? formatNumber($r['viewability_pct'], 1) . '%' : '-' ?></td>
                    <td><?= (int)$r['heavy_events'] > 0 ? formatNumber($r['heavy_events']) : '-' ?></td>
                    <td><?= formatMoney($r['revenue_usd'], 'USD') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="cl-grid-2">
    <div class="table-container">
        <div class="table-header"><span class="table-title">Proxy por site / inventario GAM</span></div>
        <div class="table-scroll">
            <table class="cl-small-table">
                <thead><tr><th>Site</th><th>Revenue</th><th>Imp.</th><th>Requests</th><th>Fill</th><th>eCPM</th><th>Risco</th></tr></thead>
                <tbody>
                <?php if (empty($siteRows)): ?>
                    <tr><td colspan="7" class="empty-state">Sem dados GAM no periodo.</td></tr>
                <?php else: foreach ($siteRows as $r): ?>
                    <tr>
                        <td><?= sanitize($r['site_name']) ?></td>
                        <td><?= formatMoney($r['revenue_brl']) ?></td>
                        <td><?= formatNumber($r['impressions']) ?></td>
                        <td><?= formatNumber($r['ad_requests']) ?></td>
                        <td><?= $r['fill_rate'] > 0 ? formatNumber($r['fill_rate'], 1) . '%' : '-' ?></td>
                        <td><?= $r['ecpm'] > 0 ? formatMoney($r['ecpm']) : '-' ?></td>
                        <td><span class="badge <?= cl_badge($r['risk_level']) ?>"><?= cl_label($r['risk_level']) ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-container">
        <div class="table-header"><span class="table-title">Proxy por placement / UTM content</span></div>
        <div class="table-scroll">
            <table class="cl-small-table">
                <thead><tr><th>Placement</th><th>Revenue</th><th>Imp.</th><th>eCPM</th><th>Observacao</th></tr></thead>
                <tbody>
                <?php if (empty($placementRows)): ?>
                    <tr><td colspan="5" class="empty-state">Sem revenue por placement no periodo.</td></tr>
                <?php else: foreach ($placementRows as $r):
                    $ecpm = cl_num($r['impressions']) > 0 ? (cl_num($r['revenue_brl']) / cl_num($r['impressions'])) * 1000 : 0;
                ?>
                    <tr>
                        <td><?= sanitize($r['placement'] ?: 'Sem placement') ?></td>
                        <td><?= formatMoney($r['revenue_brl']) ?></td>
                        <td><?= formatNumber($r['impressions']) ?></td>
                        <td><?= $ecpm > 0 ? formatMoney($ecpm) : '-' ?></td>
                        <td>Sem latencia por criativo</td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="table-container">
    <div class="table-header"><span class="table-title">Checklist seguro para medir latencia real</span></div>
    <div class="table-scroll">
        <table class="cl-table">
            <thead><tr><th>Item</th><th>Status</th><th>Como medir sem interferir</th></tr></thead>
            <tbody>
                <tr><td>Criativo pesado</td><td><span class="badge badge-yellow">Parcial</span></td><td>GAM envia tamanho/dimensao do slot; peso real em KB ainda depende de CSV Spun/ad server ou auditoria de rede externa.</td></tr>
                <tr><td>Render/load por anunciante</td><td><span class="badge badge-blue">GAM Ad Speed</span></td><td>Sincronizacao tenta importar buckets de load do GAM. Se a conta nao liberar o relatorio, os logs mostram o erro.</td></tr>
                <tr><td>Demand partner</td><td><span class="badge badge-blue">GAM classificado</span></td><td>Sincronizacao tenta importar parceiro classificado. Latencia por partner ainda pode exigir Spun/CSV se o GAM nao cruzar com Ad Speed.</td></tr>
                <tr><td>ad_slot_requested/rendered/empty</td><td><span class="badge badge-red">Nao instalado no site</span></td><td>Implementar somente apos confirmacao; usar dataLayer/GA4, sem alterar GPT, Spun, preloader ou layout.</td></tr>
                <tr><td>visible_ad_detected</td><td><span class="badge badge-yellow">Cuidado</span></td><td>Medir apenas visibilidade de containers proprios, sem acessar conteudo cross-origin do iframe.</td></tr>
                <tr><td>Politicas Google</td><td><span class="badge badge-green">Respeitado</span></td><td>Sem fingerprint agressivo, sem PII e sem manipular leilao/anuncio.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
async function fetchSyncJson(url, label) {
    const res = await fetch(url, { cache: 'no-store' });
    let json;
    try {
        json = await res.json();
    } catch (e) {
        throw new Error(`${label}: resposta invalida do servidor`);
    }
    if (!json.success) {
        throw new Error(json.error || json.message || `${label}: falha na sincronizacao`);
    }
    return json;
}

async function syncCreatives() {
    const buttons = Array.from(document.querySelectorAll('.js-sync-creatives'));
    const setButtons = (text, disabled) => {
        buttons.forEach((btn) => {
            if (!btn.dataset.originalText) btn.dataset.originalText = btn.textContent;
            btn.disabled = disabled;
            btn.textContent = text;
        });
    };
    setButtons('Meta Ads...', true);

    try {
        const errors = [];
        let fbJson = {};
        let gamJson = {};

        try {
            fbJson = await fetchSyncJson('api/sync.php?action=sync_fb&skip_cross_ref=1&creative_audit=1', 'Meta Ads');
        } catch (e) {
            errors.push(e.message);
        }

        setButtons('GAM...', true);
        try {
            gamJson = await fetchSyncJson('api/sync.php?action=sync_gam&skip_cross_ref=1&creative_audit=1', 'GAM');
        } catch (e) {
            errors.push(e.message);
        }

        const ads = fbJson.creative_ads ?? 0;
        const imported = gamJson.creative_latency_imported ?? 0;
        if (errors.length && ads <= 0 && imported <= 0) {
            throw new Error(errors.join(' | '));
        }

        const msg = `Meta Ads: ${ads} anuncios. GAM/latencia: ${imported} linhas.`;
        const type = errors.length ? 'warning' : ((ads > 0 || imported > 0) ? 'success' : 'warning');
        const fullMsg = errors.length ? `${msg} Avisos: ${errors.join(' | ')}` : msg;
        if (typeof showToast === 'function') showToast(fullMsg, type);
        setTimeout(() => window.location.reload(), 900);
    } catch (e) {
        if (typeof showToast === 'function') showToast(e.message, 'error');
        alert(e.message);
    } finally {
        buttons.forEach((btn) => {
            btn.disabled = false;
            btn.textContent = btn.dataset.originalText || 'Sync Criativos';
        });
    }
}

async function copyCreativeId(id) {
    if (!id) return;
    try {
        await navigator.clipboard.writeText(id);
    } catch (e) {
        const input = document.createElement('textarea');
        input.value = id;
        input.style.position = 'fixed';
        input.style.left = '-9999px';
        document.body.appendChild(input);
        input.focus();
        input.select();
        document.execCommand('copy');
        input.remove();
    }
    if (typeof showToast === 'function') showToast('ID do criativo copiado.', 'success');
}
</script>

<style>
.cl-note {
    margin: 0 0 14px;
    padding: 12px 14px;
    border: 1px solid rgba(255, 214, 10, 0.28);
    border-left: 3px solid #ffd60a;
    border-radius: 8px;
    background: rgba(255, 214, 10, 0.07);
    color: var(--text-primary);
    font-size: 12px;
    line-height: 1.5;
}
.cl-grid-2 {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 16px;
    margin-top: 16px;
}
.cl-import {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px;
    flex-wrap: wrap;
}
.cl-help {
    padding: 0 16px 16px;
    color: var(--text-muted);
    font-size: 11.5px;
    line-height: 1.55;
}
.cl-help code {
    background: var(--bg-input);
    border: 1px solid var(--border);
    padding: 2px 5px;
    border-radius: 4px;
    color: var(--text-primary);
}
.cl-table th,
.cl-table td,
.cl-small-table th,
.cl-small-table td {
    font-size: 11.5px;
    padding: 9px 8px;
    white-space: nowrap;
    vertical-align: middle;
}
.cl-table td:last-child {
    white-space: normal;
    min-width: 220px;
}
.cl-score {
    display: inline-flex;
    min-width: 34px;
    height: 26px;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    font-weight: 800;
    font-size: 12px;
}
.cl-score-good { color: #061b10; background: #30d158; }
.cl-score-mid { color: #201600; background: #ffd60a; }
.cl-score-bad { color: #fff; background: #ff453a; }
.cl-risk-high { background: rgba(255, 69, 58, 0.08); }
.cl-risk-medium { background: rgba(255, 214, 10, 0.06); }
.cl-flag {
    display: inline-flex;
    margin: 2px 4px 2px 0;
    padding: 4px 7px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 700;
}
.cl-flag-high { color: #ffd7d4; background: rgba(255, 69, 58, 0.18); border: 1px solid rgba(255, 69, 58, 0.28); }
.cl-flag-medium { color: #ffe89a; background: rgba(255, 214, 10, 0.14); border: 1px solid rgba(255, 214, 10, 0.24); }
.cl-flag-info { color: #cce8ff; background: rgba(100, 210, 255, 0.12); border: 1px solid rgba(100, 210, 255, 0.22); }
.cl-copy-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 24px;
    padding: 0 8px;
    margin-right: 7px;
    border-radius: 6px;
    border: 1px solid rgba(10, 132, 255, 0.4);
    background: rgba(10, 132, 255, 0.14);
    color: #9fd0ff;
    font-size: 10px;
    font-weight: 800;
    cursor: pointer;
}
.cl-id {
    display: inline-block;
    max-width: 96px;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: middle;
}
@media (max-width: 980px) {
    .cl-grid-2 { grid-template-columns: 1fr; }
}
</style>
