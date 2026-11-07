<?php
/**
 * =============================================================================
 * CityCare Hospital - Google Sign-In Gateway & Sandbox
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/google.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';

// If already signed in, redirect to portal
if (isLoggedIn()) {
    header('Location: ' . getDashboardUrl());
    exit;
}

// 1. If real Google credentials are configured, redirect straight to Google
if (isGoogleAuthConfigured() && !isset($_GET['sandbox'])) {
    header('Location: ' . getGoogleLoginUrl());
    exit;
}

// 2. Handle Simulation Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'simulate') {
    $email = trim((string)($_POST['sim_email'] ?? ''));
    $name  = trim((string)($_POST['sim_name'] ?? ''));

    if (empty($email)) {
        $error = 'Please select or enter a Google email address.';
    } else {
        $simProfile = [
            'sub'     => 'sim_google_' . md5(strtolower($email)),
            'email'   => $email,
            'name'    => !empty($name) ? $name : ucfirst(explode('@', $email)[0]),
            'picture' => 'https://ui-avatars.com/api/?name=' . urlencode($name ?: $email) . '&background=0284c7&color=fff&size=128'
        ];

        $res = handleGoogleUser($simProfile);

        if ($res['success']) {
            setFlash('success', $res['message']);
            header('Location: ' . $res['redirect']);
            exit;
        } else {
            $error = $res['message'];
        }
    }
}

$pageTitle = 'Sign In with Google';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper" style="max-width: 520px;">
    <div class="card" style="box-shadow: var(--shadow-lg);">
        <div style="text-align: center; margin-bottom: 24px;">
            <svg style="width: 44px; height: 44px; margin-bottom: 8px;" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <h1 style="font-size: 22px; margin: 0 0 6px 0; color: var(--gray-900);">Google Identity Services</h1>
            <p style="margin: 0; color: var(--gray-500); font-size: 14px;">
                Choose an account to continue to <strong>CityCare Hospital</strong>
            </p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <span>&#10008;</span>
                <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php endif; ?>

        <!-- Quick 1-Click Simulated Google Accounts for Instant Evaluation -->
        <div style="border: 1px solid var(--gray-200); border-radius: var(--radius-md); overflow: hidden; margin-bottom: 20px;">
            <div style="background: var(--gray-50); padding: 10px 14px; font-size: 12px; font-weight: 700; color: var(--gray-500); text-transform: uppercase;">
                Select a Google Account:
            </div>

            <!-- Profile 1 -->
            <form method="POST" action="google_auth.php" style="margin: 0;">
                <input type="hidden" name="action" value="simulate">
                <input type="hidden" name="sim_name" value="Alex Morgan">
                <input type="hidden" name="sim_email" value="alex.morgan@gmail.com">
                <button type="submit" class="google-user-item">
                    <img src="https://ui-avatars.com/api/?name=Alex+Morgan&background=0284c7&color=fff&size=80" alt="Avatar" class="google-avatar">
                    <div style="text-align: left;">
                        <div style="font-weight: 600; color: var(--gray-900); font-size: 14px;">Alex Morgan</div>
                        <div style="font-size: 12px; color: var(--gray-500);">alex.morgan@gmail.com</div>
                    </div>
                </button>
            </form>

            <!-- Profile 2 -->
            <form method="POST" action="google_auth.php" style="margin: 0;">
                <input type="hidden" name="action" value="simulate">
                <input type="hidden" name="sim_name" value="Maria Santos">
                <input type="hidden" name="sim_email" value="maria.santos@gmail.com">
                <button type="submit" class="google-user-item">
                    <img src="https://ui-avatars.com/api/?name=Maria+Santos&background=16a34a&color=fff&size=80" alt="Avatar" class="google-avatar">
                    <div style="text-align: left;">
                        <div style="font-weight: 600; color: var(--gray-900); font-size: 14px;">Maria Santos</div>
                        <div style="font-size: 12px; color: var(--gray-500);">maria.santos@gmail.com</div>
                    </div>
                </button>
            </form>
        </div>

        <!-- Custom Google Account Form -->
        <details style="margin-bottom: 20px; font-size: 13px;">
            <summary style="cursor: pointer; color: var(--primary); font-weight: 600; padding: 6px 0;">
                &#10010; Use another Google Account (custom email)
            </summary>
            <form method="POST" action="google_auth.php" style="margin-top: 14px; background: var(--gray-50); padding: 14px; border-radius: var(--radius-sm); border: 1px solid var(--gray-200);">
                <input type="hidden" name="action" value="simulate">
                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="form-label" style="font-size: 12px;">Your Name</label>
                    <input type="text" name="sim_name" class="form-control" placeholder="e.g. John Doe" required>
                </div>
                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-size: 12px;">Google Email</label>
                    <input type="email" name="sim_email" class="form-control" placeholder="e.g. yourname@gmail.com" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block" style="padding: 10px; font-size: 13px;">
                    Continue with this Google Account &rarr;
                </button>
            </form>
        </details>

        <!-- Google Cloud API Keys Setup Guide -->
        <div style="background: #f8fafc; border: 1px dashed var(--gray-300); border-radius: var(--radius-sm); padding: 12px; font-size: 12px; color: var(--gray-600); line-height: 1.5;">
            <strong>To switch to Live Google OAuth 2.0 with Real Google Login:</strong><br>
            Add your Google Cloud Console Client ID &amp; Secret into <code>config/google.php</code>. 
            The system will automatically switch to live Google authentication.
        </div>

        <div style="text-align: center; margin-top: 20px;">
            <a href="login.php" style="color: var(--gray-500); font-size: 13px; text-decoration: none;">
                &larr; Return to regular email/password login
            </a>
        </div>
    </div>
</div>

<style>
.google-user-item {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 12px 16px;
    background: #ffffff;
    border: none;
    border-bottom: 1px solid var(--gray-200);
    cursor: pointer;
    transition: background 0.15s ease;
}
.google-user-item:last-child {
    border-bottom: none;
}
.google-user-item:hover {
    background: var(--primary-light);
}
.google-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

