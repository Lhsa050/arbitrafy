<?php
/**
 * Facebook OAuth Social Login
 * Handles the complete OAuth flow for connecting Facebook Ads accounts.
 * 
 * Actions:
 *   login          → Redirect to Facebook OAuth
 *   callback       → Handle Facebook callback, exchange tokens, fetch accounts
 *   disconnect     → Remove a connection
 *   refresh        → Re-fetch ad accounts for a connection
 *   save_selection → Save which ad accounts to sync
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Ensure fb_connections table exists
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
} catch (Exception $e) {
    // Table already exists
}

$action = $_GET['action'] ?? '';
$fbApiVersion = getSetting('fb_api_version', 'v21.0');

switch ($action) {
    case 'login':
        handleLogin($fbApiVersion);
        break;
    case 'callback':
        handleCallback($fbApiVersion);
        break;
    case 'disconnect':
        handleDisconnect();
        break;
    case 'refresh':
        handleRefresh($fbApiVersion);
        break;
    case 'save_selection':
        handleSaveSelection();
        break;
    case 'test_connection':
        handleTestConnection($fbApiVersion);
        break;
    default:
        jsonResponse(['error' => 'Ação inválida'], 400);
}

/**
 * Step 1: Redirect user to Facebook OAuth dialog
 */
function handleLogin($version) {
    $appId = getSetting('fb_app_id', '');
    $appSecret = getSetting('fb_app_secret', '');

    if (empty($appId) || empty($appSecret)) {
        $_SESSION['fb_auth_error'] = 'Configure o App ID e o App Secret antes de conectar.';
        header('Location: /?page=settings');
        exit;
    }

    $redirectUri = getCallbackUrl();
    
    // Generate CSRF state token
    $state = bin2hex(random_bytes(16));
    $_SESSION['fb_oauth_state'] = $state;

    $scopes = 'ads_read,read_insights,business_management';

    $authUrl = "https://www.facebook.com/{$version}/dialog/oauth?" . http_build_query([
        'client_id' => $appId,
        'redirect_uri' => $redirectUri,
        'scope' => $scopes,
        'response_type' => 'code',
        'state' => $state,
    ]);

    header("Location: {$authUrl}");
    exit;
}

/**
 * Step 2: Handle callback from Facebook
 */
