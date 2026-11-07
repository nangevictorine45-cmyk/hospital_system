<?php
/**
 * =============================================================================
 * CityCare Hospital - Available Slots AJAX Endpoint
 * =============================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/booking.php';

$doctorId   = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$dateString = isset($_GET['date']) ? trim((string)$_GET['date']) : '';

if ($doctorId <= 0 || empty($dateString)) {
    echo json_encode([
        'success' => false,
        'message' => 'Both doctor_id and date query parameters are required.',
        'slots'   => []
    ]);
    exit;
}

$result = getDoctorAvailableSlots($doctorId, $dateString);
echo json_encode($result);
exit;

