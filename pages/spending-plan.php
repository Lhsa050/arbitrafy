<?php
/**
 * Plano de Gastos Page
 */

$periodo = $_GET['periodo'] ?? '';
$periodos = fetchAll("SELECT DISTINCT periodo FROM plano_gastos ORDER BY periodo DESC");

if (!$periodo && !empty($periodos)) {
    $periodo = $periodos[0]['periodo'];
}

$data = [];
$metaInfo = null;
if ($periodo) {
    $data = fetchAll("SELECT * FROM plano_gastos WHERE periodo = ? ORDER BY dia, conta, campaign_name", [$periodo]);
    $metaInfo = fetchOne("SELECT meta, restante_meta FROM plano_gastos WHERE periodo = ? AND meta > 0 LIMIT 1", [$periodo]);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'add_plan') {
        insert('plano_gastos', [
            'periodo' => sanitize($_POST['periodo']),
            'dia' => $_POST['dia'],
            'conta' => sanitize($_POST['conta']),
            'campaign_id' => sanitize($_POST['campaign_id']),
            'campaign_name' => sanitize($_POST['campaign_name']),
            'status' => sanitize($_POST['status']),
            'orcamento' => (float)$_POST['orcamento'],
            'meta' => (float)($_POST['meta'] ?? 0),
        ]);
        redirect("?page=spending-plan&periodo=" . urlencode($_POST['periodo']));
    }
    if ($_POST['action'] === 'delete_plan') {
        delete('plano_gastos', 'id = ?', [(int)$_POST['id']]);
        redirect("?page=spending-plan&periodo=" . urlencode($periodo));
    }
}
?>

<div class="filters-bar">
    <select class="filter-input" onchange="window.location='?page=spending-plan&periodo='+encodeURIComponent(this.value)">
        <option value="">Selecionar período</option>
        <?php foreach ($periodos as $p): ?>
        <option value="<?= $p['periodo'] ?>" <?= $p['periodo'] === $periodo ? 'selected' : '' ?>><?= $p['periodo'] ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('addPlanModal').classList.add('active')">+ Novo Registro</button>
</div>

<?php if ($metaInfo): ?>
<div class="cards-grid">
    <div class="card card-yellow">
        <div class="card-label">Meta</div>
        <div class="card-value"><?= formatMoney($metaInfo['meta']) ?></div>
    </div>
    <div class="card card-blue">
        <div class="card-label">Restante Meta</div>
        <div class="card-value"><?= formatMoney($metaInfo['restante_meta']) ?></div>
    </div>
    <div class="card card-green">
        <div class="card-label">Total Orçamento</div>
        <div class="card-value"><?= formatMoney(array_sum(array_column($data, 'orcamento'))) ?></div>
    </div>
    <div class="card card-purple">
        <div class="card-label">Registros</div>
        <div class="card-value"><?= count($data) ?></div>
    </div>
</div>
<?php endif; ?>

<div class="table-container">
    <div class="table-header">
        <span class="table-title">📋 Plano de Gastos — <?= sanitize($periodo) ?></span>
        <a href="?page=import&type=plano_gastos" class="btn btn-secondary btn-sm">📤 Importar CSV</a>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Dia</th><th>Conta</th><th>Campaign ID</th><th>Campanha</th>
                    <th>Status</th><th>Orçamento</th><th>Escala</th>
                    <th>Projetado</th><th>Realizado</th><th>Pacing</th>
                    <th>Tx.Cresc.Real</th><th>Tx.Cresc.Proj</th><th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data)): ?>
                <tr><td colspan="13" style="text-align:center;padding:40px;color:var(--text-muted);">Nenhum plano de gastos para este período.</td></tr>
                <?php else: foreach ($data as $r): ?>
                <tr>
                    <td><?= formatDate($r['dia']) ?></td>
                    <td><span class="badge badge-blue"><?= $r['conta'] ?></span></td>
                    <td style="font-family:monospace;font-size:11px;"><?= $r['campaign_id'] ?></td>
                    <td><?= sanitize(mb_substr($r['campaign_name'] ?? '', 0, 35)) ?></td>
                    <td><span class="badge <?= $r['status'] === 'Escalar' ? 'badge-green' : 'badge-yellow' ?>"><?= $r['status'] ?></span></td>
                    <td><?= formatMoney($r['orcamento']) ?></td>
                    <td><?= $r['escala'] ?></td>
                    <td><?= formatMoney($r['projetado']) ?></td>
                    <td><?= formatMoney($r['realizado']) ?></td>
                    <td class="<?= roiClass($r['pacing']) ?>"><?= formatPercent($r['pacing']) ?></td>
                    <td><?= formatPercentRaw($r['tx_cresc_real'] * 100) ?></td>
                    <td><?= formatPercentRaw($r['tx_cresc_proj'] * 100) ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete_plan">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="color:var(--red);padding:4px;" onclick="return confirm('Remover?')">✕</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Plan Modal -->
<div class="modal-overlay" id="addPlanModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">Novo Registro de Gasto</span>
            <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">✕</button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="action" value="add_plan">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Período</label>
                    <input type="text" name="periodo" class="form-input" value="<?= sanitize($periodo) ?>" placeholder="Ex: Fev 2026" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Dia</label>
                    <input type="date" name="dia" class="form-input" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Conta</label>
                    <input type="text" name="conta" class="form-input" placeholder="CA 01 - LLS">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option>Escalar</option><option>Manter</option><option>Pausar</option><option>Testar</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Campaign ID</label>
                <input type="text" name="campaign_id" class="form-input">
            </div>
            <div class="form-group">
                <label class="form-label">Nome Campanha</label>
                <input type="text" name="campaign_name" class="form-input">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Orçamento (R$)</label>
                    <input type="number" name="orcamento" class="form-input" step="0.01">
                </div>
                <div class="form-group">
                    <label class="form-label">Meta (R$)</label>
                    <input type="number" name="meta" class="form-input" step="0.01">
                </div>
            </div>
            <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>
