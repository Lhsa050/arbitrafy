<?php
/**
 * Análises — Visão completa por campanha (FB + Revenue)
 */

$cotacao = getCotacaoDolar();
ensureGA4Table();
$account = $_GET['account'] ?? '';
list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'date_desc';
$viewMode = $_GET['view_mode'] ?? 'diario';

$where = "WHERE fc.date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($account) {
    $where .= " AND fc.account_name = ?";
    $params[] = $account;
}
if ($search) {
    $where .= " AND (fc.campaign_name LIKE ? OR fc.campaign_id LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$fbWhere = str_replace('fc.', '', $where);

// Sort
$sortMap = [
    'date_desc' => 'fc.date DESC',
    'date_asc' => 'fc.date ASC',
    'name_asc' => 'campaign_name ASC',
    'name_desc' => 'campaign_name DESC',
    'custo_desc' => 'custo DESC',
    'custo_asc' => 'custo ASC',
    'imp_desc' => 'impressoes DESC',
    'imp_asc' => 'impressoes ASC',
    'clicks_desc' => 'clicks DESC',
    'clicks_asc' => 'clicks ASC',
    'cpc_desc' => 'cpc DESC',
    'cpc_asc' => 'cpc ASC',
    'ctr_desc' => 'ctr DESC',
    'ctr_asc' => 'ctr ASC',
    'conv_desc' => 'conversoes DESC',
    'conv_asc' => 'conversoes ASC',
    'cpm_desc' => 'cpm DESC',
    'cpm_asc' => 'cpm ASC',
    'cpr_desc' => 'custo_resultado DESC',
    'cpr_asc' => 'custo_resultado ASC',
    'vizlp_desc' => 'viz_lp DESC',
    'vizlp_asc' => 'viz_lp ASC',
    'cvlp_desc' => 'custo_viz_lp DESC',
    'cvlp_asc' => 'custo_viz_lp ASC',
    'rev_desc' => 'revenue DESC',
    'rev_asc' => 'revenue ASC',
    'roi_desc' => 'fc.date DESC',
    'roi_asc' => 'fc.date DESC',
    'lucro_desc' => 'fc.date DESC',
    'lucro_asc' => 'fc.date DESC',
    'rps_desc' => 'fc.date DESC',
    'rps_asc' => 'fc.date DESC',
    'rpc_desc' => 'fc.date DESC',
    'rpc_asc' => 'fc.date DESC',
    'spread_desc' => 'fc.date DESC',
    'spread_asc' => 'fc.date DESC',
    'cr_desc' => 'fc.date DESC',
    'cr_asc' => 'fc.date DESC',
];
$orderBy = $sortMap[$sort] ?? 'fc.date DESC';
if ($viewMode !== 'diario') {
    $orderBy = str_replace('fc.date', 'date', $orderBy);
}
$derivedSortMap = [
    'roi_desc' => ['roi', 'desc'],
    'roi_asc' => ['roi', 'asc'],
    'lucro_desc' => ['lucro', 'desc'],
    'lucro_asc' => ['lucro', 'asc'],
    'rps_desc' => ['rps', 'desc'],
    'rps_asc' => ['rps', 'asc'],
    'rpc_desc' => ['rpc', 'desc'],
    'rpc_asc' => ['rpc', 'asc'],
    'spread_desc' => ['spread', 'desc'],
    'spread_asc' => ['spread', 'asc'],
    'cr_desc' => ['connect_rate', 'desc'],
    'cr_asc' => ['connect_rate', 'asc'],
];

if ($viewMode === 'diario') {
    $rows = fetchAll("
        SELECT
            fc.date,
            fc.campaign_id,
            fc.campaign_name,
            fc.account_name,
            fc.custo,
            fc.impressoes,
            fc.clicks,
            fc.cpc,
            fc.ctr,
            fc.conversoes,
            fc.cpm,
            fc.custo_resultado,
            fc.viz_lp,
            fc.custo_viz_lp,
            COALESCE(rv.rev_usd, 0) * {$cotacao} as revenue,
            COALESCE(gs_id.sessions, gs_name.sessions, 0) as ga4_sessions
        FROM (
            SELECT date,
                   campaign_id,
                   MIN(campaign_name) as campaign_name,
                   MIN(account_name) as account_name,
                   SUM(investimento) as custo,
                   SUM(impressoes) as impressoes,
                   SUM(cliques) as clicks,
                   CASE WHEN SUM(cliques) > 0 THEN SUM(investimento) / SUM(cliques) ELSE 0 END as cpc,
                   CASE WHEN SUM(impressoes) > 0 THEN (SUM(cliques) * 100.0 / SUM(impressoes)) ELSE 0 END as ctr,
                   COALESCE(SUM(conv_pct), 0) as conversoes,
                   CASE WHEN SUM(impressoes) > 0 THEN (SUM(investimento) / SUM(impressoes)) * 1000 ELSE 0 END as cpm,
                   CASE WHEN COALESCE(SUM(conv_pct), 0) > 0 THEN SUM(investimento) / SUM(conv_pct) ELSE 0 END as custo_resultado,
                   COALESCE(SUM(viz_lp), 0) as viz_lp,
                   CASE WHEN COALESCE(SUM(viz_lp), 0) > 0 THEN SUM(investimento) / SUM(viz_lp) ELSE 0 END as custo_viz_lp
            FROM fb_campaigns
            {$fbWhere}
            GROUP BY date, campaign_id
        ) fc
        LEFT JOIN (
            SELECT date, campaign_id, SUM(receita_usd) as rev_usd
            FROM revenue
            GROUP BY date, campaign_id
        ) rv ON rv.campaign_id = fc.campaign_id AND rv.date = fc.date
        LEFT JOIN (
            SELECT date, campaign_id, SUM(sessions) as sessions
            FROM ga4_sessions
            GROUP BY date, campaign_id
        ) gs_id ON gs_id.campaign_id = fc.campaign_id AND gs_id.date = fc.date
        LEFT JOIN (
            SELECT date, campaign_id, SUM(sessions) as sessions
            FROM ga4_sessions
            GROUP BY date, campaign_id
        ) gs_name ON gs_name.campaign_id = fc.campaign_name AND gs_name.date = fc.date
        ORDER BY {$orderBy}
        LIMIT 1000
    ", $params);

    // Post-process derived metrics from revenue
    foreach ($rows as &$r) {
        $rev = (float)$r['revenue'];
        $custo = (float)$r['custo'];
        $sessions = (int)($r['ga4_sessions'] ?? 0);
        $r['roi'] = $custo > 0 ? (($rev - $custo) / $custo) * 100 : 0;
        $r['lucro'] = $rev - $custo;
        $r['rps'] = $sessions > 0 ? $rev / $sessions : 0;
        $r['rpc'] = (int)($r['clicks'] ?? 0) > 0 ? $rev / $r['clicks'] : 0;
        $r['custo_sessao'] = $sessions > 0 ? $custo / $sessions : 0;
        $r['spread'] = $r['rps'] - $r['custo_sessao'];
        $clicks = (int)($r['clicks'] ?? 0);
        $r['connect_rate_raw'] = $clicks > 0 ? ($sessions / $clicks) * 100 : 0;
        $r['connect_rate'] = min(100, $r['connect_rate_raw']);
    }
    unset($r);
    if (isset($derivedSortMap[$sort])) {
        [$derivedKey, $derivedDir] = $derivedSortMap[$sort];
        usort($rows, function($a, $b) use ($derivedKey, $derivedDir) {
            $cmp = ((float)($a[$derivedKey] ?? 0)) <=> ((float)($b[$derivedKey] ?? 0));
            if ($cmp === 0) {
                $cmp = strcmp((string)($a['campaign_name'] ?? ''), (string)($b['campaign_name'] ?? ''));
            }
            return $derivedDir === 'desc' ? -$cmp : $cmp;
        });
    }
} else {
    $rows = fetchAll("
        SELECT
            MIN(fc.date) as date,
            fc.campaign_id,
            MIN(fc.campaign_name) as campaign_name,
            MIN(fc.account_name) as account_name,
            SUM(fc.investimento) as custo,
            SUM(fc.impressoes) as impressoes,
            SUM(fc.cliques) as clicks,
            CASE WHEN SUM(fc.cliques) > 0 THEN SUM(fc.investimento) / SUM(fc.cliques) ELSE 0 END as cpc,
            CASE WHEN SUM(fc.impressoes) > 0 THEN (SUM(fc.cliques) * 100.0 / SUM(fc.impressoes)) ELSE 0 END as ctr,
            COALESCE(SUM(fc.conv_pct), 0) as conversoes,
            CASE WHEN SUM(fc.impressoes) > 0 THEN (SUM(fc.investimento) / SUM(fc.impressoes)) * 1000 ELSE 0 END as cpm,
            CASE WHEN COALESCE(SUM(fc.conv_pct), 0) > 0 THEN SUM(fc.investimento) / SUM(fc.conv_pct) ELSE 0 END as custo_resultado,
            COALESCE(SUM(fc.viz_lp), 0) as viz_lp,
            CASE WHEN COALESCE(SUM(fc.viz_lp), 0) > 0 THEN SUM(fc.investimento) / SUM(fc.viz_lp) ELSE 0 END as custo_viz_lp,
            COALESCE(SUM(rv.rev_usd), 0) * {$cotacao} as revenue,
            COALESCE(SUM(COALESCE(gs_id.sessions, gs_name.sessions, 0)), 0) as ga4_sessions
        FROM (
            SELECT date,
                   campaign_id,
                   MIN(campaign_name) as campaign_name,
                   MIN(account_name) as account_name,
                   SUM(investimento) as investimento,
                   SUM(impressoes) as impressoes,
                   SUM(cliques) as cliques,
                   COALESCE(SUM(conv_pct), 0) as conv_pct,
                   COALESCE(SUM(viz_lp), 0) as viz_lp
            FROM fb_campaigns
            {$fbWhere}
            GROUP BY date, campaign_id
        ) fc
        LEFT JOIN (
            SELECT date, campaign_id, SUM(receita_usd) as rev_usd
            FROM revenue
            GROUP BY date, campaign_id
        ) rv ON rv.campaign_id = fc.campaign_id AND rv.date = fc.date
        LEFT JOIN (
            SELECT date, campaign_id, SUM(sessions) as sessions
            FROM ga4_sessions
            GROUP BY date, campaign_id
        ) gs_id ON gs_id.campaign_id = fc.campaign_id AND gs_id.date = fc.date
        LEFT JOIN (
            SELECT date, campaign_id, SUM(sessions) as sessions
            FROM ga4_sessions
            GROUP BY date, campaign_id
        ) gs_name ON gs_name.campaign_id = fc.campaign_name AND gs_name.date = fc.date
        GROUP BY fc.campaign_id
        ORDER BY {$orderBy}
        LIMIT 500
    ", $params);

    // Post-process derived metrics from revenue
    foreach ($rows as &$r) {
        $rev = (float)$r['revenue'];
        $custo = (float)$r['custo'];
        $sessions = (int)($r['ga4_sessions'] ?? 0);
        $r['roi'] = $custo > 0 ? (($rev - $custo) / $custo) * 100 : 0;
        $r['lucro'] = $rev - $custo;
        $r['rps'] = $sessions > 0 ? $rev / $sessions : 0;
        $r['rpc'] = (int)($r['clicks'] ?? 0) > 0 ? $rev / $r['clicks'] : 0;
        $r['custo_sessao'] = $sessions > 0 ? $custo / $sessions : 0;
        $r['spread'] = $r['rps'] - $r['custo_sessao'];
        $clicks = (int)($r['clicks'] ?? 0);
        $r['connect_rate_raw'] = $clicks > 0 ? ($sessions / $clicks) * 100 : 0;
        $r['connect_rate'] = min(100, $r['connect_rate_raw']);
    }
    unset($r);
    if (isset($derivedSortMap[$sort])) {
        [$derivedKey, $derivedDir] = $derivedSortMap[$sort];
        usort($rows, function($a, $b) use ($derivedKey, $derivedDir) {
            $cmp = ((float)($a[$derivedKey] ?? 0)) <=> ((float)($b[$derivedKey] ?? 0));
            if ($cmp === 0) {
                $cmp = strcmp((string)($a['campaign_name'] ?? ''), (string)($b['campaign_name'] ?? ''));
            }
            return $derivedDir === 'desc' ? -$cmp : $cmp;
        });
    }
}

// Totals
$totals = fetchOne("
    SELECT
        COALESCE(SUM(fc.investimento), 0) as total_custo,
        COALESCE(SUM(fc.impressoes), 0) as total_imp,
        COALESCE(SUM(fc.cliques), 0) as total_clicks,
        COALESCE(SUM(fc.conv_pct), 0) as total_conv,
        COUNT(DISTINCT fc.campaign_id) as total_campaigns
    FROM fb_campaigns fc
    {$where}
", $params);

// Total revenue from GAM — must respect campaign filter
$revWhere = "WHERE r.date BETWEEN ? AND ?";
$revParams = [$dateFrom, $dateTo];
if ($search) {
    // Only count revenue for campaigns matching the search filter
    $revWhere .= " AND r.campaign_id IN (SELECT DISTINCT campaign_id FROM fb_campaigns WHERE campaign_name LIKE ? OR campaign_id LIKE ?)";
    $revParams[] = "%{$search}%";
    $revParams[] = "%{$search}%";
}
if ($account) {
    $revWhere .= " AND r.campaign_id IN (SELECT DISTINCT campaign_id FROM fb_campaigns WHERE account_name = ?)";
    $revParams[] = $account;
}
$totalRevRow = fetchOne("
    SELECT COALESCE(SUM(receita_usd), 0) * {$cotacao} as total_revenue
    FROM revenue r
    {$revWhere}
", $revParams);
$totals['total_revenue'] = $totalRevRow['total_revenue'] ?? 0;

$totalLucro = $totals['total_revenue'] - $totals['total_custo'];
$totalROI = $totals['total_custo'] > 0 ? (($totals['total_revenue'] - $totals['total_custo']) / $totals['total_custo']) * 100 : 0;

$accounts = fetchAll("SELECT DISTINCT account_name FROM fb_campaigns ORDER BY account_name");

function anaUrl($overrides = []) {
    $params = array_merge($_GET, ['page' => 'analytics'], $overrides);
    return '?' . http_build_query($params);
}
function anaViewUrl($mode) {
    $params = array_merge($_GET, ['page' => 'analytics', 'view_mode' => $mode]);
    return '?' . http_build_query($params);
}
function anaSortArrow($col, $current) {
    if ($current === $col . '_desc') return '↓';
    if ($current === $col . '_asc') return '↑';
    return '↕';
}
function anaSortToggle($col, $current) {
    return $current === $col . '_desc' ? $col . '_asc' : $col . '_desc';
}
?>

<!-- Date Filter -->
<?php
$currentPage = 'analytics';
ob_start();
?>
<select class="filter-input" style="max-width:180px;min-height:34px;padding:6px 10px;font-size:12px;" onchange="var p=new URLSearchParams(location.search);p.set('account',this.value);location.href='?'+p.toString()">
    <option value="">Todas as Contas</option>
    <?php foreach ($accounts as $acc): ?>
    <option value="<?= $acc['account_name'] ?>" <?= $account === $acc['account_name'] ? 'selected' : '' ?>><?= $acc['account_name'] ?></option>
    <?php endforeach; ?>
</select>
<input type="text" class="filter-input" style="max-width:180px;min-height:34px;padding:6px 10px;font-size:12px;" placeholder="🔍 Buscar campanha..." value="<?= sanitize($search) ?>"
       onkeydown="if(event.key==='Enter'){var p=new URLSearchParams(location.search);p.set('search',this.value);location.href='?'+p.toString();}">
<div class="view-mode-toggle">
    <a href="<?= anaViewUrl('agrupado') ?>" class="view-mode-btn <?= $viewMode === 'agrupado' ? 'active' : '' ?>" title="Dados agrupados por campanha">
        <span class="view-mode-icon">📊</span> Agrupado
    </a>
    <a href="<?= anaViewUrl('diario') ?>" class="view-mode-btn <?= $viewMode === 'diario' ? 'active' : '' ?>" title="Dados dia a dia">
        <span class="view-mode-icon">📅</span> Diário
    </a>
</div>
<button class="btn-export" onclick="exportTableToCSV('analyticsTable', 'analise_<?= $viewMode ?>_<?= $dateFrom ?>_<?= $dateTo ?>')" title="Exportar dados filtrados para CSV">
    📥 Exportar CSV
</button>
<?php $dateFilterExtra = ob_get_clean(); ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<!-- Summary Cards -->
<div class="cards-grid">
    <div class="card card-blue">
        <div class="card-label">Investimento Total</div>
        <div class="card-value"><?= formatMoney($totals['total_custo']) ?></div>
        <div class="card-change"><?= $totals['total_campaigns'] ?> campanhas</div>
    </div>
    <div class="card card-green">
        <div class="card-label">Revenue Total</div>
        <div class="card-value"><?= formatMoney($totals['total_revenue']) ?></div>
    </div>
    <div class="card <?= $totalLucro >= 0 ? 'card-green' : 'card-red' ?>">
        <div class="card-label">Lucro Total</div>
        <div class="card-value <?= $totalLucro >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($totalLucro) ?></div>
    </div>
    <div class="card <?= $totalROI >= 0 ? 'card-purple' : 'card-red' ?>">
        <div class="card-label">ROI Geral</div>
        <div class="card-value <?= $totalROI >= 0 ? 'positive' : 'negative' ?>"><?= formatNumber($totalROI, 1) ?>%</div>
    </div>
</div>

<!-- Heatmap Table -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">🔬 Análise Completa — <?= $viewMode === 'diario' ? 'Visão Diária' : 'Agrupado por Campanha' ?></span>
        <div class="table-actions">
            <span class="badge badge-blue"><?= count($rows) ?> <?= $viewMode === 'diario' ? 'registros' : 'campanhas' ?></span>
            <label class="heatmap-toggle" title="Ativar/desativar mapa de calor">
                <input type="checkbox" id="heatmapToggle" checked> 🌡️ Heatmap
            </label>
            <button class="btn-export" onclick="exportTableToCSV('analyticsTable', 'analise_<?= $viewMode ?>_<?= $dateFrom ?>_<?= $dateTo ?>')" title="Exportar dados filtrados para CSV">
                📥 Exportar CSV
            </button>
        </div>
    </div>
    <div class="table-scroll">
        <table id="analyticsTable">
            <thead>
                <tr>
                    <?php if ($viewMode === 'diario'): ?>
                    <th><a href="<?= anaUrl(['sort' => anaSortToggle('date', $sort)]) ?>" class="sort-link">Data <?= anaSortArrow('date', $sort) ?></a></th>
                    <?php endif; ?>
                    <th><a href="<?= anaUrl(['sort' => anaSortToggle('name', $sort)]) ?>" class="sort-link">Campanha <?= anaSortArrow('name', $sort) ?></a></th>
                    <th class="hm-col hm-lower"><a href="<?= anaUrl(['sort' => anaSortToggle('custo', $sort)]) ?>" class="sort-link">Custo <?= anaSortArrow('custo', $sort) ?></a><span class="th-help" title="Valor investido no Facebook Ads">?</span></th>
                    <th class="hm-col"><a href="<?= anaUrl(['sort' => anaSortToggle('imp', $sort)]) ?>" class="sort-link">Impressões <?= anaSortArrow('imp', $sort) ?></a><span class="th-help" title="Quantidade de vezes que o anúncio foi exibido (Facebook Ads)">?</span></th>
                    <th class="hm-col"><a href="<?= anaUrl(['sort' => anaSortToggle('clicks', $sort)]) ?>" class="sort-link">Clicks <?= anaSortArrow('clicks', $sort) ?></a><span class="th-help" title="Cliques no link do anúncio (Facebook Ads)">?</span></th>
                    <th class="hm-col hm-lower"><a href="<?= anaUrl(['sort' => anaSortToggle('cpc', $sort)]) ?>" class="sort-link">CPC <?= anaSortArrow('cpc', $sort) ?></a><span class="th-help" title="Custo por Clique = Custo ÷ Cliques (Facebook Ads)">?</span></th>
                    <th class="hm-col hm-higher"><a href="<?= anaUrl(['sort' => anaSortToggle('vizlp', $sort)]) ?>" class="sort-link">Viz. LP <?= anaSortArrow('vizlp', $sort) ?></a><span class="th-help" title="Visualizações de Landing Page — usuários que clicaram e carregaram a página (Facebook Ads)">?</span></th>
                    <th class="hm-col hm-lower"><a href="<?= anaUrl(['sort' => anaSortToggle('cvlp', $sort)]) ?>" class="sort-link">Custo Viz. LP <?= anaSortArrow('cvlp', $sort) ?></a><span class="th-help" title="Custo por Visualização de LP = Custo ÷ Viz. LP (Facebook Ads)">?</span></th>
                    <th class="hm-col hm-higher"><a href="<?= anaUrl(['sort' => anaSortToggle('ctr', $sort)]) ?>" class="sort-link">CTR <?= anaSortArrow('ctr', $sort) ?></a><span class="th-help" title="Click-Through Rate = Cliques ÷ Impressões × 100 (Facebook Ads)">?</span></th>
                    <th class="hm-col hm-higher"><a href="<?= anaUrl(['sort' => anaSortToggle('conv', $sort)]) ?>" class="sort-link">Resultados <?= anaSortArrow('conv', $sort) ?></a><span class="th-help" title="Conversões/resultados da campanha (Facebook Ads)">?</span></th>
                    <th class="hm-col hm-lower"><a href="<?= anaUrl(['sort' => anaSortToggle('cpm', $sort)]) ?>" class="sort-link">CPM <?= anaSortArrow('cpm', $sort) ?></a><span class="th-help" title="Custo por Mil Impressões = (Custo ÷ Impressões) × 1000 (Facebook Ads)">?</span></th>
                    <th class="hm-col hm-lower"><a href="<?= anaUrl(['sort' => anaSortToggle('cpr', $sort)]) ?>" class="sort-link">Custo/Result. <?= anaSortArrow('cpr', $sort) ?></a><span class="th-help" title="Custo por Resultado = Custo ÷ Resultados (Facebook Ads)">?</span></th>
                    <th class="ana-divider hm-col hm-higher"><a href="<?= anaUrl(['sort' => anaSortToggle('rev', $sort)]) ?>" class="sort-link">Revenue <?= anaSortArrow('rev', $sort) ?></a><span class="th-help" title="Receita em BRL gerada pela campanha no Google Ad Manager (GAM)">?</span></th>
                    <th class="hm-col hm-higher"><a href="<?= anaUrl(['sort' => anaSortToggle('roi', $sort)]) ?>" class="sort-link">ROI <?= anaSortArrow('roi', $sort) ?></a><span class="th-help" title="Retorno sobre Investimento = ((Revenue - Custo) ÷ Custo) × 100 (GAM + FB)">?</span></th>
                    <th class="hm-col hm-higher"><a href="<?= anaUrl(['sort' => anaSortToggle('lucro', $sort)]) ?>" class="sort-link">Lucro <?= anaSortArrow('lucro', $sort) ?></a><span class="th-help" title="Lucro = Revenue (GAM) - Custo (Facebook Ads)">?</span></th>
                    <th class="hm-col hm-higher"><a href="<?= anaUrl(['sort' => anaSortToggle('rps', $sort)]) ?>" class="sort-link">RPS <?= anaSortArrow('rps', $sort) ?></a><span class="th-help" title="Revenue Per Session = Revenue (GAM) ÷ Sessões (GA4)">?</span></th>
                    <th class="hm-col">Sessões GA4<span class="th-help" title="Sessões do Google Analytics 4, filtradas por utm_source=facebook">?</span></th>
                    <th class="hm-col hm-higher"><a href="<?= anaUrl(['sort' => anaSortToggle('cr', $sort)]) ?>" class="sort-link">Connect Rate <?= anaSortArrow('cr', $sort) ?></a><span class="th-help" title="Connect Rate = Sessões (GA4) ÷ Cliques (FB) × 100. Indica % de cliques que realmente carregaram o site">?</span></th>
                    <th class="hm-col hm-lower">Custo/Sessão<span class="th-help" title="Custo por Sessão = Custo (Facebook) ÷ Sessões (GA4)">?</span></th>
                    <th class="hm-col hm-higher"><a href="<?= anaUrl(['sort' => anaSortToggle('spread', $sort)]) ?>" class="sort-link">SPREAD <?= anaSortArrow('spread', $sort) ?></a><span class="th-help" title="SPREAD = RPS - Custo/Sessão. Positivo = lucrando por sessão">?</span></th>
                    <th class="hm-col hm-higher"><a href="<?= anaUrl(['sort' => anaSortToggle('rpc', $sort)]) ?>" class="sort-link">RPC <?= anaSortArrow('rpc', $sort) ?></a><span class="th-help" title="Revenue Per Click = Revenue (GAM) ÷ Cliques (Facebook Ads)">?</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="<?= $viewMode === 'diario' ? '22' : '21' ?>" class="empty-state" style="padding:40px;">
                    <div class="empty-state-icon">🔬</div>
                    <div class="empty-state-title">Nenhum dado encontrado</div>
                    <div class="empty-state-text">Ajuste o período ou sincronize suas campanhas</div>
                </td></tr>
                <?php else: ?>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <?php if ($viewMode === 'diario'): ?>
                    <td><span class="badge badge-purple"><?= date('d/m/Y', strtotime($r['date'])) ?></span></td>
                    <?php endif; ?>
                    <td class="ana-campaign-name" title="<?= sanitize($r['campaign_name']) ?>&#10;ID: <?= $r['campaign_id'] ?>"><?= sanitize(mb_substr($r['campaign_name'] ?? '', 0, 35)) ?></td>
                    <td class="hm-cell" data-val="<?= (float)$r['custo'] ?>"><?= formatMoney($r['custo']) ?></td>
                    <td class="hm-cell" data-val="<?= (int)$r['impressoes'] ?>"><?= formatNumber($r['impressoes']) ?></td>
                    <td class="hm-cell" data-val="<?= (int)$r['clicks'] ?>"><?= formatNumber($r['clicks']) ?></td>
                    <td class="hm-cell" data-val="<?= (float)$r['cpc'] ?>"><?= formatMoney($r['cpc']) ?></td>
                    <td class="hm-cell" data-val="<?= (int)$r['viz_lp'] ?>"><?= formatNumber($r['viz_lp']) ?></td>
                    <td class="hm-cell" data-val="<?= (float)$r['custo_viz_lp'] ?>"><?= $r['custo_viz_lp'] > 0 ? formatMoney($r['custo_viz_lp']) : '-' ?></td>
                    <td class="hm-cell" data-val="<?= (float)$r['ctr'] ?>"><?= formatNumber($r['ctr'], 2) ?>%</td>
                    <td class="hm-cell" data-val="<?= (int)$r['conversoes'] ?>"><?= formatNumber($r['conversoes']) ?></td>
                    <td class="hm-cell" data-val="<?= (float)$r['cpm'] ?>"><?= formatMoney($r['cpm']) ?></td>
                    <td class="hm-cell" data-val="<?= (float)$r['custo_resultado'] ?>"><?= $r['custo_resultado'] > 0 ? formatMoney($r['custo_resultado']) : '-' ?></td>
                    <td class="hm-cell ana-divider" data-val="<?= (float)$r['revenue'] ?>"><?= formatMoney($r['revenue']) ?></td>
                    <td class="hm-cell <?= $r['roi'] >= 0 ? 'positive' : 'negative' ?>" data-val="<?= (float)$r['roi'] ?>"><?= formatNumber($r['roi'], 1) ?>%</td>
                    <td class="hm-cell <?= $r['lucro'] >= 0 ? 'positive' : 'negative' ?>" data-val="<?= (float)$r['lucro'] ?>"><?= formatMoney($r['lucro']) ?></td>
                    <td class="hm-cell" data-val="<?= (float)$r['rps'] ?>"><?= $r['rps'] > 0 ? formatMoney($r['rps']) : '-' ?></td>
                    <td class="hm-cell" data-val="<?= (int)($r['ga4_sessions'] ?? 0) ?>"><?= (int)($r['ga4_sessions'] ?? 0) > 0 ? formatNumber($r['ga4_sessions']) : '-' ?></td>
                    <td class="hm-cell <?= ($r['connect_rate'] ?? 0) >= 70 ? 'positive' : (($r['connect_rate'] ?? 0) > 0 && ($r['connect_rate'] ?? 0) < 60 ? 'negative' : '') ?>" data-val="<?= (float)($r['connect_rate'] ?? 0) ?>" title="<?= ($r['connect_rate_raw'] ?? 0) > 100 ? 'GA4 maior que cliques. Verifique UTMs/atribuição.' : '' ?>"><?= ($r['connect_rate'] ?? 0) > 0 ? formatNumber($r['connect_rate'], 1) . '%' : '-' ?></td>
                    <td class="hm-cell" data-val="<?= (float)($r['custo_sessao'] ?? 0) ?>"><?= ($r['custo_sessao'] ?? 0) > 0 ? formatMoney($r['custo_sessao']) : '-' ?></td>
                    <td class="hm-cell <?= ($r['spread'] ?? 0) >= 0 ? 'positive' : 'negative' ?>" data-val="<?= (float)($r['spread'] ?? 0) ?>"><?= ($r['ga4_sessions'] ?? 0) > 0 ? formatMoney($r['spread']) : '-' ?></td>
                    <td class="hm-cell" data-val="<?= (float)$r['rpc'] ?>"><?= $r['rpc'] > 0 ? formatMoney($r['rpc']) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Heatmap JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('analyticsTable');
    if (!table) return;

    const thead = table.querySelector('thead tr');
    const headers = Array.from(thead.querySelectorAll('th'));
    const tbodyRows = Array.from(table.querySelectorAll('tbody tr'));
    if (tbodyRows.length === 0) return;

    // Detect which columns have heatmap and their direction
    const colConfig = [];
    headers.forEach((th, i) => {
        if (th.classList.contains('hm-col')) {
            let direction = 'neutral';
            if (th.classList.contains('hm-higher')) direction = 'higher';
            if (th.classList.contains('hm-lower')) direction = 'lower';
            colConfig.push({ index: i, direction });
        }
    });

    function applyHeatmap() {
        colConfig.forEach(cfg => {
            const vals = [];
            tbodyRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells[cfg.index]) {
                    const v = parseFloat(cells[cfg.index].getAttribute('data-val'));
                    if (!isNaN(v)) vals.push(v);
                }
            });
            if (vals.length === 0) return;

            const mn = Math.min(...vals);
            const mx = Math.max(...vals);
            const range = mx - mn;
            if (range === 0) return;

            tbodyRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const cell = cells[cfg.index];
                if (!cell) return;
                const v = parseFloat(cell.getAttribute('data-val'));
                if (isNaN(v)) return;

                let pct = (v - mn) / range; // 0..1

                let r, g, b;
                if (cfg.direction === 'higher') {
                    // low=red, mid=yellow, high=green
                    if (pct < 0.5) {
                        const t = pct * 2;
                        r = 220; g = Math.round(60 + 160 * t); b = 60;
                    } else {
                        const t = (pct - 0.5) * 2;
                        r = Math.round(220 - 180 * t); g = 200; b = 60;
                    }
                } else if (cfg.direction === 'lower') {
                    // low=green, mid=yellow, high=red (inverted)
                    pct = 1 - pct;
                    if (pct < 0.5) {
                        const t = pct * 2;
                        r = 220; g = Math.round(60 + 160 * t); b = 60;
                    } else {
                        const t = (pct - 0.5) * 2;
                        r = Math.round(220 - 180 * t); g = 200; b = 60;
                    }
                } else {
                    // Neutral: intensity only (blue)
                    r = 40; g = 80; b = Math.round(120 + 120 * pct);
                }

                cell.style.backgroundColor = `rgba(${r}, ${g}, ${b}, 0.18)`;
            });
        });
    }

    function clearHeatmap() {
        tbodyRows.forEach(row => {
            row.querySelectorAll('.hm-cell').forEach(cell => {
                cell.style.backgroundColor = '';
            });
        });
    }

    const toggle = document.getElementById('heatmapToggle');
    applyHeatmap();
    toggle.addEventListener('change', function() {
        if (this.checked) applyHeatmap();
        else clearHeatmap();
    });
});
</script>

