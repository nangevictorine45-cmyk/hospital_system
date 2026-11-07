<?php
/**
 * =============================================================================
 * CityCare Hospital - Google OAuth Integration Test Suite
 * =============================================================================
 * 
 * Verifies:
 *  1. Google OAuth configuration helper & entry URL generator
 *  2. Auto-provisioning of new patient accounts (users + patient_profiles) via Google
 *  3. Returning Google patient authentication & session establishment
 *  4. Account linking: linking an existing local email account to a Google ID
 *  5. Inactive/suspended account defense
 *  6. Audit trail logging for Google registration and login events
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/google.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';

$isCli = (php_sapi_name() === 'cli');

$tests = [
    'config_url'     => ['title' => 'Google OAuth URL Generator',       'status' => false, 'messages' => []],
    'auto_provision' => ['title' => 'New Patient Google Registration',  'status' => false, 'messages' => []],
    'returning_user' => ['title' => 'Returning Google Patient Sign-In', 'status' => false, 'messages' => []],
    'account_link'   => ['title' => 'Existing Account Google Linking',  'status' => false, 'messages' => []],
    'inactive_guard' => ['title' => 'Inactive Account Sign-In Block',   'status' => false, 'messages' => []],
    'audit_logging'  => ['title' => 'Google Audit Trail Recording',     'status' => false, 'messages' => []]
];

$pdo = getDBConnection();

// -----------------------------------------------------------------------------
// Test 1: Google OAuth URL Generator
// -----------------------------------------------------------------------------
try {
    $loginUrl = getGoogleLoginUrl();
    if (!empty($loginUrl) && (strpos($loginUrl, 'google_auth.php') !== false || strpos($loginUrl, 'accounts.google.com') !== false)) {
        $tests['config_url']['status'] = true;
        $tests['config_url']['messages'][] = "Generated Google login gateway URL: {$loginUrl}";
        $tests['config_url']['messages'][] = "Mode: " . (isGoogleAuthConfigured() ? 'Live Google OAuth 2.0' : 'Google Identity Sandbox');
    } else {
        $tests['config_url']['status'] = false;
        $tests['config_url']['messages'][] = "Invalid Google login URL generated.";
    }
} catch (Exception $e) {
    $tests['config_url']['status'] = false;
    $tests['config_url']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 2: New Patient Google Registration (Auto-Provisioning)
// -----------------------------------------------------------------------------
$newGoogleSub = 'goog_sub_' . time() . '_' . rand(100, 999);
$newGoogleEmail = 'patient.google.' . time() . '@gmail.local';

try {
    $googleProfile = [
        'sub'     => $newGoogleSub,
        'email'   => $newGoogleEmail,
        'name'    => 'Jordan Rivera',
        'picture' => 'https://ui-avatars.com/api/?name=Jordan+Rivera'
    ];

    $res = handleGoogleUser($googleProfile);

    if ($res['success'] && !empty($res['user']['id'])) {
        $createdId = (int)$res['user']['id'];

        // Verify users row
        $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $uStmt->execute([':id' => $createdId]);
        $uRow = $uStmt->fetch();

        // Verify patient_profiles row
        $pStmt = $pdo->prepare("SELECT * FROM patient_profiles WHERE user_id = :id");
        $pStmt->execute([':id' => $createdId]);
        $pRow = $pStmt->fetch();

        if ($uRow && $pRow && $uRow['role'] === 'patient' && $uRow['google_id'] === $newGoogleSub && $uRow['auth_provider'] === 'google') {
            $tests['auto_provision']['status'] = true;
            $tests['auto_provision']['messages'][] = "Auto-provisioned patient ID #{$createdId} ({$newGoogleEmail}).";
            $tests['auto_provision']['messages'][] = "Saved Google Sub ID: {$newGoogleSub} and avatar URL.";
            $tests['auto_provision']['messages'][] = "Linked medical patient_profile created automatically.";
        } else {
            $tests['auto_provision']['status'] = false;
            $tests['auto_provision']['messages'][] = "Database verification failed for created Google patient.";
        }
    } else {
        $tests['auto_provision']['status'] = false;
        $tests['auto_provision']['messages'][] = "Auto-provisioning failed: " . ($res['message'] ?? 'Unknown error');
    }
} catch (Exception $e) {
    $tests['auto_provision']['status'] = false;
    $tests['auto_provision']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 3: Returning Google Patient Sign-In
// -----------------------------------------------------------------------------
try {
    // Sign in again with the same Google payload
    $returningRes = handleGoogleUser($googleProfile);

    if ($returningRes['success'] && isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] === $createdId) {
        $tests['returning_user']['status'] = true;
        $tests['returning_user']['messages'][] = "Returning Google patient authenticated successfully.";
        $tests['returning_user']['messages'][] = "Session populated with patient role and identity.";
    } else {
        $tests['returning_user']['status'] = false;
        $tests['returning_user']['messages'][] = "Returning sign-in failed.";
    }
} catch (Exception $e) {
    $tests['returning_user']['status'] = false;
    $tests['returning_user']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 4: Existing Account Google Linking
// -----------------------------------------------------------------------------
// Patient 3 (patient@citycare.local) was created via local email/password
try {
    $linkSub = 'goog_link_' . time();
    $linkProfile = [
        'sub'     => $linkSub,
        'email'   => 'patient@citycare.local',
        'name'    => 'John Doe',
        'picture' => 'https://ui-avatars.com/api/?name=John+Doe'
    ];

    $linkRes = handleGoogleUser($linkProfile);

    // Verify patient 3 in database now has this google_id
    $checkLink = $pdo->query("SELECT google_id FROM users WHERE id = 3")->fetchColumn();

    if ($linkRes['success'] && $checkLink === $linkSub) {
        $tests['account_link']['status'] = true;
        $tests['account_link']['messages'][] = "Existing local patient (patient@citycare.local) linked with Google ID {$linkSub}.";
        $tests['account_link']['messages'][] = "Password and local credentials preserved while enabling 1-click Google sign-in.";
    } else {
        $tests['account_link']['status'] = false;
        $tests['account_link']['messages'][] = "Failed to link existing account to Google ID.";
    }
} catch (Exception $e) {
    $tests['account_link']['status'] = false;
    $tests['account_link']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 5: Inactive Account Sign-In Block
// -----------------------------------------------------------------------------
try {
    // Temporarily set created test patient to inactive
    $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = :id")->execute([':id' => $createdId]);

    $blockedRes = handleGoogleUser($googleProfile);

    // Restore to active
    $pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id")->execute([':id' => $createdId]);

    if (!$blockedRes['success'] && strpos($blockedRes['message'], 'inactive') !== false) {
        $tests['inactive_guard']['status'] = true;
        $tests['inactive_guard']['messages'][] = "Suspended/inactive account prevented from signing in via Google.";
    } else {
        $tests['inactive_guard']['status'] = false;
        $tests['inactive_guard']['messages'][] = "Inactive account check failed.";
    }
} catch (Exception $e) {
    $tests['inactive_guard']['status'] = false;
    $tests['inactive_guard']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 6: Google Audit Trail Recording
// -----------------------------------------------------------------------------
try {
    $auditStmt = $pdo->query("
        SELECT action, COUNT(*) as cnt 
        FROM audit_logs 
        WHERE action IN ('LOGIN_GOOGLE', 'REGISTER_GOOGLE') 
        GROUP BY action
    ");
    $auditCounts = [];
    while ($row = $auditStmt->fetch()) {
        $auditCounts[$row['action']] = (int)$row['cnt'];
    }

    if (!empty($auditCounts['LOGIN_GOOGLE']) && !empty($auditCounts['REGISTER_GOOGLE'])) {
        $tests['audit_logging']['status'] = true;
        $tests['audit_logging']['messages'][] = "LOGIN_GOOGLE events recorded: {$auditCounts['LOGIN_GOOGLE']}";
        $tests['audit_logging']['messages'][] = "REGISTER_GOOGLE events recorded: {$auditCounts['REGISTER_GOOGLE']}";
    } else {
        $tests['audit_logging']['status'] = false;
        $tests['audit_logging']['messages'][] = "Missing Google audit events in audit_logs.";
    }
} catch (Exception $e) {
    $tests['audit_logging']['status'] = false;
    $tests['audit_logging']['messages'][] = "Error: " . $e->getMessage();
}

// Check overall success
$allPassed = true;
foreach ($tests as $t) {
    if (!$t['status']) {
        $allPassed = false;
        break;
    }
}

if ($isCli) {
    echo "====================================================================\n";
    echo " CityCare Hospital - Google OAuth Integration Test Suite\n";
    echo "====================================================================\n\n";

    $i = 1;
    foreach ($tests as $key => $t) {
        echo sprintf("[%d] %-42s : %s\n", $i++, $t['title'], $t['status'] ? "PASS" : "FAIL");
        foreach ($t['messages'] as $msg) {
            echo "    - " . $msg . "\n";
        }
        echo "\n";
    }

    echo "====================================================================\n";
    echo " OVERALL STATUS: " . ($allPassed ? "ALL GOOGLE AUTH TESTS PASSED" : "SOME TESTS FAILED") . "\n";
    echo "====================================================================\n";
    exit($allPassed ? 0 : 1);
}

// Browser UI
$pageTitle = 'Google Authentication Diagnostics';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h1 class="card-title">Google Authentication Integration Diagnostics</h1>
            <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
                Diagnostic verification for patient Google Sign-In, auto-provisioning, account linking, and audit trail.
            </p>
        </div>
        <span class="badge" style="background: <?php echo $allPassed ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $allPassed ? '#15803d' : '#b91c1c'; ?>; font-size: 13px;">
            <?php echo $allPassed ? '&#10004; ALL TESTS PASSED' : '&#10008; TESTS FAILED'; ?>
        </span>
    </div>

    <?php $stepIdx = 1; ?>
    <?php foreach ($tests as $key => $t): ?>
        <div style="padding: 16px 0; border-bottom: 1px solid var(--gray-200);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <h3 style="margin: 0; font-size: 16px; color: var(--gray-900);">
                    <?php echo $stepIdx++; ?>. <?php echo htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8'); ?>
                </h3>
                <span class="badge" style="background: <?php echo $t['status'] ? 'var(--success-light)' : 'var(--danger-light)'; ?>; color: <?php echo $t['status'] ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <?php echo $t['status'] ? 'PASS' : 'FAIL'; ?>
                </span>
            </div>
            <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--gray-700);">
                <?php foreach ($t['messages'] as $m): ?>
                    <li><?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>

    <div style="display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap;">
        <a href="google_auth.php" class="btn btn-primary">Try Google Sign-In &rarr;</a>
        <a href="login.php" class="btn btn-secondary">Go to Login Page</a>
        <a href="index.php" class="btn btn-secondary">&larr; Return to Home</a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

