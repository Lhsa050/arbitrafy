<?php
/**
 * Update page.
 */
require_once __DIR__ . '/../includes/updater.php';

$message = '';
$error = '';
$latest = null;
$result = null;

if (empty($_SESSION['update_csrf'])) {
    $_SESSION['update_csrf'] = bin2hex(random_bytes(32));
}

function updatePageCheckCsrf(): void {
    $expected = $_SESSION['update_csrf'] ?? '';
    $received = $_POST['csrf'] ?? '';

    if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
        throw new Exception('Sessao expirada. Recarregue a pagina e tente novamente.');
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        updatePageCheckCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'save_update_settings') {
            updaterSaveConfig(
                $_POST['repo'] ?? '',
                $_POST['branch'] ?? '',
                trim($_POST['token'] ?? ''),
                isset($_POST['clear_token'])
            );
            $message = 'Configuracao de atualizacao salva.';
        }

        if ($action === 'check_update') {
            $latest = updaterCheckLatest();
            if ($latest['has_update'] === false) {
                $message = 'O sistema ja esta na versao mais recente conhecida.';
            } elseif ($latest['has_update'] === true) {
                $message = 'Atualizacao encontrada no GitHub.';
            } else {
                $message = 'Versao do GitHub encontrada. Esta instalacao ainda nao tem uma versao registrada.';
            }
        }

        if ($action === 'apply_update') {
            $result = updaterApplyUpdate();
            $latest = $result['latest'];
            $message = 'Atualizacao aplicada com sucesso.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$config = updaterConfig();
$protectedPaths = updaterProtectedPaths();
?>

<?php if ($message): ?>
<div class="alert alert-success" style="margin-bottom:16px;"><?= sanitize($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-error" style="margin-bottom:16px;"><?= sanitize($error) ?></div>
<?php endif; ?>

<div class="cards-grid" style="margin-bottom:16px;">
    <div class="card">
        <div class="card-label">Repositorio</div>
        <div class="card-value" style="font-size:18px;"><?= sanitize($config['repo']) ?></div>
        <div class="card-change"><?= sanitize($config['branch']) ?></div>
    </div>
    <div class="card">
        <div class="card-label">Versao instalada</div>
        <div class="card-value" style="font-size:18px;"><?= $config['current_sha'] ? sanitize(substr($config['current_sha'], 0, 7)) : '-' ?></div>
        <div class="card-change"><?= $config['last_run_at'] ? sanitize(formatDateTime($config['last_run_at'])) : 'Sem registro' ?></div>
    </div>
    <div class="card">
        <div class="card-label">Token GitHub</div>
        <div class="card-value" style="font-size:18px;"><?= sanitize(updaterTokenPreview($config['token'])) ?></div>
        <div class="card-change">Necessario para repositorio privado</div>
    </div>
    <div class="card">
        <div class="card-label">Backup</div>
        <div class="card-value" style="font-size:18px;">Automatico</div>
        <div class="card-change">Antes de copiar arquivos</div>
    </div>
</div>

<div class="two-columns">
    <div>
        <div class="chart-container">
            <h3 class="chart-title" style="margin-bottom:20px;">Atualizacao pelo GitHub</h3>

            <form method="POST">
                <input type="hidden" name="csrf" value="<?= sanitize($_SESSION['update_csrf']) ?>">
                <input type="hidden" name="action" value="save_update_settings">

                <div class="form-group">
                    <label class="form-label">Repositorio GitHub</label>
                    <input type="text" name="repo" class="form-input" value="<?= sanitize($config['repo']) ?>" placeholder="owner/repo" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Branch</label>
                    <input type="text" name="branch" class="form-input" value="<?= sanitize($config['branch']) ?>" placeholder="main" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Token GitHub</label>
                    <input type="password" name="token" class="form-input" value="" placeholder="<?= $config['token'] ? 'Token salvo: ' . sanitize(updaterTokenPreview($config['token'])) : 'Opcional para repositorio publico' ?>">
                </div>

                <?php if ($config['token']): ?>
                <label style="display:flex;align-items:center;gap:8px;margin:8px 0 16px;color:var(--text-secondary);font-size:13px;">
                    <input type="checkbox" name="clear_token" value="1">
                    Remover token salvo
                </label>
                <?php endif; ?>

                <button type="submit" class="btn btn-secondary">Salvar configuracao</button>
            </form>
        </div>

        <div class="chart-container">
            <h3 class="chart-title" style="margin-bottom:16px;">Arquivos protegidos</h3>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ($protectedPaths as $path): ?>
                    <span class="badge badge-blue"><?= sanitize($path) ?></span>
                <?php endforeach; ?>
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-top:14px;">
                Estes caminhos nao sao substituidos durante a atualizacao para preservar banco, uploads e credenciais do servidor.
            </p>
        </div>
    </div>

    <div>
        <div class="chart-container">
            <h3 class="chart-title" style="margin-bottom:20px;">Buscar atualizacoes</h3>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= sanitize($_SESSION['update_csrf']) ?>">
                    <input type="hidden" name="action" value="check_update">
                    <button type="submit" class="btn btn-secondary">Buscar atualizacoes</button>
                </form>

                <form method="POST" onsubmit="return confirm('Aplicar a atualizacao agora? Um backup sera criado antes de copiar os arquivos.')">
                    <input type="hidden" name="csrf" value="<?= sanitize($_SESSION['update_csrf']) ?>">
                    <input type="hidden" name="action" value="apply_update">
                    <button type="submit" class="btn btn-primary">Atualizar agora</button>
                </form>
            </div>

            <?php if ($latest): ?>
            <div style="border:1px solid var(--border);border-radius:12px;padding:14px;background:var(--bg-input);">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px;">
                    <div>
                        <div style="font-size:12px;color:var(--text-muted);">Ultimo commit no GitHub</div>
                        <div style="font-size:18px;font-weight:700;"><?= sanitize($latest['short_sha']) ?></div>
                    </div>
                    <?php if ($latest['has_update'] === true): ?>
                        <span class="badge badge-green">Atualizacao disponivel</span>
                    <?php elseif ($latest['has_update'] === false): ?>
                        <span class="badge badge-blue">Atualizado</span>
                    <?php else: ?>
                        <span class="badge badge-yellow">Primeira verificacao</span>
                    <?php endif; ?>
                </div>

                <div style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;">
                    <?= sanitize($latest['message'] ?: 'Sem mensagem de commit') ?>
                </div>

                <?php if ($latest['author_date']): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">
                    Data GitHub: <?= sanitize(date('d/m/Y H:i', strtotime($latest['author_date']))) ?>
                </div>
                <?php endif; ?>

                <?php if ($latest['html_url']): ?>
                <a href="<?= sanitize($latest['html_url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Ver commit</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="alert alert-info" style="margin:0;">
                Clique em buscar para consultar o ultimo commit da branch configurada.
            </div>
            <?php endif; ?>
        </div>

        <?php if ($result): ?>
        <div class="chart-container">
            <h3 class="chart-title" style="margin-bottom:16px;">Resultado</h3>
            <div style="display:grid;gap:10px;">
                <div>
                    <span class="badge badge-green"><?= (int)$result['copied'] ?> arquivos copiados</span>
                    <span class="badge badge-blue"><?= (int)$result['backup_count'] ?> arquivos no backup</span>
                </div>
                <div style="font-size:13px;color:var(--text-secondary);">
                    Backup: <span style="font-family:monospace;"><?= sanitize($result['backup']) ?></span>
                </div>
                <?php if (!empty($result['skipped'])): ?>
                <div>
                    <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">Protegidos nesta atualizacao</div>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                        <?php foreach (array_slice($result['skipped'], 0, 12) as $path): ?>
                            <span class="badge badge-blue"><?= sanitize($path) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
