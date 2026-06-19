<?php
/**
 * Dashboard - Real data from FB Campaigns + GAM Revenue
 * Comprehensive filters: date range, account, campaign, sort
 */

$cotacao = getCotacaoDolar();
$taxaFb = (float)getSetting('imposto_facebook', '0') / 100;
$taxaOutros = (float)getSetting('imposto_outros', '0') / 100;

// --- FILTERS ---
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$monthName = getMonthName($month);
$dashAccount = $_GET['account'] ?? '';
list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();
$searchCampaign = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'date_desc';
$viewMode = $_GET['view'] ?? 'daily'; // daily or campaign
$dashSite = $_GET['site'] ?? ''; // site GAM filter

// Build date range
$monthStart = $dateFrom;
$monthEnd = $dateTo;

// --- FB investment by day ---
$fbWhere = "WHERE date BETWEEN ? AND ?";
$fbParams = [$monthStart, $monthEnd];
if ($dashAccount) {
    $fbWhere .= " AND account_name = ?";
    $fbParams[] = $dashAccount;
}
if ($searchCampaign) {
    $fbWhere .= " AND (campaign_name LIKE ? OR campaign_id LIKE ?)";
    $fbParams[] = "%{$searchCampaign}%";
    $fbParams[] = "%{$searchCampaign}%";
}

