<?php
/**
 * Имитация оплаты для учебной демонстрации.
 */

define('IS_API_CALL', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/client_auth.php';

header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$bookingCode   = trim($data['booking_code'] ?? '');
$paymentMethod = trim($data['payment_method'] ?? 'sbp'); // sbp, mir_card, tinkoff_pay, sber_pay
$paymentType   = trim($data['payment_type'] ?? 'full_online'); // deposit_prepay, full_online
$customAmount  = !empty($data['amount']) ? (float)$data['amount'] : null;

if (empty($bookingCode)) {
    sendJsonResponse(['success' => false, 'error' => 'Не указан номер бронирования'], 400);
}

try {
    $pdo->beginTransaction();

    $forUpdate = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') ? '' : 'FOR UPDATE';
    $stmt = $pdo->prepare("SELECT * FROM appointments WHERE booking_code = :code {$forUpdate}");
    $stmt->execute([':code' => $bookingCode]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appointment || !in_array((int)$appointment['client_id'], sessionBookingClients(), true)) {
        $pdo->rollBack();
        sendJsonResponse(['success' => false, 'error' => 'Запись не найдена'], 404);
    }

    $appId = (int)$appointment['appointment_id'];
    $totalPrice = (float)$appointment['total_price'];

    if ($customAmount && $customAmount > 0) {
        $payAmount = min($customAmount, $totalPrice);
    } elseif ($paymentType === 'deposit_prepay') {
        $payAmount = min(PREPAY_DEPOSIT_RUB, $totalPrice);
    } else {
        $payAmount = $totalPrice - (float)$appointment['paid_amount'];
    }

    if ($payAmount <= 0) {
        $pdo->rollBack();
        sendJsonResponse(['success' => false, 'error' => 'Заказ уже полностью оплачен'], 400);
    }

    $payCode = 'PAY-' . str_replace('-', '', $bookingCode) . '-' . rand(10, 99);
    $fiscalNumber = null;
    $fiscalQr = null;
    $insPay = $pdo->prepare("
        INSERT INTO payments 
        (appointment_id, payment_code, amount, payment_type, payment_method, status, fiscal_receipt_number, fiscal_qr_payload) 
        VALUES 
        (:aid, :code, :amount, :ptype, :pmethod, 'succeeded', :fisc_num, :fisc_qr)
    ");
    $insPay->execute([
        ':aid'      => $appId,
        ':code'     => $payCode,
        ':amount'   => $payAmount,
        ':ptype'    => $paymentType,
        ':pmethod'  => $paymentMethod,
        ':fisc_num' => $fiscalNumber,
        ':fisc_qr'  => $fiscalQr
    ]);

    $newPaidAmount = (float)$appointment['paid_amount'] + $payAmount;
    $newPayStatus = ($newPaidAmount >= $totalPrice) ? 'paid' : 'partially_paid';

    $updApp = $pdo->prepare("UPDATE appointments SET paid_amount = :paid, payment_status = :status WHERE appointment_id = :id");
    $updApp->execute([
        ':paid'   => $newPaidAmount,
        ':status' => $newPayStatus,
        ':id'     => $appId
    ]);

    $pdo->commit();

    sendJsonResponse([
        'success'        => true,
        'booking_code'   => $bookingCode,
        'payment_code'   => $payCode,
        'amount_paid'    => $payAmount,
        'total_paid'     => $newPaidAmount,
        'payment_status' => $newPayStatus,
        'payment_method' => $paymentMethod,
        'fiscal_receipt' => null,
        'simulated'      => true,
        'message'        => 'Демооплата выполнена'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendJsonResponse([
        'success' => false,
        'error'   => 'Ошибка при проведении платежа'
    ], 500);
}

