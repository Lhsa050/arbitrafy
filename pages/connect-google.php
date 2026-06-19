<?php
/**
 * Conectar Google Ads — OAuth + Manual
 */

$message = '';

// Flash messages do OAuth
if (!empty($_SESSION['google_auth_success'])) {
    $message = $_SESSION['google_auth_success'];
    unset($_SESSION['google_auth_success']);
} elseif (!empty($_SESSION['google_auth_error'])) {
    $message = '❌ ' . $_SESSION['google_auth_error'];
    unset($_SESSION['google_auth_error']);
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_google_oauth') {
        setSetting('google_oauth_client_id', $_POST['google_oauth_client_id'] ?? '');
        setSetting('google_oauth_client_secret', $_POST['google_oauth_client_secret'] ?? '');
        setSetting('gads_developer_token', $_POST['gads_developer_token'] ?? '');
        $message = '✅ Credenciais Google Ads salvas!';
    }
    if ($action === 'save_gads_settings') {
        setSetting('gads_customer_id', $_POST['gads_customer_id'] ?? '');
        if (isset($_POST['gads_developer_token'])) {
            setSetting('gads_developer_token', $_POST['gads_developer_token'] ?? '');
        }
        setSetting('gads_client_id', $_POST['gads_client_id'] ?? '');
        setSetting('gads_client_secret', $_POST['gads_client_secret'] ?? '');
        setSetting('gads_refresh_token', $_POST['gads_refresh_token'] ?? '');
        $message = '✅ Configurações Google Ads salvas!';
    }
}

// Load data
$googleClientId = getSetting('google_oauth_client_id', '');
$googleClientSecret = getSetting('google_oauth_client_secret', '');
$gadsDevToken = getSetting('gads_developer_token', '');
$gadsCustomerId = getSetting('gads_customer_id', '');
$gadsClientId = getSetting('gads_client_id', '');
$gadsClientSecret = getSetting('gads_client_secret', '');
$gadsRefreshToken = getSetting('gads_refresh_token', '');

