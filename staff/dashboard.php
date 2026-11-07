<?php
/**
 * =============================================================================
 * CityCare Hospital - Staff Clinical Operations Dashboard
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Role Guard: Staff & Admin only
requireLogin(['staff', 'admin']);

$user = currentUser();
$pdo = getDBConnection();

$todayDate = date('Y-m-d');
$todayDay = date('l'); // 'Monday', 'Tuesday', etc.

// 1. Doctors scheduled today
$docStmt = $pdo->prepare("
    SELECT DISTINCT d.id, d.full_name, d.specialty, d.room_number, s.start_time, s.end_time
    FROM doctors d
    INNER JOIN doctor_schedules s ON d.id = s.doctor_id
    WHERE s.day_of_week = :day_name
      AND s.active_status = 1
      AND d.active_status = 1
    ORDER BY s.start_time ASC
");
$docStmt->execute([':day_name' => $todayDay]);
$scheduledDoctors = $docStmt->fetchAll();

// 2. Count today's appointments
$appCountStmt = $pdo->prepare("
    SELECT COUNT(*) AS total 
    FROM appointments 
    WHERE appointment_date = :today
");
$appCountStmt->execute([':today' => $todayDate]);
$todayAppCount = (int)$appCountStmt->fetch()['total'];

// 3. Count today's active queue
$queueCountStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_queue,
        SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) AS waiting_count,
        SUM(CASE WHEN status = 'in_service' THEN 1 ELSE 0 END) AS in_service_count
    FROM queue_entries 
    WHERE queue_date = :today
");
$queueCountStmt->execute([':today' => $todayDate]);
$queueStats = $queueCountStmt->fetch();

$waitingCount = (int)($queueStats['waiting_count'] ?? 0);
$inServiceCount = (int)($queueStats['in_service_count'] ?? 0);

$pageTitle = 'Staff Clinical Operations';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">Staff Clinical Portal</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Operator: <strong><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong> &bull; 
            Today is <strong><?php echo $todayDay; ?>, <?php echo date('M d, Y'); ?></strong>
        </p>
    </div>
    <span class="role-tag role-tag-staff">Clinical Receptionist</span>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Waiting in Queue</div>
        <div class="stat-value" style="color: var(--warning);"><?php echo $waitingCount; ?></div>
        <div class="stat-desc">Patients awaiting consultation</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Currently in Service</div>
        <div class="stat-value" style="color: var(--primary);"><?php echo $inServiceCount; ?></div>
        <div class="stat-desc">Active in doctor consultation rooms</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Today's Bookings</div>
        <div class="stat-value"><?php echo $todayAppCount; ?></div>
        <div class="stat-desc">Total scheduled appointments</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Doctors on Duty</div>
        <div class="stat-value"><?php echo count($scheduledDoctors); ?></div>
        <div class="stat-desc">Active shifts scheduled today</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
    <!-- Doctors on Duty Today -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title" style="font-size: 17px;">Doctors on Duty (<?php echo $todayDay; ?>)</h2>
        </div>
        <?php if (!empty($scheduledDoctors)): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Doctor</th>
                            <th>Specialty</th>
                            <th>Room</th>
                            <th>Shift Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduledDoctors as $doc): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($doc['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td><?php echo htmlspecialchars($doc['specialty'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="badge" style="background: var(--gray-100);"><?php echo htmlspecialchars($doc['room_number'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo substr($doc['start_time'], 0, 5); ?> - <?php echo substr($doc['end_time'], 0, 5); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color: var(--gray-500); font-size: 14px; margin: 0;">
                No doctor shifts scheduled for today (<?php echo $todayDay; ?>).
            </p>
        <?php endif; ?>
    </div>

    <!-- Front Desk Quick Workflow Actions -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title" style="font-size: 17px;">Front Desk Operations</h2>
        </div>
        <p style="color: var(--gray-500); font-size: 14px; margin-top: 0;">
            Manage patient arrivals, issue physical queue tickets, and advance queue calling.
        </p>

        <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px;">
            <a href="#" class="btn btn-primary" style="justify-content: flex-start; padding: 12px 16px;">
                <span style="font-size: 18px;">&#10004;</span>
                <div>
                    <strong>Check In Arrived Patient</strong>
                    <div style="font-size: 12px; opacity: 0.9; font-weight: normal;">Lookup booking reference and print/issue queue ticket</div>
                </div>
            </a>

            <a href="#" class="btn btn-secondary" style="justify-content: flex-start; padding: 12px 16px;">
                <span style="font-size: 18px;">&#128227;</span>
                <div>
                    <strong>Queue Calling Desk</strong>
                    <div style="font-size: 12px; color: var(--gray-500); font-weight: normal;">Call next patient, mark in-service, or skip no-show</div>
                </div>
            </a>

            <a href="appointments.php" class="btn btn-secondary" style="justify-content: flex-start; padding: 12px 16px;">
                <span style="font-size: 18px;">&#128203;</span>
                <div>
                    <strong>Master Appointment Roster</strong>
                    <div style="font-size: 12px; color: var(--gray-500); font-weight: normal;">View, verify, or reschedule clinic appointments</div>
                </div>
            </a>

            <a href="missed_appointments.php" class="btn btn-secondary" style="justify-content: flex-start; padding: 12px 16px;">
                <span style="font-size: 18px;">&#9888;</span>
                <div>
                    <strong>Missed Appointment Recovery Desk</strong>
                    <div style="font-size: 12px; color: var(--gray-500); font-weight: normal;">Detect no-shows and dispatch automated rescheduling options</div>
                </div>
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

