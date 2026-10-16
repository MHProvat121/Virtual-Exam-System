(() => {
    const root = document.querySelector('.admin-dashboard-page');
    if (!root) return;

    const endpoint = root.dataset.dashboardUrl || 'admin-dashboard-page.php?ajax=dashboard';
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
    const setText = (id, value) => {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    };
    const number = value => new Intl.NumberFormat().format(Number(value) || 0);

    function updateDhakaClock() {
        const clock = document.getElementById('dhakaClock');
        if (!clock) return;
        clock.textContent = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Asia/Dhaka', day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
        }).format(new Date()) + ' (Asia/Dhaka)';
    }

    function renderExams(exams) {
        const body = document.getElementById('liveExamRows');
        if (!body) return;
        if (!Array.isArray(exams) || exams.length === 0) {
            body.innerHTML = '<tr><td colspan="6" class="empty-cell">No exam records found in exam_header.</td></tr>';
            return;
        }
        body.innerHTML = exams.map(exam => {
            const status = String(exam.status || 'Unknown');
            const statusClass = status.toLowerCase().replace(/[\s/]+/g, '-');
            return `<tr>
                <td><strong>#${Number(exam.id) || 0} ${escapeHtml(exam.exam_title)}</strong><small>${escapeHtml(exam.subject)}</small></td>
                <td>${escapeHtml(exam.batch)}<small>${escapeHtml(exam.course_code || 'No course code')}</small></td>
                <td>${escapeHtml(exam.teacher_name)}</td>
                <td>${escapeHtml(exam.exam_date)}<small>${escapeHtml(String(exam.exam_time || '').slice(0, 5))}</small></td>
                <td><span class="status-pill status-${escapeHtml(statusClass)}">${escapeHtml(status)}</span></td>
                <td>${Number(exam.active_count) || 0} active<small>${Number(exam.submitted_count) || 0} submitted</small></td>
            </tr>`;
        }).join('');
    }

    function renderPresence(participants, features) {
        const list = document.getElementById('presenceList');
        if (!list) return;
        if (!Array.isArray(participants) || participants.length === 0) {
            const note = features?.proctor_presence
                ? 'A candidate appears after their browser establishes a proctoring heartbeat.'
                : 'Run exam-proctoring-migration.sql to enable presence tracking.';
            list.innerHTML = `<p class="empty-state"><i class="fa-solid fa-video-slash"></i><strong>No live candidate peers detected</strong><span>${escapeHtml(note)}</span></p>`;
            return;
        }
        list.innerHTML = participants.map(person => `<article class="presence-row">
            <span class="presence-avatar"><i class="fa-solid fa-user-graduate"></i></span>
            <div><strong>${escapeHtml(person.student_name || 'Student')}</strong><small>${escapeHtml(person.student_id)} · ${escapeHtml(person.exam_title)} (#${Number(person.exam_id) || 0})</small></div>
            <span class="connected-dot" title="Heartbeat received recently"></span>
        </article>`).join('');
    }

    function applySnapshot(data) {
        const metrics = data.metrics || {};
        const health = data.health || {};
        setText('metricActive', number(metrics.active_attempts));
        setText('metricCompleted', number(metrics.completed_exams));
        setText('metricPending', number(metrics.pending_starts));
        setText('metricStudents', number(metrics.students));
        setText('metricPendingEnrollments', number(metrics.pending_enrollments));
        setText('metricOnline', number(metrics.online_candidates));
        setText('presenceCount', number(metrics.online_candidates));
        setText('healthDatabase', health.database || 'Unavailable');
        setText('healthLatency', health.db_latency_ms === null || health.db_latency_ms === undefined ? '—' : health.db_latency_ms);
        setText('healthConnections', health.db_connections === null || health.db_connections === undefined ? 'Unavailable' : number(health.db_connections));
        setText('healthLoad', health.host_load === null || health.host_load === undefined ? 'Unavailable on this host' : health.host_load);
        setText('healthMemory', `${health.php_memory_mb ?? 0} MB`);
        renderExams(data.exams);
        renderPresence(data.participants, data.features);
    }

    async function refreshDashboard() {
        try {
            const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } });
            if (!response.ok) throw new Error(`Dashboard refresh failed (${response.status})`);
            const data = await response.json();
            applySnapshot(data);
        } catch (error) {
            console.warn('Admin dashboard refresh:', error);
        }
    }

    updateDhakaClock();
    window.setInterval(updateDhakaClock, 1000);
    window.setInterval(refreshDashboard, 10000);
})();
