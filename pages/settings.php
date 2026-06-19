<?php
/**
 * Settings Page — Configurações Gerais
 */

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_financial') {
        setSetting('cotacao_dolar', $_POST['cotacao_dolar'] ?? '5.80');
        setSetting('imposto_facebook', $_POST['imposto_facebook'] ?? '0.125');
        setSetting('imposto_outros', $_POST['imposto_outros'] ?? '0.16');
        $message = '✅ Configurações financeiras salvas!';
    }
    if ($action === 'change_password') {
        $newPass = $_POST['new_password'] ?? '';
        if (strlen($newPass) >= 4) {
            setSetting('auth_user', $_POST['new_username'] ?? 'admin');
            setSetting('auth_pass', password_hash($newPass, PASSWORD_DEFAULT));
            $message = '✅ Credenciais atualizadas!';
        } else {
            $message = '❌ Senha deve ter pelo menos 4 caracteres.';
        }
    }
    if ($action === 'add_gam_site') {
        $siteName = sanitize($_POST['site_name'] ?? '');
        $adUnitPattern = sanitize($_POST['ad_unit_pattern'] ?? '');
        if ($siteName && $adUnitPattern) {
            insert('gam_sites', ['site_name' => $siteName, 'ad_unit_pattern' => $adUnitPattern]);
            $message = '✅ Site GAM adicionado!';
        } else {
            $message = '❌ Preencha todos os campos.';
        }
    }
    if ($action === 'remove_gam_site') {
        delete('gam_sites', 'id = ?', [(int)$_POST['id']]);
        $message = '✅ Site GAM removido!';
    }
    if ($action === 'add_responsavel') {
        insert('fonte_dados_responsaveis', ['nome' => sanitize($_POST['nome'])]);
        $message = '✅ Responsável adicionado!';
    }
    if ($action === 'remove_responsavel') {
        delete('fonte_dados_responsaveis', 'id = ?', [(int)$_POST['id']]);
        $message = '✅ Responsável removido!';
    }
}

// Load data
$cotacao = getSetting('cotacao_dolar', '5.80');
$impostoFb = getSetting('imposto_facebook', '0.125');
$impostoOutros = getSetting('imposto_outros', '0.16');

try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS gam_sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_name VARCHAR(255) NOT NULL,
        ad_unit_pattern VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $gamSites = fetchAll("SELECT * FROM gam_sites ORDER BY site_name");
} catch (Exception $e) {
    $gamSites = [];
}

$responsaveis = fetchAll("SELECT * FROM fonte_dados_responsaveis ORDER BY nome");
?>

<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>

<div class="two-columns">
    <div>
        <!-- Financial Settings -->
        <div class="chart-container">
            <h3 class="chart-title" style="margin-bottom:20px;">💰 Configurações Financeiras</h3>
            <form method="POST">
                <input type="hidden" name="action" value="save_financial">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Cotação Dólar (R$)</label>
                        <input type="number" name="cotacao_dolar" class="form-input" step="0.01" value="<?= $cotacao ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">% Imposto Facebook</label>
                        <input type="number" name="imposto_facebook" class="form-input" step="0.001" value="<?= $impostoFb ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">% Outros Impostos</label>
                        <input type="number" name="imposto_outros" class="form-input" step="0.001" value="<?= $impostoOutros ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top:8px;">💾 Salvar</button>
            </form>
        </div>

        <!-- GAM Sites -->
        <div class="chart-container">
            <h3 class="chart-title" style="margin-bottom:16px;">🌐 Sites GAM (Filtro por Site)</h3>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">
                Cadastre seus sites para filtrar receita por site quando o GAM tem múltiplos domínios. 
                O "Padrão Ad Unit" é o texto que identifica o site no nome do Ad Unit do GAM.
            </p>
            <?php foreach ($gamSites as $site): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);">
                <span>
                    <span class="badge badge-green"><?= sanitize($site['site_name']) ?></span>
                    <span style="font-family:monospace;font-size:11px;margin-left:8px;color:var(--text-muted);"><?= sanitize($site['ad_unit_pattern']) ?></span>
                </span>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="remove_gam_site">
                    <input type="hidden" name="id" value="<?= $site['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="color:var(--red);padding:2px 8px;" onclick="return confirm('Remover este site?')">✕</button>
                </form>
            </div>
            <?php endforeach; ?>
            <?php if (empty($gamSites)): ?>
            <span style="color:var(--text-muted);font-size:12px;">Nenhum site cadastrado.</span>
            <?php endif; ?>

            <form method="POST" style="margin-top:12px;">
                <input type="hidden" name="action" value="add_gam_site">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nome do Site</label>
                        <input type="text" name="site_name" class="form-input" placeholder="meusite.com.br" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Padrão Ad Unit</label>
                        <input type="text" name="ad_unit_pattern" class="form-input" placeholder="meusite ou /12345/meusite" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-secondary btn-sm">+ Adicionar Site</button>
            </form>
        </div>
    </div>

    <div>
        <!-- Change Password -->
        <div class="chart-container">
            <h3 class="chart-title" style="margin-bottom:20px;">🔒 Alterar Credenciais</h3>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label class="form-label">Novo Usuário</label>
                    <input type="text" name="new_username" class="form-input" value="admin">
                </div>
                <div class="form-group">
                    <label class="form-label">Nova Senha</label>
                    <input type="password" name="new_password" class="form-input" required minlength="4">
                </div>
                <button type="submit" class="btn btn-primary">🔒 Atualizar</button>
            </form>
        </div>

        <!-- Responsáveis -->
        <div class="chart-container">
            <h3 class="chart-title" style="margin-bottom:16px;">👥 Responsáveis (Tarefas)</h3>
            <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:12px;">
                <?php foreach ($responsaveis as $r): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);">
                    <span class="badge badge-blue"><?= $r['nome'] ?></span>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="remove_responsavel">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button type="submit" class="btn btn-sm" style="color:var(--red);padding:2px 8px;" onclick="return confirm('Remover este responsável?')">✕</button>
                    </form>
                </div>
                <?php endforeach; ?>
                <?php if (empty($responsaveis)): ?>
                <span style="color:var(--text-muted);font-size:12px;">Nenhum responsável cadastrado</span>
                <?php endif; ?>
            </div>
            <form method="POST" class="form-inline">
                <input type="hidden" name="action" value="add_responsavel">
                <input type="text" name="nome" class="form-input" placeholder="Nome" required style="flex:1;">
                <button type="submit" class="btn btn-secondary btn-sm">+ Adicionar</button>
            </form>
        </div>
    </div>
</div>
