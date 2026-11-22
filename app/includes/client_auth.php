<?php
declare(strict_types=1);

function clientFormValue(array $values, string $key): string
{
    return is_string($values[$key] ?? null) ? trim($values[$key]) : '';
}

function clientPhoneKey(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    return strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)
        ? substr($digits, 1)
        : $digits;
}

function clientOdometer(string $value): ?int
{
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2000000]]) ?: null;
}

function signedInClient(PDO $pdo): ?array
{
    if (empty($_SESSION['client_id'])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT c.client_id, c.full_name, c.phone, ca.email FROM client_accounts ca
        JOIN clients c ON c.client_id = ca.client_id WHERE ca.client_id = ?');
    $stmt->execute([(int)$_SESSION['client_id']]);
    return $stmt->fetch() ?: null;
}

function sessionBookingClients(): array
{
    $ids = array_values($_SESSION['guest_clients'] ?? []);
    if (!empty($_SESSION['client_id'])) {
        $ids[] = (int)$_SESSION['client_id'];
    }
    return array_unique(array_map('intval', $ids));
}

function rememberGuestBooking(string $phone, int $clientId): void
{
    $_SESSION['guest_clients'][clientPhoneKey($phone)] = $clientId;
}

function clientCsrfToken(): string
{
    return $_SESSION['client_csrf'] ??= bin2hex(random_bytes(32));
}

function checkClientCsrf(): bool
{
    return hash_equals(clientCsrfToken(), clientFormValue($_POST, 'csrf'));
}

function clientRedirect(string $path): never
{
    header('Location: ' . BASE_URL . $path, true, 303);
    exit;
}

function ownedClientVehicle(PDO $pdo, int $clientId, int $vehicleId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM vehicles WHERE client_id = ? AND vehicle_id = ?');
    $stmt->execute([$clientId, $vehicleId]);
    return $stmt->fetch() ?: null;
}

function clientHtml(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
