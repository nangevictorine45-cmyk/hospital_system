<?php
/**
 * =============================================================================
 * CityCare Hospital - Patient Consultation Booking Page
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
$settings = getClinicSettings();
$doctors = getDoctors(true);

$selectedDoctorId = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
if ($selectedDoctorId === 0 && !empty($doctors)) {
    $selectedDoctorId = (int)$doctors[0]['id'];
}

$today = date('Y-m-d');
$maxDays = (int)$settings['max_advance_booking_days'];
$maxDate = date('Y-m-d', strtotime("+{$maxDays} days"));

$selectedDate = isset($_GET['date']) ? trim((string)$_GET['date']) : $today;
// Default to tomorrow if today is past clinic closing time or weekend
if (date('H:i') >= substr($settings['closing_time'], 0, 5)) {
    $selectedDate = date('Y-m-d', strtotime('+1 day'));
}

$slotsResult = getDoctorAvailableSlots($selectedDoctorId, $selectedDate);
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!verifyCsrfToken($submittedToken)) {
        $errorMessage = 'Security validation failed (Invalid CSRF token). Please try again.';
    } else {
        $docId     = (int)($_POST['doctor_id'] ?? 0);
        $appDate   = trim((string)($_POST['appointment_date'] ?? ''));
        $startTime = trim((string)($_POST['start_time'] ?? ''));

        $res = createAppointment((int)$user['id'], $docId, $appDate, $startTime);

        if ($res['success']) {
            setFlash('success', "Appointment scheduled! Your booking reference is {$res['booking_reference']}.");
            header('Location: ' . APP_BASE_URL . '/patient/appointments.php?new_ref=' . urlencode($res['booking_reference']));
            exit;
        } else {
            $errorMessage = $res['message'];
            $selectedDoctorId = $docId;
            $selectedDate = $appDate;
            $slotsResult = getDoctorAvailableSlots($selectedDoctorId, $selectedDate);
        }
    }
}

$pageTitle = 'Book Appointment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">Book a Doctor Consultation</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Select a medical specialist, choose an available day, and reserve a 20-minute consultation slot.
        </p>
    </div>
    <a href="<?php echo APP_BASE_URL; ?>/doctors.php" class="btn btn-secondary" style="font-size: 13px;">
        &larr; View Doctor Qualifications
    </a>
</div>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger">
        <span>&#10008;</span>
        <div><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
    <!-- Step 1 & 2: Selection Form -->
    <div class="card">
        <h2 style="font-size: 17px; margin: 0 0 16px 0; color: var(--primary-dark);">
            1. Consultation Details
        </h2>

        <form method="POST" action="book.php" id="bookingForm">
            <?php echo csrfField(); ?>

            <div class="form-group">
                <label for="doctor_id" class="form-label">Select Specialist Doctor *</label>
                <select id="doctor_id" name="doctor_id" class="form-control" onchange="refreshSlots()" required>
                    <?php foreach ($doctors as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo ($selectedDoctorId === (int)$d['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($d['full_name'], ENT_QUOTES, 'UTF-8'); ?> &mdash; <?php echo htmlspecialchars($d['specialty'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($d['room_number'], ENT_QUOTES, 'UTF-8'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="appointment_date" class="form-label">Consultation Date *</label>
                <input 
                    type="date" 
                    id="appointment_date" 
                    name="appointment_date" 
                    class="form-control" 
                    min="<?php echo $today; ?>" 
                    max="<?php echo $maxDate; ?>" 
                    value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
                    onchange="refreshSlots()"
                    required
                >
                <div style="font-size: 12px; color: var(--gray-500); margin-top: 4px;">
                    Appointments may be booked up to <?php echo $maxDays; ?> days in advance.
                </div>
            </div>

            <div class="form-group" style="margin-top: 24px;">
                <label class="form-label">Select Available 20-Minute Time Slot *</label>
                <div id="slotsContainer">
                    <?php if (!empty($slotsResult['slots'])): ?>
                        <?php 
                        $hasAvailable = false; 
                        ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(105px, 1fr)); gap: 10px; margin-top: 8px;">
                            <?php foreach ($slotsResult['slots'] as $slot): ?>
                                <?php if ($slot['is_available']): $hasAvailable = true; ?>
                                    <label style="display: block; cursor: pointer;">
                                        <input type="radio" name="start_time" value="<?php echo $slot['start_time']; ?>" style="display: none;" onchange="updateSelectedSlot(this)" required>
                                        <div class="slot-pill slot-available">
                                            <?php echo $slot['display_time']; ?>
                                        </div>
                                    </label>
                                <?php else: ?>
                                    <div class="slot-pill slot-unavailable" title="<?php echo $slot['is_booked'] ? 'Already reserved' : 'Slot passed'; ?>">
                                        <?php echo $slot['display_time']; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!$hasAvailable): ?>
                            <div class="alert alert-warning" style="margin-top: 12px;">
                                All consultation slots for this date are fully reserved or have passed. Please select another date.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info" style="margin-top: 10px;">
                            <?php echo htmlspecialchars($slotsResult['message'] ?? 'No shifts found on this date.'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top: 28px;">
                <button type="submit" id="submitBtn" class="btn btn-primary btn-block" style="padding: 14px; font-size: 15px;">
                    &#128197; Confirm &amp; Schedule Appointment &rarr;
                </button>
            </div>
        </form>
    </div>

    <!-- Booking Summary & Guidelines -->
    <div>
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header">
                <h2 class="card-title" style="font-size: 16px;">Appointment Guidelines</h2>
            </div>
            <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--gray-700); line-height: 1.6;">
                <li>Please arrive at the clinic <strong>10 minutes prior</strong> to your scheduled time.</li>
                <li>Check in at the front desk or mobile kiosk using your <strong>Booking Reference</strong> to receive your queue ticket.</li>
                <li>Cancellations are accepted up to <strong><?php echo $settings['min_cancellation_hours_notice']; ?> hours</strong> before your appointment.</li>
                <li>All consultations are normalized to 20-minute sessions to ensure punctual clinic scheduling.</li>
            </ul>
        </div>

        <div class="card" style="background: var(--gray-50); border: 1px dashed var(--gray-300);">
            <h3 style="margin: 0 0 8px 0; font-size: 14px; color: var(--gray-700);">Patient Information</h3>
            <div style="font-size: 13px; color: var(--gray-500); line-height: 1.5;">
                Name: <strong><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                Email: <strong><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                Phone: <strong><?php echo htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </div>
    </div>
</div>

<style>
.slot-pill {
    padding: 10px 8px;
    text-align: center;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 600;
    transition: all 0.15s ease;
    border: 1px solid var(--gray-200);
}
.slot-available {
    background: #ffffff;
    color: var(--primary-dark);
    border-color: var(--primary);
}
.slot-available:hover {
    background: var(--primary-light);
    transform: translateY(-1px);
}
.slot-pill.active {
    background: var(--primary) !important;
    color: #ffffff !important;
    border-color: var(--primary-dark) !important;
    box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.4);
}
.slot-unavailable {
    background: var(--gray-100);
    color: var(--gray-400);
    text-decoration: line-through;
    cursor: not-allowed;
    border-color: var(--gray-200);
}
</style>

<script>
function updateSelectedSlot(input) {
    document.querySelectorAll('.slot-pill').forEach(el => el.classList.remove('active'));
    if (input.checked) {
        input.nextElementSibling.classList.add('active');
    }
}

function refreshSlots() {
    const docId = document.getElementById('doctor_id').value;
    const dateVal = document.getElementById('appointment_date').value;
    const container = document.getElementById('slotsContainer');

    if (!docId || !dateVal) return;

    container.innerHTML = '<div style="padding: 16px; text-align: center; color: var(--gray-500); font-size: 13px;">&#8987; Checking schedule availability...</div>';

    fetch('<?php echo APP_BASE_URL; ?>/api/get_slots.php?doctor_id=' + encodeURIComponent(docId) + '&date=' + encodeURIComponent(dateVal))
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.slots || data.slots.length === 0) {
                container.innerHTML = '<div class="alert alert-info" style="margin-top: 10px;">' + (data.message || 'No scheduled consultation shifts on this day.') + '</div>';
                return;
            }

            let html = '<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(105px, 1fr)); gap: 10px; margin-top: 8px;">';
            let availableCount = 0;

            data.slots.forEach(slot => {
                if (slot.is_available) {
                    availableCount++;
                    html += `
                        <label style="display: block; cursor: pointer;">
                            <input type="radio" name="start_time" value="${slot.start_time}" style="display: none;" onchange="updateSelectedSlot(this)" required>
                            <div class="slot-pill slot-available">${slot.display_time}</div>
                        </label>
                    `;
                } else {
                    const title = slot.is_booked ? 'Already reserved' : 'Slot passed';
                    html += `<div class="slot-pill slot-unavailable" title="${title}">${slot.display_time}</div>`;
                }
            });
            html += '</div>';

            if (availableCount === 0) {
                html += '<div class="alert alert-warning" style="margin-top: 12px;">All consultation slots for this date are booked or have elapsed.</div>';
            }

            container.innerHTML = html;
        })
        .catch(err => {
            container.innerHTML = '<div class="alert alert-danger">Error retrieving doctor availability. Please reload the page.</div>';
        });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

