/**
 * student-examTable-page.js
 * Client-side script for student-examTable-page.php:
 * 1. Controls-card status tabs filtering (All, Running, Published, Completed, On Hold, Rescheduled)
 * 2. Multi-keyword search across batch names, course codes, subjects, titles, and instructors
 * 3. Live countdown timers, duration timers, and real-time state transitions
 * 4. Pin & Archive batch controls
 */

// -------------------------------------------------------------
// GLOBAL FILTER STATE & FUNCTIONS (Defined immediately for UI onclick)
// -------------------------------------------------------------
var currentExamStatusFilter = 'all';

function filterExamStatus(status, tabElement) {
    currentExamStatusFilter = (status || 'all').toLowerCase().trim();

    // Update active class on tab buttons
    var container = document.getElementById('examStatusTabs');
    if (container) {
        var tabs = container.querySelectorAll('.tab-link');
        tabs.forEach(function(t) {
            t.classList.remove('active');
        });
    }

    if (tabElement) {
        tabElement.classList.add('active');
    } else if (container) {
        // Fallback: match by data-status
        var matchingTab = container.querySelector('.tab-link[data-status="' + currentExamStatusFilter + '"]');
        if (matchingTab) {
            matchingTab.classList.add('active');
        }
    }

    applyExamFilters();
}

function filterExamBatches() {
    applyExamFilters();
}

function applyExamFilters() {
    var searchInput = document.getElementById('examBatchSearch') || document.getElementById('courseSearch');
    var term = searchInput ? searchInput.value.toLowerCase().trim() : '';
    var cards = document.querySelectorAll('.batch-card');

    var visibleCards = 0;

    cards.forEach(function(card) {
        var cardBatchName = (card.getAttribute('data-batch') || '').toLowerCase();
        var cardText = card.innerText.toLowerCase();
        var rows = card.querySelectorAll('tbody tr.student-exam-row, tbody tr.exam-row');

        // Check if the card itself or any of its text matches search
        var cardMatchesSearch = !term || cardText.indexOf(term) > -1 || cardBatchName.indexOf(term) > -1;

        if (!cardMatchesSearch) {
            card.style.display = 'none';
            return;
        }

        var visibleRowsCount = 0;

        if (rows.length > 0) {
            rows.forEach(function(r) {
                var rowStatusAttr = (r.getAttribute('data-status') || '').toLowerCase().trim();
                var statusCell = r.querySelector('.status-cell');
                var badgeText = statusCell ? statusCell.textContent.toLowerCase().trim() : '';
                var isCompletedAttr = r.getAttribute('data-is-completed') === '1';
                var isRescheduledAttr = r.getAttribute('data-is-rescheduled') === '1';

                // Determine dynamic status
                var isCompleted = isCompletedAttr || rowStatusAttr === 'completed' || rowStatusAttr === 'finished' || badgeText.indexOf('completed') > -1 || badgeText.indexOf('complete') > -1;
                var isOnHold = !isCompleted && (rowStatusAttr.indexOf('hold') > -1 || rowStatusAttr.indexOf('cancelled') > -1 || badgeText.indexOf('hold') > -1);
                var isRescheduled = !isCompleted && !isOnHold && (isRescheduledAttr || rowStatusAttr.indexOf('rescheduled') > -1 || rowStatusAttr.indexOf('edited') > -1 || badgeText.indexOf('rescheduled') > -1);
                var isRunning = !isCompleted && !isOnHold && (rowStatusAttr === 'running' || badgeText.indexOf('running') > -1);
                var isPublished = !isCompleted && !isOnHold && !isRunning && !isRescheduled && (rowStatusAttr === 'published' || badgeText.indexOf('published') > -1);

                // Status match evaluation
                var matchesStatus = false;
                if (currentExamStatusFilter === 'all') {
                    matchesStatus = true;
                } else if (currentExamStatusFilter === 'running') {
                    matchesStatus = isRunning;
                } else if (currentExamStatusFilter === 'published') {
                    matchesStatus = isPublished;
                } else if (currentExamStatusFilter === 'completed') {
                    matchesStatus = isCompleted;
                } else if (currentExamStatusFilter.indexOf('hold') > -1 || currentExamStatusFilter.indexOf('cancelled') > -1) {
                    matchesStatus = isOnHold;
                } else if (currentExamStatusFilter.indexOf('rescheduled') > -1 || currentExamStatusFilter.indexOf('edited') > -1) {
                    matchesStatus = isRescheduled;
                } else {
                    matchesStatus = (rowStatusAttr.indexOf(currentExamStatusFilter) > -1 || badgeText.indexOf(currentExamStatusFilter) > -1);
                }

                // Row search match evaluation
                var rowText = r.innerText.toLowerCase();
                var matchesSearch = !term || rowText.indexOf(term) > -1 || cardBatchName.indexOf(term) > -1;

                if (matchesStatus && matchesSearch) {
                    r.style.display = '';
                    visibleRowsCount++;
                } else {
                    r.style.display = 'none';
                }
            });

            if (visibleRowsCount > 0) {
                card.style.display = '';
                visibleCards++;
            } else {
                card.style.display = 'none';
            }
        } else {
            // Card has no exams (empty batch)
            if (currentExamStatusFilter === 'all' && cardMatchesSearch) {
                card.style.display = '';
                visibleCards++;
            } else {
                card.style.display = 'none';
            }
        }
    });

    var emptyContainer = document.getElementById('searchNoResultsMessage');
    if (emptyContainer) {
        emptyContainer.style.display = (visibleCards === 0 && cards.length > 0) ? 'block' : 'none';
    }
}

