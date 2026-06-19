<?php
/**
 * Google OAuth Social Login
 * Handles OAuth flow for connecting Google Ads + Google Ad Manager accounts.
 * 
 * Actions:
 *   login          → Redirect to Google OAuth consent screen
 *   callback       → Handle callback, exchange tokens, fetch accounts/networks
 *   disconnect     → Remove a connection
 *   refresh        → Re-fetch accounts for a connection
 *   save_selection → Save which accounts/networks to sync
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

// Ensure google_connections table exists
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
} catch (Exception $e) {}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'callback':
        handleCallback();
        break;
    case 'disconnect':
        handleDisconnect();
        break;
    case 'refresh':
        handleRefresh();
        break;
    case 'save_selection':
        handleSaveSelection();
        break;
    default:
        jsonResponse(['error' => 'Ação inválida'], 400);
}

/**
 * Redirect to Google OAuth consent screen
 */
function handleLogin() {
    $clientId = getSetting('google_oauth_client_id', '');
    $clientSecret = getSetting('google_oauth_client_secret', '');

    if (empty($clientId) || empty($clientSecret)) {
        $_SESSION['google_auth_error'] = 'Configure o Client ID e Secret do Google antes de conectar.';
        header('Location: /?page=settings');
        exit;
    }

    $redirectUri = getGoogleCallbackUrl();
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    // Scopes: Google Ads + GAM + user profile
    $scopes = implode(' ', [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/adwords',        // Google Ads
        'https://www.googleapis.com/auth/admanager',       // GAM
        'https://www.googleapis.com/auth/analytics.readonly', // GA4
    ]);

    $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => $scopes,
        'access_type' => 'offline',      // Get refresh_token
        'prompt' => 'consent',           // Always show consent to get refresh_token
        'state' => $state,
    ]);

    header("Location: {$authUrl}");
    exit;
}

/**
 * Handle callback from Google
 */
