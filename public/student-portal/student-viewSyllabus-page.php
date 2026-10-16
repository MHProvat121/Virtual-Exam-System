<?php
// =========================================================================
// STUDENT VIEW SYLLABUS PAGE
// View course syllabus details, file downloads, and course credits
// =========================================================================

// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. RBAC Session Gatekeeper - Student or authenticated users
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Include Database Configuration
require_once __DIR__ . '/../../src/config/config.php';

// Handle PDO connection standard
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

$id = (int)($_GET['id'] ?? $_GET['exam_id'] ?? 0);

// -------------------------------------------------------------------------
// 4. FETCH BATCH & SYLLABUS DATA
// -------------------------------------------------------------------------
$batchInfo = [];
$syllabusContent = '';
$syllabusFile    = '';

if ($id > 0 && $dbConn) {
    // A. Fetch Batch / Course Row
    try {
        $stmt = $dbConn->prepare("SELECT * FROM `batches` WHERE `id` = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $batchData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($batchData) {
            $batchInfo = $batchData;
        }
    } catch (PDOException $e) {
        error_log("Error fetching batch row: " . $e->getMessage());
    }

    // B. Check syllabus details & file from $batchInfo
    if (!empty($batchInfo)) {
        $syllabusContent = $batchInfo['syllabus_details'] ?? $batchInfo['syllabus'] ?? $batchInfo['syllabus_content'] ?? '';
        $syllabusFile    = $batchInfo['syllabus_file'] ?? '';
    }

    // C. Fallback: Check separate syllabus table if content is still empty
    if (empty($syllabusContent)) {
        try {
            $stmtSyl = $dbConn->prepare("SELECT * FROM `syllabus` WHERE `batch_id` = :id OR `id` = :id LIMIT 1");
            $stmtSyl->execute([':id' => $id]);
            $sylData = $stmtSyl->fetch(PDO::FETCH_ASSOC);

            if ($sylData) {
                $syllabusContent = $sylData['syllabus_details'] ?? $sylData['syllabus_content'] ?? $sylData['content'] ?? $sylData['description'] ?? '';
                if (empty($syllabusFile)) {
                    $syllabusFile = $sylData['syllabus_file'] ?? $sylData['file_attachment'] ?? '';
                }
            }
        } catch (PDOException $e) {
            // Table syllabus might not exist
        }
    }
}

// Extract batch variables with defaults
$batchName   = !empty($batchInfo['batch']) ? $batchInfo['batch'] : 'N/A';
$courseCode  = !empty($batchInfo['course_code']) ? $batchInfo['course_code'] : 'N/A';
$credit      = !empty($batchInfo['credit']) ? $batchInfo['credit'] : 'N/A';
$subjectName = !empty($batchInfo['subject']) ? $batchInfo['subject'] : 'N/A';
$examTitle   = !empty($batchInfo['exam_title']) ? $batchInfo['exam_title'] : 'N/A';
$teacherName = !empty($batchInfo['teacher_name']) ? $batchInfo['teacher_name'] : 'Instructor';
$teacherId   = !empty($batchInfo['teacher_id']) ? $batchInfo['teacher_id'] : 'N/A';