// Attach to window immediately so inline attributes can invoke them anywhere
window.filterExamStatus = filterExamStatus;
window.filterExamBatches = filterExamBatches;
window.applyExamFilters = applyExamFilters;

// -------------------------------------------------------------
// TIMER SYNCHRONIZATION & DOM INITIALIZATION
// -------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function() {
    // Search input listeners for instant real-time filtering
    var searchInput = document.getElementById('examBatchSearch') || document.getElementById('courseSearch');
    if (searchInput) {
        searchInput.addEventListener('input', filterExamBatches);
        searchInput.addEventListener('keyup', filterExamBatches);
    }

    var initialRow = document.querySelector('tr.student-exam-row');
    var serverOffsetMs = 0;
    if (initialRow) {
        var rawServerMs = Number(initialRow.getAttribute('data-server-time-ms'));
        if (Number.isFinite(rawServerMs) && rawServerMs > 0) {
            serverOffsetMs = rawServerMs - Date.now();
        }
    }
    if (!Number.isFinite(serverOffsetMs)) serverOffsetMs = 0;
    var serverNowMs = function() { return Date.now() + serverOffsetMs; };

    // Helper: format milliseconds into HH:MM:SS
    function formatTime(ms) {
        if (isNaN(ms) || ms <= 0) return '00:00:00';
        var totalSec = Math.floor(ms / 1000);
        var hours = Math.floor(totalSec / 3600);
        var mins = Math.floor((totalSec % 3600) / 60);
        var secs = totalSec % 60;
        return [
            String(hours).padStart(2, '0'),
            String(mins).padStart(2, '0'),
            String(secs).padStart(2, '0')
        ].join(':');
    }

    // Update each exam row's countdown badges, status badges, and action buttons
    function updateRowTimers() {
        var rows = document.querySelectorAll('tr.student-exam-row');
        var now = serverNowMs();
        var statusChanged = false;

        rows.forEach(function(row) {
            var startTimerBadge = row.querySelector('.start-timer');
            var durationTimerBadge = row.querySelector('.duration-timer');
            var statusCell = row.querySelector('.status-cell');
            var actionGroup = row.querySelector('.action-btn-group');

            var examId = row.getAttribute('data-exam-id');
            var status = (row.getAttribute('data-status') || 'Published').trim();
            var statusLower = status.toLowerCase();
            var isRescheduled = row.getAttribute('data-is-rescheduled') === '1' || statusLower === 'edited/rescheduled';
            var isSubmitted = row.getAttribute('data-is-submitted') === '1';
            var durationMins = parseInt(row.getAttribute('data-duration') || '0', 10);
            var startTimestamp = Number(row.getAttribute('data-start-timestamp'));
            var startTimeMs = Number.isFinite(startTimestamp) && startTimestamp > 0 ? startTimestamp * 1000 : NaN;
            var remainingBaseSeconds = Math.max(0, parseInt(row.getAttribute('data-remaining-seconds') || String(durationMins * 60), 10));
            var timerUpdatedAtEpoch = Number(row.getAttribute('data-timer-updated-at-epoch')) || 0;
            var timeToStart = startTimeMs - now;

            var isOnHold = (statusLower === 'cancelled/on hold');
            var isFinished = (statusLower === 'completed');

            // -------------------------------------------------------------
            // STATE 1: FINISHED / COMPLETED
            // -------------------------------------------------------------
            if (isFinished) {
                if (startTimerBadge) {
                    startTimerBadge.textContent = 'Finished';
                    startTimerBadge.className = 'timer-badge start-timer finished';
                }
                if (durationTimerBadge) {
                    durationTimerBadge.textContent = '0';
                    durationTimerBadge.className = 'timer-badge duration-timer finished';
                }
                if (statusCell) {
                    statusCell.innerHTML = '<span class="badge badge-completed"><i class="fa-solid fa-check"></i> Completed</span>';
                }
                if (actionGroup) {
                    var btn = actionGroup.querySelector('.btn-finished');
                    if (!btn || !btn.textContent.includes('Complete')) {
                        actionGroup.innerHTML = '<button type="button" class="btn btn-finished is-completed" disabled title="Exam duration has ended"><i class="fa-solid fa-check-double"></i> Complete</button>';
                    }
                }
                row.setAttribute('data-is-completed', '1');
                return;
            }

            // -------------------------------------------------------------
            // STATE 2: ON HOLD
            // -------------------------------------------------------------
            if (isOnHold) {
                if (startTimerBadge) {
                    startTimerBadge.textContent = 'On Hold';
                    startTimerBadge.className = 'timer-badge start-timer on-hold';
                }
                if (durationTimerBadge) {
                    durationTimerBadge.textContent = formatTime(remainingBaseSeconds * 1000) + ' (On Hold)';
                    durationTimerBadge.className = 'timer-badge duration-timer on-hold';
                }
                if (statusCell) {
                    statusCell.innerHTML = '<span class="badge badge-hold"><i class="fa-solid fa-pause"></i> On Hold</span>';
                }
                if (actionGroup) {
                    var onHoldBtn = actionGroup.querySelector('.btn-on-hold');
                    if (!onHoldBtn) {
                        actionGroup.innerHTML = '<button type="button" class="btn btn-on-hold" disabled title="Exam is temporarily on hold"><i class="fa-solid fa-pause"></i> On Hold</button>';
                    }
                }
                return;
            }

            // -------------------------------------------------------------
            // STATE 3: RUNNING (Exam is Active)
            // -------------------------------------------------------------
            if (statusLower === 'running') {
                var updatedAtMs = timerUpdatedAtEpoch * 1000;
                var durationRemaining = (remainingBaseSeconds * 1000) - Math.max(0, now - updatedAtMs);

                if (durationRemaining <= 0) {
                    // Time elapsed -> Complete
                    if (startTimerBadge) {
                        startTimerBadge.textContent = 'Finished';
                        startTimerBadge.className = 'timer-badge start-timer finished';
                    }
                    if (durationTimerBadge) {
                        durationTimerBadge.textContent = '0';
                        durationTimerBadge.className = 'timer-badge duration-timer finished';
                    }
                    if (statusCell) {
                        statusCell.innerHTML = '<span class="badge badge-completed"><i class="fa-solid fa-check"></i> Completed</span>';
                    }
                    if (actionGroup) {
                        actionGroup.innerHTML = '<button type="button" class="btn btn-finished is-completed" disabled title="Exam duration has ended"><i class="fa-solid fa-check-double"></i> Complete</button>';
                    }
                    row.setAttribute('data-status', 'Completed');
                    row.setAttribute('data-is-completed', '1');
                    statusChanged = true;
                    return;
                }

                // Starts in column -> "In Progress"
                if (startTimerBadge) {
                    startTimerBadge.textContent = 'In Progress';
                    startTimerBadge.className = 'timer-badge start-timer running';
                }

                // Duration Left column -> real-time synchronized countdown
                if (durationTimerBadge) {
                    durationTimerBadge.textContent = formatTime(durationRemaining);
                    durationTimerBadge.className = 'timer-badge duration-timer active';
                }

                // Status column -> "Running"
                if (statusCell) {
                    statusCell.innerHTML = '<span class="badge badge-running"><span class="pulse-dot"></span> Running</span>';
                }

                // Action button: Revise or Give Exam
                if (actionGroup) {
                    var hasEnteredOrSubmitted = isSubmitted 
                        || row.getAttribute('data-is-submitted') === '1'
                        || localStorage.getItem('exam_entered_' + examId) === '1'
                        || localStorage.getItem('exam_submitted_' + examId) === '1'
                        || (document.cookie.indexOf('exam_entered_' + examId + '=1') !== -1)
                        || (document.cookie.indexOf('exam_submitted_' + examId + '=1') !== -1);

                    if (hasEnteredOrSubmitted) {
                        var reviseBtn = actionGroup.querySelector('.btn-revise');
                        if (!reviseBtn) {
                            actionGroup.innerHTML = '<a href="student-giveExam-page.php?exam_id=' + examId + '&mode=revise" class="btn btn-revise" title="Revise submitted responses"><i class="fa-solid fa-pen-to-square"></i> Revise</a>';
                        }
                    } else {
                        var giveBtn = actionGroup.querySelector('.btn-give-exam');
                        if (!giveBtn) {
                            actionGroup.innerHTML = '<a href="student-giveExam-page.php?exam_id=' + examId + '" class="btn btn-give-exam" title="Take this exam"><i class="fa-solid fa-play"></i> Give Exam</a>';
                        }
                    }
                }
                return;
            }

            // -------------------------------------------------------------
            // STATE 4: PUBLISHED / RESCHEDULED (Upcoming or Ready to Start)
            // -------------------------------------------------------------
            localStorage.removeItem('exam_running_start_' + examId);
            localStorage.removeItem('exam_remaining_ms_' + examId);
            row.removeAttribute('data-running-start');

            if (isNaN(startTimeMs)) {
                if (startTimerBadge) {
                    startTimerBadge.textContent = 'Ready to Start';
                    startTimerBadge.className = 'timer-badge start-timer ready';
                }
            } else if (timeToStart > 0) {
                if (startTimerBadge) {
                    startTimerBadge.textContent = formatTime(timeToStart);
                    startTimerBadge.className = 'timer-badge start-timer active';
                }
            } else {
                if (startTimerBadge) {
                    startTimerBadge.textContent = 'Ready to Start';
                    startTimerBadge.className = 'timer-badge start-timer ready';
                }
            }

            if (durationTimerBadge) {
                durationTimerBadge.textContent = durationMins + ' min';
                durationTimerBadge.className = 'timer-badge duration-timer';
            }

            if (statusCell) {
                if (isRescheduled) {
                    statusCell.innerHTML = '<span class="badge badge-rescheduled"><i class="fa-solid fa-clock-rotate-left"></i> Rescheduled</span>';
                } else {
                    statusCell.innerHTML = '<span class="badge badge-published"><i class="fa-regular fa-clock"></i> Published</span>';
                }
            }

            if (actionGroup) {
                var notStartedBtn = actionGroup.querySelector('.btn-not-started');
                if (!notStartedBtn) {
                    actionGroup.innerHTML = '<button type="button" class="btn btn-not-started" disabled title="Exam has not started yet"><i class="fa-regular fa-clock"></i> Not Started</button>';
                }
            }
        });

        if (statusChanged && currentExamStatusFilter !== 'all') {
            applyExamFilters();
        }
    }

    // Run timer loop every second
    updateRowTimers();
    setInterval(updateRowTimers, 1000);

    // Initial filter application
    applyExamFilters();

    // Live polling: Sync exam state changes from server API
    async function pollExamStatuses() {
        try {
            var requestStartedAt = Date.now();
            var resp = await fetch('student-examTable-page-logic.php?action=get_student_exams&t=' + Date.now());
            if (!resp.ok) return;
            var data = await resp.json();
            var responseReceivedAt = Date.now();
            if (!data.success || !Array.isArray(data.exams)) return;
            if (Number.isFinite(Number(data.server_time_ms))) {
                serverOffsetMs = Number(data.server_time_ms) - ((requestStartedAt + responseReceivedAt) / 2);
            }

            data.exams.forEach(function(exam) {
                var examId = exam.id;
                var row = document.getElementById('exam-row-' + examId);
                if (!row) return;

                var status = (exam.status || 'Published').trim();
                var isRescheduled = Boolean(exam.is_rescheduled) || status.toLowerCase() === 'edited/rescheduled';
                var isSubmitted = (exam.is_submitted === 1 || exam.submission_status === 'Submitted' || exam.submission_status === 'Under Review' || exam.submission_status === 'Graded');

                var hasEntered = isSubmitted 
                    || localStorage.getItem('exam_entered_' + examId) === '1'
                    || localStorage.getItem('exam_submitted_' + examId) === '1';

                row.setAttribute('data-status', status);
                row.setAttribute('data-is-rescheduled', isRescheduled ? '1' : '0');
                row.setAttribute('data-is-submitted', hasEntered ? '1' : '0');
                row.setAttribute('data-start-timestamp', exam.start_timestamp || 0);
                row.setAttribute('data-timer-updated-at-epoch', exam.timer_updated_at_epoch || 0);
                row.setAttribute('data-remaining-seconds', exam.remaining_seconds || 0);
                if (exam.duration) {
                    row.setAttribute('data-duration', exam.duration);
                }
                if (exam.updated_at) {
                    row.setAttribute('data-updated-at', exam.updated_at);
                }
            });

            updateRowTimers();
        } catch (err) {
            // Silently ignore background polling errors
        }
    }

    setInterval(pollExamStatuses, 5000);

    // Track click on "Give Exam" so button transitions to "Revise" on return
    document.addEventListener('click', function(e) {
        var giveBtn = e.target.closest('.btn-give-exam');
        if (giveBtn) {
            var row = giveBtn.closest('tr');
            var examId = row ? row.getAttribute('data-exam-id') : null;
            if (examId) {
                localStorage.setItem('exam_entered_' + examId, '1');
                document.cookie = 'exam_entered_' + examId + '=1; path=/; max-age=86400';
                if (row) {
                    row.setAttribute('data-is-submitted', '1');
                }
            }
        }
    });
});
