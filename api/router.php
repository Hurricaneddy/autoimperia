<?php
declare(strict_types=1);

$routes = [
    '/' => ['index.php', ['GET']],
    '/index.php' => ['index.php', ['GET']],
    '/admin/index.php' => ['admin/index.php', ['GET']],
    '/admin/login.php' => ['admin/login.php', ['GET', 'POST']],
    '/client/login.php' => ['client/login.php', ['GET', 'POST']],
    '/client/index.php' => ['client/index.php', ['GET', 'POST']],
    '/api/check_status.php' => ['api/check_status.php', ['GET']],
    '/api/get_services.php' => ['api/get_services.php', ['GET']],
    '/api/get_slots.php' => ['api/get_slots.php', ['GET']],
    '/api/parse_car_pricing.php' => ['api/parse_car_pricing.php', ['GET']],
    '/api/create_booking.php' => ['api/create_booking.php', ['POST']],
    '/api/process_payment.php' => ['api/process_payment.php', ['POST']],
    '/api/admin/get_timeline.php' => ['api/admin/get_timeline.php', ['GET']],
    '/api/admin/quick_add.php' => ['api/admin/quick_add.php', ['POST']],
    '/api/admin/update_status.php' => ['api/admin/update_status.php', ['POST']],
];

$path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
if (PHP_SAPI === 'cli-server' && preg_match('~^/assets/(?:[a-z0-9_-]+/)*[a-z0-9_-]+\.(?:css|js|svg)$~D', $path)) {
    return false;
}
if (isset($_GET['__demo_route']) && is_string($_GET['__demo_route'])) {
    $path = '/' . ltrim($_GET['__demo_route'], '/');
}
if (!isset($routes[$path])) {
    http_response_code(404);
    exit('Страница не найдена');
}

[$filename, $methods] = $routes[$path];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, $methods, true)) {
    header('Allow: ' . implode(', ', $methods));
    http_response_code(405);
    exit;
}
if ($method === 'POST' && ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
    http_response_code(403);
    exit;
}

define('BASE_URL', '');
date_default_timezone_set('Europe/Moscow');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
ini_set('display_errors', '0');

$workspace = sys_get_temp_dir() . '/autoimperia-demo-v1';
if (!is_dir($workspace) && !@mkdir($workspace, 0700) && !is_dir($workspace)) {
    http_response_code(503);
    exit('Временное хранилище недоступно');
}
session_save_path($workspace);
session_name('autoimperia_demo');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'path' => '/',
    'secure' => isset($_SERVER['VERCEL']) || strtolower($_SERVER['HTTPS'] ?? '') === 'on',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!isset($_SESSION['demo_workspace'])) {
    $_SESSION['demo_workspace'] = bin2hex(random_bytes(20));
}
$configuredDb = getenv('AUTOIMPERIA_DB_PATH');
$localDb = PHP_SAPI === 'cli-server' ? __DIR__ . '/../var/autoimperia.sqlite' : '';
define('PERSISTENT_DB', !isset($_SERVER['VERCEL']) && ($configuredDb || $localDb !== ''));
define('DEMO_DB_FILE', PERSISTENT_DB ? ($configuredDb ?: $localDb) : $workspace . '/db-' . $_SESSION['demo_workspace'] . '.sqlite');

try {
    require __DIR__ . '/../app/' . $filename;
} catch (Throwable $exception) {
    error_log('Autoimperia demo: ' . $exception::class);
    http_response_code(500);
    if (str_starts_with($path, '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Ошибка демоверсии'], JSON_UNESCAPED_UNICODE);
    } else {
        echo 'Не удалось открыть демоверсию.';
    }
}
