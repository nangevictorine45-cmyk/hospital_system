<?php
/**
 * =============================================================================
 * CityCare Hospital - Clinic Operational Settings Management
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $errorMessage = 'Security validation failed (Invalid CSRF token).';
    } else {
        $clinicName   = trim((string)($_POST['clinic_name'] ?? 'CityCare Hospital'));
        $address      = trim((string)($_POST['address'] ?? ''));
        $phone        = trim((string)($_POST['phone'] ?? ''));
        $email        = trim((string)($_POST['email'] ?? ''));
        $timezone     = trim((string)($_POST['timezone'] ?? 'UTC'));
        $openingTime  = trim((string)($_POST['opening_time'] ?? '08:00'));
        $closingTime  = trim((string)($_POST['closing_time'] ?? '17:00'));
        $maxDays      = max(1, (int)($_POST['max_advance_booking_days'] ?? 30));
        $minCancelHrs = max(0, (int)($_POST['min_cancellation_hours_notice'] ?? 2));

        if (strlen($openingTime) === 5) $openingTime .= ':00';
        if (strlen($closingTime) === 5) $closingTime .= ':00';

        try {
            $upStmt = $pdo->prepare("
                UPDATE clinic_settings SET
                    clinic_name = :name,
                    address = :address,
                    phone = :phone,
                    email = :email,
                    timezone = :timezone,
                    opening_time = :open_time,
                    closing_time = :close_time,
                    max_advance_booking_days = :max_days,
                    min_cancellation_hours_notice = :min_cancel,
                    updated_at = NOW()
                WHERE id = 1
            ");

            $upStmt->execute([
                ':name'       => $clinicName,
                ':address'    => $address,
                ':phone'      => $phone,
                ':email'      => $email,
                ':timezone'   => $timezone,
                ':open_time'  => $openingTime,
                ':close_time' => $closingTime,
                ':max_days'   => $maxDays,
                ':min_cancel' => $minCancelHrs
            ]);

            logAuditEvent(
                (int)$user['id'],
                'SETTINGS_UPDATE',
                'clinic_settings',
                1,
                "Admin updated clinic operational settings"
            );

            setFlash('success', 'Clinic operational settings have been updated successfully.');
            header('Location: settings.php');
            exit;
        } catch (Exception $e) {
            $errorMessage = 'Failed to update settings: ' . $e->getMessage();
        }
    }
}

$clinic = $pdo->query("SELECT * FROM clinic_settings LIMIT 1")->fetch();

$pageTitle = 'Clinic Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">Clinic Operational Settings</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Configure hospital operating hours, advance booking limits, and cancellation policies.
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

<div class="card" style="max-width: 760px;">
    <form method="POST" action="settings.php">
        <?php echo csrfField(); ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label class="form-label">Hospital / Clinic Name *</label>
                <input type="text" name="clinic_name" class="form-control" value="<?php echo htmlspecialchars($clinic['clinic_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Contact Phone *</label>
                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($clinic['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label class="form-label">Official Email *</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($clinic['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Timezone</label>
                <input type="text" name="timezone" class="form-control" value="<?php echo htmlspecialchars($clinic['timezone'] ?? 'UTC', ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Physical Street Address *</label>
            <textarea name="address" rows="2" class="form-control" required><?php echo htmlspecialchars($clinic['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label class="form-label">Clinic Opening Time (24h)</label>
                <input type="time" name="opening_time" class="form-control" value="<?php echo substr($clinic['opening_time'] ?? '08:00', 0, 5); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Clinic Closing Time (24h)</label>
                <input type="time" name="closing_time" class="form-control" value="<?php echo substr($clinic['closing_time'] ?? '17:00', 0, 5); ?>" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label class="form-label">Maximum Advance Booking Days</label>
                <input type="number" name="max_advance_booking_days" class="form-control" min="1" max="180" value="<?php echo (int)($clinic['max_advance_booking_days'] ?? 30); ?>" required>
                <div style="font-size: 11px; color: var(--gray-500); margin-top: 4px;">Controls how many days into the future patients can schedule appointments.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Minimum Cancellation Notice (Hours)</label>
                <input type="number" name="min_cancellation_hours_notice" class="form-control" min="0" max="72" value="<?php echo (int)($clinic['min_cancellation_hours_notice'] ?? 2); ?>" required>
                <div style="font-size: 11px; color: var(--gray-500); margin-top: 4px;">Hours before consultation required for patient self-cancellation.</div>
            </div>
        </div>

        <div style="margin-top: 20px;">
            <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">
                &#10004; Save Settings
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