$fbDaily = fetchAll("
    SELECT date, 
           SUM(investimento) as investimento,
           SUM(cliques) as cliques,
           SUM(impressoes) as impressoes,
           COUNT(DISTINCT campaign_id) as campaigns
    FROM fb_campaigns {$fbWhere}
    GROUP BY date ORDER BY date
", $fbParams);
$fbByDate = [];
foreach ($fbDaily as $d) {
    $fbByDate[$d['date']] = $d;
}

$revWhere = "WHERE date BETWEEN ? AND ?";
$revParams = [$monthStart, $monthEnd];
if ($dashSite) {
    $revWhere .= " AND site_name = ?";
    $revParams[] = $dashSite;
}
if ($searchCampaign) {
    $revWhere .= " AND (campaign_id LIKE ? OR utm_campaign LIKE ?)";
    $revParams[] = "%{$searchCampaign}%";
    $revParams[] = "%{$searchCampaign}%";
}

$revDaily = fetchAll("
    SELECT date, SUM(receita_usd) as receita_usd
    FROM revenue {$revWhere}
    GROUP BY date ORDER BY date
", $revParams);
$revByDate = [];
foreach ($revDaily as $d) {
    $revByDate[$d['date']] = $d;
}

// --- Campaign-level data (needs fc. prefixes for JOIN) ---
$fcWhere = "WHERE fc.date BETWEEN ? AND ?";
$fcParams = [$monthStart, $monthEnd];
if ($dashAccount) {
    $fcWhere .= " AND fc.account_name = ?";
    $fcParams[] = $dashAccount;
}
if ($searchCampaign) {
    $fcWhere .= " AND (fc.campaign_name LIKE ? OR fc.campaign_id LIKE ?)";
    $fcParams[] = "%{$searchCampaign}%";
    $fcParams[] = "%{$searchCampaign}%";
}

$campaignData = fetchAll("
    SELECT fc.campaign_id, fc.campaign_name,
           SUM(fc.investimento) as invest,
           SUM(fc.cliques) as cliques,
           SUM(fc.impressoes) as impressoes,
           COALESCE(SUM(r.receita_usd), 0) * {$cotacao} as receita_brl,
           COALESCE(SUM(r.receita_usd), 0) as receita_usd,
           COUNT(DISTINCT fc.date) as dias
    FROM fb_campaigns fc
    LEFT JOIN revenue r ON fc.campaign_id = r.utm_campaign AND fc.date = r.date
    {$fcWhere}
    GROUP BY fc.campaign_id, fc.campaign_name
    ORDER BY invest DESC
", $fcParams);

// Add ROAS to campaign data (with taxes)
foreach ($campaignData as &$cd) {
    $cd['imposto_fb'] = $cd['invest'] * $taxaFb;
    $cd['custo_real'] = $cd['invest'] + $cd['imposto_fb'];
    $cd['imposto_receita'] = $cd['receita_brl'] * $taxaOutros;
    $cd['custo_total'] = $cd['custo_real'] + $cd['imposto_receita'];
    $cd['lucro'] = $cd['receita_brl'] - $cd['custo_total'];
    $cd['roas'] = $cd['custo_total'] > 0 ? (($cd['receita_brl'] - $cd['custo_total']) / $cd['custo_total']) * 100 : 0;
    $cd['cpc'] = $cd['cliques'] > 0 ? $cd['invest'] / $cd['cliques'] : 0;
}
unset($cd);

// --- Merge daily data ---
$allDates = array_unique(array_merge(array_keys($fbByDate), array_keys($revByDate)));
sort($allDates);

$monthlyData = [];
$totalInvest = 0;
$totalReceita = 0;
$totalReceitaBRL = 0;
$totalCliques = 0;
$totalImpressoes = 0;
$totalDays = 0;
$totalImpostoFb = 0;
$totalImpostoReceita = 0;
$totalCustoTotal = 0;

foreach ($allDates as $dt) {
    $invest = $fbByDate[$dt]['investimento'] ?? 0;
    $revUsd = $revByDate[$dt]['receita_usd'] ?? 0;
    $revBrl = $revUsd * $cotacao;
    $impostoFbDia = $invest * $taxaFb;
    $custoReal = $invest + $impostoFbDia;
    $impostoReceitaDia = $revBrl * $taxaOutros;
    $custoTotalDia = $custoReal + $impostoReceitaDia;
    $lucro = $revBrl - $custoTotalDia;
    $roi = $custoTotalDia > 0 ? ($revBrl - $custoTotalDia) / $custoTotalDia : 0;
    $roas = $custoTotalDia > 0 ? (($revBrl - $custoTotalDia) / $custoTotalDia) * 100 : 0;
    $campaigns = $fbByDate[$dt]['campaigns'] ?? 0;
    $cliques = $fbByDate[$dt]['cliques'] ?? 0;
    $impressoes = $fbByDate[$dt]['impressoes'] ?? 0;

    $monthlyData[] = [
        'date' => $dt,
        'investimento' => $invest,
        'imposto_fb' => $impostoFbDia,
        'custo_real' => $custoReal,
        'imposto_receita' => $impostoReceitaDia,
        'custo_total' => $custoTotalDia,
        'receita_usd' => $revUsd,
        'receita_brl' => $revBrl,
        'lucro' => $lucro,
        'roi' => $roi,
        'roas' => $roas,
        'campaigns' => $campaigns,
        'cliques' => $cliques,
        'impressoes' => $impressoes,
    ];

    $totalInvest += $invest;
    $totalReceita += $revUsd;
    $totalReceitaBRL += $revBrl;
    $totalCliques += $cliques;
    $totalImpressoes += $impressoes;
    $totalImpostoFb += $impostoFbDia;
    $totalImpostoReceita += $impostoReceitaDia;
    $totalCustoTotal += $custoTotalDia;
    $totalDays++;
}

// --- Sort daily data ---
switch ($sortBy) {
    case 'date_asc': usort($monthlyData, fn($a, $b) => strcmp($a['date'], $b['date'])); break;
    case 'date_desc': usort($monthlyData, fn($a, $b) => strcmp($b['date'], $a['date'])); break;
    case 'invest_desc': usort($monthlyData, fn($a, $b) => $b['investimento'] <=> $a['investimento']); break;
    case 'invest_asc': usort($monthlyData, fn($a, $b) => $a['investimento'] <=> $b['investimento']); break;
    case 'receita_desc': usort($monthlyData, fn($a, $b) => $b['receita_brl'] <=> $a['receita_brl']); break;
    case 'receita_asc': usort($monthlyData, fn($a, $b) => $a['receita_brl'] <=> $b['receita_brl']); break;
    case 'lucro_desc': usort($monthlyData, fn($a, $b) => $b['lucro'] <=> $a['lucro']); break;
    case 'lucro_asc': usort($monthlyData, fn($a, $b) => $a['lucro'] <=> $b['lucro']); break;
    case 'roas_desc': usort($monthlyData, fn($a, $b) => $b['roas'] <=> $a['roas']); break;
    case 'roas_asc': usort($monthlyData, fn($a, $b) => $a['roas'] <=> $b['roas']); break;
}

// --- Sort campaign data ---
switch ($sortBy) {
    case 'name_asc': usort($campaignData, fn($a, $b) => strcmp($a['campaign_name'], $b['campaign_name'])); break;
    case 'name_desc': usort($campaignData, fn($a, $b) => strcmp($b['campaign_name'], $a['campaign_name'])); break;
    case 'invest_desc': usort($campaignData, fn($a, $b) => $b['invest'] <=> $a['invest']); break;
    case 'invest_asc': usort($campaignData, fn($a, $b) => $a['invest'] <=> $b['invest']); break;
    case 'roas_desc': usort($campaignData, fn($a, $b) => $b['roas'] <=> $a['roas']); break;
    case 'roas_asc': usort($campaignData, fn($a, $b) => $a['roas'] <=> $b['roas']); break;
    case 'lucro_desc': usort($campaignData, fn($a, $b) => $b['lucro'] <=> $a['lucro']); break;
    case 'lucro_asc': usort($campaignData, fn($a, $b) => $a['lucro'] <=> $b['lucro']); break;
}

$totalLucro = $totalReceitaBRL - $totalCustoTotal;
$avgROI = $totalCustoTotal > 0 ? ($totalReceitaBRL - $totalCustoTotal) / $totalCustoTotal : 0;
$totalROAS = $totalCustoTotal > 0 ? (($totalReceitaBRL - $totalCustoTotal) / $totalCustoTotal) * 100 : 0;
$avgCPC = $totalCliques > 0 ? $totalInvest / $totalCliques : 0;

// Available accounts for filter
$dashAccounts = fetchAll("SELECT DISTINCT account_name FROM fb_campaigns ORDER BY account_name");

// Available GAM sites for filter
try {
    $dashGamSites = fetchAll("SELECT DISTINCT site_name FROM revenue WHERE site_name != '' ORDER BY site_name");
} catch (Exception $e) {
    $dashGamSites = [];
}

// Available campaigns for filter
$dashCampaigns = fetchAll("
    SELECT DISTINCT campaign_name FROM fb_campaigns {$fbWhere} ORDER BY campaign_name LIMIT 50
", $fbParams);

// Last day data (uses the end date of the selected filter, not always "today")
$lastDate = $dateTo;
$fbLastWhere = 'WHERE date = ?';
$fbLastParams = [$lastDate];
if ($dashAccount) {
    $fbLastWhere .= ' AND account_name = ?';
    $fbLastParams[] = $dashAccount;
}
$fbLast = fetchOne("
    SELECT COALESCE(SUM(investimento), 0) as total_invest,
           COUNT(DISTINCT campaign_id) as total_campaigns
    FROM fb_campaigns {$fbLastWhere}
", $fbLastParams);

$revLastWhere = 'WHERE date = ?';
$revLastParams = [$lastDate];
if ($dashSite) {
    $revLastWhere .= ' AND site_name = ?';
    $revLastParams[] = $dashSite;
}
$revLast = fetchOne("
    SELECT COALESCE(SUM(receita_usd), 0) as total
    FROM revenue {$revLastWhere}
", $revLastParams);
$revLastBRL = ($revLast['total'] ?? 0) * $cotacao;

// --- Hourly FB performance (same dashboard filters) ---
$hourlyRows = [];
$hourlyRevenueRows = [];
try {
    if (function_exists('ensureFBHourlyTable')) {
        ensureFBHourlyTable();
    }

    $hourWhere = "WHERE fh.date BETWEEN ? AND ?";
    $hourParams = [$monthStart, $monthEnd];

    if ($dashAccount) {
        $hourWhere .= " AND fh.account_name = ?";
        $hourParams[] = $dashAccount;
    }
    if ($searchCampaign) {
        $hourWhere .= " AND (fh.campaign_name LIKE ? OR fh.campaign_id LIKE ?)";
        $hourParams[] = "%{$searchCampaign}%";
        $hourParams[] = "%{$searchCampaign}%";
    }
    if ($dashSite) {
        $hourWhere .= " AND EXISTS (
            SELECT 1 FROM revenue r
            WHERE r.date = fh.date
              AND r.site_name = ?
              AND (r.utm_campaign = fh.campaign_id OR r.campaign_id = fh.campaign_id)
        )";
        $hourParams[] = $dashSite;
    }

    $hourlyRows = fetchAll("
        SELECT fh.date,
               fh.hour_start,
               MIN(fh.hour_label) as hour_label,
               fh.campaign_id,
               MIN(fh.campaign_name) as campaign_name,
               fh.account_name,
               SUM(fh.spend) as spend,
               SUM(fh.impressions) as impressions,
               SUM(fh.clicks) as clicks,
               SUM(fh.results) as results
        FROM fb_hourly fh
        {$hourWhere}
        GROUP BY fh.date, fh.hour_start, fh.campaign_id, fh.account_name
        ORDER BY fh.date, fh.hour_start
    ", $hourParams);

    $revHourlyWhere = "WHERE date BETWEEN ? AND ?";
    $revHourlyParams = [$monthStart, $monthEnd];
    if ($dashSite) {
        $revHourlyWhere .= " AND site_name = ?";
        $revHourlyParams[] = $dashSite;
    }
    if ($searchCampaign) {
        $revHourlyWhere .= " AND (campaign_id LIKE ? OR utm_campaign LIKE ?)";
        $revHourlyParams[] = "%{$searchCampaign}%";
        $revHourlyParams[] = "%{$searchCampaign}%";
    }

    $hourlyRevenueRows = fetchAll("
        SELECT date,
               CASE
                   WHEN utm_campaign IS NOT NULL AND utm_campaign != '' THEN utm_campaign
                   ELSE campaign_id
               END as campaign_key,
               SUM(receita_usd) as receita_usd
        FROM revenue
        {$revHourlyWhere}
        GROUP BY date, CASE
            WHEN utm_campaign IS NOT NULL AND utm_campaign != '' THEN utm_campaign
            ELSE campaign_id
        END
    ", $revHourlyParams);
} catch (Exception $e) {
    $hourlyRows = [];
    $hourlyRevenueRows = [];
}

$hourlyRevenueMap = [];
foreach ($hourlyRevenueRows as $revHour) {
    $revKey = ($revHour['date'] ?? '') . '|' . ($revHour['campaign_key'] ?? '');
    $hourlyRevenueMap[$revKey] = (float)($revHour['receita_usd'] ?? 0);
}

$hourlyDayTotals = [];
foreach ($hourlyRows as $hr) {
    $dayKey = ($hr['date'] ?? '') . '|' . ($hr['campaign_id'] ?? '');
    if (!isset($hourlyDayTotals[$dayKey])) {
        $hourlyDayTotals[$dayKey] = [
            'spend' => 0,
            'impressions' => 0,
            'clicks' => 0,
            'results' => 0,
        ];
    }

    $hourlyDayTotals[$dayKey]['spend'] += (float)($hr['spend'] ?? 0);
    $hourlyDayTotals[$dayKey]['impressions'] += (int)($hr['impressions'] ?? 0);
    $hourlyDayTotals[$dayKey]['clicks'] += (int)($hr['clicks'] ?? 0);
    $hourlyDayTotals[$dayKey]['results'] += (int)($hr['results'] ?? 0);
}

$hourlyChartData = [];
for ($hour = 0; $hour < 24; $hour++) {
    $hourlyChartData[$hour] = [
        'hour' => sprintf('%02dh', $hour),
        'hour_start' => $hour,
        'hour_label' => sprintf('%02d:00 - %02d:59', $hour, $hour),
        'receita_brl' => 0,
        'spend' => 0,
        'imposto_fb' => 0,
        'imposto_receita' => 0,
        'custo_total' => 0,
        'lucro' => 0,
        'impressions' => 0,
        'clicks' => 0,
        'results' => 0,
        'cpc' => 0,
        'ctr' => 0,
        'cpm' => 0,
        'cost_per_result' => 0,
        'days_count' => 0,
        'campaigns' => 0,
        'campaign_keys' => [],
        'active_rows' => 0,
    ];
}

foreach ($hourlyRows as $hr) {
    $hour = max(0, min(23, (int)($hr['hour_start'] ?? 0)));
    $spend = (float)($hr['spend'] ?? 0);
    $impressions = (int)($hr['impressions'] ?? 0);
    $clicks = (int)($hr['clicks'] ?? 0);
    $results = (int)($hr['results'] ?? 0);
    $dayKey = ($hr['date'] ?? '') . '|' . ($hr['campaign_id'] ?? '');
    $dayTotals = $hourlyDayTotals[$dayKey] ?? ['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'results' => 0];
    $share = 0;

    if (($dayTotals['results'] ?? 0) > 0) {
        $share = $results / $dayTotals['results'];
    } elseif (($dayTotals['clicks'] ?? 0) > 0) {
        $share = $clicks / $dayTotals['clicks'];
    } elseif (($dayTotals['impressions'] ?? 0) > 0) {
        $share = $impressions / $dayTotals['impressions'];
    } elseif (($dayTotals['spend'] ?? 0) > 0) {
        $share = $spend / $dayTotals['spend'];
    }

    $receitaUsd = $hourlyRevenueMap[$dayKey] ?? 0;
    $receitaBrl = $receitaUsd * $cotacao * $share;
    $impostoFbHora = $spend * $taxaFb;
    $impostoReceitaHora = $receitaBrl * $taxaOutros;
    $custoTotalHora = $spend + $impostoFbHora + $impostoReceitaHora;
    $lucroHora = $receitaBrl - $custoTotalHora;

    $hourlyChartData[$hour]['hour_label'] = $hr['hour_label'] ?: $hourlyChartData[$hour]['hour_label'];
    $hourlyChartData[$hour]['receita_brl'] += $receitaBrl;
    $hourlyChartData[$hour]['spend'] += $spend;
    $hourlyChartData[$hour]['imposto_fb'] += $impostoFbHora;
    $hourlyChartData[$hour]['imposto_receita'] += $impostoReceitaHora;
    $hourlyChartData[$hour]['custo_total'] += $custoTotalHora;
    $hourlyChartData[$hour]['lucro'] += $lucroHora;
    $hourlyChartData[$hour]['impressions'] += $impressions;
    $hourlyChartData[$hour]['clicks'] += $clicks;
    $hourlyChartData[$hour]['results'] += $results;
    $hourlyChartData[$hour]['active_rows']++;
    $hourlyChartData[$hour]['campaign_keys'][$hr['campaign_id'] ?? ''] = true;
    if ($hr['date'] ?? '') {
        $hourlyChartData[$hour]['days'][$hr['date']] = true;
    }
}

foreach ($hourlyChartData as &$hourData) {
    $hourData['cpc'] = $hourData['clicks'] > 0 ? $hourData['spend'] / $hourData['clicks'] : 0;
    $hourData['ctr'] = $hourData['impressions'] > 0 ? ($hourData['clicks'] / $hourData['impressions']) * 100 : 0;
    $hourData['cpm'] = $hourData['impressions'] > 0 ? ($hourData['spend'] / $hourData['impressions']) * 1000 : 0;
    $hourData['cost_per_result'] = $hourData['results'] > 0 ? $hourData['spend'] / $hourData['results'] : 0;
    $hourData['campaigns'] = count(array_filter(array_keys($hourData['campaign_keys'])));
    $hourData['days_count'] = isset($hourData['days']) ? count($hourData['days']) : 0;
    unset($hourData['campaign_keys'], $hourData['days']);
}
unset($hourData);
$hourlyChartData = array_values($hourlyChartData);

$hourlyTotalResults = array_sum(array_column($hourlyChartData, 'results'));
$hourlyTotalClicks = array_sum(array_column($hourlyChartData, 'clicks'));
$hourlyTotalSpend = array_sum(array_column($hourlyChartData, 'spend'));
$hourlyTotalReceita = array_sum(array_column($hourlyChartData, 'receita_brl'));
$hourlyTotalProfit = array_sum(array_column($hourlyChartData, 'lucro'));
$hourlyHasData = ($hourlyTotalSpend > 0 || $hourlyTotalReceita > 0 || $hourlyTotalClicks > 0 || $hourlyTotalResults > 0);
$hourlyMetricLabel = 'Lucro estimado';
$bestHour = null;
$worstHour = null;

foreach ($hourlyChartData as $hourRow) {
    if ($hourRow['spend'] <= 0 && $hourRow['receita_brl'] <= 0 && $hourRow['clicks'] <= 0 && $hourRow['results'] <= 0) {
        continue;
    }

    if (!$bestHour || $hourRow['lucro'] > $bestHour['lucro']) {
        $bestHour = $hourRow;
    }
    if (!$worstHour || $hourRow['lucro'] < $worstHour['lucro']) {
        $worstHour = $hourRow;
    }
}

$lastDateLabel = formatDate($lastDate);

// Build filter URL helper
function dashUrl($overrides = []) {
    $params = array_merge($_GET, ['page' => 'dashboard'], $overrides);
    return '?' . http_build_query($params);
}
?>

<?php
$currentPage = 'dashboard';
ob_start();
?>
<select class="filter-input" style="max-width:180px;min-height:36px;padding:6px 12px;font-size:13px;" onchange="var p=new URLSearchParams(location.search);p.set('account',this.value);location.href='?'+p.toString()">
    <option value="">Todas as Contas</option>
    <?php foreach ($dashAccounts as $acc): ?>
    <option value="<?= $acc['account_name'] ?>" <?= $dashAccount === $acc['account_name'] ? 'selected' : '' ?>><?= $acc['account_name'] ?></option>
    <?php endforeach; ?>
</select>
<?php if (!empty($dashGamSites)): ?>
<select class="filter-input" style="max-width:160px;min-height:36px;padding:6px 12px;font-size:13px;" onchange="var p=new URLSearchParams(location.search);p.set('site',this.value);location.href='?'+p.toString()">
    <option value="">Todos os Sites</option>
    <?php foreach ($dashGamSites as $site): ?>
    <option value="<?= sanitize($site['site_name']) ?>" <?= $dashSite === $site['site_name'] ? 'selected' : '' ?>>🌐 <?= sanitize($site['site_name']) ?></option>
    <?php endforeach; ?>
</select>
<?php endif; ?>
<button class="btn btn-primary btn-sm" id="btnSyncAll" onclick="syncAll()">🔄 Sync</button>
<span id="syncStatus" style="font-size:12px;color:var(--text-muted);"></span>
<?php $dateFilterExtra = ob_get_clean(); ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<!-- Summary Cards -->
<div class="cards-grid" style="margin-top:16px;">
    <div class="card card-blue">
        <div class="card-icon">💰</div>
        <div class="card-label">Custo Total</div>
        <div class="card-value"><?= formatMoney($totalCustoTotal) ?></div>
        <div class="card-change">FB: <?= formatMoney($totalInvest) ?> + Imp.FB: <?= formatMoney($totalImpostoFb) ?> + Imp.Receita: <?= formatMoney($totalImpostoReceita) ?></div>
    </div>

    <div class="card card-green">
        <div class="card-icon">📈</div>
        <div class="card-label">Receita GAM</div>
        <div class="card-value"><?= formatMoney($totalReceitaBRL) ?></div>
        <div class="card-change"><?= formatMoney($totalReceita, 'USD') ?> (USD) • Cotação R$ <?= formatNumber($cotacao, 2) ?></div>
    </div>

    <div class="card card-<?= $totalROAS >= 0 ? 'green' : 'red' ?>">
        <div class="card-icon"><?= $totalROAS >= 0 ? '↑' : '↓' ?></div>
        <div class="card-label">ROAS</div>
        <div class="card-value <?= $totalROAS >= 0 ? 'positive' : 'negative' ?>"><?= formatNumber($totalROAS, 1) ?>%</div>
        <div class="card-change">ROI: <?= formatPercent($avgROI) ?> • CPC: <?= formatMoney($avgCPC) ?></div>
    </div>

    <div class="card card-<?= $totalLucro >= 0 ? 'purple' : 'red' ?>">
        <div class="card-icon">🎯</div>
        <div class="card-label">Lucro / Prejuízo</div>
        <div class="card-value <?= roiClass($totalLucro) ?>">
            <?= formatMoney($totalLucro) ?>
        </div>
        <div class="card-change">Receita − Custos (c/ impostos <?= formatNumber($taxaFb * 100, 1) ?>% FB + <?= formatNumber($taxaOutros * 100, 1) ?>% outros)</div>
    </div>
</div>

<!-- Last Day Cards (respects filter) -->
<div class="cards-grid" style="margin-bottom: 20px;">
    <div class="card">
        <div class="card-label">Último dia (<?= $lastDateLabel ?>) - Invest. FB</div>
        <div class="card-value" style="font-size:20px;"><?= formatMoney($fbLast['total_invest'] ?? 0) ?></div>
        <div class="card-change"><?= $fbLast['total_campaigns'] ?? 0 ?> campanhas ativas</div>
    </div>
    <div class="card">
        <div class="card-label">Último dia (<?= $lastDateLabel ?>) - Receita GAM</div>
        <div class="card-value" style="font-size:20px;"><?= formatMoney($revLastBRL) ?></div>
        <div class="card-change"><?= formatMoney($revLast['total'] ?? 0, 'USD') ?> (USD)</div>
    </div>
    <div class="card">
        <div class="card-label">Último dia (<?= $lastDateLabel ?>) - Lucro</div>
        <?php
            $lastInvest = $fbLast['total_invest'] ?? 0;
            $lastCustoTotal = $lastInvest + ($lastInvest * $taxaFb) + ($revLastBRL * $taxaOutros);
            $lastLucro = $revLastBRL - $lastCustoTotal;
        ?>
        <div class="card-value <?= roiClass($lastLucro) ?>" style="font-size:20px;"><?= formatMoney($lastLucro) ?></div>
    </div>
    <div class="card">
        <div class="card-label">Métricas do Período</div>
        <div class="card-value" style="font-size:16px;">
            <?= formatNumber($totalCliques) ?> cliques
        </div>
        <div class="card-change"><?= formatNumber($totalImpressoes) ?> impressões</div>
    </div>
</div>

<!-- Chart -->
<div class="chart-container">
    <div class="chart-header">
        <span class="chart-title">📊 Investimento vs Receita BRL vs Lucro</span>
    </div>
    <canvas id="compassChart" class="chart-canvas"></canvas>
</div>

<!-- Hourly Results Chart -->
<div class="chart-container">
    <div class="chart-header" style="align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <span class="chart-title">Lucro estimado por hora</span>
        <?php if ($hourlyHasData): ?>
        <div class="table-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
            <span class="badge <?= $hourlyTotalProfit >= 0 ? 'badge-green' : 'badge-red' ?>">
                Saldo: <?= formatMoney($hourlyTotalProfit) ?>
            </span>
            <?php if ($bestHour): ?>
            <span class="badge <?= $bestHour['lucro'] >= 0 ? 'badge-green' : 'badge-red' ?>">
                Melhor: <?= sanitize($bestHour['hour']) ?> (<?= formatMoney($bestHour['lucro']) ?>)
            </span>
            <?php endif; ?>
            <?php if ($worstHour && $worstHour['hour_start'] !== ($bestHour['hour_start'] ?? null)): ?>
            <span class="badge <?= $worstHour['lucro'] < 0 ? 'badge-red' : 'badge-yellow' ?>">
                Pior: <?= sanitize($worstHour['hour']) ?> (<?= formatMoney($worstHour['lucro']) ?>)
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($hourlyHasData): ?>
    <canvas id="hourlyResultsChart" class="chart-canvas"></canvas>
    <?php else: ?>
    <div class="empty-state" style="padding:32px 16px;">
        <div class="empty-state-title">Nenhum dado por hora encontrado</div>
        <div class="empty-state-text">Clique em Sync para importar o breakdown horario do Facebook neste periodo.</div>
    </div>
    <?php endif; ?>
</div>

<?php if ($viewMode === 'campaign'): ?>
<!-- ============= CAMPAIGN VIEW ============= -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">📢 Performance por Campanha</span>
        <div class="table-actions">
            <span class="badge badge-blue"><?= count($campaignData) ?> campanhas</span>
        </div>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th><a href="<?= dashUrl(['sort' => $sortBy === 'name_asc' ? 'name_desc' : 'name_asc']) ?>" class="sort-link">Campanha <?= $sortBy === 'name_asc' ? '↑' : ($sortBy === 'name_desc' ? '↓' : '↕') ?></a></th>
                    <th>ID</th>
                    <th>Dias</th>
                    <th><a href="<?= dashUrl(['sort' => $sortBy === 'invest_desc' ? 'invest_asc' : 'invest_desc']) ?>" class="sort-link">Investimento <?= $sortBy === 'invest_desc' ? '↓' : ($sortBy === 'invest_asc' ? '↑' : '↕') ?></a></th>
                    <th><a href="<?= dashUrl(['sort' => $sortBy === 'receita_desc' ? 'receita_asc' : 'receita_desc']) ?>" class="sort-link">Receita BRL <?= $sortBy === 'receita_desc' ? '↓' : ($sortBy === 'receita_asc' ? '↑' : '↕') ?></a></th>
                    <th><a href="<?= dashUrl(['sort' => $sortBy === 'lucro_desc' ? 'lucro_asc' : 'lucro_desc']) ?>" class="sort-link">Lucro <?= $sortBy === 'lucro_desc' ? '↓' : ($sortBy === 'lucro_asc' ? '↑' : '↕') ?></a></th>
                    <th><a href="<?= dashUrl(['sort' => $sortBy === 'roas_desc' ? 'roas_asc' : 'roas_desc']) ?>" class="sort-link">ROAS <?= $sortBy === 'roas_desc' ? '↓' : ($sortBy === 'roas_asc' ? '↑' : '↕') ?></a></th>
                    <th>Cliques</th>
                    <th>CPC</th>
                    <th>Impressões</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($campaignData)): ?>
                <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted);">Nenhuma campanha encontrada para este período.</td></tr>
                <?php else: ?>
                <?php foreach ($campaignData as $cd): ?>
                <tr>
                    <td title="<?= sanitize($cd['campaign_name']) ?>"><?= sanitize(mb_substr($cd['campaign_name'] ?? '', 0, 45)) ?></td>
                    <td style="font-family:monospace;font-size:11px;"><?= $cd['campaign_id'] ?></td>
                    <td><?= $cd['dias'] ?></td>
                    <td><?= formatMoney($cd['invest']) ?></td>
                    <td><?= formatMoney($cd['receita_brl']) ?></td>
                    <td class="<?= roiClass($cd['lucro']) ?>"><?= formatMoney($cd['lucro']) ?></td>
                    <td class="<?= $cd['roas'] >= 0 ? 'positive' : 'negative' ?>"><?= formatNumber($cd['roas'], 1) ?>%</td>
                    <td><?= formatNumber($cd['cliques']) ?></td>
                    <td><?= formatMoney($cd['cpc']) ?></td>
                    <td><?= formatNumber($cd['impressoes']) ?></td>
                </tr>
                <?php endforeach; ?>
                <!-- Summary -->
                <tr class="summary-row">
                    <td><strong>TOTAL</strong></td>
                    <td>-</td>
                    <td><?= $totalDays ?></td>
                    <td><?= formatMoney($totalInvest) ?></td>
                    <td><?= formatMoney($totalReceitaBRL) ?></td>
                    <td class="<?= roiClass($totalLucro) ?>"><?= formatMoney($totalLucro) ?></td>
                    <td class="<?= $totalROAS >= 0 ? 'positive' : 'negative' ?>"><?= formatNumber($totalROAS, 1) ?>%</td>
                    <td><?= formatNumber($totalCliques) ?></td>
                    <td><?= formatMoney($avgCPC) ?></td>
                    <td><?= formatNumber($totalImpressoes) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- ============= DAILY VIEW ============= -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">📅 Dados Diários</span>
        <div class="table-actions">
            <span class="badge badge-blue"><?= count($monthlyData) ?> dias</span>
        </div>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th><a href="<?= dashUrl(['sort' => $sortBy === 'date_desc' ? 'date_asc' : 'date_desc']) ?>" class="sort-link">Data <?= $sortBy === 'date_desc' ? '↓' : ($sortBy === 'date_asc' ? '↑' : '↕') ?></a></th>
                    <th><a href="<?= dashUrl(['sort' => $sortBy === 'invest_desc' ? 'invest_asc' : 'invest_desc']) ?>" class="sort-link">Investimento FB <?= $sortBy === 'invest_desc' ? '↓' : ($sortBy === 'invest_asc' ? '↑' : '↕') ?></a></th>
                    <th>Receita USD</th>
                    <th><a href="<?= dashUrl(['sort' => $sortBy === 'receita_desc' ? 'receita_asc' : 'receita_desc']) ?>" class="sort-link">Receita BRL <?= $sortBy === 'receita_desc' ? '↓' : ($sortBy === 'receita_asc' ? '↑' : '↕') ?></a></th>
                    <th><a href="<?= dashUrl(['sort' => $sortBy === 'lucro_desc' ? 'lucro_asc' : 'lucro_desc']) ?>" class="sort-link">Lucro <?= $sortBy === 'lucro_desc' ? '↓' : ($sortBy === 'lucro_asc' ? '↑' : '↕') ?></a></th>
                    <th>ROI</th>
                    <th><a href="<?= dashUrl(['sort' => $sortBy === 'roas_desc' ? 'roas_asc' : 'roas_desc']) ?>" class="sort-link">ROAS <?= $sortBy === 'roas_desc' ? '↓' : ($sortBy === 'roas_asc' ? '↑' : '↕') ?></a></th>
                    <th>Campanhas</th>
                    <th>Cliques</th>
                    <th>Impressões</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($monthlyData)): ?>
                <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted);">Nenhum dado para este período. Sincronize o Facebook e o GAM.</td></tr>
                <?php else: ?>
                <?php foreach ($monthlyData as $row): ?>
                <tr>
                    <td><?= formatDate($row['date']) ?></td>
                    <td><?= formatMoney($row['investimento']) ?></td>
                    <td><?= formatMoney($row['receita_usd'], 'USD') ?></td>
                    <td><?= formatMoney($row['receita_brl']) ?></td>
                    <td class="<?= roiClass($row['lucro']) ?>"><?= formatMoney($row['lucro']) ?></td>
                    <td class="<?= roiClass($row['roi']) ?>"><?= formatPercent($row['roi']) ?></td>
                    <td class="<?= $row['roas'] >= 0 ? 'positive' : 'negative' ?>"><?= formatNumber($row['roas'], 1) ?>%</td>
                    <td><?= $row['campaigns'] ?></td>
                    <td><?= formatNumber($row['cliques']) ?></td>
                    <td><?= formatNumber($row['impressoes']) ?></td>
                </tr>
                <?php endforeach; ?>
                <!-- Summary row -->
                <tr class="summary-row">
                    <td><strong>TOTAL</strong></td>
                    <td><?= formatMoney($totalInvest) ?></td>
                    <td><?= formatMoney($totalReceita, 'USD') ?></td>
                    <td><?= formatMoney($totalReceitaBRL) ?></td>
                    <td class="<?= roiClass($totalLucro) ?>"><?= formatMoney($totalLucro) ?></td>
                    <td class="<?= roiClass($avgROI) ?>"><?= formatPercent($avgROI) ?></td>
                    <td class="<?= $totalROAS >= 0 ? 'positive' : 'negative' ?>"><?= formatNumber($totalROAS, 1) ?>%</td>
                    <td>-</td>
                    <td><?= formatNumber($totalCliques) ?></td>
                    <td><?= formatNumber($totalImpressoes) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const money = value => 'R$ ' + (Number(value) || 0).toFixed(2);

    // Sort chart data by date always
    const data = <?= json_encode($monthlyData) ?>;
    if (data.length > 0) {
        data.sort((a, b) => a.date.localeCompare(b.date));

        const ctx = document.getElementById('compassChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(d => d.date.split('-').reverse().slice(0,2).join('/')),
                    datasets: [
                        {
                            label: 'Investimento FB',
                            data: data.map(d => parseFloat(d.investimento) || 0),
                            borderColor: '#0a84ff',
                            backgroundColor: 'rgba(10, 132, 255, 0.08)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Receita BRL',
                            data: data.map(d => parseFloat(d.receita_brl) || 0),
                            borderColor: '#30d158',
                            backgroundColor: 'rgba(48, 209, 88, 0.08)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: 'Lucro',
                            data: data.map(d => parseFloat(d.lucro) || 0),
                            borderColor: '#ffd60a',
                            backgroundColor: 'rgba(255, 214, 10, 0.08)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#98989f', font: { family: 'Inter', weight: '500' } } },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    return ctx.dataset.label + ': ' + money(ctx.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#636366' } },
                        y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#636366' } }
                    }
                }
            });
        }
    }

    const hourlyData = <?= json_encode($hourlyChartData) ?>;
    const hourlyMetricLabel = <?= json_encode($hourlyMetricLabel) ?>;
    const hourlyCtx = document.getElementById('hourlyResultsChart');

    if (hourlyCtx && hourlyData.length > 0) {
        const hourlyLimit = Math.max(...hourlyData.map(d => Math.abs(Number(d.lucro) || 0)), 1) * 1.15;

        new Chart(hourlyCtx, {
            type: 'bar',
            data: {
                labels: hourlyData.map(d => d.hour),
                datasets: [
                    {
                        label: hourlyMetricLabel,
                        data: hourlyData.map(d => Number(d.lucro) || 0),
                        backgroundColor: hourlyData.map(d => (Number(d.lucro) || 0) >= 0 ? 'rgba(48, 209, 88, 0.72)' : 'rgba(255, 69, 58, 0.72)'),
                        borderColor: hourlyData.map(d => (Number(d.lucro) || 0) >= 0 ? '#30d158' : '#ff453a'),
                        borderWidth: 1,
                        borderRadius: 4,
                        yAxisID: 'y'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: '#98989f', font: { family: 'Inter', weight: '500' } } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': ' + money(ctx.parsed.y);
                            },
                            afterBody: function(items) {
                                const item = items[0];
                                const hour = hourlyData[item.dataIndex] || {};
                                return [
                                    'Receita: ' + money(hour.receita_brl),
                                    'Custo total: ' + money(hour.custo_total),
                                    'Investimento FB: ' + money(hour.spend),
                                    'Resultados: ' + (Number(hour.results) || 0).toLocaleString('pt-BR'),
                                    'Impressões: ' + (Number(hour.impressions) || 0).toLocaleString('pt-BR'),
                                    'Cliques: ' + (Number(hour.clicks) || 0).toLocaleString('pt-BR'),
                                    'CTR: ' + (Number(hour.ctr) || 0).toFixed(2) + '%'
                                ];
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#636366', maxRotation: 0 } },
                    y: {
                        min: -hourlyLimit,
                        max: hourlyLimit,
                        grid: {
                            color: context => context.tick.value === 0 ? 'rgba(255,255,255,0.45)' : 'rgba(255,255,255,0.04)',
                            lineWidth: context => context.tick.value === 0 ? 2 : 1
                        },
                        ticks: {
                            color: '#636366',
                            callback: value => money(value)
                        },
                        title: { display: true, text: hourlyMetricLabel, color: '#98989f' }
                    }
                }
            }
        });
    }
});



