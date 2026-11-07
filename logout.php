<?php
/**
 * =============================================================================
 * CityCare Hospital - User Logout Handler
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';

performLogout();

// Start a fresh session to deliver the goodbye flash message
initSession();
setFlash('info', 'You have been safely signed out of CityCare Hospital.');

header('Location: login.php');
exit;

