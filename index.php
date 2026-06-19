<?php
/**
 * Bússola do Tráfego - Main Router
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/date-filter.php';

// Handle API routes
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

if (strpos($path, '/api/') === 0) {
    // Support both /api/facebook and /api/facebook.php style URLs
    $apiFile = __DIR__ . $path;
    if (!file_exists($apiFile)) {
        $apiFile = __DIR__ . $path . '.php';
    }
    if (file_exists($apiFile) && pathinfo($apiFile, PATHINFO_EXTENSION) === 'php') {
        requireLogin();
        require $apiFile;
        exit;
    }
    jsonResponse(['error' => 'API not found'], 404);
}

// Handle login/logout
$page = $_GET['page'] ?? 'dashboard';

if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = $_POST['username'] ?? '';
        $pass = $_POST['password'] ?? '';
        $remember = isset($_POST['remember_me']);
        if (login($user, $pass, $remember)) {
            redirect('?page=dashboard');
        } else {
            $loginError = 'Usuário ou senha incorretos';
        }
    }
    require __DIR__ . '/pages/login.php';
    exit;
}

if ($page === 'logout') {
    logout();
    exit;
}

// Require auth for all other pages
requireLogin();

// Valid pages
$validPages = [
    'dashboard', 'campaigns', 'analytics', 'placements', 'devices', 'revenue', 'programmatic',
    'financial', 'spending-plan', 'tasks', 'logs',
    'settings', 'google-ads', 'connect-facebook', 'connect-google', 'connect-gam', 'connect-ga4'
];

if (!in_array($page, $validPages)) {
    $page = 'dashboard';
}

$pageFile = __DIR__ . '/pages/' . $page . '.php';
if (!file_exists($pageFile)) {
    $pageFile = __DIR__ . '/pages/dashboard.php';
    $page = 'dashboard';
}

// Page titles
$pageTitles = [
    'dashboard' => 'Dashboard',
    'campaigns' => 'Campanhas Facebook',
    'analytics' => 'Análises',
    'placements' => 'Análise Placement',
    'devices' => 'Dispositivos',
    'revenue' => 'Revenue GAM',
    'programmatic' => 'Receita Programática',
    'financial' => 'Financeiro',
    'spending-plan' => 'Plano de Gastos',
    'tasks' => 'Tarefas',
    'logs' => 'Sync Logs',

    'settings' => 'Configurações',
    'google-ads' => 'Google Ads',
    'connect-facebook' => 'Conectar Facebook',
    'connect-google' => 'Conectar Google Ads',
    'connect-gam' => 'Conectar GAM',
    'connect-ga4' => 'Conectar GA4'
];

$pageTitle = $pageTitles[$page] ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= $pageTitle ?> — ArbitraFy</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ArbitraFy">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/mobile-app.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="app">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <img src="assets/img/logo-arbitrafy.svg" alt="ArbitraFy" style="height:32px;width:auto;">
                </div>
                <button class="sidebar-toggle" id="sidebarToggle">☰</button>
            </div>
            <nav class="sidebar-nav">
                <a href="?page=dashboard" class="nav-item <?= $page === 'dashboard' ? 'active' : '' ?>">
                    <span class="nav-icon">📊</span>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a href="?page=campaigns" class="nav-item <?= $page === 'campaigns' ? 'active' : '' ?>">
                    <span class="nav-icon">📢</span>
                    <span class="nav-text">Campanhas FB</span>
                </a>
                <a href="?page=analytics" class="nav-item <?= $page === 'analytics' ? 'active' : '' ?>">
                    <span class="nav-icon">🔬</span>
                    <span class="nav-text">Análises</span>
                </a>
                <a href="?page=placements" class="nav-item <?= $page === 'placements' ? 'active' : '' ?>">
                    <span class="nav-icon">📍</span>
                    <span class="nav-text">Placements</span>
                </a>
                <a href="?page=devices" class="nav-item <?= $page === 'devices' ? 'active' : '' ?>">
                    <span class="nav-icon">📱</span>
                    <span class="nav-text">Dispositivos</span>
                </a>
                <a href="?page=revenue" class="nav-item <?= $page === 'revenue' ? 'active' : '' ?>">
                    <span class="nav-icon">💰</span>
                    <span class="nav-text">Revenue GAM</span>
                </a>
                <a href="?page=programmatic" class="nav-item <?= $page === 'programmatic' ? 'active' : '' ?>">
                    <span class="nav-icon">📈</span>
                    <span class="nav-text">Receita Prog.</span>
                </a>
                <a href="?page=google-ads" class="nav-item <?= $page === 'google-ads' ? 'active' : '' ?>">
                    <span class="nav-icon">🔍</span>
                    <span class="nav-text">Google Ads</span>
                </a>

                <div class="nav-divider"></div>

                <a href="?page=financial" class="nav-item <?= $page === 'financial' ? 'active' : '' ?>">
                    <span class="nav-icon">🏦</span>
                    <span class="nav-text">Financeiro</span>
                </a>
                <a href="?page=spending-plan" class="nav-item <?= $page === 'spending-plan' ? 'active' : '' ?>">
                    <span class="nav-icon">📋</span>
                    <span class="nav-text">Plano de Gastos</span>
                </a>
                <a href="?page=tasks" class="nav-item <?= $page === 'tasks' ? 'active' : '' ?>">
                    <span class="nav-icon">✅</span>
                    <span class="nav-text">Tarefas</span>
                </a>

                <div class="nav-divider"></div>


                <a href="?page=logs" class="nav-item <?= $page === 'logs' ? 'active' : '' ?>">
                    <span class="nav-icon">📋</span>
                    <span class="nav-text">Sync Logs</span>
                </a>

                <div class="nav-divider"></div>

                <a href="?page=connect-facebook" class="nav-item <?= $page === 'connect-facebook' ? 'active' : '' ?>">
                    <span class="nav-icon">📘</span>
                    <span class="nav-text">Conectar Facebook</span>
                </a>
                <a href="?page=connect-google" class="nav-item <?= $page === 'connect-google' ? 'active' : '' ?>">
                    <span class="nav-icon">🔍</span>
                    <span class="nav-text">Conectar Google Ads</span>
                </a>
                <a href="?page=connect-gam" class="nav-item <?= $page === 'connect-gam' ? 'active' : '' ?>">
                    <span class="nav-icon">📊</span>
                    <span class="nav-text">Conectar GAM</span>
                </a>
                <a href="?page=connect-ga4" class="nav-item <?= $page === 'connect-ga4' ? 'active' : '' ?>">
                    <span class="nav-icon">📈</span>
                    <span class="nav-text">Conectar GA4</span>
                </a>
                <a href="?page=settings" class="nav-item <?= $page === 'settings' ? 'active' : '' ?>">
                    <span class="nav-icon">⚙️</span>
                    <span class="nav-text">Configurações</span>
                </a>
            </nav>
            <div class="sidebar-footer">
                <a href="?page=logout" class="nav-item logout">
                    <span class="nav-icon">🚪</span>
                    <span class="nav-text">Sair</span>
                </a>
            </div>
        </aside>

        <!-- Sidebar Overlay (Mobile) -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Main Content -->
        <main class="main-content">
            <header class="topbar">
                <button class="mobile-menu-btn" id="mobileMenuBtn">☰</button>
                <h1 class="page-title"><?= $pageTitle ?></h1>
                <div class="topbar-right">
                    <span class="current-date"><?= date('d/m/Y H:i') ?></span>
                    <span class="user-badge">👤 <?= $_SESSION['username'] ?? 'Admin' ?></span>
                </div>
            </header>

            <div class="content">
                <?php require $pageFile; ?>
            </div>
        </main>
    </div>

    <script src="/assets/js/app.js"></script>
</body>
</html>
