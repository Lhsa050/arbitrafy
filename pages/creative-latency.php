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
    if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
        getDB()->exec("CREATE TABLE IF NOT EXISTS creative_latency_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            advertiser VARCHAR(255) DEFAULT '',
            demand_partner VARCHAR(255) DEFAULT '',
            creative_id VARCHAR(100) DEFAULT '',
            creative_name VARCHAR(255) DEFAULT '',
            source VARCHAR(100) DEFAULT 'csv',
            impressions INT DEFAULT 0,
            ad_requests INT DEFAULT 0,
            rendered INT DEFAULT 0,
            empty_count INT DEFAULT 0,
            heavy_events INT DEFAULT 0,
            avg_render_ms DECIMAL(12,2) DEFAULT 0,
            p95_render_ms DECIMAL(12,2) DEFAULT 0,
            avg_load_ms DECIMAL(12,2) DEFAULT 0,
            p95_load_ms DECIMAL(12,2) DEFAULT 0,
            creative_size_kb DECIMAL(12,2) DEFAULT 0,
            viewability_pct DECIMAL(8,4) DEFAULT 0,
            ctr DECIMAL(8,4) DEFAULT 0,
            revenue_usd DECIMAL(12,6) DEFAULT 0,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } else {
        getDB()->exec("CREATE TABLE IF NOT EXISTS creative_latency_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date DATE NOT NULL,
            advertiser VARCHAR(255) DEFAULT '',
            demand_partner VARCHAR(255) DEFAULT '',
            creative_id VARCHAR(100) DEFAULT '',
            creative_name VARCHAR(255) DEFAULT '',
            source VARCHAR(100) DEFAULT 'csv',
            impressions INTEGER DEFAULT 0,
            ad_requests INTEGER DEFAULT 0,
            rendered INTEGER DEFAULT 0,
            empty_count INTEGER DEFAULT 0,
            heavy_events INTEGER DEFAULT 0,
            avg_render_ms DECIMAL(12,2) DEFAULT 0,
            p95_render_ms DECIMAL(12,2) DEFAULT 0,
            avg_load_ms DECIMAL(12,2) DEFAULT 0,
            p95_load_ms DECIMAL(12,2) DEFAULT 0,
            creative_size_kb DECIMAL(12,2) DEFAULT 0,
            viewability_pct DECIMAL(8,4) DEFAULT 0,
            ctr DECIMAL(8,4) DEFAULT 0,
            revenue_usd DECIMAL(12,6) DEFAULT 0,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
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

            insert('creative_latency_audit', [
                'date' => $date,
                'advertiser' => trim(cl_pick($row, ['advertiser', 'anunciante', 'buyer', 'comprador'], '')),
                'demand_partner' => trim(cl_pick($row, ['demand_partner', 'demand', 'partner', 'demand_channel', 'ad_exchange', 'exchange', 'ssp', 'yield_partner'], '')),
                'creative_id' => trim(cl_pick($row, ['creative_id', 'creativeid', 'id_criativo', 'creative'], '')),
                'creative_name' => trim(cl_pick($row, ['creative_name', 'nome_criativo', 'creative_label', 'ad_name'], '')),
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
           CASE WHEN SUM(impressions) > 0 THEN SUM(avg_render_ms * impressions) / SUM(impressions) ELSE AVG(avg_render_ms) END as avg_render_ms,
           MAX(p95_render_ms) as p95_render_ms,
           CASE WHEN SUM(impressions) > 0 THEN SUM(avg_load_ms * impressions) / SUM(impressions) ELSE AVG(avg_load_ms) END as avg_load_ms,
           MAX(p95_load_ms) as p95_load_ms,
           MAX(creative_size_kb) as creative_size_kb,
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

$sourceRows = fetchAll("SELECT DISTINCT source FROM creative_latency_audit WHERE source != '' ORDER BY source");

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
<?php $dateFilterExtra = ob_get_clean(); ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<?php if ($message): ?><div class="alert alert-success"><?= sanitize($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= sanitize($error) ?></div><?php endif; ?>

<div class="cl-note">
    <strong>Auditoria de criativos pesados e latencia.</strong>
    O Google Ad Manager recomenda minimizar latencia e evitar criativos pesados. Esta aba nao altera tags, SpunMidia, GPT, preloader ou anuncios; ela cruza dados locais e aceita CSV de relatorios para analisar anunciante, demand partner e criativo.
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
    <div class="card card-blue">
        <div class="card-label">Impressoes importadas</div>
        <div class="card-value"><?= formatNumber($manualImpressions) ?></div>
        <div class="card-change"><?= formatNumber($totalImportedRows) ?> linhas historicas</div>
    </div>
    <div class="card card-green">
        <div class="card-label">Revenue importado</div>
        <div class="card-value"><?= formatMoney($manualRevenue, 'USD') ?></div>
        <div class="card-change">CSV/relatorios externos</div>
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
            Colunas aceitas: <code>date</code>, <code>advertiser</code>, <code>demand_partner</code>, <code>creative_id</code>, <code>creative_name</code>, <code>impressions</code>, <code>ad_requests</code>, <code>avg_render_ms</code>, <code>p95_render_ms</code>, <code>avg_load_ms</code>, <code>creative_size_kb</code>, <code>viewability_pct</code>, <code>heavy_events</code>, <code>revenue_usd</code>.
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
                    <tr><td>Latencia real por criativo</td><td>-</td><td>Precisa CSV/GAM/Spun/GTM leve</td></tr>
                </tbody>
            </table>
        </div>
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
                    <th>Imp.</th><th>Requests</th><th>Fill</th><th>Heavy</th><th>Peso max</th>
                    <th>Render medio</th><th>P95 render</th><th>Load medio</th><th>Viewability</th><th>Revenue</th><th>Alertas</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($partnerRows)): ?>
                <tr><td colspan="16" class="empty-state" style="padding:38px;">Ainda nao ha dados por anunciante/demand partner. Importe um CSV exportado do GAM, Spun, AdX ou monitoramento leve.</td></tr>
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
                    <td><?= $r['avg_render_ms'] > 0 ? formatNumber($r['avg_render_ms'], 0) . ' ms' : '-' ?></td>
                    <td><?= $r['p95_render_ms'] > 0 ? formatNumber($r['p95_render_ms'], 0) . ' ms' : '-' ?></td>
                    <td><?= $r['avg_load_ms'] > 0 ? formatNumber($r['avg_load_ms'], 0) . ' ms' : '-' ?></td>
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
                    <th>Imp.</th><th>Peso</th><th>Render medio</th><th>P95 render</th><th>Load medio</th><th>Viewability</th><th>Heavy</th><th>Revenue</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($creativeRows)): ?>
                <tr><td colspan="13" class="empty-state" style="padding:32px;">Sem criativos importados ainda.</td></tr>
            <?php else: foreach ($creativeRows as $r): ?>
                <tr class="cl-risk-<?= sanitize($r['risk_level']) ?>">
                    <td><span class="badge <?= cl_badge($r['risk_level']) ?>"><?= cl_label($r['risk_level']) ?></span></td>
                    <td><span class="cl-score <?= $r['risk_score'] >= 40 ? 'cl-score-bad' : ($r['risk_score'] >= 20 ? 'cl-score-mid' : 'cl-score-good') ?>"><?= (int)$r['risk_score'] ?></span></td>
                    <td title="<?= sanitize($r['creative_id']) ?>"><?= sanitize($r['creative_name'] ?: ($r['creative_id'] ?: 'Sem criativo')) ?></td>
                    <td><?= sanitize($r['advertiser'] ?: '-') ?></td>
                    <td><?= sanitize($r['demand_partner'] ?: '-') ?></td>
                    <td><?= formatNumber($r['impressions']) ?></td>
                    <td><?= $r['creative_size_kb'] > 0 ? formatNumber($r['creative_size_kb'], 0) . ' KB' : '-' ?></td>
                    <td><?= $r['avg_render_ms'] > 0 ? formatNumber($r['avg_render_ms'], 0) . ' ms' : '-' ?></td>
                    <td><?= $r['p95_render_ms'] > 0 ? formatNumber($r['p95_render_ms'], 0) . ' ms' : '-' ?></td>
                    <td><?= $r['avg_load_ms'] > 0 ? formatNumber($r['avg_load_ms'], 0) . ' ms' : '-' ?></td>
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
                <tr><td>Criativo pesado</td><td><span class="badge badge-yellow">Precisa dado externo</span></td><td>Importar tamanho do criativo via relatorio do ad server/Spun ou auditoria de rede; nao inspecionar iframe de anuncio.</td></tr>
                <tr><td>Render/load por demand partner</td><td><span class="badge badge-yellow">Precisa CSV ou evento leve</span></td><td>Preferir relatorio GAM/Spun. Se usar GTM, apenas medir timestamps de eventos, async, sem bloquear renderizacao.</td></tr>
                <tr><td>ad_slot_requested/rendered/empty</td><td><span class="badge badge-red">Nao instalado no site</span></td><td>Implementar somente apos confirmacao; usar dataLayer/GA4, sem alterar GPT, Spun, preloader ou layout.</td></tr>
                <tr><td>visible_ad_detected</td><td><span class="badge badge-yellow">Cuidado</span></td><td>Medir apenas visibilidade de containers proprios, sem acessar conteudo cross-origin do iframe.</td></tr>
                <tr><td>Politicas Google</td><td><span class="badge badge-green">Respeitado</span></td><td>Sem fingerprint agressivo, sem PII e sem manipular leilao/anuncio.</td></tr>
            </tbody>
        </table>
    </div>
</div>

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
@media (max-width: 980px) {
    .cl-grid-2 { grid-template-columns: 1fr; }
}
</style>
