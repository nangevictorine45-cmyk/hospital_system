<?php
/**
 * =============================================================================
 * CityCare Hospital - Database Verification Test Script
 * =============================================================================
 * 
 * Verifies Phase 2 implementation:
 *  1. PDO connection to database 'hospital_system'
 *  2. Existence of all 8 core tables
 *  3. Verification of Foreign Key constraints
 *  4. Verification of seeded records (Admin, Staff, Patient, Doctors, Clinic Settings)
 *  5. Cryptographic verification of seeded password hash
 * 
 * Can be run from CLI: php test_db.php
 * Or via web browser: http://localhost/hospital_system/test_db.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$isCli = (php_sapi_name() === 'cli');

$results = [
    'connection'   => ['status' => false, 'message' => '', 'details' => []],
    'tables'       => ['status' => false, 'message' => '', 'found' => [], 'missing' => []],
    'foreign_keys' => ['status' => false, 'message' => '', 'keys' => []],
    'seeds'        => ['status' => false, 'message' => '', 'counts' => [], 'samples' => []],
    'auth_check'   => ['status' => false, 'message' => '']
];

$requiredTables = [
    'users',
    'patient_profiles',
    'doctors',
    'doctor_schedules',
    'appointments',
    'queue_entries',
    'clinic_settings',
    'audit_logs'
];

$expectedForeignKeys = [
    'fk_patient_profiles_user'     => ['table' => 'patient_profiles', 'ref' => 'users'],
    'fk_doctor_schedules_doctor'   => ['table' => 'doctor_schedules', 'ref' => 'doctors'],
    'fk_appointments_patient'      => ['table' => 'appointments',     'ref' => 'users'],
    'fk_appointments_doctor'       => ['table' => 'appointments',     'ref' => 'doctors'],
    'fk_queue_entries_appointment' => ['table' => 'queue_entries',    'ref' => 'appointments'],
    'fk_audit_logs_actor'          => ['table' => 'audit_logs',       'ref' => 'users']
];

try {
    // 1. Connection Test
    $pdo = getDBConnection();
    $serverVersion = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $connectionStatus = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
    
    $results['connection']['status'] = true;
    $results['connection']['message'] = 'PDO Connection established successfully to database: ' . DB_NAME;
    $results['connection']['details'] = [
        'Server Version'    => $serverVersion,
        'Connection Status' => $connectionStatus,
        'Host'              => DB_HOST . ':' . DB_PORT,
        'Database'          => DB_NAME,
        'Charset'           => DB_CHARSET
    ];

    // 2. Table Existence Test
    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $missingTables = array_diff($requiredTables, $existingTables);
    $foundTables = array_intersect($requiredTables, $existingTables);

    $results['tables']['found'] = $foundTables;
    $results['tables']['missing'] = $missingTables;

    if (empty($missingTables) && count($foundTables) === count($requiredTables)) {
        $results['tables']['status'] = true;
        $results['tables']['message'] = sprintf('All %d required tables are present in the database.', count($requiredTables));
    } else {
        $results['tables']['status'] = false;
        $results['tables']['message'] = 'Missing tables: ' . implode(', ', $missingTables);
    }

    // 3. Foreign Key Constraints Test
    $fkQuery = "
        SELECT 
            CONSTRAINT_NAME, 
            TABLE_NAME, 
            REFERENCED_TABLE_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = :dbname 
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ";
    $fkStmt = $pdo->prepare($fkQuery);
    $fkStmt->execute([':dbname' => DB_NAME]);
    $foundFks = $fkStmt->fetchAll();

    $fkMap = [];
    foreach ($foundFks as $fk) {
        $fkMap[$fk['CONSTRAINT_NAME']] = [
            'table' => $fk['TABLE_NAME'],
            'ref'   => $fk['REFERENCED_TABLE_NAME']
        ];
    }
    $results['foreign_keys']['keys'] = $fkMap;

    $missingFks = [];
    foreach ($expectedForeignKeys as $fkName => $fkDef) {
        if (!isset($fkMap[$fkName])) {
            $missingFks[] = $fkName;
        }
    }

    if (empty($missingFks)) {
        $results['foreign_keys']['status'] = true;
        $results['foreign_keys']['message'] = sprintf('All %d relational foreign key constraints are valid and enforced.', count($expectedForeignKeys));
    } else {
        $results['foreign_keys']['status'] = false;
        $results['foreign_keys']['message'] = 'Missing foreign key constraints: ' . implode(', ', $missingFks);
    }

    // 4. Seed Data Verification
    $counts = [];
    foreach ($requiredTables as $table) {
        $cStmt = $pdo->query("SELECT COUNT(*) AS total FROM `{$table}`");
        $counts[$table] = (int)$cStmt->fetch()['total'];
    }
    $results['seeds']['counts'] = $counts;

    // Fetch sample users
    $uStmt = $pdo->query("SELECT id, full_name, email, role, status, password_hash FROM `users` ORDER BY id ASC");
    $users = $uStmt->fetchAll();
    $results['seeds']['samples']['users'] = $users;

    // Fetch sample doctors
    $dStmt = $pdo->query("SELECT id, full_name, specialty, room_number, active_status FROM `doctors` ORDER BY id ASC");
    $doctors = $dStmt->fetchAll();
    $results['seeds']['samples']['doctors'] = $doctors;

    // Fetch clinic settings
    $sStmt = $pdo->query("SELECT clinic_name, phone, email, opening_time, closing_time FROM `clinic_settings` LIMIT 1");
    $clinic = $sStmt->fetch();
    $results['seeds']['samples']['clinic'] = $clinic;

    // Check minimum expected seeds
    $seedOk = ($counts['users'] >= 3) &&
              ($counts['doctors'] >= 3) &&
              ($counts['doctor_schedules'] >= 10) &&
              ($counts['clinic_settings'] >= 1) &&
              ($counts['patient_profiles'] >= 1);

    $results['seeds']['status'] = $seedOk;
    $results['seeds']['message'] = $seedOk 
        ? 'All required seed records (users, patient profiles, doctors, schedules, settings) are present.'
        : 'Incomplete seed data detected.';

    // 5. Test Password Hash Verification
    $adminUser = null;
    foreach ($users as $u) {
        if ($u['email'] === 'admin@citycare.local') {
            $adminUser = $u;
            break;
        }
    }

    if ($adminUser && password_verify('Password123!', $adminUser['password_hash'])) {
        $results['auth_check']['status'] = true;
        $results['auth_check']['message'] = 'Password hash for admin@citycare.local verified successfully with password: Password123!';
    } else {
        $results['auth_check']['status'] = false;
        $results['auth_check']['message'] = 'Password verification failed for admin@citycare.local.';
    }

} catch (Exception $e) {
    $results['connection']['status'] = false;
    $results['connection']['message'] = $e->getMessage();
}

$allPass = $results['connection']['status'] && 
           $results['tables']['status'] && 
           $results['foreign_keys']['status'] && 
           $results['seeds']['status'] && 
           $results['auth_check']['status'];

// If run from command line, print formatted text output
if ($isCli) {
    echo "====================================================================\n";
    echo " CityCare Hospital - Database Verification Test (Phase 2)\n";
    echo "====================================================================\n\n";

    echo "[1] PDO Connection:       " . ($results['connection']['status'] ? "PASS" : "FAIL") . "\n";
    echo "    " . $results['connection']['message'] . "\n\n";

    echo "[2] Database Tables (8):  " . ($results['tables']['status'] ? "PASS" : "FAIL") . "\n";
    echo "    " . $results['tables']['message'] . "\n";
    echo "    Found: " . implode(', ', $results['tables']['found']) . "\n\n";

    echo "[3] Foreign Key Integrity:" . ($results['foreign_keys']['status'] ? "PASS" : "FAIL") . "\n";
    echo "    " . $results['foreign_keys']['message'] . "\n\n";

    echo "[4] Seed Data Counts:     " . ($results['seeds']['status'] ? "PASS" : "FAIL") . "\n";
    foreach ($results['seeds']['counts'] as $tbl => $cnt) {
        echo sprintf("    - %-20s : %d rows\n", $tbl, $cnt);
    }
    echo "\n";

    echo "[5] Password Authentication:" . ($results['auth_check']['status'] ? "PASS" : "FAIL") . "\n";
    echo "    " . $results['auth_check']['message'] . "\n\n";

    echo "====================================================================\n";
    echo " OVERALL STATUS: " . ($allPass ? "ALL TESTS PASSED SUCCESSFULLY" : "SOME CHECKS FAILED") . "\n";
    echo "====================================================================\n";
    exit($allPass ? 0 : 1);
}

// Otherwise, render full responsive web UI
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Verification - <?php echo APP_NAME; ?></title>
    <style>
        :root {
            --primary: #0284c7;
            --primary-dark: #0369a1;
            --success: #16a34a;
            --success-bg: #f0fdf4;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --gray-100: #f8fafc;
            --gray-200: #e2e8f0;
            --gray-700: #334155;
            --gray-900: #0f172a;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f1f5f9;
            color: var(--gray-900);
            margin: 0;
            padding: 30px 16px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .card {
            background: #ffffff;
            border-radius: 14px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            border: 1px solid var(--gray-200);
        }
        .header-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 8px;
        }
        h1 { margin: 0; font-size: 24px; color: var(--gray-900); }
        .overall-banner {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-weight: 600;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .banner-pass {
            background: var(--success-bg);
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .banner-fail {
            background: var(--danger-bg);
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .check-item {
            padding: 18px 0;
            border-bottom: 1px solid var(--gray-200);
        }
        .check-item:last-child { border-bottom: none; }
        .check-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .check-title {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .badge {
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-pass { background: #dcfce7; color: #15803d; }
        .badge-fail { background: #fee2e2; color: #b91c1c; }
        .badge-info { background: #e0f2fe; color: #0284c7; }
        .check-desc { color: var(--gray-700); font-size: 14px; margin: 0 0 10px 0; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 10px;
        }
        th, td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
        }
        th { background: #f8fafc; font-weight: 600; color: var(--gray-700); }
        code {
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: Consolas, monospace;
            font-size: 13px;
            color: #0369a1;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-top: 12px;
        }
        .subcard {
            background: #f8fafc;
            border-radius: 8px;
            padding: 14px;
            border: 1px solid #e2e8f0;
        }
        .subcard h4 { margin: 0 0 8px 0; font-size: 14px; color: var(--gray-900); }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .btn {
            display: inline-block;
            background: var(--primary);
            color: white;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.2s;
        }
        .btn:hover { background: var(--primary-dark); }
        .btn-secondary {
            background: #64748b;
        }
        .btn-secondary:hover { background: #475569; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header-title">
            <h1><?php echo APP_NAME; ?> - Database Verification</h1>
            <span class="badge badge-info">Phase 2 Verification</span>
        </div>
        <p style="color: #64748b; margin-top: 0;">Automated diagnostic report for local MariaDB/MySQL database integration.</p>

        <div class="overall-banner <?php echo $allPass ? 'banner-pass' : 'banner-fail'; ?>">
            <span><?php echo $allPass ? '&#10004;' : '&#10008;'; ?></span>
            <div>
                <strong><?php echo $allPass ? 'All Phase 2 Verification Checks Passed!' : 'Verification Incomplete'; ?></strong>
                <div style="font-size: 13px; font-weight: normal; margin-top: 2px;">
                    <?php echo $allPass ? 'Database schema, constraints, seed data, and PDO helper are operational.' : 'One or more database tests failed. Inspect the breakdown below.'; ?>
                </div>
            </div>
        </div>

        <!-- 1. Connection -->
        <div class="check-item">
            <div class="check-header">
                <div class="check-title">
                    <span>1. PDO Connection Helper</span>
                </div>
                <span class="badge <?php echo $results['connection']['status'] ? 'badge-pass' : 'badge-fail'; ?>">
                    <?php echo $results['connection']['status'] ? 'PASS' : 'FAIL'; ?>
                </span>
            </div>
            <p class="check-desc"><?php echo htmlspecialchars($results['connection']['message']); ?></p>
            <?php if (!empty($results['connection']['details'])): ?>
                <table>
                    <tbody>
                        <?php foreach ($results['connection']['details'] as $key => $val): ?>
                            <tr>
                                <td style="width: 200px; font-weight: 600;"><?php echo htmlspecialchars($key); ?></td>
                                <td><code><?php echo htmlspecialchars((string)$val); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- 2. Tables -->
        <div class="check-item">
            <div class="check-header">
                <div class="check-title">
                    <span>2. Normalized Table Schema (8 Tables)</span>
                </div>
                <span class="badge <?php echo $results['tables']['status'] ? 'badge-pass' : 'badge-fail'; ?>">
                    <?php echo $results['tables']['status'] ? 'PASS' : 'FAIL'; ?>
                </span>
            </div>
            <p class="check-desc"><?php echo htmlspecialchars($results['tables']['message']); ?></p>
            <table>
                <thead>
                    <tr>
                        <th>Table Name</th>
                        <th>Status</th>
                        <th>Row Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requiredTables as $tbl): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($tbl); ?></code></td>
                            <td>
                                <?php if (in_array($tbl, $results['tables']['found'], true)): ?>
                                    <span style="color: var(--success); font-weight: 600;">&#10004; Created</span>
                                <?php else: ?>
                                    <span style="color: var(--danger); font-weight: 600;">&#10008; Missing</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo isset($results['seeds']['counts'][$tbl]) ? $results['seeds']['counts'][$tbl] . ' rows' : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- 3. Foreign Keys -->
        <div class="check-item">
            <div class="check-header">
                <div class="check-title">
                    <span>3. Foreign Key Referential Integrity</span>
                </div>
                <span class="badge <?php echo $results['foreign_keys']['status'] ? 'badge-pass' : 'badge-fail'; ?>">
                    <?php echo $results['foreign_keys']['status'] ? 'PASS' : 'FAIL'; ?>
                </span>
            </div>
            <p class="check-desc"><?php echo htmlspecialchars($results['foreign_keys']['message']); ?></p>
            <table>
                <thead>
                    <tr>
                        <th>Constraint Name</th>
                        <th>Source Table</th>
                        <th>Referenced Table</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expectedForeignKeys as $fkName => $fkDef): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($fkName); ?></code></td>
                            <td><code><?php echo htmlspecialchars($fkDef['table']); ?></code></td>
                            <td><code><?php echo htmlspecialchars($fkDef['ref']); ?></code></td>
                            <td>
                                <?php if (isset($results['foreign_keys']['keys'][$fkName])): ?>
                                    <span style="color: var(--success); font-weight: 600;">&#10004; Active</span>
                                <?php else: ?>
                                    <span style="color: var(--danger); font-weight: 600;">&#10008; Missing</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- 4. Seed Data -->
        <div class="check-item">
            <div class="check-header">
                <div class="check-title">
                    <span>4. Seed Data Verification</span>
                </div>
                <span class="badge <?php echo $results['seeds']['status'] ? 'badge-pass' : 'badge-fail'; ?>">
                    <?php echo $results['seeds']['status'] ? 'PASS' : 'FAIL'; ?>
                </span>
            </div>
            <p class="check-desc"><?php echo htmlspecialchars($results['seeds']['message']); ?></p>
            
            <div class="grid-2">
                <div class="subcard">
                    <h4>Sample Accounts (Users)</h4>
                    <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--gray-700);">
                        <?php if (!empty($results['seeds']['samples']['users'])): ?>
                            <?php foreach ($results['seeds']['samples']['users'] as $u): ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($u['role']); ?>:</strong> 
                                    <?php echo htmlspecialchars($u['email']); ?>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="subcard">
                    <h4>Sample Doctors</h4>
                    <ul style="margin: 0; padding-left: 20px; font-size: 13px; color: var(--gray-700);">
                        <?php if (!empty($results['seeds']['samples']['doctors'])): ?>
                            <?php foreach ($results['seeds']['samples']['doctors'] as $d): ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($d['full_name']); ?></strong> 
                                    (<?php echo htmlspecialchars($d['specialty']); ?> - <?php echo htmlspecialchars($d['room_number']); ?>)
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- 5. Password Authentication -->
        <div class="check-item">
            <div class="check-header">
                <div class="check-title">
                    <span>5. Password Hashing & Verification</span>
                </div>
                <span class="badge <?php echo $results['auth_check']['status'] ? 'badge-pass' : 'badge-fail'; ?>">
                    <?php echo $results['auth_check']['status'] ? 'PASS' : 'FAIL'; ?>
                </span>
            </div>
            <p class="check-desc"><?php echo htmlspecialchars($results['auth_check']['message']); ?></p>
            <div style="font-size: 13px; color: var(--gray-700); background: #f8fafc; padding: 10px; border-radius: 6px;">
                Verified that <code>password_verify('Password123!', $hash)</code> matches the seeded bcrypt hash.
            </div>
        </div>

        <div class="actions">
            <a href="index.php" class="btn btn-secondary">&larr; Return to Home</a>
            <a href="http://localhost/phpmyadmin/index.php?route=/database/structure&db=hospital_system" target="_blank" class="btn">Open in phpMyAdmin &rarr;</a>
        </div>
    </div>
</div>
</body>
</html>

