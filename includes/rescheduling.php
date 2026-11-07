<?php
/**
 * =============================================================================
 * CityCare Hospital - Missed Appointment Recovery & Rescheduling Algorithm
 * =============================================================================
 * 
 * Detects missed appointments, computes optimal upcoming consultation slots,
 * generates personalized in-app notifications with interactive options,
 * and executes instant 1-click patient rescheduling.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/audit.php';

/**
 * Intelligent Algorithm: Find the top recommended upcoming slots for a doctor.
 *
 * Scans the next 14 days, identifies available 20-minute consultation shifts,
 * and selects 3 diverse options (e.g., morning and afternoon across different days).
 *
 * @param int    $doctorId
 * @param string $startDate 'Y-m-d'
 * @param int    $maxOptions Default 3
 * @return array List of slot options with date, times, and human-readable labels
 */
function findRecommendedRescheduleSlots(int $doctorId, ?string $startDate = null, int $maxOptions = 3): array
{
    if ($startDate === null) {
        $startDate = date('Y-m-d', strtotime('+1 day'));
    }

    $recommended = [];
    $daysChecked = 0;
    $maxDaysToScan = 14;

    $currentDate = $startDate;

    while (count($recommended) < $maxOptions && $daysChecked < $maxDaysToScan) {
        $availability = getDoctorAvailableSlots($doctorId, $currentDate);

        if ($availability['success'] && !empty($availability['slots'])) {
            $availableSlots = array_values(array_filter($availability['slots'], function ($s) {
                return !empty($s['is_available']);
            }));

            if (!empty($availableSlots)) {
                // If we need a morning slot or afternoon slot, pick strategically
                // On first day, pick a mid-morning slot
                $slotCount = count($availableSlots);
                $pickIndex = 0;

                if (count($recommended) === 1 && $slotCount > 2) {
                    // Pick an afternoon slot for Option 2 if available
                    for ($i = 0; $i < $slotCount; $i++) {
                        if (strtotime($availableSlots[$i]['start_time']) >= strtotime('13:00:00')) {
                            $pickIndex = $i;
                            break;
                        }
                    }
                } elseif (count($recommended) === 2 && $slotCount > 1) {
                    $pickIndex = min(2, $slotCount - 1);
                }

                $chosenSlot = $availableSlots[$pickIndex];
                $dayLabel = date('l, M d', strtotime($currentDate));
                $optionNum = count($recommended) + 1;

                $recommended[] = [
                    'option_number'  => $optionNum,
                    'date'           => $currentDate,
                    'day_of_week'    => date('l', strtotime($currentDate)),
                    'formatted_date' => $dayLabel,
                    'start_time'     => $chosenSlot['start_time'],
                    'end_time'       => $chosenSlot['end_time'],
                    'display_time'   => $chosenSlot['display_time'],
                    'option_label'   => "Option {$optionNum}: {$dayLabel} at {$chosenSlot['display_time']}"
                ];
            }
        }

        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        $daysChecked++;
    }

    return $recommended;
}

/**
 * Process a missed appointment:
 * 1. Mark status as 'no_show'.
 * 2. Run rescheduling algorithm to generate optimal slot options.
 * 3. Send structured message into patient's in-app inbox.
 *
 * @param int         $appointmentId
 * @param string|null $customNote Optional note from clinic staff
 * @return array ['success' => bool, 'message' => string, 'suggested_slots' => array]
 */
