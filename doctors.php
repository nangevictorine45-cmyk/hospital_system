<?php
/**
 * =============================================================================
 * CityCare Hospital - Doctor Directory & Medical Specialties
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/booking.php';

$specialtyFilter = isset($_GET['specialty']) ? trim((string)$_GET['specialty']) : null;
$doctors = getDoctors(true, $specialtyFilter);

// Get distinct specialties for filter tabs
$pdo = getDBConnection();
$specStmt = $pdo->query("SELECT DISTINCT specialty FROM doctors WHERE active_status = 1 ORDER BY specialty ASC");
$allSpecialties = $specStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Medical Specialists & Doctors';
require_once __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom: 24px;">
    <h1 style="font-size: 28px; margin: 0 0 8px 0; color: var(--gray-900);">Clinical Specialists &amp; Medical Faculty</h1>
    <p style="color: var(--gray-500); font-size: 15px; margin: 0; max-width: 680px;">
        Browse our certified doctors across primary and specialized healthcare fields. Select a specialist to view consultation hours and book an appointment.
    </p>
</div>

<!-- Specialty Filter Tabs -->
<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px;">
    <a href="doctors.php" class="btn <?php echo empty($specialtyFilter) ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 8px 16px; font-size: 13px;">
        All Specialties
    </a>
    <?php foreach ($allSpecialties as $spec): ?>
        <a href="doctors.php?specialty=<?php echo urlencode($spec); ?>" 
           class="btn <?php echo ($specialtyFilter === $spec) ? 'btn-primary' : 'btn-secondary'; ?>" 
           style="padding: 8px 16px; font-size: 13px;">
            <?php echo htmlspecialchars($spec, ENT_QUOTES, 'UTF-8'); ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Doctors Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
    <?php if (!empty($doctors)): ?>
        <?php foreach ($doctors as $doc): ?>
            <?php $schedules = getDoctorWeeklySchedules((int)$doc['id']); ?>
            <div class="card" style="display: flex; flex-direction: column; justify-content: space-between; margin-bottom: 0;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 12px;">
                        <div>
                            <span class="badge" style="background: var(--primary-light); color: var(--primary-dark); margin-bottom: 6px;">
                                <?php echo htmlspecialchars($doc['specialty'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <h2 style="margin: 0; font-size: 20px; color: var(--gray-900);">
                                <?php echo htmlspecialchars($doc['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </h2>
                        </div>
                        <span class="badge" style="background: var(--gray-100); color: var(--gray-700); font-size: 12px;">
                            <?php echo htmlspecialchars($doc['room_number'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <p style="color: var(--gray-700); font-size: 14px; margin-bottom: 16px; line-height: 1.5;">
                        <?php echo htmlspecialchars($doc['description'] ?? 'Dedicated healthcare professional providing personalized outpatient care.', ENT_QUOTES, 'UTF-8'); ?>
                    </p>

                    <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-sm); padding: 12px; margin-bottom: 18px;">
                        <div style="font-size: 12px; font-weight: 700; color: var(--gray-500); text-transform: uppercase; margin-bottom: 6px;">
                            Weekly Consultation Shifts:
                        </div>
                        <?php if (!empty($schedules)): ?>
                            <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: var(--gray-700);">
                                <?php foreach ($schedules as $sch): ?>
                                    <li>
                                        <strong><?php echo $sch['day_of_week']; ?>:</strong> 
                                        <?php echo substr($sch['start_time'], 0, 5); ?> - <?php echo substr($sch['end_time'], 0, 5); ?>
                                        <span style="color: var(--gray-500); font-size: 11px;">(<?php echo $sch['slot_duration_minutes']; ?>m slots)</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div style="font-size: 13px; color: var(--gray-500);">No regular weekly shifts listed.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <a href="<?php echo APP_BASE_URL; ?>/patient/book.php?doctor_id=<?php echo $doc['id']; ?>" class="btn btn-primary btn-block" style="padding: 12px; font-size: 14px;">
                        Book Consultation with <?php echo htmlspecialchars($doc['full_name'], ENT_QUOTES, 'UTF-8'); ?> &rarr;
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
            <p style="color: var(--gray-500); margin-bottom: 16px;">No doctors found matching the selected specialty.</p>
            <a href="doctors.php" class="btn btn-secondary">View All Doctors</a>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

