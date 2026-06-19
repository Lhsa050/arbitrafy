<?php
/**
 * Análise por Placement — Agrupado por Campanha
 * Cruza custo REAL do FB (por placement) com receita do GAM
 * Usa tabela `revenue` (totais corretos) e distribui por placement proporcionalmente
 * Normaliza nomes de placement para evitar duplicatas FB vs GAM
 */

$cotacao = getCotacaoDolar();
ensurePlacementsTable();
ensureFBPlacementsTable();

list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();
$sort = $_GET['sort'] ?? 'rev_desc';

/**
 * Normalize placement name to canonical form.
 * Merges variants like Instagram_Instagram_Stories → Instagram_Stories
 */
function normalizePlacement($name) {
    // Canonical mapping — all known variants → standard name
    $canonical = [
        'Facebook_Mobile_Feed' => 'Facebook_Mobile_Feed',
        'Facebook_Feed' => 'Facebook_Mobile_Feed',
        'Facebook_Instream_Vid' => 'Facebook_Instream_Vid',
        'Facebook_Instream_Video' => 'Facebook_Instream_Vid',
        'Facebook_Mobile_Reels' => 'Facebook_Mobile_Reels',
        'Facebook_Reels' => 'Facebook_Mobile_Reels',
        'Facebook_Facebook_Reels' => 'Facebook_Mobile_Reels',
        'Facebook_Right_Column' => 'Facebook_Right_Column',
        'Facebook_Marketplace' => 'Facebook_Marketplace',
        'Facebook_Stories' => 'Facebook_Stories',
        'Facebook_Facebook_Stories' => 'Facebook_Stories',
        'Facebook_Search' => 'Facebook_Search',
        'Facebook_Search_Results' => 'Facebook_Search',
        'Facebook_Home' => 'Facebook_Mobile_Feed',
        'Facebook_Video_Feeds' => 'Facebook_Instream_Vid',
        'Facebook_Instant_Articles' => 'Facebook_Instant_Articles',
        'Instagram_Feed' => 'Instagram_Feed',
        'Instagram_Stream' => 'Instagram_Feed',
        'Instagram_Profile_Feed' => 'Instagram_Feed',
        'Instagram_Stories' => 'Instagram_Stories',
        'Instagram_Story' => 'Instagram_Stories',
        'Instagram_Instagram_Stories' => 'Instagram_Stories',
        'Instagram_Reels' => 'Instagram_Reels',
        'Instagram_Ig_Reels' => 'Instagram_Reels',
        'Instagram_Instagram_Reels' => 'Instagram_Reels',
        'Instagram_Reels_Overlay' => 'Instagram_Reels',
        'Instagram_Explore' => 'Instagram_Explore',
        'Instagram_Explore_Home' => 'Instagram_Explore',
        'Instagram_Instagram_Search' => 'Instagram_Explore',
        'Instagram_Shop' => 'Instagram_Shop',
        'Audience_Network' => 'Audience_Network',
        'Audience_Network_Classic' => 'Audience_Network',
        'Audience_Network_Rewarded' => 'Audience_Network_Rewarded',
        'Audience_Network_Rewarded_Video' => 'Audience_Network_Rewarded',
        'Audience_Network_Instream_Video' => 'Audience_Network',
        'Messenger_Inbox' => 'Messenger_Inbox',
        'Messenger_Stories' => 'Messenger_Stories',
        'Messenger_Messenger_Stories' => 'Messenger_Stories',
        'Messenger_Sponsored_Messages' => 'Messenger_Inbox',
    ];
    
    if (isset($canonical[$name])) return $canonical[$name];
    
    // Auto-fix common pattern: Platform_Platform_Position → Platform_Position
    if (preg_match('/^(Facebook|Instagram|Messenger|Audience_Network)_\1_(.+)$/i', $name, $m)) {
        $fixed = $m[1] . '_' . $m[2];
        if (isset($canonical[$fixed])) return $canonical[$fixed];
        return $fixed;
    }
    
    return $name;
}

// ==========================================
// Detail by Campaign + Placement (no filters)
// ==========================================

