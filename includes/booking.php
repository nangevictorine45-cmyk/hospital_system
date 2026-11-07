<?php
/**
 * =============================================================================
 * CityCare Hospital - Appointment Scheduling & Slot Engine
 * =============================================================================
 * 
 * Core engine managing doctor schedules, dynamic slot calculation, booking
 * conflict prevention, and cancellation policy enforcement.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';

/**
 * Retrieve current clinic operating settings.
 */
function getClinicSettings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT * FROM `clinic_settings` LIMIT 1");
    $settings = $stmt->fetch() ?: [
        'clinic_name'                  => 'CityCare Hospital',
        'address'                      => '123 Healthcare Boulevard',
        'phone'                        => '+1 (555) 019-2834',
        'email'                        => 'info@citycare.local',
        'timezone'                     => 'UTC',
        'opening_time'                 => '08:00:00',
        'closing_time'                 => '17:00:00',
        'max_advance_booking_days'     => 30,
        'min_cancellation_hours_notice'=> 2,
    ];

    return $settings;
}

/**
 * Fetch all doctors, optionally filtered by medical specialty.
 */
function getDoctors(bool $activeOnly = true, ?string $specialty = null): array
{
    $pdo = getDBConnection();
    $sql = "SELECT id, full_name, specialty, email, phone, room_number, description, active_status FROM doctors WHERE 1=1";
    $params = [];

    if ($activeOnly) {
        $sql .= " AND active_status = 1";
    }

    if ($specialty !== null && $specialty !== '') {
        $sql .= " AND specialty = :specialty";
        $params[':specialty'] = $specialty;
    }

    $sql .= " ORDER BY specialty ASC, full_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Retrieve single doctor by ID.
 */
function getDoctorById(int $doctorId): ?array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM doctors WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $doctorId]);
    $doc = $stmt->fetch();
    return $doc ?: null;
}

/**
 * Retrieve a doctor's weekly active shift schedules.
 */
function getDoctorWeeklySchedules(int $doctorId): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT id, day_of_week, start_time, end_time, slot_duration_minutes, active_status
        FROM doctor_schedules
        WHERE doctor_id = :doc_id AND active_status = 1
        ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time ASC
    ");
    $stmt->execute([':doc_id' => $doctorId]);
    return $stmt->fetchAll();
}

/**
 * Calculate available consultation slots for a doctor on a specific date.
 *
 * @param int    $doctorId
 * @param string $dateString Date in 'Y-m-d' format
 * @return array ['success' => bool, 'message' => string, 'slots' => array]
 */
