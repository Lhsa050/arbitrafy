<?php
/**
 * Tarefas / Kanban Page (Mentoria Alvo10k)
 */

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'add_task') {
        insert('tarefas', [
            'status' => sanitize($_POST['status']),
            'prazo' => $_POST['prazo'] ?: null,
            'descricao' => sanitize($_POST['descricao']),
            'responsavel' => sanitize($_POST['responsavel']),
            'prioridade' => sanitize($_POST['prioridade']),
            'formato' => sanitize($_POST['formato'] ?? ''),
            'escopo' => sanitize($_POST['escopo'] ?? ''),
            'obs' => sanitize($_POST['obs'] ?? ''),
        ]);
        redirect('?page=tasks');
    }
    if ($_POST['action'] === 'update_status') {
        update('tarefas', ['status' => sanitize($_POST['status'])], 'id = ?', [(int)$_POST['id']]);
        redirect('?page=tasks');
    }
    if ($_POST['action'] === 'delete_task') {
        delete('tarefas', 'id = ?', [(int)$_POST['id']]);
        redirect('?page=tasks');
    }
}

$statuses = ['Pendente', 'Em Andamento', 'Concluído', 'Arquivado'];
$statusColors = ['Pendente' => 'badge-yellow', 'Em Andamento' => 'badge-blue', 'Concluído' => 'badge-green', 'Arquivado' => 'badge-red'];

$tasks = fetchAll("SELECT * FROM tarefas ORDER BY
    CASE status WHEN 'Em Andamento' THEN 1 WHEN 'Pendente' THEN 2 WHEN 'Concluído' THEN 3 ELSE 4 END,
    CASE prioridade WHEN '0. Urgente' THEN 1 WHEN '1. Alta' THEN 2 WHEN '2. Média' THEN 3 ELSE 4 END,
    created_at DESC
");

$tasksByStatus = [];
foreach ($statuses as $s) $tasksByStatus[$s] = [];
foreach ($tasks as $t) {
    $tasksByStatus[$t['status']][] = $t;
}

$responsaveis = fetchAll("SELECT nome FROM fonte_dados_responsaveis ORDER BY nome");
$prioridades = fetchAll("SELECT nome FROM fonte_dados_prioridades ORDER BY nome");
?>

<div class="filters-bar">
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('addTaskModal').classList.add('active')">+ Nova Tarefa</button>
    <span class="badge badge-blue"><?= count($tasks) ?> tarefas</span>
</div>

<!-- Kanban Board -->
<div class="kanban-board">
    <?php foreach ($statuses as $status): ?>
    <div class="kanban-column">
        <div class="kanban-column-header">
            <span><?= $status ?></span>
            <span class="kanban-column-count"><?= count($tasksByStatus[$status]) ?></span>
        </div>
        <div class="kanban-cards">
            <?php foreach ($tasksByStatus[$status] as $t): ?>
            <div class="kanban-card">
                <div class="kanban-card-title"><?= $t['descricao'] ?></div>
                <div class="kanban-card-meta">
                    <?php if ($t['responsavel']): ?><span class="badge badge-blue"><?= $t['responsavel'] ?></span><?php endif; ?>
                    <span class="badge <?= $statusColors[$t['prioridade']] ?? 'badge-yellow' ?>"><?= $t['prioridade'] ?></span>
                    <?php if ($t['prazo']): ?><span>📅 <?= formatDate($t['prazo']) ?></span><?php endif; ?>
                </div>
                <?php if ($t['obs']): ?><div style="font-size:11px;color:var(--text-muted);margin-top:8px;"><?= $t['obs'] ?></div><?php endif; ?>
                <div style="margin-top:10px;display:flex;gap:4px;flex-wrap:wrap;">
                    <?php foreach ($statuses as $s): if ($s === $status) continue; ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                        <input type="hidden" name="status" value="<?= $s ?>">
                        <button type="submit" class="btn btn-sm btn-secondary" style="font-size:10px;padding:3px 8px;">→ <?= $s ?></button>
                    </form>
                    <?php endforeach; ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="delete_task">
                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-sm" style="color:var(--red);font-size:10px;padding:3px 8px;" onclick="return confirm('Remover?')">✕</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($tasksByStatus[$status])): ?>
            <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:12px;">Nenhuma tarefa</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add Task Modal -->
<div class="modal-overlay" id="addTaskModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">Nova Tarefa</span>
            <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('active')">✕</button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="action" value="add_task">
            <div class="form-group">
                <label class="form-label">Descrição</label>
                <textarea name="descricao" class="form-textarea" required placeholder="Descreva a tarefa..."></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach ($statuses as $s): ?><option><?= $s ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Prioridade</label>
                    <select name="prioridade" class="form-select">
                        <?php foreach ($prioridades as $p): ?><option><?= $p['nome'] ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Responsável</label>
                    <select name="responsavel" class="form-select">
                        <option value="">Sem responsável</option>
                        <?php foreach ($responsaveis as $r): ?><option><?= $r['nome'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Prazo</label>
                    <input type="date" name="prazo" class="form-input">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Formato</label>
                <input type="text" name="formato" class="form-input" placeholder="Vídeo, Documento, etc.">
            </div>
            <div class="form-group">
                <label class="form-label">Escopo</label>
                <textarea name="escopo" class="form-textarea" style="min-height:60px;" placeholder="Escopo das aulas..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Observações</label>
                <textarea name="obs" class="form-textarea" style="min-height:60px;"></textarea>
            </div>
            <div class="modal-footer" style="padding:0;border:none;margin-top:16px;">
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>
