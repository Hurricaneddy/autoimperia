<?php
/**
 * API: api/admin/update_status.php
 * Смена статуса заявки, переназначение поста или мастера приёмщиком
 */

define('IS_API_CALL', true);
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

requireStaffAuth(true);

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = $_POST;
}

$appointmentId = isset($data['appointment_id']) ? (int)$data['appointment_id'] : 0;
$newStatus     = isset($data['status']) ? trim($data['status']) : '';
$newBayId      = !empty($data['bay_id']) ? (int)$data['bay_id'] : null;
$newMasterId   = !empty($data['master_id']) ? (int)$data['master_id'] : null;
$internalNotes = isset($data['internal_notes']) ? trim($data['internal_notes']) : null;
$cancelReason  = isset($data['cancel_reason']) ? trim($data['cancel_reason']) : null;

$allowedStatuses = ['new', 'confirmed', 'in_work', 'done', 'canceled'];

if ($appointmentId <= 0) {
    sendJsonResponse(['success' => false, 'error' => 'Не указан ID заявки'], 400);
}

if (!in_array($newStatus, $allowedStatuses, true)) {
    sendJsonResponse(['success' => false, 'error' => 'Недопустимый статус заявки'], 400);
}

try {
    $stmt = $pdo->prepare("
        UPDATE appointments 
        SET status = :status,
            bay_id = COALESCE(:bay_id, bay_id),
            master_id = COALESCE(:master_id, master_id),
            internal_notes = COALESCE(:notes, internal_notes),
            cancel_reason = COALESCE(:cancel_reason, cancel_reason)
        WHERE appointment_id = :id
    ");
    $stmt->execute([
        ':status'        => $newStatus,
        ':bay_id'        => $newBayId,
        ':master_id'     => $newMasterId,
        ':notes'         => $internalNotes,
        ':cancel_reason' => $cancelReason,
        ':id'            => $appointmentId
    ]);

    if ($stmt->rowCount() === 0) {
        // Проверяем, существует ли запись
        $check = $pdo->prepare("SELECT appointment_id FROM appointments WHERE appointment_id = :id");
        $check->execute([':id' => $appointmentId]);
        if (!$check->fetch()) {
            sendJsonResponse(['success' => false, 'error' => 'Заявка не найдена'], 404);
        }
    }

    sendJsonResponse([
        'success'        => true,
        'appointment_id' => $appointmentId,
        'new_status'     => $newStatus,
        'message'        => 'Статус заявки успешно обновлен'
    ]);
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'error'   => 'Ошибка при обновлении статуса'
    ], 500);
}

