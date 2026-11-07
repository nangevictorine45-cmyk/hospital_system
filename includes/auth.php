<?php
/**
 * =============================================================================
 * CityCare Hospital - Authentication & Access Control Engine
 * =============================================================================
 * 
 * Manages user sessions, authentication, patient registration, CSRF protection,
 * and Role-Based Access Control (RBAC) across patient, staff, and admin portals.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit.php';

/**
 * Initialize PHP session with security hardening cookies.
 */
function initSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        session_set_cookie_params([
            'lifetime' => 0,              // Session expires when browser is closed
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,       // Only send cookie over HTTPS if available
            'httponly' => true,           // Block JavaScript document.cookie access (Mitigates XSS)
            'samesite' => 'Lax'           // Protect against CSRF
        ]);

        session_start();
    }
}

// Automatically initialize session whenever auth.php is included
initSession();

// =============================================================================
// CSRF TOKEN HELPERS
// =============================================================================

/**
 * Get current CSRF token or create one if none exists in session.
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Generate hidden HTML input tag containing the CSRF token.
 */
function csrfField(): string
{
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Verify submitted CSRF token against session token.
 */
function verifyCsrfToken(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// =============================================================================
// FLASH MESSAGE HELPERS
// =============================================================================

/**
 * Add a temporary flash message for display on the next page load.
 *
 * @param string $type    'success', 'danger', 'info', or 'warning'
 * @param string $message Flash message text
 */
function setFlash(string $type, string $message): void
{
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][] = [
        'type'    => $type,
        'message' => $message
    ];
}

/**
 * Retrieve and clear all pending flash messages.
 *
 * @return array List of ['type' => ..., 'message' => ...]
 */
function getFlashes(): array
{
    $flashes = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $flashes;
}

// =============================================================================
// USER STATE & ROLE-BASED ACCESS CONTROL (RBAC)
// =============================================================================

/**
 * Check if a user is currently logged in.
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user']['id']);
}

/**
 * Get current authenticated user session data.
 */
function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Check if the authenticated user has one of the required roles.
 *
 * @param string|array $roles Single role or array of allowed roles
 */
function hasRole($roles): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    $userRole = $_SESSION['user']['role'] ?? '';
    if (is_array($roles)) {
        return in_array($userRole, $roles, true);
    }

    return $userRole === $roles;
}

/**
 * Get default portal dashboard URL based on user role.
 */
function getDashboardUrl(?string $role = null): string
{
    if ($role === null && isLoggedIn()) {
        $role = $_SESSION['user']['role'] ?? '';
    }

    $baseUrl = defined('APP_BASE_URL') ? APP_BASE_URL : '/hospital_system';

    switch ($role) {
        case 'admin':
            return $baseUrl . '/admin/dashboard.php';
        case 'staff':
            return $baseUrl . '/staff/dashboard.php';
        case 'patient':
            return $baseUrl . '/patient/dashboard.php';
        default:
            return $baseUrl . '/index.php';
    }
}

/**
 * Enforce authentication and role permissions on protected routes.
 *
 * @param array|string $allowedRoles Empty array permits any logged-in user.
 */
function requireLogin($allowedRoles = []): void
{
    $baseUrl = defined('APP_BASE_URL') ? APP_BASE_URL : '/hospital_system';

    // 1. Check if user is logged in
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        setFlash('warning', 'Please sign in to access this page.');
        header('Location: ' . $baseUrl . '/login.php');
        exit;
    }

    // 2. If specific roles are required, check role membership
    if (!empty($allowedRoles)) {
        if (!hasRole($allowedRoles)) {
            // User is logged in but lacks required permission
            header('Location: ' . $baseUrl . '/403.php');
            exit;
        }
    }
}

// =============================================================================
// AUTHENTICATION LOGIC: LOGIN, REGISTRATION, LOGOUT
// =============================================================================

/**
 * Attempt user authentication with email and password.
 *
 * @param string $email
 * @param string $password
 * @return array ['success' => bool, 'message' => string, 'redirect' => string|null]
 */
function attemptLogin(string $email, string $password): array
{
    $email = trim(filter_var($email, FILTER_SANITIZE_EMAIL));

    if (empty($email) || empty($password)) {
        return [
            'success'  => false,
            'message'  => 'Please enter both your email address and password.',
            'redirect' => null
        ];
    }

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT `id`, `full_name`, `email`, `phone`, `password_hash`, `role`, `status`
        FROM `users`
        WHERE `email` = :email
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        logAuditEvent(null, 'LOGIN_FAILED', 'users', null, "Failed login: Unknown email '{$email}'");
        return [
            'success'  => false,
            'message'  => 'Invalid email or password. Please try again.',
            'redirect' => null
        ];
    }

    if ($user['status'] !== 'active') {
        logAuditEvent((int)$user['id'], 'LOGIN_BLOCKED', 'users', (int)$user['id'], "Account inactive or suspended");
        return [
            'success'  => false,
            'message'  => 'Your account is currently inactive. Please contact clinic administration.',
            'redirect' => null
        ];
    }

    if (!password_verify($password, $user['password_hash'])) {
        logAuditEvent((int)$user['id'], 'LOGIN_FAILED', 'users', (int)$user['id'], "Failed login: Incorrect password");
        return [
            'success'  => false,
            'message'  => 'Invalid email or password. Please try again.',
            'redirect' => null
        ];
    }

    // Authentication Successful!
    // Regenerate session ID to mitigate session fixation attacks
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'        => (int)$user['id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'phone'     => $user['phone'],
        'role'      => $user['role']
    ];

    logAuditEvent((int)$user['id'], 'LOGIN_SUCCESS', 'users', (int)$user['id'], "User successfully signed in as {$user['role']}");

    // Determine redirect destination
    $redirect = $_SESSION['redirect_after_login'] ?? getDashboardUrl($user['role']);
    unset($_SESSION['redirect_after_login']);

    return [
        'success'  => true,
        'message'  => 'Welcome back, ' . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') . '!',
        'redirect' => $redirect
    ];
}

