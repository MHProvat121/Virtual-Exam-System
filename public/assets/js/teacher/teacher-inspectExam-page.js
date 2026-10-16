/* Teacher live exam inspection: database timer, roster, proctoring, and force submit. */
document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('inspectPageApp');
    if (!app) return;

    const examId = app.dataset.examId;
    const endpoint = `teacher-inspectExam-page-logic.php`;
    const timerDisplay = document.getElementById('inspectDurationTimer');
    const heroStatusBadge = document.getElementById('heroStatusBadge');
    const refreshBtn = document.getElementById('refreshInspectBtn');
    const searchInput = document.getElementById('studentSearchInput');
    const filterTabs = [...document.querySelectorAll('.tab-btn')];
    let currentStatus = app.dataset.examStatus || 'Published';
    let baseRemainingMs = Number(app.dataset.remainingSeconds) * 1000;
    if (!Number.isFinite(baseRemainingMs)) baseRemainingMs = Number(app.dataset.duration || 60) * 60 * 1000;
    let timerAnchorMs = (Number(app.dataset.timerUpdatedAtEpoch) || 0) * 1000;
    let serverOffsetMs = (Number(app.dataset.serverTimeMs) || Date.now()) - Date.now();
    let submittedMap = {};
    let activeStudentIds = new Set();
    let activeFilter = 'all';
    const forceModal = document.getElementById('forceSubmitModal');
    const forceSubmitTarget = document.getElementById('forceSubmitStudentTarget');
    const confirmForceSubmitBtn = document.getElementById('confirmForceSubmitBtn');
    const cancelForceSubmitBtn = document.getElementById('cancelForceSubmitBtn');
    let targetStudentId = null;

    function formatTime(milliseconds) {
        const safe = Math.floor(Math.max(0, Number(milliseconds) || 0) / 1000);
        const hours = String(Math.floor(safe / 3600)).padStart(2, '0');
        const minutes = String(Math.floor((safe % 3600) / 60)).padStart(2, '0');
        const secs = String(safe % 60).padStart(2, '0');
        return `${hours}:${minutes}:${secs}`;
    }

    function renderTimer() {
        if (!timerDisplay) return;
        const status = currentStatus.toLowerCase();
        if (status === 'completed') {
            timerDisplay.textContent = '00:00:00 (Completed)';
            timerDisplay.style.color = '#10b981';
        } else if (status === 'cancelled/on hold') {
            timerDisplay.textContent = `PAUSED (${formatTime(baseRemainingMs)})`;
            timerDisplay.style.color = '#f59e0b';
        } else if (status === 'running') {
            const nowOnServer = Date.now() + serverOffsetMs;
            const elapsedMs = Math.max(0, nowOnServer - timerAnchorMs);
            const remainingMs = Math.max(0, baseRemainingMs - elapsedMs);
            timerDisplay.textContent = formatTime(remainingMs);
            timerDisplay.style.color = remainingMs <= 300000 ? '#f87171' : '#38bdf8';
        } else {
            timerDisplay.textContent = 'Not Started';
            timerDisplay.style.color = '#64748b';
        }
    }
    window.setInterval(renderTimer, 1000);
    renderTimer();

    function statusMarkup(isLive, isSubmitted) {
        if (isLive) return '<span class="status-pill pill-progress"><span class="pulse-dot"></span> Giving Exam</span>';
        if (isSubmitted) return '<span class="status-pill pill-submitted"><i class="fa-solid fa-check"></i> Submitted</span>';
        return '<span class="status-pill pill-not-started"><i class="fa-regular fa-circle"></i> Not Connected</span>';
    }

    function normalizedSubmissionStatus(record) {
        return String(record?.status || record?.submission_status || '')
            .trim()
            .toLowerCase()
            .replace(/\s+/g, ' ');
    }

    function isSubmittedStatus(status) {
        return ['submitted', 'under review', 'graded'].includes(status);
    }

    function isAttemptInProgressStatus(status) {
        return ['in progress', 'in-progress', 'started'].includes(status);
    }

    function updateRoster() {
        let inProgress = 0;
        let submitted = 0;
        let notStarted = 0;
        document.querySelectorAll('.student-row').forEach(row => {
            const id = row.dataset.studentId;
            const submissionStatus = normalizedSubmissionStatus(submittedMap[id]);
            const isConnected = activeStudentIds.has(id);
            const isLive = isConnected || isAttemptInProgressStatus(submissionStatus);
            const isSubmitted = isSubmittedStatus(submissionStatus);
            const statusCell = row.children[2];
            if (statusCell) statusCell.innerHTML = statusMarkup(isLive, isSubmitted);
            row.dataset.statusType = isLive ? 'in_progress' : (isSubmitted ? 'submitted' : 'not_started');
            const forceBtn = row.querySelector('.btn-force-submit');
            if (forceBtn) forceBtn.hidden = !isConnected;
            if (isLive) inProgress++;
            else if (isSubmitted) submitted++;
            else notStarted++;
        });
        const count = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = String(value); };
        const kpiProgress = document.getElementById('kpiInProgress');
        if (kpiProgress) kpiProgress.childNodes[0].nodeValue = `${inProgress} `;
        count('kpiSubmitted', submitted);
        count('kpiNotStarted', notStarted);
        count('countFilterProgress', inProgress);
        count('countFilterSubmitted', submitted);
        count('countFilterNotStarted', notStarted);
        applyFilters();
    }

    function applyFilters() {
        const query = (searchInput?.value || '').toLowerCase().trim();
        document.querySelectorAll('.student-row').forEach(row => {
            const id = (row.dataset.studentId || '').toLowerCase();
            const name = (row.dataset.studentName || '').toLowerCase();
            const matchesFilter = activeFilter === 'all' || row.dataset.statusType === activeFilter;
            row.style.display = matchesFilter && (!query || id.includes(query) || name.includes(query)) ? '' : 'none';
        });
    }

    filterTabs.forEach(tab => tab.addEventListener('click', () => {
        filterTabs.forEach(item => item.classList.remove('active'));
        tab.classList.add('active');
        activeFilter = tab.dataset.filter || 'all';
        applyFilters();
    }));
    searchInput?.addEventListener('input', applyFilters);

    async function fetchLiveData() {
        const sentAt = Date.now();
        try {
            const response = await fetch(`${endpoint}?action=poll_live_data&exam_id=${encodeURIComponent(examId)}`, { cache: 'no-store' });
            const data = await response.json();
            if (!data.success) return;
            const receivedAt = Date.now();
            if (Number(data.server_time_ms)) serverOffsetMs = Number(data.server_time_ms) - ((sentAt + receivedAt) / 2);
            if (data.exam) {
                currentStatus = data.exam.status || currentStatus;
                const remaining = Number(data.exam.remaining_seconds);
                if (Number.isFinite(remaining)) baseRemainingMs = Math.max(0, remaining) * 1000;
                const anchor = Number(data.exam.timer_updated_at_epoch) || 0;
                timerAnchorMs = anchor * 1000;
                if (heroStatusBadge) {
                    heroStatusBadge.textContent = currentStatus;
                    heroStatusBadge.className = `badge-exam-status status-${currentStatus.toLowerCase().replace(/[\/\s]/g, '-')}`;
                }
                renderTimer();
            }
            submittedMap = data.submissions || {};
            updateRoster();
        } catch (error) {
            console.warn('Inspection polling error:', error);
        }
    }
    fetchLiveData();
    window.setInterval(fetchLiveData, 5000);
    refreshBtn?.addEventListener('click', () => {
        refreshBtn.classList.add('fa-spin');
        fetchLiveData().finally(() => window.setTimeout(() => refreshBtn.classList.remove('fa-spin'), 600));
    });

    const proctor = window.ExamProctoring;
    const proctorStatus = document.getElementById('teacherProctorStatus');
    const startProctoringBtn = document.getElementById('btnStartProctoring') || document.getElementById('btnStartProctoringButton');
    if (proctor) {
        proctor.init({
            onParticipants(participants, connectionStates) {
                activeStudentIds = new Set(participants.map(item => String(item.student_id)));
                document.querySelectorAll('.student-row').forEach(row => {
                    const liveStatus = row.querySelector('[data-proctor-status]');
                    const connection = [...connectionStates.values()].find(item => String(item.student_id) === String(row.dataset.studentId));
                    if (liveStatus) {
                        liveStatus.textContent = connection?.state === 'connected' ? 'Audio & video connected' : (connection ? 'Connecting…' : (activeStudentIds.has(row.dataset.studentId) ? 'Joining…' : 'Not connected'));
                        liveStatus.dataset.proctorStatus = connection?.state || (activeStudentIds.has(row.dataset.studentId) ? 'connecting' : 'disconnected');
                    }
                });
                if (proctorStatus) {
                    const connected = [...connectionStates.values()].filter(item => item.state === 'connected').length;
                    proctorStatus.textContent = activeStudentIds.size ? `${connected} of ${activeStudentIds.size} student feed(s) connected` : 'Waiting for students to join the exam';
                }
                updateRoster();
            },
            onError(message) { if (proctorStatus) proctorStatus.textContent = message; }
        });
        startProctoringBtn?.addEventListener('click', async () => {
            startProctoringBtn.disabled = true;
            if (proctorStatus) proctorStatus.textContent = 'Requesting teacher camera and microphone…';
            try {
                await proctor.start();
                startProctoringBtn.innerHTML = '<i class="fa-solid fa-video"></i> Proctoring Active';
                if (proctorStatus) proctorStatus.textContent = 'Camera and microphone are active; connecting students…';
            } catch (error) {
                startProctoringBtn.disabled = false;
                if (proctorStatus) proctorStatus.textContent = error.message || 'Camera and microphone permission is required.';
            }
        });
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('.btn-force-submit');
        if (!button || button.hidden) return;
        targetStudentId = button.dataset.studentId;
        if (forceSubmitTarget) forceSubmitTarget.textContent = `${button.dataset.studentName || 'Student'} (ID: #${targetStudentId})`;
        if (forceModal) { forceModal.style.display = 'flex'; forceModal.setAttribute('aria-hidden', 'false'); }
    });
    cancelForceSubmitBtn?.addEventListener('click', () => {
        if (forceModal) { forceModal.style.display = 'none'; forceModal.setAttribute('aria-hidden', 'true'); }
        targetStudentId = null;
    });
    confirmForceSubmitBtn?.addEventListener('click', async () => {
        if (!targetStudentId) return;
        confirmForceSubmitBtn.disabled = true;
        try {
            const response = await fetch(`${endpoint}?action=force_submit&exam_id=${encodeURIComponent(examId)}&student_id=${encodeURIComponent(targetStudentId)}`, { method: 'POST' });
            const data = await response.json();
            if (!data.success) { window.alert(data.message || 'Failed to force submit.'); return; }
            if (forceModal) { forceModal.style.display = 'none'; forceModal.setAttribute('aria-hidden', 'true'); }
            await fetchLiveData();
        } catch (error) {
            window.alert('Network error while force submitting.');
        } finally {
            confirmForceSubmitBtn.disabled = false;
            targetStudentId = null;
        }
    });

    document.getElementById('btnHoldFromInspect')?.addEventListener('click', async () => {
        if (!window.confirm('Put this examination on hold? Students will be paused.')) return;
        const response = await fetch(`${endpoint}?action=hold_exam&exam_id=${encodeURIComponent(examId)}`, { method: 'POST' });
        const data = await response.json();
        if (data.success) location.reload();
    });
    document.getElementById('btnResumeFromInspect')?.addEventListener('click', async () => {
        if (!window.confirm('Resume this examination for all students?')) return;
        const response = await fetch(`${endpoint}?action=resume_exam&exam_id=${encodeURIComponent(examId)}`, { method: 'POST' });
        const data = await response.json();
        if (data.success) location.reload();
    });
});
