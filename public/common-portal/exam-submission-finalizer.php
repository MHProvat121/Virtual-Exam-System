<?php
/**
 * Marks active exam attempts as submitted when the authoritative exam timer
 * has expired. This also covers students who leave the exam page before the
 * browser can send its automatic submit request.
 */
if (!function_exists('finalizeExpiredExamSubmissions')) {
    function finalizeExpiredExamSubmissions($db, $examId = null) {
        if (!$db) return;

        $examId = ($examId !== null && intval($examId) > 0) ? intval($examId) : null;
        $examScope = $examId !== null ? ' AND e.id = ?' : '';
        $sql = "
            UPDATE `exam_submissions` s
            INNER JOIN `exam_header` e ON e.id = s.exam_id
            SET s.status = 'Submitted',
                s.submitted_at = COALESCE(s.submitted_at, UTC_TIMESTAMP()),
                s.updated_at = UTC_TIMESTAMP()
            WHERE s.status = 'In Progress'
              AND (
                    e.status = 'Completed'
                    OR (
                        e.status = 'Running'
                        AND GREATEST(
                            0,
                            COALESCE(e.remaining_seconds, e.duration * 60)
                            - GREATEST(
                                0,
                                UNIX_TIMESTAMP() - COALESCE(UNIX_TIMESTAMP(e.timer_updated_at), UNIX_TIMESTAMP())
                            )
                        ) = 0
                    )
                    OR (
                        e.status = 'Cancelled/On Hold'
                        AND COALESCE(e.remaining_seconds, e.duration * 60) = 0
                    )
              )
              {$examScope}
        ";

        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                $stmt->execute($examId !== null ? [$examId] : []);
            } elseif ($db instanceof mysqli) {
                $stmt = $db->prepare($sql);
                if ($examId !== null) {
                    $stmt->bind_param('i', $examId);
                }
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('Expired exam attempt finalization failed: ' . $e->getMessage());
        }
    }
}