// Google connections (for OAuth-based Google Ads)
try {
    getDB()->exec("CREATE TABLE IF NOT EXISTS google_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        google_user_id VARCHAR(100) UNIQUE NOT NULL,
        google_user_name VARCHAR(255),
        google_email VARCHAR(255),
        google_avatar VARCHAR(500),
        refresh_token TEXT NOT NULL,
        access_token TEXT,
        token_expires_at DATETIME,
        gads_accounts TEXT DEFAULT '[]',
        gads_selected TEXT DEFAULT '[]',
        gam_networks TEXT DEFAULT '[]',
        gam_selected TEXT DEFAULT '[]',
        status VARCHAR(20) DEFAULT 'active',
        connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $googleConnections = fetchAll("SELECT * FROM google_connections ORDER BY connected_at DESC");
} catch (Exception $e) {
    $googleConnections = [];
}

$hasCredentials = !empty($googleClientId) && !empty($googleClientSecret);
$hasConnection = !empty($googleConnections);
?>

<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>

<!-- 1. Credenciais OAuth -->
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:16px;">🔑 Credenciais OAuth Google Ads</h3>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
        Acesse o <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:var(--accent);text-decoration:underline;">Google Cloud Console</a>, 
        crie credenciais OAuth 2.0 e configure a URI de redirecionamento:<br>
        <code style="color:var(--green);font-size:11px;">https://SEU-DOMINIO/api/google-auth.php?action=callback</code>
    </p>
    <form method="POST">
        <input type="hidden" name="action" value="save_google_oauth">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">OAuth Client ID</label>
                <input type="text" name="google_oauth_client_id" class="form-input" value="<?= sanitize($googleClientId) ?>" placeholder="123456789.apps.googleusercontent.com">
            </div>
            <div class="form-group">
                <label class="form-label">OAuth Client Secret</label>
                <input type="password" name="google_oauth_client_secret" class="form-input" value="<?= sanitize($googleClientSecret) ?>" placeholder="GOCSPX-...">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Developer Token (Google Ads)</label>
            <input type="password" name="gads_developer_token" class="form-input" value="<?= sanitize($gadsDevToken) ?>" placeholder="aBcDeFgHiJkLmNoPqRs">
            <small style="color:var(--text-muted);font-size:11px;">Obtido em Google Ads → Ferramentas → API Center</small>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">💾 Salvar</button>
    </form>
</div>

<!-- 2. Conectar via OAuth -->
<div class="chart-container">
    <h3 class="chart-title" style="margin-bottom:16px;">🔗 Conectar Conta Google Ads</h3>
    
    <?php if ($hasCredentials): ?>
        <a href="/api/google-auth.php?action=login" class="btn-google">
            <svg viewBox="0 0 24 24" width="18" height="18"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
            <?= $hasConnection ? 'Conectar outra conta' : 'Conectar com Google' ?>
        </a>
    <?php else: ?>
        <div class="alert alert-warning" style="margin:0;">
            ⚠️ Preencha as credenciais OAuth acima primeiro.
        </div>
    <?php endif; ?>

    <?php if ($hasConnection): ?>
        <div style="margin-top:20px;">
            <h4 style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;">Contas conectadas</h4>
            <?php foreach ($googleConnections as $gconn):
                $gUserInitial = mb_strtoupper(mb_substr($gconn['google_user_name'] ?? 'G', 0, 1));
                $gadsAccounts = json_decode($gconn['gads_accounts'], true) ?: [];
                $gadsSelected = json_decode($gconn['gads_selected'], true) ?: [];
                $gadsSelectedIds = array_column($gadsSelected, 'id');
            ?>
            <div class="google-connection-card" style="margin-bottom:12px;">
                <div class="google-connection-header">
                    <div class="google-connection-user">
                        <div class="google-user-avatar">
                            <?php if (!empty($gconn['google_avatar'])): ?>
                                <img src="<?= sanitize($gconn['google_avatar']) ?>" alt="">
                            <?php else: ?>
                                <?= $gUserInitial ?>
                            <?php endif; ?>
                        </div>
                        <div class="google-user-info">
                            <h4><?= sanitize($gconn['google_user_name']) ?></h4>
                            <small><?= sanitize($gconn['google_email']) ?></small>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <?php if (!empty($gadsAccounts)): ?>
                            <span class="service-tag ads">🔍 <?= count($gadsAccounts) ?> Ads</span>
                        <?php endif; ?>
                        <span class="fb-token-status active">✅ Permanente</span>
                        <form method="POST" action="/api/google-auth.php?action=disconnect" style="display:inline;">
                            <input type="hidden" name="connection_id" value="<?= $gconn['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="color:var(--red);padding:4px 10px;"
                                    onclick="return confirm('Desconectar <?= sanitize($gconn['google_user_name']) ?>?')">🗑</button>
                        </form>
                    </div>
                </div>

                <!-- Seleção de contas Google Ads -->
                <form method="POST" action="/api/google-auth.php?action=save_selection">
                    <input type="hidden" name="connection_id" value="<?= $gconn['id'] ?>">
                    
                    <?php if (!empty($gadsAccounts)): ?>
                    <div class="fb-accounts-list">
                        <div class="fb-accounts-title">🔍 Contas Google Ads (<?= count($gadsAccounts) ?>)</div>
                        <?php foreach ($gadsAccounts as $gacc): 
                            $isSelected = in_array($gacc['id'], $gadsSelectedIds);
                        ?>
                        <div class="fb-account-item">
                            <input type="checkbox" name="gads_selected[]" value="<?= sanitize($gacc['id']) ?>"
                                   id="gads_<?= $gconn['id'] ?>_<?= sanitize($gacc['id']) ?>" <?= $isSelected ? 'checked' : '' ?>>
                            <label for="gads_<?= $gconn['id'] ?>_<?= sanitize($gacc['id']) ?>">
                                <span><?= sanitize($gacc['name']) ?></span>
                                <span class="account-id"><?= sanitize($gacc['id']) ?></span>
                                <?php if ($gacc['is_manager'] ?? false): ?>
                                    <span class="badge badge-yellow" style="font-size:10px;">MCC</span>
                                <?php endif; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($gadsAccounts)): ?>
                    <div style="display:flex;gap:8px;margin-top:12px;">
                        <button type="submit" class="btn btn-primary btn-sm">💾 Salvar Seleção</button>
                        <form method="POST" action="/api/google-auth.php?action=refresh" style="display:inline;">
                            <input type="hidden" name="connection_id" value="<?= $gconn['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">🔄 Atualizar</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div style="padding:16px 0;text-align:center;">
                        <p style="color:var(--text-muted);font-size:13px;margin-bottom:8px;">Nenhuma conta encontrada.</p>
                        <form method="POST" action="/api/google-auth.php?action=refresh" style="display:inline;">
                            <input type="hidden" name="connection_id" value="<?= $gconn['id'] ?>">
                            <button type="submit" class="btn btn-secondary btn-sm">🔄 Buscar Contas</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </form>
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
        <form method="POST">
            <input type="hidden" name="action" value="save_gads_settings">
            <div class="form-group">
                <label class="form-label">Customer ID</label>
                <input type="text" name="gads_customer_id" class="form-input" value="<?= sanitize($gadsCustomerId) ?>" placeholder="123-456-7890">
            </div>
            <div class="form-group">
                <label class="form-label">OAuth Client ID</label>
                <input type="text" name="gads_client_id" class="form-input" value="<?= sanitize($gadsClientId) ?>" placeholder="123456789.apps.googleusercontent.com">
            </div>
            <div class="form-group">
                <label class="form-label">OAuth Client Secret</label>
                <input type="password" name="gads_client_secret" class="form-input" value="<?= sanitize($gadsClientSecret) ?>" placeholder="GOCSPX-...">
            </div>
            <div class="form-group">
                <label class="form-label">Refresh Token</label>
                <input type="password" name="gads_refresh_token" class="form-input" value="<?= sanitize($gadsRefreshToken) ?>" placeholder="1//0abcdef...">
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-secondary btn-sm">💾 Salvar</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="testGoogleAds()">🧪 Testar</button>
            </div>
            <div id="gadsTestResult" style="margin-top:10px;font-size:13px;"></div>
        </form>
    </div>
</div>

<script>
function toggleCollapsible(header) {
    const content = header.nextElementSibling;
    const arrow = header.querySelector('.collapsible-arrow');
    content.classList.toggle('open');
    arrow.style.transform = content.classList.contains('open') ? 'rotate(180deg)' : 'rotate(0)';
}

async function testGoogleAds() {
    const result = document.getElementById('gadsTestResult');
    result.innerHTML = '<span style="color:var(--text-muted);">⏳ Testando conexão...</span>';
    try {
        const res = await fetch('/api/google-ads.php?action=test');
        const data = await res.json();
        if (data.success) {
            result.innerHTML = '<span style="color:#30d158;">✅ ' + data.message + (data.customer_name ? ' — Conta: ' + data.customer_name : '') + '</span>';
        } else {
            result.innerHTML = '<span style="color:#ff453a;">❌ ' + (data.error || 'Erro desconhecido') + '</span>';
        }
    } catch (e) {
        result.innerHTML = '<span style="color:#ff453a;">❌ Erro: ' + e.message + '</span>';
    }
}
</script>
