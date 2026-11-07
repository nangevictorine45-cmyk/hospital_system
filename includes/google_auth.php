<?php
/**
 * =============================================================================
 * CityCare Hospital - Google OAuth Authentication Engine
 * =============================================================================
 * 
 * Manages Google Sign-In, token exchange, user identity synchronization,
 * and auto-provisioning of patient accounts and medical profiles.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/google.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';

/**
 * Generate the Google authorization entry URL.
 * Routes to live Google OAuth 2.0 when credentials exist, or to local Sandbox.
 */
function getGoogleLoginUrl(): string
{
    initSession();

    if (isGoogleAuthConfigured()) {
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;

        $params = [
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account'
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    // Fallback to local Google Sandbox / Evaluation Mode
    return (defined('APP_BASE_URL') ? APP_BASE_URL : '/hospital_system') . '/google_auth.php';
}

/**
 * Exchange Google authorization code for access and ID tokens via cURL.
 */
function exchangeGoogleCode(string $code): ?array
{
    $tokenEndpoint = 'https://oauth2.googleapis.com/token';

    $postData = [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code'
    ];

    $ch = curl_init($tokenEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200 || !$response) {
        error_log('[CityCare Google OAuth] Token exchange failed. HTTP: ' . $httpCode . ' Error: ' . $curlError);
        return null;
    }

    $data = json_decode((string)$response, true);
    return is_array($data) ? $data : null;
}

/**
 * Retrieve user profile info from Google's UserInfo API endpoint.
 */
function fetchGoogleUserInfo(string $accessToken): ?array
{
    $userInfoEndpoint = 'https://www.googleapis.com/oauth2/v3/userinfo';

    $ch = curl_init($userInfoEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $data = json_decode((string)$response, true);
    return is_array($data) ? $data : null;
}

/**
 * Process a verified Google profile: authenticate existing patient or auto-provision a new one.
 *
 * @param array $googleProfile ['sub' => string, 'email' => string, 'name' => string, 'picture' => ?string]
 * @return array ['success' => bool, 'message' => string, 'redirect' => string, 'user' => array|null]
 */
function handleGoogleUser(array $googleProfile): array
{
    $pdo = getDBConnection();

    $email = trim(filter_var($googleProfile['email'] ?? '', FILTER_SANITIZE_EMAIL));
    $googleId = trim((string)($googleProfile['sub'] ?? ''));
    $fullName = trim((string)($googleProfile['name'] ?? ''));
    $avatarUrl = !empty($googleProfile['picture']) ? trim((string)$googleProfile['picture']) : null;

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid or missing email address in Google account profile.', 'redirect' => '', 'user' => null];
    }

    if (empty($googleId)) {
        $googleId = 'gid_' . md5($email);
    }

    if (empty($fullName)) {
        $fullName = ucfirst(explode('@', $email)[0]);
    }

    // 1. Look for existing user by google_id, then by email
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, phone, role, status, google_id, avatar_url, auth_provider
        FROM users 
        WHERE google_id = :gid OR email = :email
        LIMIT 1
    ");
    $stmt->execute([':gid' => $googleId, ':email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        // User exists: verify status
        if ($user['status'] !== 'active') {
            logAuditEvent((int)$user['id'], 'LOGIN_BLOCKED', 'users', (int)$user['id'], "Blocked Google sign-in: Account is inactive");
            return ['success' => false, 'message' => 'Your account is currently inactive. Please contact clinic reception.', 'redirect' => '', 'user' => null];
        }

        // Link Google ID or update avatar if newly available
        $updateFields = [];
        $updateParams = [':id' => $user['id']];

        if (empty($user['google_id'])) {
            $updateFields[] = "google_id = :new_gid";
            $updateParams[':new_gid'] = $googleId;
        }

        if (!empty($avatarUrl) && empty($user['avatar_url'])) {
            $updateFields[] = "avatar_url = :new_avatar";
            $updateParams[':new_avatar'] = $avatarUrl;
        }

        if (!empty($updateFields)) {
            $updateFields[] = "updated_at = NOW()";
            $upSql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $upStmt = $pdo->prepare($upSql);
            $upStmt->execute($updateParams);
        }

        // Regenerate session to protect against session fixation
        initSession();
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'         => (int)$user['id'],
            'full_name'  => $user['full_name'],
            'email'      => $user['email'],
            'phone'      => $user['phone'] ?? '',
            'role'       => $user['role'],
            'avatar_url' => $avatarUrl ?? $user['avatar_url'] ?? null
        ];

        logAuditEvent(
            (int)$user['id'],
            'LOGIN_GOOGLE',
            'users',
            (int)$user['id'],
            "Patient signed in via Google account ({$user['email']})"
        );

        $redirect = $_SESSION['redirect_after_login'] ?? getDashboardUrl($user['role']);
        unset($_SESSION['redirect_after_login']);

        return [
            'success'  => true,
            'message'  => 'Welcome back, ' . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') . '!',
            'redirect' => $redirect,
            'user'     => $_SESSION['user']
        ];
    }

    // 2. User does not exist: Automatically register new patient via Google Account
    try {
        $pdo->beginTransaction();

        $insUser = $pdo->prepare("
            INSERT INTO users (
                full_name,
                email,
                google_id,
                phone,
                password_hash,
                auth_provider,
                avatar_url,
                role,
                status,
                created_at
            ) VALUES (
                :full_name,
                :email,
                :google_id,
                NULL,
                NULL,
                'google',
                :avatar_url,
                'patient',
                'active',
                NOW()
            )
        ");

        $insUser->execute([
            ':full_name'  => $fullName,
            ':email'      => $email,
            ':google_id'  => $googleId,
            ':avatar_url' => $avatarUrl
        ]);

        $newUserId = (int)$pdo->lastInsertId();

        // Create linked empty patient profile
        $insProfile = $pdo->prepare("
            INSERT INTO patient_profiles (
                user_id,
                created_at
            ) VALUES (
                :user_id,
                NOW()
            )
        ");
        $insProfile->execute([':user_id' => $newUserId]);

        $pdo->commit();

        logAuditEvent(
            $newUserId,
            'REGISTER_GOOGLE',
            'users',
            $newUserId,
            "New patient registered automatically via Google account ({$email})"
        );

        initSession();
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'         => $newUserId,
            'full_name'  => $fullName,
            'email'      => $email,
            'phone'      => '',
            'role'       => 'patient',
            'avatar_url' => $avatarUrl
        ];

        return [
            'success'  => true,
            'message'  => 'Your patient account has been created via Google! Welcome to CityCare Hospital.',
            'redirect' => getDashboardUrl('patient'),
            'user'     => $_SESSION['user']
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CityCare Google Registration Error] ' . $e->getMessage());
        return [
            'success'  => false,
            'message'  => 'Could not complete Google registration due to a server error. Please try again.',
            'redirect' => '',
            'user'     => null
        ];
    }
}