async function syncAll() {
    const btn = document.getElementById('btnSyncAll');
    const status = document.getElementById('syncStatus');
    btn.disabled = true;
    btn.textContent = '⏳ Sincronizando...';
    status.textContent = '';
    
    try {
        // Sync FB
        status.textContent = '⏳ Sincronizando Facebook...';
        const fbRes = await fetch('api/sync.php?action=sync_fb');
        const fbData = await fbRes.json();
        
        // Sync GAM
        status.textContent = '⏳ Sincronizando GAM...';
        const gamRes = await fetch('api/sync.php?action=sync_gam');
        const gamData = await gamRes.json();

        // Sync Google Ads (optional — only if configured)
        let gadsMsg = '';
        try {
            status.textContent = '⏳ Sincronizando Google Ads...';
            const gadsRes = await fetch('api/sync.php?action=sync_google_ads');
            const gadsData = await gadsRes.json();
            gadsMsg = gadsData.success ? ` | GAds: ${gadsData.imported} reg.` : '';
        } catch (e) {
            // Google Ads not configured — skip silently
        }

        // Sync GA4 (optional — only if configured)
        let ga4Msg = '';
        try {
            status.textContent = '⏳ Sincronizando GA4...';
            const ga4Res = await fetch('api/sync.php?action=sync_ga4');
            const ga4Data = await ga4Res.json();
            ga4Msg = ga4Data.success ? ` | GA4: ${ga4Data.imported} sessões` : '';
        } catch (e) {
            // GA4 not configured — skip silently
        }
        
        const fbMsg = fbData.success ? `FB: ${fbData.imported} registros, ${fbData.hourly || 0} horas` : `FB: erro`;
        const gamMsg = gamData.success ? `GAM: ${gamData.imported} registros` : `GAM: erro`;
        
        status.textContent = `✅ ${fbMsg} | ${gamMsg}${gadsMsg}${ga4Msg}`;
        status.style.color = '#10b981';
        
        setTimeout(() => location.reload(), 1500);
    } catch (e) {
        status.textContent = '❌ Erro na sincronização: ' + e.message;
        status.style.color = '#ef4444';
    } finally {
        btn.disabled = false;
        btn.textContent = '🔄 Atualizar Dados';
    }
}
</script>

<style>
.sort-link {
    color: var(--text-primary);
    text-decoration: none;
    white-space: nowrap;
    transition: color 0.2s;
}
.sort-link:hover {
    color: var(--primary);
}
</style>
