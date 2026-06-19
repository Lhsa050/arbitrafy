<?php
/**
 * Bússola do Tráfego - Configuração
 */

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Base path
define('BASE_PATH', __DIR__);
define('UPLOADS_PATH', BASE_PATH . '/uploads');

// Database (SQLite para teste local, MySQL para VPS)
define('DB_TYPE', 'sqlite'); // 'sqlite' ou 'mysql'
define('DB_SQLITE_PATH', BASE_PATH . '/database/bussola.sqlite');

// MySQL config (usar na VPS)
define('DB_HOST', 'localhost');
define('DB_NAME', 'bussola_trafego');
define('DB_USER', 'root');
define('DB_PASS', '');

// Facebook API
define('FB_API_VERSION', 'v21.0');
define('FB_ACCESS_TOKEN', ''); // Configurar via Settings
define('FB_AD_ACCOUNTS', ''); // JSON array de contas, configurar via Settings

// GAM API (Google Ad Manager)
define('GAM_NETWORK_CODE', ''); // Configurar via Settings
define('GAM_SERVICE_ACCOUNT_JSON', ''); // Path para o JSON de credenciais

// Auth
define('AUTH_USER', 'admin');
define('AUTH_PASS', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'); // password
define('AUTH_SECRET', 'bussola_secret_key_change_me');

// Session
session_start();
