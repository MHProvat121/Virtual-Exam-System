<?php
// 1. Initialize session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. RBAC Session Gatekeeper (Accessible by Teachers and Admins)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['teacher', 'admin', 'student'])) {
    header("Location: ../../src/auth/login-page.php");
    exit();
}

// 3. Database Connection
require_once __DIR__ . '/../../src/config/config.php';
$dbConn = $pdo ?? ($conn instanceof PDO ? $conn : null);

$batchId = (int)($_GET['id'] ?? 0);

// 4. Fetch Syllabus Batch Record Info
$stmtBatch = $dbConn->prepare("SELECT * FROM `batches` WHERE `id` = ? LIMIT 1");
$stmtBatch->execute([$batchId]);
$batchInfo = $stmtBatch->fetch(PDO::FETCH_ASSOC);

if (!$batchInfo) {
    echo "Syllabus record not found.";
    exit();
}

// 5. Fetch All Comments for this Syllabus Record
$stmtComments = $dbConn->prepare("
    SELECT 
        c.*, 
        s.full_name AS student_name, 
        s.student_id AS student_code, 
        s.profile_image 
    FROM `syllabus_comments` c 
    JOIN `students` s ON c.student_id = s.id 
    WHERE c.batch_id = ? 
    ORDER BY c.created_at DESC
");
$stmtComments->execute([$batchId]);
$commentsList = $stmtComments->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Syllabus Comments - Virtual Exam System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f1f5f9; color: #1e293b; padding: 30px; }
        .container { max-width: 850px; margin: 0 auto; }
        
        .header-card { background: #fff; padding: 22px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .header-card h2 { font-size: 20px; color: #0f172a; margin-bottom: 8px; }
        .meta-pills { display: flex; gap: 12px; flex-wrap: wrap; font-size: 13px; color: #64748b; }
        .pill { background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 6px; font-weight: 600; }
        
        .btn-back { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #64748b; color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600; margin-bottom: 20px; }
        .btn-back:hover { background: #475569; }

        .comments-list { display: flex; flex-direction: column; gap: 18px; }
        .comment-card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.03); }
        .comment-user { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
        .user-details { display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 1px solid #cbd5e1; }
        .user-name { font-weight: 700; font-size: 15px; color: #1e293b; }
        .user-id { font-size: 12px; color: #64748b; }
        .comment-date { font-size: 12px; color: #94a3b8; }

        .comment-body { font-size: 14.5px; line-height: 1.6; color: #334155; margin-bottom: 12px; }
        
        .screenshot-box { margin-top: 10px; background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; display: inline-block; }
        .screenshot-box img { max-width: 100%; max-height: 300px; border-radius: 6px; border: 1px solid #cbd5e1; display: block; }
        .screenshot-box a { display: inline-block; margin-top: 6px; font-size: 12px; color: #0266cc; text-decoration: none; font-weight: 600; }
        .screenshot-box a:hover { text-decoration: underline; }

        .empty-comments { text-align: center; padding: 50px; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; color: #64748b; }
    </style>
</head>
<body>
    <div class="container">
        <a href="javascript:history.back()" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Syllabus</a>

        <!-- Syllabus Record Meta Header -->
        <div class="header-card">
            <h2><i class="fa-solid fa-comments" style="color:#0266cc;"></i> Student Comments & Feedback</h2>
            <div class="meta-pills">
                <span><strong>Batch:</strong> <?php echo htmlspecialchars($batchInfo['batch']); ?></span>
                <span class="pill">Subject: <?php echo htmlspecialchars($batchInfo['subject']); ?></span>
                <span><strong>Exam Title:</strong> <?php echo htmlspecialchars($batchInfo['exam_title']); ?></span>
            </div>
        </div>

        <!-- Comments List -->
        <div class="comments-list">
            <?php if (!empty($commentsList)): foreach ($commentsList as $c): ?>
                <div class="comment-card">
                    <div class="comment-user">
                        <div class="user-details">
                            <img src="../../<?php echo !empty($c['profile_image']) ? htmlspecialchars($c['profile_image']) : 'assets/images/user-avatar.jpg'; ?>" class="user-avatar" alt="Student">
                            <div>
                                <div class="user-name"><?php echo htmlspecialchars($c['student_name']); ?></div>
                                <div class="user-id">Student ID: <?php echo htmlspecialchars($c['student_code']); ?></div>
                            </div>
                        </div>
                        <div class="comment-date">
                            <i class="fa-regular fa-clock"></i> <?php echo date('M d, Y - h:i A', strtotime($c['created_at'])); ?>
                        </div>
                    </div>

                    <div class="comment-body">
                        <?php echo nl2br(htmlspecialchars($c['comment_text'])); ?>
                    </div>

                    <?php if (!empty($c['screenshot_image'])): ?>
                        <div class="screenshot-box">
                            <img src="../../<?php echo htmlspecialchars($c['screenshot_image']); ?>" alt="Student Screenshot">
                            <a href="../../<?php echo htmlspecialchars($c['screenshot_image']); ?>" target="_blank">
                                <i class="fa-solid fa-up-right-from-square"></i> Open Full Image
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; else: ?>
                <div class="empty-comments">
                    <i class="fa-solid fa-comment-slash" style="font-size:32px; color:#cbd5e1; margin-bottom:10px;"></i>
                    <h3>No comments submitted yet.</h3>
                    <p>When students submit comments or queries regarding this syllabus topic, they will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>