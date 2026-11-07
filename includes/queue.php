<?php
/**
 * =============================================================================
 * CityCare Hospital - Front-Desk Check-In & Queue Management Engine
 * =============================================================================
 * 
 * Provides centralized business logic for:
 *  1. Specialty-prefixed daily queue ticket generation (e.g. G001, P001, C001)
 *  2. Front-desk patient arrival check-in with atomic lock
 *  3. Queue calling workflow (waiting -> in_service -> completed / skipped)
 *  4. Waiting room metrics and queue position tracking
 *  5. Public live display feeds with sanitized data
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/rescheduling.php';

/**
 * Returns the 1-letter specialty prefix used for queue tickets.
 */
function getSpecialtyQueuePrefix(string $specialty): string
{
    $clean = strtolower(trim($specialty));
    if (strpos($clean, 'general') !== false || strpos($clean, 'medicine') !== false) {
        return 'G';
    }
    if (strpos($clean, 'pediatric') !== false || strpos($clean, 'child') !== false) {
        return 'P';
    }
    if (strpos($clean, 'cardio') !== false || strpos($clean, 'heart') !== false) {
        return 'C';
    }
    if (strpos($clean, 'dental') !== false) {
        return 'D';
    }
    if (strpos($clean, 'ortho') !== false) {
        return 'O';
    }
    return 'A'; // Default Clinic Prefix
}

/**
 * Generates the next sequential queue number for today with a prefix.
 * Example: G001, G002, P001.
 */
