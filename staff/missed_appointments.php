<?php
/**
 * =============================================================================
 * CityCare Hospital - Staff Missed Appointment Recovery Desk
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/rescheduling.php';

// Role Guard: Staff & Admin only
requireLogin(['staff', 'admin']);

$user = currentUser();
$pdo = getDBConnection();
$errorMessage = '';

// Handle Manual Reschedule Message Dispatch for a single appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_reschedule_message') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $errorMessage = 'Invalid CSRF token.';
    } else {
        $appId = (int)($_POST['appointment_id'] ?? 0);
        $note  = trim((string)($_POST['note'] ?? ''));

        $res = processMissedAppointment($appId, $note);
        if ($res['success']) {
            setFlash('success', $res['message']);
            header('Location: missed_appointments.php');
            exit;
        } else {
            $errorMessage = $res['message'];
        }
    }
}

// Handle Auto-Detect Batch Scan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'auto_detect_batch') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $errorMessage = 'Invalid CSRF token.';
    } else {
        $count = autoDetectAndProcessPastMissedAppointments();
        setFlash('info', "Batch scan complete: {$count} past unfulfilled appointments were automatically detected, marked as no-show, and dispatched rescheduling options.");
        header('Location: missed_appointments.php');
        exit;
    }
}

// Fetch all no-show appointments or past unfulfilled appointments
$today = date('Y-m-d');
$nowTime = date('H:i:s');

$stmt = $pdo->prepare("
    SELECT 
        a.id,
        a.booking_reference,
        a.appointment_date,
        a.start_time,
        a.end_time,
        a.status,
        u.id AS patient_id,
        u.full_name AS patient_name,
        u.phone AS patient_phone,
        u.email AS patient_email,
        d.full_name AS doctor_name,
        d.specialty AS doctor_specialty,
        d.room_number,
        m.id AS message_id,
        m.is_read AS msg_read,
        m.is_resolved AS msg_resolved,
        m.created_at AS msg_sent_at
    FROM appointments a
    JOIN users u ON a.patient_id = u.id
    JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN patient_messages m ON (a.id = m.appointment_id AND m.message_type = 'missed_appointment')
    WHERE a.status = 'no_show' 
       OR (a.status IN ('pending', 'confirmed') AND (a.appointment_date < :today OR (a.appointment_date = :today AND a.end_time < :now_time)))
    ORDER BY a.appointment_date DESC, a.start_time DESC
");
$stmt->execute([':today' => $today, ':now_time' => $nowTime]);
$missedList = $stmt->fetchAll();

$pageTitle = 'Missed Appointment Recovery Desk';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">&#9888; Missed Appointment Recovery Desk</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Detect patient no-shows, dispatch automated rescheduling options, and monitor consultation recovery.
        </p>
    </div>
    <div style="display: flex; gap: 8px;">
        <form method="POST" action="missed_appointments.php" style="margin: 0;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="auto_detect_batch">
            <button type="submit" class="btn btn-primary" style="font-size: 13px;">
                &#128269; Run Auto-Detect Batch Scan
            </button>
        </form>
        <a href="appointments.php" class="btn btn-secondary" style="font-size: 13px;">
            &larr; Master Roster
        </a>
    </div>
</div>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger">
        <span>&#10008;</span>
        <div><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
<?php endif; ?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h2 style="font-size: 16px; margin: 0; color: var(--gray-800);">
            Unattended Consultations &amp; No-Shows (<?php echo count($missedList); ?>)
        </h2>
    </div>

    <?php if (!empty($missedList)): ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Booking Ref</th>
                        <th>Missed Consultation</th>
                        <th>Patient</th>
                        <th>Specialist</th>
                        <th>Status</th>
                        <th>Reschedule Message Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($missedList as $item): ?>
                        <tr>
                            <td>
                                <strong><code><?php echo htmlspecialchars($item['booking_reference'], ENT_QUOTES, 'UTF-8'); ?></code></strong>
                            </td>
                            <td>
                                <div><?php echo date('M d, Y', strtotime($item['appointment_date'])); ?></div>
                                <div style="font-size: 12px; color: var(--gray-700); font-weight: 600;">
                                    <?php echo date('h:i A', strtotime($item['start_time'])); ?>
                                </div>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($item['patient_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div style="font-size: 12px; color: var(--gray-500);"><?php echo htmlspecialchars($item['patient_phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($item['doctor_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div style="font-size: 11px; color: var(--gray-500);"><?php echo htmlspecialchars($item['doctor_specialty'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($item['room_number'], ENT_QUOTES, 'UTF-8'); ?>)</div>
                            </td>
                            <td>
                                <span class="badge" style="background: var(--danger-light); color: var(--danger);">
                                    <?php echo ($item['status'] === 'no_show') ? 'No-Show' : 'Missed'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($item['message_id'])): ?>
                                    <?php if ((int)$item['msg_resolved'] === 1): ?>
                                        <span class="badge" style="background: var(--success-light); color: var(--success);">
                                            &#10004; Patient Rescheduled
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background: var(--warning-light); color: var(--warning);">
                                            &#9993; Message Sent (Awaiting Patient)
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge" style="background: var(--gray-100); color: var(--gray-500);">
                                        Not Notified Yet
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($item['message_id'])): ?>
                                    <form method="POST" action="missed_appointments.php" style="display: inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="send_reschedule_message">
                                        <input type="hidden" name="appointment_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">
                                            &#9993; Send 3 Reschedule Options
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="missed_appointments.php" style="display: inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="send_reschedule_message">
                                        <input type="hidden" name="appointment_id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="btn btn-secondary" style="padding: 6px 10px; font-size: 11px;">
                                            Resend Options
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="text-align: center; color: var(--gray-500); padding: 30px; margin: 0;">
            No missed consultations or unattended appointments found.
        </p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

