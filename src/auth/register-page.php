<?php
// 1. Pull in the logic and session configuration first thing
require_once __DIR__ . '/logReg-errorHandle-logic.php';

// Put this at the absolute top of register-page.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$old = isset($_SESSION['old_input']) ? $_SESSION['old_input'] : [];
unset($_SESSION['old_input']); // Clear it so it won't persist forever
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Your Account</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.0/css/all.min.css">
    <link rel="stylesheet" href="../../public/assets/css/login-register/register-page.css">
      
</head>

<body>

    <div class="container">
        <!-- Left Decorative Column -->
        <div class="left-panel">
            <div class="illustration-card">
                <!-- Document + Plus Icon Embedded SVG -->
                <svg viewBox="0 0 200 200" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Document Background Base -->
                    <path
                        d="M40 30C40 24.4772 44.4772 20 50 20H125L160 55V150C160 155.523 155.523 160 150 160H50C44.4772 160 40 155.523 40 150V30Z"
                        fill="#0056d2" />
                    <!-- Folded corner effect -->
                    <path d="M125 20V45C125 50.5228 129.477 55 135 55H160L125 20Z" fill="#0041a3" opacity="0.3" />
                    <!-- Document Lines -->
                    <rect x="60" y="55" width="50" height="8" rx="4" fill="white" opacity="0.8" />
                    <rect x="60" y="75" width="80" height="8" rx="4" fill="white" opacity="0.8" />
                    <rect x="60" y="95" width="80" height="8" rx="4" fill="white" opacity="0.8" />
                    <rect x="60" y="115" width="60" height="8" rx="4" fill="white" opacity="0.8" />
                    <!-- Plus Circle Badge -->
                    <circle cx="140" cy="140" r="32" fill="#0056d2" stroke="white" stroke-width="6" />
                    <!-- Plus Sign -->
                    <path d="M140 126V154M126 140H154" stroke="white" stroke-width="6" stroke-linecap="round" />
                </svg>
            </div>
        </div>

        <!-- Right Form Column -->
        <div class="right-panel" <?=isActiveForm('register',$activeForm);?> >
            <div class="header-section">
                <h1>Create Your Account</h1>
                <p>REGISTER as a NEW MEMBER</p>
            </div>

            
            <!--===========================================
                    FORM SECTION 

            =========================================================-->
            <form action="register-page-logic.php" method="POST">
                <?= showError($errors['register']); ?>
                
                <!-- Full Name Input -->
                <div class="form-group">
                    <label for="fullname">Full Name</label>
                    <input type="text" id="fullname" name="name" value="<?= htmlspecialchars($old['name'] ?? '', ENT_QUOTES); ?>" placeholder="Enter full name" autocomplete="name" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES); ?>" placeholder="Enter email address" autocomplete="email" required>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($old['username'] ?? '', ENT_QUOTES); ?>" placeholder="Choose username" autocomplete="username" required>
                </div>

                <div class="form-group">
                    <label for="role">Member Role</label>
                    <select name="role" required>
                        <?php $selectedRole = $old['role'] ?? ''; ?>
                        <option value="">Select Your Role</option>
                        <option value="admin" <?= $selectedRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="student" <?= $selectedRole === 'student' ? 'selected' : ''; ?>>Student</option>
                        <option value="teacher" <?= $selectedRole === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                    </select>
                </div>

                <!-- Password Input -->
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" class="password-field" placeholder="Password" autocomplete="new-password" required>
                    <i class="fa-solid fa-eye toggle-password"></i>
                </div>

                <!-- Confirm Password Input (Changed name to "confirm_password") -->
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" name="confirm_password" class="password-field" placeholder="Confirm Password" autocomplete="new-password" required>
                    <i class="fa-solid fa-eye toggle-password"></i>
                </div>

                <!-- 2. FIXED: Added name="register" to fire the script -->
                <button type="submit" name="register" class="btn-register">Register</button>
            </form>

            <p class="footer-text">Already have an account? <a href="login-page.php">Login here</a></p>
        </div>
    </div>

    <script src="../../public/assets/js/login-register/login-register.js"></script>

</body>

</html>