function handleCallback($version) {
    // Check for errors from Facebook
    if (isset($_GET['error'])) {
        $errorMsg = $_GET['error_description'] ?? $_GET['error_reason'] ?? 'Autorização negada';
        $_SESSION['fb_auth_error'] = "Facebook: {$errorMsg}";
        header('Location: /?page=settings');
        exit;
    }

    // Validate state (CSRF protection)
    $state = $_GET['state'] ?? '';
    $expectedState = $_SESSION['fb_oauth_state'] ?? '';
    unset($_SESSION['fb_oauth_state']);

    if (empty($state) || $state !== $expectedState) {
        $_SESSION['fb_auth_error'] = 'Erro de segurança (state inválido). Tente novamente.';
        header('Location: /?page=settings');
        exit;
    }

    $code = $_GET['code'] ?? '';
    if (empty($code)) {
        $_SESSION['fb_auth_error'] = 'Código de autorização não recebido.';
        header('Location: /?page=settings');
        exit;
    }

    $appId = getSetting('fb_app_id', '');
    $appSecret = getSetting('fb_app_secret', '');
    $redirectUri = getCallbackUrl();

    // Exchange code for short-lived token
    $tokenUrl = "https://graph.facebook.com/{$version}/oauth/access_token?" . http_build_query([
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'redirect_uri' => $redirectUri,
        'code' => $code,
    ]);

    $tokenResult = fbAuthRequest($tokenUrl);
    if (isset($tokenResult['error'])) {
        $_SESSION['fb_auth_error'] = 'Erro ao trocar código: ' . ($tokenResult['error']['message'] ?? 'Desconhecido');
        header('Location: /?page=settings');
        exit;
    }

    $shortToken = $tokenResult['access_token'] ?? '';
    if (empty($shortToken)) {
        $_SESSION['fb_auth_error'] = 'Token não retornado pelo Facebook.';
        header('Location: /?page=settings');
        exit;
    }

    // Exchange short-lived for long-lived token (~60 days)
    $longTokenUrl = "https://graph.facebook.com/{$version}/oauth/access_token?" . http_build_query([
        'grant_type' => 'fb_exchange_token',
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'fb_exchange_token' => $shortToken,
    ]);

    $longResult = fbAuthRequest($longTokenUrl);
    $accessToken = $longResult['access_token'] ?? $shortToken;
    $expiresIn = $longResult['expires_in'] ?? 5184000; // Default 60 days
    $tokenExpiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

    // Fetch user info
    $meUrl = "https://graph.facebook.com/{$version}/me?fields=id,name,email&access_token={$accessToken}";
    $meResult = fbAuthRequest($meUrl);
    
    if (isset($meResult['error'])) {
        $_SESSION['fb_auth_error'] = 'Erro ao buscar dados do usuário: ' . ($meResult['error']['message'] ?? 'Desconhecido');
        header('Location: /?page=settings');
        exit;
    }

    $fbUserId = $meResult['id'] ?? '';
    $fbUserName = $meResult['name'] ?? 'Usuário Facebook';
    $fbEmail = $meResult['email'] ?? '';

    // Fetch ad accounts
    $adAccounts = fetchAdAccounts($version, $accessToken);

    // Save or update connection
    $existing = fetchOne("SELECT id FROM fb_connections WHERE fb_user_id = ?", [$fbUserId]);
    
    if ($existing) {
        update('fb_connections', [
            'fb_user_name' => $fbUserName,
            'fb_email' => $fbEmail,
            'access_token' => $accessToken,
            'token_expires_at' => $tokenExpiresAt,
            'ad_accounts' => json_encode($adAccounts, JSON_UNESCAPED_UNICODE),
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$existing['id']]);

        $_SESSION['fb_auth_success'] = "✅ Conta de {$fbUserName} reconectada! " . count($adAccounts) . " contas de anúncio encontradas.";
    } else {
        // Auto-select all accounts on first connection
        $selectedAccounts = array_map(function($acc) {
            return ['id' => $acc['id'], 'name' => $acc['name']];
        }, $adAccounts);

        insert('fb_connections', [
            'fb_user_id' => $fbUserId,
            'fb_user_name' => $fbUserName,
            'fb_email' => $fbEmail,
            'access_token' => $accessToken,
            'token_expires_at' => $tokenExpiresAt,
            'ad_accounts' => json_encode($adAccounts, JSON_UNESCAPED_UNICODE),
            'selected_accounts' => json_encode($selectedAccounts, JSON_UNESCAPED_UNICODE),
            'status' => 'active',
        ]);

        $_SESSION['fb_auth_success'] = "✅ Conta de {$fbUserName} conectada! " . count($adAccounts) . " contas de anúncio encontradas.";
    }

    header('Location: /?page=settings');
    exit;
}

/**
 * Disconnect a Facebook account
 */
function handleDisconnect() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }

    $connectionId = $_POST['connection_id'] ?? 0;
    if ($connectionId) {
        delete('fb_connections', 'id = ?', [(int)$connectionId]);
        $_SESSION['fb_auth_success'] = '✅ Conta desconectada com sucesso.';
    }
    
    header('Location: /?page=settings');
    exit;
}

/**
 * Refresh ad accounts for a connection
 */
function handleRefresh($version) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }

    $connectionId = (int)($_POST['connection_id'] ?? 0);
    $conn = fetchOne("SELECT * FROM fb_connections WHERE id = ?", [$connectionId]);

    if (!$conn) {
        $_SESSION['fb_auth_error'] = 'Conexão não encontrada.';
        header('Location: /?page=settings');
        exit;
    }

    $adAccounts = fetchAdAccounts($version, $conn['access_token']);

    if (empty($adAccounts) && $conn['status'] !== 'expired') {
        // Token might be expired, test it
        $testUrl = "https://graph.facebook.com/{$version}/me?access_token=" . $conn['access_token'];
        $testResult = fbAuthRequest($testUrl);
        if (isset($testResult['error'])) {
            update('fb_connections', [
                'status' => 'expired',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$connectionId]);
            $_SESSION['fb_auth_error'] = '⚠️ Token expirado. Reconecte a conta.';
            header('Location: /?page=settings');
            exit;
        }
    }

    update('fb_connections', [
        'ad_accounts' => json_encode($adAccounts, JSON_UNESCAPED_UNICODE),
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$connectionId]);

    $_SESSION['fb_auth_success'] = '✅ Contas de anúncio atualizadas! ' . count($adAccounts) . ' encontradas.';
    header('Location: /?page=settings');
    exit;
}

/**
 * Save which ad accounts to sync
 */
