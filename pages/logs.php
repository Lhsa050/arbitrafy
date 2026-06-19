<?php
/**
 * Sync Logs Page — GAM + FB unified logs
 */

// Ensure table exists (for existing deployments that haven't re-run setup)
try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS sync_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        source VARCHAR(20) NOT NULL,
        level VARCHAR(20) DEFAULT 'ERROR',
        step VARCHAR(100),
        message TEXT,
        details TEXT,
        http_code INTEGER,
        duration_ms INTEGER
    )");
} catch (Exception $e) {}

// Handle clear logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_logs') {
    $clearSource = $_POST['clear_source'] ?? 'all';
    if ($clearSource === 'all') {
        query("DELETE FROM sync_logs");
    } else {
        query("DELETE FROM sync_logs WHERE source = ?", [$clearSource]);
    }
    header('Location: ?page=logs&cleared=1');
    exit;
}

// Filters
$filterSource = $_GET['source'] ?? '';
$filterLevel = $_GET['level'] ?? '';

$where = "1=1";
$params = [];
if ($filterSource) {
    $where .= " AND source = ?";
    $params[] = $filterSource;
}
if ($filterLevel) {
    $where .= " AND level = ?";
    $params[] = $filterLevel;
}

$logs = fetchAll("SELECT * FROM sync_logs WHERE {$where} ORDER BY timestamp DESC LIMIT 500", $params);

// Stats
$statTotal = fetchOne("SELECT COUNT(*) as c FROM sync_logs")['c'] ?? 0;
$statErrors = fetchOne("SELECT COUNT(*) as c FROM sync_logs WHERE level = 'ERROR'")['c'] ?? 0;
$statWarnings = fetchOne("SELECT COUNT(*) as c FROM sync_logs WHERE level = 'WARNING'")['c'] ?? 0;
$statGam = fetchOne("SELECT COUNT(*) as c FROM sync_logs WHERE source = 'GAM'")['c'] ?? 0;
$statFb = fetchOne("SELECT COUNT(*) as c FROM sync_logs WHERE source = 'FB'")['c'] ?? 0;
$statGads = fetchOne("SELECT COUNT(*) as c FROM sync_logs WHERE source = 'GADS'")['c'] ?? 0;
$statGa4 = fetchOne("SELECT COUNT(*) as c FROM sync_logs WHERE source = 'GA4'")['c'] ?? 0;
$lastError = fetchOne("SELECT timestamp FROM sync_logs WHERE level = 'ERROR' ORDER BY timestamp DESC LIMIT 1");
?>

<!-- Stats Cards -->
<div class="cards-grid" style="margin-bottom: 16px;">
    <div class="card">
        <div class="card-icon">📋</div>
        <div class="card-label">Total de Logs</div>
        <div class="card-value" style="font-size:22px;"><?= $statTotal ?></div>
    </div>
    <div class="card card-red">
        <div class="card-icon">🔴</div>
        <div class="card-label">Erros</div>
        <div class="card-value" style="font-size:22px;"><?= $statErrors ?></div>
        <div class="card-change"><?= $lastError ? 'Último: ' . formatDateTime($lastError['timestamp']) : 'Nenhum erro' ?></div>
    </div>
    <div class="card">
        <div class="card-icon">🟡</div>
        <div class="card-label">Warnings</div>
        <div class="card-value" style="font-size:22px;"><?= $statWarnings ?></div>
    </div>
    <div class="card">
        <div class="card-icon">🔀</div>
        <div class="card-label">Por Fonte</div>
        <div class="card-value" style="font-size:16px;">GAM: <?= $statGam ?> | FB: <?= $statFb ?> | GAds: <?= $statGads ?> | GA4: <?= $statGa4 ?></div>
    </div>
</div>

<?php if (isset($_GET['cleared'])): ?>
<div class="alert alert-success" style="margin-bottom:16px;">✅ Logs limpos com sucesso!</div>
<?php endif; ?>

<!-- Filters -->
<div class="filters-bar" style="margin-bottom: 16px;">
    <select class="filter-input" id="logSourceFilter" onchange="applyLogFilters()">
        <option value="">Todas as Fontes</option>
        <option value="GAM" <?= $filterSource === 'GAM' ? 'selected' : '' ?>>🟢 GAM</option>
        <option value="FB" <?= $filterSource === 'FB' ? 'selected' : '' ?>>🔵 Facebook</option>
        <option value="GADS" <?= $filterSource === 'GADS' ? 'selected' : '' ?>>🟠 Google Ads</option>
        <option value="GA4" <?= $filterSource === 'GA4' ? 'selected' : '' ?>>📈 GA4</option>
    </select>
    <select class="filter-input" id="logLevelFilter" onchange="applyLogFilters()">
        <option value="">Todos os Níveis</option>
        <option value="ERROR" <?= $filterLevel === 'ERROR' ? 'selected' : '' ?>>🔴 ERROR</option>
        <option value="WARNING" <?= $filterLevel === 'WARNING' ? 'selected' : '' ?>>🟡 WARNING</option>
        <option value="INFO" <?= $filterLevel === 'INFO' ? 'selected' : '' ?>>🟢 INFO</option>
    </select>
    <form method="POST" style="display:inline;" onsubmit="return confirm('Tem certeza? Isso vai apagar os logs selecionados.')">
        <input type="hidden" name="action" value="clear_logs">
        <input type="hidden" name="clear_source" value="<?= $filterSource ?: 'all' ?>">
        <button type="submit" class="btn btn-secondary btn-sm" style="background: var(--danger); border-color: var(--danger);">🗑️ Limpar <?= $filterSource ?: 'Todos' ?></button>
    </form>
