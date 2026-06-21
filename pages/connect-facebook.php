<?php
/**
 * Conectar Facebook — Página dedicada
 */

$message = '';

// Flash messages do OAuth
if (!empty($_SESSION['fb_auth_success'])) {
    $message = $_SESSION['fb_auth_success'];
    unset($_SESSION['fb_auth_success']);
} elseif (!empty($_SESSION['fb_auth_error'])) {
    $message = '❌ ' . $_SESSION['fb_auth_error'];
    unset($_SESSION['fb_auth_error']);
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_fb_app') {
        setSetting('fb_app_id', $_POST['fb_app_id'] ?? '');
        setSetting('fb_app_secret', $_POST['fb_app_secret'] ?? '');
        $message = '✅ Credenciais do App salvas!';
    }
    if ($action === 'save_fb_token') {
        setSetting('fb_access_token', $_POST['fb_access_token'] ?? '');
        setSetting('fb_api_version', $_POST['fb_api_version'] ?? 'v21.0');
        $message = '✅ Token manual salvo!';
    }
    if ($action === 'add_account') {
        $accounts = json_decode(getSetting('fb_ad_accounts', '[]'), true) ?: [];
        $accounts[] = [
            'id' => sanitize($_POST['account_id']),
            'name' => sanitize($_POST['account_name']),
        ];
        setSetting('fb_ad_accounts', json_encode($accounts));
        $message = '✅ Conta adicionada!';
    }
    if ($action === 'remove_account') {
        $accounts = json_decode(getSetting('fb_ad_accounts', '[]'), true) ?: [];
        $idx = (int)$_POST['index'];
        if (isset($accounts[$idx])) {
            array_splice($accounts, $idx, 1);
            setSetting('fb_ad_accounts', json_encode($accounts));
        }
        $message = '✅ Conta removida!';
    }
}

// Load data
$fbAppId = getSetting('fb_app_id', '');
$fbAppSecret = getSetting('fb_app_secret', '');
$fbToken = getSetting('fb_access_token', '');
$fbVersion = getSetting('fb_api_version', 'v21.0');
$fbAccounts = json_decode(getSetting('fb_ad_accounts', '[]'), true) ?: [];

// Facebook connections
try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS fb_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        fb_user_id VARCHAR(50) UNIQUE NOT NULL,
        fb_user_name VARCHAR(255),
        fb_email VARCHAR(255),
        access_token TEXT NOT NULL,
        token_expires_at DATETIME,
        ad_accounts TEXT DEFAULT '[]',
        selected_accounts TEXT DEFAULT '[]',
        status VARCHAR(20) DEFAULT 'active',
        connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $fbConnections = fetchAll("SELECT * FROM fb_connections ORDER BY connected_at DESC");
} catch (Exception $e) {
    $fbConnections = [];
}

$hasCredentials = !empty($fbAppId) && !empty($fbAppSecret);
$hasConnection = !empty($fbConnections);

$fbSvg = '<svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>';
?>

<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>

<!-- 1. Credenciais do App -->
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:16px;">🔑 Credenciais do App Facebook</h3>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
        Acesse <a href="https://developers.facebook.com/apps/" target="_blank" style="color:var(--accent);text-decoration:underline;">Facebook Developers</a>, 
        crie um App tipo "Business", ative "Facebook Login" e copie App ID + Secret.
    </p>
    <form method="POST">
        <input type="hidden" name="action" value="save_fb_app">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">App ID</label>
                <input type="text" name="fb_app_id" class="form-input" value="<?= sanitize($fbAppId) ?>" placeholder="123456789012345">
            </div>
            <div class="form-group">
                <label class="form-label">App Secret</label>
                <input type="password" name="fb_app_secret" class="form-input" value="<?= sanitize($fbAppSecret) ?>" placeholder="abc123def456...">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">💾 Salvar</button>
    </form>
</div>

