<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/client_auth.php';
require_once __DIR__ . '/../includes/ui_icons.php';

if (signedInClient($pdo)) {
    clientRedirect('/client/index.php');
}
$register = ($_GET['mode'] ?? '') === 'register';
$error = '';
$email = strtolower(clientFormValue($_POST, 'email'));
$name = clientFormValue($_POST, 'name');
$phone = clientFormValue($_POST, 'phone');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (!checkClientCsrf()) {
        $error = 'Форма устарела. Откройте страницу заново.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || strlen($password) > 72 || str_contains($password, "\0")) {
        $error = 'Проверьте адрес почты и пароль.';
    } elseif ($register) {
        if ($name === '' || mb_strlen($name) > 80 || mb_strlen($phone) > 30 || !preg_match('/^\d{10,11}$/D', clientPhoneKey($phone))) {
            $error = 'Введите имя и телефон из 10–11 цифр.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'В пароле должно быть не менее 8 символов.';
        } elseif ($password !== ($_POST['confirm_password'] ?? null)) {
            $error = 'Пароли не совпадают.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $phoneKey = clientPhoneKey($phone);
            $pdo->beginTransaction();
            try {
                $guestId = (int)($_SESSION['guest_clients'][$phoneKey] ?? 0);
                $guest = $pdo->prepare('SELECT client_id FROM clients WHERE client_id = ?
                    AND NOT EXISTS (SELECT 1 FROM client_accounts WHERE client_accounts.client_id = clients.client_id)');
                $guest->execute([$guestId]);
                $id = (int)$guest->fetchColumn();
                if ($id) {
                    $pdo->prepare('UPDATE clients SET full_name = ?, phone = ?, email = ? WHERE client_id = ?')
                        ->execute([$name, $phone, $email, $id]);
                } else {
                    $id = insertReturningId($pdo, 'INSERT INTO clients (full_name, phone, email) VALUES (?, ?, ?)',
                        [$name, $phone, $email], 'client_id');
                }
                $pdo->prepare('INSERT INTO client_accounts (client_id, email, password_hash) VALUES (?, ?, ?)')
                    ->execute([$id, $email, $hash]);
                $pdo->commit();
                unset($_SESSION['guest_clients'][$phoneKey]);
            } catch (PDOException $exception) {
                $pdo->rollBack();
                if (!in_array($exception->getCode(), ['23000', '23505'], true)) {
                    throw $exception;
                }
                $error = 'Этот адрес почты уже используется. Войдите в кабинет или укажите другой.';
            }
        }
    } else {
        $attempts = $_SESSION['client_login_attempts'] ?? [];
        $attempts = array_values(array_filter($attempts, static fn ($t) => $t > time() - 300));
        if (count($attempts) >= 8) {
            $error = 'Слишком много попыток. Попробуйте через несколько минут.';
        } else {
            $stmt = $pdo->prepare('SELECT client_id, password_hash FROM client_accounts WHERE lower(email) = ?');
            $stmt->execute([$email]);
            $account = $stmt->fetch();
            if ($account && password_verify($password, $account['password_hash'])) {
                $id = (int)$account['client_id'];
            } else {
                $attempts[] = time();
                $error = 'Неверная почта или пароль.';
            }
        }
        $_SESSION['client_login_attempts'] = $attempts;
    }

    if ($error === '' && isset($id)) {
        session_regenerate_id(true);
        unset($_SESSION['client_csrf'], $_SESSION['client_login_attempts']);
        $_SESSION['client_id'] = $id;
        clientRedirect('/client/index.php');
    }
}
$title = $register ? 'Регистрация клиента' : 'Вход для клиента';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $title ?> · Автоимперия</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/ui-icons.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/booking-widget.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/client-account.css">
</head>
<body>
<main class="account-login">
  <a class="account-brand" href="<?= BASE_URL ?>/"><?= uiIcon('crown') ?> Автоимперия</a>
  <h1><?= $title ?></h1>
  <p class="account-muted">Записи, история ремонтов и напоминания по автомобилям.</p>
  <?php if (!PERSISTENT_DB): ?><p class="account-notice">Учебный режим. Используйте вымышленные данные. Аккаунт и записи доступны в текущем сеансе.</p><?php endif; ?>
  <?php if ($error): ?><p class="account-error" role="alert"><?= clientHtml($error) ?></p><?php endif; ?>
  <form method="post" class="account-form">
    <input type="hidden" name="csrf" value="<?= clientCsrfToken() ?>">
    <?php if ($register): ?>
      <label>Имя<input class="form-control" name="name" value="<?= clientHtml($name) ?>" maxlength="80" autocomplete="name" required></label>
      <label>Телефон<input class="form-control" type="tel" name="phone" value="<?= clientHtml($phone) ?>" maxlength="30" autocomplete="tel" required></label>
    <?php endif; ?>
    <label>Электронная почта<input class="form-control" type="email" name="email" value="<?= clientHtml($email) ?>" maxlength="254" autocomplete="email" required></label>
    <label>Пароль<input class="form-control" type="password" name="password" autocomplete="<?= $register ? 'new-password' : 'current-password' ?>" <?= $register ? 'minlength="8"' : '' ?> maxlength="72" required></label>
    <?php if ($register): ?>
      <label>Повторите пароль<input class="form-control" type="password" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="72" required></label>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit"><?= $register ? 'Создать аккаунт' : 'Войти' ?></button>
  </form>
  <p class="account-alt">
    <?php if ($register): ?>Уже есть аккаунт? <a href="<?= BASE_URL ?>/client/login.php">Войти</a>
    <?php else: ?>Впервые здесь? <a href="<?= BASE_URL ?>/client/login.php?mode=register">Зарегистрироваться</a><?php endif; ?>
  </p>
</main>
</body>
</html>