// Fetch Teacher Name and ID from `teachers` table if teacher_id is present
if (!empty($teacherId) && $teacherId !== 'N/A' && $dbConn) {
    try {
        $stmtT = $dbConn->prepare("
            SELECT `full_name`, `teacher_id` FROM `teachers` 
            WHERE `teacher_id` = :tid OR `id` = :tid 
            LIMIT 1
        ");
        $stmtT->execute([':tid' => $teacherId]);
        $teacherRow = $stmtT->fetch(PDO::FETCH_ASSOC);
        if ($teacherRow) {
            $teacherName = $teacherRow['full_name'] ?? $teacherName;
            if (!empty($teacherRow['teacher_id'])) {
                $teacherId = $teacherRow['teacher_id'];
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching teacher name: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Syllabus - <?php echo htmlspecialchars($subjectName); ?> - Virtual Exam System</title>
    
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="student-courseSyllabus-page.css">
    <link rel="stylesheet" href="../assets/css/student/student-header-sidebar-section.css">
    <link rel="stylesheet" href="../assets/css/student/student-courseSyllabus-page.css">

    <style>
        .syllabus-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 5px 18px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            margin-top: 15px;
        }

        .syllabus-card-header {
            background: linear-gradient(135deg, #0266cc 0%, #1e40af 100%);
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .syllabus-card-header h2 {
            color: #ffffff;
            font-size: 1.35rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-badge {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            backdrop-filter: blur(4px);
        }

        .syllabus-body {
            padding: 26px;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            background: #f8fafc;
            padding: 18px 20px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            margin-bottom: 24px;
        }

        .meta-pill {
            font-size: 0.95rem;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .meta-pill strong {
            color: #0f172a;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .meta-pill strong i {
            color: #0266cc;
        }

        .badge-teacher-id {
            background: #e0f2fe;
            color: #0369a1;
            padding: 3px 8px;
            border-radius: 5px;
            font-weight: 600;
            font-size: 0.85rem;
        }

        .badge-credit {
            background: #e0e7ff;
            color: #3730a3;
            padding: 3px 10px;
            border-radius: 5px;
            font-weight: 700;
            font-size: 0.88rem;
            border: 1px solid #c7d2fe;
        }

        .syllabus-content-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
            min-height: 250px;
            line-height: 1.75;
            color: #1e293b;
            font-size: 1.05rem;
            margin-bottom: 24px;
        }

        .file-attachment-card {
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.95rem;
            color: #1e293b;
            font-weight: 500;
        }

        .file-info i {
            font-size: 1.8rem;
            color: #ef4444;
        }

        .btn-download {
            background: #0266cc;
            color: #ffffff !important;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-download:hover {
            background: #024da0;
            transform: translateY(-1px);
        }

        .page-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.2s;
        }

        .btn-back {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .btn-back:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-print {
            background: #0284c7;
            color: #ffffff;
        }

        .btn-print:hover {
            background: #0369a1;
        }

        @media print {
            .page-actions, .sidebar, .header-container, header, nav {
                display: none !important;
            }
            .dashboard, .main-layout {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .syllabus-card {
                box-shadow: none !important;
                border: 1px solid #000 !important;
            }
            .syllabus-card-header {
                background: #f1f5f9 !important;
                color: #000 !important;
            }
            .syllabus-card-header h2 {
                color: #000 !important;
            }
        }
    </style>
</head>

<body>
    <div class="main-layout">

        <!-- STUDENT HEADER AND SIDEBAR -->
        <?php 
        if (file_exists(__DIR__ . '/student-header-sidebar-section.php')) {
            require_once __DIR__ . '/student-header-sidebar-section.php'; 
        }
        ?>

        <!-- MAIN CONTENT START -->
        <main class="dashboard">

            <div class="page-actions">
                <a href="student-courseSyllabus-page.php" class="btn-action btn-back">
                    <i class="fa-solid fa-arrow-left"></i> Back to Enrolled Courses
                </a>

                <div class="action-group-right">
                    <button type="button" class="btn-action btn-print" onclick="window.print()">
                        <i class="fa-solid fa-print"></i> Print / Save PDF
                    </button>
                </div>
            </div>

            <div class="syllabus-card">
                <div class="syllabus-card-header">
                    <h2><i class="fa-solid fa-book-open"></i> Course Syllabus</h2>
                    <span class="student-badge"><i class="fa-solid fa-graduation-cap"></i> Student View</span>
                </div>

                <div class="syllabus-body">
                    <!-- METADATA GRID -->
                    <div class="meta-grid">
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-users"></i> Batch:</strong> <?php echo htmlspecialchars($batchName); ?>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-barcode"></i> Course Code:</strong> <?php echo htmlspecialchars($courseCode); ?>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-award"></i> Credit:</strong> <span class="badge-credit"><?php echo htmlspecialchars($credit); ?></span>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-book"></i> Subject:</strong> <?php echo htmlspecialchars($subjectName); ?>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-heading"></i> Exam Title:</strong> <?php echo htmlspecialchars($examTitle); ?>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-user-tie"></i> Teacher Name:</strong> <?php echo htmlspecialchars($teacherName); ?>
                        </div>
                        <div class="meta-pill">
                            <strong><i class="fa-solid fa-id-badge"></i> Teacher ID:</strong> <span class="badge-teacher-id"><?php echo htmlspecialchars($teacherId); ?></span>
                        </div>
                    </div>

                    <h3 style="margin-bottom: 12px; color: #1d2b53;">Syllabus Outline & Topics</h3>
                    
                    <!-- TEXT CONTENT AREA -->
                    <div class="syllabus-content-box">
                        <?php if (!empty(trim($syllabusContent))): ?>
                            <?php 
                            // If contains HTML tags, output as sanitized HTML; otherwise nl2br
                            if (strip_tags($syllabusContent) !== $syllabusContent) {
                                echo $syllabusContent;
                            } else {
                                echo nl2br(htmlspecialchars($syllabusContent));
                            }
                            ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; color: #94a3b8;">
                                <i class="fa-solid fa-file-circle-question" style="font-size: 3rem; margin-bottom: 12px; display: block;"></i>
                                <p style="font-size: 1.1rem; margin: 0;">No written syllabus content has been posted for this course yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ATTACHED FILE DOWNLOAD AREA -->
                    <?php if (!empty($syllabusFile)): ?>
                        <h3 style="margin-bottom: 12px; color: #1d2b53;">Downloadable Course Material</h3>
                        <div class="file-attachment-card">
                            <div class="file-info">
                                <i class="fa-solid fa-file-pdf"></i>
                                <div>
                                    <div style="font-weight: 600; color: #0f172a;"><?php echo htmlspecialchars(basename($syllabusFile)); ?></div>
                                    <small style="color: #64748b;">Official course syllabus document</small>
                                </div>
                            </div>
                            <a href="<?php echo htmlspecialchars($syllabusFile); ?>" class="btn-download" download>
                                <i class="fa-solid fa-download"></i> Download Syllabus
                            </a>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </main>
        <!-- MAIN CONTENT END -->
    </div>
</body>
</html>
