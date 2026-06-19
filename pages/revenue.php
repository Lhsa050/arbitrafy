<?php
/**
 * Revenue GAM Page — Dados compilados por campanha
 */

$cotacao = (float)getSetting('cotacao_dolar', '5.80');
list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'receita_desc';
$revSite = $_GET['site'] ?? '';

// Available GAM sites
try {
    $revGamSites = fetchAll("SELECT DISTINCT site_name FROM revenue WHERE site_name != '' ORDER BY site_name");
} catch (Exception $e) {
    $revGamSites = [];
}
$where = "WHERE r.date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($revSite) {
    $where .= " AND r.site_name = ?";
    $params[] = $revSite;
}
if ($search) {
    $where .= " AND (r.campaign_id LIKE ? OR r.utm_campaign LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

// Revenue COMPILADA por campanha (somados no período)
$revenueData = fetchAll("
    SELECT
        r.campaign_id,
        r.utm_campaign,
        SUM(r.receita_usd) as total_receita_usd,
        COUNT(DISTINCT r.date) as days_count,
        fc.campaign_name,
        fc.fb_invest
    FROM revenue r
    LEFT JOIN (
        SELECT campaign_id,
               MIN(campaign_name) as campaign_name,
               SUM(investimento) as fb_invest
        FROM fb_campaigns
        WHERE date BETWEEN ? AND ?
        GROUP BY campaign_id
    ) fc ON r.campaign_id = fc.campaign_id
    {$where}
    GROUP BY r.campaign_id
    ORDER BY total_receita_usd DESC
    LIMIT 500
", array_merge([$dateFrom, $dateTo], $params));

$totals = fetchOne("
    SELECT
        COALESCE(SUM(receita_usd), 0) as total_receita,
        COUNT(*) as total_rows,
        COUNT(DISTINCT campaign_id) as total_campaigns,
        COUNT(DISTINCT date) as total_days
    FROM revenue r {$where}
", $params);

// Daily aggregation for chart
$dailyRevenue = fetchAll("
    SELECT date, SUM(receita_usd) as total
    FROM revenue r {$where}
    GROUP BY date ORDER BY date
", $params);

function revUrl($overrides = []) {
    $params = array_merge($_GET, ['page' => 'revenue'], $overrides);
    return '?' . http_build_query($params);
}
?>

<?php
$currentPage = 'revenue';
ob_start();
?>
<?php if (!empty($revGamSites)): ?>
<select class="filter-input" style="max-width:180px;min-height:34px;padding:6px 10px;font-size:12px;" onchange="var p=new URLSearchParams(location.search);p.set('site',this.value);location.href='?'+p.toString()">
    <option value="">Todos os Sites</option>
    <?php foreach ($revGamSites as $site): ?>
    <option value="<?= sanitize($site['site_name']) ?>" <?= $revSite === $site['site_name'] ? 'selected' : '' ?>>🌐 <?= sanitize($site['site_name']) ?></option>
    <?php endforeach; ?>
</select>
<?php endif; ?>
<input type="text" class="filter-input" style="max-width:180px;min-height:34px;padding:6px 10px;font-size:12px;" placeholder="🔍 Buscar campaign_id..." value="<?= sanitize($search) ?>"
       onkeydown="if(event.key==='Enter'){var p=new URLSearchParams(location.search);p.set('search',this.value);location.href='?'+p.toString();}">
<?php $dateFilterExtra = ob_get_clean(); ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<div class="cards-grid">
    <div class="card card-green">
        <div class="card-icon">💰</div>
        <div class="card-label">Receita Total (USD)</div>
        <div class="card-value"><?= formatMoney($totals['total_receita'], 'USD') ?></div>
        <div class="card-change"><?= $totals['total_campaigns'] ?> campanhas</div>
    </div>
    <div class="card card-blue">
        <div class="card-icon">💵</div>
        <div class="card-label">Receita Total (BRL)</div>
        <div class="card-value"><?= formatMoney($totals['total_receita'] * $cotacao) ?></div>
        <div class="card-change">Cotação: R$ <?= number_format($cotacao, 2, ',', '.') ?></div>
    </div>
    <div class="card card-purple">
        <div class="card-icon">📊</div>
        <div class="card-label">Campanhas Únicas</div>
        <div class="card-value"><?= $totals['total_campaigns'] ?></div>
    </div>
    <div class="card card-cyan">
        <div class="card-icon">📅</div>
        <div class="card-label">Dias com Dados</div>
        <div class="card-value"><?= $totals['total_days'] ?></div>
    </div>
</div>

<!-- Chart -->
<div class="chart-container">
    <div class="chart-header">
        <span class="chart-title">💰 Receita Diária GAM (USD)</span>
    </div>
    <canvas id="revenueChart" class="chart-canvas"></canvas>
</div>

<!-- Data Table — Compiled -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">💰 Revenue por Campanha — Totais do Período</span>
        <div class="table-actions">
            <span class="badge badge-blue"><?= count($revenueData) ?> campanhas</span>
            <button class="btn btn-primary btn-sm" onclick="syncGAM()" id="btnSyncGam">🔄 Atualizar GAM</button>
        </div>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Campaign ID</th>
                    <th>Campanha FB</th>
                    <th>Invest. FB</th>
                    <th>Receita (USD)</th>
                    <th>Receita (BRL)</th>
                    <th>ROAS</th>
                    <th>Lucro</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($revenueData)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">
                    Nenhum dado de revenue. Sincronize com o GAM ou importe um CSV.
                </td></tr>
                <?php else: ?>
                <?php foreach ($revenueData as $r): ?>
                <?php
                    $receitaBrl = $r['total_receita_usd'] * $cotacao;
                    $fbInvest = (float)($r['fb_invest'] ?? 0);
                    $roas = $fbInvest > 0 ? (($receitaBrl - $fbInvest) / $fbInvest) * 100 : 0;
                    $profit = $receitaBrl - $fbInvest;
                ?>
                <tr>
                    <td style="font-family:monospace;font-size:11px;"><?= $r['campaign_id'] ?></td>
                    <td title="utm: <?= sanitize($r['utm_campaign'] ?? '') ?>"><?= sanitize(mb_substr($r['campaign_name'] ?? $r['utm_campaign'] ?? '-', 0, 40)) ?></td>
                    <td><?= $fbInvest > 0 ? formatMoney($fbInvest) : '-' ?></td>
                    <td><?= formatMoney($r['total_receita_usd'], 'USD') ?></td>
                    <td><?= formatMoney($receitaBrl) ?></td>
                    <td class="<?= $roas >= 0 ? 'positive' : 'negative' ?>"><?= formatNumber($roas, 1) ?>%</td>
                    <td class="<?= roiClass($profit) ?>"><?= formatMoney($profit) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dailyData = <?= json_encode($dailyRevenue) ?>;
    if (dailyData.length === 0) return;

    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: dailyData.map(d => d.date.split('-').reverse().slice(0,2).join('/')),
            datasets: [{
                label: 'Receita USD',
                data: dailyData.map(d => parseFloat(d.total) || 0),
                backgroundColor: 'rgba(16, 185, 129, 0.6)',
                borderColor: '#10b981',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { labels: { color: '#8b95b0' } } },
            scales: {
                x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#5a6480' } },
                y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#5a6480' } }
            }
        }
    });
});
</script>

<script>
async function syncGAM() {
    const btn = document.getElementById('btnSyncGam');
    btn.disabled = true;
    btn.textContent = '⏳ Sincronizando GAM...';

    try {
        const res = await fetch('/api/sync.php?action=sync_gam');
        const data = await res.json();
        
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ Erro: ' + (data.error || data.details || 'Erro desconhecido'));
            btn.disabled = false;
            btn.textContent = '🔄 Atualizar GAM';
        }
    } catch (e) {
        alert('❌ Erro de conexão: ' + e.message);
        btn.disabled = false;
        btn.textContent = '🔄 Atualizar GAM';
    }
}
</script>

<style>
.sort-link { color: var(--text-primary); text-decoration: none; white-space: nowrap; transition: color 0.2s; }
.sort-link:hover { color: var(--primary); }
</style>
