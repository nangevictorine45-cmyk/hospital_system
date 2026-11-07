<?php
/**
 * =============================================================================
 * CityCare Hospital - Patient Appointments History & Management
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';

// Role Guard: Patient & Admin
requireLogin(['patient', 'admin']);

$user = currentUser();
$pdo = getDBConnection();
$settings = getClinicSettings();
$newRef = isset($_GET['new_ref']) ? trim((string)$_GET['new_ref']) : '';

// Fetch all appointments for this patient
$stmt = $pdo->prepare("
    SELECT 
        a.id,
        a.booking_reference,
        a.appointment_date,
        a.start_time,
        a.end_time,
        a.status,
        a.created_at,
        d.full_name AS doctor_name,
        d.specialty AS doctor_specialty,
        d.room_number,
        q.queue_number,
        q.status AS queue_status
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN queue_entries q ON a.id = q.appointment_id
    WHERE a.patient_id = :patient_id
    ORDER BY a.appointment_date DESC, a.start_time DESC
");
$stmt->execute([':patient_id' => $user['id']]);
$appointments = $stmt->fetchAll();

$pageTitle = 'My Appointments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">My Consultation Appointments</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            View and manage your upcoming doctor visits, booking references, and visit history.
        </p>
    </div>
    <a href="<?php echo APP_BASE_URL; ?>/patient/book.php" class="btn btn-primary" style="font-size: 14px;">
        &#10010; Book New Appointment
    </a>
</div>

<?php if (!empty($newRef)): ?>
    <div class="card" style="background: #f0fdf4; border-color: #bbf7d0; margin-bottom: 24px;">
        <div style="display: flex; gap: 16px; align-items: center;">
            <div style="font-size: 36px; color: var(--success);">&#10004;</div>
            <div>
                <h3 style="margin: 0 0 4px 0; color: #166534; font-size: 18px;">Appointment Confirmed!</h3>
                <p style="margin: 0; color: #15803d; font-size: 14px;">
                    Your booking reference is <strong><code><?php echo htmlspecialchars($newRef, ENT_QUOTES, 'UTF-8'); ?></code></strong>. 
                    Please save this reference or present it to the reception desk when checking in.
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <?php if (!empty($appointments)): ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Booking Ref</th>
                        <th>Specialist</th>
                        <th>Consultation Date &amp; Time</th>
                        <th>Room</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $app): ?>
                        <?php 
                        $isUpcoming = ($app['appointment_date'] >= date('Y-m-d') && in_array($app['status'], ['pending', 'confirmed']));
                        $appTimestamp = strtotime($app['appointment_date'] . ' ' . $app['start_time']);
                        $hoursRemaining = ($appTimestamp - time()) / 3600;
                        $canCancel = ($isUpcoming && $hoursRemaining >= (int)$settings['min_cancellation_hours_notice']);
                        ?>
                        <tr>
                            <td>
                                <strong><code><?php echo htmlspecialchars($app['booking_reference'], ENT_QUOTES, 'UTF-8'); ?></code></strong>
                                <?php if (!empty($app['queue_number'])): ?>
                                    <div style="margin-top: 4px;">
                                        <span class="badge" style="background: var(--primary-light); color: var(--primary-dark); font-size: 11px;">
                                            Ticket: <?php echo htmlspecialchars($app['queue_number'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($app['doctor_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div style="font-size: 12px; color: var(--gray-500);"><?php echo htmlspecialchars($app['doctor_specialty'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td>
                                <div><?php echo date('M d, Y (l)', strtotime($app['appointment_date'])); ?></div>
                                <div style="font-size: 13px; color: var(--gray-700); font-weight: 600;">
                                    <?php echo date('h:i A', strtotime($app['start_time'])); ?> &ndash; <?php echo date('h:i A', strtotime($app['end_time'])); ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge" style="background: var(--gray-100);"><?php echo htmlspecialchars($app['room_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td>
                                <?php if ($app['status'] === 'confirmed'): ?>
                                    <span class="badge" style="background: var(--success-light); color: var(--success);">Confirmed</span>
                                <?php elseif ($app['status'] === 'checked_in'): ?>
                                    <span class="badge" style="background: var(--primary-light); color: var(--primary);">Checked In</span>
                                <?php elseif ($app['status'] === 'completed'): ?>
                                    <span class="badge" style="background: var(--gray-100); color: var(--gray-700);">Completed</span>
                                <?php elseif ($app['status'] === 'cancelled'): ?>
                                    <span class="badge" style="background: var(--danger-light); color: var(--danger);">Cancelled</span>
                                <?php else: ?>
                                    <span class="badge" style="background: var(--warning-light); color: var(--warning);"><?php echo ucfirst($app['status']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canCancel): ?>
                                    <form method="POST" action="cancel.php" onsubmit="return confirm('Are you sure you want to cancel this appointment?');" style="display: inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="appointment_id" value="<?php echo $app['id']; ?>">
                                        <button type="submit" class="btn btn-secondary" style="color: var(--danger); border-color: #fecaca; padding: 6px 12px; font-size: 12px;">
                                            Cancel
                                        </button>
                                    </form>
                                <?php elseif ($isUpcoming): ?>
                                    <span style="font-size: 11px; color: var(--gray-400);" title="Notice window exceeded">Non-cancellable</span>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: var(--gray-400);">&ndash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 40px 20px;">
            <div style="font-size: 40px; margin-bottom: 12px; color: var(--gray-300);">&#128197;</div>
            <h3 style="margin: 0 0 8px 0; color: var(--gray-700);">No Appointments Booked Yet</h3>
            <p style="color: var(--gray-500); font-size: 14px; margin-bottom: 20px;">
                You haven't scheduled any doctor consultations. Choose a specialist to book your first appointment.
            </p>
            <a href="book.php" class="btn btn-primary">Schedule Consultation &rarr;</a>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

