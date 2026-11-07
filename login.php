<?php
/**
 * =============================================================================
 * CityCare Hospital - User Login Page
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . getDashboardUrl());
    exit;
}

$pageTitle = 'Sign In';
$errorMessage = '';
$emailInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    
    if (!verifyCsrfToken($submittedToken)) {
        $errorMessage = 'Security validation failed (Invalid or expired CSRF token). Please try again.';
    } else {
        $emailInput = trim((string)($_POST['email'] ?? ''));
        $passwordInput = (string)($_POST['password'] ?? '');

        $result = attemptLogin($emailInput, $passwordInput);

        if ($result['success']) {
            setFlash('success', $result['message']);
            header('Location: ' . $result['redirect']);
            exit;
        } else {
            $errorMessage = $result['message'];
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
    <div class="card">
        <div class="auth-header">
            <h1>Sign In to CityCare</h1>
            <p>Access your patient bookings, clinical queues, or administration</p>
        </div>

        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger">
                <span>&#10008;</span>
                <div><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php endif; ?>

        <!-- Google Sign-In Option -->
        <a href="<?php echo getGoogleLoginUrl(); ?>" class="btn-google">
            <svg class="google-icon-svg" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <span>Continue with Google</span>
        </a>

        <div class="auth-divider">
            <span>OR SIGN IN WITH EMAIL</span>
        </div>

        <form method="POST" action="login.php" autocomplete="off">
            <?php echo csrfField(); ?>

            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    class="form-control" 
                    placeholder="e.g. patient@citycare.local"
                    value="<?php echo htmlspecialchars($emailInput, ENT_QUOTES, 'UTF-8'); ?>"
                    required 
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    class="form-control" 
                    placeholder="Enter your account password"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary btn-block" style="padding: 12px; font-size: 15px;">
                Sign In &rarr;
            </button>
        </form>

        <!-- Interactive Demo Credentials Helper for Evaluators & Students -->
        <div class="demo-box">
            <div class="demo-title">&#128273; Fast Evaluation Demo Accounts (Password: <code>Password123!</code>):</div>
            <div class="demo-pills">
                <button type="button" class="demo-btn" onclick="fillDemo('admin@citycare.local', 'Password123!')">
                    Admin Account
                </button>
                <button type="button" class="demo-btn" onclick="fillDemo('staff@citycare.local', 'Password123!')">
                    Staff Account
                </button>
                <button type="button" class="demo-btn" onclick="fillDemo('patient@citycare.local', 'Password123!')">
                    Patient Account
                </button>
            </div>
        </div>

        <div style="text-align: center; margin-top: 20px; font-size: 14px; color: var(--gray-500);">
            Don't have an account yet? 
            <a href="register.php" style="color: var(--primary); font-weight: 600; text-decoration: none;">Register as a new patient</a>
        </div>
    </div>
</div>

<script>
function fillDemo(email, pass) {
    document.getElementById('email').value = email;
    document.getElementById('password').value = pass;
    document.getElementById('password').focus();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