function handleCallback() {
    if (isset($_GET['error'])) {
        $_SESSION['google_auth_error'] = 'Google: ' . ($_GET['error_description'] ?? $_GET['error'] ?? 'Acesso negado');
        header('Location: /?page=settings');
        exit;
    }

    $state = $_GET['state'] ?? '';
    $expectedState = $_SESSION['google_oauth_state'] ?? '';
    unset($_SESSION['google_oauth_state']);

    if (empty($state) || $state !== $expectedState) {
        $_SESSION['google_auth_error'] = 'Erro de segurança (state inválido). Tente novamente.';
        header('Location: /?page=settings');
        exit;
    }

    $code = $_GET['code'] ?? '';
    if (empty($code)) {
        $_SESSION['google_auth_error'] = 'Código de autorização não recebido.';
        header('Location: /?page=settings');
        exit;
    }

    $clientId = getSetting('google_oauth_client_id', '');
    $clientSecret = getSetting('google_oauth_client_secret', '');
    $redirectUri = getGoogleCallbackUrl();

    // Exchange code for tokens
    $tokenResult = googlePost('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);

    if (isset($tokenResult['error'])) {
        $_SESSION['google_auth_error'] = 'Erro ao trocar código: ' . ($tokenResult['error_description'] ?? $tokenResult['error']);
        header('Location: /?page=settings');
        exit;
    }

    $accessToken = $tokenResult['access_token'] ?? '';
    $refreshToken = $tokenResult['refresh_token'] ?? '';
    $expiresIn = $tokenResult['expires_in'] ?? 3600;

    if (empty($accessToken)) {
        $_SESSION['google_auth_error'] = 'Token não retornado pelo Google.';
        header('Location: /?page=settings');
        exit;
    }

    if (empty($refreshToken)) {
        $_SESSION['google_auth_error'] = 'Refresh Token não obtido. Tente revogar o acesso em myaccount.google.com e conectar novamente.';
        header('Location: /?page=settings');
        exit;
    }

    // Fetch user profile
    $userInfo = googleGet("https://www.googleapis.com/oauth2/v2/userinfo", $accessToken);
    $googleUserId = $userInfo['id'] ?? '';
    $googleUserName = $userInfo['name'] ?? 'Usuário Google';
    $googleEmail = $userInfo['email'] ?? '';
    $googleAvatar = $userInfo['picture'] ?? '';

    // Fetch Google Ads accounts
    $gadsAccounts = fetchGoogleAdsAccounts($accessToken);

    // Fetch GAM networks
    $gamNetworks = fetchGAMNetworks($accessToken);

    $tokenExpiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

    // Save or update connection
    $existing = fetchOne("SELECT id FROM google_connections WHERE google_user_id = ?", [$googleUserId]);

    if ($existing) {
        update('google_connections', [
            'google_user_name' => $googleUserName,
            'google_email' => $googleEmail,
            'google_avatar' => $googleAvatar,
            'refresh_token' => $refreshToken,
            'access_token' => $accessToken,
            'token_expires_at' => $tokenExpiresAt,
            'gads_accounts' => json_encode($gadsAccounts, JSON_UNESCAPED_UNICODE),
            'gam_networks' => json_encode($gamNetworks, JSON_UNESCAPED_UNICODE),
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$existing['id']]);
        $_SESSION['google_auth_success'] = "✅ Conta de {$googleUserName} reconectada! " 
            . count($gadsAccounts) . " contas Ads, " . count($gamNetworks) . " redes GAM.";
    } else {
        // Auto-select all on first connection
        insert('google_connections', [
            'google_user_id' => $googleUserId,
            'google_user_name' => $googleUserName,
            'google_email' => $googleEmail,
            'google_avatar' => $googleAvatar,
            'refresh_token' => $refreshToken,
            'access_token' => $accessToken,
            'token_expires_at' => $tokenExpiresAt,
            'gads_accounts' => json_encode($gadsAccounts, JSON_UNESCAPED_UNICODE),
            'gads_selected' => json_encode($gadsAccounts, JSON_UNESCAPED_UNICODE),
            'gam_networks' => json_encode($gamNetworks, JSON_UNESCAPED_UNICODE),
            'gam_selected' => json_encode($gamNetworks, JSON_UNESCAPED_UNICODE),
            'status' => 'active',
        ]);
        $_SESSION['google_auth_success'] = "✅ Conta de {$googleUserName} conectada! "
            . count($gadsAccounts) . " contas Ads, " . count($gamNetworks) . " redes GAM.";
    }

    header('Location: /?page=settings');
    exit;
}

/**
 * Disconnect a Google account
 */
function handleDisconnect() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }
    $id = (int)($_POST['connection_id'] ?? 0);
    if ($id) {
        delete('google_connections', 'id = ?', [$id]);
        $_SESSION['google_auth_success'] = '✅ Conta Google desconectada.';
    }
    header('Location: /?page=settings');
    exit;
}

/**
 * Refresh accounts for a connection
 */
function handleRefresh() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }

    $id = (int)($_POST['connection_id'] ?? 0);
    $conn = fetchOne("SELECT * FROM google_connections WHERE id = ?", [$id]);
    if (!$conn) {
        $_SESSION['google_auth_error'] = 'Conexão não encontrada.';
        header('Location: /?page=settings');
        exit;
    }

    // Get fresh access token
    $accessToken = refreshGoogleAccessToken($conn['refresh_token']);
    if (!$accessToken) {
        update('google_connections', ['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        $_SESSION['google_auth_error'] = '⚠️ Token expirado. Reconecte a conta.';
        header('Location: /?page=settings');
        exit;
    }

    $gadsAccounts = fetchGoogleAdsAccounts($accessToken);
    $gamNetworks = fetchGAMNetworks($accessToken);

    update('google_connections', [
        'access_token' => $accessToken,
        'token_expires_at' => date('Y-m-d H:i:s', time() + 3600),
        'gads_accounts' => json_encode($gadsAccounts, JSON_UNESCAPED_UNICODE),
        'gam_networks' => json_encode($gamNetworks, JSON_UNESCAPED_UNICODE),
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);

    $_SESSION['google_auth_success'] = '✅ Contas atualizadas! ' . count($gadsAccounts) . ' Ads, ' . count($gamNetworks) . ' GAM.';
    header('Location: /?page=settings');
    exit;
}

/**
 * Save account/network selection
 */
function handleSaveSelection() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'POST required'], 405);
    }

    $id = (int)($_POST['connection_id'] ?? 0);
    $conn = fetchOne("SELECT * FROM google_connections WHERE id = ?", [$id]);
    if (!$conn) {
        $_SESSION['google_auth_error'] = 'Conexão não encontrada.';
        header('Location: /?page=settings');
        exit;
    }

    $selectedGads = $_POST['gads_selected'] ?? [];
    $selectedGam = $_POST['gam_selected'] ?? [];

    // Build selected arrays
    $allGads = json_decode($conn['gads_accounts'], true) ?: [];
    $allGam = json_decode($conn['gam_networks'], true) ?: [];

    $gadsSelected = array_filter($allGads, fn($a) => in_array($a['id'], $selectedGads));
    $gamSelected = array_filter($allGam, fn($n) => in_array($n['network_code'], $selectedGam));

    update('google_connections', [
        'gads_selected' => json_encode(array_values($gadsSelected), JSON_UNESCAPED_UNICODE),
        'gam_selected' => json_encode(array_values($gamSelected), JSON_UNESCAPED_UNICODE),
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$id]);

    $_SESSION['google_auth_success'] = '✅ Seleção salva! ' . count($gadsSelected) . ' Ads, ' . count($gamSelected) . ' GAM.';
    header('Location: /?page=settings');
    exit;
}

