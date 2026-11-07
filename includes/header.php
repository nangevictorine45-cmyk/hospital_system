<?php
/**
 * =============================================================================
 * CityCare Hospital - Shared Header & Universal Navigation Menu
 * =============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rescheduling.php';

$pageTitle = $pageTitle ?? APP_NAME;
$user = currentUser();
$flashes = getFlashes();
$baseUrl = defined('APP_BASE_URL') ? APP_BASE_URL : '/hospital_system';

// Check unread notifications if patient
$unreadMsgCount = 0;
if ($user && $user['role'] === 'patient') {
    $unreadMsgCount = getUnreadMessageCount((int)$user['id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> - CityCare Hospital</title>
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/style.css">
</head>
<body>

<!-- =========================================================================
     TOP NAVIGATION BAR (With Always-Visible Prominent "Menu" Button)
     ========================================================================= -->
<header class="navbar">
    <div class="nav-container">
        <div style="display: flex; align-items: center; gap: 14px;">
            <!-- Prominent Menu Button: Visible on ALL Screen Sizes -->
            <button type="button" class="nav-menu-btn" onclick="openNavDrawer()" id="menuButton" aria-label="Open Navigation Menu">
                <span style="font-size: 16px;">&#9776;</span>
                <span>Menu</span>
            </button>

            <a href="<?php echo $baseUrl; ?>/index.php" class="brand">
                <span class="brand-icon">&#10010;</span>
                <span>CityCare Hospital</span>
            </a>
        </div>

        <nav>
            <ul class="nav-links" id="navLinks">
                <li><a href="<?php echo $baseUrl; ?>/index.php" class="nav-link">&#127968; Home</a></li>
                <li><a href="<?php echo $baseUrl; ?>/doctors.php" class="nav-link">&#129658; Specialists</a></li>
                
                <?php if ($user): ?>
                    <!-- Patient Menu -->
                    <?php if ($user['role'] === 'patient'): ?>
                        <li class="nav-dropdown">
                            <a href="<?php echo $baseUrl; ?>/patient/dashboard.php" class="nav-link dropdown-toggle">
                                &#128100; Patient Portal <span style="font-size: 10px;">&#9660;</span>
                            </a>
                            <div class="dropdown-menu">
                                <div class="dropdown-header">Patient Services</div>
                                <a href="<?php echo $baseUrl; ?>/patient/dashboard.php" class="dropdown-item">
                                    &#128202; Portal Dashboard
                                </a>
                                <a href="<?php echo $baseUrl; ?>/patient/book.php" class="dropdown-item">
                                    &#128197; Book Consultation
                                </a>
                                <a href="<?php echo $baseUrl; ?>/patient/appointments.php" class="dropdown-item">
                                    &#128337; My Appointments
                                </a>
                                <a href="<?php echo $baseUrl; ?>/patient/messages.php" class="dropdown-item">
                                    &#128172; Messages &amp; Rescheduling <?php if ($unreadMsgCount > 0): ?><span class="badge" style="background: var(--warning); color: #fff; font-size: 10px; margin-left: auto;"><?php echo $unreadMsgCount; ?></span><?php endif; ?>
                                </a>
                            </div>
                        </li>

                        <!-- Direct Notification Link if unread messages exist -->
                        <li>
                            <a href="<?php echo $baseUrl; ?>/patient/messages.php" class="nav-link" style="position: relative;">
                                &#128172; Messages
                                <?php if ($unreadMsgCount > 0): ?>
                                    <span class="badge" style="background: var(--warning); color: #ffffff; padding: 2px 6px; font-size: 11px; border-radius: 9999px;">
                                        <?php echo $unreadMsgCount; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Staff Menu -->
                    <?php if ($user['role'] === 'staff'): ?>
                        <li class="nav-dropdown">
                            <a href="<?php echo $baseUrl; ?>/staff/dashboard.php" class="nav-link dropdown-toggle">
                                &#127973; Staff Desk <span style="font-size: 10px;">&#9660;</span>
                            </a>
                            <div class="dropdown-menu">
                                <div class="dropdown-header">Front Desk Operations</div>
                                <a href="<?php echo $baseUrl; ?>/staff/dashboard.php" class="dropdown-item">
                                    &#128202; Clinical Operations Desk
                                </a>
                                <a href="<?php echo $baseUrl; ?>/staff/appointments.php" class="dropdown-item">
                                    &#128203; Master Appointment Roster
                                </a>
                                <a href="<?php echo $baseUrl; ?>/staff/missed_appointments.php" class="dropdown-item">
                                    &#9888; Missed Appointment Recovery
                                </a>
                                <a href="<?php echo $baseUrl; ?>/doctors.php" class="dropdown-item">
                                    &#129658; Doctor Shift Directory
                                </a>
                            </div>
                        </li>
                    <?php endif; ?>

                    <!-- Admin Menu -->
                    <?php if ($user['role'] === 'admin'): ?>
                        <li class="nav-dropdown">
                            <a href="<?php echo $baseUrl; ?>/admin/dashboard.php" class="nav-link dropdown-toggle">
                                &#128737; Admin Portal <span style="font-size: 10px;">&#9660;</span>
                            </a>
                            <div class="dropdown-menu">
                                <div class="dropdown-header">Hospital Administration</div>
                                <a href="<?php echo $baseUrl; ?>/admin/dashboard.php" class="dropdown-item">
                                    &#128202; System Command Center
                                </a>
                                <a href="<?php echo $baseUrl; ?>/admin/doctors.php" class="dropdown-item">
                                    &#129658; Doctor &amp; Shift Management
                                </a>
                                <a href="<?php echo $baseUrl; ?>/staff/appointments.php" class="dropdown-item">
                                    &#128203; Master Hospital Roster
                                </a>
                                <a href="<?php echo $baseUrl; ?>/staff/missed_appointments.php" class="dropdown-item">
                                    &#9888; Missed Visit Recovery Desk
                                </a>
                                <a href="<?php echo $baseUrl; ?>/admin/settings.php" class="dropdown-item">
                                    &#9881; Clinic Operating Settings
                                </a>
                                <a href="<?php echo $baseUrl; ?>/admin/users.php" class="dropdown-item">
                                    &#128101; User &amp; Patient Accounts
                                </a>
                                <div class="dropdown-divider"></div>
                                <div class="dropdown-header">Switch View</div>
                                <a href="<?php echo $baseUrl; ?>/staff/dashboard.php" class="dropdown-item">
                                    &#127973; Staff Desk View
                                </a>
                                <a href="<?php echo $baseUrl; ?>/patient/dashboard.php" class="dropdown-item">
                                    &#128100; Patient Portal View
                                </a>
                            </div>
                        </li>
                    <?php endif; ?>

                    <!-- User Pill & Sign Out -->
                    <li>
                        <div class="user-pill">
                            <?php if (!empty($user['avatar_url'])): ?>
                                <img src="<?php echo htmlspecialchars($user['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar" style="width: 22px; height: 22px; border-radius: 50%; object-fit: cover; vertical-align: middle;">
                            <?php endif; ?>
                            <span><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="role-tag role-tag-<?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(strtoupper($user['role']), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                    </li>
                    <li><a href="<?php echo $baseUrl; ?>/logout.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">Sign Out</a></li>
                <?php else: ?>
                    <li><a href="<?php echo $baseUrl; ?>/login.php" class="nav-link">Sign In</a></li>
                    <li><a href="<?php echo $baseUrl; ?>/register.php" class="btn btn-primary" style="padding: 8px 14px; font-size: 13px;">Register</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</header>

<!-- =========================================================================
     SLIDE-OUT UNIVERSAL NAVIGATION DRAWER (Opened via [ ☰ Menu ] Button)
     ========================================================================= -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeNavDrawer()"></div>

<aside class="nav-drawer" id="navDrawer" aria-label="Navigation Drawer">
    <div class="drawer-header">
        <h2 class="drawer-title">&#10010; CityCare Navigation</h2>
        <button type="button" class="drawer-close" onclick="closeNavDrawer()" aria-label="Close Menu">&times;</button>
    </div>

    <?php if ($user): ?>
        <div style="background: var(--gray-50); padding: 14px 20px; border-bottom: 1px solid var(--gray-200); display: flex; align-items: center; gap: 12px;">
            <?php if (!empty($user['avatar_url'])): ?>
                <img src="<?php echo htmlspecialchars($user['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
            <?php else: ?>
                <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px;">
                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                </div>
            <?php endif; ?>
            <div style="flex: 1; min-width: 0;">
                <div style="font-weight: 700; color: var(--gray-900); font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div style="font-size: 12px; color: var(--gray-500); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div style="margin-top: 4px;">
                    <span class="role-tag role-tag-<?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars(strtoupper($user['role']), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Section 1: Main Outpatient Services -->
    <div class="drawer-section">
        <div class="drawer-section-title">General Hospital Services</div>
        <a href="<?php echo $baseUrl; ?>/index.php" class="drawer-link" onclick="closeNavDrawer()">
            <span style="font-size: 16px;">&#127968;</span>
            <span>Hospital Home</span>
        </a>
        <a href="<?php echo $baseUrl; ?>/doctors.php" class="drawer-link" onclick="closeNavDrawer()">
            <span style="font-size: 16px;">&#129658;</span>
            <span>Medical Specialists &amp; Shifts</span>
        </a>
    </div>

    <!-- Section 2: Patient Portal -->
    <div class="drawer-section">
        <div class="drawer-section-title">Patient Portal &amp; Bookings</div>
        <a href="<?php echo $baseUrl; ?>/patient/dashboard.php" class="drawer-link" onclick="closeNavDrawer()">
            <span style="font-size: 16px;">&#128100;</span>
            <span>Patient Portal Dashboard</span>
        </a>
        <a href="<?php echo $baseUrl; ?>/patient/book.php" class="drawer-link" onclick="closeNavDrawer()">
            <span style="font-size: 16px;">&#128197;</span>
            <span>Book Consultation Slot</span>
        </a>
        <a href="<?php echo $baseUrl; ?>/patient/appointments.php" class="drawer-link" onclick="closeNavDrawer()">
            <span style="font-size: 16px;">&#128337;</span>
            <span>My Scheduled Appointments</span>
        </a>
        <a href="<?php echo $baseUrl; ?>/patient/messages.php" class="drawer-link" onclick="closeNavDrawer()">
            <span style="font-size: 16px;">&#128172;</span>
            <span>Messages &amp; Rescheduling</span>
            <?php if ($unreadMsgCount > 0): ?>
                <span class="badge" style="background: var(--warning); color: #fff; font-size: 10px; margin-left: auto;">
                    <?php echo $unreadMsgCount; ?> unread
                </span>
            <?php endif; ?>
        </a>
    </div>

    <!-- Section 3: Clinical Staff Operations -->
    <?php if ($user && in_array($user['role'], ['staff', 'admin'], true)): ?>
        <div class="drawer-section">
            <div class="drawer-section-title">Clinical Staff Desk</div>
            <a href="<?php echo $baseUrl; ?>/staff/dashboard.php" class="drawer-link" onclick="closeNavDrawer()">
                <span style="font-size: 16px;">&#127973;</span>
                <span>Clinical Operations &amp; Queue Desk</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/staff/appointments.php" class="drawer-link" onclick="closeNavDrawer()">
                <span style="font-size: 16px;">&#128203;</span>
                <span>Master Appointment Roster</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/staff/missed_appointments.php" class="drawer-link" onclick="closeNavDrawer()">
                <span style="font-size: 16px;">&#9888;</span>
                <span>Missed Consultation Recovery Desk</span>
            </a>
        </div>
    <?php endif; ?>

    <!-- Section 4: Hospital Administration -->
    <?php if ($user && $user['role'] === 'admin'): ?>
        <div class="drawer-section">
            <div class="drawer-section-title">Hospital Administration</div>
            <a href="<?php echo $baseUrl; ?>/admin/dashboard.php" class="drawer-link" onclick="closeNavDrawer()">
                <span style="font-size: 16px;">&#128737;</span>
                <span>System Command Center</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/admin/doctors.php" class="drawer-link" onclick="closeNavDrawer()">
                <span style="font-size: 16px;">&#129658;</span>
                <span>Doctor Directory &amp; Schedules</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/admin/settings.php" class="drawer-link" onclick="closeNavDrawer()">
                <span style="font-size: 16px;">&#9881;</span>
                <span>Clinic Operating Settings</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/admin/users.php" class="drawer-link" onclick="closeNavDrawer()">
                <span style="font-size: 16px;">&#128101;</span>
                <span>User Accounts &amp; Roles</span>
            </a>
        </div>
    <?php endif; ?>

    <div style="padding: 20px; margin-top: auto; border-top: 1px solid var(--gray-200);">
        <?php if ($user): ?>
            <a href="<?php echo $baseUrl; ?>/logout.php" class="btn btn-secondary btn-block" style="padding: 10px; font-size: 13px;">
                &#128682; Sign Out of CityCare
            </a>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <a href="<?php echo $baseUrl; ?>/login.php" class="btn btn-primary btn-block">Sign In &rarr;</a>
                <a href="<?php echo $baseUrl; ?>/register.php" class="btn btn-secondary btn-block">Register New Patient</a>
            </div>
        <?php endif; ?>
    </div>
</aside>

<script>
function openNavDrawer() {
    var drawer = document.getElementById('navDrawer');
    var overlay = document.getElementById('drawerOverlay');
    if (drawer) drawer.classList.add('active');
    if (overlay) overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeNavDrawer() {
    var drawer = document.getElementById('navDrawer');
    var overlay = document.getElementById('drawerOverlay');
    if (drawer) drawer.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeNavDrawer();
    }
});
</script>

<main class="main-content">
    <?php if (!empty($flashes)): ?>
        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8'); ?>">
                <span>
                    <?php if ($flash['type'] === 'success'): ?>&#10004;<?php endif; ?>
                    <?php if ($flash['type'] === 'danger'): ?>&#10008;<?php endif; ?>
                    <?php if ($flash['type'] === 'warning'): ?>&#9888;<?php endif; ?>
                    <?php if ($flash['type'] === 'info'): ?>&#8505;<?php endif; ?>
                </span>
                <div><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
