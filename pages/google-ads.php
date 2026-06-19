<?php
/**
 * Google Ads Page — Dados compilados por campanha
 */

list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();

// Dados COMPILADOS por campanha (somados no período)
$data = fetchAll("
    SELECT
        campaign_id,
        campaign_name,
        SUM(cost) as total_cost,
        SUM(impressions) as total_imp,
        SUM(clicks) as total_clicks,
        CASE WHEN SUM(clicks) > 0 THEN SUM(cost) / SUM(clicks) ELSE 0 END as avg_cpc,
        CASE WHEN SUM(impressions) > 0 THEN (SUM(clicks) * 100.0 / SUM(impressions)) ELSE 0 END as avg_ctr,
        CASE WHEN SUM(impressions) > 0 THEN (SUM(cost) / SUM(impressions)) * 1000 ELSE 0 END as avg_cpm,
        COALESCE(AVG(conversion_rate), 0) as avg_conv_rate,
        MAX(status) as status,
        COUNT(*) as days_count
    FROM google_ads
    WHERE date BETWEEN ? AND ?
    GROUP BY campaign_id, campaign_name
    ORDER BY total_cost DESC
    LIMIT 500
", [$dateFrom, $dateTo]);

$totals = fetchOne("
    SELECT
        COALESCE(SUM(cost), 0) as total_cost,
        COALESCE(SUM(impressions), 0) as total_imp,
        COALESCE(SUM(clicks), 0) as total_clicks,
        COUNT(DISTINCT campaign_id) as total_campaigns,
        COUNT(*) as total_rows
    FROM google_ads WHERE date BETWEEN ? AND ?
", [$dateFrom, $dateTo]);
?>

<?php $currentPage = 'google-ads'; $dateFilterExtra = ''; ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<div class="cards-grid">
    <div class="card card-blue">
        <div class="card-label">Custo Total</div>
        <div class="card-value"><?= formatMoney($totals['total_cost']) ?></div>
        <div class="card-change"><?= $totals['total_campaigns'] ?> campanhas</div>
    </div>
    <div class="card card-green">
        <div class="card-label">Impressões</div>
        <div class="card-value"><?= formatNumber($totals['total_imp']) ?></div>
    </div>
    <div class="card card-purple">
        <div class="card-label">Cliques</div>
        <div class="card-value"><?= formatNumber($totals['total_clicks']) ?></div>
        <div class="card-change">CTR: <?= $totals['total_imp'] > 0 ? formatNumber(($totals['total_clicks'] / $totals['total_imp']) * 100, 2) . '%' : '-' ?></div>
    </div>
    <div class="card card-cyan">
        <div class="card-label">CPC Médio</div>
        <div class="card-value"><?= $totals['total_clicks'] > 0 ? formatMoney($totals['total_cost'] / $totals['total_clicks']) : '-' ?></div>
    </div>
</div>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">🔍 Google Ads — Totais do Período</span>
        <div class="table-actions">
            <span class="badge badge-blue"><?= count($data) ?> campanhas</span>
            <button class="btn btn-primary btn-sm" id="btnSyncGads" onclick="syncGoogleAds()">🔄 Sincronizar API</button>
            <a href="?page=import&type=google_ads" class="btn btn-secondary btn-sm">📤 Importar CSV</a>
        </div>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Campanha</th>
                    <th>Custo</th>
                    <th>Impressões</th>
                    <th>Cliques</th>
                    <th>CPC Médio</th>
                    <th>CTR</th>
                    <th>Conv. Rate</th>
                    <th>CPM</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data)): ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">Nenhum dado. Sincronize via API ou importe um CSV.</td></tr>
                <?php else: foreach ($data as $r): ?>
                <tr>
                    <td title="ID: <?= $r['campaign_id'] ?>"><?= sanitize(mb_substr($r['campaign_name'] ?? '', 0, 45)) ?></td>
                    <td><?= formatMoney($r['total_cost']) ?></td>
                    <td><?= formatNumber($r['total_imp']) ?></td>
                    <td><?= formatNumber($r['total_clicks']) ?></td>
                    <td><?= formatMoney($r['avg_cpc']) ?></td>
                    <td><?= formatNumber($r['avg_ctr'], 2) ?>%</td>
                    <td><?= formatNumber($r['avg_conv_rate'] * 100, 2) ?>%</td>
                    <td><?= formatMoney($r['avg_cpm']) ?></td>
                    <td><span class="badge <?= $r['status'] === 'Ativo' ? 'badge-green' : 'badge-yellow' ?>"><?= $r['status'] ?></span></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function syncGoogleAds() {
    const btn = document.getElementById('btnSyncGads');
    btn.disabled = true;
    btn.textContent = '⏳ Sincronizando...';

    try {
        const res = await fetch('/api/sync.php?action=sync_google_ads');
        const data = await res.json();

        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ ' + (data.error || 'Erro desconhecido'));
        }
    } catch (e) {
        alert('❌ Erro: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.textContent = '🔄 Sincronizar API';
    }
}
</script>
