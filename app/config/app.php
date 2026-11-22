<?php
/**
 * Автосервис «Автоимперия» (г. Сергиев Посад)
 * Глобальные константы и параметры конфигурации приложения
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// Базовый веб-путь проекта (для поддержки развертывания как в корне домена, так и в подпапках вроде /autoimperia/ в XAMPP / OSPanel)
if (!defined('BASE_URL')) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    $dir = preg_replace('#/(admin|api(/admin)?|cli)$#', '', $dir);
    define('BASE_URL', rtrim($dir, '/'));
}

define('APP_NAME', 'Автосервис «Автоимперия»');
define('APP_CITY', 'Сергиев Посад');
define('APP_ADDRESS', 'Сергиев Посад · учебный стенд');
define('APP_PHONE', '—');
define('APP_PHONE_MOBILE', '—');
define('APP_EMAIL', '—');

// Рабочий график техцентра
define('WORK_START_TIME', '09:00');
define('WORK_END_TIME', '20:00');
define('SLOT_STEP_MINUTES', 30); // Шаг временной сетки: 30 минут

// Настройки онлайн-оплаты и монетизации
define('PREPAY_DEPOSIT_RUB', 500.00); // Сумма предоплаты брони
define('ONLINE_PAY_DISCOUNT_PERCENT', 5.0); // Скидка при 100% онлайн-оплате

// Генерация уникального кода бронирования формата AI-YYYYMMDD-XXXX
function generateBookingCode(): string {
    $datePart = date('Ymd');
    $randPart = str_pad((string)random_int(100, 9999), 4, '0', STR_PAD_LEFT);
    return "AI-{$datePart}-{$randPart}";
}

// Защита от CSRF
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Хелпер отправки JSON-ответа
function sendJsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

