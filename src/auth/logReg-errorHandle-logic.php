<?php
// Start the session safely if it hasn't been started yet
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Map the errors exactly to the session keys used in your logic scripts
$errors = [
    'login'    => $_SESSION['errors']['login'] ?? '', 
    'register' => $_SESSION['register_error'] ?? ''
];

// Map the success flash message if it exists
$successMessage = $_SESSION['register_success'] ?? '';

// Determine which form should be flagged active
$activeForm = $_SESSION['active_form'] ?? 'login'; // Default to login if not specified

// 2. FIXED: Clear the actual active session error keys so they don't linger forever
unset($_SESSION['errors']['login']); // Wipes the specific login error array key
unset($_SESSION['register_error']);
unset($_SESSION['register_success']);
unset($_SESSION['active_form']);

/**
 * Renders an error message block if an error exists.
 * Features built-in XSS protection for safety.
 */
function showError($error) {
    return !empty($error) ? "<p class='error-message'><i class='fa-solid fa-circle-exclamation'></i> " . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</p>" : '';
}

/**
 * Renders a success message block if a success status exists.
 */
function showSuccess($success) {
    return !empty($success) ? "<p class='success-message'><i class='fa-solid fa-circle-check'></i> " . htmlspecialchars($success, ENT_QUOTES, 'UTF-8') . "</p>" : '';
}

/**
 * Returns the structural CSS indicator for the wrapper column
 */
function isActiveForm($formName, $activeForm) {
    return $formName === $activeForm ? 'active' : '';
}
?>