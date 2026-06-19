<?php
/**
 * Receita Programática Page
 */

list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();

$data = fetchAll("
    SELECT * FROM receita_programatica
    WHERE date BETWEEN ? AND ?
    ORDER BY date DESC
", [$dateFrom, $dateTo]);

$totals = fetchOne("
    SELECT
        COALESCE(SUM(impressions), 0) as total_imp,
        COALESCE(SUM(clicks), 0) as total_clicks,
        COALESCE(SUM(revenue_usd), 0) as total_revenue,
        COALESCE(SUM(views), 0) as total_views,
        COALESCE(SUM(sessions), 0) as total_sessions,
        COALESCE(SUM(ad_requests), 0) as total_ad_req,
        COALESCE(AVG(avg_ecpm), 0) as avg_ecpm,
        COALESCE(AVG(bounce_rate), 0) as avg_bounce,
        COUNT(*) as total_days
    FROM receita_programatica WHERE date BETWEEN ? AND ?
", [$dateFrom, $dateTo]);
?>

<?php $currentPage = 'programmatic'; $dateFilterExtra = ''; ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<div class="cards-grid">
    <div class="card card-green">
        <div class="card-label">Revenue Total (USD)</div>
        <div class="card-value"><?= formatMoney($totals['total_revenue'], 'USD') ?></div>
        <div class="card-change"><?= $totals['total_days'] ?> dias</div>
    </div>
    <div class="card card-blue">
        <div class="card-label">Impressões / Cliques</div>
        <div class="card-value" style="font-size:18px;"><?= formatNumber($totals['total_imp']) ?> / <?= formatNumber($totals['total_clicks']) ?></div>
    </div>
    <div class="card card-purple">
        <div class="card-label">eCPM Médio</div>
        <div class="card-value" style="font-size:20px;"><?= formatMoney($totals['avg_ecpm'], 'USD') ?></div>
    </div>
    <div class="card card-cyan">
        <div class="card-label">Views / Sessions</div>
        <div class="card-value" style="font-size:18px;"><?= formatNumber($totals['total_views']) ?> / <?= formatNumber($totals['total_sessions']) ?></div>
        <div class="card-change">Bounce Rate Médio: <?= formatPercentRaw($totals['avg_bounce'] * 100) ?></div>
    </div>
</div>

<div class="chart-container">
    <div class="chart-header">
        <span class="chart-title">📈 Revenue & eCPM Diário</span>
    </div>
    <canvas id="progChart" class="chart-canvas"></canvas>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">Receita Programática Detalhada</span>
        <a href="?page=import&type=receita_programatica" class="btn btn-secondary btn-sm">📤 Importar CSV</a>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Data</th><th>Dia</th><th>Impressões</th><th>Cliques</th>
                    <th>CTR</th><th>Revenue $</th><th>eCPM</th><th>Ad Req.</th>
                    <th>Match Rate</th><th>Views</th><th>Sessions</th><th>Bounce</th>
                    <th>RPP</th><th>RPS</th><th>IMP/PV</th><th>REQ/PV</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data)): ?>
                <tr><td colspan="16" style="text-align:center;padding:40px;color:var(--text-muted);">Nenhum dado. Importe um CSV.</td></tr>
                <?php else: foreach ($data as $r): ?>
                <tr>
                    <td><?= formatDate($r['date']) ?></td>
                    <td><?= $r['day_of_week'] ?></td>
                    <td><?= formatNumber($r['impressions']) ?></td>
                    <td><?= formatNumber($r['clicks']) ?></td>
                    <td><?= formatPercentRaw($r['ctr'] * 100) ?></td>
                    <td><?= formatMoney($r['revenue_usd'], 'USD') ?></td>
                    <td><?= formatMoney($r['avg_ecpm'], 'USD') ?></td>
                    <td><?= formatNumber($r['ad_requests']) ?></td>
                    <td><?= formatPercentRaw($r['match_rate'] * 100) ?></td>
                    <td><?= formatNumber($r['views']) ?></td>
                    <td><?= formatNumber($r['sessions']) ?></td>
                    <td><?= formatPercentRaw($r['bounce_rate'] * 100) ?></td>
                    <td><?= formatNumber($r['rpp'], 4) ?></td>
                    <td><?= formatNumber($r['rps'], 4) ?></td>
                    <td><?= formatNumber($r['imp_pageview'], 2) ?></td>
                    <td><?= formatNumber($r['req_pageview'], 2) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const data = <?= json_encode($data) ?>;
    if (!data.length) return;
    const sorted = [...data].sort((a,b) => a.date.localeCompare(b.date));
    new Chart(document.getElementById('progChart'), {
        type: 'line',
        data: {
            labels: sorted.map(d => d.date.split('-').reverse().slice(0,2).join('/')),
            datasets: [
                { label: 'Revenue $', data: sorted.map(d => parseFloat(d.revenue_usd)||0), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.4, yAxisID: 'y' },
                { label: 'eCPM $', data: sorted.map(d => parseFloat(d.avg_ecpm)||0), borderColor: '#a855f7', tension: 0.4, yAxisID: 'y1' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: '#8b95b0' } } },
            scales: {
                x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#5a6480' } },
                y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#5a6480' }, position: 'left' },
                y1: { grid: { display: false }, ticks: { color: '#a855f7' }, position: 'right' }
            }
        }
    });
});
</script>
