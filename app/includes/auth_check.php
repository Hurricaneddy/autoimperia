<?php
/**
 * Автосервис «Автоимперия» (г. Сергиев Посад)
 * Проверка авторизации сотрудников и мастера-приёмщика (АРМ)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isStaffLoggedIn(): bool {
    return !empty($_SESSION['staff_user_id']) && !empty($_SESSION['staff_username']);
}

function getAuthUser(): ?array {
    if (!isStaffLoggedIn()) {
        return null;
    }
    return [
        'id'        => $_SESSION['staff_user_id'],
        'username'  => $_SESSION['staff_username'],
        'full_name' => $_SESSION['staff_full_name'] ?? 'Сотрудник',
        'role'      => $_SESSION['staff_role'] ?? 'master',
        'phone'     => $_SESSION['staff_phone'] ?? ''
    ];
}

function requireStaffAuth(bool $isApi = false): void {
    if (!isStaffLoggedIn()) {
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Необходима авторизация мастера-приёмщика'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            $loginUrl = (defined('BASE_URL') && BASE_URL ? BASE_URL : '') . '/admin/login.php';
            header('Location: ' . $loginUrl);
            exit;
        }
    }
}

