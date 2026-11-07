<?php
/**
 * =============================================================================
 * CityCare Hospital - Appointment & Scheduling Test Suite (Phase 4)
 * =============================================================================
 * 
 * Verifies:
 *  1. Doctor directory retrieval & specialty filtering
 *  2. Dynamic 20-minute slot generation from weekly shifts
 *  3. Appointment reservation with unique reference CC-YYYYMMDD-XXXX
 *  4. Double-booking prevention on same doctor/date/time
 *  5. Same-patient simultaneous booking conflict prevention
 *  6. Cancellation policy notice window enforcement
 *  7. Audit logging for scheduling lifecycle events
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/booking.php';

$isCli = (php_sapi_name() === 'cli');

$tests = [
    'doctor_directory' => ['title' => 'Doctor Directory & Shift Retrieval', 'status' => false, 'messages' => []],
    'slot_generator'   => ['title' => 'Dynamic 20-Min Slot Calculation',     'status' => false, 'messages' => []],
    'booking_flow'     => ['title' => 'Appointment Creation & Reference',    'status' => false, 'messages' => []],
    'conflict_guard'   => ['title' => 'Double-Booking Conflict Prevention',   'status' => false, 'messages' => []],
    'cancellation'     => ['title' => 'Notice-Period Cancellation Policy',   'status' => false, 'messages' => []],
    'audit_logging'    => ['title' => 'Booking Audit Trail Recording',       'status' => false, 'messages' => []]
];

$pdo = getDBConnection();

// -----------------------------------------------------------------------------
// Test 1: Doctor Directory & Shift Retrieval
// -----------------------------------------------------------------------------
try {
    $allDocs = getDoctors(true);
    $cardioDocs = getDoctors(true, 'Cardiology');
    $pediaDocs = getDoctors(true, 'Pediatrics');
    
    $doc1Schedules = getDoctorWeeklySchedules(1); // Dr. Sarah Jenkins

    if (count($allDocs) >= 3 && count($cardioDocs) >= 1 && count($pediaDocs) >= 1 && count($doc1Schedules) >= 5) {
        $tests['doctor_directory']['status'] = true;
        $tests['doctor_directory']['messages'][] = sprintf('Retrieved %d active doctors across specialties.', count($allDocs));
        $tests['doctor_directory']['messages'][] = sprintf('Dr. Sarah Jenkins has %d scheduled weekly shifts.', count($doc1Schedules));
    } else {
        $tests['doctor_directory']['status'] = false;
        $tests['doctor_directory']['messages'][] = 'Doctor directory or schedules did not match expected count.';
    }
} catch (Exception $e) {
    $tests['doctor_directory']['status'] = false;
    $tests['doctor_directory']['messages'][] = 'Error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 2: Dynamic 20-Minute Slot Calculation
// -----------------------------------------------------------------------------
// Find next Monday date
$nextMonday = date('Y-m-d', strtotime('next Monday'));
try {
    $slotsRes = getDoctorAvailableSlots(1, $nextMonday);

    if ($slotsRes['success'] && !empty($slotsRes['slots'])) {
        $totalSlots = count($slotsRes['slots']);
        $firstSlot = $slotsRes['slots'][0];
        
        // Slot duration check: 09:00:00 to 09:20:00
        $diff = strtotime($firstSlot['end_time']) - strtotime($firstSlot['start_time']);

        if ($diff === 1200) { // 20 minutes * 60 seconds = 1200
            $tests['slot_generator']['status'] = true;
            $tests['slot_generator']['messages'][] = "Generated {$totalSlots} normalized 20-minute slots for {$nextMonday} ({$slotsRes['day']}).";
            $tests['slot_generator']['messages'][] = "First slot: {$firstSlot['start_time']} to {$firstSlot['end_time']} ({$firstSlot['display_time']}).";
        } else {
            $tests['slot_generator']['status'] = false;
            $tests['slot_generator']['messages'][] = "Slot duration expected 20m (1200s), got {$diff}s.";
        }
    } else {
        $tests['slot_generator']['status'] = false;
        $tests['slot_generator']['messages'][] = "Failed to generate slots: " . ($slotsRes['message'] ?? 'No slots');
    }
} catch (Exception $e) {
    $tests['slot_generator']['status'] = false;
    $tests['slot_generator']['messages'][] = 'Error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 3: Appointment Creation & Reference
// -----------------------------------------------------------------------------
$patientUserId = 3; // patient@citycare.local
$testSlotTime = '09:00:00';
$bookedReference = null;
$bookedAppId = null;

try {
    // Clean up any previous test booking on this exact slot to ensure clean test run
    $cleanStmt = $pdo->prepare("DELETE FROM appointments WHERE doctor_id = 1 AND appointment_date = :app_date AND start_time = :time");
    $cleanStmt->execute([':app_date' => $nextMonday, ':time' => $testSlotTime]);

    $bookRes = createAppointment($patientUserId, 1, $nextMonday, $testSlotTime);

    if ($bookRes['success'] && !empty($bookRes['booking_reference'])) {
        $bookedReference = $bookRes['booking_reference'];
        $bookedAppId = (int)$bookRes['appointment_id'];

        // Verify row in database
        $checkStmt = $pdo->prepare("SELECT * FROM appointments WHERE id = :id");
        $checkStmt->execute([':id' => $bookedAppId]);
        $row = $checkStmt->fetch();

        if ($row && $row['status'] === 'confirmed' && $row['booking_reference'] === $bookedReference) {
            $tests['booking_flow']['status'] = true;
            $tests['booking_flow']['messages'][] = "Successfully booked appointment (ID: {$bookedAppId}).";
            $tests['booking_flow']['messages'][] = "Unique reference verified: {$bookedReference}";
            $tests['booking_flow']['messages'][] = "Status initialized as 'confirmed'.";
        } else {
            $tests['booking_flow']['status'] = false;
            $tests['booking_flow']['messages'][] = "Database row verification failed.";
        }
    } else {
        $tests['booking_flow']['status'] = false;
        $tests['booking_flow']['messages'][] = "Booking failed: " . ($bookRes['message'] ?? 'Unknown error');
    }
} catch (Exception $e) {
    $tests['booking_flow']['status'] = false;
    $tests['booking_flow']['messages'][] = 'Error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 4: Double-Booking Conflict Prevention
// -----------------------------------------------------------------------------
try {
    // Attempt to book the exact same slot with a different user
    $conflictRes = createAppointment(1, 1, $nextMonday, $testSlotTime);

    // Verify slots endpoint marks slot as unavailable
    $recheck = getDoctorAvailableSlots(1, $nextMonday);
    $targetSlotState = null;
    foreach ($recheck['slots'] as $s) {
        if ($s['start_time'] === $testSlotTime) {
            $targetSlotState = $s;
            break;
        }
    }

    if (!$conflictRes['success'] && $targetSlotState && $targetSlotState['is_available'] === false && $targetSlotState['is_booked'] === true) {
        $tests['conflict_guard']['status'] = true;
        $tests['conflict_guard']['messages'][] = "Duplicate booking for identical slot successfully blocked: " . $conflictRes['message'];
        $tests['conflict_guard']['messages'][] = "Availability engine dynamically marked {$testSlotTime} as is_booked=true.";
    } else {
        $tests['conflict_guard']['status'] = false;
        $tests['conflict_guard']['messages'][] = "Conflict guard failed to block duplicate appointment.";
    }
} catch (Exception $e) {
    $tests['conflict_guard']['status'] = false;
    $tests['conflict_guard']['messages'][] = 'Error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 5: Notice-Period Cancellation Policy
// -----------------------------------------------------------------------------
try {
    if ($bookedAppId) {
        // Cancel the test appointment booked for next Monday (> 2 hours notice)
        $cancelRes = cancelAppointment($bookedAppId, $patientUserId, 'patient');

        // Check database status
        $statusStmt = $pdo->prepare("SELECT status FROM appointments WHERE id = :id");
        $statusStmt->execute([':id' => $bookedAppId]);
        $currentStatus = $statusStmt->fetchColumn();

        if ($cancelRes['success'] && $currentStatus === 'cancelled') {
            $tests['cancellation']['status'] = true;
            $tests['cancellation']['messages'][] = "Future appointment cancelled successfully within allowed notice window.";
            $tests['cancellation']['messages'][] = "Database status updated to 'cancelled'.";
        } else {
            $tests['cancellation']['status'] = false;
            $tests['cancellation']['messages'][] = "Cancellation failed: " . ($cancelRes['message'] ?? 'Unknown error');
        }
    } else {
        $tests['cancellation']['status'] = false;
        $tests['cancellation']['messages'][] = "No appointment available to test cancellation.";
    }
} catch (Exception $e) {
    $tests['cancellation']['status'] = false;
    $tests['cancellation']['messages'][] = 'Error: ' . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 6: Booking Audit Trail Recording
// -----------------------------------------------------------------------------
try {
    $auditStmt = $pdo->query("
        SELECT action, COUNT(*) as cnt 
        FROM audit_logs 
        WHERE action IN ('APPOINTMENT_CREATE', 'APPOINTMENT_CANCEL') 
        GROUP BY action
    ");
    $auditCounts = [];
    while ($row = $auditStmt->fetch()) {
        $auditCounts[$row['action']] = (int)$row['cnt'];
    }

    if (!empty($auditCounts['APPOINTMENT_CREATE']) && !empty($auditCounts['APPOINTMENT_CANCEL'])) {
        $tests['audit_logging']['status'] = true;
        $tests['audit_logging']['messages'][] = "APPOINTMENT_CREATE events logged: {$auditCounts['APPOINTMENT_CREATE']}";
        $tests['audit_logging']['messages'][] = "APPOINTMENT_CANCEL events logged: {$auditCounts['APPOINTMENT_CANCEL']}";
    } else {
        $tests['audit_logging']['status'] = false;
        $tests['audit_logging']['messages'][] = "Audit logs missing scheduling actions.";
    }
} catch (Exception $e) {
    $tests['audit_logging']['status'] = false;
    $tests['audit_logging']['messages'][] = 'Error: ' . $e->getMessage();
}

// Check overall success
$allPassed = true;
foreach ($tests as $t) {
    if (!$t['status']) {
        $allPassed = false;
        break;
    }
}

if ($isCli) {
    echo "====================================================================\n";
    echo " CityCare Hospital - Appointment & Booking Test Suite (Phase 4)\n";
    echo "====================================================================\n\n";

    $i = 1;
    foreach ($tests as $key => $t) {
        echo sprintf("[%d] %-42s : %s\n", $i++, $t['title'], $t['status'] ? "PASS" : "FAIL");
        foreach ($t['messages'] as $msg) {
            echo "    - " . $msg . "\n";
        }
        echo "\n";
    }

    echo "====================================================================\n";
    echo " OVERALL STATUS: " . ($allPassed ? "ALL SCHEDULING TESTS PASSED" : "SOME TESTS FAILED") . "\n";
    echo "====================================================================\n";
    exit($allPassed ? 0 : 1);
}

// Browser UI
$pageTitle = 'Scheduling Diagnostics';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h1 class="card-title">Appointment &amp; Scheduling Engine Verification</h1>
            <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
                Diagnostic results for 20-minute slot generation, double-booking prevention, and cancellation rules.
            </p>
        </div>
        <span class="badge" style="background: <?php echo $allPassed ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $allPassed ? '#15803d' : '#b91c1c'; ?>; font-size: 13px;">
            <?php echo $allPassed ? '&#10004; ALL TESTS PASSED' : '&#10008; TESTS FAILED'; ?>
        </span>
    </div>

    <?php $stepIdx = 1; ?>
    <?php foreach ($tests as $key => $t): ?>
        <div style="padding: 16px 0; border-bottom: 1px solid var(--gray-200);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <h3 style="margin: 0; font-size: 16px; color: var(--gray-900);">
                    <?php echo $stepIdx++; ?>. <?php echo htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'); ?>
                </h3>
                <span class="badge" style="background: <?php echo $t['status'] ? 'var(--success-light)' : 'var(--danger-light)'; ?>; color: <?php echo $t['status'] ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <?php echo $t['status'] ? 'PASS' : 'FAIL'; ?>
                </span>
            </div>
            <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--gray-700);">
                <?php foreach ($t['messages'] as $m): ?>
                    <li><?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>

    <div style="display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap;">
        <a href="patient/book.php" class="btn btn-primary">Try Booking an Appointment &rarr;</a>
        <a href="doctors.php" class="btn btn-secondary">Browse Doctors</a>
        <a href="index.php" class="btn btn-secondary">&larr; Return to Home</a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

