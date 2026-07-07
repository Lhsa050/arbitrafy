<?php
/**
 * Traffic Quality / IVT dashboard.
 *
 * This page only reads local dashboard data. It does not inject anything into
 * WordPress, SpunMidia, GPT, ad iframes, preloader or the public site.
 */

$cotacao = getCotacaoDolar();
ensureGA4Table();
if (function_exists('ensureRevenueTableSchema')) ensureRevenueTableSchema();
if (function_exists('ensurePlacementsTable')) ensurePlacementsTable();
if (function_exists('ensureFBPlacementsTable')) ensureFBPlacementsTable();
if (function_exists('ensureFBDevicesTable')) ensureFBDevicesTable();
if (function_exists('ensureFBHourlyTable')) ensureFBHourlyTable();
if (function_exists('ensureRevenueDevicesTable')) ensureRevenueDevicesTable();

list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();
$account = $_GET['account'] ?? '';
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'risk_desc';

function tq_num($value) {
    return is_numeric($value) ? (float)$value : 0.0;
}

function tq_clamp($value, $min = 0, $max = 100) {
    return max($min, min($max, $value));
}

function tq_pct($num, $den) {
    $den = (float)$den;
    return $den > 0 ? ((float)$num / $den) * 100 : 0;
}

function tq_safe_div($num, $den) {
    $den = (float)$den;
    return $den > 0 ? (float)$num / $den : 0;
}

function tq_badge_class($level) {
    if ($level === 'high') return 'badge-red';
    if ($level === 'medium') return 'badge-yellow';
    return 'badge-green';
}

function tq_level_label($level) {
    if ($level === 'high') return 'Alto';
    if ($level === 'medium') return 'Atencao';
    return 'OK';
}

function tq_add_flag(&$flags, $level, $label) {
    $flags[] = ['level' => $level, 'label' => $label];
}

function tq_quality_assessment($row, $avgEcpm, $avgRps) {
    $spend = tq_num($row['spend'] ?? 0);
    $revenue = tq_num($row['revenue'] ?? 0);
    $impressions = tq_num($row['fb_impressions'] ?? 0);
    $clicks = tq_num($row['clicks'] ?? 0);
    $lpViews = tq_num($row['lp_views'] ?? 0);
    $sessions = tq_num($row['ga4_sessions'] ?? 0);
    $gamImpressions = tq_num($row['gam_impressions'] ?? 0);
    $gamRequests = tq_num($row['gam_ad_requests'] ?? 0);

    $ctr = tq_pct($clicks, $impressions);
    $lpRate = tq_pct($lpViews, $clicks);
    $connectRate = tq_pct($sessions, $clicks);
    $fillRate = tq_pct($gamImpressions, $gamRequests);
    $ecpm = $gamImpressions > 0 ? ($revenue / $gamImpressions) * 1000 : 0;
    $rpmSession = $sessions > 0 ? ($revenue / $sessions) * 1000 : 0;
    $rps = tq_safe_div($revenue, $sessions);
    $costPerSession = tq_safe_div($spend, $sessions);
    $spread = $rps - $costPerSession;
    $roi = $spend > 0 ? (($revenue - $spend) / $spend) * 100 : 0;

    $score = 58;
    $flags = [];

    if ($sessions > 0) {
        $score += 8;
    } elseif ($clicks >= 30) {
        $score -= 24;
        tq_add_flag($flags, 'high', 'Sem sessoes GA4 com volume de cliques');
    }

    if ($clicks >= 30) {
        if ($connectRate >= 75) $score += 14;
        elseif ($connectRate >= 60) $score += 7;
        elseif ($connectRate > 0) {
            $score -= 12;
            tq_add_flag($flags, $connectRate < 45 ? 'high' : 'medium', 'Connect rate baixo');
        }
    }

    if ($clicks >= 30 && $lpViews > 0) {
        if ($lpRate >= 75) $score += 8;
        elseif ($lpRate < 45) {
            $score -= 10;
            tq_add_flag($flags, 'medium', 'LP views muito abaixo dos cliques');
        }
    }

    if ($impressions >= 1000) {
        if ($ctr > 10) {
            $score -= 16;
            tq_add_flag($flags, 'high', 'CTR muito acima do normal');
        } elseif ($ctr > 6) {
            $score -= 8;
            tq_add_flag($flags, 'medium', 'CTR acima do normal');
        } elseif ($ctr >= 0.4 && $ctr <= 4.5) {
            $score += 5;
        }
    }

    if ($gamRequests >= 500) {
        if ($fillRate >= 80) $score += 6;
        elseif ($fillRate < 45) {
            $score -= 10;
            tq_add_flag($flags, 'medium', 'Fill rate baixo');
        }
    }

    if ($avgEcpm > 0 && $ecpm > 0) {
        if ($ecpm >= $avgEcpm * 1.15) $score += 7;
        elseif ($ecpm < $avgEcpm * 0.45) {
            $score -= 10;
            tq_add_flag($flags, 'medium', 'eCPM abaixo da media do periodo');
        }
    }

    if ($avgRps > 0 && $rps > 0) {
        if ($rps >= $avgRps * 1.1) $score += 5;
        elseif ($rps < $avgRps * 0.5) $score -= 5;
    }

    if ($sessions >= 50 && $revenue <= 0) {
        $score -= 18;
        tq_add_flag($flags, 'high', 'Trafego com sessoes e sem revenue');
    }

    if ($sessions > 0 && $spread < 0) {
        $score -= 10;
        tq_add_flag($flags, 'medium', 'RPS menor que custo por sessao');
    } elseif ($spread > 0) {
        $score += 8;
    }

    if ($spend > 0 && $roi > 0) $score += 8;
    elseif ($spend > 0 && $roi < -40) {
        $score -= 8;
        tq_add_flag($flags, 'medium', 'ROI muito negativo');
    }

    $highFlags = count(array_filter($flags, fn($f) => $f['level'] === 'high'));
    $mediumFlags = count(array_filter($flags, fn($f) => $f['level'] === 'medium'));
    $score = (int)round(tq_clamp($score));

    $risk = 'low';
    if ($score < 45 || $highFlags >= 2) $risk = 'high';
    elseif ($score < 65 || $highFlags >= 1 || $mediumFlags >= 2) $risk = 'medium';

    return [
        'score' => $score,
        'risk' => $risk,
        'flags' => $flags,
        'ctr' => $ctr,
        'lp_rate' => $lpRate,
        'connect_rate' => $connectRate,
        'fill_rate' => $fillRate,
        'ecpm' => $ecpm,
        'rpm_session' => $rpmSession,
        'rps' => $rps,
        'cost_per_session' => $costPerSession,
        'spread' => $spread,
        'roi' => $roi,
    ];
}