// 1) Real revenue per campaign from `revenue` table (same source as Dashboard)
$realRevData = fetchAll("
    SELECT
        campaign_id,
        SUM(receita_usd) * {$cotacao} as real_revenue_brl,
        SUM(gam_impressions) as real_gam_impressions
    FROM revenue
    WHERE date BETWEEN ? AND ?
    GROUP BY campaign_id
", [$dateFrom, $dateTo]);
$realRevByCampaign = [];
foreach ($realRevData as $rr) {
    $realRevByCampaign[$rr['campaign_id']] = $rr;
}

// 2) Placement-level breakdown from `revenue_placements` (normalized)
$placementData = fetchAll("
    SELECT
        rp.placement,
        rp.campaign_id,
        SUM(rp.receita_usd) as raw_revenue_usd,
        SUM(rp.gam_impressions) as gam_impressions
    FROM revenue_placements rp
    WHERE rp.date BETWEEN ? AND ?
    GROUP BY rp.campaign_id, rp.placement
", [$dateFrom, $dateTo]);

// Group by campaign, merging normalized placement names
$placementsByCampaign = [];
foreach ($placementData as $pd) {
    $cid = $pd['campaign_id'];
    $norm = normalizePlacement($pd['placement']);
    if (!isset($placementsByCampaign[$cid])) {
        $placementsByCampaign[$cid] = ['total_raw_usd' => 0, 'placements' => []];
    }
    if (!isset($placementsByCampaign[$cid]['placements'][$norm])) {
        $placementsByCampaign[$cid]['placements'][$norm] = ['raw_revenue_usd' => 0, 'gam_impressions' => 0];
    }
    $placementsByCampaign[$cid]['placements'][$norm]['raw_revenue_usd'] += (float)$pd['raw_revenue_usd'];
    $placementsByCampaign[$cid]['placements'][$norm]['gam_impressions'] += (int)$pd['gam_impressions'];
    $placementsByCampaign[$cid]['total_raw_usd'] += (float)$pd['raw_revenue_usd'];
}

// Build scaled GAM map
$gamMap = [];
foreach ($placementsByCampaign as $cid => $campData) {
    $realRev = $realRevByCampaign[$cid]['real_revenue_brl'] ?? 0;
    $realImp = (int)($realRevByCampaign[$cid]['real_gam_impressions'] ?? 0);
    $totalRawUsd = $campData['total_raw_usd'];
    
    foreach ($campData['placements'] as $plName => $pd) {
        $rawUsd = $pd['raw_revenue_usd'];
        if ($totalRawUsd > 0) {
            $proportion = $rawUsd / $totalRawUsd;
            $scaledRev = (float)$realRev * $proportion;
            $scaledImp = (int)round($realImp * $proportion);
        } else {
            $scaledRev = 0;
            $scaledImp = $pd['gam_impressions'];
        }
        
        $key = $cid . '|' . $plName;
        $gamMap[$key] = [
            'campaign_id' => $cid,
            'placement' => $plName,
            'revenue' => $scaledRev,
            'gam_impressions' => $scaledImp,
        ];
    }
}

// 3) FB spend per campaign+placement (normalized + merged)
$fbData = fetchAll("
    SELECT
        fp.placement,
        fp.campaign_id,
        fp.campaign_name,
        SUM(fp.spend) as fb_spend,
        SUM(fp.impressions) as fb_impressions,
        SUM(fp.clicks) as fb_clicks
    FROM fb_placements fp
    WHERE fp.date BETWEEN ? AND ?
    GROUP BY fp.campaign_id, fp.placement
", [$dateFrom, $dateTo]);
$fbMap = [];
foreach ($fbData as $f) {
    $norm = normalizePlacement($f['placement']);
    $key = $f['campaign_id'] . '|' . $norm;
    if (!isset($fbMap[$key])) {
        $fbMap[$key] = $f;
        $fbMap[$key]['placement'] = $norm;
    } else {
        // Merge duplicates with same normalized name
        $fbMap[$key]['fb_spend'] = (float)$fbMap[$key]['fb_spend'] + (float)$f['fb_spend'];
        $fbMap[$key]['fb_impressions'] = (int)$fbMap[$key]['fb_impressions'] + (int)$f['fb_impressions'];
        $fbMap[$key]['fb_clicks'] = (int)$fbMap[$key]['fb_clicks'] + (int)$f['fb_clicks'];
    }
}

// Merge GAM + FB
$allKeys = array_unique(array_merge(array_keys($gamMap), array_keys($fbMap)));
$rows = [];
foreach ($allKeys as $key) {
    $gam = $gamMap[$key] ?? null;
    $fb = $fbMap[$key] ?? null;
    
    $campaignId = $gam['campaign_id'] ?? ($fb['campaign_id'] ?? '');
    $placement = $gam['placement'] ?? ($fb['placement'] ?? '');
    $rev = (float)($gam['revenue'] ?? 0);
    $imp = (int)($gam['gam_impressions'] ?? 0);
    $spend = (float)($fb['fb_spend'] ?? 0);
    $fbImp = (int)($fb['fb_impressions'] ?? 0);
    $fbClicks = (int)($fb['fb_clicks'] ?? 0);
    
    // Get campaign name
    $campName = $fb['campaign_name'] ?? '';
    if (empty($campName)) {
        $nameRow = fetchOne("SELECT campaign_name FROM fb_campaigns WHERE campaign_id = ? LIMIT 1", [$campaignId]);
        $campName = $nameRow['campaign_name'] ?? $campaignId;
    }
    
    $rows[] = [
        'placement' => $placement,
        'campaign_id' => $campaignId,
        'campaign_name' => $campName,
        'revenue' => $rev,
        'gam_impressions' => $imp,
        'ecpm' => $imp > 0 ? ($rev / $imp) * 1000 : 0,
        'fb_spend' => $spend,
        'fb_impressions' => $fbImp,
        'fb_clicks' => $fbClicks,
        'fb_cpc' => $fbClicks > 0 ? $spend / $fbClicks : 0,
        'lucro' => $rev - $spend,
        'roi' => $spend > 0 ? (($rev - $spend) / $spend) * 100 : 0,
        'has_gam' => $gam !== null,
        'has_fb' => $fb !== null,
    ];
}

// Sort within each campaign group
usort($rows, function($a, $b) use ($sort) {
    // Primary sort: campaign name
    $cmp = strcmp($a['campaign_name'], $b['campaign_name']);
    if ($cmp !== 0) return $cmp;
    // Secondary sort: by selected column
    $col = str_replace(['_desc', '_asc'], '', $sort);
    $dir = strpos($sort, '_asc') !== false ? 1 : -1;
    $map = ['rev' => 'revenue', 'placement' => 'placement', 'ecpm' => 'ecpm', 
            'custo' => 'fb_spend', 'roi' => 'roi', 'lucro' => 'lucro', 'imp' => 'gam_impressions'];
    $key = $map[$col] ?? 'revenue';
    if ($key === 'placement') return $dir * strcmp($a[$key], $b[$key]);
    return $dir * (($b[$key] ?? 0) <=> ($a[$key] ?? 0));
});

// Group rows by campaign
$campaigns = [];
foreach ($rows as $r) {
    $cid = $r['campaign_id'] ?: 'unknown';
    if (!isset($campaigns[$cid])) {
        $campaigns[$cid] = [
            'name' => $r['campaign_name'] ?: $r['campaign_id'],
            'id' => $r['campaign_id'],
            'rows' => [],
            'total_revenue' => 0,
            'total_spend' => 0,
        ];
    }
    $campaigns[$cid]['rows'][] = $r;
    $campaigns[$cid]['total_revenue'] += $r['revenue'];
    $campaigns[$cid]['total_spend'] += $r['fb_spend'];
}

// Sort campaigns by total revenue descending
uasort($campaigns, function($a, $b) {
    return $b['total_revenue'] <=> $a['total_revenue'];
});

// Global Totals
$totalRev = array_sum(array_column($rows, 'revenue'));
$totalSpend = array_sum(array_column($rows, 'fb_spend'));
$totalLucro = $totalRev - $totalSpend;
$totalROI = $totalSpend > 0 ? (($totalRev - $totalSpend) / $totalSpend) * 100 : 0;

function plUrl($overrides = []) {
    $params = array_merge($_GET, ['page' => 'placements'], $overrides);
    return '?' . http_build_query($params);
}
function plSortArrow($col, $current) {
    if ($current === $col . '_desc') return '↓';
    if ($current === $col . '_asc') return '↑';
    return '↕';
}
function plSortToggle($col, $current) {
    return $current === $col . '_desc' ? $col . '_asc' : $col . '_desc';
}

// Placement display names
function formatPlacement($name) {
    $map = [
        'Facebook_Mobile_Feed' => '📱 FB Mobile Feed',
        'Facebook_Instream_Vid' => '🎬 FB Instream Video',
        'Facebook_Mobile_Reels' => '🎞️ FB Mobile Reels',
        'Facebook_Feed' => '📰 FB Feed',
        'Facebook_Right_Column' => '📐 FB Right Column',
        'Facebook_Marketplace' => '🛒 FB Marketplace',
        'Facebook_Stories' => '📱 FB Stories',
        'Facebook_Search' => '🔍 FB Search',
        'Instagram_Feed' => '📷 IG Feed',
        'Instagram_Stories' => '📱 IG Stories',
        'Instagram_Reels' => '🎞️ IG Reels',
        'Instagram_Explore' => '🔍 IG Explore',
        'Instagram_Shop' => '🛍️ IG Shop',
        'Audience_Network' => '🌐 Audience Network',
        'Messenger_Inbox' => '💬 Messenger',
    ];
    return $map[$name] ?? str_replace('_', ' ', $name);
}
?>

<!-- Date Filter Bar + Sync Button -->
<?php
$currentPage = 'placements';
ob_start();
?>
<button class="btn btn-primary btn-sm" id="btnSyncPlacements" onclick="syncPlacements()">🔄 Sync</button>
<button class="btn-export" onclick="exportPlacementsToCSV('placements_<?= $dateFrom ?>_<?= $dateTo ?>')" title="Exportar dados filtrados para CSV">
    📥 Exportar CSV
</button>
<span id="syncPlStatus" style="font-size:12px;color:var(--text-muted);"></span>
<?php $dateFilterExtra = ob_get_clean(); ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<!-- Summary Cards -->
<div class="cards-grid">
    <div class="card card-blue">
        <div class="card-label">Revenue GAM</div>
        <div class="card-value"><?= formatMoney($totalRev) ?></div>
        <div class="card-sub"><?= count($campaigns) ?> campanhas · <?= count($rows) ?> placements</div>
    </div>
    <div class="card card-orange">
        <div class="card-label">Custo Facebook</div>
        <div class="card-value"><?= formatMoney($totalSpend) ?></div>
        <div class="card-sub">Gasto real por placement</div>
    </div>
    <div class="card <?= $totalLucro >= 0 ? 'card-green' : 'card-red' ?>">
        <div class="card-label">Lucro</div>
        <div class="card-value"><?= formatMoney($totalLucro) ?></div>
    </div>
    <div class="card <?= $totalROI >= 0 ? 'card-green' : 'card-red' ?>">
        <div class="card-label">ROI</div>
        <div class="card-value"><?= formatNumber($totalROI, 1) ?>%</div>
    </div>
</div>

<!-- Campaigns + Placements -->
<?php if (empty($campaigns)): ?>
<div class="card" style="margin-top:15px; padding:40px; text-align:center;">
    <div class="empty-state-icon">📍</div>
    <div class="empty-state-title">Nenhum dado de placement encontrado</div>
    <div class="empty-state-text">Sincronize o Facebook e o GAM para importar dados</div>
</div>
<?php else: ?>
<?php foreach ($campaigns as $cid => $camp): 
    $campLucro = $camp['total_revenue'] - $camp['total_spend'];
    $campROI = $camp['total_spend'] > 0 ? (($camp['total_revenue'] - $camp['total_spend']) / $camp['total_spend']) * 100 : 0;
?>
<div class="campaign-placement-card" style="margin-top:15px;">
    <!-- Campaign Header -->
    <div class="campaign-header" onclick="this.parentElement.classList.toggle('collapsed')">
        <div class="campaign-header-left">
            <span class="campaign-toggle-icon">▾</span>
            <div class="campaign-header-info">
                <span class="campaign-header-name"><?= htmlspecialchars($camp['name']) ?></span>
                <span class="campaign-header-meta"><?= count($camp['rows']) ?> placement<?= count($camp['rows']) > 1 ? 's' : '' ?></span>
            </div>
        </div>
        <div class="campaign-header-stats">
            <div class="campaign-stat">
                <span class="campaign-stat-label">Revenue</span>
                <span class="campaign-stat-value" style="color:#60a5fa;"><?= formatMoney($camp['total_revenue']) ?></span>
            </div>
            <div class="campaign-stat">
                <span class="campaign-stat-label">Custo</span>
                <span class="campaign-stat-value" style="color:#f59e0b;"><?= formatMoney($camp['total_spend']) ?></span>
            </div>
            <div class="campaign-stat">
                <span class="campaign-stat-label">Lucro</span>
                <span class="campaign-stat-value <?= $campLucro >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($campLucro) ?></span>
            </div>
            <div class="campaign-stat">
                <span class="campaign-stat-label">ROI</span>
                <span class="campaign-stat-value <?= $campROI >= 0 ? 'positive' : 'negative' ?>"><?= formatNumber($campROI, 1) ?>%</span>
            </div>
        </div>
    </div>

    <!-- Placements Table -->
    <div class="campaign-placements-body">
        <div class="table-scroll">
            <table class="placementsTable">
                <thead>
                    <tr>
                        <th><a href="<?= plUrl(['sort' => plSortToggle('placement', $sort)]) ?>" class="sort-link">Placement <?= plSortArrow('placement', $sort) ?></a></th>
                        <th class="hm-col hm-lower"><a href="<?= plUrl(['sort' => plSortToggle('custo', $sort)]) ?>" class="sort-link">Custo FB <?= plSortArrow('custo', $sort) ?></a><span class="th-help" title="Gasto REAL no Facebook neste placement">?</span></th>
                        <th class="hm-col hm-higher"><a href="<?= plUrl(['sort' => plSortToggle('rev', $sort)]) ?>" class="sort-link">Revenue GAM <?= plSortArrow('rev', $sort) ?></a><span class="th-help" title="Receita do GAM neste placement (via utm_content)">?</span></th>
                        <th class="hm-col hm-higher"><a href="<?= plUrl(['sort' => plSortToggle('ecpm', $sort)]) ?>" class="sort-link">eCPM <?= plSortArrow('ecpm', $sort) ?></a><span class="th-help" title="eCPM = (Revenue ÷ Imp. GAM) × 1000">?</span></th>
                        <th class="hm-col hm-higher"><a href="<?= plUrl(['sort' => plSortToggle('lucro', $sort)]) ?>" class="sort-link">Lucro <?= plSortArrow('lucro', $sort) ?></a><span class="th-help" title="Revenue GAM - Custo Facebook">?</span></th>
                        <th class="hm-col hm-higher"><a href="<?= plUrl(['sort' => plSortToggle('roi', $sort)]) ?>" class="sort-link">ROI <?= plSortArrow('roi', $sort) ?></a><span class="th-help" title="((Revenue - Custo) ÷ Custo) × 100">?</span></th>
                        <th>Imp. GAM<span class="th-help" title="Impressões no Ad Exchange do GAM">?</span></th>
                        <th>Cliques FB<span class="th-help" title="Cliques no link no Facebook">?</span></th>
                        <th>CPC FB<span class="th-help" title="Custo por clique no Facebook">?</span></th>
                        <th>Fonte<span class="th-help" title="🟢 = tem dados FB + GAM | 🔵 = só GAM | 🟠 = só FB">?</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($camp['rows'] as $r): ?>
                    <tr>
                        <td>
                            <span class="placement-badge"><?= formatPlacement($r['placement']) ?></span>
                            <span class="placement-raw"><?= htmlspecialchars($r['placement']) ?></span>
                        </td>
                        <td class="hm-cell" data-val="<?= (float)$r['fb_spend'] ?>" style="color:#f59e0b;"><?= $r['fb_spend'] > 0 ? formatMoney($r['fb_spend']) : '-' ?></td>
                        <td class="hm-cell" data-val="<?= (float)$r['revenue'] ?>"><?= $r['revenue'] > 0 ? formatMoney($r['revenue']) : '-' ?></td>
                        <td class="hm-cell" data-val="<?= (float)$r['ecpm'] ?>"><?= $r['ecpm'] > 0 ? formatMoney($r['ecpm']) : '-' ?></td>
                        <td class="hm-cell <?= $r['lucro'] >= 0 ? 'positive' : 'negative' ?>" data-val="<?= (float)$r['lucro'] ?>">
                            <?= ($r['fb_spend'] > 0 || $r['revenue'] > 0) ? formatMoney($r['lucro']) : '-' ?>
                        </td>
                        <td class="hm-cell <?= $r['roi'] >= 0 ? 'positive' : 'negative' ?>" data-val="<?= (float)$r['roi'] ?>">
                            <?= $r['fb_spend'] > 0 ? formatNumber($r['roi'], 1) . '%' : '-' ?>
                        </td>
                        <td><?= $r['gam_impressions'] > 0 ? formatNumber($r['gam_impressions']) : '-' ?></td>
                        <td><?= $r['fb_clicks'] > 0 ? formatNumber($r['fb_clicks']) : '-' ?></td>
                        <td><?= $r['fb_cpc'] > 0 ? formatMoney($r['fb_cpc']) : '-' ?></td>
                        <td>
                            <?php if ($r['has_gam'] && $r['has_fb']): ?>
                                <span title="Dados FB + GAM cruzados" style="font-size:14px;">🟢</span>
                            <?php elseif ($r['has_gam']): ?>
                                <span title="Só tem dados GAM (sem match no FB)" style="font-size:14px;">🔵</span>
                            <?php else: ?>
                                <span title="Só tem dados FB (sem match no GAM)" style="font-size:14px;">🟠</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Campaign Subtotal Row -->
                    <tr class="campaign-subtotal-row">
                        <td><strong>Total da Campanha</strong></td>
                        <td style="color:#f59e0b;"><strong><?= formatMoney($camp['total_spend']) ?></strong></td>
                        <td><strong><?= formatMoney($camp['total_revenue']) ?></strong></td>
                        <td>-</td>
                        <td class="<?= $campLucro >= 0 ? 'positive' : 'negative' ?>"><strong><?= formatMoney($campLucro) ?></strong></td>
                        <td class="<?= $campROI >= 0 ? 'positive' : 'negative' ?>"><strong><?= $camp['total_spend'] > 0 ? formatNumber($campROI, 1) . '%' : '-' ?></strong></td>
                        <td><strong><?= formatNumber(array_sum(array_column($camp['rows'], 'gam_impressions'))) ?></strong></td>
                        <td><strong><?= formatNumber(array_sum(array_column($camp['rows'], 'fb_clicks'))) ?></strong></td>
                        <td>-</td>
                        <td>-</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Heatmap JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const tables = document.querySelectorAll('.placementsTable');
    tables.forEach(table => {
        const thead = table.querySelector('thead tr');
        const headers = Array.from(thead.querySelectorAll('th'));
        const tbodyRows = Array.from(table.querySelectorAll('tbody tr:not(.campaign-subtotal-row)'));
        if (tbodyRows.length === 0) return;

        const colConfig = [];
        headers.forEach((th, i) => {
            if (th.classList.contains('hm-col')) {
                let direction = 'neutral';
                if (th.classList.contains('hm-higher')) direction = 'higher';
                if (th.classList.contains('hm-lower')) direction = 'lower';
                colConfig.push({ index: i, direction });
            }
        });

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
                let pct = (v - mn) / range;
                let r, g, b;
                if (cfg.direction === 'higher') {
                    if (pct < 0.5) { const t = pct * 2; r = 220; g = Math.round(60 + 160 * t); b = 60; }
                    else { const t = (pct - 0.5) * 2; r = Math.round(220 - 180 * t); g = 200; b = 60; }
                } else if (cfg.direction === 'lower') {
                    pct = 1 - pct;
                    if (pct < 0.5) { const t = pct * 2; r = 220; g = Math.round(60 + 160 * t); b = 60; }
                    else { const t = (pct - 0.5) * 2; r = Math.round(220 - 180 * t); g = 200; b = 60; }
                } else {
                    r = 40; g = 80; b = Math.round(120 + 120 * pct);
                }
                cell.style.backgroundColor = `rgba(${r}, ${g}, ${b}, 0.18)`;
            });
        });
    });
});

