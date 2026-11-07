<?php
/**
 * =============================================================================
 * CityCare Hospital - Front Desk Patient Check-In Terminal
 * =============================================================================
 * 
 * Receptionists verify arrived patients, validate booking references, and
 * issue daily sequential queue tickets (e.g. G001, P001, C001).
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
$searchTerm = trim((string)($_GET['search'] ?? ''));
$filterDate = trim((string)($_GET['date'] ?? $today));

$issuedTicket = null;

// Handle Check-In POST Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_in') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('danger', 'Security token expired. Please try again.');
        redirect($_SERVER['REQUEST_URI']);
    }

    $appointmentId = (int)($_POST['appointment_id'] ?? 0);
    if ($appointmentId <= 0) {
        setFlash('danger', 'Invalid appointment selected for check-in.');
        redirect($_SERVER['REQUEST_URI']);
    }

    $result = checkInAppointment($appointmentId, (int)$user['id']);

    if ($result['success']) {
        setFlash('success', $result['message']);
        // Store for immediate receipt modal display in this request
        $_SESSION['last_issued_ticket'] = [
            'queue_number'     => $result['queue_entry']['queue_number'],
            'booking_reference'=> $result['appointment']['booking_reference'],
            'patient_name'     => $result['appointment']['patient_name'],
            'doctor_name'      => $result['appointment']['doctor_name'],
            'specialty'        => $result['appointment']['specialty'],
            'room_number'      => $result['appointment']['room_number'],
            'appointment_date' => $result['appointment']['appointment_date'],
            'start_time'       => $result['appointment']['start_time'],
            'checked_in_at'    => $result['queue_entry']['checked_in_at']
        ];
        redirect('checkin.php?date=' . urlencode($filterDate) . '&ticket_modal=1');
    } else {
        setFlash('danger', $result['message']);
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Retrieve issued ticket data for modal if returning from check-in
if (isset($_GET['ticket_modal']) && isset($_SESSION['last_issued_ticket'])) {
    $issuedTicket = $_SESSION['last_issued_ticket'];
    unset($_SESSION['last_issued_ticket']);
}

// -----------------------------------------------------------------------------
// Fetch Today's Queue Metrics
// -----------------------------------------------------------------------------
$metricStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT a.id) AS total_today,
        COUNT(DISTINCT CASE WHEN a.status = 'checked_in' THEN a.id END) AS checked_in_count,
        COUNT(DISTINCT CASE WHEN q.status = 'waiting' THEN q.id END) AS waiting_count,
        COUNT(DISTINCT CASE WHEN q.status = 'in_service' THEN q.id END) AS in_service_count,
        COUNT(DISTINCT CASE WHEN q.status = 'completed' THEN q.id END) AS completed_count
    FROM appointments a
    LEFT JOIN queue_entries q ON a.id = q.appointment_id AND q.queue_date = :metric_date
    WHERE a.appointment_date = :metric_date2
");
$metricStmt->execute([':metric_date' => $filterDate, ':metric_date2' => $filterDate]);
$metrics = $metricStmt->fetch() ?: [
    'total_today' => 0, 'checked_in_count' => 0, 'waiting_count' => 0, 'in_service_count' => 0, 'completed_count' => 0
];

// -----------------------------------------------------------------------------
// Fetch Appointments Expected for Arrival
// -----------------------------------------------------------------------------
$searchSql = "";
$searchParams = [':filter_date' => $filterDate];

if ($searchTerm !== '') {
    $searchSql = " AND (a.booking_reference LIKE :term OR u.full_name LIKE :term OR u.phone LIKE :term) ";
    $searchParams[':term'] = '%' . $searchTerm . '%';
}

$expectedStmt = $pdo->prepare("
    SELECT a.*, d.full_name AS doctor_name, d.specialty, d.room_number,
           u.full_name AS patient_name, u.phone AS patient_phone, u.email AS patient_email,
           q.queue_number, q.status AS queue_status, q.checked_in_at
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.id
    JOIN users u ON a.patient_id = u.id
    LEFT JOIN queue_entries q ON a.id = q.appointment_id
    WHERE a.appointment_date = :filter_date
      {$searchSql}
    ORDER BY a.start_time ASC
");
$expectedStmt->execute($searchParams);
$appointments = $expectedStmt->fetchAll();

$pageTitle = 'Front Desk Patient Check-In';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Printable Thermal Ticket Slip Style */
@media print {
    body * {
        visibility: hidden;
    }
    #ticketPrintArea, #ticketPrintArea * {
        visibility: visible;
    }
    #ticketPrintArea {
        position: fixed;
        left: 0;
        top: 0;
        width: 80mm;
        margin: 0 auto;
        padding: 10px;
        background: #fff;
        color: #000;
        font-family: monospace;
    }
    .no-print {
        display: none !important;
    }
}

.ticket-slip {
    max-width: 360px;
    margin: 0 auto;
    background: #ffffff;
    border: 2px dashed #94a3b8;
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.08);
    text-align: center;
}

