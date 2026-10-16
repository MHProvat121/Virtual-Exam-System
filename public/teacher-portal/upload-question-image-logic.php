<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION['user_id']) && !isset($_SESSION['teacher_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['question_image'])) {
    $file = $_FILES['question_image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error code: ' . $file['error']]);
        exit();
    }

    // Validate size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit.']);
        exit();
    }

    // Validate extension
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, GIF, or WEBP images are allowed.']);
        exit();
    }

    // Define target directory
    $uploadDir = __DIR__ . '/../../public/assets/images/question_images/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newFileName = 'qimg_' . time() . '_' . uniqid() . '.' . $ext;
    $targetPath  = $uploadDir . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $publicUrl = '../assets/images/question_images/' . $newFileName;
        echo json_encode([
            'success' => true,
            'url'     => $publicUrl
        ]);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded image.']);
        exit();
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No image uploaded.']);
    exit();
}