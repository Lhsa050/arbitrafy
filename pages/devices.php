<?php
/**
 * Análise por Dispositivo de Impressão — Agrupado por Campanha
 * Mostra resultados por tipo de dispositivo (iPhone, Android, Desktop, etc) das campanhas do Facebook
 */

$cotacao = getCotacaoDolar();
ensureFBDevicesTable();

list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();
$sort = $_GET['sort'] ?? 'rev_desc';

// Map FB impression_device → GAM device_category
function fbDeviceToGamCategory($fbDevice) {
    $map = [
        'iphone' => 'Smartphone', 'android_smartphone' => 'Smartphone',
        'ipad' => 'Tablet', 'android_tablet' => 'Tablet', 'ipod' => 'Smartphone',
        'desktop' => 'Desktop',
    ];
    return $map[strtolower($fbDevice)] ?? 'Other';
}

// ============================================================
// Revenue source: try real GAM device data, fallback to proportional
// ============================================================
$useRealDeviceRev = false;
$gamDeviceRevMap = []; // [campaign_id][device_category] => rev_brl

// Try revenue_devices (real GAM data per device)
try {
    $gamDevData = fetchAll("
        SELECT campaign_id, device_category, SUM(receita_usd) * {$cotacao} as rev_brl
        FROM revenue_devices WHERE date BETWEEN ? AND ?
        GROUP BY campaign_id, device_category
    ", [$dateFrom, $dateTo]);
    foreach ($gamDevData as $g) {
        $gamDeviceRevMap[$g['campaign_id']][$g['device_category']] = (float)$g['rev_brl'];
    }
    if (!empty($gamDeviceRevMap)) $useRealDeviceRev = true;
} catch (Exception $e) {}

// Fallback: campaign-level revenue (proportional — ROI will be same per device)
$realRevByCampaign = [];
if (!$useRealDeviceRev) {
    $revData = fetchAll("
        SELECT campaign_id, SUM(receita_usd) * {$cotacao} as rev_brl
        FROM revenue WHERE date BETWEEN ? AND ? GROUP BY campaign_id
    ", [$dateFrom, $dateTo]);
    foreach ($revData as $rv) {
        $realRevByCampaign[$rv['campaign_id']] = (float)$rv['rev_brl'];
    }
}

// FB spend per campaign+device
$fbData = fetchAll("
    SELECT fd.device_os, fd.campaign_id, fd.campaign_name,
        SUM(fd.spend) as fb_spend, SUM(fd.impressions) as fb_impressions, SUM(fd.clicks) as fb_clicks
    FROM fb_devices fd WHERE fd.date BETWEEN ? AND ?
    GROUP BY fd.campaign_id, fd.device_os
", [$dateFrom, $dateTo]);

// Pre-calculate spend per campaign and per campaign+gamCategory
$spendByCampaign = [];
$spendByCampaignCat = []; // [campaign_id][gamCategory] => total_spend
foreach ($fbData as $f) {
    $cid = $f['campaign_id'];
    $cat = fbDeviceToGamCategory($f['device_os']);
    $s = (float)$f['fb_spend'];
    if (!isset($spendByCampaign[$cid])) $spendByCampaign[$cid] = 0;
    $spendByCampaign[$cid] += $s;
    if (!isset($spendByCampaignCat[$cid][$cat])) $spendByCampaignCat[$cid][$cat] = 0;
    $spendByCampaignCat[$cid][$cat] += $s;
}

// Build rows
$rows = [];
foreach ($fbData as $f) {
    $spend = (float)$f['fb_spend'];
    $imp = (int)$f['fb_impressions'];
    $clicks = (int)$f['fb_clicks'];
    $cid = $f['campaign_id'];
    $gamCat = fbDeviceToGamCategory($f['device_os']);

    if ($useRealDeviceRev) {
        // Real GAM revenue per device category — different ROI per device!
        $catTotalRev = $gamDeviceRevMap[$cid][$gamCat] ?? 0;
        $catTotalSpend = $spendByCampaignCat[$cid][$gamCat] ?? 0;
        // Within same GAM category, distribute proportionally by FB spend
        $proportion = $catTotalSpend > 0 ? $spend / $catTotalSpend : 0;
        $deviceRev = $catTotalRev * $proportion;
    } else {
        // Fallback: proportional from total campaign revenue (same ROI per device)
        $campRev = $realRevByCampaign[$cid] ?? 0;
        $campSpend = $spendByCampaign[$cid] ?? 0;
        $proportion = $campSpend > 0 ? $spend / $campSpend : 0;
        $deviceRev = $campRev * $proportion;
    }

    $lucro = $deviceRev - $spend;
    $roi = $spend > 0 ? (($deviceRev - $spend) / $spend) * 100 : 0;

    $rows[] = [
        'device_os' => $f['device_os'],
        'campaign_id' => $cid,
        'campaign_name' => $f['campaign_name'] ?: $cid,
        'revenue' => $deviceRev,
        'fb_spend' => $spend,
        'fb_impressions' => $imp,
        'fb_clicks' => $clicks,
        'fb_cpc' => $clicks > 0 ? $spend / $clicks : 0,
        'fb_ctr' => $imp > 0 ? ($clicks / $imp) * 100 : 0,
        'fb_cpm' => $imp > 0 ? ($spend / $imp) * 1000 : 0,
        'lucro' => $lucro,
        'roi' => $roi,
    ];
}

// Sort
usort($rows, function($a, $b) use ($sort) {
    $cmp = strcmp($a['campaign_name'], $b['campaign_name']);
    if ($cmp !== 0) return $cmp;
    $col = str_replace(['_desc', '_asc'], '', $sort);
    $dir = strpos($sort, '_asc') !== false ? 1 : -1;
    $map = ['spend' => 'fb_spend', 'os' => 'device_os', 'imp' => 'fb_impressions',
            'clicks' => 'fb_clicks', 'cpc' => 'fb_cpc', 'ctr' => 'fb_ctr', 'cpm' => 'fb_cpm',
            'rev' => 'revenue', 'lucro' => 'lucro', 'roi' => 'roi'];
    $key = $map[$col] ?? 'revenue';
    if ($key === 'device_os') return $dir * strcmp($a[$key], $b[$key]);
    return $dir * (($b[$key] ?? 0) <=> ($a[$key] ?? 0));
});

// Group by campaign
$campaigns = [];
foreach ($rows as $r) {
    $cid = $r['campaign_id'] ?: 'unknown';
    if (!isset($campaigns[$cid])) {
        $campaigns[$cid] = [
            'name' => $r['campaign_name'], 'id' => $cid, 'rows' => [],
            'total_spend' => 0, 'total_revenue' => 0, 'total_impressions' => 0, 'total_clicks' => 0,
        ];
    }
    $campaigns[$cid]['rows'][] = $r;
    $campaigns[$cid]['total_spend'] += $r['fb_spend'];
    $campaigns[$cid]['total_revenue'] += $r['revenue'];
    $campaigns[$cid]['total_impressions'] += $r['fb_impressions'];
    $campaigns[$cid]['total_clicks'] += $r['fb_clicks'];
}
uasort($campaigns, function($a, $b) { return $b['total_revenue'] <=> $a['total_revenue']; });

// Global Totals
$totalRev = array_sum(array_column($rows, 'revenue'));
$totalSpend = array_sum(array_column($rows, 'fb_spend'));
$totalLucro = $totalRev - $totalSpend;
$totalROI = $totalSpend > 0 ? (($totalRev - $totalSpend) / $totalSpend) * 100 : 0;

// Device aggregated totals
$osTotals = [];
foreach ($rows as $r) {
    $os = $r['device_os'];
    if (!isset($osTotals[$os])) $osTotals[$os] = ['spend' => 0, 'revenue' => 0];
    $osTotals[$os]['spend'] += $r['fb_spend'];
    $osTotals[$os]['revenue'] += $r['revenue'];
}
arsort($osTotals);

function devUrl($overrides = []) {
    $params = array_merge($_GET, ['page' => 'devices'], $overrides);
    return '?' . http_build_query($params);
}
function devSortArrow($col, $current) {
    if ($current === $col . '_desc') return '↓';
    if ($current === $col . '_asc') return '↑';
    return '↕';
}
function devSortToggle($col, $current) {
    return $current === $col . '_desc' ? $col . '_asc' : $col . '_desc';
}

function formatOS($name) {
    $map = [
        'iphone' => '📱 iPhone',
        'ipad' => '📱 iPad',
        'ipod' => '📱 iPod',
        'android_smartphone' => '🤖 Android Celular',
        'android_tablet' => '📱 Android Tablet',
        'desktop' => '🖥️ Desktop',
        'other' => '❓ Outro',
        'Unknown' => '❓ Desconhecido',
    ];
    return $map[$name] ?? '📱 ' . ucfirst(str_replace('_', ' ', $name));
}

function osColor($name) {
    $map = [
        'iphone' => '#a78bfa',
        'ipad' => '#c084fc',
        'ipod' => '#d8b4fe',
        'android_smartphone' => '#34d399',
        'android_tablet' => '#6ee7b7',
        'desktop' => '#60a5fa',
        'other' => '#94a3b8',
    ];
    return $map[$name] ?? '#fbbf24';
}
?>

<!-- Date Filter Bar + Sync Button -->
<?php
$currentPage = 'devices';
ob_start();
?>
<button class="btn btn-primary btn-sm" id="btnSyncDevices" onclick="syncDevices()">🔄 Sync</button>
<button class="btn-export" onclick="exportDevicesToCSV('devices_<?= $dateFrom ?>_<?= $dateTo ?>')" title="Exportar dados filtrados para CSV">
    📥 Exportar CSV
</button>
<span id="syncDevStatus" style="font-size:12px;color:var(--text-muted);"></span>
<?php $dateFilterExtra = ob_get_clean(); ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<!-- Summary Cards -->
<div class="cards-grid">
    <div class="card card-blue">
        <div class="card-label">Revenue GAM</div>
        <div class="card-value"><?= formatMoney($totalRev) ?></div>
        <div class="card-sub"><?= $useRealDeviceRev ? '📡 Revenue real por dispositivo' : '⚠️ Proporcional — Sincronize o GAM' ?></div>
    </div>
    <div class="card card-orange">
        <div class="card-label">Custo Facebook</div>
        <div class="card-value"><?= formatMoney($totalSpend) ?></div>
        <div class="card-sub">Gasto real por dispositivo</div>
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

<!-- OS Distribution Bar -->
<?php if (!empty($osTotals) && $totalSpend > 0): ?>
<div class="card" style="margin-top:15px; padding:18px 20px;">
    <div style="font-size:12px; font-weight:600; color:var(--text-primary); margin-bottom:12px;">📊 Distribuição por Dispositivo de Impressão</div>
    <div style="display:flex; border-radius:8px; overflow:hidden; height:28px; background:rgba(255,255,255,0.04);">
        <?php foreach ($osTotals as $os => $data):
            $pct = ($data['spend'] / $totalSpend) * 100;
            if ($pct < 0.5) continue;
        ?>
        <div style="width:<?= $pct ?>%; background:<?= osColor($os) ?>; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; color:#111; min-width:30px; transition:all 0.3s;"
             title="<?= $os ?>: <?= formatMoney($data['spend']) ?> (<?= formatNumber($pct, 1) ?>%)">
            <?= $pct >= 8 ? $os . ' ' . formatNumber($pct, 1) . '%' : formatNumber($pct, 0) . '%' ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex; gap:16px; margin-top:10px; flex-wrap:wrap;">
        <?php foreach ($osTotals as $os => $data):
            $pct = ($data['spend'] / $totalSpend) * 100;
            $devLucro = $data['revenue'] - $data['spend'];
        ?>
        <div style="display:flex; align-items:center; gap:6px; font-size:11px;">
            <span style="width:10px; height:10px; border-radius:50%; background:<?= osColor($os) ?>; display:inline-block;"></span>
            <span style="color:var(--text-primary); font-weight:600;"><?= formatOS($os) ?></span>
            <span style="color:var(--text-muted);">Custo: <?= formatMoney($data['spend']) ?> (<?= formatNumber($pct, 1) ?>%)</span>
            <span style="color:#60a5fa;">Rev: <?= formatMoney($data['revenue']) ?></span>
            <span class="<?= $devLucro >= 0 ? 'positive' : 'negative' ?>">Lucro: <?= formatMoney($devLucro) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Campaigns + Devices -->
<?php if (empty($campaigns)): ?>
<div class="card" style="margin-top:15px; padding:40px; text-align:center;">
    <div class="empty-state-icon">📱</div>
    <div class="empty-state-title">Nenhum dado de dispositivo encontrado</div>
    <div class="empty-state-text">Sincronize o Facebook para importar dados de dispositivos de impressão</div>
</div>
<?php else: ?>
<?php foreach ($campaigns as $cid => $camp):
    $campLucro = $camp['total_revenue'] - $camp['total_spend'];
    $campROI = $camp['total_spend'] > 0 ? (($camp['total_revenue'] - $camp['total_spend']) / $camp['total_spend']) * 100 : 0;
?>
<div class="campaign-placement-card" style="margin-top:15px;">
    <div class="campaign-header" onclick="this.parentElement.classList.toggle('collapsed')">
        <div class="campaign-header-left">
            <span class="campaign-toggle-icon">▾</span>
            <div class="campaign-header-info">
                <span class="campaign-header-name"><?= htmlspecialchars($camp['name']) ?></span>
                <span class="campaign-header-meta"><?= count($camp['rows']) ?> dispositivo<?= count($camp['rows']) > 1 ? 's' : '' ?></span>
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

    <div class="campaign-placements-body">
        <div class="table-scroll">
            <table class="devicesTable">
                <thead>
                    <tr>
                        <th><a href="<?= devUrl(['sort' => devSortToggle('os', $sort)]) ?>" class="sort-link">Dispositivo <?= devSortArrow('os', $sort) ?></a></th>
                        <th class="hm-col hm-lower"><a href="<?= devUrl(['sort' => devSortToggle('spend', $sort)]) ?>" class="sort-link">Custo FB <?= devSortArrow('spend', $sort) ?></a></th>
                        <th class="hm-col hm-higher"><a href="<?= devUrl(['sort' => devSortToggle('rev', $sort)]) ?>" class="sort-link">Revenue GAM <?= devSortArrow('rev', $sort) ?></a></th>
                        <th class="hm-col hm-higher"><a href="<?= devUrl(['sort' => devSortToggle('lucro', $sort)]) ?>" class="sort-link">Lucro <?= devSortArrow('lucro', $sort) ?></a></th>
                        <th class="hm-col hm-higher"><a href="<?= devUrl(['sort' => devSortToggle('roi', $sort)]) ?>" class="sort-link">ROI <?= devSortArrow('roi', $sort) ?></a></th>
                        <th><a href="<?= devUrl(['sort' => devSortToggle('imp', $sort)]) ?>" class="sort-link">Imp. FB <?= devSortArrow('imp', $sort) ?></a></th>
                        <th><a href="<?= devUrl(['sort' => devSortToggle('clicks', $sort)]) ?>" class="sort-link">Cliques <?= devSortArrow('clicks', $sort) ?></a></th>
                        <th>CPC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($camp['rows'] as $r): ?>
                    <tr>
                        <td>
                            <span class="os-badge" style="border-left:3px solid <?= osColor($r['device_os']) ?>;">
                                <?= formatOS($r['device_os']) ?>
                            </span>
                        </td>
                        <td class="hm-cell" data-val="<?= (float)$r['fb_spend'] ?>" style="color:#f59e0b;"><?= $r['fb_spend'] > 0 ? formatMoney($r['fb_spend']) : '-' ?></td>
                        <td class="hm-cell" data-val="<?= (float)$r['revenue'] ?>"><?= $r['revenue'] > 0 ? formatMoney($r['revenue']) : '-' ?></td>
                        <td class="hm-cell <?= $r['lucro'] >= 0 ? 'positive' : 'negative' ?>" data-val="<?= (float)$r['lucro'] ?>">
                            <?= ($r['fb_spend'] > 0 || $r['revenue'] > 0) ? formatMoney($r['lucro']) : '-' ?>
                        </td>
                        <td class="hm-cell <?= $r['roi'] >= 0 ? 'positive' : 'negative' ?>" data-val="<?= (float)$r['roi'] ?>">
                            <?= $r['fb_spend'] > 0 ? formatNumber($r['roi'], 1) . '%' : '-' ?>
                        </td>
                        <td><?= $r['fb_impressions'] > 0 ? formatNumber($r['fb_impressions']) : '-' ?></td>
                        <td><?= $r['fb_clicks'] > 0 ? formatNumber($r['fb_clicks']) : '-' ?></td>
                        <td><?= $r['fb_cpc'] > 0 ? formatMoney($r['fb_cpc']) : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="campaign-subtotal-row">
                        <td><strong>Total da Campanha</strong></td>
                        <td style="color:#f59e0b;"><strong><?= formatMoney($camp['total_spend']) ?></strong></td>
                        <td><strong><?= formatMoney($camp['total_revenue']) ?></strong></td>
                        <td class="<?= $campLucro >= 0 ? 'positive' : 'negative' ?>"><strong><?= formatMoney($campLucro) ?></strong></td>
                        <td class="<?= $campROI >= 0 ? 'positive' : 'negative' ?>"><strong><?= $camp['total_spend'] > 0 ? formatNumber($campROI, 1) . '%' : '-' ?></strong></td>
                        <td><strong><?= formatNumber($camp['total_impressions']) ?></strong></td>
                        <td><strong><?= formatNumber($camp['total_clicks']) ?></strong></td>
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
    const tables = document.querySelectorAll('.devicesTable');
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
            const mn = Math.min(...vals), mx = Math.max(...vals), range = mx - mn;
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

async function syncDevices() {
    const btn = document.getElementById('btnSyncDevices');
    const status = document.getElementById('syncDevStatus');
    btn.disabled = true;
    btn.textContent = '⏳ Sincronizando...';
    status.textContent = '';
    try {
        status.textContent = '⏳ Sincronizando Facebook...';
        const fbRes = await fetch('api/sync.php?action=sync_fb');
        const fbData = await fbRes.json();
        const msg = fbData.success ? `FB: ${fbData.imported} reg, ${fbData.devices || 0} devices` : 'FB: erro';
        status.textContent = `✅ ${msg}`;
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

function exportDevicesToCSV(filename) {
    const cards = document.querySelectorAll('.campaign-placement-card');
    if (!cards.length) { showToast('Nenhum dado para exportar', 'error'); return; }
    const rows = [];
    const firstTable = cards[0].querySelector('.devicesTable');
    if (!firstTable) return;
    const headers = ['Campanha'];
    firstTable.querySelectorAll('thead th').forEach(th => {
        headers.push(th.textContent.replace(/[↕↑↓?]/g, '').trim());
    });
    rows.push(headers);
    cards.forEach(card => {
        const campEl = card.querySelector('.campaign-header-name');
        const campName = campEl ? campEl.textContent.trim() : 'N/A';
        const table = card.querySelector('.devicesTable');
        if (!table) return;
        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = [campName];
            tr.querySelectorAll('td').forEach(td => {
                let val = td.getAttribute('data-val');
                cells.push(val !== null ? val : td.textContent.trim().replace(/\s+/g, ' '));
            });
            rows.push(cells);
        });
    });
    downloadCSV(rows, filename);
}
</script>

<style>
.sort-link { color: var(--text-primary); text-decoration: none; white-space: nowrap; transition: color 0.2s; }
.sort-link:hover { color: var(--primary); }

.os-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 12px; border-radius: 6px; font-size: 11.5px; font-weight: 600;
    background: rgba(99,102,241,0.08); color: #e2e8f0; white-space: nowrap;
}

.devicesTable th, .devicesTable td { font-size: 11.5px; padding: 8px 6px; white-space: nowrap; }

.th-help {
    display: inline-flex; align-items: center; justify-content: center;
    width: 14px; height: 14px; margin-left: 3px;
    font-size: 9px; font-weight: 700;
    color: var(--text-muted, #666); background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.1); border-radius: 50%;
    cursor: help; vertical-align: middle; opacity: 0.5; transition: opacity 0.2s;
}
.th-help:hover { opacity: 1; color: var(--primary); border-color: var(--primary); background: rgba(99,102,241,0.1); }

.hm-cell { transition: background-color 0.3s ease; }

.pct-bar-container {
    display: flex; align-items: center; gap: 6px; min-width: 80px;
}
.pct-bar {
    height: 6px; border-radius: 3px; transition: width 0.4s ease; min-width: 2px;
}
.pct-label {
    font-size: 10px; font-weight: 600; color: var(--text-muted); white-space: nowrap;
}

/* Reuse campaign card styles from placements */
.campaign-placement-card { background: var(--card-bg, #1e1e2e); border-radius: 12px; border: 1px solid rgba(255,255,255,0.06); overflow: hidden; transition: all 0.3s ease; }
.campaign-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; cursor: pointer; gap: 16px; background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.06); transition: background 0.2s; }
.campaign-header:hover { background: rgba(99,102,241,0.06); }
.campaign-header-left { display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1; }
.campaign-toggle-icon { font-size: 16px; color: var(--text-muted, #888); transition: transform 0.3s ease; flex-shrink: 0; }
.campaign-placement-card.collapsed .campaign-toggle-icon { transform: rotate(-90deg); }
.campaign-header-info { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.campaign-header-name { font-size: 13px; font-weight: 600; color: var(--text-primary, #e2e8f0); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.campaign-header-meta { font-size: 10px; color: var(--text-muted, #888); }
.campaign-header-stats { display: flex; align-items: center; gap: 20px; flex-shrink: 0; }
.campaign-stat { display: flex; flex-direction: column; align-items: flex-end; gap: 1px; }
.campaign-stat-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted, #888); }
.campaign-stat-value { font-size: 12px; font-weight: 700; color: var(--text-primary, #e2e8f0); }
.campaign-placements-body { transition: max-height 0.4s ease, opacity 0.3s ease; overflow: hidden; }
.campaign-placement-card.collapsed .campaign-placements-body { max-height: 0 !important; opacity: 0; overflow: hidden; }
.campaign-subtotal-row { background: rgba(255,255,255,0.03); border-top: 1px solid rgba(255,255,255,0.08); }
.campaign-subtotal-row td { font-size: 11px !important; padding-top: 10px !important; padding-bottom: 10px !important; }
.positive { color: #34d399; }
.negative { color: #f87171; }

@media (max-width: 768px) {
    .campaign-header { flex-direction: column; align-items: flex-start; gap: 10px; }
    .campaign-header-stats { width: 100%; justify-content: space-between; }
}
</style>