<style>
.sort-link { color: var(--text-primary); text-decoration: none; white-space: nowrap; transition: color 0.2s; }
.sort-link:hover { color: var(--primary); }

/* View Mode Toggle */
.view-mode-toggle {
    display: inline-flex;
    background: var(--card-bg, #1a1d23);
    border: 1px solid var(--border, rgba(255,255,255,0.08));
    border-radius: 10px;
    overflow: hidden;
}
.view-mode-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 14px; font-size: 12px; font-weight: 500;
    color: var(--text-muted, #888); text-decoration: none;
    transition: all 0.25s ease; cursor: pointer;
    border: none; background: transparent; white-space: nowrap;
}
.view-mode-btn:hover { color: var(--text-primary, #fff); background: rgba(255,255,255,0.04); }
.view-mode-btn.active { color: #fff; background: var(--primary, #6366f1); box-shadow: 0 2px 8px rgba(99,102,241,0.35); }
.view-mode-icon { font-size: 13px; }
.badge-purple { background: rgba(168,85,247,0.15); color: #c084fc; }

/* Analytics table tweaks */
#analyticsTable th, #analyticsTable td { font-size: 11.5px; padding: 8px 6px; white-space: nowrap; }
.ana-campaign-name { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ana-divider { border-left: 2px solid rgba(99,102,241,0.4) !important; }

/* Heatmap toggle */
.heatmap-toggle {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; color: var(--text-muted); cursor: pointer;
    padding: 4px 10px; border-radius: 6px;
    background: rgba(255,255,255,0.04); border: 1px solid var(--border, rgba(255,255,255,0.08));
    transition: all 0.2s;
}
.heatmap-toggle:hover { color: var(--text-primary); background: rgba(255,255,255,0.08); }
.heatmap-toggle input { accent-color: var(--primary, #6366f1); cursor: pointer; }

/* Heatmap cells */
.hm-cell { transition: background-color 0.3s ease; border-radius: 0; }

/* Tooltip help icon */
.th-help {
    display: inline-flex; align-items: center; justify-content: center;
    width: 14px; height: 14px; margin-left: 3px;
    font-size: 9px; font-weight: 700; font-style: normal;
    color: var(--text-muted, #666); background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.1); border-radius: 50%;
    cursor: help; vertical-align: middle; position: relative;
    opacity: 0.5; transition: opacity 0.2s;
}
.th-help:hover {
    opacity: 1; color: var(--primary, #6366f1);
    border-color: var(--primary, #6366f1); background: rgba(99,102,241,0.1);
}
</style>
