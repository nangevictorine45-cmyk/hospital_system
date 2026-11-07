<?php
/**
 * =============================================================================
 * CityCare Hospital - Patient Notifications & Rescheduling Inbox
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/rescheduling.php';

// Role Guard: Patient & Admin Preview
requireLogin(['patient', 'admin']);

$user = currentUser();
$pdo = getDBConnection();
$errorMessage = '';
$successMessage = '';

// Handle 1-Click Option Selection Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_reschedule') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $errorMessage = 'Security validation failed (Invalid CSRF token). Please try again.';
    } else {
        $messageId    = (int)($_POST['message_id'] ?? 0);
        $newDate      = trim((string)($_POST['new_date'] ?? ''));
        $newStartTime = trim((string)($_POST['new_start_time'] ?? ''));

        $res = acceptRescheduleOption($messageId, (int)$user['id'], $newDate, $newStartTime);

        if ($res['success']) {
            setFlash('success', $res['message']);
            header('Location: ' . APP_BASE_URL . '/patient/appointments.php?new_ref=' . urlencode($res['new_booking_reference']));
            exit;
        } else {
            $errorMessage = $res['message'];
        }
    }
}

// Mark all unread messages as read upon viewing inbox
$pdo->prepare("UPDATE patient_messages SET is_read = 1 WHERE patient_id = :pat_id AND is_read = 0")
    ->execute([':pat_id' => $user['id']]);

$messages = getPatientMessages((int)$user['id']);

$pageTitle = 'Messages & Rescheduling';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">&#128172; Messages &amp; Rescheduling Notifications</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Notifications, clinic updates, and automatic rescheduling recommendations for missed visits.
        </p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="appointments.php" class="btn btn-secondary" style="font-size: 13px;">
            &#128337; My Appointments
        </a>
        <a href="book.php" class="btn btn-primary" style="font-size: 13px;">
            &#10010; Book Consultation
        </a>
    </div>
</div>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger">
        <span>&#10008;</span>
        <div><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
<?php endif; ?>

<?php if (!empty($messages)): ?>
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <?php foreach ($messages as $msg): ?>
            <?php 
            $isMissed = ($msg['message_type'] === 'missed_appointment');
            $suggestedSlots = !empty($msg['suggested_slots_json']) ? json_decode($msg['suggested_slots_json'], true) : [];
            $isResolved = ((int)$msg['is_resolved'] === 1);
            ?>
            <div class="card" style="margin-bottom: 0; border-left: 4px solid <?php echo $isMissed ? ($isResolved ? 'var(--success)' : 'var(--warning)') : 'var(--primary)'; ?>;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            <?php if ($isMissed): ?>
                                <span class="badge" style="background: <?php echo $isResolved ? 'var(--success-light)' : 'var(--warning-light)'; ?>; color: <?php echo $isResolved ? 'var(--success)' : 'var(--warning)'; ?>;">
                                    <?php echo $isResolved ? '&#10004; Rescheduled' : '&#9888; Action Required: Missed Visit'; ?>
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: var(--primary-light); color: var(--primary-dark);">Clinic Notice</span>
                            <?php endif; ?>
                            <span style="font-size: 12px; color: var(--gray-400);">
                                <?php echo date('M d, Y &bull; h:i A', strtotime($msg['created_at'])); ?>
                            </span>
                        </div>
                        <h2 style="margin: 0; font-size: 18px; color: var(--gray-900);">
                            <?php echo htmlspecialchars($msg['title'], ENT_QUOTES, 'UTF-8'); ?>
                        </h2>
                    </div>
                    <?php if (!empty($msg['booking_reference'])): ?>
                        <span class="badge" style="background: var(--gray-100); color: var(--gray-700);">
                            Ref: <?php echo htmlspecialchars($msg['booking_reference'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div style="font-size: 14px; color: var(--gray-700); line-height: 1.6; white-space: pre-line; margin-bottom: 16px;">
                    <?php echo htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <!-- Interactive Rescheduling Options Card -->
                <?php if ($isMissed && !empty($suggestedSlots)): ?>
                    <div style="background: #f8fafc; border: 1px solid var(--gray-200); border-radius: var(--radius-md); padding: 18px; margin-top: 14px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                            <strong style="font-size: 14px; color: var(--gray-900);">
                                &#128197; Recommended Reschedule Options:
                            </strong>
                            <span style="font-size: 12px; color: var(--gray-500);">
                                Pick an option below to confirm instantly
                            </span>
                        </div>

                        <?php if (!$isResolved): ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-bottom: 14px;">
                                <?php foreach ($suggestedSlots as $slot): ?>
                                    <form method="POST" action="messages.php" style="margin: 0;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="accept_reschedule">
                                        <input type="hidden" name="message_id" value="<?php echo $msg['id']; ?>">
                                        <input type="hidden" name="new_date" value="<?php echo $slot['date']; ?>">
                                        <input type="hidden" name="new_start_time" value="<?php echo $slot['start_time']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-block" style="text-align: left; padding: 12px 14px; border-color: var(--primary); background: #ffffff;">
                                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--primary);">
                                                <?php echo htmlspecialchars($slot['day_of_week']); ?>
                                            </div>
                                            <div style="font-size: 15px; font-weight: 700; color: var(--gray-900);">
                                                <?php echo htmlspecialchars($slot['display_time']); ?>
                                            </div>
                                            <div style="font-size: 12px; color: var(--gray-500);">
                                                <?php echo date('M d, Y', strtotime($slot['date'])); ?> &bull; Click to Confirm &rarr;
                                            </div>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; font-size: 13px; color: var(--gray-500);">
                                <span>Prefer a different consultation day?</span>
                                <a href="book.php" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px;">
                                    &#128269; Browse All Specialist Days &rarr;
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success" style="margin: 0;">
                                <span>&#10004;</span>
                                <div>
                                    <strong>Rescheduling Completed:</strong> This missed consultation has been recovered and scheduled into a new time slot. 
                                    <a href="appointments.php" style="color: inherit; font-weight: 700; text-decoration: underline;">View updated appointment list</a>.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card" style="text-align: center; padding: 48px 20px;">
        <div style="font-size: 48px; margin-bottom: 12px; color: var(--gray-300);">&#128236;</div>
        <h3 style="margin: 0 0 6px 0; font-size: 18px; color: var(--gray-700);">No Messages in Your Inbox</h3>
        <p style="color: var(--gray-500); font-size: 14px; margin-bottom: 20px;">
            You have no notifications or missed consultation recovery alerts at this time.
        </p>
        <a href="dashboard.php" class="btn btn-secondary">Return to Portal Dashboard</a>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