function generateNextQueueNumber(PDO $pdo, string $prefix, string $date): string
{
    $stmt = $pdo->prepare("
        SELECT queue_number 
        FROM queue_entries 
        WHERE queue_date = :qdate AND queue_number LIKE :pattern
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute([
        ':qdate'   => $date,
        ':pattern' => $prefix . '%'
    ]);
    $lastTicket = $stmt->fetchColumn();

    $nextSeq = 1;
    if ($lastTicket && preg_match('/^[A-Z](\d+)$/', (string)$lastTicket, $matches)) {
        $nextSeq = ((int)$matches[1]) + 1;
    }

    return sprintf('%s%03d', $prefix, $nextSeq);
}

/**
 * Checks in an arrived patient by appointment ID.
 * Transitions appointment status to 'checked_in' and creates a 'waiting' queue entry.
 *
 * @param int $appointmentId
 * @param int|null $actorUserId
 * @return array
 */
function checkInAppointment(int $appointmentId, ?int $actorUserId = null): array
{
    $pdo = getDBConnection();

    try {
        $pdo->beginTransaction();

        // 1. Lock appointment row
        $appStmt = $pdo->prepare("
            SELECT a.*, d.full_name AS doctor_name, d.specialty, d.room_number,
                   u.full_name AS patient_name, u.phone AS patient_phone
            FROM appointments a
            JOIN doctors d ON a.doctor_id = d.id
            JOIN users u ON a.patient_id = u.id
            WHERE a.id = :id
            FOR UPDATE
        ");
        $appStmt->execute([':id' => $appointmentId]);
        $app = $appStmt->fetch();

        if (!$app) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Appointment not found.'];
        }

        // Prevent check-in for completed or cancelled bookings
        if (in_array($app['status'], ['completed', 'cancelled', 'no_show'], true)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => "Cannot check in appointment with status: {$app['status']}."];
        }

        // 2. Check if a queue entry already exists for this appointment
        $qCheck = $pdo->prepare("SELECT * FROM queue_entries WHERE appointment_id = :aid FOR UPDATE");
        $qCheck->execute([':aid' => $appointmentId]);
        $existingQueue = $qCheck->fetch();

        if ($existingQueue) {
            // Already has a ticket
            $pdo->commit();
            return [
                'success'     => true,
                'already_had' => true,
                'queue_entry' => $existingQueue,
                'appointment' => $app,
                'message'     => "Patient already checked in with Ticket #{$existingQueue['queue_number']}."
            ];
        }

        $today = date('Y-m-d');
        $prefix = getSpecialtyQueuePrefix($app['specialty']);
        $queueNumber = generateNextQueueNumber($pdo, $prefix, $today);

        // 3. Insert queue entry
        $insertQ = $pdo->prepare("
            INSERT INTO queue_entries (
                appointment_id, queue_date, queue_number, status, checked_in_at, created_at, updated_at
            ) VALUES (
                :aid, :qdate, :qnum, 'waiting', NOW(), NOW(), NOW()
            )
        ");
        $insertQ->execute([
            ':aid'   => $appointmentId,
            ':qdate' => $today,
            ':qnum'  => $queueNumber
        ]);
        $queueId = (int)$pdo->lastInsertId();

        // 4. Update appointment status
        $updateApp = $pdo->prepare("UPDATE appointments SET status = 'checked_in', updated_at = NOW() WHERE id = :id");
        $updateApp->execute([':id' => $appointmentId]);

        // 5. Audit Log
        logAudit(
            $actorUserId,
            'PATIENT_CHECKED_IN',
            'queue_entries',
            $queueId,
            "Checked in booking {$app['booking_reference']} ({$app['patient_name']}). Assigned ticket {$queueNumber} for {$app['doctor_name']} ({$app['room_number']})."
        );

        $pdo->commit();

        // Fetch fresh queue row
        $fetchQ = $pdo->prepare("SELECT * FROM queue_entries WHERE id = :id");
        $fetchQ->execute([':id' => $queueId]);
        $newQueueEntry = $fetchQ->fetch();

        return [
            'success'     => true,
            'already_had' => false,
            'queue_entry' => $newQueueEntry,
            'appointment' => $app,
            'message'     => "Patient checked in successfully! Ticket #{$queueNumber} issued."
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Check-in failed: ' . $e->getMessage()];
    }
}

/**
 * Calls the next waiting patient for a specific doctor.
 * Moves current in_service patient (if any) to completed, and marks the next waiting patient as 'in_service'.
 *
 * @param int $doctorId
 * @param int|null $actorUserId
 * @return array
 */
function callNextPatient(int $doctorId, ?int $actorUserId = null): array
{
    $pdo = getDBConnection();
    $today = date('Y-m-d');

    try {
        $pdo->beginTransaction();

        // Find the next waiting patient for this doctor today
        $nextStmt = $pdo->prepare("
            SELECT q.id AS queue_id, q.queue_number, q.appointment_id, q.checked_in_at,
                   a.booking_reference, a.appointment_date, a.start_time,
                   u.full_name AS patient_name, u.phone AS patient_phone,
                   d.id AS doctor_id, d.full_name AS doctor_name, d.room_number, d.specialty
            FROM queue_entries q
            JOIN appointments a ON q.appointment_id = a.id
            JOIN users u ON a.patient_id = u.id
            JOIN doctors d ON a.doctor_id = d.id
            WHERE a.doctor_id = :doc_id
              AND q.queue_date = :qdate
              AND q.status = 'waiting'
            ORDER BY a.start_time ASC, q.checked_in_at ASC
            LIMIT 1
            FOR UPDATE
        ");
        $nextStmt->execute([
            ':doc_id' => $doctorId,
            ':qdate'  => $today
        ]);
        $nextPatient = $nextStmt->fetch();

        if (!$nextPatient) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'No patients currently waiting in queue for this doctor.'];
        }

        // Check if there is an existing in-service patient for this doctor and auto-complete them
        $currentInService = $pdo->prepare("
            SELECT q.id, q.appointment_id, q.queue_number 
            FROM queue_entries q
            JOIN appointments a ON q.appointment_id = a.id
            WHERE a.doctor_id = :doc_id
              AND q.queue_date = :qdate
              AND q.status = 'in_service'
            FOR UPDATE
        ");
        $currentInService->execute([':doc_id' => $doctorId, ':qdate' => $today]);
        $activeEntries = $currentInService->fetchAll();

        foreach ($activeEntries as $activeEntry) {
            $compStmt = $pdo->prepare("
                UPDATE queue_entries 
                SET status = 'completed', completed_at = NOW(), updated_at = NOW() 
                WHERE id = :id
            ");
            $compStmt->execute([':id' => $activeEntry['id']]);

            $appComp = $pdo->prepare("
                UPDATE appointments 
                SET status = 'completed', updated_at = NOW() 
                WHERE id = :aid
            ");
            $appComp->execute([':aid' => $activeEntry['appointment_id']]);

            logAudit(
                $actorUserId,
                'QUEUE_COMPLETED',
                'queue_entries',
                (int)$activeEntry['id'],
                "Auto-completed consultation for ticket {$activeEntry['queue_number']} upon calling next patient."
            );
        }

        // Advance next patient to in_service
        $updateNext = $pdo->prepare("
            UPDATE queue_entries 
            SET status = 'in_service', called_at = NOW(), service_started_at = NOW(), updated_at = NOW() 
            WHERE id = :qid
        ");
        $updateNext->execute([':qid' => $nextPatient['queue_id']]);

        logAudit(
            $actorUserId,
            'QUEUE_CALLED',
            'queue_entries',
            (int)$nextPatient['queue_id'],
            "Called ticket {$nextPatient['queue_number']} ({$nextPatient['patient_name']}) into {$nextPatient['room_number']} with {$nextPatient['doctor_name']}."
        );

        $pdo->commit();

        return [
            'success'     => true,
            'called_entry'=> $nextPatient,
            'message'     => "Ticket #{$nextPatient['queue_number']} called into {$nextPatient['room_number']}!"
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Error calling next patient: ' . $e->getMessage()];
    }
}

/**
 * Manually marks a queue consultation as completed.
 */
function completeQueueEntry(int $queueId, ?int $actorUserId = null): array
{
    $pdo = getDBConnection();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT q.*, a.id AS appointment_id, a.booking_reference, u.full_name AS patient_name 
            FROM queue_entries q
            JOIN appointments a ON q.appointment_id = a.id
            JOIN users u ON a.patient_id = u.id
            WHERE q.id = :id
            FOR UPDATE
        ");
        $stmt->execute([':id' => $queueId]);
        $q = $stmt->fetch();

        if (!$q) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Queue entry not found.'];
        }

        $pdo->prepare("
            UPDATE queue_entries 
            SET status = 'completed', completed_at = NOW(), updated_at = NOW() 
            WHERE id = :id
        ")->execute([':id' => $queueId]);

        $pdo->prepare("
            UPDATE appointments 
            SET status = 'completed', updated_at = NOW() 
            WHERE id = :aid
        ")->execute([':aid' => $q['appointment_id']]);

        logAudit(
            $actorUserId,
            'QUEUE_COMPLETED',
            'queue_entries',
            $queueId,
            "Consultation completed for ticket {$q['queue_number']} ({$q['patient_name']}, Ref: {$q['booking_reference']})."
        );

        $pdo->commit();
        return ['success' => true, 'message' => "Ticket #{$q['queue_number']} marked as completed."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Failed to complete consultation: ' . $e->getMessage()];
    }
}

/**
 * Skips a patient ticket (e.g. absent when called) and dispatches automated rescheduling recovery.
 */
function skipQueueEntry(int $queueId, ?int $actorUserId = null, string $reason = 'Absent at call'): array
{
    $pdo = getDBConnection();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT q.*, a.id AS appointment_id, a.booking_reference, u.full_name AS patient_name 
            FROM queue_entries q
            JOIN appointments a ON q.appointment_id = a.id
            JOIN users u ON a.patient_id = u.id
            WHERE q.id = :id
            FOR UPDATE
        ");
        $stmt->execute([':id' => $queueId]);
        $q = $stmt->fetch();

        if (!$q) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Queue entry not found.'];
        }

        $pdo->prepare("
            UPDATE queue_entries 
            SET status = 'skipped', updated_at = NOW() 
            WHERE id = :id
        ")->execute([':id' => $queueId]);

        logAudit(
            $actorUserId,
            'QUEUE_SKIPPED',
            'queue_entries',
            $queueId,
            "Skipped ticket {$q['queue_number']} ({$q['patient_name']}). Reason: {$reason}"
        );

        $pdo->commit();

        // Trigger our automated missed recovery algorithm so the patient immediately gets rescheduling options in their inbox!
        processMissedAppointment((int)$q['appointment_id'], $actorUserId);

        return ['success' => true, 'message' => "Ticket #{$q['queue_number']} skipped and rescheduled options sent to patient."];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Failed to skip ticket: ' . $e->getMessage()];
    }
}

/**
 * Returns today's live queue state for public waiting room boards and reception consoles.
 */
function getPublicQueueBoard(?string $date = null): array
{
    $pdo = getDBConnection();
    $date = $date ?? date('Y-m-d');

    // 1. Fetch all active doctors with consultation rooms
    $docStmt = $pdo->query("
        SELECT id, full_name, specialty, room_number 
        FROM doctors 
        WHERE active_status = 1 
        ORDER BY room_number ASC, full_name ASC
    ");
    $doctors = $docStmt->fetchAll();

    $rooms = [];

    foreach ($doctors as $doc) {
        $docId = (int)$doc['id'];

        // Get currently serving ticket for this doctor today
        $servingStmt = $pdo->prepare("
            SELECT q.id, q.queue_number, q.called_at, q.service_started_at,
                   TIMESTAMPDIFF(MINUTE, q.called_at, NOW()) AS minutes_in_service,
                   u.full_name AS patient_name
            FROM queue_entries q
            JOIN appointments a ON q.appointment_id = a.id
            JOIN users u ON a.patient_id = u.id
            WHERE a.doctor_id = :doc_id
              AND q.queue_date = :qdate
              AND q.status = 'in_service'
            ORDER BY q.called_at DESC
            LIMIT 1
        ");
        $servingStmt->execute([':doc_id' => $docId, ':qdate' => $date]);
        $serving = $servingStmt->fetch();

        // Get waiting tickets for this doctor today
        $waitStmt = $pdo->prepare("
            SELECT q.id, q.queue_number, a.start_time, q.checked_in_at,
                   TIMESTAMPDIFF(MINUTE, q.checked_in_at, NOW()) AS wait_minutes,
                   u.full_name AS patient_name
            FROM queue_entries q
            JOIN appointments a ON q.appointment_id = a.id
            JOIN users u ON a.patient_id = u.id
            WHERE a.doctor_id = :doc_id
              AND q.queue_date = :qdate
              AND q.status = 'waiting'
            ORDER BY a.start_time ASC, q.checked_in_at ASC
        ");
        $waitStmt->execute([':doc_id' => $docId, ':qdate' => $date]);
        $waiting = $waitStmt->fetchAll();

        // Get count completed today
        $compStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM queue_entries q
            JOIN appointments a ON q.appointment_id = a.id
            WHERE a.doctor_id = :doc_id
              AND q.queue_date = :qdate
              AND q.status = 'completed'
        ");
        $compStmt->execute([':doc_id' => $docId, ':qdate' => $date]);
        $completedCount = (int)$compStmt->fetchColumn();

        $rooms[] = [
            'doctor_id'        => $docId,
            'doctor_name'      => $doc['full_name'],
            'specialty'        => $doc['specialty'],
            'room_number'      => $doc['room_number'],
            'now_serving'      => $serving ?: null,
            'waiting_list'     => $waiting,
            'waiting_count'    => count($waiting),
            'completed_count'  => $completedCount,
            'estimated_wait'   => count($waiting) * 15 // ~15 mins per waiting patient
        ];
    }

    return $rooms;
}

/**
 * Returns the live queue position for a specific patient.
 * Used for mobile queue tracking on the Patient Portal.
 */
function getPatientQueueStatus(int $patientId, ?string $date = null): ?array
{
    $pdo = getDBConnection();
    $date = $date ?? date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT q.id AS queue_id, q.queue_number, q.status AS queue_status, q.checked_in_at, q.called_at,
               a.id AS appointment_id, a.booking_reference, a.appointment_date, a.start_time,
               d.id AS doctor_id, d.full_name AS doctor_name, d.specialty, d.room_number
        FROM queue_entries q
        JOIN appointments a ON q.appointment_id = a.id
        JOIN doctors d ON a.doctor_id = d.id
        WHERE a.patient_id = :pid
          AND q.queue_date = :qdate
          AND q.status IN ('waiting', 'in_service')
        ORDER BY q.id DESC
        LIMIT 1
    ");
    $stmt->execute([':pid' => $patientId, ':qdate' => $date]);
    $entry = $stmt->fetch();

    if (!$entry) {
        return null;
    }

    $docId = (int)$entry['doctor_id'];

    // Get current serving ticket for this doctor
    $servingStmt = $pdo->prepare("
        SELECT queue_number 
        FROM queue_entries q
        JOIN appointments a ON q.appointment_id = a.id
        WHERE a.doctor_id = :doc_id
          AND q.queue_date = :qdate
          AND q.status = 'in_service'
        LIMIT 1
    ");
    $servingStmt->execute([':doc_id' => $docId, ':qdate' => $date]);
    $currentServing = $servingStmt->fetchColumn() ?: 'None yet';

    // Count how many patients are ahead in line
    $aheadCount = 0;
    if ($entry['queue_status'] === 'waiting') {
        $aheadStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM queue_entries q
            JOIN appointments a ON q.appointment_id = a.id
            WHERE a.doctor_id = :doc_id
              AND q.queue_date = :qdate
              AND q.status = 'waiting'
              AND (a.start_time < :my_start OR (a.start_time = :my_start2 AND q.checked_in_at < :my_checkin))
        ");
        $aheadStmt->execute([
            ':doc_id'     => $docId,
            ':qdate'      => $date,
            ':my_start'   => $entry['start_time'],
            ':my_start2'  => $entry['start_time'],
            ':my_checkin' => $entry['checked_in_at']
        ]);
        $aheadCount = (int)$aheadStmt->fetchColumn();
    }

    return [
        'queue_id'         => (int)$entry['queue_id'],
        'queue_number'     => $entry['queue_number'],
        'queue_status'     => $entry['queue_status'],
        'checked_in_at'    => $entry['checked_in_at'],
        'called_at'        => $entry['called_at'],
        'booking_reference'=> $entry['booking_reference'],
        'start_time'       => $entry['start_time'],
        'doctor_name'      => $entry['doctor_name'],
        'specialty'        => $entry['specialty'],
        'room_number'      => $entry['room_number'],
        'current_serving'  => $currentServing,
        'patients_ahead'   => $aheadCount,
        'estimated_wait'   => ($aheadCount * 15) // ~15 mins per patient ahead
    ];
}