function handleSaveSelection() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }

    $connectionId = (int)($_POST['connection_id'] ?? 0);
    $selectedIds = $_POST['selected_accounts'] ?? [];

    $conn = fetchOne("SELECT * FROM fb_connections WHERE id = ?", [$connectionId]);
    if (!$conn) {
        $_SESSION['fb_auth_error'] = 'Conexão não encontrada.';
        header('Location: /?page=settings');
        exit;
    }

    $allAccounts = json_decode($conn['ad_accounts'], true) ?: [];
    $selected = [];

    foreach ($allAccounts as $acc) {
        if (in_array($acc['id'], $selectedIds)) {
            $selected[] = ['id' => $acc['id'], 'name' => $acc['name']];
        }
    }

    update('fb_connections', [
        'selected_accounts' => json_encode($selected, JSON_UNESCAPED_UNICODE),
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$connectionId]);

    $_SESSION['fb_auth_success'] = '✅ ' . count($selected) . ' contas selecionadas para sincronização.';
    header('Location: /?page=settings');
    exit;
}

/**
 * Quick connection test without running the full sync.
 */
function handleTestConnection($version) {
    $mode = $_GET['mode'] ?? 'auto';
    if (!in_array($mode, ['auto', 'oauth', 'manual'], true)) {
        $mode = 'auto';
    }

    $checks = [];
    $totalAccounts = 0;
    $okAccounts = 0;

    if ($mode !== 'manual') {
        try {
            $connections = fetchAll("SELECT * FROM fb_connections WHERE status = 'active' ORDER BY connected_at DESC");
        } catch (Exception $e) {
            $connections = [];
        }

        foreach ($connections as $conn) {
            $check = testFBTokenAndAccounts($version, $conn['access_token'], json_decode($conn['selected_accounts'] ?? '[]', true) ?: [], [
                'source' => 'oauth',
                'name' => $conn['fb_user_name'] ?? 'Facebook OAuth',
                'expires_at' => $conn['token_expires_at'] ?? null,
            ]);

            if (!$check['token_ok'] && !empty($check['is_expired'])) {
                try {
                    update('fb_connections', [
                        'status' => 'expired',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ], 'id = ?', [$conn['id']]);
                } catch (Exception $e) {
                }
            }

            $checks[] = $check;
            $totalAccounts += $check['accounts_checked'];
            $okAccounts += $check['accounts_ok'];
        }
    }

    $hasWorkingOAuth = false;
    foreach ($checks as $check) {
        if (!empty($check['token_ok']) && (int)$check['accounts_ok'] > 0) {
            $hasWorkingOAuth = true;
            break;
        }
    }

    if ($mode === 'manual' || ($mode === 'auto' && !$hasWorkingOAuth)) {
        $manualToken = getSetting('fb_access_token', '');
        $manualAccounts = json_decode(getSetting('fb_ad_accounts', '[]'), true) ?: [];

        if (empty($manualToken) || empty($manualAccounts)) {
            jsonResponse([
                'success' => false,
                'message' => $mode === 'manual'
                    ? 'Token manual ou contas manuais nao configurados.'
                    : 'Nenhuma conexao Facebook ativa ou token manual configurado.',
                'checks' => $checks,
            ], 400);
        }

        $check = testFBTokenAndAccounts($version, $manualToken, $manualAccounts, [
            'source' => 'manual',
            'name' => 'Token manual',
            'expires_at' => null,
        ]);
        $checks[] = $check;
        $totalAccounts += $check['accounts_checked'];
        $okAccounts += $check['accounts_ok'];
    }

    $tokenOk = false;
    foreach ($checks as $check) {
        if (!empty($check['token_ok'])) {
            $tokenOk = true;
            break;
        }
    }

    $success = $tokenOk && $okAccounts > 0;
    $message = $success
        ? "Conexao OK: {$okAccounts}/{$totalAccounts} conta(s) de anuncio acessivel(is)."
        : ($mode === 'manual'
            ? 'Token manual com problema. Gere um novo token e confira as contas manuais.'
            : 'Conexao Facebook com problema. Reconecte a conta ou atualize o token manual.');

    jsonResponse([
        'success' => $success,
        'message' => $message,
        'accounts_ok' => $okAccounts,
        'accounts_checked' => $totalAccounts,
        'checks' => $checks,
    ], $success ? 200 : 400);
}

