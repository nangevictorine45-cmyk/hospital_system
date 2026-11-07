<?php
/**
 * =============================================================================
 * CityCare Hospital - Administrative System Command Center
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Role Guard: Administrator only
requireLogin(['admin']);

$user = currentUser();
$pdo = getDBConnection();

// 1. User Counts by Role
$userCountStmt = $pdo->query("
    SELECT role, COUNT(*) AS total 
    FROM users 
    GROUP BY role
");
$userCounts = [];
while ($row = $userCountStmt->fetch()) {
    $userCounts[$row['role']] = (int)$row['total'];
}

// 2. Doctor and Schedule Counts
$docCount = (int)$pdo->query("SELECT COUNT(*) FROM doctors WHERE active_status = 1")->fetchColumn();
$schedCount = (int)$pdo->query("SELECT COUNT(*) FROM doctor_schedules WHERE active_status = 1")->fetchColumn();

// 3. Appointment Counts
$totalAppointments = (int)$pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
$upcomingAppointments = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date >= CURDATE() AND status IN ('pending', 'confirmed')")->fetchColumn();

// 4. Clinic Settings
$clinic = $pdo->query("SELECT * FROM clinic_settings LIMIT 1")->fetch();

// 5. Recent Appointments (Last 5)
$recentAppsStmt = $pdo->query("
    SELECT a.id, a.booking_reference, a.appointment_date, a.start_time, a.status,
           u.full_name AS patient_name, u.email AS patient_email,
           d.full_name AS doctor_name, d.specialty
    FROM appointments a
    JOIN users u ON a.patient_id = u.id
    JOIN doctors d ON a.doctor_id = d.id
    ORDER BY a.id DESC
    LIMIT 5
");
$recentAppointments = $recentAppsStmt->fetchAll();

// 6. Recent Audit Logs (Last 8)
$auditStmt = $pdo->query("
    SELECT 
        a.id, 
        a.action, 
        a.entity_type, 
        a.description, 
        a.ip_address, 
        a.created_at,
        u.email AS actor_email,
        u.role AS actor_role
    FROM audit_logs a
    LEFT JOIN users u ON a.actor_user_id = u.id
    ORDER BY a.id DESC
    LIMIT 8
");
$recentLogs = $auditStmt->fetchAll();

$pageTitle = 'Administrator Command Center';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">System Administration Command Center</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Administrator: <strong><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong> (<code><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></code>) &bull;
            Clinic: <strong><?php echo htmlspecialchars($clinic['clinic_name'] ?? 'CityCare Hospital', ENT_QUOTES, 'UTF-8'); ?></strong>
        </p>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="<?php echo APP_BASE_URL; ?>/staff/dashboard.php" class="btn btn-secondary" style="font-size: 13px;">
            &#127973; Staff Desk View
        </a>
        <a href="<?php echo APP_BASE_URL; ?>/patient/dashboard.php" class="btn btn-secondary" style="font-size: 13px;">
            &#128100; Patient Portal View
        </a>
    </div>
</div>

<!-- =========================================================================
     ADMINISTRATION FUNCTIONALITY MENU (Actionable Navigation Grid)
     ========================================================================= -->
<div style="margin-bottom: 24px;">
    <h2 style="font-size: 16px; margin: 0 0 12px 0; color: var(--gray-700); text-transform: uppercase; letter-spacing: 0.5px;">
        Hospital Operations &amp; Management Menu
    </h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
        <!-- Card 1: Doctors -->
        <div class="card" style="margin-bottom: 0; padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="font-size: 28px; margin-bottom: 8px;">&#129658;</div>
                <h3 style="margin: 0 0 6px 0; font-size: 17px; color: var(--gray-900);">Doctor &amp; Shift Management</h3>
                <p style="margin: 0 0 14px 0; color: var(--gray-500); font-size: 13px; line-height: 1.4;">
                    Configure <?php echo $docCount; ?> medical specialists, consultation rooms, and weekly schedule shifts.
                </p>
            </div>
            <a href="doctors.php" class="btn btn-primary" style="padding: 10px; font-size: 13px;">
                Manage Doctors &amp; Shifts &rarr;
            </a>
        </div>

        <!-- Card 2: Appointments Roster -->
        <div class="card" style="margin-bottom: 0; padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="font-size: 28px; margin-bottom: 8px;">&#128203;</div>
                <h3 style="margin: 0 0 6px 0; font-size: 17px; color: var(--gray-900);">Master Appointment Roster</h3>
                <p style="margin: 0 0 14px 0; color: var(--gray-500); font-size: 13px; line-height: 1.4;">
                    Search, filter, and inspect all patient consultation bookings across all clinical departments.
                </p>
            </div>
            <a href="<?php echo APP_BASE_URL; ?>/staff/appointments.php" class="btn btn-primary" style="padding: 10px; font-size: 13px;">
                Open Hospital Roster &rarr;
            </a>
        </div>

        <!-- Card 3: Clinic Settings -->
        <div class="card" style="margin-bottom: 0; padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="font-size: 28px; margin-bottom: 8px;">&#9881;</div>
                <h3 style="margin: 0 0 6px 0; font-size: 17px; color: var(--gray-900);">Clinic Operating Settings</h3>
                <p style="margin: 0 0 14px 0; color: var(--gray-500); font-size: 13px; line-height: 1.4;">
                    Adjust clinic hours (<?php echo substr($clinic['opening_time'] ?? '08:00', 0, 5); ?> - <?php echo substr($clinic['closing_time'] ?? '17:00', 0, 5); ?>), advance booking windows, and cancellation rules.
                </p>
            </div>
            <a href="settings.php" class="btn btn-secondary" style="padding: 10px; font-size: 13px;">
                Edit Clinic Settings &rarr;
            </a>
        </div>

        <!-- Card 4: User Accounts -->
        <div class="card" style="margin-bottom: 0; padding: 20px; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="font-size: 28px; margin-bottom: 8px;">&#128101;</div>
                <h3 style="margin: 0 0 6px 0; font-size: 17px; color: var(--gray-900);">User &amp; Patient Accounts</h3>
                <p style="margin: 0 0 14px 0; color: var(--gray-500); font-size: 13px; line-height: 1.4;">
                    Review registered patients, staff accounts, account statuses, and Google OAuth links.
                </p>
            </div>
            <a href="users.php" class="btn btn-secondary" style="padding: 10px; font-size: 13px;">
                Manage Users &rarr;
            </a>
        </div>
    </div>
</div>

<!-- Key Performance Metrics Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Registered Patients</div>
        <div class="stat-value" style="color: var(--primary);"><?php echo $userCounts['patient'] ?? 0; ?></div>
        <div class="stat-desc">Patient health accounts created</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Upcoming Appointments</div>
        <div class="stat-value" style="color: var(--success);"><?php echo $upcomingAppointments; ?></div>
        <div class="stat-desc"><?php echo $totalAppointments; ?> total bookings recorded</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active Specialists</div>
        <div class="stat-value"><?php echo $docCount; ?></div>
        <div class="stat-desc"><?php echo $schedCount; ?> weekly shift slots active</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Clinic Hours</div>
        <div class="stat-value" style="font-size: 20px;">
            <?php echo substr($clinic['opening_time'] ?? '08:00', 0, 5); ?> - <?php echo substr($clinic['closing_time'] ?? '17:00', 0, 5); ?>
        </div>
        <div class="stat-desc">Booking limit: <?php echo $clinic['max_advance_booking_days'] ?? 30; ?> days</div>
    </div>
</div>

<!-- Recent Appointments Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title" style="font-size: 17px;">Recent Patient Bookings</h2>
        <a href="<?php echo APP_BASE_URL; ?>/staff/appointments.php" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px;">
            View All in Master Roster &rarr;
        </a>
    </div>

    <?php if (!empty($recentAppointments)): ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Booking Ref</th>
                        <th>Patient</th>
                        <th>Specialist</th>
                        <th>Date &amp; Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentAppointments as $app): ?>
                        <tr>
                            <td><strong><code><?php echo htmlspecialchars($app['booking_reference'], ENT_QUOTES, 'UTF-8'); ?></code></strong></td>
                            <td>
                                <strong><?php echo htmlspecialchars($app['patient_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div style="font-size: 11px; color: var(--gray-500);"><?php echo htmlspecialchars($app['patient_email'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($app['doctor_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div style="font-size: 11px; color: var(--gray-500);"><?php echo htmlspecialchars($app['specialty'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td>
                                <div><?php echo date('M d, Y', strtotime($app['appointment_date'])); ?></div>
                                <div style="font-size: 12px; color: var(--gray-700);"><?php echo date('h:i A', strtotime($app['start_time'])); ?></div>
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
                                    <span class="badge"><?php echo ucfirst($app['status']); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="text-align: center; color: var(--gray-500); padding: 20px; margin: 0;">No appointments recorded yet.</p>
    <?php endif; ?>
</div>

<!-- Real-Time Security & System Audit Trail -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title" style="font-size: 17px;">Real-Time System Audit Trail</h2>
        <span class="badge" style="background: var(--gray-100); color: var(--gray-700);">Latest Security Events</span>
    </div>
    
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Action</th>
                    <th>Actor</th>
                    <th>Description</th>
                    <th>Client IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($recentLogs)): ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td style="font-size: 13px; color: var(--gray-500); white-space: nowrap;">
                                <?php echo htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <code><?php echo htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8'); ?></code>
                            </td>
                            <td>
                                <?php if (!empty($log['actor_email'])): ?>
                                    <strong><?php echo htmlspecialchars($log['actor_email'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span class="role-tag role-tag-<?php echo htmlspecialchars($log['actor_role'] ?? 'patient', ENT_QUOTES, 'UTF-8'); ?>" style="font-size: 10px;">
                                        <?php echo htmlspecialchars(strtoupper($log['actor_role'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <em style="color: var(--gray-500);">System / Guest</em>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 13px;">
                                <?php echo htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td>
                                <code style="font-size: 12px;"><?php echo htmlspecialchars($log['ip_address'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--gray-500); padding: 24px;">
                            No audit log records recorded yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
