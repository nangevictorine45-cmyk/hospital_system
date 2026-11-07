<?php
/**
 * =============================================================================
 * CityCare Hospital - Patient Portal Dashboard
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Role Guard: Patient & Admin Preview
requireLogin(['patient', 'admin']);

$user = currentUser();
$pdo = getDBConnection();

// Fetch patient's full profile
$stmt = $pdo->prepare("
    SELECT u.full_name, u.email, u.phone, u.google_id, u.auth_provider, u.avatar_url,
           p.date_of_birth, p.gender, p.address, p.emergency_contact_name, p.emergency_contact_phone
    FROM users u
    LEFT JOIN patient_profiles p ON u.id = p.user_id
    WHERE u.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $user['id']]);
$profile = $stmt->fetch();

// Fetch upcoming appointments count and next upcoming appointment
$appStmt = $pdo->prepare("
    SELECT a.*, d.full_name AS doctor_name, d.specialty, d.room_number, q.queue_number
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN queue_entries q ON a.id = q.appointment_id
    WHERE a.patient_id = :patient_id
      AND a.appointment_date >= CURDATE()
      AND a.status IN ('pending', 'confirmed', 'checked_in')
    ORDER BY a.appointment_date ASC, a.start_time ASC
");
$appStmt->execute([':patient_id' => $user['id']]);
$upcomingAppointments = $appStmt->fetchAll();
$upcomingCount = count($upcomingAppointments);
$nextAppointment = $upcomingAppointments[0] ?? null;

// Check for active unresolved missed appointment notifications
$missedMsgStmt = $pdo->prepare("
    SELECT id, title, created_at 
    FROM patient_messages 
    WHERE patient_id = :pat_id AND message_type = 'missed_appointment' AND is_resolved = 0
    ORDER BY id DESC LIMIT 1
");
$missedMsgStmt->execute([':pat_id' => $user['id']]);
$unresolvedMissedMsg = $missedMsgStmt->fetch();

$pageTitle = 'Patient Portal';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($user['role'] === 'admin'): ?>
    <div class="alert alert-info" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 20px;">
        <div>
            &#128737; <strong>Administrator View</strong>: You are viewing the Patient Portal with administrative privileges.
        </div>
        <a href="<?php echo APP_BASE_URL; ?>/admin/dashboard.php" class="btn btn-secondary" style="font-size: 12px; padding: 4px 10px;">
            &larr; Return to Admin Command Center
        </a>
    </div>
<?php endif; ?>

<div class="card-header">
    <div>
        <h1 class="card-title">Patient Portal</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Welcome back, <strong><?php echo htmlspecialchars($profile['full_name'] ?? $user['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>!
        </p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="messages.php" class="btn btn-secondary" style="font-size: 13px;">
            &#128172; Messages &amp; Rescheduling
        </a>
        <a href="book.php" class="btn btn-primary" style="font-size: 13px;">
            &#10010; Book Consultation
        </a>
    </div>
</div>

<?php if ($unresolvedMissedMsg): ?>
    <div class="card" style="background: #fffbeb; border: 1px solid #fde68a; margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="font-size: 32px; color: var(--warning);">&#9888;</div>
                <div>
                    <h3 style="margin: 0 0 4px 0; font-size: 16px; color: #92400e;">Missed Consultation: Rescheduling Options Ready</h3>
                    <p style="margin: 0; font-size: 13px; color: #78350f;">
                        You have 1 missed appointment. Our clinical scheduling engine has recommended 3 upcoming time slots for you.
                    </p>
                </div>
            </div>
            <a href="messages.php" class="btn btn-primary" style="padding: 10px 18px; font-size: 13px;">
                &#128172; View Reschedule Options &rarr;
            </a>
        </div>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Upcoming Appointments</div>
        <div class="stat-value" style="color: var(--primary);"><?php echo $upcomingCount; ?></div>
        <div class="stat-desc">Scheduled specialist consultations</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Next Visit</div>
        <div class="stat-value" style="font-size: 18px; color: var(--gray-900);">
            <?php if ($nextAppointment): ?>
                <?php echo date('M d', strtotime($nextAppointment['appointment_date'])); ?> @ <?php echo date('h:i A', strtotime($nextAppointment['start_time'])); ?>
            <?php else: ?>
                None Scheduled
            <?php endif; ?>
        </div>
        <div class="stat-desc">
            <?php echo $nextAppointment ? htmlspecialchars($nextAppointment['doctor_name'], ENT_QUOTES, 'UTF-8') : 'No pending consultations'; ?>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Registered Phone</div>
        <div class="stat-value" style="font-size: 18px; color: var(--gray-700);">
            <?php echo htmlspecialchars($profile['phone'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <div class="stat-desc">Used for SMS queue notices</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
    <!-- Quick Actions Card -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title" style="font-size: 17px;">Patient Services</h2>
        </div>
        <p style="color: var(--gray-500); font-size: 14px; margin-top: 0;">
            Use the quick shortcuts below to schedule consultations or manage your bookings.
        </p>

        <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px;">
            <a href="book.php" class="btn btn-primary" style="justify-content: flex-start; padding: 12px 16px;">
                <span style="font-size: 18px;">&#128197;</span>
                <div>
                    <strong>Book Doctor Appointment</strong>
                    <div style="font-size: 12px; opacity: 0.9; font-weight: normal;">Browse specialists and reserve consultation time</div>
                </div>
            </a>

            <a href="appointments.php" class="btn btn-secondary" style="justify-content: flex-start; padding: 12px 16px;">
                <span style="font-size: 18px;">&#128337;</span>
                <div>
                    <strong>My Appointments &amp; Booking References</strong>
                    <div style="font-size: 12px; color: var(--gray-500); font-weight: normal;">View booking codes, visit history, or cancel</div>
                </div>
            </a>

            <a href="messages.php" class="btn btn-secondary" style="justify-content: flex-start; padding: 12px 16px;">
                <span style="font-size: 18px;">&#128172;</span>
                <div>
                    <strong>Messages &amp; Reschedule Notifications</strong>
                    <div style="font-size: 12px; color: var(--gray-500); font-weight: normal;">View clinic notifications and 1-click missed visit recovery</div>
                </div>
            </a>

            <a href="<?php echo APP_BASE_URL; ?>/doctors.php" class="btn btn-secondary" style="justify-content: flex-start; padding: 12px 16px;">
                <span style="font-size: 18px;">&#129658;</span>
                <div>
                    <strong>Find Medical Specialists</strong>
                    <div style="font-size: 12px; color: var(--gray-500); font-weight: normal;">View doctor qualifications and consultation hours</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Patient Medical Card Summary -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title" style="font-size: 17px;">Healthcare Profile</h2>
        </div>
        <table class="table">
            <tbody>
                <tr>
                    <td style="width: 140px; font-weight: 600; color: var(--gray-500);">Email</td>
                    <td><?php echo htmlspecialchars($profile['email'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td style="font-weight: 600; color: var(--gray-500);">Authentication</td>
                    <td>
                        <?php if (!empty($profile['google_id'])): ?>
                            <span class="badge" style="background: #eaf1fb; color: #1a73e8; font-weight: 600;">
                                &#10004; Google Connected
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: var(--gray-100); color: var(--gray-700);">
                                Standard Password
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="font-weight: 600; color: var(--gray-500);">Date of Birth</td>
                    <td><?php echo htmlspecialchars($profile['date_of_birth'] ?? 'Not specified', ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td style="font-weight: 600; color: var(--gray-500);">Gender</td>
                    <td><?php echo htmlspecialchars(ucfirst($profile['gender'] ?? 'Not specified'), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td style="font-weight: 600; color: var(--gray-500);">Residential Address</td>
                    <td><?php echo htmlspecialchars($profile['address'] ?? 'Not specified', ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <tr>
                    <td style="font-weight: 600; color: var(--gray-500);">Emergency Contact</td>
                    <td>
                        <?php if (!empty($profile['emergency_contact_name'])): ?>
                            <strong><?php echo htmlspecialchars($profile['emergency_contact_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            (<?php echo htmlspecialchars($profile['emergency_contact_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)
                        <?php else: ?>
                            <em>None registered</em>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
