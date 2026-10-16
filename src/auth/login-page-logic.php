<?php

// 1. Start the session safely if it hasn't been started yet
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Include your database config file
require_once __DIR__ . '/../config/config.php'; 

// 3. Only execute processing if the form was actually submitted via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    
    // Initialize error array and old input storage safely inside the POST block
    $_SESSION['errors'] = ['login' => ''];
    $_SESSION['old_input'] = [];
    
    // Grab and trim the inputs from your HTML form
    $identifier = isset($_POST['email']) ? trim($_POST['email']) : ''; // Username or Email
    $password   = isset($_POST['password']) ? trim($_POST['password']) : '';

    // Keep track of the identifier so the user doesn't have to retype it on failure
    $_SESSION['old_input']['email'] = $identifier;

    // Basic validation
    if (empty($identifier) || empty($password)) {
        $_SESSION['errors']['login'] = "Please fill in all fields.";
        header("Location: login-page.php");
        exit;
    }

    try {
        // 4. Fetch the user row (including the 'role' column) from users-table
        $stmt = $conn->prepare("SELECT * FROM `users-table` WHERE email = :identifier OR username = :identifier LIMIT 1");
        $stmt->execute(['identifier' => $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

                                


        // 5. Verify user existence and password hash
        if ($user && password_verify($password, $user['password'])) {
            
            // Regenerate session ID for security against session fixation
            session_regenerate_id(true);

            // Set up basic user session variables
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['email']     = $user['email'];
            $_SESSION['user_role'] = strtolower(trim($user['role'])); 

            // --- TEACHER PROFILE LOOKUP ---
            // If the user is a teacher, fetch teacher_id ('92880') & full_name from 'teachers' table
            if ($_SESSION['user_role'] === 'teacher') {
                $teacherStmt = $conn->prepare("SELECT teacher_id, full_name FROM `teachers` WHERE email = :email LIMIT 1");
                $teacherStmt->execute(['email' => $user['email']]);
                $teacherProfile = $teacherStmt->fetch(PDO::FETCH_ASSOC);

                if ($teacherProfile) {
                    $_SESSION['teacher_id']   = $teacherProfile['teacher_id']; // Stores '92880'
                    $_SESSION['teacher_name'] = $teacherProfile['full_name'];  // Stores 'Md. ALAUDDIN'
                } else {
                    // Fallback if record is missing in teachers table
                    $_SESSION['teacher_id']   = $user['username'];
                    $_SESSION['teacher_name'] = $user['username'];
                }
            }

            // Clean up errors and old inputs on successful login
            unset($_SESSION['errors']);
            unset($_SESSION['old_input']);

            // Handle the "Remember Me" checkbox cookie if it was set in your form
            if (isset($_POST['remember'])) {
                setcookie("remember_user", $identifier, time() + (86400 * 30), "/", "", false, true);
            } else {
                if (isset($_COOKIE['remember_user'])) {
                    setcookie("remember_user", "", time() - 3600, "/");
                }
            }

            // 6. Route to the matching dashboard based on the user's role
            switch ($_SESSION['user_role']) {
                case 'admin':
                    header("Location: ../../public/admin-portal/admin-dashboard-page.php");
                    break;
                case 'teacher':
                    header("Location: ../../public/teacher-portal/teacher-dashboard-page.php");
                    break;
                case 'student':
                default:
                    header("Location: ../../public/student-portal/student-dashboard-page.php");
                    break;
            }
            exit;
            
        } else {
            $_SESSION['errors']['login'] = "Invalid username/email or password.";
            header("Location: login-page.php");
            exit;
        }

    } catch (PDOException $e) {
        error_log("Login Error: " . $e->getMessage());
        $_SESSION['errors']['login'] = "An unexpected error occurred. Please try again later.";
        header("Location: login-page.php");
        exit;
    }
} else {
    header("Location: login-page.php");
    exit;
}