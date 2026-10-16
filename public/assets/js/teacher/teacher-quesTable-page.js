/* ==========================================================
   TEACHER EXAM QUESTIONS TABLE SCRIPT
   Matched to teacher-quesTable-page.php
   ========================================================== */

document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const rows = Array.from(document.querySelectorAll('.exam-row'));
    const LOGIC_URL = 'teacher-quesTable-page-logic.php';
    let serverOffsetMs = rows.length ? Number(rows[0].dataset.serverTimeMs) - Date.now() : 0;
    if (!Number.isFinite(serverOffsetMs)) serverOffsetMs = 0;
    const serverNowMs = () => Date.now() + serverOffsetMs;

    const START_STATUS = 'Running';
    const HOLD_STATUS = 'Cancelled/On Hold';
    const COMPLETED_STATUS = 'Completed';

    const storageKey = (name, examId) => `exam_${name}_${examId}`;

    function formatTime(ms) {
        ms = Math.max(0, Number(ms) || 0);
        const totalSeconds = Math.floor(ms / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        return [hours, minutes, seconds].map(v => String(v).padStart(2, '0')).join(':');
    }

    function parseLocalDateTime(value) {
        if (!value) return NaN;
        const text = String(value).trim().replace('T', ' ');
        const parts = text.split(/\s+/);
        if (parts.length < 2) {
            const parsed = new Date(value).getTime();
            return Number.isFinite(parsed) ? parsed : NaN;
        }

        const date = parts[0].split('-').map(Number);
        const time = parts[1].split(':').map(Number);
        if (date.length !== 3 || date.some(Number.isNaN)) return NaN;

        return new Date(
            date[0],
            date[1] - 1,
            date[2],
            Number.isFinite(time[0]) ? time[0] : 0,
            Number.isFinite(time[1]) ? time[1] : 0,
            Number.isFinite(time[2]) ? time[2] : 0
        ).getTime();
    }

    function getStoredNumber(key) {
        const value = Number(localStorage.getItem(key));
        return Number.isFinite(value) ? value : NaN;
    }

    async function postLogic(formData) {
        const response = await fetch(LOGIC_URL, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();
        if (!data || data.success !== true) {
            throw new Error(data?.message || 'Server rejected the request.');
        }
        return data;
    }

    function updateStatusInDB(examId, status, onSuccess, onError) {
        const formData = new FormData();
        formData.append('exam_id', examId);
        formData.append('status', status);

        postLogic(formData)
            .then(data => {
                if (Number.isFinite(Number(data.server_time_ms))) {
                    serverOffsetMs = Number(data.server_time_ms) - Date.now();
                }
                const row = rows.find(item => item.dataset.examId === String(examId));
                if (row) {
                    if (data.remaining_seconds !== undefined) row.dataset.remainingSeconds = String(data.remaining_seconds);
                    if (data.timer_updated_at_epoch !== undefined) row.dataset.timerUpdatedAtEpoch = String(data.timer_updated_at_epoch);
                }
                onSuccess?.(data);
            })
            .catch(error => {
                console.error('Status update failed:', error);
                onError?.(error);
            });
    }

    /* ----------------------------------------------------------
       Start / Resume modal
       The PHP page already renders this modal, so JS does not
       inject a second copy into the DOM.
       ---------------------------------------------------------- */
    const startModal = document.getElementById('startExamModal');
    const startModalTitle = document.getElementById('startModalTitle');
    const startModalDesc = document.getElementById('startModalDesc');
    const startModalSub = document.getElementById('startModalSub');
    const startModalIcon = document.getElementById('startModalIcon');
    const modalExamDetail = document.getElementById('modalExamDetail');
    const modalRemainingInfo = document.getElementById('modalExamRemainingInfo');
    const modalRemainingTime = document.getElementById('modalRemainingTime');
    const cancelStartBtn = document.getElementById('cancelStartExamBtn');
    const confirmStartBtn = document.getElementById('confirmStartExamBtn');
    const confirmStartBtnText = document.getElementById('confirmStartBtnText');

    let pendingStart = null;

    function showStartModal(examId, row, button, isResume) {
        pendingStart = { examId, row, button, isResume };

        const title = row.dataset.examTitle || 'Exam';
        const durationMs = (parseInt(row.dataset.duration, 10) || 0) * 60000;
        let remaining = isResume ? Math.max(0, Number(row.dataset.remainingSeconds) || 0) * 1000 : Number(row.dataset.remainingMs);

        if ((!Number.isFinite(remaining) || remaining <= 0) && isResume) {
            remaining = Number(row.dataset.remainingMs);
        }
        if (!Number.isFinite(remaining) || remaining <= 0) {
            remaining = getStoredNumber(storageKey('remaining_ms', examId));
        }
        if (!Number.isFinite(remaining) || remaining <= 0) remaining = durationMs;

        modalExamDetail && (modalExamDetail.textContent = `#${examId} - ${title}`);

        if (isResume) {
            startModalTitle && (startModalTitle.textContent = 'Confirm Exam Resume');
            startModalSub && (startModalSub.textContent = 'Resume Paused Exam');
            startModalDesc && (startModalDesc.textContent = `Are you sure you want to resume this exam? The countdown will continue from ${formatTime(remaining)} remaining.`);
            confirmStartBtnText && (confirmStartBtnText.textContent = 'Yes, Resume Exam');
            if (confirmStartBtn) confirmStartBtn.style.backgroundColor = '#d97706';
            if (startModalIcon) {
                startModalIcon.classList.add('resume');
                startModalIcon.style.backgroundColor = '#fef3c7';
                startModalIcon.style.color = '#d97706';
            }
            if (modalRemainingInfo) modalRemainingInfo.style.display = 'block';
            if (modalRemainingTime) modalRemainingTime.textContent = formatTime(remaining);
        } else {
            startModalTitle && (startModalTitle.textContent = 'Confirm Exam Launch');
            startModalSub && (startModalSub.textContent = 'Live Exam Action');
            startModalDesc && (startModalDesc.textContent = 'Are you sure you want to start the exam? Students will immediately be allowed to enter the exam portal and begin their test.');
            confirmStartBtnText && (confirmStartBtnText.textContent = 'Yes, Start Exam');
            if (confirmStartBtn) confirmStartBtn.style.backgroundColor = '#16a34a';
            if (startModalIcon) {
                startModalIcon.classList.remove('resume');
                startModalIcon.style.backgroundColor = '';
                startModalIcon.style.color = '';
            }
            if (modalRemainingInfo) modalRemainingInfo.style.display = 'none';
        }

        startModal?.classList.add('show');
        startModal?.setAttribute('aria-hidden', 'false');
        confirmStartBtn?.focus();
    }

    function hideStartModal() {
        startModal?.classList.remove('show');
        startModal?.setAttribute('aria-hidden', 'true');
        pendingStart = null;
    }

    cancelStartBtn?.addEventListener('click', hideStartModal);
    startModal?.addEventListener('click', event => {
        if (event.target === startModal) hideStartModal();
    });

    /* ----------------------------------------------------------
       Delete modal
       ---------------------------------------------------------- */
    const deleteModal = document.getElementById('deleteExamModal');
    const deleteModalDetail = document.getElementById('deleteModalExamDetail');
    const cancelDeleteBtn = document.getElementById('cancelDeleteExamBtn');
    const confirmDeleteBtn = document.getElementById('confirmDeleteExamBtn');
    const deleteBtnLabel = document.getElementById('deleteBtnLabel');

    let pendingDelete = null;
    let deleteTimer = null;
    let deleteSeconds = 3;

    function showDeleteModal(examId, row) {
        pendingDelete = { examId, row };
        deleteSeconds = 3;

        const title = row.dataset.examTitle || 'Exam';
        deleteModalDetail && (deleteModalDetail.textContent = `#${examId} - ${title}`);
        if (confirmDeleteBtn) confirmDeleteBtn.disabled = true;
        if (deleteBtnLabel) deleteBtnLabel.textContent = 'Delete Exam (3s)';

        deleteModal?.classList.add('show');
        deleteModal?.setAttribute('aria-hidden', 'false');

        clearInterval(deleteTimer);
        deleteTimer = setInterval(() => {
            deleteSeconds -= 1;
            if (deleteSeconds <= 0) {
                clearInterval(deleteTimer);
                deleteTimer = null;
                if (confirmDeleteBtn) confirmDeleteBtn.disabled = false;
                if (deleteBtnLabel) deleteBtnLabel.textContent = 'Yes, Delete Entire Exam';
            } else if (deleteBtnLabel) {
                deleteBtnLabel.textContent = `Delete Exam (${deleteSeconds}s)`;
            }
        }, 1000);
    }

    function hideDeleteModal() {
        clearInterval(deleteTimer);
        deleteTimer = null;
        deleteModal?.classList.remove('show');
        deleteModal?.setAttribute('aria-hidden', 'true');
        if (confirmDeleteBtn) confirmDeleteBtn.disabled = true;
        if (deleteBtnLabel) deleteBtnLabel.textContent = 'Delete Exam (3s)';
        pendingDelete = null;
    }

    cancelDeleteBtn?.addEventListener('click', hideDeleteModal);
    deleteModal?.addEventListener('click', event => {
        if (event.target === deleteModal) hideDeleteModal();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            if (startModal?.classList.contains('show')) hideStartModal();
            if (deleteModal?.classList.contains('show')) hideDeleteModal();
        }
    });

    confirmDeleteBtn?.addEventListener('click', async () => {
        if (confirmDeleteBtn.disabled || !pendingDelete) return;

        const { examId, row } = pendingDelete;
        confirmDeleteBtn.disabled = true;
        if (deleteBtnLabel) deleteBtnLabel.textContent = 'Deleting...';

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('exam_id', examId);

        try {
            const data = await postLogic(formData);
            row.style.transition = 'opacity .25s ease, transform .25s ease';
            row.style.opacity = '0';
            row.style.transform = 'scale(.98)';

            setTimeout(() => {
                row.remove();
                const remainingRows = document.querySelectorAll('.exam-row');
                if (!remainingRows.length) {
                    const tbody = document.querySelector('.table-section table tbody');
                    if (tbody) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="16" style="text-align:center;padding:30px;color:#666;">
                                    <i class="fa-regular fa-folder-open" style="font-size:2rem;margin-bottom:10px;display:block;color:#9ca3af;"></i>
                                    No exam headers found.
                                </td>
                            </tr>`;
                    }
                }
            }, 250);

            Object.keys(localStorage)
                .filter(key => key.endsWith(`_${examId}`))
                .forEach(key => localStorage.removeItem(key));

            hideDeleteModal();
        } catch (error) {
            console.error('Delete request failed:', error);
            alert(`Error deleting exam: ${error.message || 'Network error.'}`);
            confirmDeleteBtn.disabled = false;
            if (deleteBtnLabel) deleteBtnLabel.textContent = 'Yes, Delete Entire Exam';
        }
    });

    /* ----------------------------------------------------------
       Start / Resume confirmation
       ---------------------------------------------------------- */
    confirmStartBtn?.addEventListener('click', () => {
        if (!pendingStart) return;

        const { examId, row, button, isResume } = pendingStart;
        const durationMs = (parseInt(row.dataset.duration, 10) || 0) * 60000;
        let remainingMs = durationMs;

        if (isResume) {
            remainingMs = Math.max(0, Number(row.dataset.remainingSeconds) || 0) * 1000;
            if (!Number.isFinite(remainingMs) || remainingMs <= 0) remainingMs = Number(row.dataset.remainingMs);
            if (!Number.isFinite(remainingMs) || remainingMs <= 0) {
                remainingMs = getStoredNumber(storageKey('remaining_ms', examId));
            }
            if (!Number.isFinite(remainingMs) || remainingMs <= 0) remainingMs = durationMs;
        }

        const runningStart = Date.now() - Math.max(0, durationMs - remainingMs);
        row.dataset.remainingMs = String(remainingMs);
        row.dataset.runningStart = String(runningStart);

        localStorage.setItem(storageKey('remaining_ms', examId), String(remainingMs));
        localStorage.setItem(storageKey('running_start', examId), String(runningStart));
        localStorage.setItem(storageKey('status', examId), START_STATUS);
        localStorage.removeItem(storageKey('paused_remaining', examId));

        confirmStartBtn.disabled = true;
        updateStatusInDB(examId, START_STATUS, data => {
            if (Number.isFinite(Number(data.remaining_seconds))) {
                row.dataset.remainingSeconds = String(data.remaining_seconds);
                row.dataset.remainingMs = String(Number(data.remaining_seconds) * 1000);
            }
            if (Number.isFinite(Number(data.timer_updated_at_epoch))) row.dataset.timerUpdatedAtEpoch = String(data.timer_updated_at_epoch);
            setRunningUI(row, button);
            hideStartModal();
        }, () => {
            confirmStartBtn.disabled = false;
        });
    });

    function setRunningUI(row, button) {
        row.dataset.status = START_STATUS;
        const dropdown = row.querySelector('.status-select');
        if (dropdown) dropdown.value = START_STATUS;

        if (button) {
            button.disabled = false;
            button.className = 'btn btn-start start-exam-btn is-running';
            button.style.backgroundColor = '#0d6efd';
            button.style.cursor = 'default';
            button.innerHTML = '<i class="fa-solid fa-circle-dot fa-fade"></i> Exam Running';
            button.title = 'Exam is currently running';
            button.removeAttribute('data-is-resume');
        }

        const inspect = row.querySelector('.btn-inspect');
        if (inspect) {
            inspect.classList.add('is-live');
            if (!inspect.querySelector('.live-badge-dot')) {
                inspect.insertAdjacentHTML('beforeend', ' <span class="live-badge-dot"></span>');
            }
        }
    }

    function setHoldUI(row, remainingMs) {
        row.dataset.status = HOLD_STATUS;
        const dropdown = row.querySelector('.status-select');
        const button = row.querySelector('.start-exam-btn');
        const durationBadge = row.querySelector('.duration-timer');
        const startBadge = row.querySelector('.start-timer');

        if (dropdown) dropdown.value = HOLD_STATUS;
        if (startBadge) {
            startBadge.textContent = 'On Hold';
            startBadge.className = 'timer-badge start-timer on-hold';
        }
        if (durationBadge) {
            durationBadge.textContent = `${formatTime(remainingMs)} (On Hold)`;
            durationBadge.className = 'timer-badge duration-timer on-hold';
        }
        if (button && !button.classList.contains('is-completed')) {
            button.disabled = false;
            button.className = 'btn btn-start btn-resume start-exam-btn is-resume';
            button.style.backgroundColor = '#d97706';
            button.style.cursor = 'pointer';
            button.innerHTML = '<i class="fa-solid fa-play"></i> Resume Exam';
            button.title = `Resume exam from ${formatTime(remainingMs)} remaining`;
            button.dataset.isResume = 'true';
        }
    }

    /* ----------------------------------------------------------
       Per-row exam lifecycle
       ---------------------------------------------------------- */
    rows.forEach(row => {
        const examId = row.dataset.examId;
        if (!examId) return;

        const durationMs = (parseInt(row.dataset.duration, 10) || 0) * 60000;
        const startTimeSeconds = Number(row.dataset.startTimestamp);
        const startTime = Number.isFinite(startTimeSeconds) && startTimeSeconds > 0 ? startTimeSeconds * 1000 : NaN;

        const dropdown = row.querySelector('.status-select');
        const startBtn = row.querySelector('.start-exam-btn');
        const deleteBtn = row.querySelector('.btn-delete-exam');
        const startTimer = row.querySelector('.start-timer');
        const durationTimer = row.querySelector('.duration-timer');

        /* PHP already disables every status except Cancelled/On Hold. */
        if (dropdown) {
            dropdown.querySelectorAll('option').forEach(option => {
                option.disabled = option.value.toLowerCase() !== HOLD_STATUS.toLowerCase();
            });
        }

        deleteBtn?.addEventListener('click', event => {
            event.preventDefault();
            showDeleteModal(examId, row);
        });

        dropdown?.addEventListener('change', function () {
            const selected = this.value;
            if (selected.toLowerCase() !== HOLD_STATUS.toLowerCase()) {
                this.value = row.dataset.status || 'Published';
                return;
            }

            const baseRemainingMs = Math.max(0, Number(row.dataset.remainingSeconds) || durationMs / 1000) * 1000;
            const updatedAtMs = (Number(row.dataset.timerUpdatedAtEpoch) || 0) * 1000;
            const remaining = Math.max(0, baseRemainingMs - Math.max(0, serverNowMs() - updatedAtMs));

            if (remaining <= 0) {
                this.value = COMPLETED_STATUS;
                row.dataset.status = COMPLETED_STATUS;
                return;
            }

            row.dataset.remainingMs = String(remaining);
            localStorage.setItem(storageKey('remaining_ms', examId), String(remaining));
            localStorage.setItem(storageKey('paused_remaining', examId), String(remaining));
            localStorage.setItem(storageKey('status', examId), HOLD_STATUS);

            updateStatusInDB(examId, HOLD_STATUS, data => {
                const savedRemaining = Math.max(0, Number(data.remaining_seconds) || Math.ceil(remaining / 1000)) * 1000;
                row.dataset.remainingMs = String(savedRemaining);
                setHoldUI(row, savedRemaining);
            }, () => {
                this.value = row.dataset.status || 'Published';
            });
        });

        startBtn?.addEventListener('click', event => {
            event.preventDefault();
            if (startBtn.disabled || startBtn.classList.contains('is-completed')) return;

            const status = (row.dataset.status || dropdown?.value || 'Published').trim().toLowerCase();
            if (status === 'running' || status === 'completed') return;

            const isResume = status === HOLD_STATUS.toLowerCase()
                || startBtn.classList.contains('is-resume')
                || startBtn.dataset.isResume === 'true';

            showStartModal(examId, row, startBtn, isResume);
        });

        function tick() {
            const now = serverNowMs();
            const status = (row.dataset.status || dropdown?.value || 'Published').trim();
            const lowerStatus = status.toLowerCase();
            const untilStart = startTime - now;

            /* Start-time badge and Start button */
            if (lowerStatus === 'published' || lowerStatus === 'edited/rescheduled') {
                if (Number.isFinite(startTime) && untilStart > 0) {
                    startTimer && (startTimer.textContent = formatTime(untilStart));
                    startTimer && (startTimer.className = 'timer-badge start-timer active');
                    if (startBtn && !startBtn.classList.contains('is-completed')) {
                        startBtn.disabled = true;
                        startBtn.title = `Exam start time has not arrived yet (${formatTime(untilStart)} remaining)`;
                    }
                } else {
                    startTimer && (startTimer.textContent = 'Ready to Start');
                    startTimer && (startTimer.className = 'timer-badge start-timer ready');
                    if (startBtn && !startBtn.classList.contains('is-completed') && !startBtn.classList.contains('is-running')) {
                        startBtn.disabled = false;
                        startBtn.title = 'Click to launch this exam immediately for students';
                    }
                }
            } else if (lowerStatus === 'running') {
                startTimer && (startTimer.textContent = 'In Progress');
                startTimer && (startTimer.className = 'timer-badge start-timer running');
            } else if (lowerStatus === HOLD_STATUS.toLowerCase()) {
                startTimer && (startTimer.textContent = 'On Hold');
                startTimer && (startTimer.className = 'timer-badge start-timer on-hold');
            } else if (lowerStatus === 'completed') {
                startTimer && (startTimer.textContent = 'Completed');
                startTimer && (startTimer.className = 'timer-badge start-timer finished');
            }

            /* Duration badge */
            if (!durationTimer) return;

            if (lowerStatus === 'running') {
                const baseRemainingMs = Math.max(0, Number(row.dataset.remainingSeconds) || durationMs / 1000) * 1000;
                const updatedAtMs = (Number(row.dataset.timerUpdatedAtEpoch) || 0) * 1000;
                const remaining = baseRemainingMs - Math.max(0, now - updatedAtMs);

                if (remaining > 0) {
                    row.dataset.remainingMs = String(remaining);
                    localStorage.setItem(storageKey('remaining_ms', examId), String(remaining));
                    durationTimer.textContent = formatTime(remaining);
                    durationTimer.className = 'timer-badge duration-timer active';
                } else {
                    row.dataset.remainingMs = '0';
                    durationTimer.textContent = 'Expired';
                    durationTimer.className = 'timer-badge duration-timer expired';

                    if (startBtn) {
                        startBtn.disabled = true;
                        startBtn.className = 'btn btn-start is-completed';
                        startBtn.style.backgroundColor = '#6c757d';
                        startBtn.style.cursor = 'not-allowed';
                        startBtn.innerHTML = '<i class="fa-solid fa-check"></i> Completed';
                        startBtn.title = 'Exam has ended';
                        startBtn.removeAttribute('data-is-resume');
                    }

                    if (row.dataset.status !== COMPLETED_STATUS) {
                        row.dataset.status = COMPLETED_STATUS;
                        if (dropdown) dropdown.value = COMPLETED_STATUS;
                        localStorage.setItem(storageKey('status', examId), COMPLETED_STATUS);
                        updateStatusInDB(examId, COMPLETED_STATUS);
                    }
                }
            } else if (lowerStatus === HOLD_STATUS.toLowerCase()) {
                let remaining = Math.max(0, Number(row.dataset.remainingSeconds) || durationMs / 1000) * 1000;
                durationTimer.textContent = `${formatTime(remaining)} (On Hold)`;
                durationTimer.className = 'timer-badge duration-timer on-hold';
            } else if (lowerStatus === 'completed') {
                durationTimer.textContent = 'Finished';
                durationTimer.className = 'timer-badge duration-timer finished';
            } else {
                durationTimer.textContent = '--:--:--';
                durationTimer.className = 'timer-badge duration-timer';
            }
        }

        tick();
        setInterval(tick, 1000);
    });
});

// -------------------------------------------------------------
// CONTROLS-CARD FILTERING (STATUS TABS + LIVE SEARCH)
// Prototyped from admin-manageEnroll-page
// -------------------------------------------------------------
let currentTeacherExamStatusFilter = 'all';

function filterExamStatus(status, tabElement) {
    currentTeacherExamStatusFilter = (status || 'all').toLowerCase().trim();

    const container = document.getElementById('examStatusTabs');
    if (container) {
        const tabs = container.querySelectorAll('.tab-link');
        tabs.forEach(t => t.classList.remove('active'));
    }
    if (tabElement) {
        tabElement.classList.add('active');
    }

    applyTeacherExamFilters();
}

function filterTeacherExams() {
    applyTeacherExamFilters();
}

function applyTeacherExamFilters() {
    const searchInput = document.getElementById('teacherExamSearch');
    const term = searchInput ? searchInput.value.toLowerCase().trim() : '';
    const cards = document.querySelectorAll('.batch-card');

    let visibleCards = 0;

    cards.forEach(card => {
        const cardBatchName = (card.getAttribute('data-batch') || '').toLowerCase();
        const cardText = card.innerText.toLowerCase();
        const cardRows = card.querySelectorAll('tbody tr.exam-row');

        const cardMatchesSearch = term === '' || cardText.includes(term) || cardBatchName.includes(term);

        if (!cardMatchesSearch) {
            card.style.display = 'none';
            return;
        }

        let visibleRowsCount = 0;

        if (cardRows.length > 0) {
            cardRows.forEach(r => {
                const rowStatus = (r.getAttribute('data-status') || '').toLowerCase().trim();
                const rowText = r.innerText.toLowerCase();

                let matchesStatus = false;
                if (currentTeacherExamStatusFilter === 'all') {
                    matchesStatus = true;
                } else if (currentTeacherExamStatusFilter === 'running') {
                    matchesStatus = (rowStatus === 'running');
                } else if (currentTeacherExamStatusFilter === 'published') {
                    matchesStatus = (rowStatus === 'published');
                } else if (currentTeacherExamStatusFilter === 'completed') {
                    matchesStatus = (rowStatus === 'completed');
                } else if (currentTeacherExamStatusFilter.includes('hold')) {
                    matchesStatus = (rowStatus.includes('hold') || rowStatus.includes('cancelled'));
                } else if (currentTeacherExamStatusFilter.includes('rescheduled')) {
                    matchesStatus = (rowStatus.includes('rescheduled') || rowStatus.includes('edited'));
                } else {
                    matchesStatus = (rowStatus === currentTeacherExamStatusFilter);
                }

                const matchesSearch = (term === '') || rowText.includes(term);

                if (matchesStatus && matchesSearch) {
                    r.style.display = '';
                    visibleRowsCount++;
                } else {
                    r.style.display = 'none';
                }
            });

            if (visibleRowsCount > 0) {
                card.style.display = 'block';
                visibleCards++;
            } else {
                card.style.display = 'none';
            }
        } else {
            if (currentTeacherExamStatusFilter === 'all' && cardMatchesSearch) {
                card.style.display = 'block';
                visibleCards++;
            } else {
                card.style.display = 'none';
            }
        }
    });

    const emptyContainer = document.getElementById('searchNoResultsMessage');
    if (emptyContainer) {
        emptyContainer.style.display = (visibleCards === 0 && cards.length > 0) ? 'block' : 'none';
    }
}

window.filterExamStatus = filterExamStatus;
window.filterTeacherExams = filterTeacherExams;

