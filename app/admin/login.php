<?php
/**
 * Автосервис «Автоимперия» (г. Сергиев Посад)
 * Экран входа персонала и мастера-приёмщика в АРМ (admin/login.php)
 */

require_once __DIR__ . '/../includes/ui_icons.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Обработка выхода
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    foreach (['staff_user_id', 'staff_username', 'staff_full_name', 'staff_role', 'staff_phone'] as $key) {
        unset($_SESSION[$key]);
    }
    session_regenerate_id(true);
    header('Location: ' . BASE_URL . '/admin/login.php?logged_out=1');
    exit;
}

// Если уже авторизован — перенаправляем на главную панель АРМ
if (isStaffLoggedIn()) {
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $errorMsg = 'Пожалуйста, введите логин и пароль сотрудника.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM staff_users WHERE username = :username AND is_active = 1 LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $masterPassword = getenv('AUTOIMPERIA_MASTER_PASSWORD');
        $usesDemoSecret = $user && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            && $user['username'] === 'demo' && $user['password_hash'] === 'disabled';
        $passwordMatches = $usesDemoSecret
            ? is_string($masterPassword) && strlen($masterPassword) >= 16 && hash_equals($masterPassword, $password)
            : $user && password_verify($password, $user['password_hash']);
        if ($passwordMatches) {
            // Успешная аутентификация
            session_regenerate_id(true);
            $_SESSION['staff_user_id']   = (int)$user['user_id'];
            $_SESSION['staff_username']  = $user['username'];
            $_SESSION['staff_full_name'] = $user['full_name'];
            $_SESSION['staff_role']      = $user['role'];
            $_SESSION['staff_phone']     = $user['phone'];

            header('Location: ' . BASE_URL . '/admin/index.php');
            exit;
        } else {
            $errorMsg = 'Неверный логин или пароль. Попробуйте снова.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Вход в АРМ мастера-приёмщика | Автоимперия</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/ui-icons.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin-panel.css">
  <style>
    body {
      background: #0f172a;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      margin: 0;
      font-family: 'Inter', sans-serif;
    }
    .login-card {
      background: #1e293b;
      border: 1px solid #334155;
      border-radius: 14px;
      padding: 40px;
      width: 100%;
      max-width: 440px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
      color: #f8fafc;
    }
    .login-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 24px;
    }
    .brand-crown {
      width: 44px;
      height: 44px;
      background: linear-gradient(135deg, #d32f2f, #b71c1c);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
    }
    .brand-name {
      font-size: 1.4rem;
      font-weight: 700;
      color: #ffffff;
    }
    .brand-name span { color: #f87171; }
    .brand-city {
      font-size: 0.82rem;
      color: #94a3b8;
    }
    .form-group {
      margin-bottom: 20px;
    }
    .form-label {
      display: block;
      margin-bottom: 8px;
      font-size: 0.88rem;
      font-weight: 600;
      color: #cbd5e1;
    }
    .form-input {
      width: 100%;
      box-sizing: border-box;
      padding: 12px 14px;
      background: #0f172a;
      border: 1px solid #475569;
      border-radius: 8px;
      color: #ffffff;
      font-size: 0.95rem;
      outline: none;
      transition: border-color 0.2s;
    }
    .form-input:focus {
      border-color: #ef4444;
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
    }
    .btn-submit {
      width: 100%;
      padding: 13px;
      background: linear-gradient(135deg, #d32f2f, #b71c1c);
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-weight: 700;
      font-size: 1rem;
      cursor: pointer;
      transition: transform 0.15s, opacity 0.2s;
    }
    .btn-submit:hover {
      opacity: 0.95;
      transform: translateY(-1px);
    }
    .alert-error {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid #ef4444;
      color: #fca5a5;
      padding: 12px 16px;
      border-radius: 8px;
      font-size: 0.88rem;
      margin-bottom: 20px;
    }
    .demo-hint {
      margin-top: 24px;
      padding: 12px;
      background: #0f172a;
      border: 1px dashed #475569;
      border-radius: 8px;
      font-size: 0.84rem;
      color: #94a3b8;
    }
    .demo-hint code {
      color: #f87171;
      background: #1e293b;
      padding: 2px 6px;
      border-radius: 4px;
      font-family: 'JetBrains Mono', monospace;
    }
    .back-link {
      display: inline-block;
      margin-top: 18px;
      color: #94a3b8;
      text-decoration: none;
      font-size: 0.86rem;
    }
    .back-link:hover { color: #f8fafc; }
  </style>
</head>
<body>

  <div class="login-card">
    <div class="login-brand">
      <div class="brand-crown"><?= uiIcon('crown') ?></div>
      <div>
        <div class="brand-name">Авто<span>империя</span></div>
        <div class="brand-city">АРМ мастера-приёмщика • г. Сергиев Посад</div>
      </div>
    </div>

    <h2 style="font-size: 1.15rem; margin-top: 0; margin-bottom: 18px; font-weight: 600;">Авторизация диспетчера</h2>

    <?php if (!empty($errorMsg)): ?>
      <div class="alert-error">
        <?= uiIcon('warning') ?> <?= htmlspecialchars($errorMsg) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>/admin/login.php">
      <div class="form-group">
        <label class="form-label">Логин сотрудника</label>
        <input type="text" name="username" class="form-input" value="demo" required autofocus autocomplete="username">
      </div>

      <div class="form-group">
        <label class="form-label">Пароль</label>
        <input type="password" name="password" class="form-input" required autocomplete="current-password">
      </div>

      <button type="submit" class="btn-submit">Войти в диспетчерскую →</button>
    </form>

    <div class="demo-hint"><strong>Учебный вход</strong><br>Логин: <code>demo</code><br>Пароль: <code>autoimperia-demo</code><br>Видны только записи текущего сеанса.</div>

    <div style="text-align: center;">
      <a href="<?= BASE_URL ?>/" class="back-link">← Вернуться на клиентский сайт онлайн-записи</a>
    </div>
  </div>

</body>
</html>

