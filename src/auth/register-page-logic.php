<?php
session_start();
require_once '../config/config.php'; // Ensure this file creates a $conn PDO instance

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    // 1. Capture and sanitize form inputs
    $name             = trim($_POST['name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $username         = trim($_POST['username'] ?? '');
    $role             = $_POST['role'] ?? 'user'; // Provide a default role fallback if needed
    $raw_password     = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // 2. Validation: Check if passwords match
    if ($raw_password !== $confirm_password) {
        $_SESSION['register_error'] = 'Passwords do not match!';
        $_SESSION['active_form'] = 'register';
        
        // Store their data so they don't have to re-type it
        $_SESSION['old_input'] = [
            'name'     => $name,
            'email'    => $email,
            'username' => $username,
            'role'     => $role
        ];
        
        header("Location: register-page.php"); 
        exit();
    }

    try {
        // 3. Fixed: Changed $pdo to $conn, and explicitly selected 'email' and 'username' columns
        $stmt = $conn->prepare("SELECT email, username FROM `users-table` WHERE email = :email OR username = :username LIMIT 1");
        $stmt->execute([
            ':email'    => $email,
            ':username' => $username
        ]);
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            // Fixed: This conditional logic now works accurately because the columns are selected
            if ($existingUser['email'] === $email) {
                $_SESSION['register_error'] = 'Email is already registered!';
            } else {
                $_SESSION['register_error'] = 'Username is already taken!';
            }
            $_SESSION['active_form'] = 'register';
            header("Location: register-page.php");
            exit();
        }

        // 4. Securely hash the password (ONCE)
        $hashed_password = password_hash($raw_password, PASSWORD_DEFAULT);

        // 5. Insert new user into the database using PDO named parameters
        $insertQuery = "INSERT INTO `users-table` (name, username, email, password, role) 
                        VALUES (:name, :username, :email, :password, :role)";
        
        $insertStmt = $conn->prepare($insertQuery);
        $success = $insertStmt->execute([
            ':name'     => $name,
            ':username' => $username,
            ':email'    => $email,
            ':password' => $hashed_password,
            ':role'     => $role
        ]);

        if ($success) {
            $_SESSION['register_success'] = 'Registration successful! You can now log in.';
            $_SESSION['active_form'] = 'login'; 
            header("Location: login-page.php"); 
        } else {
            $_SESSION['register_error'] = 'Something went wrong. Please try again.';
            $_SESSION['active_form'] = 'register';
            header("Location: register-page.php");
        }
        exit();

    } catch (PDOException $e) {
        // Handle database errors elegantly without breaking script flow completely
        error_log("Database Error: " . $e->getMessage());
        $_SESSION['register_error'] = 'An unexpected database error occurred.';
        $_SESSION['active_form'] = 'register';
        header("Location: register-page.php");
        exit();
    }
} else {
    header("Location: register-page.php");
    exit();
}