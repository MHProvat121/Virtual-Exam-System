-- ==============================================================================
-- DATABASE SCHEMA DEFINITION
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `admins` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `admin_id` varchar(50) NOT NULL,
 `full_name` varchar(100) NOT NULL,
 `email` varchar(100) NOT NULL,
 `designation` varchar(100) DEFAULT 'System Administrator',
 `department` varchar(100) DEFAULT 'IT Department',
 `contact_number` varchar(30) DEFAULT NULL,
 `profile_image` varchar(255) DEFAULT 'assets/images/user-avatar.jpg',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `admin_id` (`admin_id`),
 UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `batches` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `batch` varchar(255) NOT NULL COMMENT 'The name/year of the batch',
 `course_code` varchar(100) DEFAULT NULL COMMENT 'Course / Subject code (e.g. CSE-101)',
 `credit` varchar(50) DEFAULT NULL COMMENT 'Course Credit (e.g. 3.0, 1.5, 4.0)',
 `subject` varchar(255) DEFAULT NULL COMMENT 'The name of the subject',
 `exam_title` varchar(255) DEFAULT NULL COMMENT 'Title of the examination',
 `syllabus_details` text DEFAULT NULL COMMENT 'Text/HTML syllabus content or description',
 `syllabus_file` varchar(255) DEFAULT NULL COMMENT 'File path if teacher uploads a PDF/DOC',
 `teacher_id` varchar(50) NOT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `teacher_id` (`teacher_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `batch_deletion_logs` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `batch_name` varchar(255) NOT NULL,
 `deleted_by` varchar(50) NOT NULL COMMENT 'Teacher ID or Admin ID',
 `deleted_at` timestamp NOT NULL DEFAULT current_timestamp(),
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_details` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `teacher_id` varchar(50) NOT NULL,
 `teacher_name` varchar(100) DEFAULT NULL,
 `batch` varchar(255) NOT NULL,
 `subject` varchar(255) NOT NULL,
 `exam_title` varchar(255) NOT NULL,
 `exam_type` varchar(50) NOT NULL,
 `exam_date` date NOT NULL,
 `exam_time` time NOT NULL,
 `duration` int(11) NOT NULL,
 `total_marks` int(11) NOT NULL DEFAULT 0,
 `status` enum('Published','Running','Completed','Cancelled/On Hold','Edited/Rescheduled') NOT NULL DEFAULT 'Published',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `teacher_id` (`teacher_id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_header` (
 `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Self-generated unique exam identifier',
 `teacher_id` varchar(50) NOT NULL COMMENT 'Identifier of the instructor from teachers table',
 `teacher_name` varchar(100) NOT NULL COMMENT 'Full name of the instructor',
 `batch` varchar(255) NOT NULL COMMENT 'Target batch name/year from batches table',
 `subject` varchar(255) NOT NULL COMMENT 'Subject name from batches table',
 `exam_title` varchar(255) NOT NULL COMMENT 'Course / Exam Title from batches table',
 `exam_type` enum('MCQ','Theory','Hybrid (MCQ+Theory)') NOT NULL DEFAULT 'MCQ' COMMENT 'Format of the examination',
 `exam_date` date NOT NULL COMMENT 'Scheduled examination date',
 `exam_time` time NOT NULL COMMENT 'Scheduled examination start time',
 `duration` int(11) NOT NULL COMMENT 'Examination duration in minutes',
 `marks` decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT 'Cumulative total marks calculated from added questions',
 `status` enum('Published','Running','Completed','Cancelled/On Hold','Edited/Rescheduled') NOT NULL DEFAULT 'Published',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `idx_teacher_id` (`teacher_id`),
 KEY `idx_batch` (`batch`),
 KEY `idx_subject` (`subject`),
 KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_submissions` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `exam_id` int(11) NOT NULL COMMENT 'Foreign key to exam_details(id)',
 `student_id` varchar(50) NOT NULL COMMENT 'Student identifier/roll',
 `student_name` varchar(150) DEFAULT NULL COMMENT 'Full student name',
 `submitted_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Timestamp of exam submission',
 `status` enum('Submitted','Under Review','Graded') NOT NULL DEFAULT 'Submitted' COMMENT 'Evaluation status',
 `total_marks_obtained` decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT 'Cumulative marks awarded',
 `teacher_feedback` text DEFAULT NULL COMMENT 'Overall qualitative feedback from evaluator',
 `evaluated_by` varchar(50) DEFAULT NULL COMMENT 'Teacher ID who evaluated the paper',
 `evaluated_at` datetime DEFAULT NULL COMMENT 'Timestamp when grading was finalized',
 `created_at` datetime NOT NULL DEFAULT current_timestamp(),
 `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `unique_exam_student` (`exam_id`,`student_id`),
 KEY `idx_exam_id` (`exam_id`),
 KEY `idx_student_id` (`student_id`),
 KEY `idx_status` (`status`),
 CONSTRAINT `fk_submissions_exam_header` FOREIGN KEY (`exam_id`) REFERENCES `exam_header` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `questions` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `exam_id` int(11) NOT NULL,
 `question_number` int(11) NOT NULL,
 `question_type` enum('MCQ','Theory') NOT NULL DEFAULT 'MCQ',
 `question_text` text NOT NULL,
 `marks` decimal(5,2) NOT NULL DEFAULT 1.00,
 `image_url` varchar(255) DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `fk_questions_exam` (`exam_id`),
 CONSTRAINT `fk_questions_exam_header` FOREIGN KEY (`exam_id`) REFERENCES `exam_header` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `question_options` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `question_id` int(11) NOT NULL,
 `option_text` text NOT NULL,
 `option_order` int(11) NOT NULL DEFAULT 0,
 `is_correct` tinyint(1) NOT NULL DEFAULT 0,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `fk_options_question` (`question_id`),
 CONSTRAINT `fk_options_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `students` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `student_id` varchar(50) NOT NULL,
 `full_name` varchar(100) NOT NULL,
 `email` varchar(100) NOT NULL,
 `program` varchar(150) DEFAULT NULL,
 `batch` varchar(50) DEFAULT NULL,
 `contact_number` varchar(30) DEFAULT NULL,
 `guardian_contact` varchar(30) DEFAULT NULL,
 `present_address` text DEFAULT NULL,
 `permanent_address` text DEFAULT NULL,
 `profile_image` varchar(255) DEFAULT 'assets/images/user-avatar.jpg',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 `registration_date` date DEFAULT curdate(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `student_id` (`student_id`),
 UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_answers` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `submission_id` int(11) NOT NULL COMMENT 'Foreign key to exam_submissions(id)',
 `exam_id` int(11) NOT NULL COMMENT 'Foreign key to exam_details(id)',
 `question_id` int(11) NOT NULL COMMENT 'Foreign key to questions(id)',
 `student_id` varchar(50) NOT NULL COMMENT 'Student identifier/roll',
 `selected_option_id` int(11) DEFAULT NULL COMMENT 'Option ID selected if MCQ question',
 `answer_text` text DEFAULT NULL COMMENT 'Written response if Theory question',
 `answer_image` text DEFAULT NULL COMMENT 'Path or JSON array of uploaded answer image(s)',
 `is_correct` tinyint(1) DEFAULT NULL COMMENT '1=Correct, 0=Incorrect, NULL=Pending manual evaluation',
 `marks_obtained` decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT 'Marks awarded for this specific question',
 `teacher_remarks` varchar(255) DEFAULT NULL COMMENT 'Optional inline remarks/critique from teacher',
 `created_at` datetime NOT NULL DEFAULT current_timestamp(),
 `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `unique_submission_question` (`submission_id`,`question_id`),
 KEY `idx_submission_id` (`submission_id`),
 KEY `idx_exam_question` (`exam_id`,`question_id`),
 KEY `idx_student_id` (`student_id`),
 CONSTRAINT `fk_answers_exam_header` FOREIGN KEY (`exam_id`) REFERENCES `exam_header` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=554 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_education` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `student_id` int(11) NOT NULL,
 `institution` varchar(150) NOT NULL,
 `program` varchar(150) NOT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `fk_education_student` (`student_id`),
 CONSTRAINT `fk_education_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_enrollments` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `student_id` int(11) NOT NULL COMMENT 'FK to students.id',
 `batch` varchar(255) NOT NULL,
 `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
 `payment_method` varchar(50) NOT NULL COMMENT 'Bkash, Nagad, Rocket, Bank Transfer, Hand Cash',
 `transaction_id` varchar(100) NOT NULL,
 `payment_slip` varchar(255) DEFAULT NULL COMMENT 'Path to uploaded slip image/pdf',
 `enroll_requested_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'Timestamp when student submitted enrollment request',
 `status` enum('Unenrolled','Pending','Enrolled','Cancelled') NOT NULL DEFAULT 'Pending',
 `reviewed_by_id` varchar(50) DEFAULT NULL,
 `reviewed_by_name` varchar(100) DEFAULT NULL,
 `action_at` timestamp NULL DEFAULT NULL,
 `cancellation_reason` text DEFAULT NULL,
 `cancelled_by_id` varchar(50) DEFAULT NULL COMMENT 'Admin ID who cancelled enrollment request',
 `cancelled_by_name` varchar(100) DEFAULT NULL COMMENT 'Admin Full Name who cancelled enrollment request',
 `cancelled_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when Admin cancelled enrollment request',
 `approved_by_id` varchar(50) DEFAULT NULL COMMENT 'Admin ID who reviewed request',
 `approved_by_name` varchar(100) DEFAULT NULL COMMENT 'Admin Full Name',
 `enrolled_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when Admin marked as Enrolled',
 `withdraw_status` enum('None','Pending','Withdrawn','Rejected') NOT NULL DEFAULT 'None' COMMENT 'Withdrawal Status',
 `withdraw_reason` text DEFAULT NULL COMMENT 'Reason for course/batch withdrawal',
 `withdraw_requested_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when student requested withdrawal',
 `withdraw_action_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when admin processed withdrawal',
 `withdraw_reviewed_by_id` varchar(50) DEFAULT NULL COMMENT 'Admin ID who reviewed withdrawal',
 `withdraw_reviewed_by_name` varchar(100) DEFAULT NULL COMMENT 'Admin Full Name who reviewed withdrawal',
 `withdraw_cancelled_by_id` varchar(50) DEFAULT NULL COMMENT 'Admin ID who cancelled/rejected withdrawal',
 `withdraw_cancelled_by_name` varchar(100) DEFAULT NULL COMMENT 'Admin Full Name who cancelled/rejected withdrawal',
 `withdraw_cancelled_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when admin cancelled/rejected withdrawal',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `unique_student_batch` (`student_id`,`batch`),
 KEY `fk_enrollment_student` (`student_id`),
 CONSTRAINT `fk_enrollment_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_enrollment_courses` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `enrollment_id` int(11) NOT NULL COMMENT 'FK to student_enrollments.id',
 `course_code` varchar(100) NOT NULL COMMENT 'Checked course code for enrollment',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `unique_enrollment_course` (`enrollment_id`, `course_code`),
 CONSTRAINT `fk_courses_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `student_enrollments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_withdrawal_courses` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `enrollment_id` int(11) NOT NULL COMMENT 'FK to student_enrollments.id',
 `course_code` varchar(100) NOT NULL COMMENT 'Checked course code for withdrawal',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `unique_withdrawal_course` (`enrollment_id`, `course_code`),
 CONSTRAINT `fk_withdrawal_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `student_enrollments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_payments` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `student_id` int(11) NOT NULL,
 `bank_name` varchar(100) DEFAULT NULL,
 `account_name` varchar(100) DEFAULT NULL,
 `account_number` varchar(50) DEFAULT NULL,
 `mobile_banking_number` varchar(30) DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `fk_student_pay` (`student_id`),
 CONSTRAINT `fk_payments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `syllabus_comments` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `batch_id` int(11) NOT NULL COMMENT 'FK to batches.id',
 `student_id` int(11) NOT NULL COMMENT 'FK to students.id',
 `comment_text` text NOT NULL,
 `screenshot_image` varchar(255) DEFAULT NULL COMMENT 'Path to uploaded screenshot image',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `fk_comment_batch` (`batch_id`),
 KEY `fk_comment_student` (`student_id`),
 CONSTRAINT `fk_comment_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT `fk_comment_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teachers` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `teacher_id` varchar(50) NOT NULL,
 `full_name` varchar(100) NOT NULL,
 `email` varchar(100) NOT NULL,
 `degree` varchar(150) DEFAULT NULL,
 `designation` varchar(100) DEFAULT 'Senior Lecturer',
 `contact_number` varchar(30) DEFAULT NULL,
 `present_address` text DEFAULT NULL,
 `permanent_address` text DEFAULT NULL,
 `profile_image` varchar(255) DEFAULT 'assets/images/user-avatar.jpg',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 `registration_date` date DEFAULT curdate(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `teacher_id` (`teacher_id`),
 UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teacher_education` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `teacher_id` int(11) NOT NULL,
 `institution` varchar(150) NOT NULL,
 `program` varchar(150) NOT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `fk_education_teacher` (`teacher_id`),
 CONSTRAINT `fk_education_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teacher_payments` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `teacher_id` int(11) NOT NULL,
 `bank_name` varchar(100) DEFAULT NULL,
 `account_name` varchar(100) DEFAULT NULL,
 `account_number` varchar(50) DEFAULT NULL,
 `mobile_banking_number` varchar(30) DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `fk_teacher_pay` (`teacher_id`),
 CONSTRAINT `fk_payments_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teacher_salaries` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `teacher_id` varchar(50) NOT NULL COMMENT 'Links to teachers.teacher_id or teachers.id',
 `registered_courses` varchar(255) DEFAULT 'N/A' COMMENT 'List of subjects/courses taught',
 `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
 `payment_date` date NOT NULL,
 `payment_method` varchar(50) NOT NULL COMMENT 'Bkash, Nagad, Bank Transfer, Hand Cash',
 `payor_id` varchar(100) NOT NULL COMMENT 'Admin ID, Transaction ID, or Bank Ref',
 `status` enum('Paid','Pending','Processing') NOT NULL DEFAULT 'Paid',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `idx_teacher_id` (`teacher_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users-table` (
 `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
 `name` varchar(255) NOT NULL,
 `email` varchar(255) NOT NULL,
 `username` varchar(30) NOT NULL,
 `role` enum('admin','student','teacher') NOT NULL,
 `password` varchar(255) NOT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
 PRIMARY KEY (`id`),
 UNIQUE KEY `email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- OPTIONAL MIGRATION / UPGRADE STATEMENTS FOR EXISTING LIVE DATABASES
-- (100% Pure SQL: Zero Procedures, Zero Delimiters, Zero Triggers)
-- Safe to run in phpMyAdmin on shared hosts like InfinityFree without Error #1370
-- ==============================================================================

-- 1. Safely add missing columns to existing batches table
ALTER TABLE `batches`
  ADD COLUMN IF NOT EXISTS `course_code` varchar(100) DEFAULT NULL COMMENT 'Course / Subject code (e.g. CSE-101)' AFTER `batch`,
  ADD COLUMN IF NOT EXISTS `credit` varchar(50) DEFAULT NULL COMMENT 'Course Credit (e.g. 3.0, 1.5, 4.0)' AFTER `course_code`;

-- 2. Safely add missing answer_image column to existing student_answers table
ALTER TABLE `student_answers`
  ADD COLUMN IF NOT EXISTS `answer_image` text DEFAULT NULL COMMENT 'Path or JSON array of uploaded answer image(s)' AFTER `answer_text`;

-- 3. Safely add all missing tracking columns to existing student_enrollments table
ALTER TABLE `student_enrollments`
  ADD COLUMN IF NOT EXISTS `enroll_requested_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when student submitted enrollment request' AFTER `payment_slip`,
  ADD COLUMN IF NOT EXISTS `cancelled_by_id` varchar(50) DEFAULT NULL COMMENT 'Admin ID who cancelled enrollment request' AFTER `cancellation_reason`,
  ADD COLUMN IF NOT EXISTS `cancelled_by_name` varchar(100) DEFAULT NULL COMMENT 'Admin Full Name who cancelled enrollment request' AFTER `cancelled_by_id`,
  ADD COLUMN IF NOT EXISTS `cancelled_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when Admin cancelled enrollment request' AFTER `cancelled_by_name`,
  ADD COLUMN IF NOT EXISTS `withdraw_status` enum('None','Pending','Withdrawn','Rejected') NOT NULL DEFAULT 'None' COMMENT 'Withdrawal Status' AFTER `enrolled_at`,
  ADD COLUMN IF NOT EXISTS `withdraw_reason` text DEFAULT NULL COMMENT 'Reason for course/batch withdrawal' AFTER `withdraw_status`,
  ADD COLUMN IF NOT EXISTS `withdraw_requested_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when student requested withdrawal' AFTER `withdraw_reason`,
  ADD COLUMN IF NOT EXISTS `withdraw_action_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when admin processed withdrawal' AFTER `withdraw_requested_at`,
  ADD COLUMN IF NOT EXISTS `withdraw_reviewed_by_id` varchar(50) DEFAULT NULL COMMENT 'Admin ID who reviewed withdrawal' AFTER `withdraw_action_at`,
  ADD COLUMN IF NOT EXISTS `withdraw_reviewed_by_name` varchar(100) DEFAULT NULL COMMENT 'Admin Full Name who reviewed withdrawal' AFTER `withdraw_reviewed_by_id`,
  ADD COLUMN IF NOT EXISTS `withdraw_cancelled_by_id` varchar(50) DEFAULT NULL COMMENT 'Admin ID who cancelled/rejected withdrawal' AFTER `withdraw_reviewed_by_name`,
  ADD COLUMN IF NOT EXISTS `withdraw_cancelled_by_name` varchar(100) DEFAULT NULL COMMENT 'Admin Full Name who cancelled/rejected withdrawal' AFTER `withdraw_cancelled_by_id`,
  ADD COLUMN IF NOT EXISTS `withdraw_cancelled_at` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when admin cancelled/rejected withdrawal' AFTER `withdraw_cancelled_by_name`;

-- 4. Backfill historical records for consistency
UPDATE `student_enrollments`
SET `cancelled_by_id` = `reviewed_by_id`,
    `cancelled_by_name` = `reviewed_by_name`,
    `cancelled_at` = `action_at`
WHERE `status` = 'Cancelled'
  AND `cancelled_by_name` IS NULL
  AND `reviewed_by_name` IS NOT NULL;

UPDATE `student_enrollments`
SET `withdraw_cancelled_by_id` = `withdraw_reviewed_by_id`,
    `withdraw_cancelled_by_name` = `withdraw_reviewed_by_name`,
    `withdraw_cancelled_at` = `withdraw_action_at`
WHERE `withdraw_status` = 'Rejected'
  AND `withdraw_cancelled_by_name` IS NULL
  AND `withdraw_reviewed_by_name` IS NOT NULL;

UPDATE `student_enrollments`
SET `enroll_requested_at` = COALESCE(`created_at`, `updated_at`, NOW())
WHERE `enroll_requested_at` IS NULL 
  AND (`amount` > 0.00 OR `payment_method` NOT IN ('None', '') OR `transaction_id` != '' OR `status` = 'Pending');

UPDATE `student_enrollments`
SET `withdraw_requested_at` = COALESCE(`withdraw_action_at`, `updated_at`, `created_at`, NOW())
WHERE `withdraw_requested_at` IS NULL 
  AND `withdraw_status` IN ('Pending', 'Withdrawn', 'Rejected');

-- 5. Clean up orphaned enrollments
DELETE e FROM `student_enrollments` e
LEFT JOIN `batches` b ON e.batch COLLATE utf8mb4_unicode_ci = b.batch COLLATE utf8mb4_unicode_ci
WHERE b.id IS NULL;