.ticket-badge-lg {
    font-size: 54px;
    font-weight: 900;
    letter-spacing: 2px;
    color: var(--primary);
    background: #eff6ff;
    border: 2px solid #bfdbfe;
    border-radius: 12px;
    padding: 12px;
    margin: 16px 0;
    line-height: 1;
}

.ticket-divider {
    border-top: 1px dashed #cbd5e1;
    margin: 16px 0;
}
</style>

<!-- Header & Quick Nav -->
<div class="card-header no-print">
    <div>
        <h1 class="card-title">Front Desk Patient Check-In</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            Lookup scheduled arrivals, verify identities, and issue sequential physical queue tickets.
        </p>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="queue.php" class="btn btn-primary" style="font-size: 13px;">
            &#128227; Queue Calling Desk &rarr;
        </a>
        <a href="<?php echo APP_BASE_URL; ?>/queue_display.php" target="_blank" class="btn btn-secondary" style="font-size: 13px;">
            &#128250; Open Lobby Waiting Display
        </a>
    </div>
</div>

<!-- Queue Metrics Counter Bar -->
<div class="stats-grid no-print" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-label">Expected Today</div>
        <div class="stat-value" style="color: var(--primary);"><?php echo (int)$metrics['total_today']; ?></div>
        <div class="stat-desc">Scheduled bookings</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Currently Waiting</div>
        <div class="stat-value" style="color: var(--warning);"><?php echo (int)$metrics['waiting_count']; ?></div>
        <div class="stat-desc">In reception waiting lobby</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">In Consultation</div>
        <div class="stat-value" style="color: #0284c7;"><?php echo (int)$metrics['in_service_count']; ?></div>
        <div class="stat-desc">Currently in doctor rooms</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Completed Today</div>
        <div class="stat-value" style="color: var(--success);"><?php echo (int)$metrics['completed_count']; ?></div>
        <div class="stat-desc">Discharged consultations</div>
    </div>
</div>