<!-- 2. Conectar -->
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:16px;">🔗 Conectar Conta</h3>
    
    <?php if ($hasCredentials): ?>
        <a href="/api/fb-auth.php?action=login" class="btn-facebook">
            <?= $fbSvg ?>
            <?= $hasConnection ? 'Conectar outra conta' : 'Conectar com Facebook' ?>
        </a>
    <?php else: ?>
        <div class="alert alert-warning" style="margin:0;">
            ⚠️ Preencha as credenciais do App acima primeiro.
        </div>
    <?php endif; ?>

    <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary btn-sm" id="btnTestFBConnection" onclick="testFacebookConnection()">TESTAR CONEXÃO</button>
    </div>
    <div id="fbTestStatus" style="margin-top:12px;display:none;"></div>

    <?php if ($hasConnection): ?>
        <div style="margin-top:20px;">
            <h4 style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;">Contas conectadas</h4>
            <?php foreach ($fbConnections as $conn): 
                $userInitial = mb_strtoupper(mb_substr($conn['fb_user_name'] ?? 'U', 0, 1));
                
                // Token status
                $tokenText = '✅ Ativo';
                $tokenClass = 'active';
                $needsReconnect = false;
                
                if ($conn['status'] === 'expired') {
                    $tokenText = '❌ Expirado'; $tokenClass = 'expired'; $needsReconnect = true;
                } elseif ($conn['token_expires_at']) {
                    $expiresAt = strtotime($conn['token_expires_at']);
                    $daysLeft = max(0, floor(($expiresAt - time()) / 86400));
                    if ($daysLeft <= 0) {
                        $tokenText = '❌ Expirado'; $tokenClass = 'expired'; $needsReconnect = true;
                    } elseif ($daysLeft <= 7) {
                        $tokenText = "⚠️ {$daysLeft}d restante(s)"; $tokenClass = 'warning'; $needsReconnect = true;
                    } else {
                        $tokenText = "✅ {$daysLeft} dias";
                    }
                }
            ?>
            <div class="fb-connection-card" style="margin-bottom:12px;">
                <div class="fb-connection-header">
                    <div class="fb-connection-user">
                        <div class="fb-user-avatar"><?= $userInitial ?></div>
                        <div class="fb-user-info">
                            <h4><?= sanitize($conn['fb_user_name']) ?></h4>
                            <small><?= sanitize($conn['fb_email'] ?: 'ID: ' . $conn['fb_user_id']) ?></small>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="fb-token-status <?= $tokenClass ?>"><?= $tokenText ?></span>
                        <?php if ($needsReconnect): ?>
                            <a href="/api/fb-auth.php?action=login" class="btn btn-secondary btn-sm" style="color:var(--yellow);">🔑 Reconectar</a>
                        <?php endif; ?>
                        <form method="POST" action="/api/fb-auth.php?action=disconnect" style="display:inline;">
                            <input type="hidden" name="connection_id" value="<?= $conn['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="color:var(--red);padding:4px 10px;" 
                                    onclick="return confirm('Desconectar <?= sanitize($conn['fb_user_name']) ?>?')">🗑</button>
                        </form>
                    </div>
                </div>

                <!-- Ad Accounts -->
                <?php 
                    $allAccounts = json_decode($conn['ad_accounts'], true) ?: [];
                    $selectedAccounts = json_decode($conn['selected_accounts'], true) ?: [];
                    $selectedIds = array_column($selectedAccounts, 'id');
                ?>
                <?php if (!empty($allAccounts)): ?>
                <form method="POST" action="/api/fb-auth.php?action=save_selection">
                    <input type="hidden" name="connection_id" value="<?= $conn['id'] ?>">
                    <div class="fb-accounts-list">
                        <div class="fb-accounts-title">📋 Contas de Anúncio (<?= count($allAccounts) ?>)</div>
                        <?php foreach ($allAccounts as $acc): 
                            $isSelected = in_array($acc['id'], $selectedIds);
                            $statusClass = ($acc['status_code'] ?? 0) == 1 ? 'active' : 'inactive';
                        ?>
                        <div class="fb-account-item">
                            <input type="checkbox" name="selected_accounts[]" value="<?= sanitize($acc['id']) ?>"
                                   id="acc_<?= $conn['id'] ?>_<?= sanitize($acc['id']) ?>" <?= $isSelected ? 'checked' : '' ?>>
                            <label for="acc_<?= $conn['id'] ?>_<?= sanitize($acc['id']) ?>">
                                <span><?= sanitize($acc['name']) ?></span>
                                <span class="account-id"><?= sanitize($acc['id']) ?></span>
                                <?php if (!empty($acc['business_name'])): ?>
                                    <span class="badge badge-blue" style="font-size:10px;"><?= sanitize($acc['business_name']) ?></span>
                                <?php endif; ?>
                                <span class="account-status <?= $statusClass ?>"><?= sanitize($acc['status'] ?? 'Ativa') ?></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:12px;">
                        <button type="submit" class="btn btn-primary btn-sm">💾 Salvar Seleção</button>
                        <form method="POST" action="/api/fb-auth.php?action=refresh" style="display:inline;">
                            <input type="hidden" name="connection_id" value="<?= $conn['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">🔄 Atualizar Contas</button>
                        </form>
                    </div>
                </form>
                <?php else: ?>
                <div style="padding:16px 0;text-align:center;">
                    <p style="color:var(--text-muted);font-size:13px;margin-bottom:8px;">Nenhuma conta de anúncio encontrada.</p>
                    <form method="POST" action="/api/fb-auth.php?action=refresh" style="display:inline;">
                        <input type="hidden" name="connection_id" value="<?= $conn['id'] ?>">
                        <button type="submit" class="btn btn-secondary btn-sm">🔄 Buscar Contas</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- 3. Configuração Manual (Fallback) -->
