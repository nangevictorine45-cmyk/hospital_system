<?php
/**
 * =============================================================================
 * CityCare Hospital - Doctor & Specialty Management
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';

// Role Guard: Administrator only
requireLogin(['admin']);

$user = currentUser();
$pdo = getDBConnection();
$errorMessage = '';

// Handle Status Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $errorMessage = 'Invalid CSRF token.';
    } else {
        $doctorId = (int)($_POST['doctor_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 1);

        $upStmt = $pdo->prepare("UPDATE doctors SET active_status = :status, updated_at = NOW() WHERE id = :id");
        $upStmt->execute([':status' => $newStatus, ':id' => $doctorId]);

        logAuditEvent((int)$user['id'], 'DOCTOR_UPDATE', 'doctors', $doctorId, "Doctor ID #{$doctorId} active status changed to {$newStatus}");
        setFlash('success', 'Doctor status updated successfully.');
        header('Location: doctors.php');
        exit;
    }
}

// Handle Add Doctor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_doctor') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $errorMessage = 'Invalid CSRF token.';
    } else {
        $fullName    = trim((string)($_POST['full_name'] ?? ''));
        $specialty   = trim((string)($_POST['specialty'] ?? ''));
        $email       = trim((string)($_POST['email'] ?? ''));
        $phone       = trim((string)($_POST['phone'] ?? ''));
        $roomNumber  = trim((string)($_POST['room_number'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        if (empty($fullName) || empty($specialty) || empty($email) || empty($roomNumber)) {
            $errorMessage = 'Please complete all required fields for the new specialist.';
        } else {
            try {
                $insStmt = $pdo->prepare("
                    INSERT INTO doctors (full_name, specialty, email, phone, room_number, description, active_status, created_at)
                    VALUES (:name, :specialty, :email, :phone, :room, :desc, 1, NOW())
                ");
                $insStmt->execute([
                    ':name'      => $fullName,
                    ':specialty' => $specialty,
                    ':email'     => $email,
                    ':phone'     => $phone,
                    ':room'      => $roomNumber,
                    ':desc'      => $description
                ]);
                $newDocId = (int)$pdo->lastInsertId();

                logAuditEvent((int)$user['id'], 'DOCTOR_CREATE', 'doctors', $newDocId, "Added new doctor: {$fullName} ({$specialty})");
                setFlash('success', "Doctor {$fullName} added successfully.");
                header('Location: doctors.php');
                exit;
            } catch (Exception $e) {
                $errorMessage = 'Error adding doctor: ' . $e->getMessage();
            }
        }
    }
}

$allDoctors = getDoctors(false);

$pageTitle = 'Manage Doctors';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">Doctor &amp; Specialty Faculty Management</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Manage medical faculty, consultation rooms, and availability status.
        </p>
    </div>
    <a href="dashboard.php" class="btn btn-secondary" style="font-size: 13px;">
        &larr; Return to Admin Dashboard
    </a>
</div>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger">
        <span>&#10008;</span>
        <div><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
<?php endif; ?>

<!-- Doctors Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title" style="font-size: 17px;">Medical Specialists Directory (<?php echo count($allDoctors); ?>)</h2>
    </div>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Doctor Name</th>
                    <th>Specialty</th>
                    <th>Room</th>
                    <th>Contact</th>
                    <th>Weekly Shifts</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allDoctors as $doc): ?>
                    <?php $shifts = getDoctorWeeklySchedules((int)$doc['id']); ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($doc['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        </td>
                        <td>
                            <span class="badge" style="background: var(--primary-light); color: var(--primary-dark);">
                                <?php echo htmlspecialchars($doc['specialty'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </td>
                        <td>
                            <code><?php echo htmlspecialchars($doc['room_number'], ENT_QUOTES, 'UTF-8'); ?></code>
                        </td>
                        <td style="font-size: 12px; color: var(--gray-600);">
                            <?php echo htmlspecialchars($doc['email'], ENT_QUOTES, 'UTF-8'); ?><br>
                            <?php echo htmlspecialchars($doc['phone'], ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td style="font-size: 12px;">
                            <?php echo count($shifts); ?> shifts scheduled
                        </td>
                        <td>
                            <?php if ((int)$doc['active_status'] === 1): ?>
                                <span class="badge" style="background: var(--success-light); color: var(--success);">Active</span>
                            <?php else: ?>
                                <span class="badge" style="background: var(--danger-light); color: var(--danger);">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" action="doctors.php" style="display: inline;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="doctor_id" value="<?php echo $doc['id']; ?>">
                                <input type="hidden" name="new_status" value="<?php echo (int)$doc['active_status'] === 1 ? '0' : '1'; ?>">
                                <button type="submit" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;">
                                    <?php echo (int)$doc['active_status'] === 1 ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add New Specialist Modal / Card -->
<div class="card" style="max-width: 760px;">
    <div class="card-header">
        <h2 class="card-title" style="font-size: 17px;">Add New Medical Specialist</h2>
    </div>
    <form method="POST" action="doctors.php">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_doctor">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" name="full_name" class="form-control" placeholder="e.g. Dr. Arthur Wright" required>
            </div>
            <div class="form-group">
                <label class="form-label">Specialty *</label>
                <input type="text" name="specialty" class="form-control" placeholder="e.g. Dermatology / Neurology" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label class="form-label">Email Address *</label>
                <input type="email" name="email" class="form-control" placeholder="e.g. a.wright@citycare.local" required>
            </div>
            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <input type="text" name="phone" class="form-control" placeholder="e.g. +1 (555) 301-0004">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Consultation Room Number *</label>
            <input type="text" name="room_number" class="form-control" placeholder="e.g. Room 301" required>
        </div>

        <div class="form-group">
            <label class="form-label">Professional Bio / Description</label>
            <textarea name="description" rows="2" class="form-control" placeholder="Board-certified specialist with expertise in..."></textarea>
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">
            &#10010; Add Doctor to Directory
        </button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