function processMissedAppointment(int $appointmentId, ?string $customNote = null): array
{
    $pdo = getDBConnection();

    // 1. Fetch appointment details with doctor & patient
    $stmt = $pdo->prepare("
        SELECT 
            a.*,
            u.id AS patient_id,
            u.full_name AS patient_name,
            u.email AS patient_email,
            d.id AS doctor_id,
            d.full_name AS doctor_name,
            d.specialty AS doctor_specialty,
            d.room_number
        FROM appointments a
        JOIN users u ON a.patient_id = u.id
        JOIN doctors d ON a.doctor_id = d.id
        WHERE a.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $appointmentId]);
    $app = $stmt->fetch();

    if (!$app) {
        return ['success' => false, 'message' => 'Appointment record not found.', 'suggested_slots' => []];
    }

    // 2. Mark appointment status as 'no_show'
    $upStmt = $pdo->prepare("
        UPDATE appointments 
        SET status = 'no_show', updated_at = NOW() 
        WHERE id = :id
    ");
    $upStmt->execute([':id' => $appointmentId]);

    // Also cancel any open queue ticket
    $qStmt = $pdo->prepare("
        UPDATE queue_entries 
        SET status = 'skipped', updated_at = NOW() 
        WHERE appointment_id = :id AND status IN ('waiting', 'in_service')
    ");
    $qStmt->execute([':id' => $appointmentId]);

    // 3. Compute recommended replacement slots
    $suggestedSlots = findRecommendedRescheduleSlots((int)$app['doctor_id'], date('Y-m-d', strtotime('+1 day')), 3);

    // 4. Construct humanized message body with options
    $formattedMissedDate = date('l, M d, Y', strtotime($app['appointment_date']));
    $formattedMissedTime = date('h:i A', strtotime($app['start_time']));
    $docDisplayName = (strpos($app['doctor_name'], 'Dr.') === 0) ? $app['doctor_name'] : 'Dr. ' . $app['doctor_name'];

    $title = "Missed Consultation: Reschedule with {$docDisplayName}";

    $messageBody = "Dear {$app['patient_name']},\n\n";
    $messageBody .= "We noticed you were unable to attend your scheduled consultation with {$docDisplayName} ({$app['doctor_specialty']}) on {$formattedMissedDate} at {$formattedMissedTime} (Ref: {$app['booking_reference']}).\n\n";
    $messageBody .= "Your health and continuous care are very important to us! To help you recover this consultation without hassle, our automated scheduling engine has found 3 recommended time slots:\n\n";

    if (!empty($suggestedSlots)) {
        foreach ($suggestedSlots as $opt) {
            $messageBody .= "• {$opt['option_label']}\n";
        }
        $messageBody .= "\nPlease select one of the buttons below to confirm your new appointment with a single click, or choose a custom date.";
    } else {
        $messageBody .= "{$docDisplayName} currently has limited open shifts. Please browse our calendar to select a future date that suits you.";
    }

    if (!empty($customNote)) {
        $messageBody .= "\n\nNote from Clinic Reception: " . trim($customNote);
    }

    // 5. Store in patient_messages
    try {
        $insMsg = $pdo->prepare("
            INSERT INTO patient_messages (
                patient_id,
                appointment_id,
                sender_role,
                title,
                message,
                message_type,
                suggested_slots_json,
                is_read,
                is_resolved,
                created_at
            ) VALUES (
                :patient_id,
                :appointment_id,
                'system',
                :title,
                :message,
                'missed_appointment',
                :slots_json,
                0,
                0,
                NOW()
            )
        ");

        $insMsg->execute([
            ':patient_id'     => $app['patient_id'],
            ':appointment_id' => $appointmentId,
            ':title'          => $title,
            ':message'        => $messageBody,
            ':slots_json'     => !empty($suggestedSlots) ? json_encode($suggestedSlots) : null
        ]);

        $messageId = (int)$pdo->lastInsertId();

        logAuditEvent(
            (int)$app['patient_id'],
            'APPOINTMENT_MISSED',
            'appointments',
            $appointmentId,
            "Appointment #{$appointmentId} marked no-show. Sent reschedule notification Message #{$messageId} with " . count($suggestedSlots) . " suggested options"
        );

        return [
            'success'         => true,
            'message'         => 'Appointment marked as missed. Reschedule message with 3 options has been dispatched to the patient.',
            'message_id'      => $messageId,
            'suggested_slots' => $suggestedSlots
        ];
    } catch (Exception $e) {
        error_log('[CityCare Reschedule Engine Error] ' . $e->getMessage());
        return [
            'success'         => false,
            'message'         => 'Could not record reschedule message: ' . $e->getMessage(),
            'suggested_slots' => $suggestedSlots
        ];
    }
}

/**
 * Execute 1-Click Patient Rescheduling from an accepted slot option.
 *
 * @param int    $messageId
 * @param int    $patientId
 * @param string $newDate 'Y-m-d'
 * @param string $newStartTime 'H:i:s'
 * @return array ['success' => bool, 'message' => string, 'new_booking_reference' => string|null]
 */
function acceptRescheduleOption(int $messageId, int $patientId, string $newDate, string $newStartTime): array
{
    $pdo = getDBConnection();

    // 1. Fetch the message and ensure ownership
    $stmt = $pdo->prepare("
        SELECT m.*, a.doctor_id, a.booking_reference AS old_ref
        FROM patient_messages m
        JOIN appointments a ON m.appointment_id = a.id
        WHERE m.id = :id AND m.patient_id = :pat_id
        LIMIT 1
    ");
    $stmt->execute([':id' => $messageId, ':pat_id' => $patientId]);
    $msg = $stmt->fetch();

    if (!$msg) {
        return ['success' => false, 'message' => 'Reschedule offer not found or already processed.'];
    }

    if ((int)$msg['is_resolved'] === 1) {
        return ['success' => false, 'message' => 'This rescheduling request has already been completed.'];
    }

    $doctorId = (int)$msg['doctor_id'];

    // 2. Attempt to book the new slot
    $bookingResult = createAppointment($patientId, $doctorId, $newDate, $newStartTime);

    if (!$bookingResult['success']) {
        return [
            'success' => false,
            'message' => 'The selected option is no longer available (' . $bookingResult['message'] . '). Please choose another option or pick a different date.'
        ];
    }

    // 3. Mark message as read and resolved
    $newRef = $bookingResult['booking_reference'];
    $upMsg = $pdo->prepare("
        UPDATE patient_messages 
        SET is_read = 1, is_resolved = 1, updated_at = NOW() 
        WHERE id = :id
    ");
    $upMsg->execute([':id' => $messageId]);

    logAuditEvent(
        $patientId,
        'APPOINTMENT_RESCHEDULED',
        'appointments',
        (int)$bookingResult['appointment_id'],
        "Patient rescheduled missed appointment from {$msg['old_ref']} to new booking {$newRef} on {$newDate} at {$newStartTime}"
    );

    return [
        'success'               => true,
        'message'               => "Your consultation has been rescheduled! New booking reference: {$newRef}",
        'new_booking_reference' => $newRef
    ];
}

/**
 * Retrieve patient messages with unread count.
 */
function getPatientMessages(int $patientId): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT m.*, a.booking_reference, d.full_name AS doctor_name, d.specialty AS doctor_specialty
        FROM patient_messages m
        LEFT JOIN appointments a ON m.appointment_id = a.id
        LEFT JOIN doctors d ON a.doctor_id = d.id
        WHERE m.patient_id = :pat_id
        ORDER BY m.id DESC
    ");
    $stmt->execute([':pat_id' => $patientId]);
    return $stmt->fetchAll();
}

/**
 * Get count of unread messages for patient badge indicator.
 */
function getUnreadMessageCount(int $patientId): int
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM patient_messages 
        WHERE patient_id = :pat_id AND is_read = 0
    ");
    $stmt->execute([':pat_id' => $patientId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Automated batch job: Scans all past appointments that were unfulfilled and marks them no_show.
 *
 * @return int Number of missed appointments detected and processed
 */
function autoDetectAndProcessPastMissedAppointments(): int
{
    $pdo = getDBConnection();
    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    // Find past appointments with status 'pending' or 'confirmed'
    $stmt = $pdo->prepare("
        SELECT id FROM appointments 
        WHERE status IN ('pending', 'confirmed')
          AND (appointment_date < :today OR (appointment_date = :today AND end_time < :now_time))
    ");
    $stmt->execute([':today' => $today, ':now_time' => $nowTime]);
    $missedApps = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $processedCount = 0;
    foreach ($missedApps as $appId) {
        $res = processMissedAppointment((int)$appId, "Auto-detected by clinic scheduling monitor.");
        if ($res['success']) {
            $processedCount++;
        }
    }

    return $processedCount;
}
