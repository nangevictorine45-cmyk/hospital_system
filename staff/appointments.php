<?php
/**
 * =============================================================================
 * CityCare Hospital - Clinical Staff Master Appointment Roster
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';

// Role Guard: Staff & Admin only
requireLogin(['staff', 'admin']);

$user = currentUser();
$pdo = getDBConnection();

$filterDate   = isset($_GET['date']) ? trim((string)$_GET['date']) : date('Y-m-d');
$filterDoctor = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
$filterStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$search       = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$doctors = getDoctors(false);

$sql = "
    SELECT 
        a.id,
        a.booking_reference,
        a.appointment_date,
        a.start_time,
        a.end_time,
        a.status,
        a.created_at,
        u.id AS patient_id,
        u.full_name AS patient_name,
        u.email AS patient_email,
        u.phone AS patient_phone,
        d.full_name AS doctor_name,
        d.specialty AS doctor_specialty,
        d.room_number,
        q.queue_number,
        q.status AS queue_status
    FROM appointments a
    JOIN users u ON a.patient_id = u.id
    JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN queue_entries q ON a.id = q.appointment_id
    WHERE 1=1
";
$params = [];

if ($filterDate !== '') {
    $sql .= " AND a.appointment_date = :app_date";
    $params[':app_date'] = $filterDate;
}

if ($filterDoctor > 0) {
    $sql .= " AND a.doctor_id = :doc_id";
    $params[':doc_id'] = $filterDoctor;
}

if ($filterStatus !== '') {
    $sql .= " AND a.status = :status";
    $params[':status'] = $filterStatus;
}

if ($search !== '') {
    $sql .= " AND (a.booking_reference LIKE :search OR u.full_name LIKE :search OR u.phone LIKE :search)";
    $params[':search'] = "%{$search}%";
}

$sql .= " ORDER BY a.appointment_date ASC, a.start_time ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll();

$pageTitle = 'Master Appointment Roster';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">Master Appointment Roster</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Clinical front-desk consultation registry and patient check-in lookup.
        </p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="<?php echo APP_BASE_URL; ?>/staff/dashboard.php" class="btn btn-secondary" style="font-size: 13px;">
            &larr; Return to Staff Desk
        </a>
    </div>
</div>

<!-- Filter Control Bar -->
<div class="card" style="padding: 18px; margin-bottom: 20px;">
    <form method="GET" action="appointments.php" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 140px;">
            <label class="form-label">Consultation Date</label>
            <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div style="flex: 1; min-width: 180px;">
            <label class="form-label">Doctor</label>
            <select name="doctor_id" class="form-control">
                <option value="0">All Specialists</option>
                <?php foreach ($doctors as $doc): ?>
                    <option value="<?php echo $doc['id']; ?>" <?php echo ($filterDoctor === (int)$doc['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($doc['full_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($doc['specialty'], ENT_QUOTES, 'UTF-8'); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex: 1; min-width: 140px;">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="confirmed" <?php echo ($filterStatus === 'confirmed') ? 'selected' : ''; ?>>Confirmed</option>
                <option value="checked_in" <?php echo ($filterStatus === 'checked_in') ? 'selected' : ''; ?>>Checked In</option>
                <option value="completed" <?php echo ($filterStatus === 'completed') ? 'selected' : ''; ?>>Completed</option>
                <option value="cancelled" <?php echo ($filterStatus === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>

        <div style="flex: 1.5; min-width: 180px;">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" placeholder="Booking Ref or Patient Name" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="padding: 10px 18px;">
                &#128269; Filter Roster
            </button>
            <a href="appointments.php" class="btn btn-secondary" style="padding: 10px 14px;">Reset</a>
        </div>
    </form>
</div>

<!-- Appointments Table -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h2 style="font-size: 16px; margin: 0; color: var(--gray-800);">
            Matching Bookings (<?php echo count($appointments); ?> records)
        </h2>
        <span style="font-size: 13px; color: var(--gray-500);">
            Date: <strong><?php echo !empty($filterDate) ? date('M d, Y', strtotime($filterDate)) : 'All Dates'; ?></strong>
        </span>
    </div>

    <?php if (!empty($appointments)): ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Booking Ref</th>
                        <th>Time</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Room</th>
                        <th>Status</th>
                        <th>Queue Ticket</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $a): ?>
                        <tr>
                            <td>
                                <strong><code><?php echo htmlspecialchars($a['booking_reference'], ENT_QUOTES, 'UTF-8'); ?></code></strong>
                            </td>
                            <td>
                                <strong><?php echo date('h:i A', strtotime($a['start_time'])); ?></strong>
                                <div style="font-size: 11px; color: var(--gray-500);"><?php echo $a['appointment_date']; ?></div>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($a['patient_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div style="font-size: 12px; color: var(--gray-500);"><?php echo htmlspecialchars($a['patient_phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($a['doctor_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div style="font-size: 11px; color: var(--gray-500);"><?php echo htmlspecialchars($a['doctor_specialty'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </td>
                            <td>
                                <span class="badge" style="background: var(--gray-100);"><?php echo htmlspecialchars($a['room_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </td>
                            <td>
                                <?php if ($a['status'] === 'confirmed'): ?>
                                    <span class="badge" style="background: var(--success-light); color: var(--success);">Confirmed</span>
                                <?php elseif ($a['status'] === 'checked_in'): ?>
                                    <span class="badge" style="background: var(--primary-light); color: var(--primary);">Checked In</span>
                                <?php elseif ($a['status'] === 'completed'): ?>
                                    <span class="badge" style="background: var(--gray-100); color: var(--gray-700);">Completed</span>
                                <?php elseif ($a['status'] === 'cancelled'): ?>
                                    <span class="badge" style="background: var(--danger-light); color: var(--danger);">Cancelled</span>
                                <?php else: ?>
                                    <span class="badge"><?php echo ucfirst($a['status']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($a['queue_number'])): ?>
                                    <span class="badge" style="background: var(--primary); color: white; font-weight: 700;">
                                        #<?php echo htmlspecialchars($a['queue_number'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: var(--gray-400);">Not issued</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="text-align: center; color: var(--gray-500); padding: 30px 0; margin: 0;">
            No appointments found matching the current search criteria.
        </p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

