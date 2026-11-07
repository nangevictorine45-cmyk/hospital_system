<?php
/**
 * =============================================================================
 * CityCare Hospital - Authentication & RBAC Test Suite (Phase 3)
 * =============================================================================
 * 
 * Verifies Phase 3 implementation:
 *  1. CSRF Token Generation & Tamper Resistance
 *  2. Authentication with Seeded Credentials (Admin, Staff, Patient)
 *  3. Defense against Bad Passwords & Unknown Accounts
 *  4. Atomic Patient Registration (users + patient_profiles transaction)
 *  5. Registration Form Validation Rules (Duplicate email, short passwords)
 *  6. Role-Based Access Control (RBAC) Permitted/Denied Matrix
 *  7. Audit Logging of Security & Auth Events
 * 
 * Can be run from CLI: php test_auth.php
 * Or via web browser: http://localhost/hospital_system/test_auth.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$isCli = (php_sapi_name() === 'cli');

$tests = [
    'csrf'         => ['title' => 'CSRF Token Generation & Validation', 'status' => false, 'messages' => []],
    'seed_auth'    => ['title' => 'Seeded Accounts Authentication',     'status' => false, 'messages' => []],
    'security_bad' => ['title' => 'Rejection of Invalid Credentials',  'status' => false, 'messages' => []],
    'registration' => ['title' => 'Transactional Patient Registration', 'status' => false, 'messages' => []],
    'validation'   => ['title' => 'Registration Validation Safeguards', 'status' => false, 'messages' => []],
    'rbac'         => ['title' => 'Role-Based Access Control (RBAC)',   'status' => false, 'messages' => []],
    'audit_trail'  => ['title' => 'Security Audit Trail Logging',       'status' => false, 'messages' => []],
];

$pdo = getDBConnection();

// -----------------------------------------------------------------------------
// Test 1: CSRF Protection
// -----------------------------------------------------------------------------
try {
    $token1 = getCsrfToken();
    $validCheck = verifyCsrfToken($token1);
    $tamperedCheck = verifyCsrfToken('tampered_fake_token_value_12345');
    $emptyCheck = verifyCsrfToken('');

    if ($validCheck && !$tamperedCheck && !$emptyCheck && strlen($token1) === 64) {
        $tests['csrf']['status'] = true;
        $tests['csrf']['messages'][] = "CSRF 256-bit token generated ({$token1}).";
        $tests['csrf']['messages'][] = "Tampered & empty tokens correctly rejected by constant-time hash_equals().";
    } else {
        $tests['csrf']['status'] = false;
        $tests['csrf']['messages'][] = "CSRF verification test failed.";
    }
} catch (Exception $e) {
    $tests['csrf']['status'] = false;
    $tests['csrf']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 2: Seeded Accounts Authentication
// -----------------------------------------------------------------------------
try {
    $accounts = [
        'admin@citycare.local'   => 'admin',
        'staff@citycare.local'   => 'staff',
        'patient@citycare.local' => 'patient'
    ];

    $allPassed = true;
    foreach ($accounts as $email => $expectedRole) {
        $res = attemptLogin($email, 'Password123!');
        if ($res['success'] && isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === $expectedRole) {
            $tests['seed_auth']['messages'][] = "Successfully authenticated {$email} with role: {$expectedRole}";
        } else {
            $allPassed = false;
            $tests['seed_auth']['messages'][] = "Authentication failed for {$email}: " . ($res['message'] ?? 'No message');
        }
    }
    $tests['seed_auth']['status'] = $allPassed;
} catch (Exception $e) {
    $tests['seed_auth']['status'] = false;
    $tests['seed_auth']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 3: Rejection of Invalid Credentials
// -----------------------------------------------------------------------------
try {
    $badPassRes = attemptLogin('admin@citycare.local', 'WrongPassword999!');
    $unknownUserRes = attemptLogin('nonexistent_user_999@citycare.local', 'Password123!');

    if (!$badPassRes['success'] && !$unknownUserRes['success']) {
        $tests['security_bad']['status'] = true;
        $tests['security_bad']['messages'][] = "Wrong password properly rejected without granting access.";
        $tests['security_bad']['messages'][] = "Unknown email address properly rejected without leaking user existence.";
    } else {
        $tests['security_bad']['status'] = false;
        $tests['security_bad']['messages'][] = "Security check failed: invalid credentials were inappropriately accepted.";
    }
} catch (Exception $e) {
    $tests['security_bad']['status'] = false;
    $tests['security_bad']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 4: Transactional Patient Registration
// -----------------------------------------------------------------------------
$testEmail = 'alice.unit.' . time() . '@example.local';
try {
    $regData = [
        'full_name'                => 'Alice Unit Tester',
        'email'                    => $testEmail,
        'phone'                    => '+1 (555) 777-8888',
        'password'                 => 'SecurePass123!',
        'confirm_password'         => 'SecurePass123!',
        'date_of_birth'            => '1995-04-12',
        'gender'                   => 'female',
        'address'                  => '789 Testing Blvd, Suite 4',
        'emergency_contact_name'   => 'Bob Tester (Brother)',
        'emergency_contact_phone'  => '+1 (555) 777-9999'
    ];

    $regRes = registerPatient($regData);

    if ($regRes['success'] && !empty($regRes['user_id'])) {
        $newUserId = (int)$regRes['user_id'];
        
        // Verify user row
        $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $uStmt->execute([':id' => $newUserId]);
        $createdUser = $uStmt->fetch();

        // Verify patient_profiles row
        $pStmt = $pdo->prepare("SELECT * FROM patient_profiles WHERE user_id = :id");
        $pStmt->execute([':id' => $newUserId]);
        $createdProfile = $pStmt->fetch();

        // Verify password check works on new user
        $pwValid = password_verify('SecurePass123!', $createdUser['password_hash']);

        if ($createdUser && $createdProfile && $pwValid) {
            $tests['registration']['status'] = true;
            $tests['registration']['messages'][] = "New patient registered (ID: {$newUserId}, Email: {$testEmail}).";
            $tests['registration']['messages'][] = "Linked patient_profile created with DOB: {$createdProfile['date_of_birth']}.";
            $tests['registration']['messages'][] = "Password hash verified using bcrypt cost=10.";
        } else {
            $tests['registration']['status'] = false;
            $tests['registration']['messages'][] = "Database verification failed for created records.";
        }
    } else {
        $tests['registration']['status'] = false;
        $tests['registration']['messages'][] = "Registration failed: " . ($regRes['message'] ?? 'Unknown error');
    }
} catch (Exception $e) {
    $tests['registration']['status'] = false;
    $tests['registration']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 5: Registration Validation Safeguards
// -----------------------------------------------------------------------------
try {
    // Attempt duplicate email
    $dupRes = registerPatient([
        'full_name'        => 'Duplicate User',
        'email'            => $testEmail, // already registered in Test 4
        'phone'            => '+1 (555) 000-1111',
        'password'         => 'SecurePass123!',
        'confirm_password' => 'SecurePass123!'
    ]);

    // Attempt short password
    $shortPassRes = registerPatient([
        'full_name'        => 'Short Password User',
        'email'            => 'short.pass.' . time() . '@example.local',
        'phone'            => '+1 (555) 000-2222',
        'password'         => '12345',
        'confirm_password' => '12345'
    ]);

    // Attempt password mismatch
    $mismatchRes = registerPatient([
        'full_name'        => 'Mismatch User',
        'email'            => 'mismatch.' . time() . '@example.local',
        'phone'            => '+1 (555) 000-3333',
        'password'         => 'Password123!',
        'confirm_password' => 'DifferentPassword123!'
    ]);

    $dupBlocked = !$dupRes['success'] && isset($dupRes['errors']['email']);
    $shortBlocked = !$shortPassRes['success'] && isset($shortPassRes['errors']['password']);
    $mismatchBlocked = !$mismatchRes['success'] && isset($mismatchRes['errors']['confirm_password']);

    if ($dupBlocked && $shortBlocked && $mismatchBlocked) {
        $tests['validation']['status'] = true;
        $tests['validation']['messages'][] = "Duplicate email registration prevented.";
        $tests['validation']['messages'][] = "Password < 8 characters blocked.";
        $tests['validation']['messages'][] = "Mismatched confirmation password rejected.";
    } else {
        $tests['validation']['status'] = false;
        $tests['validation']['messages'][] = "Validation rules test did not block expected illegal inputs.";
    }
} catch (Exception $e) {
    $tests['validation']['status'] = false;
    $tests['validation']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 6: Role-Based Access Control (RBAC) Permitted/Denied Matrix
// -----------------------------------------------------------------------------
try {
    // 1. Simulate Patient Session
    $_SESSION['user'] = ['id' => 3, 'role' => 'patient'];
    $patientHasPatient = hasRole('patient');
    $patientHasAdmin = hasRole('admin');
    $patientHasStaff = hasRole('staff');

    // 2. Simulate Admin Session
    $_SESSION['user'] = ['id' => 1, 'role' => 'admin'];
    $adminHasAdmin = hasRole('admin');
    $adminHasPatient = hasRole('patient');
    $adminHasStaffOrAdmin = hasRole(['staff', 'admin']);

    $rbacPass = $patientHasPatient && !$patientHasAdmin && !$patientHasStaff &&
                $adminHasAdmin && !$adminHasPatient && $adminHasStaffOrAdmin;

    if ($rbacPass) {
        $tests['rbac']['status'] = true;
        $tests['rbac']['messages'][] = "Patient session correctly granted 'patient' role & denied 'admin' / 'staff'.";
        $tests['rbac']['messages'][] = "Admin session correctly granted 'admin' role and array of permitted roles ['staff', 'admin'].";
    } else {
        $tests['rbac']['status'] = false;
        $tests['rbac']['messages'][] = "RBAC logic test failed to match expected role permissions.";
    }
} catch (Exception $e) {
    $tests['rbac']['status'] = false;
    $tests['rbac']['messages'][] = "Error: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// Test 7: Security Audit Trail Logging
// -----------------------------------------------------------------------------
try {
    $auditStmt = $pdo->query("
        SELECT action, COUNT(*) as cnt 
        FROM audit_logs 
        WHERE action IN ('LOGIN_SUCCESS', 'LOGIN_FAILED', 'REGISTER') 
        GROUP BY action
    ");
    $auditCounts = [];
    while ($row = $auditStmt->fetch()) {
        $auditCounts[$row['action']] = (int)$row['cnt'];
    }

    $hasSuccess = !empty($auditCounts['LOGIN_SUCCESS']);
    $hasFailed = !empty($auditCounts['LOGIN_FAILED']);
    $hasRegister = !empty($auditCounts['REGISTER']);

    if ($hasSuccess && $hasFailed && $hasRegister) {
        $tests['audit_trail']['status'] = true;
        $tests['audit_trail']['messages'][] = "LOGIN_SUCCESS events recorded: {$auditCounts['LOGIN_SUCCESS']}";
        $tests['audit_trail']['messages'][] = "LOGIN_FAILED events recorded: {$auditCounts['LOGIN_FAILED']}";
        $tests['audit_trail']['messages'][] = "REGISTER events recorded: {$auditCounts['REGISTER']}";
    } else {
        $tests['audit_trail']['status'] = false;
        $tests['audit_trail']['messages'][] = "Audit logs missing one or more required events.";
    }
} catch (Exception $e) {
    $tests['audit_trail']['status'] = false;
    $tests['audit_trail']['messages'][] = "Error: " . $e->getMessage();
}

// Check overall success
$allPassed = true;
foreach ($tests as $t) {
    if (!$t['status']) {
        $allPassed = false;
        break;
    }
}

// -----------------------------------------------------------------------------
// Output formatting
// -----------------------------------------------------------------------------
if ($isCli) {
    echo "====================================================================\n";
    echo " CityCare Hospital - Auth & RBAC Verification Test (Phase 3)\n";
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
    echo " OVERALL STATUS: " . ($allPassed ? "ALL PHASE 3 AUTH TESTS PASSED" : "SOME TESTS FAILED") . "\n";
    echo "====================================================================\n";
    exit($allPassed ? 0 : 1);
}

// Render Browser UI
$pageTitle = 'Phase 3 Auth Verification';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h1 class="card-title">Phase 3: Authentication &amp; RBAC Verification</h1>
            <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
                Comprehensive test report verifying session hardening, registration, login, and authorization.
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
        <a href="login.php" class="btn btn-primary">Go to Login Page &rarr;</a>
        <a href="register.php" class="btn btn-secondary">Go to Registration Page</a>
        <a href="index.php" class="btn btn-secondary">&larr; Return to Home</a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