function testFBTokenAndAccounts($version, $accessToken, $accounts, $meta = []) {
    $check = [
        'source' => $meta['source'] ?? '',
        'name' => $meta['name'] ?? 'Facebook',
        'expires_at' => $meta['expires_at'] ?? null,
        'days_left' => null,
        'token_ok' => false,
        'is_expired' => false,
        'message' => '',
        'accounts_checked' => 0,
        'accounts_ok' => 0,
        'accounts' => [],
    ];

    if (!empty($check['expires_at'])) {
        $expiresTs = strtotime($check['expires_at']);
        if ($expiresTs) {
            $check['days_left'] = (int)floor(($expiresTs - time()) / 86400);
            if ($expiresTs <= time()) {
                $check['is_expired'] = true;
                $check['message'] = 'Token expirado. Reconecte a conta.';
                return $check;
            }
        }
    }

    $meUrl = "https://graph.facebook.com/{$version}/me?" . http_build_query([
        'fields' => 'id,name',
        'access_token' => $accessToken,
    ]);
    $meResult = fbAuthRequest($meUrl);

    if (isset($meResult['error'])) {
        $check['message'] = $meResult['error']['message'] ?? 'Erro ao validar token.';
        $check['is_expired'] = (($meResult['error']['code'] ?? 0) == 190);
        return $check;
    }

    $check['token_ok'] = true;
    $check['facebook_user'] = $meResult['name'] ?? '';

    if (empty($accounts)) {
        $check['message'] = 'Token valido, mas nenhuma conta de anuncio selecionada.';
        return $check;
    }

    foreach ($accounts as $account) {
        $accountId = $account['id'] ?? $account['account_id'] ?? '';
        if ($accountId === '') {
            continue;
        }
        if (strpos($accountId, 'act_') !== 0) {
            $accountId = 'act_' . $accountId;
        }

        $accountUrl = "https://graph.facebook.com/{$version}/{$accountId}?" . http_build_query([
            'fields' => 'name,account_status,currency,timezone_name',
            'access_token' => $accessToken,
        ]);
        $accountResult = fbAuthRequest($accountUrl);
        $check['accounts_checked']++;

        if (isset($accountResult['error'])) {
            $check['accounts'][] = [
                'id' => $accountId,
                'name' => $account['name'] ?? $accountId,
                'ok' => false,
                'message' => $accountResult['error']['message'] ?? 'Sem acesso.',
            ];
            continue;
        }

        $check['accounts_ok']++;
        $check['accounts'][] = [
            'id' => $accountId,
            'name' => $accountResult['name'] ?? ($account['name'] ?? $accountId),
            'ok' => true,
            'status' => $accountResult['account_status'] ?? null,
            'currency' => $accountResult['currency'] ?? '',
            'timezone' => $accountResult['timezone_name'] ?? '',
        ];
    }

    $check['message'] = $check['accounts_ok'] > 0
        ? "Token valido e {$check['accounts_ok']}/{$check['accounts_checked']} conta(s) acessivel(is)."
        : 'Token valido, mas nenhuma conta selecionada ficou acessivel.';

    return $check;
}

// ============================================================
// Helper functions
// ============================================================

/**
 * Build the OAuth callback URL dynamically
 */
function getCallbackUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "{$scheme}://{$host}/api/fb-auth.php?action=callback";
}

/**
 * Fetch all ad accounts for a user
 */
function fetchAdAccounts($version, $accessToken) {
    $accounts = [];
    $url = "https://graph.facebook.com/{$version}/me/adaccounts?" . http_build_query([
        'fields' => 'name,account_id,account_status,business_name',
        'limit' => 100,
        'access_token' => $accessToken,
    ]);

    while ($url) {
        $result = fbAuthRequest($url);

        if (isset($result['error'])) {
            break;
        }

        foreach ($result['data'] ?? [] as $acc) {
            // account_status: 1 = ACTIVE, 2 = DISABLED, 3 = UNSETTLED, 7 = PENDING_RISK_REVIEW, etc.
            $statusLabels = [
                1 => 'Ativa', 2 => 'Desativada', 3 => 'Pendente', 
                7 => 'Em Revisão', 8 => 'Em Período de Graça', 9 => 'Temporariamente Indisponível',
                100 => 'Análise Pendente', 101 => 'Fechada', 201 => 'Qualquer Atividade',
            ];
            $statusCode = $acc['account_status'] ?? 0;

            $accounts[] = [
                'id' => $acc['id'], // Already has act_ prefix
                'account_id' => $acc['account_id'] ?? '',
                'name' => $acc['name'] ?? 'Sem nome',
                'business_name' => $acc['business_name'] ?? '',
                'status' => $statusLabels[$statusCode] ?? 'Desconhecido',
                'status_code' => $statusCode,
            ];
        }

        $url = $result['paging']['next'] ?? null;
    }

    return $accounts;
}

/**
 * Make an HTTP request to the Facebook API
 */
function fbAuthRequest($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => ['message' => "cURL: {$error}"]];
    }

    return json_decode($response, true) ?: ['error' => ['message' => 'Resposta inválida']];
}
