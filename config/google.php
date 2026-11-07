<?php
/**
 * =============================================================================
 * CityCare Hospital - Google OAuth 2.0 Configuration
 * =============================================================================
 * 
 * Provides credentials and configuration for Google Identity Services / OAuth 2.0.
 * 
 * To enable LIVE Google Sign-In with real Google accounts:
 *  1. Go to Google Cloud Console (https://console.cloud.google.com/)
 *  2. Create a project and configure OAuth Consent Screen
 *  3. Under Credentials, create an "OAuth 2.0 Client ID" for Web Application
 *  4. Add Authorized redirect URI:
 *     http://localhost/hospital_system/google_callback.php
 *  5. Enter your Client ID and Client Secret below (or define environment variables)
 * 
 * When credentials are empty, the system automatically runs in Google Sandbox /
 * Simulation Mode, allowing instant local testing without needing API keys!
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/app.php';
}

// Set your Google Cloud OAuth 2.0 Credentials here (or via environment variables)
defined('GOOGLE_CLIENT_ID')     || define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
defined('GOOGLE_CLIENT_SECRET') || define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');

// Build base host and protocol dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl  = defined('APP_BASE_URL') ? APP_BASE_URL : '/hospital_system';

defined('GOOGLE_REDIRECT_URI')  || define('GOOGLE_REDIRECT_URI', $protocol . $host . $baseUrl . '/google_callback.php');

/**
 * Check whether real Google OAuth 2.0 keys have been configured.
 */
function isGoogleAuthConfigured(): bool
{
    $id = trim(GOOGLE_CLIENT_ID);
    $secret = trim(GOOGLE_CLIENT_SECRET);
    return ($id !== '' && $secret !== '' && strpos($id, 'your_google_client_id') === false);
}