$where = "WHERE fc.date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($account !== '') {
    $where .= " AND fc.account_name = ?";
    $params[] = $account;
}
if ($search !== '') {
    $where .= " AND (fc.campaign_name LIKE ? OR fc.campaign_id LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$accounts = fetchAll("SELECT DISTINCT account_name FROM fb_campaigns ORDER BY account_name");

$campaignRows = fetchAll("
    SELECT
        fc.campaign_id,
        MIN(fc.campaign_name) as campaign_name,
        MIN(fc.account_name) as account_name,
        COUNT(DISTINCT fc.date) as active_days,
        SUM(fc.investimento) as spend,
        SUM(fc.impressoes) as fb_impressions,
        SUM(fc.cliques) as clicks,
        SUM(fc.viz_lp) as lp_views,
        SUM(fc.conv_pct) as results,
        COALESCE(SUM(rv.rev_usd), 0) * {$cotacao} as revenue,
        COALESCE(SUM(rv.gam_impressions), 0) as gam_impressions,
        COALESCE(SUM(rv.gam_ad_requests), 0) as gam_ad_requests,
        COALESCE(SUM(COALESCE(gs_id.sessions, gs_name.sessions, 0)), 0) as ga4_sessions
    FROM fb_campaigns fc
    LEFT JOIN (
        SELECT date, campaign_id, SUM(receita_usd) as rev_usd,
               SUM(gam_impressions) as gam_impressions,
               SUM(gam_ad_requests) as gam_ad_requests
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
    {$where}
    GROUP BY fc.campaign_id
", $params);

$dailyRows = fetchAll("
    SELECT
        fc.date,
        SUM(fc.investimento) as spend,
        SUM(fc.impressoes) as fb_impressions,
        SUM(fc.cliques) as clicks,
        SUM(fc.viz_lp) as lp_views,
        COALESCE(SUM(rv.rev_usd), 0) * {$cotacao} as revenue,
        COALESCE(SUM(rv.gam_impressions), 0) as gam_impressions,
        COALESCE(SUM(rv.gam_ad_requests), 0) as gam_ad_requests,
        COALESCE(SUM(COALESCE(gs_id.sessions, gs_name.sessions, 0)), 0) as ga4_sessions
    FROM fb_campaigns fc
    LEFT JOIN (
        SELECT date, campaign_id, SUM(receita_usd) as rev_usd,
               SUM(gam_impressions) as gam_impressions,
               SUM(gam_ad_requests) as gam_ad_requests
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
    {$where}
    GROUP BY fc.date
    ORDER BY fc.date
", $params);

$dayNames = [
    1 => 'Segunda',
    2 => 'Terca',
    3 => 'Quarta',
    4 => 'Quinta',
    5 => 'Sexta',
    6 => 'Sabado',
    7 => 'Domingo',
];
$weekdayRows = [];
foreach ($dailyRows as $row) {
    $dayNum = (int)date('N', strtotime($row['date']));
    if (!isset($weekdayRows[$dayNum])) {
        $weekdayRows[$dayNum] = [
            'day_num' => $dayNum,
            'day_name' => $dayNames[$dayNum] ?? (string)$dayNum,
            'days' => 0,
            'spend' => 0,
            'revenue' => 0,
            'clicks' => 0,
            'ga4_sessions' => 0,
            'gam_impressions' => 0,
            'gam_ad_requests' => 0,
        ];
    }
    $weekdayRows[$dayNum]['days']++;
    $weekdayRows[$dayNum]['spend'] += tq_num($row['spend'] ?? 0);
    $weekdayRows[$dayNum]['revenue'] += tq_num($row['revenue'] ?? 0);
    $weekdayRows[$dayNum]['clicks'] += tq_num($row['clicks'] ?? 0);
    $weekdayRows[$dayNum]['ga4_sessions'] += tq_num($row['ga4_sessions'] ?? 0);
    $weekdayRows[$dayNum]['gam_impressions'] += tq_num($row['gam_impressions'] ?? 0);
    $weekdayRows[$dayNum]['gam_ad_requests'] += tq_num($row['gam_ad_requests'] ?? 0);
}
ksort($weekdayRows);
foreach ($weekdayRows as &$row) {
    $row['connect_rate'] = tq_pct($row['ga4_sessions'], $row['clicks']);
    $row['roi'] = tq_num($row['spend']) > 0 ? ((tq_num($row['revenue']) - tq_num($row['spend'])) / tq_num($row['spend'])) * 100 : 0;
    $row['ecpm'] = tq_num($row['gam_impressions']) > 0 ? (tq_num($row['revenue']) / tq_num($row['gam_impressions'])) * 1000 : 0;
}
unset($row);

$totalSpend = array_sum(array_map(fn($r) => tq_num($r['spend'] ?? 0), $campaignRows));
$totalRevenue = array_sum(array_map(fn($r) => tq_num($r['revenue'] ?? 0), $campaignRows));
$totalClicks = array_sum(array_map(fn($r) => tq_num($r['clicks'] ?? 0), $campaignRows));
$totalSessions = array_sum(array_map(fn($r) => tq_num($r['ga4_sessions'] ?? 0), $campaignRows));
$totalGamImpressions = array_sum(array_map(fn($r) => tq_num($r['gam_impressions'] ?? 0), $campaignRows));
$totalGamRequests = array_sum(array_map(fn($r) => tq_num($r['gam_ad_requests'] ?? 0), $campaignRows));
$avgEcpm = $totalGamImpressions > 0 ? ($totalRevenue / $totalGamImpressions) * 1000 : 0;
$avgRps = tq_safe_div($totalRevenue, $totalSessions);

foreach ($campaignRows as &$row) {
    $assessment = tq_quality_assessment($row, $avgEcpm, $avgRps);
    $row = array_merge($row, $assessment);
}
unset($row);

usort($campaignRows, function($a, $b) use ($sort) {
    $map = [
        'risk_desc' => ['risk_sort', 'asc'],
        'score_desc' => ['score', 'desc'],
        'score_asc' => ['score', 'asc'],
        'spend_desc' => ['spend', 'desc'],
        'revenue_desc' => ['revenue', 'desc'],
        'ecpm_desc' => ['ecpm', 'desc'],
        'connect_asc' => ['connect_rate', 'asc'],
        'roi_desc' => ['roi', 'desc'],
    ];
    $riskRank = ['high' => 1, 'medium' => 2, 'low' => 3];
    $a['risk_sort'] = $riskRank[$a['risk']] ?? 9;
    $b['risk_sort'] = $riskRank[$b['risk']] ?? 9;
    [$key, $dir] = $map[$sort] ?? ['risk_sort', 'asc'];
    $cmp = ($a[$key] ?? 0) <=> ($b[$key] ?? 0);
    if ($cmp === 0) $cmp = tq_num($b['spend'] ?? 0) <=> tq_num($a['spend'] ?? 0);
    return $dir === 'desc' ? -$cmp : $cmp;
});

$qualityAvg = count($campaignRows) ? array_sum(array_column($campaignRows, 'score')) / count($campaignRows) : 0;
$highRiskCount = count(array_filter($campaignRows, fn($r) => $r['risk'] === 'high'));
$mediumRiskCount = count(array_filter($campaignRows, fn($r) => $r['risk'] === 'medium'));
$connectRateTotal = tq_pct($totalSessions, $totalClicks);
$fillRateTotal = tq_pct($totalGamImpressions, $totalGamRequests);
$roiTotal = $totalSpend > 0 ? (($totalRevenue - $totalSpend) / $totalSpend) * 100 : 0;

$bestCampaigns = array_values(array_filter($campaignRows, fn($r) => $r['score'] >= 65 && tq_num($r['revenue']) > 0));
usort($bestCampaigns, fn($a, $b) => ($b['score'] <=> $a['score']) ?: (tq_num($b['revenue']) <=> tq_num($a['revenue'])));
$bestCampaigns = array_slice($bestCampaigns, 0, 8);
$worstCampaigns = array_slice($campaignRows, 0, 8);

$sourceRows = [];
try {
    $sourceRows = fetchAll("
        SELECT utm_source, SUM(sessions) as sessions
        FROM ga4_sessions
        WHERE date BETWEEN ? AND ?
        GROUP BY utm_source
        ORDER BY sessions DESC
    ", [$dateFrom, $dateTo]);
} catch (Exception $e) {
    $sourceRows = [];
}

$placementRows = [];
try {
    $placementWhere = "WHERE fp.date BETWEEN ? AND ?";
    $placementParams = [$dateFrom, $dateTo];
    if ($account !== '') {
        $placementWhere .= " AND fp.account_name = ?";
        $placementParams[] = $account;
    }
    if ($search !== '') {
        $placementWhere .= " AND (fp.campaign_name LIKE ? OR fp.campaign_id LIKE ? OR fp.placement LIKE ?)";
        $placementParams[] = "%{$search}%";
        $placementParams[] = "%{$search}%";
        $placementParams[] = "%{$search}%";
    }

    $placementRows = fetchAll("
        SELECT
            fp.placement as utm_content,
            SUM(fp.spend) as spend,
            SUM(fp.impressions) as impressions,
            SUM(fp.clicks) as clicks,
            COALESCE(SUM(rp.receita_usd), 0) * {$cotacao} as revenue,
            COALESCE(SUM(rp.gam_impressions), 0) as gam_impressions
        FROM fb_placements fp
        LEFT JOIN revenue_placements rp
               ON rp.date = fp.date
              AND rp.campaign_id = fp.campaign_id
              AND rp.placement = fp.placement
        {$placementWhere}
        GROUP BY fp.placement
        HAVING SUM(fp.spend) > 0 OR COALESCE(SUM(rp.receita_usd), 0) > 0
        ORDER BY spend DESC
        LIMIT 20
    ", $placementParams);
} catch (Exception $e) {
    $placementRows = [];
}

foreach ($placementRows as &$row) {
    $row['ctr'] = tq_pct($row['clicks'] ?? 0, $row['impressions'] ?? 0);
    $row['ecpm'] = tq_num($row['gam_impressions'] ?? 0) > 0 ? (tq_num($row['revenue']) / tq_num($row['gam_impressions'])) * 1000 : 0;
    $row['roi'] = tq_num($row['spend']) > 0 ? ((tq_num($row['revenue']) - tq_num($row['spend'])) / tq_num($row['spend'])) * 100 : 0;
}
unset($row);

$deviceRows = [];
try {
    $deviceWhere = "WHERE date BETWEEN ? AND ?";
    $deviceParams = [$dateFrom, $dateTo];
    if ($account !== '') {
        $deviceWhere .= " AND account_name = ?";
        $deviceParams[] = $account;
    }
    if ($search !== '') {
        $deviceWhere .= " AND (campaign_name LIKE ? OR campaign_id LIKE ? OR device_os LIKE ?)";
        $deviceParams[] = "%{$search}%";
        $deviceParams[] = "%{$search}%";
        $deviceParams[] = "%{$search}%";
    }

    $deviceRows = fetchAll("
        SELECT device_os, SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks
        FROM fb_devices
        {$deviceWhere}
        GROUP BY device_os
        HAVING SUM(spend) > 0 OR SUM(clicks) > 0
        ORDER BY spend DESC
        LIMIT 12
    ", $deviceParams);
} catch (Exception $e) {
    $deviceRows = [];
}
foreach ($deviceRows as &$row) {
    $row['ctr'] = tq_pct($row['clicks'] ?? 0, $row['impressions'] ?? 0);
    $row['cpc'] = tq_safe_div($row['spend'] ?? 0, $row['clicks'] ?? 0);
}
unset($row);

$hourRows = [];
try {
    $hourWhere = "WHERE date BETWEEN ? AND ?";
    $hourParams = [$dateFrom, $dateTo];
    if ($account !== '') {
        $hourWhere .= " AND account_name = ?";
        $hourParams[] = $account;
    }
    if ($search !== '') {
        $hourWhere .= " AND (campaign_name LIKE ? OR campaign_id LIKE ?)";
        $hourParams[] = "%{$search}%";
        $hourParams[] = "%{$search}%";
    }

    $hourRows = fetchAll("
        SELECT hour_start, MIN(hour_label) as hour_label,
               SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(results) as results
        FROM fb_hourly
        {$hourWhere}
        GROUP BY hour_start
        ORDER BY hour_start
    ", $hourParams);
} catch (Exception $e) {
    $hourRows = [];
}
foreach ($hourRows as &$row) {
    $row['ctr'] = tq_pct($row['clicks'] ?? 0, $row['impressions'] ?? 0);
    $row['cpc'] = tq_safe_div($row['spend'] ?? 0, $row['clicks'] ?? 0);
    $row['cost_result'] = tq_safe_div($row['spend'] ?? 0, $row['results'] ?? 0);
}
unset($row);

$programmatic = [];
try {
    $programmatic = fetchOne("
        SELECT
            COALESCE(SUM(revenue_usd), 0) as revenue_usd,
            COALESCE(SUM(impressions), 0) as impressions,
            COALESCE(SUM(ad_requests), 0) as ad_requests,
            COALESCE(AVG(match_rate), 0) as match_rate,
            COALESCE(AVG(active_view_pct), 0) as active_view_pct,
            COALESCE(AVG(bounce_rate), 0) as bounce_rate,
            COALESCE(AVG(avg_engagement_time), 0) as avg_engagement_time,
            COALESCE(SUM(sessions), 0) as sessions
        FROM receita_programatica
        WHERE date BETWEEN ? AND ?
    ", [$dateFrom, $dateTo]) ?: [];
} catch (Exception $e) {
    $programmatic = [];
}

function tq_url($overrides = []) {
    $params = array_merge($_GET, ['page' => 'traffic-quality'], $overrides);
    return '?' . http_build_query($params);
}
?>

<?php
$currentPage = 'traffic-quality';
ob_start();
?>
<select class="filter-input" style="max-width:180px;min-height:34px;padding:6px 10px;font-size:12px;" onchange="var p=new URLSearchParams(location.search);p.set('account',this.value);p.set('page','traffic-quality');location.href='?'+p.toString()">
    <option value="">Todas as contas</option>
    <?php foreach ($accounts as $acc): ?>
    <option value="<?= sanitize($acc['account_name']) ?>" <?= $account === $acc['account_name'] ? 'selected' : '' ?>><?= sanitize($acc['account_name']) ?></option>
    <?php endforeach; ?>
</select>
<input type="text" class="filter-input" style="max-width:220px;min-height:34px;padding:6px 10px;font-size:12px;" placeholder="Buscar campanha..." value="<?= sanitize($search) ?>"
       onkeydown="if(event.key==='Enter'){var p=new URLSearchParams(location.search);p.set('page','traffic-quality');p.set('search',this.value);location.href='?'+p.toString();}">
<select class="filter-input" style="max-width:180px;min-height:34px;padding:6px 10px;font-size:12px;" onchange="var p=new URLSearchParams(location.search);p.set('page','traffic-quality');p.set('sort',this.value);location.href='?'+p.toString()">
    <option value="risk_desc" <?= $sort === 'risk_desc' ? 'selected' : '' ?>>Maior risco</option>
    <option value="score_desc" <?= $sort === 'score_desc' ? 'selected' : '' ?>>Maior qualidade</option>
    <option value="score_asc" <?= $sort === 'score_asc' ? 'selected' : '' ?>>Menor qualidade</option>
    <option value="spend_desc" <?= $sort === 'spend_desc' ? 'selected' : '' ?>>Maior custo</option>
    <option value="revenue_desc" <?= $sort === 'revenue_desc' ? 'selected' : '' ?>>Maior revenue</option>
    <option value="connect_asc" <?= $sort === 'connect_asc' ? 'selected' : '' ?>>Menor connect rate</option>
    <option value="ecpm_desc" <?= $sort === 'ecpm_desc' ? 'selected' : '' ?>>Maior eCPM</option>
</select>
<button class="btn btn-primary btn-sm" id="btnTqSync" onclick="syncTrafficQuality()">Sync dados</button>
<button class="btn-export" onclick="exportTableToCSV('trafficQualityTable', 'qualidade_trafego_<?= $dateFrom ?>_<?= $dateTo ?>')">Exportar CSV</button>
<span id="tqSyncStatus" style="font-size:12px;color:var(--text-muted);"></span>
<?php $dateFilterExtra = ob_get_clean(); ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<div class="tq-note">
    <strong>significado.digital</strong> - esta aba usa apenas dados locais ja sincronizados. Nenhum script foi instalado no WordPress e nada altera SpunMidia, GPT, iframes, preloader, cache, layout ou SEO.
</div>

<div class="cards-grid">
    <div class="card <?= $qualityAvg >= 70 ? 'card-green' : ($qualityAvg >= 55 ? 'card-yellow' : 'card-red') ?>">
        <div class="card-label">Score medio de qualidade</div>
        <div class="card-value"><?= formatNumber($qualityAvg, 0) ?>/100</div>
        <div class="card-change"><?= count($campaignRows) ?> campanhas analisadas</div>
    </div>
    <div class="card <?= $highRiskCount > 0 ? 'card-red' : 'card-green' ?>">
        <div class="card-label">Risco IVT</div>
        <div class="card-value"><?= formatNumber($highRiskCount) ?></div>
        <div class="card-change"><?= formatNumber($mediumRiskCount) ?> em atencao</div>
    </div>
    <div class="card <?= $connectRateTotal >= 70 ? 'card-green' : ($connectRateTotal >= 55 ? 'card-purple' : 'card-red') ?>">
        <div class="card-label">Connect rate GA4/FB</div>
        <div class="card-value"><?= formatNumber($connectRateTotal, 1) ?>%</div>
        <div class="card-change"><?= formatNumber($totalSessions) ?> sessoes / <?= formatNumber($totalClicks) ?> cliques</div>
    </div>
    <div class="card card-blue">
        <div class="card-label">eCPM / Fill rate</div>
        <div class="card-value"><?= formatMoney($avgEcpm) ?></div>
        <div class="card-change">Fill: <?= $totalGamRequests > 0 ? formatNumber($fillRateTotal, 1) . '%' : '-' ?></div>
    </div>
</div>

<div class="tq-grid-2">
    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Melhores campanhas</span>
            <span class="badge badge-green">score + revenue</span>
        </div>
        <div class="table-scroll">
            <table class="tq-small-table">
                <thead><tr><th>Campanha</th><th>Score</th><th>Revenue</th><th>RPS</th></tr></thead>
                <tbody>
                <?php if (empty($bestCampaigns)): ?>
                    <tr><td colspan="4" class="empty-state">Sem campanha positiva suficiente no periodo.</td></tr>
                <?php else: foreach ($bestCampaigns as $r): ?>
                    <tr>
                        <td title="<?= sanitize($r['campaign_name']) ?>"><?= sanitize(mb_substr($r['campaign_name'] ?: $r['campaign_id'], 0, 42)) ?></td>
                        <td><span class="tq-score tq-score-good"><?= (int)$r['score'] ?></span></td>
                        <td><?= formatMoney($r['revenue']) ?></td>
                        <td><?= $r['rps'] > 0 ? formatMoney($r['rps']) : '-' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-container">
        <div class="table-header">
            <span class="table-title">Piores sinais / IVT</span>
            <span class="badge badge-red">prioridade</span>
        </div>
        <div class="table-scroll">
            <table class="tq-small-table">
                <thead><tr><th>Campanha</th><th>Risco</th><th>Connect</th><th>Principal alerta</th></tr></thead>
                <tbody>
                <?php if (empty($worstCampaigns)): ?>
                    <tr><td colspan="4" class="empty-state">Sem dados suficientes.</td></tr>
                <?php else: foreach ($worstCampaigns as $r): $firstFlag = $r['flags'][0]['label'] ?? 'Sem alerta critico'; ?>
                    <tr>
                        <td title="<?= sanitize($r['campaign_name']) ?>"><?= sanitize(mb_substr($r['campaign_name'] ?: $r['campaign_id'], 0, 34)) ?></td>
                        <td><span class="badge <?= tq_badge_class($r['risk']) ?>"><?= tq_level_label($r['risk']) ?></span></td>
                        <td><?= $r['connect_rate'] > 0 ? formatNumber($r['connect_rate'], 1) . '%' : '-' ?></td>
                        <td><?= sanitize($firstFlag) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Ranking de qualidade por campanha / UTM campaign</span>
        <div class="table-actions">
            <span class="badge badge-blue"><?= count($campaignRows) ?> linhas</span>
            <span class="badge <?= $roiTotal >= 0 ? 'badge-green' : 'badge-red' ?>">ROI geral: <?= formatNumber($roiTotal, 1) ?>%</span>
        </div>
    </div>
    <div class="table-scroll">
        <table id="trafficQualityTable" class="tq-table">
            <thead>
                <tr>
                    <th>Risco</th>
                    <th>Score</th>
                    <th>Campanha</th>
                    <th>Custo</th>
                    <th>Revenue</th>
                    <th>ROI</th>
                    <th>Cliques</th>
                    <th>Sessoes GA4</th>
                    <th>Connect</th>
                    <th>LP rate</th>
                    <th>CTR FB</th>
                    <th>eCPM</th>
                    <th>Fill</th>
                    <th>RPS</th>
                    <th>Spread</th>
                    <th>Alertas</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($campaignRows)): ?>
                <tr><td colspan="16" class="empty-state" style="padding:40px;">Nenhum dado encontrado. Sincronize FB, GAM e GA4.</td></tr>
            <?php else: foreach ($campaignRows as $r): ?>
                <tr class="tq-risk-<?= sanitize($r['risk']) ?>">
                    <td><span class="badge <?= tq_badge_class($r['risk']) ?>"><?= tq_level_label($r['risk']) ?></span></td>
                    <td><span class="tq-score <?= $r['score'] >= 70 ? 'tq-score-good' : ($r['score'] >= 55 ? 'tq-score-mid' : 'tq-score-bad') ?>"><?= (int)$r['score'] ?></span></td>
                    <td title="<?= sanitize($r['campaign_name']) ?>&#10;ID: <?= sanitize($r['campaign_id']) ?>">
                        <strong><?= sanitize(mb_substr($r['campaign_name'] ?: $r['campaign_id'], 0, 48)) ?></strong>
                        <div class="tq-muted"><?= sanitize($r['campaign_id']) ?></div>
                    </td>
                    <td><?= formatMoney($r['spend']) ?></td>
                    <td><?= formatMoney($r['revenue']) ?></td>
                    <td class="<?= $r['roi'] >= 0 ? 'positive' : 'negative' ?>"><?= formatNumber($r['roi'], 1) ?>%</td>
                    <td><?= formatNumber($r['clicks']) ?></td>
                    <td><?= $r['ga4_sessions'] > 0 ? formatNumber($r['ga4_sessions']) : '-' ?></td>
                    <td class="<?= $r['connect_rate'] >= 70 ? 'positive' : ($r['connect_rate'] > 0 && $r['connect_rate'] < 50 ? 'negative' : '') ?>"><?= $r['connect_rate'] > 0 ? formatNumber($r['connect_rate'], 1) . '%' : '-' ?></td>
                    <td><?= $r['lp_rate'] > 0 ? formatNumber($r['lp_rate'], 1) . '%' : '-' ?></td>
                    <td><?= $r['ctr'] > 0 ? formatNumber($r['ctr'], 2) . '%' : '-' ?></td>
                    <td><?= $r['ecpm'] > 0 ? formatMoney($r['ecpm']) : '-' ?></td>
                    <td><?= $r['fill_rate'] > 0 ? formatNumber($r['fill_rate'], 1) . '%' : '-' ?></td>
                    <td><?= $r['rps'] > 0 ? formatMoney($r['rps']) : '-' ?></td>
                    <td class="<?= $r['spread'] >= 0 ? 'positive' : 'negative' ?>"><?= $r['ga4_sessions'] > 0 ? formatMoney($r['spread']) : '-' ?></td>
                    <td>
                        <?php if (empty($r['flags'])): ?>
                            <span class="tq-muted">Sem alerta critico</span>
                        <?php else: foreach (array_slice($r['flags'], 0, 3) as $flag): ?>
                            <span class="tq-flag tq-flag-<?= sanitize($flag['level']) ?>"><?= sanitize($flag['label']) ?></span>
                        <?php endforeach; endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="tq-grid-2">
    <div class="table-container">
        <div class="table-header"><span class="table-title">Origem / UTM source</span></div>
        <div class="table-scroll">
            <table class="tq-small-table">
                <thead><tr><th>utm_source</th><th>Sessoes GA4</th><th>Observacao</th></tr></thead>
                <tbody>
                <?php if (empty($sourceRows)): ?>
                    <tr><td colspan="3" class="empty-state">GA4 ainda nao trouxe source por sessao.</td></tr>
                <?php else: foreach ($sourceRows as $r): ?>
                    <tr>
                        <td><?= sanitize($r['utm_source'] ?: '(vazio)') ?></td>
                        <td><?= formatNumber($r['sessions']) ?></td>
                        <td><?= ($r['utm_source'] ?? '') === 'facebook' ? 'Fonte principal de compra' : 'Verificar origem' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-container">
        <div class="table-header"><span class="table-title">UTM content / placement</span></div>
        <div class="table-scroll">
            <table class="tq-small-table">
                <thead><tr><th>Placement</th><th>Custo</th><th>CTR</th><th>Revenue</th><th>ROI</th></tr></thead>
                <tbody>
                <?php if (empty($placementRows)): ?>
                    <tr><td colspan="5" class="empty-state">Sem dados de placement. Rode Sync FB/GAM.</td></tr>
                <?php else: foreach ($placementRows as $r): ?>
                    <tr>
                        <td><?= sanitize($r['utm_content'] ?: 'Sem placement') ?></td>
                        <td><?= formatMoney($r['spend']) ?></td>
                        <td><?= $r['ctr'] > 0 ? formatNumber($r['ctr'], 2) . '%' : '-' ?></td>
                        <td><?= $r['revenue'] > 0 ? formatMoney($r['revenue']) : '-' ?></td>
                        <td class="<?= $r['roi'] >= 0 ? 'positive' : 'negative' ?>"><?= $r['spend'] > 0 ? formatNumber($r['roi'], 1) . '%' : '-' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="tq-grid-2">
    <div class="table-container">
        <div class="table-header"><span class="table-title">Dia da semana</span></div>
        <div class="table-scroll">
            <table class="tq-small-table">
                <thead><tr><th>Dia</th><th>Dias</th><th>Custo</th><th>Revenue</th><th>ROI</th><th>Connect</th></tr></thead>
                <tbody>
                <?php if (empty($weekdayRows)): ?>
                    <tr><td colspan="6" class="empty-state">Sem dados diarios no periodo.</td></tr>
                <?php else: foreach ($weekdayRows as $r): ?>
                    <tr>
                        <td><?= sanitize($r['day_name']) ?></td>
                        <td><?= formatNumber($r['days']) ?></td>
                        <td><?= formatMoney($r['spend']) ?></td>
                        <td><?= formatMoney($r['revenue']) ?></td>
                        <td class="<?= $r['roi'] >= 0 ? 'positive' : 'negative' ?>"><?= $r['spend'] > 0 ? formatNumber($r['roi'], 1) . '%' : '-' ?></td>
                        <td><?= $r['connect_rate'] > 0 ? formatNumber($r['connect_rate'], 1) . '%' : '-' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-container">
        <div class="table-header"><span class="table-title">Sinais programaticos diarios</span></div>
        <div class="table-scroll">
            <table class="tq-small-table">
                <thead><tr><th>Metrica</th><th>Valor</th><th>Status</th></tr></thead>
                <tbody>
                    <tr><td>Active View / viewability</td><td><?= tq_num($programmatic['active_view_pct'] ?? 0) > 0 ? formatPercentRaw(tq_num($programmatic['active_view_pct']) * 100) : '-' ?></td><td><?= tq_num($programmatic['active_view_pct'] ?? 0) > 0 ? 'Importado diario' : 'Nao granular' ?></td></tr>
                    <tr><td>Match rate</td><td><?= tq_num($programmatic['match_rate'] ?? 0) > 0 ? formatPercentRaw(tq_num($programmatic['match_rate']) * 100) : '-' ?></td><td><?= tq_num($programmatic['match_rate'] ?? 0) > 0 ? 'Importado diario' : 'Nao granular' ?></td></tr>
                    <tr><td>Bounce rate</td><td><?= tq_num($programmatic['bounce_rate'] ?? 0) > 0 ? formatPercentRaw(tq_num($programmatic['bounce_rate']) * 100) : '-' ?></td><td><?= tq_num($programmatic['bounce_rate'] ?? 0) > 0 ? 'Importado diario' : 'Falta GA4 por campanha/URL' ?></td></tr>
                    <tr><td>Tempo engajado medio</td><td><?= tq_num($programmatic['avg_engagement_time'] ?? 0) > 0 ? formatNumber($programmatic['avg_engagement_time'], 1) . 's' : '-' ?></td><td><?= tq_num($programmatic['avg_engagement_time'] ?? 0) > 0 ? 'Importado diario' : 'Falta GA4 por campanha/URL' ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="tq-grid-2">
    <div class="table-container">
        <div class="table-header"><span class="table-title">Dispositivo / sistema disponivel</span></div>
        <div class="table-scroll">
            <table class="tq-small-table">
                <thead><tr><th>Device OS FB</th><th>Custo</th><th>Cliques</th><th>CTR</th><th>CPC</th></tr></thead>
                <tbody>
                <?php if (empty($deviceRows)): ?>
                    <tr><td colspan="5" class="empty-state">Sem breakdown de dispositivo sincronizado.</td></tr>
                <?php else: foreach ($deviceRows as $r): ?>
                    <tr>
                        <td><?= sanitize($r['device_os'] ?: 'Unknown') ?></td>
                        <td><?= formatMoney($r['spend']) ?></td>
                        <td><?= formatNumber($r['clicks']) ?></td>
                        <td><?= $r['ctr'] > 0 ? formatNumber($r['ctr'], 2) . '%' : '-' ?></td>
                        <td><?= $r['cpc'] > 0 ? formatMoney($r['cpc']) : '-' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-container">
        <div class="table-header"><span class="table-title">Qualidade por horario</span></div>
        <div class="table-scroll">
            <table class="tq-small-table">
                <thead><tr><th>Hora</th><th>Custo</th><th>Cliques</th><th>CTR</th><th>Custo/result.</th></tr></thead>
                <tbody>
                <?php if (empty($hourRows)): ?>
                    <tr><td colspan="5" class="empty-state">Sem breakdown por hora. Rode Sync FB.</td></tr>
                <?php else: foreach ($hourRows as $r): ?>
                    <tr>
                        <td><?= sanitize($r['hour_label'] ?: sprintf('%02d:00', (int)$r['hour_start'])) ?></td>
                        <td><?= formatMoney($r['spend']) ?></td>
                        <td><?= formatNumber($r['clicks']) ?></td>
                        <td><?= $r['ctr'] > 0 ? formatNumber($r['ctr'], 2) . '%' : '-' ?></td>
                        <td><?= $r['cost_result'] > 0 ? formatMoney($r['cost_result']) : '-' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Cobertura dos dados e proximos passos seguros</span>
    </div>
    <div class="table-scroll">
        <table class="tq-table">
            <thead><tr><th>Necessidade</th><th>Status atual</th><th>Fonte preferida</th><th>Acao segura</th></tr></thead>
            <tbody>
                <tr><td>utm_source / utm_campaign</td><td><span class="badge badge-green">Disponivel</span></td><td>GA4 + Facebook</td><td>Ja usado no score por campanha.</td></tr>
                <tr><td>utm_medium / utm_term / URL</td><td><span class="badge badge-yellow">Parcial</span></td><td>GA4 Data API</td><td>Proxima evolucao: sincronizar landingPagePlusQueryString e UTM completas para tabela local.</td></tr>
                <tr><td>utm_content / criativo</td><td><span class="badge badge-yellow">Placement disponivel</span></td><td>Meta Ads + GAM</td><td>Para criativo real, precisa breakdown por ad_id/ad_name ou UTM com creative id.</td></tr>
                <tr><td>Bounce / tempo engajado</td><td><span class="badge <?= !empty($programmatic) && tq_num($programmatic['sessions'] ?? 0) > 0 ? 'badge-yellow' : 'badge-red' ?>"><?= !empty($programmatic) && tq_num($programmatic['sessions'] ?? 0) > 0 ? 'Diario importado' : 'Faltando granularidade' ?></span></td><td>GA4</td><td>Puxar engagementRate, bounceRate e averageSessionDuration por campanha/URL.</td></tr>
                <tr><td>Scroll, CTA, funil P1/P2</td><td><span class="badge badge-red">Nao armazenado</span></td><td>GTM/GA4 events</td><td>Criar eventos leves no GTM antes de qualquer script proprio.</td></tr>
                <tr><td>Viewability / fill / eCPM</td><td><span class="badge badge-yellow">Parcial</span></td><td>GAM/AdX/Spun reports</td><td>Fill/eCPM por campanha ja entram; viewability fina depende de relatorio GAM/Spun.</td></tr>
                <tr><td>Browser, pais, cidade</td><td><span class="badge badge-red">Nao armazenado</span></td><td>GA4</td><td>Sincronizar dimensoes de device/geografia do GA4 sem coletar PII.</td></tr>
                <tr><td>Script WordPress</td><td><span class="badge badge-green">Nao alterado</span></td><td>GTM/GA4</td><td>So implementar apos confirmacao. Deve ser async, sem PII e sem tocar em Spun/GPT/preloader.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="tq-note tq-note-soft">
    <strong>Resumo tecnico:</strong> esta versao nao cria fingerprint, nao coleta dados pessoais, nao acessa conteudo de iframe de anuncio e nao injeta codigo no site publico. Para medir scroll_25/50/75/90, engaged_10s/30s/60s, click_cta, p1_to_p2_click e eventos de preloader, o caminho recomendado e GTM/GA4 com eventos assicronos via dataLayer. So vale criar script proprio se esses eventos nao existirem no GTM.
</div>

<script>
async function syncTrafficQuality() {
    const btn = document.getElementById('btnTqSync');
    const status = document.getElementById('tqSyncStatus');
    btn.disabled = true;
    btn.textContent = 'Sincronizando...';
    status.textContent = 'FB...';
    try {
        const fb = await fetch('api/sync.php?action=sync_fb');
        const fbData = await fb.json();
        status.textContent = 'GAM...';
        const gam = await fetch('api/sync.php?action=sync_gam');
        const gamData = await gam.json();
        status.textContent = 'GA4...';
        const ga4 = await fetch('api/sync.php?action=sync_ga4');
        const ga4Data = await ga4.json();
        const ok = (fbData.success || !fbData.error) && (gamData.success || !gamData.error) && (ga4Data.success || !ga4Data.error);
        status.style.color = ok ? '#30d158' : '#ffd60a';
        status.textContent = `FB ${fbData.imported || 0} | GAM ${gamData.imported || 0} | GA4 ${ga4Data.imported || 0}`;
        setTimeout(() => location.reload(), 1400);
    } catch (e) {
        status.style.color = '#ff453a';
        status.textContent = 'Erro: ' + e.message;
    } finally {
        btn.disabled = false;
        btn.textContent = 'Sync dados';
    }
}
</script>

<style>
.tq-note {
    margin: 0 0 14px;
    padding: 12px 14px;
    border: 1px solid rgba(100, 210, 255, 0.24);
    border-left: 3px solid #64d2ff;
    border-radius: 8px;
    background: rgba(100, 210, 255, 0.07);
    color: var(--text-primary);
    font-size: 12px;
    line-height: 1.5;
}
.tq-note-soft {
    margin-top: 16px;
    border-color: rgba(255, 214, 10, 0.26);
    border-left-color: #ffd60a;
    background: rgba(255, 214, 10, 0.06);
}
.tq-grid-2 {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 16px;
    margin-top: 16px;
}
.tq-table th,
.tq-table td,
.tq-small-table th,
.tq-small-table td {
    font-size: 11.5px;
    padding: 9px 8px;
    white-space: nowrap;
    vertical-align: middle;
}
.tq-table td:last-child {
    white-space: normal;
    min-width: 220px;
}
.tq-muted {
    color: var(--text-muted);
    font-size: 10px;
    margin-top: 2px;
}
.tq-score {
    display: inline-flex;
    min-width: 34px;
    height: 26px;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    font-weight: 800;
    font-size: 12px;
}
.tq-score-good { color: #061b10; background: #30d158; }
.tq-score-mid { color: #201600; background: #ffd60a; }
.tq-score-bad { color: #fff; background: #ff453a; }
.tq-risk-high { background: rgba(255, 69, 58, 0.08); }
.tq-risk-medium { background: rgba(255, 214, 10, 0.06); }
.tq-flag {
    display: inline-flex;
    align-items: center;
    margin: 2px 4px 2px 0;
    padding: 4px 7px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 700;
}
.tq-flag-high { color: #ffd7d4; background: rgba(255, 69, 58, 0.18); border: 1px solid rgba(255, 69, 58, 0.28); }
.tq-flag-medium { color: #ffe89a; background: rgba(255, 214, 10, 0.14); border: 1px solid rgba(255, 214, 10, 0.24); }
@media (max-width: 980px) {
    .tq-grid-2 { grid-template-columns: 1fr; }
}
</style>