function getDoctorAvailableSlots(int $doctorId, string $dateString): array
{
    $settings = getClinicSettings();
    $pdo = getDBConnection();

    // 1. Validate Date format
    $dt = DateTime::createFromFormat('Y-m-d', $dateString);
    if (!$dt || $dt->format('Y-m-d') !== $dateString) {
        return ['success' => false, 'message' => 'Invalid calendar date format (expected YYYY-MM-DD).', 'slots' => []];
    }

    $today = date('Y-m-d');
    if ($dateString < $today) {
        return ['success' => false, 'message' => 'Cannot book appointments for past dates.', 'slots' => []];
    }

    // Check maximum advance booking policy
    $maxDays = (int)$settings['max_advance_booking_days'];
    $maxDate = date('Y-m-d', strtotime("+{$maxDays} days"));
    if ($dateString > $maxDate) {
        return ['success' => false, 'message' => "Bookings are only accepted up to {$maxDays} days in advance.", 'slots' => []];
    }

    // 2. Fetch doctor
    $doctor = getDoctorById($doctorId);
    if (!$doctor || (int)$doctor['active_status'] !== 1) {
        return ['success' => false, 'message' => 'Doctor not found or currently unavailable.', 'slots' => []];
    }

    // 3. Find schedules for this day of the week
    $dayOfWeek = date('l', strtotime($dateString)); // e.g. "Monday"
    $schedStmt = $pdo->prepare("
        SELECT id, start_time, end_time, slot_duration_minutes
        FROM doctor_schedules
        WHERE doctor_id = :doc_id 
          AND day_of_week = :day_name 
          AND active_status = 1
        ORDER BY start_time ASC
    ");
    $schedStmt->execute([':doc_id' => $doctorId, ':day_name' => $dayOfWeek]);
    $shifts = $schedStmt->fetchAll();

    if (empty($shifts)) {
        return [
            'success' => true,
            'message' => "Dr. {$doctor['full_name']} has no scheduled consultation hours on {$dayOfWeek}s.",
            'slots'   => [],
            'doctor'  => $doctor,
            'day'     => $dayOfWeek
        ];
    }

    // 4. Retrieve existing booked appointments for that doctor on this date
    $bookedStmt = $pdo->prepare("
        SELECT start_time 
        FROM appointments 
        WHERE doctor_id = :doc_id 
          AND appointment_date = :app_date 
          AND status NOT IN ('cancelled')
    ");
    $bookedStmt->execute([':doc_id' => $doctorId, ':app_date' => $dateString]);
    $bookedTimes = $bookedStmt->fetchAll(PDO::FETCH_COLUMN);

    // Normalize booked times to 'H:i'
    $bookedMap = [];
    foreach ($bookedTimes as $bt) {
        $bookedMap[substr((string)$bt, 0, 5)] = true;
    }

    // 5. Generate time slots
    $generatedSlots = [];
    $currentTime = date('H:i:s');

    foreach ($shifts as $shift) {
        $durationMinutes = (int)($shift['slot_duration_minutes'] ?: 20);
        $durationSeconds = $durationMinutes * 60;

        $startTimeSec = strtotime($dateString . ' ' . $shift['start_time']);
        $endTimeSec   = strtotime($dateString . ' ' . $shift['end_time']);

        for ($t = $startTimeSec; $t + $durationSeconds <= $endTimeSec; $t += $durationSeconds) {
            $slotStart = date('H:i:s', $t);
            $slotEnd   = date('H:i:s', $t + $durationSeconds);
            $slotKey   = substr($slotStart, 0, 5);

            $isBooked = isset($bookedMap[$slotKey]);
            $isPast   = ($dateString === $today && $slotStart <= $currentTime);

            $isAvailable = (!$isBooked && !$isPast);

            $generatedSlots[] = [
                'start_time'   => $slotStart,
                'end_time'     => $slotEnd,
                'display_time' => date('h:i A', $t),
                'is_available' => $isAvailable,
                'is_booked'    => $isBooked,
                'is_past'      => $isPast
            ];
        }
    }

    return [
        'success' => true,
        'message' => sprintf('Found %d slots for %s (%s).', count($generatedSlots), $doctor['full_name'], $dayOfWeek),
        'slots'   => $generatedSlots,
        'doctor'  => $doctor,
        'day'     => $dayOfWeek
    ];
}

/**
 * Reserve an appointment with double-booking prevention and transaction safety.
 *
 * @param int    $patientId
 * @param int    $doctorId
 * @param string $appointmentDate Format 'Y-m-d'
 * @param string $startTime       Format 'H:i:s' or 'H:i'
 * @return array ['success' => bool, 'message' => string, 'booking_reference' => string|null, 'appointment_id' => int|null]
 */
function createAppointment(int $patientId, int $doctorId, string $appointmentDate, string $startTime): array
{
    $pdo = getDBConnection();

    // Standardize time to H:i:s
    if (strlen($startTime) === 5) {
        $startTime .= ':00';
    }

    // 1. Validate Doctor availability
    $availability = getDoctorAvailableSlots($doctorId, $appointmentDate);
    if (!$availability['success'] || empty($availability['slots'])) {
        return ['success' => false, 'message' => $availability['message'] ?: 'No slots available for this doctor and date.'];
    }

    // Check if the requested start time is among the available slots
    $matchedSlot = null;
    foreach ($availability['slots'] as $slot) {
        if ($slot['start_time'] === $startTime) {
            $matchedSlot = $slot;
            break;
        }
    }

    if (!$matchedSlot) {
        return ['success' => false, 'message' => 'The selected consultation time slot is not recognized for this doctor shift.'];
    }

    if (!$matchedSlot['is_available']) {
        if ($matchedSlot['is_booked']) {
            return ['success' => false, 'message' => 'This slot was just reserved by another patient. Please select a different time.'];
        }
        if ($matchedSlot['is_past']) {
            return ['success' => false, 'message' => 'This consultation time has already passed today.'];
        }
    }

    $endTime = $matchedSlot['end_time'];

    // 2. Prevent same patient booking two appointments at the exact same date & time
    $patConflict = $pdo->prepare("
        SELECT id FROM appointments 
        WHERE patient_id = :patient_id 
          AND appointment_date = :app_date 
          AND start_time = :start_time 
          AND status NOT IN ('cancelled')
        LIMIT 1
    ");
    $patConflict->execute([
        ':patient_id'  => $patientId,
        ':app_date'    => $appointmentDate,
        ':start_time'  => $startTime
    ]);
    if ($patConflict->fetch()) {
        return ['success' => false, 'message' => 'You already have an active appointment scheduled at this time.'];
    }

    // 3. Generate unique Booking Reference: CC-YYYYMMDD-XXXX
    $dateCompact = date('Ymd', strtotime($appointmentDate));
    $randomCode = strtoupper(bin2hex(random_bytes(2))); // 4 chars
    $bookingReference = "CC-{$dateCompact}-{$randomCode}";

    // 4. Insert appointment inside database transaction with conflict guard
    try {
        $pdo->beginTransaction();

        // Lock check: ensure no concurrent process just booked this slot
        $lockStmt = $pdo->prepare("
            SELECT id FROM appointments
            WHERE doctor_id = :doc_id
              AND appointment_date = :app_date
              AND start_time = :start_time
              AND status NOT IN ('cancelled')
            FOR UPDATE
        ");
        $lockStmt->execute([
            ':doc_id'     => $doctorId,
            ':app_date'   => $appointmentDate,
            ':start_time' => $startTime
        ]);
        if ($lockStmt->fetch()) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'This time slot was just booked by another user. Please choose another slot.'];
        }

        // Insert new appointment
        $insStmt = $pdo->prepare("
            INSERT INTO appointments (
                booking_reference,
                patient_id,
                doctor_id,
                appointment_date,
                start_time,
                end_time,
                status,
                created_at
            ) VALUES (
                :ref,
                :patient_id,
                :doctor_id,
                :appointment_date,
                :start_time,
                :end_time,
                'confirmed',
                NOW()
            )
        ");

        $insStmt->execute([
            ':ref'              => $bookingReference,
            ':patient_id'       => $patientId,
            ':doctor_id'        => $doctorId,
            ':appointment_date' => $appointmentDate,
            ':start_time'       => $startTime,
            ':end_time'         => $endTime
        ]);

        $appointmentId = (int)$pdo->lastInsertId();

        $pdo->commit();

        logAuditEvent(
            $patientId,
            'APPOINTMENT_CREATE',
            'appointments',
            $appointmentId,
            "Appointment #{$appointmentId} ({$bookingReference}) booked with Doctor #{$doctorId} on {$appointmentDate} at {$startTime}"
        );

        return [
            'success'           => true,
            'message'           => 'Your appointment has been successfully scheduled!',
            'booking_reference' => $bookingReference,
            'appointment_id'    => $appointmentId
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CityCare Booking Error] ' . $e->getMessage());
        return ['success' => false, 'message' => 'A system error occurred while securing your appointment. Please try again.'];
    }
}

/**
 * Cancel an appointment with cancellation policy notice window verification.
 */
function cancelAppointment(int $appointmentId, int $userId, string $userRole): array
{
    $pdo = getDBConnection();
    $settings = getClinicSettings();

    $stmt = $pdo->prepare("
        SELECT a.*, d.full_name AS doctor_name 
        FROM appointments a
        JOIN doctors d ON a.doctor_id = d.id
        WHERE a.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $appointmentId]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        return ['success' => false, 'message' => 'Appointment record not found.'];
    }

    if ($appointment['status'] === 'cancelled') {
        return ['success' => false, 'message' => 'This appointment is already cancelled.'];
    }

    if ($appointment['status'] === 'completed') {
        return ['success' => false, 'message' => 'Completed appointments cannot be cancelled.'];
    }

    // Role Ownership check: Patients can only cancel their own appointments
    if ($userRole === 'patient' && (int)$appointment['patient_id'] !== $userId) {
        return ['success' => false, 'message' => 'You are not authorized to cancel this appointment.'];
    }

    // Check cancellation notice policy for patients
    if ($userRole === 'patient') {
        $appTimestamp = strtotime($appointment['appointment_date'] . ' ' . $appointment['start_time']);
        $now = time();
        $hoursNotice = ($appTimestamp - $now) / 3600;
        $minHours = (int)$settings['min_cancellation_hours_notice'];

        if ($hoursNotice < $minHours) {
            return [
                'success' => false,
                'message' => "Appointments cannot be cancelled less than {$minHours} hours prior to consultation. Please contact clinic reception directly."
            ];
        }
    }

    // Update appointment status to 'cancelled'
    try {
        $pdo->beginTransaction();

        $upStmt = $pdo->prepare("
            UPDATE appointments 
            SET status = 'cancelled', updated_at = NOW() 
            WHERE id = :id
        ");
        $upStmt->execute([':id' => $appointmentId]);

        // Cancel any existing linked queue entry
        $qStmt = $pdo->prepare("
            UPDATE queue_entries 
            SET status = 'cancelled', updated_at = NOW() 
            WHERE appointment_id = :id AND status = 'waiting'
        ");
        $qStmt->execute([':id' => $appointmentId]);

        $pdo->commit();

        logAuditEvent(
            $userId,
            'APPOINTMENT_CANCEL',
            'appointments',
            $appointmentId,
            "Appointment #{$appointmentId} ({$appointment['booking_reference']}) cancelled by {$userRole} #{$userId}"
        );

        return ['success' => true, 'message' => 'Appointment has been successfully cancelled.'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CityCare Cancellation Error] ' . $e->getMessage());
        return ['success' => false, 'message' => 'Could not cancel appointment due to a server error.'];
    }
}

