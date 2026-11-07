<?php
/**
 * =============================================================================
 * CityCare Hospital - 403 Forbidden Access Page
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';

http_response_code(403);
$pageTitle = 'Access Denied (403)';
$user = currentUser();

require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper" style="max-width: 540px; text-align: center;">
    <div class="card" style="padding: 40px 24px;">
        <div style="font-size: 54px; color: var(--danger); margin-bottom: 12px;">&#9888;</div>
        <h1 style="margin: 0 0 10px 0; font-size: 26px; color: var(--gray-900);">403 - Access Denied</h1>
        <p style="color: var(--gray-500); margin-bottom: 24px;">
            You do not possess the required clinical authorization or administrative privileges to access this area.
        </p>

        <?php if ($user): ?>
            <div style="background: var(--gray-100); padding: 14px; border-radius: var(--radius-sm); margin-bottom: 24px; font-size: 13px;">
                Signed in as: <strong><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                Current Role: <span class="role-tag role-tag-<?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars(strtoupper($user['role']), ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            
            <a href="<?php echo getDashboardUrl(); ?>" class="btn btn-primary" style="padding: 10px 20px;">
                &larr; Return to Your Authorized Portal
            </a>
        <?php else: ?>
            <a href="login.php" class="btn btn-primary" style="padding: 10px 20px;">
                Sign In with Authorized Account
            </a>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

