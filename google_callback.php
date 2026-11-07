<?php
/**
 * =============================================================================
 * CityCare Hospital - Google OAuth 2.0 Redirect Callback Handler
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/google.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';

initSession();

// 1. Check for errors returned by Google consent screen
if (isset($_GET['error'])) {
    $errorReason = htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8');
    setFlash('danger', 'Google sign-in was cancelled or encountered an error: ' . $errorReason);
    header('Location: login.php');
    exit;
}

// 2. Verify CSRF State Token
$submittedState = $_GET['state'] ?? '';
$storedState    = $_SESSION['google_oauth_state'] ?? '';

if (empty($submittedState) || empty($storedState) || !hash_equals($storedState, $submittedState)) {
    setFlash('danger', 'Google authorization validation failed (Invalid OAuth state token).');
    header('Location: login.php');
    exit;
}

// Clean up state token
unset($_SESSION['google_oauth_state']);

// 3. Extract Authorization Code
$authCode = $_GET['code'] ?? '';
if (empty($authCode)) {
    setFlash('danger', 'No authorization code received from Google.');
    header('Location: login.php');
    exit;
}

// 4. Exchange authorization code for tokens
$tokenData = exchangeGoogleCode($authCode);
if (!$tokenData || empty($tokenData['access_token'])) {
    setFlash('danger', 'Failed to retrieve access token from Google identity servers.');
    header('Location: login.php');
    exit;
}

// 5. Fetch verified Google user profile
$googleProfile = fetchGoogleUserInfo($tokenData['access_token']);
if (!$googleProfile || empty($googleProfile['email'])) {
    setFlash('danger', 'Failed to fetch verified user profile information from Google.');
    header('Location: login.php');
    exit;
}

// 6. Handle Authentication / Auto-registration
$result = handleGoogleUser($googleProfile);

if ($result['success']) {
    setFlash('success', $result['message']);
    header('Location: ' . $result['redirect']);
    exit;
} else {
    setFlash('danger', $result['message']);
    header('Location: login.php');
    exit;
}