/**
 * Register a new patient account with atomic database transaction.
 *
 * @param array $data Form fields
 * @return array ['success' => bool, 'message' => string, 'errors' => array, 'user_id' => int|null]
 */
function registerPatient(array $data): array
{
    $pdo = getDBConnection();
    $errors = [];

    // Sanitize and extract basic user fields
    $fullName        = trim((string)($data['full_name'] ?? ''));
    $email           = trim(filter_var((string)($data['email'] ?? ''), FILTER_SANITIZE_EMAIL));
    $phone           = trim((string)($data['phone'] ?? ''));
    $password        = (string)($data['password'] ?? '');
    $confirmPassword = (string)($data['confirm_password'] ?? '');

    // Profile fields
    $dob             = !empty($data['date_of_birth']) ? trim((string)$data['date_of_birth']) : null;
    $gender          = !empty($data['gender']) ? trim((string)$data['gender']) : null;
    $address         = !empty($data['address']) ? trim((string)$data['address']) : null;
    $emergencyName   = !empty($data['emergency_contact_name']) ? trim((string)$data['emergency_contact_name']) : null;
    $emergencyPhone  = !empty($data['emergency_contact_phone']) ? trim((string)$data['emergency_contact_phone']) : null;

    // Validation
    if (strlen($fullName) < 3) {
        $errors['full_name'] = 'Full name must be at least 3 characters.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please provide a valid email address.';
    } else {
        // Check uniqueness
        $checkStmt = $pdo->prepare("SELECT `id` FROM `users` WHERE `email` = :email LIMIT 1");
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            $errors['email'] = 'An account with this email address already exists.';
        }
    }

    if (strlen($phone) < 7) {
        $errors['phone'] = 'Please provide a valid phone number (at least 7 digits).';
    }

    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirmPassword) {
        $errors['confirm_password'] = 'Password confirmation does not match.';
    }

    if ($gender !== null && !in_array($gender, ['male', 'female', 'other'], true)) {
        $errors['gender'] = 'Please select a valid gender option.';
    }

    if (!empty($errors)) {
        return [
            'success' => false,
            'message' => 'Please resolve the highlighted validation errors.',
            'errors'  => $errors,
            'user_id' => null
        ];
    }

    // Begin atomic transaction to ensure both user and patient profile are inserted
    try {
        $pdo->beginTransaction();

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

        $userStmt = $pdo->prepare("
            INSERT INTO `users` (
                `full_name`,
                `email`,
                `phone`,
                `password_hash`,
                `role`,
                `status`,
                `created_at`
            ) VALUES (
                :full_name,
                :email,
                :phone,
                :password_hash,
                'patient',
                'active',
                NOW()
            )
        ");

        $userStmt->execute([
            ':full_name'     => $fullName,
            ':email'         => $email,
            ':phone'         => $phone,
            ':password_hash' => $passwordHash,
        ]);

        $userId = (int)$pdo->lastInsertId();

        $profileStmt = $pdo->prepare("
            INSERT INTO `patient_profiles` (
                `user_id`,
                `date_of_birth`,
                `gender`,
                `address`,
                `emergency_contact_name`,
                `emergency_contact_phone`,
                `created_at`
            ) VALUES (
                :user_id,
                :date_of_birth,
                :gender,
                :address,
                :emergency_contact_name,
                :emergency_contact_phone,
                NOW()
            )
        ");

        $profileStmt->execute([
            ':user_id'                 => $userId,
            ':date_of_birth'           => $dob,
            ':gender'                  => $gender,
            ':address'                 => $address,
            ':emergency_contact_name'  => $emergencyName,
            ':emergency_contact_phone' => $emergencyPhone,
        ]);

        $pdo->commit();

        logAuditEvent($userId, 'REGISTER', 'users', $userId, "New patient account registered for {$fullName} ({$email})");

        return [
            'success' => true,
            'message' => 'Your patient account has been created successfully! You can now log in.',
            'errors'  => [],
            'user_id' => $userId
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CityCare Registration Error] ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An unexpected server error occurred during registration. Please try again.',
            'errors'  => ['general' => $e->getMessage()],
            'user_id' => null
        ];
    }
}

/**
 * Securely terminate user session and clear authentication artifacts.
 */
function performLogout(): void
{
    if (isLoggedIn()) {
        $user = currentUser();
        logAuditEvent((int)$user['id'], 'LOGOUT', 'users', (int)$user['id'], "User signed out.");
    }

    // Clear session data
    $_SESSION = [];

    // Invalidate session cookie in browser
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

