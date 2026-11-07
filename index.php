<?php
/**
 * CityCare Hospital - Main Entry & Public Portal
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/booking.php';

$clinic = getClinicSettings();
$doctors = getDoctors(true);
$user = currentUser();

$pageTitle = 'Home - Outpatient Care & Scheduling';
require_once __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom: 28px;">
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px;">
        <span class="badge" style="background: var(--primary-light); color: var(--primary-dark);">&#10010; Outpatient Care Center</span>
        <span class="badge" style="background: var(--success-light); color: var(--success);">&#128197; Online Booking Available</span>
        <span class="badge" style="background: #ede9fe; color: #6d28d9;">&#128241; Smart Queue Monitoring</span>
    </div>
    <h1 style="font-size: 32px; margin: 0 0 10px 0; color: var(--gray-900);">
        <?php echo htmlspecialchars($clinic['clinic_name'] ?? APP_NAME, ENT_QUOTES, 'UTF-8'); ?>
    </h1>
    <p style="font-size: 16px; color: var(--gray-500); max-width: 720px; margin: 0; line-height: 1.6;">
        Providing accessible, reliable healthcare for our community. Schedule appointments online with certified medical specialists and monitor your queue ticket live from your phone.
    </p>
</div>

<?php if ($user): ?>
    <div class="card" style="background: linear-gradient(to right, #ffffff, #f0fdf4); border-color: #bbf7d0;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <span class="badge" style="background: #bbf7d0; color: #166534; margin-bottom: 8px;">Active Session</span>
                <h2 style="margin: 0 0 6px 0; font-size: 20px;">Welcome back, <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>!</h2>
                <p style="margin: 0; color: var(--gray-500); font-size: 14px;">
                    Signed in as <strong><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></strong> 
                    (Portal: <strong style="text-transform: capitalize;"><?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?></strong>)
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php if ($user['role'] === 'patient'): ?>
                    <a href="<?php echo APP_BASE_URL; ?>/patient/book.php" class="btn btn-secondary">
                        &#10010; Book Consultation
                    </a>
                <?php endif; ?>
                <a href="<?php echo getDashboardUrl(); ?>" class="btn btn-primary" style="padding: 12px 24px; font-size: 15px;">
                    Enter <?php echo ucfirst($user['role']); ?> Portal &rarr;
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card" style="background: linear-gradient(to right, #ffffff, var(--primary-light)); border-color: #bae6fd;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <h2 style="margin: 0 0 6px 0; font-size: 22px; color: var(--gray-900);">Fast Online Doctor Appointments</h2>
                <p style="margin: 0; color: var(--gray-700); font-size: 14px; max-width: 580px;">
                    Reserve your 20-minute consultation slot in advance. Check in online or at the reception kiosk to track your queue position in real time.
                </p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="doctors.php" class="btn btn-primary" style="padding: 12px 20px;">Browse Specialists &rarr;</a>
                <a href="login.php" class="btn btn-secondary" style="padding: 12px 20px;">Sign In</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Quick Clinic Operations Counters -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Operating Hours</div>
        <div class="stat-value" style="font-size: 20px;">
            <?php echo substr($clinic['opening_time'] ?? '08:00', 0, 5); ?> - <?php echo substr($clinic['closing_time'] ?? '17:00', 0, 5); ?>
        </div>
        <div class="stat-desc">Monday through Friday (Outpatient)</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active Specialists</div>
        <div class="stat-value" style="font-size: 22px; color: var(--primary);">
            <?php echo count($doctors); ?> Doctors
        </div>
        <div class="stat-desc">General Medicine, Pediatrics, Cardiology</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Advance Booking</div>
        <div class="stat-value" style="font-size: 22px; color: var(--success);">
            <?php echo $clinic['max_advance_booking_days'] ?? 30; ?> Days
        </div>
        <div class="stat-desc">Normalized 20-minute slots</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Clinic Contact</div>
        <div class="stat-value" style="font-size: 18px; color: var(--gray-700);">
            <?php echo htmlspecialchars($clinic['phone'] ?? '(555) 019-2834'); ?>
        </div>
        <div class="stat-desc"><?php echo htmlspecialchars($clinic['email'] ?? 'info@citycare.local'); ?></div>
    </div>
</div>

<!-- Featured Medical Specialists -->
<div style="margin-top: 12px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <div>
            <h2 style="margin: 0; font-size: 20px; color: var(--gray-900);">Medical Faculty &amp; Specialties</h2>
            <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 13px;">Select a specialist to view their weekly shifts or schedule an appointment.</p>
        </div>
        <a href="doctors.php" class="btn btn-secondary" style="font-size: 13px;">View All Doctors &rarr;</a>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
        <?php foreach ($doctors as $doc): ?>
            <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                        <span class="badge" style="background: var(--primary-light); color: var(--primary-dark); font-size: 11px;">
                            <?php echo htmlspecialchars($doc['specialty'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span class="badge" style="background: var(--gray-100); color: var(--gray-700); font-size: 11px;">
                            <?php echo htmlspecialchars($doc['room_number'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <h3 style="margin: 0 0 8px 0; font-size: 18px; color: var(--gray-900);">
                        <?php echo htmlspecialchars($doc['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </h3>

                    <p style="color: var(--gray-600); font-size: 13px; line-height: 1.5; margin-bottom: 16px;">
                        <?php echo htmlspecialchars($doc['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>

                <a href="<?php echo APP_BASE_URL; ?>/patient/book.php?doctor_id=<?php echo $doc['id']; ?>" class="btn btn-primary" style="padding: 10px; font-size: 13px;">
                    Schedule Consultation &rarr;
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
