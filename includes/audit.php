<?php
/**
 * =============================================================================
 * CityCare Hospital - Audit Logging Helper
 * =============================================================================
 * 
 * Provides centralized recording of security events, administrative changes,
 * authentication attempts, and critical patient lifecycle actions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Log a security, authentication, or business event into audit_logs.
 *
 * @param int|null    $actorUserId  The user ID performing the action, or null for anonymous/guest/system
 * @param string      $action       Short action code (e.g. 'LOGIN_SUCCESS', 'REGISTER', 'APPOINTMENT_CREATE')
 * @param string      $entityType   Target entity (e.g. 'users', 'appointments', 'queue')
 * @param int|null    $entityId     Target entity ID, or null
 * @param string      $description  Human-readable description of the event
 * @param string|null $ipAddress    Client IP address (auto-detected if null)
 * @return bool True if logged successfully, false otherwise
 */
function logAuditEvent(
    ?int $actorUserId,
    string $action,
    string $entityType,
    ?int $entityId,
    string $description,
    ?string $ipAddress = null
): bool {
    try {
        $pdo = getDBConnection();

        if ($ipAddress === null) {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            // Trim to fit VARCHAR(45) IPv6/IPv4 field
            $ipAddress = substr((string)$ipAddress, 0, 45);
        }

        $stmt = $pdo->prepare("
            INSERT INTO `audit_logs` (
                `actor_user_id`,
                `action`,
                `entity_type`,
                `entity_id`,
                `description`,
                `ip_address`,
                `created_at`
            ) VALUES (
                :actor_user_id,
                :action,
                :entity_type,
                :entity_id,
                :description,
                :ip_address,
                NOW()
            )
        ");

        return $stmt->execute([
            ':actor_user_id' => $actorUserId,
            ':action'        => substr($action, 0, 50),
            ':entity_type'   => substr($entityType, 0, 50),
            ':entity_id'     => $entityId,
            ':description'   => $description,
            ':ip_address'    => $ipAddress,
        ]);
    } catch (Exception $e) {
        // Audit logging failure should not crash application flow, but must be logged to error_log
        error_log('[CityCare Audit Log Failure] ' . $e->getMessage());
        return false;
    }
}

