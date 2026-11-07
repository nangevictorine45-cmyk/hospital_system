<?php
/**
 * =============================================================================
 * CityCare Hospital - User & Patient Account Management
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Role Guard: Administrator only
requireLogin(['admin']);

$user = currentUser();
$pdo = getDBConnection();
$errorMessage = '';

// Handle Status Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_user_status') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $errorMessage = 'Invalid CSRF token.';
    } else {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $newStatus    = trim((string)($_POST['new_status'] ?? 'active'));

        // Prevent admin from suspending themselves
        if ($targetUserId === (int)$user['id']) {
            $errorMessage = 'You cannot suspend your own administrative account.';
        } else {
            $upStmt = $pdo->prepare("UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id");
            $upStmt->execute([':status' => $newStatus, ':id' => $targetUserId]);

            logAuditEvent((int)$user['id'], 'USER_STATUS_CHANGE', 'users', $targetUserId, "User ID #{$targetUserId} status changed to {$newStatus}");
            setFlash('success', 'User account status updated.');
            header('Location: users.php');
            exit;
        }
    }
}

$roleFilter   = isset($_GET['role']) ? trim((string)$_GET['role']) : '';
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$search       = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$sql = "
    SELECT u.id, u.full_name, u.email, u.phone, u.role, u.status, u.google_id, u.auth_provider, u.avatar_url, u.created_at,
           p.date_of_birth, p.gender
    FROM users u
    LEFT JOIN patient_profiles p ON u.id = p.user_id
    WHERE 1=1
";
$params = [];

if (!empty($roleFilter)) {
    $sql .= " AND u.role = :role";
    $params[':role'] = $roleFilter;
}

if (!empty($statusFilter)) {
    $sql .= " AND u.status = :status";
    $params[':status'] = $statusFilter;
}

if (!empty($search)) {
    $sql .= " AND (u.full_name LIKE :q OR u.email LIKE :q OR u.phone LIKE :q)";
    $params[':q'] = "%{$search}%";
}

$sql .= " ORDER BY u.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usersList = $stmt->fetchAll();

$pageTitle = 'User Account Management';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card-header">
    <div>
        <h1 class="card-title">User &amp; Patient Account Management</h1>
        <p style="margin: 4px 0 0 0; color: var(--gray-500); font-size: 14px;">
            View and manage patient, clinic staff, and administrator credentials and Google links.
        </p>
    </div>
    <a href="dashboard.php" class="btn btn-secondary" style="font-size: 13px;">
        &larr; Return to Admin Dashboard
    </a>
</div>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-danger">
        <span>&#10008;</span>
        <div><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
<?php endif; ?>

<!-- Filters -->
<div class="card" style="padding: 16px; margin-bottom: 20px;">
    <form method="GET" action="users.php" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 140px;">
            <label class="form-label">Role</label>
            <select name="role" class="form-control">
                <option value="">All Roles</option>
                <option value="patient" <?php echo ($roleFilter === 'patient') ? 'selected' : ''; ?>>Patient</option>
                <option value="staff" <?php echo ($roleFilter === 'staff') ? 'selected' : ''; ?>>Staff</option>
                <option value="admin" <?php echo ($roleFilter === 'admin') ? 'selected' : ''; ?>>Administrator</option>
            </select>
        </div>

        <div style="flex: 1; min-width: 140px;">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="active" <?php echo ($statusFilter === 'active') ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo ($statusFilter === 'inactive') ? 'selected' : ''; ?>>Inactive / Suspended</option>
            </select>
        </div>

        <div style="flex: 2; min-width: 200px;">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" placeholder="Name, Email, or Phone" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="padding: 10px 16px;">Filter</button>
            <a href="users.php" class="btn btn-secondary" style="padding: 10px 14px;">Reset</a>
        </div>
    </form>
</div>

<!-- Users Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Role</th>
                    <th>Auth Provider</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($usersList)): ?>
                    <?php foreach ($usersList as $u): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if (!empty($u['avatar_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($u['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--gray-200); display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; color: var(--gray-600);">
                                            <?php echo strtoupper(substr($u['full_name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?php echo htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <div style="font-size: 12px; color: var(--gray-500);"><?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="role-tag role-tag-<?php echo htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo strtoupper($u['role']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($u['google_id'])): ?>
                                    <span class="badge" style="background: #eaf1fb; color: #1a73e8;">
                                        &#10004; Google Connected
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: var(--gray-100); color: var(--gray-700);">
                                        Email / Password
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['status'] === 'active'): ?>
                                    <span class="badge" style="background: var(--success-light); color: var(--success);">Active</span>
                                <?php else: ?>
                                    <span class="badge" style="background: var(--danger-light); color: var(--danger);">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px; color: var(--gray-500); white-space: nowrap;">
                                <?php echo date('M d, Y', strtotime($u['created_at'])); ?>
                            </td>
                            <td>
                                <?php if ((int)$u['id'] !== (int)$user['id']): ?>
                                    <form method="POST" action="users.php" style="display: inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle_user_status">
                                        <input type="hidden" name="target_user_id" value="<?php echo $u['id']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo ($u['status'] === 'active') ? 'inactive' : 'active'; ?>">
                                        <button type="submit" class="btn btn-secondary" style="padding: 4px 10px; font-size: 11px;">
                                            <?php echo ($u['status'] === 'active') ? 'Suspend' : 'Activate'; ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size: 11px; color: var(--gray-400);">Current Session</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--gray-500); padding: 30px;">
                            No user accounts found matching query.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

