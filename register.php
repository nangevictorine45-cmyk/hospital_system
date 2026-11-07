<?php
/**
 * =============================================================================
 * CityCare Hospital - Patient Registration Page
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

$pageTitle = 'Patient Registration';
$generalError = '';
$fieldErrors = [];
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    
    if (!verifyCsrfToken($submittedToken)) {
        $generalError = 'Security validation failed (Invalid or expired CSRF token). Please try again.';
    } else {
        $formData = $_POST;
        $result = registerPatient($_POST);

        if ($result['success']) {
            setFlash('success', $result['message']);
            header('Location: login.php');
            exit;
        } else {
            $generalError = $result['message'];
            $fieldErrors = $result['errors'];
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper" style="max-width: 680px;">
    <div class="card">
        <div class="auth-header">
            <h1>Create Your Patient Account</h1>
            <p>Register once to book consultations and monitor live hospital queue tickets</p>
        </div>

        <?php if (!empty($generalError)): ?>
            <div class="alert alert-danger">
                <span>&#10008;</span>
                <div><?php echo htmlspecialchars($generalError, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php endif; ?>

        <!-- 1-Click Fast Google Registration -->
        <a href="<?php echo getGoogleLoginUrl(); ?>" class="btn-google">
            <svg class="google-icon-svg" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            <span>Fast Sign Up with Google</span>
        </a>

        <div class="auth-divider">
            <span>OR REGISTER WITH DETAILED FORM</span>
        </div>

        <form method="POST" action="register.php" autocomplete="off">
            <?php echo csrfField(); ?>

            <h3 style="font-size: 16px; margin: 0 0 16px 0; color: var(--primary-dark); border-bottom: 2px solid var(--primary-light); padding-bottom: 6px;">
                1. Account Credentials
            </h3>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
                <div class="form-group">
                    <label for="full_name" class="form-label">Full Legal Name *</label>
                    <input 
                        type="text" 
                        id="full_name" 
                        name="full_name" 
                        class="form-control <?php echo isset($fieldErrors['full_name']) ? 'is-invalid' : ''; ?>" 
                        placeholder="e.g. Jane Smith"
                        value="<?php echo htmlspecialchars($formData['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        required
                    >
                    <?php if (isset($fieldErrors['full_name'])): ?>
                        <div class="field-error"><?php echo htmlspecialchars($fieldErrors['full_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Email Address *</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-control <?php echo isset($fieldErrors['email']) ? 'is-invalid' : ''; ?>" 
                        placeholder="e.g. jane.smith@example.com"
                        value="<?php echo htmlspecialchars($formData['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        required
                    >
                    <?php if (isset($fieldErrors['email'])): ?>
                        <div class="field-error"><?php echo htmlspecialchars($fieldErrors['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="phone" class="form-label">Mobile Phone Number *</label>
                <input 
                    type="tel" 
                    id="phone" 
                    name="phone" 
                    class="form-control <?php echo isset($fieldErrors['phone']) ? 'is-invalid' : ''; ?>" 
                    placeholder="e.g. +1 (555) 234-5678"
                    value="<?php echo htmlspecialchars($formData['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    required
                >
                <?php if (isset($fieldErrors['phone'])): ?>
                    <div class="field-error"><?php echo htmlspecialchars($fieldErrors['phone'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
                <div class="form-group">
                    <label for="password" class="form-label">Password * (Min 8 characters)</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-control <?php echo isset($fieldErrors['password']) ? 'is-invalid' : ''; ?>" 
                        placeholder="Choose a secure password"
                        required
                    >
                    <?php if (isset($fieldErrors['password'])): ?>
                        <div class="field-error"><?php echo htmlspecialchars($fieldErrors['password'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password *</label>
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password" 
                        class="form-control <?php echo isset($fieldErrors['confirm_password']) ? 'is-invalid' : ''; ?>" 
                        placeholder="Re-type password"
                        required
                    >
                    <?php if (isset($fieldErrors['confirm_password'])): ?>
                        <div class="field-error"><?php echo htmlspecialchars($fieldErrors['confirm_password'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <h3 style="font-size: 16px; margin: 24px 0 16px 0; color: var(--primary-dark); border-bottom: 2px solid var(--primary-light); padding-bottom: 6px;">
                2. Patient Healthcare Demographics
            </h3>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
                <div class="form-group">
                    <label for="date_of_birth" class="form-label">Date of Birth</label>
                    <input 
                        type="date" 
                        id="date_of_birth" 
                        name="date_of_birth" 
                        class="form-control"
                        value="<?php echo htmlspecialchars($formData['date_of_birth'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="gender" class="form-label">Gender</label>
                    <select id="gender" name="gender" class="form-control">
                        <option value="">-- Select Gender --</option>
                        <option value="male" <?php echo (($formData['gender'] ?? '') === 'male') ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo (($formData['gender'] ?? '') === 'female') ? 'selected' : ''; ?>>Female</option>
                        <option value="other" <?php echo (($formData['gender'] ?? '') === 'other') ? 'selected' : ''; ?>>Other / Prefer not to say</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="address" class="form-label">Residential Address</label>
                <textarea 
                    id="address" 
                    name="address" 
                    rows="2" 
                    class="form-control" 
                    placeholder="Street, City, Postal Code"
                ><?php echo htmlspecialchars($formData['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
                <div class="form-group">
                    <label for="emergency_contact_name" class="form-label">Emergency Contact Name</label>
                    <input 
                        type="text" 
                        id="emergency_contact_name" 
                        name="emergency_contact_name" 
                        class="form-control"
                        placeholder="e.g. John Smith (Spouse)"
                        value="<?php echo htmlspecialchars($formData['emergency_contact_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="emergency_contact_phone" class="form-label">Emergency Contact Phone</label>
                    <input 
                        type="tel" 
                        id="emergency_contact_phone" 
                        name="emergency_contact_phone" 
                        class="form-control"
                        placeholder="e.g. +1 (555) 987-6543"
                        value="<?php echo htmlspecialchars($formData['emergency_contact_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>
            </div>

            <div style="margin-top: 24px;">
                <button type="submit" class="btn btn-primary btn-block" style="padding: 14px; font-size: 16px;">
                    Complete Registration &rarr;
                </button>
            </div>
        </form>

        <div style="text-align: center; margin-top: 20px; font-size: 14px; color: var(--gray-500);">
            Already have an account? 
            <a href="login.php" style="color: var(--primary); font-weight: 600; text-decoration: none;">Sign in here</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