async function syncPlacements() {
    const btn = document.getElementById('btnSyncPlacements');
    const status = document.getElementById('syncPlStatus');
    btn.disabled = true;
    btn.textContent = '⏳ Sincronizando...';
    status.textContent = '';
    
    try {
        status.textContent = '⏳ Sincronizando Facebook...';
        const fbRes = await fetch('api/sync.php?action=sync_fb');
        const fbData = await fbRes.json();
        
        status.textContent = '⏳ Sincronizando GAM...';
        const gamRes = await fetch('api/sync.php?action=sync_gam');
        const gamData = await gamRes.json();
        
        const fbMsg = fbData.success ? `FB: ${fbData.imported} reg, ${fbData.placements || 0} pl` : 'FB: erro';
        const gamMsg = gamData.success ? `GAM: ${gamData.imported} reg` : 'GAM: erro';
        
        status.textContent = `✅ ${fbMsg} | ${gamMsg}`;
        status.style.color = '#10b981';
        
        setTimeout(() => location.reload(), 1500);
    } catch (e) {
        status.textContent = '❌ Erro: ' + e.message;
        status.style.color = '#ef4444';
    } finally {
        btn.disabled = false;
        btn.textContent = '🔄 Sync';
    }
}
</script>

<style>
.sort-link { color: var(--text-primary); text-decoration: none; white-space: nowrap; transition: color 0.2s; }
.sort-link:hover { color: var(--primary); }

