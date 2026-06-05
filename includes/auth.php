<?php
require_once __DIR__ . '/../config.php';

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure'   => isset($_SERVER['HTTPS']),
            'cookie_samesite' => 'Strict',
        ]);
    }
}

function isLoggedIn(): bool {
    startSession();
    return !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/clients/courses/sales/reports/api/') . '/../login.php');
        // fallback: חיפוש login.php מהשורש
        $depth = substr_count(str_replace(dirname($_SERVER['DOCUMENT_ROOT']), '', $_SERVER['SCRIPT_FILENAME']), '/') - 1;
        $prefix = str_repeat('../', max(0, $depth - 1));
        header('Location: ' . $prefix . 'login.php');
        exit;
    }
}

function login(string $password): bool {
    startSession();
    if (hash_equals(ADMIN_PASSWORD, $password)) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        return true;
    }
    return false;
}

function logout(): void {
    startSession();
    $_SESSION = [];
    session_destroy();
}
