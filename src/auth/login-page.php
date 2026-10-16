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
    <title>Virtual Exam System - Login</title>
    <!-- Google Fonts for crisp typography -->
    <link rel="preconnect" href="https://googleapis.com">
    <link rel="preconnect" href="https://gstatic.com" crossorigin>
    <link href="https://googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.0/css/all.min.css">
    <link rel="stylesheet" href="../../public/assets/css/login-register/login-page.css">
</head>

<body>

    <!-- Left Column: Branding Assets -->
    <section class="brand-section">
        <div class="brand-container">
            <!-- Reconstructed SVG matching the exam cap & open book icon -->
            <svg class="logo" viewBox="0 0 100 100" fill="none" xmlns="http://w3.org">
                <path d="M50 15 C33.5 15 20 28.5 20 45 C20 53.5 23.5 61 29 66.5" stroke="#0c2356" stroke-width="4"
                    stroke-linecap="round" />
                <path d="M71 66.5 C76.5 61 80 53.5 80 45 C80 28.5 66.5 15 50 15" stroke="#0c2356" stroke-width="4"
                    stroke-linecap="round" />
                <path d="M50 25 L82 37 L50 49 L18 37 Z" fill="#0c2356" />
                <path d="M30 42 V54 C30 60 38 64 50 64 C62 64 70 60 70 54 V42" fill="#0c2356" />
                <path d="M73 38 V52 L75 56 L77 52 V38" fill="#0c2356" stroke="#0c2356" stroke-width="1" />
                <circle cx="75" cy="56" r="1.5" fill="#0c2356" />
                <path d="M18 69 H82 V73 H18 Z" fill="#0c2356" rx="2" />
                <path d="M22 75 C35 75 46 78 50 82 C54 78 65 75 78 75" stroke="#0c2356" stroke-width="4"
                    stroke-linecap="round" />
            </svg>
            <h1 class="brand-title">Virtual Exam System</h1>
            <p class="brand-tagline">Secure | Smart | Seamless</p>
        </div>
    </section>

    <!-- Right Column: Login Card Interface -->
    <section class="form-section">
        <div class="login-card" <?=isActiveForm('login',$activeForm);?> >

            <!--1) header-->
            <div class="card-header">
                <h2>Welcome Back</h2>
                <p>Sign in to continue</p>
            </div>

            <form action="login-page-logic.php" method="POST">
                <?= showError($errors['login']); ?>

            <!-- 2) name input -->
            <div class="form-group">
                <label for="username">Username / Email</label>
                <input type="text" id="username" name="email" value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter username" autocomplete="username" required>
            </div>

            <!-- 3) password input -->
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" class="password-field" placeholder="Password" autocomplete="new-password" required>
                <i class="fa-solid fa-eye toggle-password"></i>
            </div>

            <!-- 4) checkbox options -->
            <div class="form-options">
                <label class="remember-me">
                    <input type="checkbox" id="remember">
                    <span>Remember me</span>
                </label>
                <a href="#" class="forgot-password">Forgot Password?</a>
            </div>

            <!-- 5) login button -->
            <!-- ADDED name="login" HERE so the isset($_POST['login']) check succeeds -->
            <button type="submit" name="login" class="login-btn">Login</button>

            <div class="card-footer">
                Don't have an account? <a href="register-page.php">Register here</a>
            </div>
        </div>
    </section>

    <script src="../../public/assets/js/login-register/login-register.js"></script>

</body>

</html>