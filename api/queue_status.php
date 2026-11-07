<?php
/**
 * =============================================================================
 * CityCare Hospital - Public Queue Status API
 * =============================================================================
 * 
 * Provides real-time queue feed for waiting room lobby displays, mobile tracking,
 * and audio chime triggers. Returns JSON.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/queue.php';

try {
    $date = isset($_GET['date']) ? trim((string)$_GET['date']) : date('Y-m-d');
    
    // Validate date format YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $rooms = getPublicQueueBoard($date);

    // Sanitize patient names for public privacy (e.g. "John D.")
    foreach ($rooms as &$room) {
        if ($room['now_serving'] && !empty($room['now_serving']['patient_name'])) {
            $nameParts = explode(' ', trim($room['now_serving']['patient_name']));
            $first = $nameParts[0] ?? '';
            $lastInitial = isset($nameParts[1]) ? mb_substr($nameParts[1], 0, 1) . '.' : '';
            $room['now_serving']['patient_display'] = trim($first . ' ' . $lastInitial);
            unset($room['now_serving']['patient_name']); // Never leak full name on public monitor
        }

        foreach ($room['waiting_list'] as &$wait) {
            if (!empty($wait['patient_name'])) {
                $nameParts = explode(' ', trim($wait['patient_name']));
                $first = $nameParts[0] ?? '';
                $lastInitial = isset($nameParts[1]) ? mb_substr($nameParts[1], 0, 1) . '.' : '';
                $wait['patient_display'] = trim($first . ' ' . $lastInitial);
                unset($wait['patient_name']);
            }
        }
    }
    unset($room);

    echo json_encode([
        'success'     => true,
        'server_time' => date('Y-m-d H:i:s'),
        'queue_date'  => $date,
        'rooms'       => $rooms
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch queue status: ' . $e->getMessage()
    ]);
}