.placement-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 600;
    background: rgba(99,102,241,0.1); color: #a5b4fc; white-space: nowrap;
}
.placement-raw {
    display: block; font-size: 9px; color: var(--text-muted, #666); margin-top: 2px;
    max-width: 200px; overflow: hidden; text-overflow: ellipsis;
}

.placementsTable th, .placementsTable td { font-size: 11.5px; padding: 8px 6px; white-space: nowrap; }

.th-help {
    display: inline-flex; align-items: center; justify-content: center;
    width: 14px; height: 14px; margin-left: 3px;
    font-size: 9px; font-weight: 700; font-style: normal;
    color: var(--text-muted, #666); background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.1); border-radius: 50%;
    cursor: help; vertical-align: middle;
    opacity: 0.5; transition: opacity 0.2s;
}
.th-help:hover {
    opacity: 1; color: var(--primary, #6366f1);
    border-color: var(--primary, #6366f1); background: rgba(99,102,241,0.1);
}

.hm-cell { transition: background-color 0.3s ease; }

/* Campaign Placement Cards */
.campaign-placement-card {
    background: var(--card-bg, #1e1e2e);
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.06);
    overflow: hidden;
    transition: all 0.3s ease;
}

.campaign-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    cursor: pointer;
    gap: 16px;
    background: rgba(255,255,255,0.02);
    border-bottom: 1px solid rgba(255,255,255,0.06);
    transition: background 0.2s;
}
.campaign-header:hover {
    background: rgba(99,102,241,0.06);
}

.campaign-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    flex: 1;
}

.campaign-toggle-icon {
    font-size: 16px;
    color: var(--text-muted, #888);
    transition: transform 0.3s ease;
    flex-shrink: 0;
}
.campaign-placement-card.collapsed .campaign-toggle-icon {
    transform: rotate(-90deg);
}

.campaign-header-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.campaign-header-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary, #e2e8f0);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.campaign-header-meta {
    font-size: 10px;
    color: var(--text-muted, #888);
}

.campaign-header-stats {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-shrink: 0;
}

.campaign-stat {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 1px;
}

.campaign-stat-label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted, #888);
}

.campaign-stat-value {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary, #e2e8f0);
}

.campaign-placements-body {
    transition: max-height 0.4s ease, opacity 0.3s ease;
    overflow: hidden;
}
.campaign-placement-card.collapsed .campaign-placements-body {
    max-height: 0 !important;
    opacity: 0;
    overflow: hidden;
}

/* Subtotal row */
.campaign-subtotal-row {
    background: rgba(255,255,255,0.03);
    border-top: 1px solid rgba(255,255,255,0.08);
}
.campaign-subtotal-row td {
    font-size: 11px !important;
    padding-top: 10px !important;
    padding-bottom: 10px !important;
}

/* Positive / Negative colors */
.positive { color: #34d399; }
.negative { color: #f87171; }

/* Responsive */
@media (max-width: 768px) {
    .campaign-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    .campaign-header-stats {
        width: 100%;
        justify-content: space-between;
    }
}
</style>