<div class="chart-container">
    <div class="collapsible-header" onclick="toggleCollapsible(this)">
        <h3 class="chart-title" style="margin:0;">⚙️ Configuração Manual (Fallback)</h3>
        <span class="collapsible-arrow">▼</span>
    </div>
    <div class="collapsible-content">
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">
            Use apenas se não conseguir conectar via login social. Cole um Access Token manualmente.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="save_fb_token">
            <div class="form-group">
                <label class="form-label">Access Token</label>
                <input type="password" name="fb_access_token" class="form-input" value="<?= sanitize($fbToken) ?>" placeholder="EAAMrB01lM18BQ...">
            </div>
            <div class="form-group">
                <label class="form-label">API Version</label>
                <select name="fb_api_version" class="form-select">
                    <option <?= $fbVersion === 'v24.0' ? 'selected' : '' ?>>v24.0</option>
                    <option <?= $fbVersion === 'v21.0' ? 'selected' : '' ?>>v21.0</option>
                    <option <?= $fbVersion === 'v20.0' ? 'selected' : '' ?>>v20.0</option>
                    <option <?= $fbVersion === 'v19.0' ? 'selected' : '' ?>>v19.0</option>
                </select>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <button type="submit" class="btn btn-secondary btn-sm">💾 Salvar Token</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnTestFBManualConnection" onclick="testFacebookConnection('manual')">TESTAR CONEXÃO MANUAL</button>
            </div>
        </form>
        <div id="fbManualTestStatus" style="margin-top:12px;display:none;"></div>

        <!-- Contas manuais -->
        <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:16px;">
            <p style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:8px;">Contas Manuais:</p>
            <?php foreach ($fbAccounts as $i => $acc): ?>
            <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);">
                <span>
                    <span class="badge badge-blue"><?= $acc['name'] ?></span>
                    <span style="font-family:monospace;font-size:11px;margin-left:8px;"><?= $acc['id'] ?></span>
                </span>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="remove_account">
                    <input type="hidden" name="index" value="<?= $i ?>">
                    <button type="submit" class="btn btn-sm" style="color:var(--red);padding:2px 8px;">✕</button>
                </form>
            </div>
            <?php endforeach; ?>
            <form method="POST" style="margin-top:8px;">
                <input type="hidden" name="action" value="add_account">
                <div class="form-row">
                    <div class="form-group">
                        <input type="text" name="account_id" class="form-input" placeholder="act_200487373330050" required>
                    </div>
                    <div class="form-group">
                        <input type="text" name="account_name" class="form-input" placeholder="Nome da Conta" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-secondary btn-sm">+ Adicionar Conta Manual</button>
            </form>
        </div>
    </div>
</div>

<script>
function toggleCollapsible(header) {
    const content = header.nextElementSibling;
    const arrow = header.querySelector('.collapsible-arrow');
    content.classList.toggle('open');
    arrow.style.transform = content.classList.contains('open') ? 'rotate(180deg)' : 'rotate(0)';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

async function testFacebookConnection(mode = 'auto') {
    const isManual = mode === 'manual';
    const btn = document.getElementById(isManual ? 'btnTestFBManualConnection' : 'btnTestFBConnection');
    const status = document.getElementById(isManual ? 'fbManualTestStatus' : 'fbTestStatus');
    const idleText = isManual ? 'TESTAR CONEXÃO MANUAL' : 'TESTAR CONEXÃO';
    if (!btn || !status) return;

    btn.disabled = true;
    btn.textContent = 'TESTANDO...';
    status.style.display = 'block';
    status.className = 'alert alert-warning';
    status.innerHTML = isManual ? 'Testando token manual do Facebook...' : 'Testando conexão com Facebook...';

    try {
        const url = `/api/fb-auth.php?action=test_connection&mode=${encodeURIComponent(mode)}`;
        const res = await fetch(url, {
            headers: { 'Accept': 'application/json' }
        });
        const data = await res.json();
        const lines = [];

        (data.checks || []).forEach(check => {
            lines.push(`<div style="margin-top:8px;"><strong>${escapeHtml(check.name)}</strong>: ${escapeHtml(check.message || '')}</div>`);
            (check.accounts || []).slice(0, 8).forEach(account => {
                const label = account.ok ? 'OK' : 'Erro';
                const detail = account.ok
                    ? [account.currency, account.timezone].filter(Boolean).join(' • ')
                    : (account.message || '');
                lines.push(`<div style="font-size:12px;margin-left:12px;color:var(--text-muted);">${escapeHtml(label)} - ${escapeHtml(account.name)} <span style="font-family:monospace;">${escapeHtml(account.id)}</span>${detail ? ' - ' + escapeHtml(detail) : ''}</div>`);
            });
        });

        status.className = data.success ? 'alert alert-success' : 'alert alert-warning';
        status.innerHTML = `<strong>${data.success ? 'Conexão OK' : 'Atenção'}</strong><br>${escapeHtml(data.message || '')}${lines.join('')}`;
    } catch (e) {
        status.className = 'alert alert-warning';
        status.innerHTML = `<strong>Erro no teste</strong><br>${escapeHtml(e.message)}`;
    } finally {
        btn.disabled = false;
        btn.textContent = idleText;
    }
}
</script>
