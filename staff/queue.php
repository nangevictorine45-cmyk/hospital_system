<?php
/**
 * =============================================================================
 * CityCare Hospital - Clinical Queue Calling & Consultation Desk
 * =============================================================================
 * 
 * Medical staff and consultation room nurses manage the patient queue:
 *  - Call next waiting patient into the consultation room
 *  - Mark consultation as completed
 *  - Skip absent patients (which automatically triggers the rescheduling recovery engine)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/queue.php';

// Access Control: Staff and Admin only
requireLogin();
$user = currentUser();
if (!in_array($user['role'], ['staff', 'admin'], true)) {
    redirect(APP_BASE_URL . '/403.php');
}

$pdo = getDBConnection();
$today = date('Y-m-d');

// Fetch all active doctors for room selection
$docStmt = $pdo->query("SELECT id, full_name, specialty, room_number FROM doctors WHERE active_status = 1 ORDER BY room_number ASC");
$doctors = $docStmt->fetchAll();

// Selected doctor filter (default to first doctor if not specified)
$selectedDoctorId = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : (int)($doctors[0]['id'] ?? 1);

// Verify doctor exists
$selectedDoctor = null;
foreach ($doctors as $d) {
    if ((int)$d['id'] === $selectedDoctorId) {
        $selectedDoctor = $d;
        break;
    }
}
if (!$selectedDoctor && !empty($doctors)) {
    $selectedDoctor = $doctors[0];
    $selectedDoctorId = (int)$selectedDoctor['id'];
}

// -----------------------------------------------------------------------------
// Handle Queue Actions (POST)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('danger', 'Security token expired. Please try again.');
        redirect($_SERVER['REQUEST_URI']);
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'call_next') {
        $docId = (int)($_POST['doctor_id'] ?? $selectedDoctorId);
        $result = callNextPatient($docId, (int)$user['id']);
        if ($result['success']) {
            setFlash('success', $result['message']);
        } else {
            setFlash('warning', $result['message']);
        }
        redirect('queue.php?doctor_id=' . $docId);
    }

    if ($action === 'complete_consultation') {
        $queueId = (int)($_POST['queue_id'] ?? 0);
        $result = completeQueueEntry($queueId, (int)$user['id']);
        if ($result['success']) {
            setFlash('success', $result['message']);
        } else {
            setFlash('danger', $result['message']);
        }
        redirect('queue.php?doctor_id=' . $selectedDoctorId);
    }

    if ($action === 'skip_patient') {
        $queueId = (int)($_POST['queue_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? 'Absent at call'));
        $result = skipQueueEntry($queueId, (int)$user['id'], $reason);
        if ($result['success']) {
            setFlash('warning', $result['message']);
        } else {
            setFlash('danger', $result['message']);
        }
        redirect('queue.php?doctor_id=' . $selectedDoctorId);
    }
}

// -----------------------------------------------------------------------------
// Fetch Selected Doctor's Live Queue State
// -----------------------------------------------------------------------------

// 1. Current In-Service Patient (if any)
$servingStmt = $pdo->prepare("
    SELECT q.id AS queue_id, q.queue_number, q.called_at, q.service_started_at,
           TIMESTAMPDIFF(MINUTE, q.called_at, NOW()) AS minutes_in_service,
           a.id AS appointment_id, a.booking_reference, a.start_time,
           u.full_name AS patient_name, u.phone AS patient_phone
    FROM queue_entries q
    JOIN appointments a ON q.appointment_id = a.id
    JOIN users u ON a.patient_id = u.id
    WHERE a.doctor_id = :doc_id
      AND q.queue_date = :qdate
      AND q.status = 'in_service'
    ORDER BY q.called_at DESC
    LIMIT 1
");
$servingStmt->execute([':doc_id' => $selectedDoctorId, ':qdate' => $today]);
$currentPatient = $servingStmt->fetch();

// 2. Waiting Patients
$waitingStmt = $pdo->prepare("
    SELECT q.id AS queue_id, q.queue_number, q.checked_in_at,
           TIMESTAMPDIFF(MINUTE, q.checked_in_at, NOW()) AS wait_minutes,
           a.id AS appointment_id, a.booking_reference, a.start_time,
           u.full_name AS patient_name, u.phone AS patient_phone
    FROM queue_entries q
    JOIN appointments a ON q.appointment_id = a.id
    JOIN users u ON a.patient_id = u.id
    WHERE a.doctor_id = :doc_id
      AND q.queue_date = :qdate
      AND q.status = 'waiting'
    ORDER BY a.start_time ASC, q.checked_in_at ASC
");
$waitingStmt->execute([':doc_id' => $selectedDoctorId, ':qdate' => $today]);
$waitingPatients = $waitingStmt->fetchAll();

// 3. Completed Today
$completedStmt = $pdo->prepare("
    SELECT q.id AS queue_id, q.queue_number, q.completed_at,
           a.booking_reference, u.full_name AS patient_name
    FROM queue_entries q
    JOIN appointments a ON q.appointment_id = a.id
    JOIN users u ON a.patient_id = u.id
    WHERE a.doctor_id = :doc_id
      AND q.queue_date = :qdate
      AND q.status = 'completed'
    ORDER BY q.completed_at DESC
    LIMIT 10
");
$completedStmt->execute([':doc_id' => $selectedDoctorId, ':qdate' => $today]);
$completedPatients = $completedStmt->fetchAll();

$pageTitle = 'Queue Calling & Consultation Desk';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">Queue Calling &amp; Consultation Desk</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Live consultation room terminal to call arrived patients, manage flow, and discharge visits.
        </p>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="checkin.php" class="btn btn-secondary" style="font-size: 13px;">
            &#127919; Front Desk Check-In
        </a>
        <a href="<?php echo APP_BASE_URL; ?>/queue_display.php" target="_blank" class="btn btn-secondary" style="font-size: 13px;">
            &#128250; Open Lobby Display
        </a>
    </div>
</div>

<!-- Doctor / Room Selector Tabs -->
<div style="display: flex; gap: 10px; margin-bottom: 24px; overflow-x: auto; padding-bottom: 4px;">
    <?php foreach ($doctors as $d): ?>
        <?php $isActive = ((int)$d['id'] === $selectedDoctorId); ?>
        <a href="queue.php?doctor_id=<?php echo (int)$d['id']; ?>" 
           class="btn <?php echo $isActive ? 'btn-primary' : 'btn-secondary'; ?>" 
           style="padding: 10px 18px; text-decoration: none; border-radius: 8px; white-space: nowrap;">
            <div style="font-weight: 700; font-size: 14px;">
                <?php echo htmlspecialchars($d['room_number'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div style="font-size: 12px; opacity: 0.9;">
                <?php echo htmlspecialchars($d['full_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($d['specialty'], ENT_QUOTES, 'UTF-8'); ?>)
            </div>
        </a>
    <?php endforeach; ?>
</div>

<div class="dashboard-grid">
    <!-- LEFT COLUMN: Active Consultation Room & Quick Call -->
    <div style="display: flex; flex-direction: column; gap: 24px;">

        <!-- CURRENT IN-SERVICE ROOM BOX -->
        <div class="card" style="border: 2px solid <?php echo $currentPatient ? 'var(--primary)' : 'var(--gray-300)'; ?>; background: <?php echo $currentPatient ? '#f0f9ff' : '#ffffff'; ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-size: 20px;">&#129658;</span>
                    <div>
                        <h2 style="margin: 0; font-size: 17px; color: var(--gray-900);">
                            <?php echo htmlspecialchars($selectedDoctor['room_number'], ENT_QUOTES, 'UTF-8'); ?> &bull; 
                            <?php echo htmlspecialchars($selectedDoctor['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </h2>
                        <span style="font-size: 12px; color: var(--primary); font-weight: 600;">
                            <?php echo htmlspecialchars($selectedDoctor['specialty'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                </div>

                <span class="badge" style="background: <?php echo $currentPatient ? 'var(--primary)' : 'var(--gray-200)'; ?>; color: <?php echo $currentPatient ? '#ffffff' : 'var(--gray-600)'; ?>; font-size: 12px; padding: 4px 10px;">
                    <?php echo $currentPatient ? '● ACTIVE CONSULTATION' : 'IDLE / AVAILABLE'; ?>
                </span>
            </div>

            <?php if ($currentPatient): ?>
                <div style="background: #ffffff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 18px;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--gray-500); text-transform: uppercase; letter-spacing: 1px;">
                        Currently in Room
                    </div>
                    <div style="font-size: 52px; font-weight: 900; color: var(--primary); line-height: 1.1; margin: 8px 0;">
                        <?php echo htmlspecialchars($currentPatient['queue_number'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div style="font-size: 18px; font-weight: 700; color: var(--gray-900);">
                        <?php echo htmlspecialchars($currentPatient['patient_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div style="font-size: 13px; color: var(--gray-500); margin-top: 4px;">
                        Ref: <code style="color: var(--primary);"><?php echo htmlspecialchars($currentPatient['booking_reference'], ENT_QUOTES, 'UTF-8'); ?></code> &bull; 
                        Phone: <?php echo htmlspecialchars($currentPatient['patient_phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div style="display: inline-block; background: #e0f2fe; color: #0369a1; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 600; margin-top: 10px;">
                        In Service for: <?php echo max(1, (int)$currentPatient['minutes_in_service']); ?> min(s)
                    </div>
                </div>

                <!-- Consultation Action Buttons -->
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <form method="POST" action="queue.php?doctor_id=<?php echo $selectedDoctorId; ?>" style="flex: 2;">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="complete_consultation">
                        <input type="hidden" name="queue_id" value="<?php echo (int)$currentPatient['queue_id']; ?>">
                        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; background: var(--success); border-color: var(--success); font-weight: 700;">
                            &#10004; Complete Consultation
                        </button>
                    </form>

                    <form method="POST" action="queue.php?doctor_id=<?php echo $selectedDoctorId; ?>" style="flex: 1;" onsubmit="return confirm('Skip this patient and send automatic rescheduling recovery options to their inbox?');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="skip_patient">
                        <input type="hidden" name="queue_id" value="<?php echo (int)$currentPatient['queue_id']; ?>">
                        <input type="hidden" name="reason" value="Patient left or absent during consultation">
                        <button type="submit" class="btn btn-danger" style="width: 100%; justify-content: center; padding: 12px; font-size: 13px;">
                            &#9888; Skip Patient
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 30px 20px; color: var(--gray-500);">
                    <div style="font-size: 42px; margin-bottom: 8px;">&#128065;</div>
                    <p style="margin: 0; font-size: 16px; font-weight: 600; color: var(--gray-700);">Room is currently unoccupied</p>
                    <p style="margin: 4px 0 16px 0; font-size: 13px;">
                        <?php if (!empty($waitingPatients)): ?>
                            There are <strong><?php echo count($waitingPatients); ?></strong> patient(s) waiting in line. Click Call Next below.
                        <?php else: ?>
                            No patients currently waiting in reception.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- CALL NEXT PATIENT BUTTON -->
            <?php if (!empty($waitingPatients)): ?>
                <?php $nextInLine = $waitingPatients[0]; ?>
                <div style="border-top: 1px solid var(--gray-200); margin-top: 20px; padding-top: 20px;">
                    <form method="POST" action="queue.php?doctor_id=<?php echo $selectedDoctorId; ?>">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="call_next">
                        <input type="hidden" name="doctor_id" value="<?php echo $selectedDoctorId; ?>">
                        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 16px; font-size: 16px; font-weight: 800; letter-spacing: 0.5px;">
                            &#128227; Call Next Patient (<?php echo htmlspecialchars($nextInLine['queue_number'], ENT_QUOTES, 'UTF-8'); ?> &bull; <?php echo htmlspecialchars($nextInLine['patient_name'], ENT_QUOTES, 'UTF-8'); ?>)
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- RECENTLY COMPLETED TODAY -->
        <div class="card">
            <h3 style="margin-top: 0; font-size: 16px; color: var(--gray-800); display: flex; justify-content: space-between;">
                <span>Completed Consultations Today</span>
                <span class="badge" style="background: var(--gray-100); color: var(--gray-700);"><?php echo count($completedPatients); ?> total</span>
            </h3>

            <?php if (empty($completedPatients)): ?>
                <p style="font-size: 13px; color: var(--gray-500); margin-bottom: 0;">No consultations completed yet today.</p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($completedPatients as $cp): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: var(--gray-50); border-radius: 6px; font-size: 13px;">
                            <div>
                                <strong style="color: var(--success);">&#10004; <?php echo htmlspecialchars($cp['queue_number'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span style="margin-left: 8px; color: var(--gray-800); font-weight: 600;"><?php echo htmlspecialchars($cp['patient_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <span style="font-size: 11px; color: var(--gray-500);">
                                <?php echo date('h:i A', strtotime($cp['completed_at'])); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT COLUMN: Waiting Queue Table -->
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title" style="font-size: 17px;">
                    Waiting Room Queue (<?php echo count($waitingPatients); ?>)
                </h2>
                <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 13px;">
                    Patients checked in and waiting for <?php echo htmlspecialchars($selectedDoctor['full_name'], ENT_QUOTES, 'UTF-8'); ?>.
                </p>
            </div>
            <?php if (!empty($waitingPatients)): ?>
                <form method="POST" action="queue.php?doctor_id=<?php echo $selectedDoctorId; ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="call_next">
                    <input type="hidden" name="doctor_id" value="<?php echo $selectedDoctorId; ?>">
                    <button type="submit" class="btn btn-primary" style="font-size: 12px; padding: 6px 12px;">
                        &#128227; Call Next
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (empty($waitingPatients)): ?>
            <div style="text-align: center; padding: 40px 20px; color: var(--gray-500);">
                <div style="font-size: 42px; margin-bottom: 8px;">&#9749;</div>
                <p style="margin: 0; font-size: 15px; font-weight: 600;">No patients waiting in queue</p>
                <p style="margin: 4px 0 16px 0; font-size: 13px;">Use Front-Desk Check-In to issue tickets as patients arrive.</p>
                <a href="checkin.php" class="btn btn-secondary" style="font-size: 12px;">
                    &rarr; Go to Check-In Desk
                </a>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Ticket</th>
                            <th>Patient Name</th>
                            <th>Sched Time</th>
                            <th>Wait Time</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($waitingPatients as $idx => $wp): ?>
                            <tr style="<?php echo ($idx === 0) ? 'background: #fffbeb;' : ''; ?>">
                                <td>
                                    <span style="font-weight: 700; color: var(--gray-400); font-size: 12px;">
                                        <?php echo $idx + 1; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge" style="font-size: 15px; font-weight: 800; background: #e0f2fe; color: #0369a1; padding: 4px 10px; border: 1px solid #bae6fd;">
                                        <?php echo htmlspecialchars($wp['queue_number'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--gray-900);">
                                        <?php echo htmlspecialchars($wp['patient_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div style="font-size: 11px; color: var(--gray-500); font-family: monospace;">
                                        <?php echo htmlspecialchars($wp['booking_reference'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-weight: 600; font-size: 13px;">
                                        <?php echo date('h:i A', strtotime($wp['start_time'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size: 12px; color: var(--warning); font-weight: 600;">
                                        <?php echo (int)$wp['wait_minutes']; ?> min(s)
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($idx === 0): ?>
                                        <form method="POST" action="queue.php?doctor_id=<?php echo $selectedDoctorId; ?>" style="display: inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="call_next">
                                            <input type="hidden" name="doctor_id" value="<?php echo $selectedDoctorId; ?>">
                                            <button type="submit" class="btn btn-primary" style="padding: 4px 10px; font-size: 12px; font-weight: 700;">
                                                &#128227; Call
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" action="queue.php?doctor_id=<?php echo $selectedDoctorId; ?>" style="display: inline; margin-left: 4px;" onsubmit="return confirm('Skip this ticket? Rescheduling options will be sent to the patient.');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="skip_patient">
                                        <input type="hidden" name="queue_id" value="<?php echo (int)$wp['queue_id']; ?>">
                                        <input type="hidden" name="reason" value="Patient uncontactable / left waiting room">
                                        <button type="submit" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px; color: var(--danger);">
                                            Skip
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