</div>

<!-- Logs Table -->
<div class="table-container">
    <div class="table-header">
        <span class="table-title">📋 Sync Logs</span>
        <span class="badge badge-blue"><?= count($logs) ?> registros</span>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Fonte</th>
                    <th>Nível</th>
                    <th>Step</th>
                    <th>Mensagem</th>
                    <th>HTTP</th>
                    <th>Duração</th>
                    <th>Detalhes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">Nenhum log registrado. Os logs aparecerão automaticamente ao sincronizar.</td></tr>
                <?php else: foreach ($logs as $l): ?>
                <tr>
                    <td style="white-space:nowrap;font-size:12px;"><?= formatDateTime($l['timestamp']) ?></td>
                    <td>
                        <?php if ($l['source'] === 'GAM'): ?>
                            <span class="badge badge-green">GAM</span>
                        <?php elseif ($l['source'] === 'GADS'): ?>
                            <span class="badge badge-yellow">GAds</span>
                        <?php elseif ($l['source'] === 'GA4'): ?>
                            <span class="badge badge-purple">GA4</span>
                        <?php else: ?>
                            <span class="badge badge-blue">FB</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $levelClass = match($l['level']) {
                            'ERROR' => 'badge-red',
                            'WARNING' => 'badge-yellow',
                            'INFO' => 'badge-green',
                            default => 'badge-blue',
                        };
                        $levelIcon = match($l['level']) {
                            'ERROR' => '🔴',
                            'WARNING' => '🟡',
                            'INFO' => '🟢',
                            default => '⚪',
                        };
                        ?>
                        <span class="badge <?= $levelClass ?>"><?= $levelIcon ?> <?= $l['level'] ?></span>
                    </td>
                    <td style="font-family:monospace;font-size:11px;color:var(--text-muted);"><?= sanitize($l['step'] ?? '') ?></td>
                    <td style="max-width:350px;overflow:hidden;text-overflow:ellipsis;" title="<?= sanitize($l['message'] ?? '') ?>">
                        <?= sanitize(mb_substr($l['message'] ?? '', 0, 120)) ?>
                    </td>
                    <td>
                        <?php if ($l['http_code']): ?>
                            <span class="badge <?= $l['http_code'] >= 400 ? 'badge-red' : 'badge-green' ?>"><?= $l['http_code'] ?></span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;white-space:nowrap;">
                        <?= $l['duration_ms'] ? number_format($l['duration_ms']) . 'ms' : '-' ?>
                    </td>
                    <td>
                        <?php if (!empty($l['details'])): ?>
                            <button class="btn btn-sm btn-secondary" onclick="toggleDetails(this)" style="font-size:11px;padding:2px 8px;">👁️ Ver</button>
                            <div class="log-details" style="display:none;margin-top:8px;padding:8px;background:var(--bg-primary);border:1px solid var(--border);border-radius:6px;font-family:monospace;font-size:11px;white-space:pre-wrap;max-width:400px;max-height:200px;overflow:auto;color:var(--text-muted);"><?= sanitize($l['details']) ?></div>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function applyLogFilters() {
    const source = document.getElementById('logSourceFilter').value;
    const level = document.getElementById('logLevelFilter').value;
    const params = new URLSearchParams({ page: 'logs' });
    if (source) params.set('source', source);
    if (level) params.set('level', level);
    window.location.href = '?' + params.toString();
}

function toggleDetails(btn) {
    const details = btn.nextElementSibling;
    if (details.style.display === 'none') {
        details.style.display = 'block';
        btn.textContent = '🙈 Ocultar';
    } else {
        details.style.display = 'none';
        btn.textContent = '👁️ Ver';
    }
}
</script>

<style>
.badge-yellow {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.3);
}
.badge-red {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.3);
}
.badge-green {
    background: rgba(16, 185, 129, 0.15);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.3);
}
.badge-purple {
    background: rgba(139, 92, 246, 0.15);
    color: #8b5cf6;
    border: 1px solid rgba(139, 92, 246, 0.3);
}
</style>