// ============================================================
// Helper functions
// ============================================================

function getGoogleCallbackUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "{$scheme}://{$host}/api/google-auth.php?action=callback";
}

/**
 * Refresh an access token using a refresh token
 */
function refreshGoogleAccessToken($refreshToken) {
    $clientId = getSetting('google_oauth_client_id', '');
    $clientSecret = getSetting('google_oauth_client_secret', '');

    $result = googlePost('https://oauth2.googleapis.com/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
    ]);

    return $result['access_token'] ?? null;
}

/**
 * Fetch all accessible Google Ads customer accounts
 */
function fetchGoogleAdsAccounts($accessToken) {
    $devToken = getSetting('gads_developer_token', '');
    if (empty($devToken)) return [];

    $accounts = [];

    // List accessible customers
    $result = googleGet(
        "https://googleads.googleapis.com/v18/customers:listAccessibleCustomers",
        $accessToken,
        ['developer-token: ' . $devToken]
    );

    $resourceNames = $result['resourceNames'] ?? [];

    foreach ($resourceNames as $rn) {
        // Extract customer ID: "customers/1234567890"
        $customerId = str_replace('customers/', '', $rn);

        // Get customer details
        $query = "SELECT customer.id, customer.descriptive_name, customer.manager, customer.status FROM customer LIMIT 1";
        $detailResult = googlePostJson(
            "https://googleads.googleapis.com/v18/customers/{$customerId}/googleAds:searchStream",
            ['query' => $query],
            $accessToken,
            ['developer-token: ' . $devToken]
        );

        $name = $customerId;
        $isManager = false;
        $status = 'UNKNOWN';

        if (is_array($detailResult)) {
            foreach ($detailResult as $batch) {
                foreach ($batch['results'] ?? [] as $row) {
                    $name = $row['customer']['descriptiveName'] ?? $customerId;
                    $isManager = $row['customer']['manager'] ?? false;
                    $status = $row['customer']['status'] ?? 'UNKNOWN';
                }
            }
        }

        $accounts[] = [
            'id' => $customerId,
            'name' => $name,
            'is_manager' => $isManager,
            'status' => $status,
        ];
    }

    return $accounts;
}

/**
 * Fetch GAM networks accessible to the user
 */
function fetchGAMNetworks($accessToken) {
    $networks = [];

    // GAM REST API to get networks
    $result = googleGet(
        "https://admanager.googleapis.com/v1/networks",
        $accessToken
    );

    foreach ($result['networks'] ?? [] as $network) {
        // network name format: "networks/12345678"
        $networkCode = str_replace('networks/', '', $network['name'] ?? '');
        $networks[] = [
            'network_code' => $networkCode ?: ($network['networkCode'] ?? ''),
            'display_name' => $network['displayName'] ?? $network['networkName'] ?? 'Rede GAM',
            'network_id' => $network['networkId'] ?? $networkCode,
        ];
    }

    return $networks;
}

/**
 * HTTP GET with Bearer token
 */
function googleGet($url, $accessToken, $extraHeaders = []) {
    $headers = array_merge([
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ], $extraHeaders);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}

/**
 * HTTP POST form-encoded
 */
function googlePost($url, $data) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}

/**
 * HTTP POST JSON
 */
function googlePostJson($url, $data, $accessToken, $extraHeaders = []) {
    $headers = array_merge([
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ], $extraHeaders);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}
