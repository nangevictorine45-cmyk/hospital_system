<?php
/**
 * =============================================================================
 * CityCare Hospital - Appointment Cancellation Handler
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';

requireLogin(['patient', 'staff', 'admin']);

$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (Invalid CSRF token).');
        header('Location: ' . APP_BASE_URL . '/patient/appointments.php');
        exit;
    }

    $appointmentId = (int)($_POST['appointment_id'] ?? 0);
    $result = cancelAppointment($appointmentId, (int)$user['id'], (string)$user['role']);

    if ($result['success']) {
        setFlash('info', $result['message']);
    } else {
        setFlash('danger', $result['message']);
    }
}

$redirectUrl = ($user['role'] === 'patient') 
    ? APP_BASE_URL . '/patient/appointments.php'
    : APP_BASE_URL . '/staff/appointments.php';

header('Location: ' . $redirectUrl);
exit;

