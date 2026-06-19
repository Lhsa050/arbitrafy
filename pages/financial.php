<?php
/**
 * Financeiro Page
 */

$ano = (int)($_GET['ano'] ?? date('Y'));

// Get all costs grouped by month
$custos = fetchAll("
    SELECT * FROM financeiro_custos
    WHERE ano = ?
    ORDER BY mes_num, tipo, descricao
", [$ano]);

// Get payments
$pagamentos = fetchAll("
    SELECT * FROM financeiro_pagamentos
    WHERE ano = ?
    ORDER BY id
", [$ano]);

// Group costs by month
$custosPorMes = [];
foreach ($custos as $c) {
    $custosPorMes[$c['mes']][$c['tipo']][] = $c;
}

// Handle form submission (add cost)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_cost') {
        insert('financeiro_custos', [
            'ano' => $ano,
            'mes' => sanitize($_POST['mes']),
            'mes_num' => getMonthNum($_POST['mes']),
            'tipo' => sanitize($_POST['tipo']),
            'descricao' => sanitize($_POST['descricao']),
            'valor' => (float)$_POST['valor']
        ]);
        echo '<script>window.location.href="?page=financial&ano=' . $ano . '";</script>';
        return;
    }
    if ($_POST['action'] === 'add_payment') {
        insert('financeiro_pagamentos', [
            'ano' => $ano,
            'mes' => sanitize($_POST['mes']),
            'valor' => (float)$_POST['valor'],
            'saldo' => (float)$_POST['saldo']
        ]);
        echo '<script>window.location.href="?page=financial&ano=' . $ano . '";</script>';
        return;
    }
    if ($_POST['action'] === 'delete_cost') {
        delete('financeiro_custos', 'id = ?', [(int)$_POST['id']]);
        echo '<script>window.location.href="?page=financial&ano=' . $ano . '";</script>';
        return;
    }
}

$meses = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
?>

<div class="filters-bar">
    <select class="filter-input" onchange="window.location='?page=financial&ano='+this.value">
        <?php for ($y = 2024; $y <= 2027; $y++): ?>
        <option value="<?= $y ?>" <?= $y == $ano ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
    </select>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('addCostModal').classList.add('active')">
        + Adicionar Custo
    </button>
    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('addPaymentModal').classList.add('active')">
        + Pagamento Google
    </button>
</div>

<!-- Financial Grid by Month -->
<div class="financial-grid">
    <?php foreach ($meses as $i => $mes): ?>
    <?php
        $fixos = $custosPorMes[$mes]['fixo'] ?? [];
        $variaveis = $custosPorMes[$mes]['variavel'] ?? [];
        $totalFixo = array_sum(array_column($fixos, 'valor'));
        $totalVar = array_sum(array_column($variaveis, 'valor'));
        $totalMes = $totalFixo + $totalVar;
        if (empty($fixos) && empty($variaveis)) continue;
    ?>
    <div class="financial-month">
        <div class="financial-month-header">
            🗓️ <?= $mes ?>
            <span class="badge <?= $totalMes > 0 ? 'badge-red' : 'badge-green' ?>">
                Total: <?= formatMoney($totalMes) ?>
            </span>
        </div>
        <div class="financial-month-body">
            <?php if (!empty($fixos)): ?>
            <h4 style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">CUSTOS FIXOS</h4>
            <?php foreach ($fixos as $c): ?>
            <div class="cost-row">
                <span class="cost-label"><?= $c['descricao'] ?></span>
                <span class="cost-value">
                    <?= formatMoney($c['valor']) ?>
                    <button onclick="deleteCost(<?= $c['id'] ?>)" class="btn btn-sm" style="padding:2px 6px;font-size:10px;color:var(--red);">✕</button>
                </span>
            </div>
            <?php endforeach; ?>
            <div class="cost-row total">
                <span>Subtotal Fixo</span>
                <span><?= formatMoney($totalFixo) ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($variaveis)): ?>
            <h4 style="font-size:12px;color:var(--text-muted);margin:12px 0 8px;">CUSTOS VARIÁVEIS</h4>
            <?php foreach ($variaveis as $c): ?>
            <div class="cost-row">
                <span class="cost-label"><?= $c['descricao'] ?></span>
                <span class="cost-value">
                    <?= formatMoney($c['valor']) ?>
                    <button onclick="deleteCost(<?= $c['id'] ?>)" class="btn btn-sm" style="padding:2px 6px;font-size:10px;color:var(--red);">✕</button>
                </span>
            </div>
            <?php endforeach; ?>
            <div class="cost-row total">
                <span>Subtotal Variável</span>
                <span><?= formatMoney($totalVar) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagamentos Google -->
<?php if (!empty($pagamentos)): ?>
<div class="section" style="margin-top:32px;">
    <h3 class="section-title">💳 Pagamentos Google</h3>
    <div class="table-container">
        <div class="table-scroll">
            <table>
                <thead><tr><th>Mês</th><th>Valor</th><th>Saldo</th></tr></thead>
                <tbody>
                    <?php foreach ($pagamentos as $p): ?>
                    <tr>
                        <td><?= $p['mes'] ?></td>
                        <td><?= formatMoney($p['valor']) ?></td>
                        <td class="<?= roiClass($p['saldo']) ?>"><?= formatMoney($p['saldo']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add Cost Modal -->
<div class="modal-overlay" id="addCostModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">Adicionar Custo</span>
            <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">✕</button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="action" value="add_cost">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Mês</label>
                    <select name="mes" class="form-select" required>
                        <?php foreach ($meses as $m): ?>
                        <option><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select" required>
                        <option value="fixo">Custo Fixo</option>
                        <option value="variavel">Custo Variável</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Descrição</label>
                <input type="text" name="descricao" class="form-input" required placeholder="Ex: Hospedagem, MEI, Contas Facebook...">
            </div>
            <div class="form-group">
                <label class="form-label">Valor (R$)</label>
                <input type="number" name="valor" class="form-input" step="0.01" required>
            </div>
            <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Payment Modal -->
<div class="modal-overlay" id="addPaymentModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">Adicionar Pagamento Google</span>
            <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">✕</button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="action" value="add_payment">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Mês</label>
                    <select name="mes" class="form-select" required>
                        <?php foreach ($meses as $m): ?>
                        <option><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Valor (R$)</label>
                    <input type="number" name="valor" class="form-input" step="0.01" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Saldo (R$)</label>
                    <input type="number" name="saldo" class="form-input" step="0.01" required>
                </div>
            </div>
            <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
function deleteCost(id) {
    if (!confirm('Remover este custo?')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '?page=financial&ano=<?= $ano ?>';
    form.innerHTML = '<input type="hidden" name="action" value="delete_cost"><input type="hidden" name="id" value="'+id+'">';
    document.body.appendChild(form);
    form.submit();
}
</script>