<!-- Check-In Receipt Slip Modal (Triggered right after successful check-in) -->
<?php if ($issuedTicket): ?>
    <div class="card no-print" style="background: #f8fafc; border: 2px solid var(--primary); margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 8px; color: var(--success); font-weight: 700; font-size: 16px;">
                <span>&#10004;</span> Check-In Confirmed &amp; Ticket Issued
            </div>
            <button type="button" onclick="window.print()" class="btn btn-primary" style="font-size: 13px; padding: 6px 14px;">
                &#128424; Print Ticket Slip
            </button>
        </div>

        <!-- Render Printable Thermal Ticket Area -->
        <div id="ticketPrintArea">
            <div class="ticket-slip">
                <div style="font-size: 18px; font-weight: 800; color: var(--gray-900);">✚ CITYCARE HOSPITAL</div>
                <div style="font-size: 12px; color: var(--gray-500); margin-top: 2px;">Outpatient Department Reception</div>
                
                <div class="ticket-divider"></div>

                <div style="font-size: 12px; font-weight: 700; color: var(--gray-600); text-transform: uppercase; letter-spacing: 1px;">
                    Your Queue Ticket Number
                </div>
                <div class="ticket-badge-lg">
                    <?php echo htmlspecialchars($issuedTicket['queue_number'], ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <div style="background: #f1f5f9; padding: 10px; border-radius: 8px; margin-bottom: 14px;">
                    <div style="font-size: 15px; font-weight: 700; color: var(--gray-900);">
                        <?php echo htmlspecialchars($issuedTicket['doctor_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div style="font-size: 12px; color: var(--primary); font-weight: 600;">
                        <?php echo htmlspecialchars($issuedTicket['specialty'], ENT_QUOTES, 'UTF-8'); ?> &bull; 
                        <strong><?php echo htmlspecialchars($issuedTicket['room_number'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                </div>

                <table style="width: 100%; font-size: 12px; text-align: left; border-collapse: collapse; margin-bottom: 14px;">
                    <tr>
                        <td style="padding: 4px 0; color: var(--gray-500);">Patient:</td>
                        <td style="padding: 4px 0; font-weight: 600; text-align: right;"><?php echo htmlspecialchars($issuedTicket['patient_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: var(--gray-500);">Reference:</td>
                        <td style="padding: 4px 0; font-weight: 600; text-align: right;"><?php echo htmlspecialchars($issuedTicket['booking_reference'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: var(--gray-500);">Scheduled Time:</td>
                        <td style="padding: 4px 0; font-weight: 600; text-align: right;"><?php echo date('h:i A', strtotime($issuedTicket['start_time'])); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: var(--gray-500);">Checked In At:</td>
                        <td style="padding: 4px 0; font-weight: 600; text-align: right;"><?php echo date('h:i:s A', strtotime($issuedTicket['checked_in_at'])); ?></td>
                    </tr>
                </table>

                <div class="ticket-divider"></div>

                <div style="font-size: 11px; color: var(--gray-500); line-height: 1.4;">
                    Please proceed to the designated waiting area. Watch the overhead lobby screens for your ticket number.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Search & Date Filter Bar -->
<div class="card no-print" style="margin-bottom: 24px;">
    <form method="GET" action="checkin.php" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
        <div class="form-group" style="flex: 2; min-width: 240px; margin-bottom: 0;">
            <label for="search" class="form-label">Search Arriving Patient</label>
            <input type="text" id="search" name="search" class="form-input" 
                   placeholder="Enter Booking Reference (e.g. CC-202611...), Patient Name, or Phone..."
                   value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="form-group" style="flex: 1; min-width: 160px; margin-bottom: 0;">
            <label for="date" class="form-label">Consultation Date</label>
            <input type="date" id="date" name="date" class="form-input" 
                   value="<?php echo htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn btn-primary" style="height: 40px;">
                &#128269; Search
            </button>
            <?php if ($searchTerm !== '' || $filterDate !== $today): ?>
                <a href="checkin.php" class="btn btn-secondary" style="height: 40px; display: inline-flex; align-items: center;">
                    Reset
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Arrival & Check-In Table -->
<div class="card no-print">
    <div class="card-header">
        <div>
            <h2 class="card-title" style="font-size: 17px;">
                Roster for <?php echo date('l, M d, Y', strtotime($filterDate)); ?>
            </h2>
            <span style="font-size: 13px; color: var(--gray-500);">
                <?php echo count($appointments); ?> consultation(s) found
            </span>
        </div>
    </div>

    <?php if (empty($appointments)): ?>
        <div style="text-align: center; padding: 40px 20px; color: var(--gray-500);">
            <div style="font-size: 42px; margin-bottom: 8px;">&#128197;</div>
            <p style="margin: 0; font-size: 15px; font-weight: 600;">No appointments found matching criteria.</p>
            <p style="margin: 4px 0 0 0; font-size: 13px;">Check the date filter or verify the booking reference.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Ticket</th>
                        <th>Booking Ref</th>
                        <th>Patient Name &amp; Contact</th>
                        <th>Doctor &amp; Room</th>
                        <th>Arrival Status</th>
                        <th style="text-align: right;">Reception Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($appointments as $app): ?>
                        <?php
                        $isCheckedIn = !empty($app['queue_number']);
                        $statusClass = 'status-' . htmlspecialchars($app['status'], ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo date('h:i A', strtotime($app['start_time'])); ?></strong>
                                <div style="font-size: 11px; color: var(--gray-500);"><?php echo date('h:i A', strtotime($app['end_time'])); ?></div>
                            </td>
                            <td>
                                <?php if ($isCheckedIn): ?>
                                    <span class="badge" style="font-size: 15px; font-weight: 800; background: #e0f2fe; color: #0369a1; padding: 4px 10px; border: 1px solid #bae6fd;">
                                        <?php echo htmlspecialchars($app['queue_number'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--gray-400); font-size: 12px;">Not issued</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="font-family: monospace; font-size: 13px; color: var(--primary);">
                                    <?php echo htmlspecialchars($app['booking_reference'], ENT_QUOTES, 'UTF-8'); ?>
                                </strong>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: var(--gray-900);">
                                    <?php echo htmlspecialchars($app['patient_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div style="font-size: 12px; color: var(--gray-500);">
                                    <?php echo htmlspecialchars($app['patient_phone'] ?? 'No phone', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600;">
                                    <?php echo htmlspecialchars($app['doctor_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div style="font-size: 12px; color: var(--gray-500);">
                                    <?php echo htmlspecialchars($app['specialty'], ENT_QUOTES, 'UTF-8'); ?> &bull; 
                                    <strong style="color: var(--gray-700);"><?php echo htmlspecialchars($app['room_number'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?>
                                </span>
                                <?php if ($isCheckedIn && !empty($app['checked_in_at'])): ?>
                                    <div style="font-size: 11px; color: var(--gray-500); margin-top: 2px;">
                                        Arr: <?php echo date('h:i A', strtotime($app['checked_in_at'])); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <?php if (!$isCheckedIn && in_array($app['status'], ['pending', 'confirmed'], true)): ?>
                                    <form method="POST" action="checkin.php?date=<?php echo urlencode($filterDate); ?>" style="display: inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="check_in">
                                        <input type="hidden" name="appointment_id" value="<?php echo (int)$app['id']; ?>">
                                        <button type="submit" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px; font-weight: 700;">
                                            &#127919; Check In &amp; Issue Ticket
                                        </button>
                                    </form>
                                <?php elseif ($isCheckedIn): ?>
                                    <span style="font-size: 12px; color: var(--success); font-weight: 600;">
                                        &#10004; Checked In (<?php echo htmlspecialchars($app['queue_status'] ?? 'waiting', ENT_QUOTES, 'UTF-8'); ?>)
                                    </span>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: var(--gray-400);">
                                        <?php echo ucfirst($app['status']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

