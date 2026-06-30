<?php
/**
 * Campanhas Facebook Page
 */

$cotacao = (float)getSetting('cotacao_dolar', '5.80');
$account = $_GET['account'] ?? '';
list($dateFrom, $dateTo, $datePreset) = resolveDateFilter();
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'invest_desc';
$viewMode = $_GET['view_mode'] ?? 'agrupado'; // agrupado | diario

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

// Sort mapping — agora sobre dados agrupados
$orderBy = match($sort) {
    'name_asc' => 'campaign_name ASC',
    'name_desc' => 'campaign_name DESC',
    'invest_desc' => 'total_invest DESC',
    'invest_asc' => 'total_invest ASC',
    'ganho_desc' => 'ganho DESC',
    'ganho_asc' => 'ganho ASC',
    'impr_desc' => 'total_imp DESC',
    'impr_asc' => 'total_imp ASC',
    'click_desc' => 'total_clicks DESC',
    'click_asc' => 'total_clicks ASC',
    'roas_desc' => 'roas DESC',
    'roas_asc' => 'roas ASC',
    'date_desc' => 'fc.date DESC',
    'date_asc' => 'fc.date ASC',
    default => 'total_invest DESC',
};

if ($viewMode === 'diario') {
    // Modo DIÁRIO: sempre em ordem cronológica crescente; o sort escolhido só desempata dentro do dia.
    $dailySecondaryOrderBy = match($sort) {
        'name_asc' => 'campaign_name ASC',
        'name_desc' => 'campaign_name DESC',
        'invest_desc' => 'total_invest DESC',
        'invest_asc' => 'total_invest ASC',
        'ganho_desc' => 'ganho DESC',
        'ganho_asc' => 'ganho ASC',
        'impr_desc' => 'total_imp DESC',
        'impr_asc' => 'total_imp ASC',
        'click_desc' => 'total_clicks DESC',
        'click_asc' => 'total_clicks ASC',
        'roas_desc' => 'roas DESC',
        'roas_asc' => 'roas ASC',
        default => 'campaign_name ASC',
    };
    $dailyOrderBy = "fc.date ASC, {$dailySecondaryOrderBy}";
    $campaigns = fetchAll("
        SELECT
            fc.campaign_id,
            fc.campaign_name,
            fc.account_name,
            fc.date as row_date,
            fc.investimento as total_invest,
            fc.impressoes as total_imp,
            fc.cliques as total_clicks,
            fc.viz_lp as total_viz_lp,
            COALESCE(fc.receita_usd, 0) as total_receita_usd,
            COALESCE(fc.receita_brl, 0) as total_receita_brl,
            CASE WHEN fc.cliques > 0 THEN fc.investimento / fc.cliques ELSE 0 END as avg_cpc,
            CASE WHEN fc.impressoes > 0 THEN (fc.cliques * 100.0 / fc.impressoes) ELSE 0 END as avg_ctr,
            CASE WHEN fc.impressoes > 0 THEN (fc.investimento / fc.impressoes) * 1000 ELSE 0 END as avg_cpm,
            COALESCE(fc.receita_brl, 0) - fc.investimento as ganho,
            CASE WHEN fc.investimento > 0 THEN ((COALESCE(fc.receita_brl, 0) - fc.investimento) / fc.investimento) * 100 ELSE 0 END as roas
        FROM fb_campaigns fc
        {$where}
        ORDER BY {$dailyOrderBy}
        LIMIT 1000
    ", $params);
} else {
    // Modo AGRUPADO — dados compilados por campanha (somados no período)
    $campaigns = fetchAll("
        SELECT
            fc.campaign_id,
            fc.campaign_name,
            fc.account_name,
            SUM(fc.investimento) as total_invest,
            SUM(fc.impressoes) as total_imp,
            SUM(fc.cliques) as total_clicks,
            SUM(fc.viz_lp) as total_viz_lp,
            COALESCE(SUM(fc.receita_usd), 0) as total_receita_usd,
            COALESCE(SUM(fc.receita_brl), 0) as total_receita_brl,
            CASE WHEN SUM(fc.cliques) > 0 THEN SUM(fc.investimento) / SUM(fc.cliques) ELSE 0 END as avg_cpc,
            CASE WHEN SUM(fc.impressoes) > 0 THEN (SUM(fc.cliques) * 100.0 / SUM(fc.impressoes)) ELSE 0 END as avg_ctr,
            CASE WHEN SUM(fc.impressoes) > 0 THEN (SUM(fc.investimento) / SUM(fc.impressoes)) * 1000 ELSE 0 END as avg_cpm,
            COALESCE(SUM(fc.receita_brl), 0) - SUM(fc.investimento) as ganho,
            CASE WHEN SUM(fc.investimento) > 0 THEN ((COALESCE(SUM(fc.receita_brl), 0) - SUM(fc.investimento)) / SUM(fc.investimento)) * 100 ELSE 0 END as roas,
            COUNT(*) as days_count,
            MIN(fc.date) as first_date,
            MAX(fc.date) as last_date
        FROM fb_campaigns fc
        {$where}
        GROUP BY fc.campaign_id, fc.campaign_name, fc.account_name
        ORDER BY {$orderBy}
        LIMIT 500
    ", $params);
}

$negativeAlertTooltip = 'Campanha a mais de 3 dias no negativo';
$negativeAlertMinDays = 4;
$negativeCampaignAlerts = [];
$alertCampaignIds = array_values(array_unique(array_filter(array_map(
    fn($campaign) => (string)($campaign['campaign_id'] ?? ''),
    $campaigns
))));

if (!empty($alertCampaignIds)) {
    $alertPlaceholders = implode(',', array_fill(0, count($alertCampaignIds), '?'));
    $negativeHistoryRows = fetchAll("
        SELECT
            fc.campaign_id,
            fc.account_name,
            fc.date,
            COALESCE(SUM(fc.receita_brl), 0) - COALESCE(SUM(fc.investimento), 0) as ganho
        FROM fb_campaigns fc
        WHERE fc.campaign_id IN ({$alertPlaceholders})
        GROUP BY fc.campaign_id, fc.account_name, fc.date
        ORDER BY fc.campaign_id ASC, fc.account_name ASC, fc.date DESC
    ", $alertCampaignIds);

    $negativeStreaks = [];
    $streakClosed = [];
    foreach ($negativeHistoryRows as $historyRow) {
        $alertKey = ($historyRow['account_name'] ?? '') . '|' . ($historyRow['campaign_id'] ?? '');
        if (isset($streakClosed[$alertKey])) {
            continue;
        }

        if ((float)($historyRow['ganho'] ?? 0) < 0) {
            $negativeStreaks[$alertKey] = ($negativeStreaks[$alertKey] ?? 0) + 1;
            if ($negativeStreaks[$alertKey] >= $negativeAlertMinDays) {
                $negativeCampaignAlerts[$alertKey] = $negativeStreaks[$alertKey];
            }
        } else {
            $streakClosed[$alertKey] = true;
        }
    }
}

$totals = fetchOne("
    SELECT
        COALESCE(SUM(fc.investimento), 0) as total_invest,
        COALESCE(SUM(fc.impressoes), 0) as total_impressoes,
        COALESCE(SUM(fc.cliques), 0) as total_cliques,
        COALESCE(SUM(fc.receita_brl), 0) as total_receita,
        COUNT(DISTINCT fc.campaign_id) as total_campaigns,
        COUNT(*) as total_rows
    FROM fb_campaigns fc {$where}
", $params);

$accounts = fetchAll("SELECT DISTINCT account_name FROM fb_campaigns ORDER BY account_name");

function campUrl($overrides = []) {
    $params = array_merge($_GET, ['page' => 'campaigns'], $overrides);
    return '?' . http_build_query($params);
}
function campViewModeUrl($mode) {
    $params = array_merge($_GET, ['page' => 'campaigns', 'view_mode' => $mode]);
    return '?' . http_build_query($params);
}
function sortArrow($col, $current) {
    if ($current === $col . '_desc') return '↓';
    if ($current === $col . '_asc') return '↑';
    return '↕';
}
function sortToggle($col, $current) {
    return $current === $col . '_desc' ? $col . '_asc' : $col . '_desc';
}
?>

<!-- Date Filter -->
<?php
$currentPage = 'campaigns';
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
    <a href="<?= campViewModeUrl('agrupado') ?>" class="view-mode-btn <?= $viewMode === 'agrupado' ? 'active' : '' ?>" title="Dados agrupados por campanha">
        <span class="view-mode-icon">📊</span> Agrupado
    </a>
    <a href="<?= campViewModeUrl('diario') ?>" class="view-mode-btn <?= $viewMode === 'diario' ? 'active' : '' ?>" title="Dados dia a dia">
        <span class="view-mode-icon">📅</span> Diário
    </a>
</div>
<?php $dateFilterExtra = ob_get_clean(); ?>
<?php include __DIR__ . '/../includes/partials/date-filter-bar.php'; ?>

<!-- Summary Cards -->
<div class="cards-grid">
    <div class="card card-blue">
        <div class="card-label">Investimento Total</div>
        <div class="card-value"><?= formatMoney($totals['total_invest']) ?></div>
        <div class="card-change"><?= $totals['total_campaigns'] ?> campanhas</div>
    </div>
    <div class="card card-green">
        <div class="card-label">Receita Total</div>
        <div class="card-value"><?= formatMoney($totals['total_receita']) ?></div>
    </div>
    <?php $totalGanho = $totals['total_receita'] - $totals['total_invest']; ?>
    <div class="card <?= $totalGanho >= 0 ? 'card-green' : 'card-red' ?>">
        <div class="card-label">Lucro/Prejuízo</div>
        <div class="card-value <?= $totalGanho >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($totalGanho) ?></div>
    </div>
    <div class="card card-purple">
        <div class="card-label">Impressões / Cliques</div>
        <div class="card-value" style="font-size:18px;"><?= formatNumber($totals['total_impressoes']) ?> / <?= formatNumber($totals['total_cliques']) ?></div>
        <div class="card-change">CTR: <?= $totals['total_impressoes'] > 0 ? formatNumber(($totals['total_cliques'] / $totals['total_impressoes']) * 100, 2) . '%' : '-' ?></div>
    </div>
</div>

<!-- Data Table -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">📢 Campanhas Facebook — <?= $viewMode === 'diario' ? 'Visão Diária' : 'Totais do Período' ?></span>
        <div class="table-actions">
            <span class="badge badge-blue"><?= count($campaigns) ?> <?= $viewMode === 'diario' ? 'registros' : 'campanhas' ?></span>
            <button class="btn btn-primary btn-sm" onclick="syncFacebook()">🔄 Sincronizar FB</button>
        </div>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <?php if ($viewMode === 'diario'): ?>
                    <th><span class="sort-link">Data ↑</span></th>
                    <?php endif; ?>
                    <th>Conta</th>
                    <th><a href="<?= campUrl(['sort' => sortToggle('name', $sort)]) ?>" class="sort-link">Campanha <?= sortArrow('name', $sort) ?></a></th>
                    <th><a href="<?= campUrl(['sort' => sortToggle('invest', $sort)]) ?>" class="sort-link">Investimento <?= sortArrow('invest', $sort) ?></a></th>
                    <th><a href="<?= campUrl(['sort' => sortToggle('ganho', $sort)]) ?>" class="sort-link">Lucro <?= sortArrow('ganho', $sort) ?></a></th>
                    <th><a href="<?= campUrl(['sort' => sortToggle('impr', $sort)]) ?>" class="sort-link">Impressões <?= sortArrow('impr', $sort) ?></a></th>
                    <th><a href="<?= campUrl(['sort' => sortToggle('click', $sort)]) ?>" class="sort-link">Cliques <?= sortArrow('click', $sort) ?></a></th>
                    <th>Viz. LP</th>
                    <th>CPC</th>
                    <th>CTR</th>
                    <th>CPM</th>
                    <th><a href="<?= campUrl(['sort' => sortToggle('roas', $sort)]) ?>" class="sort-link">ROAS <?= sortArrow('roas', $sort) ?></a></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($campaigns)): ?>
                <tr><td colspan="<?= $viewMode === 'diario' ? '12' : '11' ?>" class="empty-state" style="padding:40px;">
                    <div class="empty-state-icon">📢</div>
                    <div class="empty-state-title">Nenhuma campanha encontrada</div>
                    <div class="empty-state-text">Sincronize com o Facebook ou importe um CSV</div>
                </td></tr>
                <?php else: ?>
                <?php foreach ($campaigns as $c):
                    $campaignAlertKey = ($c['account_name'] ?? '') . '|' . ($c['campaign_id'] ?? '');
                    $hasNegativeAlert = isset($negativeCampaignAlerts[$campaignAlertKey]);
                    $campaignNameFull = $c['campaign_name'] ?? '';
                    $campaignNameShort = mb_strlen($campaignNameFull) > 45
                        ? mb_substr($campaignNameFull, 0, 45) . '...'
                        : $campaignNameFull;
                ?>
                <tr class="<?= $hasNegativeAlert ? 'campaign-negative-alert' : '' ?>">
                    <?php if ($viewMode === 'diario'): ?>
                    <td><span class="badge badge-purple"><?= date('d/m/Y', strtotime($c['row_date'])) ?></span></td>
                    <?php endif; ?>
                    <td><span class="badge badge-blue"><?= $c['account_name'] ?></span></td>
                    <td title="<?= sanitize($c['campaign_name']) ?>&#10;ID: <?= $c['campaign_id'] ?>">
                        <button type="button"
                                class="campaign-copy-btn"
                                data-campaign-id="<?= sanitize($c['campaign_id']) ?>"
                                onclick="copyCampaignId(this)"
                                title="Copiar ID da campanha"
                                aria-label="Copiar ID da campanha">ID</button>
                        <?php if ($hasNegativeAlert): ?>
                            <span class="campaign-alert-icon" title="<?= sanitize($negativeAlertTooltip) ?>" aria-label="<?= sanitize($negativeAlertTooltip) ?>">&#9888;</span>
                        <?php endif; ?>
                        <button type="button"
                                class="campaign-name-toggle"
                                data-full-name="<?= sanitize($campaignNameFull) ?>"
                                data-short-name="<?= sanitize($campaignNameShort) ?>"
                                onclick="toggleCampaignName(this)"
                                title="Clique para ver o nome completo"><?= sanitize($campaignNameShort) ?></button>
                    </td>
                    <td><?= formatMoney($c['total_invest']) ?></td>
                    <td class="<?= $c['ganho'] >= 0 ? 'positive' : 'negative' ?>"><?= formatMoney($c['ganho']) ?></td>
                    <td><?= formatNumber($c['total_imp']) ?></td>
                    <td><?= formatNumber($c['total_clicks']) ?></td>
                    <td><?= formatNumber($c['total_viz_lp']) ?></td>
                    <td><?= formatMoney($c['avg_cpc']) ?></td>
                    <td><?= formatNumber($c['avg_ctr'], 2) ?>%</td>
                    <td><?= formatMoney($c['avg_cpm']) ?></td>
                    <td class="<?= $c['roas'] >= 0 ? 'positive' : 'negative' ?>"><?= formatNumber($c['roas'], 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function copyCampaignId(button) {
    const campaignId = button?.dataset?.campaignId || '';
    if (!campaignId) return;

    const originalText = button.textContent;
    const originalTitle = button.title;

    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(campaignId);
        } else {
            const input = document.createElement('input');
            input.value = campaignId;
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
        }

        button.textContent = 'OK';
        button.title = 'ID copiado';
        button.classList.add('copied');
        setTimeout(() => {
            button.textContent = originalText;
            button.title = originalTitle;
            button.classList.remove('copied');
        }, 1200);
    } catch (e) {
        alert('Nao foi possivel copiar o ID: ' + e.message);
    }
}

function toggleCampaignName(button) {
    const expanded = button.dataset.expanded === '1';
    button.dataset.expanded = expanded ? '0' : '1';
    button.textContent = expanded ? button.dataset.shortName : button.dataset.fullName;
    button.title = expanded ? 'Clique para ver o nome completo' : 'Clique para recolher o nome';
}

async function syncFacebook() {
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = '⏳ Sincronizando...';

    try {
        const res = await fetch('/api/sync.php?action=sync_fb');
        const data = await res.json();
        
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ Erro: ' + (data.error || 'Erro desconhecido'));
            btn.disabled = false;
            btn.textContent = '🔄 Sincronizar FB';
        }
    } catch (e) {
        alert('❌ Erro de conexão: ' + e.message);
        btn.disabled = false;
        btn.textContent = '🔄 Sincronizar FB';
    }
}
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
    gap: 0;
}
.view-mode-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 14px;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-muted, #888);
    text-decoration: none;
    transition: all 0.25s ease;
    cursor: pointer;
    border: none;
    background: transparent;
    white-space: nowrap;
}
.view-mode-btn:hover {
    color: var(--text-primary, #fff);
    background: rgba(255,255,255,0.04);
}
.view-mode-btn.active {
    color: #fff;
    background: var(--primary, #6366f1);
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.35);
}
.view-mode-icon {
    font-size: 13px;
}
.badge-purple {
    background: rgba(168, 85, 247, 0.15);
    color: #c084fc;
}
.campaign-negative-alert td {
    background: rgba(255, 69, 58, 0.12);
}
.campaign-negative-alert:hover td {
    background: rgba(255, 69, 58, 0.18);
}
.campaign-alert-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    margin-right: 6px;
    border-radius: 50%;
    border: 1px solid rgba(255, 69, 58, 0.35);
    background: rgba(255, 69, 58, 0.18);
    color: var(--red);
    font-size: 12px;
    font-weight: 800;
    line-height: 1;
    cursor: help;
    vertical-align: middle;
}
.campaign-copy-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 22px;
    margin-right: 8px;
    padding: 0 7px;
    border: 1px solid rgba(10, 132, 255, 0.28);
    border-radius: 6px;
    background: rgba(10, 132, 255, 0.12);
    color: var(--accent);
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
    cursor: pointer;
    vertical-align: middle;
}
.campaign-copy-btn:hover {
    background: rgba(10, 132, 255, 0.2);
}
.campaign-copy-btn.copied {
    border-color: rgba(48, 209, 88, 0.35);
    background: rgba(48, 209, 88, 0.16);
    color: var(--green);
}
.campaign-name-toggle {
    max-width: 720px;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--text-primary);
    font: inherit;
    text-align: left;
    cursor: pointer;
    vertical-align: middle;
}
.campaign-name-toggle:hover {
    color: var(--accent);
}
</style>
