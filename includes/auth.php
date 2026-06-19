<?php
/**
 * Authentication — session + remember-me cookie
 */
require_once __DIR__ . '/db.php';

define('REMEMBER_COOKIE', 'bussola_remember');
define('REMEMBER_DAYS', 30);

function isLoggedIn() {
    // Session-based
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        return true;
    }
    // Cookie-based (remember me)
    if (isset($_COOKIE[REMEMBER_COOKIE])) {
        $token = $_COOKIE[REMEMBER_COOKIE];
        $storedToken = getSetting('remember_token', '');
        if (!empty($storedToken) && hash_equals($storedToken, $token)) {
            // Restore session from cookie
            $_SESSION['logged_in'] = true;
            $_SESSION['username'] = getSetting('auth_user', 'admin');
            return true;
        }
        // Invalid token — clear cookie
        setcookie(REMEMBER_COOKIE, '', time() - 3600, '/');
    }
    return false;
}

function requireLogin() {
    if (!isLoggedIn()) {
        if (isAjax()) {
            jsonResponse(['error' => 'Não autorizado'], 401);
        }
        redirect('?page=login');
    }
}

function login($username, $password, $rememberMe = false) {
    $storedUser = getSetting('auth_user', 'admin');
    $storedPass = getSetting('auth_pass', '');

    $valid = false;

    // First-time: if no password set, use defaults from config
    if (empty($storedPass)) {
        if ($username === AUTH_USER && password_verify($password, AUTH_PASS)) {
            $valid = true;
        }
    } else {
        if ($username === $storedUser && password_verify($password, $storedPass)) {
            $valid = true;
        }
    }

    if ($valid) {
        $_SESSION['logged_in'] = true;
        $_SESSION['username'] = $username;

        if ($rememberMe) {
            // Generate a secure random token
            $token = bin2hex(random_bytes(32));
            // Store hashed token in the database
            setSetting('remember_token', $token);
            // Set cookie for 30 days
            setcookie(REMEMBER_COOKIE, $token, [
                'expires' => time() + (86400 * REMEMBER_DAYS),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return true;
    }
    return false;
}

function logout() {
    // Clear remember cookie
    if (isset($_COOKIE[REMEMBER_COOKIE])) {
        setcookie(REMEMBER_COOKIE, '', time() - 3600, '/');
    }
    // Clear stored token
    try { setSetting('remember_token', ''); } catch (Exception $e) {}
    session_destroy();
    redirect('?page=login');
}
