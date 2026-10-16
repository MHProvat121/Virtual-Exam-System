<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['email'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Settings - Virtual Exam System</title>
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/teacher/teacher-header-sidebar-section.css">
    
    <style>
        /* ========================================================
           TEACHER SETTINGS & CENTERED CARD STYLESHEET
           ======================================================== */

        /* Centering the main dashboard area horizontally */
        .dashboard {
            padding: 40px 24px;
            width: 100%;
            max-width: 650px;      /* Optimal width for a settings form */
            margin: 0 auto;         /* Centers the container horizontally */
            box-sizing: border-box;
        }

        /* Alert Message Banners */
        .alert-box {
            padding: 12px 18px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            box-sizing: border-box;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Settings Card */
        .settings-card {
            background-color: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            padding: 32px;
            width: 100%;
            box-sizing: border-box;
        }

        .card-header {
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }

        .card-header h2 {
            font-size: 1.25rem;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .header-icon {
            color: #0266cc;
        }

        .card-subtitle {
            font-size: 0.88rem;
            color: #64748b;
            margin-top: 6px;
            margin-bottom: 0;
        }

        /* Password Form */
        .password-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #334155;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            color: #94a3b8;
            font-size: 1rem;
        }

        .input-wrapper input {
            width: 100%;
            padding: 11px 14px 11px 42px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.95rem;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .input-wrapper input:focus {
            border-color: #0266cc;
            box-shadow: 0 0 0 3px rgba(2, 102, 204, 0.15);
        }

        .form-actions {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
        }

        .save-btn {
            background-color: #0266cc;
            color: #ffffff;
            border: none;
            padding: 11px 24px;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s, transform 0.1s;
        }

        .save-btn:hover {
            background-color: #024da1;
            transform: translateY(-1px);
        }
    </style>
</head>

<body>
    <div class="main-layout">

        <!-- TEACHER HEADER AND SIDEBAR -->
        <?php require_once __DIR__ . '/teacher-header-sidebar-section.php'; ?>

        <!-- MAIN DASHBOARD CONTENT -->
        <main class="dashboard">

            <!-- Flash Alert Banners -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="alert-box alert-<?php echo ($_SESSION['flash_type'] ?? '') === 'success' ? 'success' : 'error'; ?>">
                    <i class="fa-solid <?php echo ($_SESSION['flash_type'] ?? '') === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <?php 
                        echo htmlspecialchars($_SESSION['flash_message']); 
                        unset($_SESSION['flash_message']);
                        unset($_SESSION['flash_type']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- CENTERED SETTINGS CARD -->
            <div class="settings-card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-lock header-icon"></i> Change Password</h2>
                    <p class="card-subtitle">Ensure your account is using a strong, secure password.</p>
                </div>

                <form action="teacher-setting-page-logic.php" method="POST" class="password-form">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-key input-icon"></i>
                            <input type="password" id="current_password" name="current_password" placeholder="Enter current password" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-lock-open input-icon"></i>
                            <input type="password" id="new_password" name="new_password" placeholder="Enter new password (min. 6 characters)" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-shield-halved input-icon"></i>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="update_password" class="save-btn">
                            <i class="fa-solid fa-floppy-disk"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>
        </main>

    </div>

    <!-- JavaScript Files -->
    <script src="../assets/js/common/sidebar.js"></script>
    <script src="../assets/js/common/header.js"></script>
</body>

</html>