// Search Filter Function
function filterExamTable() {
    const input = document.getElementById('tableSearch');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('examTable');
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        let rowMatch = false;
        const tds = tr[i].getElementsByTagName('td');
        if (tds.length === 1) continue;

        for (let j = 0; j < tds.length; j++) {
            if (tds[j]) {
                const txtValue = tds[j].textContent || tds[j].innerText;
                if (txtValue.toLowerCase().indexOf(filter) > -1) {
                    rowMatch = true;
                    break;
                }
            }
        }
        tr[i].style.display = rowMatch ? "" : "none";
    }
}

// Local Datetime Parser
function parseLocalDateTime(dateStr) {
    if (!dateStr) return NaN;
    const cleanStr = dateStr.replace('T', ' ').trim();
    const parts = cleanStr.split(' ');
    if (parts.length < 2) return new Date(dateStr).getTime();

    const [datePart, timePart] = parts;
    const [year, month, day] = datePart.split('-').map(Number);
    const [hours, minutes, seconds] = timePart.split(':').map(Number);

    return new Date(year, month - 1, day, hours || 0, minutes || 0, seconds || 0).getTime();
}

// Time Formatter (HH:MM:SS)
function formatTime(ms) {
    if (ms <= 0) return '00:00:00';
    const totalSecs = Math.floor(ms / 1000);
    const hours = Math.floor(totalSecs / 3600);
    const mins = Math.floor((totalSecs % 3600) / 60);
    const secs = totalSecs % 60;

    return [
        hours.toString().padStart(2, '0'),
        mins.toString().padStart(2, '0'),
        secs.toString().padStart(2, '0')
    ].join(':');
}

// Dedicated Exam List Countdown Timer
function updateExamListTimers() {
    const rows = document.querySelectorAll('.exam-list-row');
    const now = Date.now();

    rows.forEach(row => {
        const startTimeStr = row.getAttribute('data-start-time');
        const status = (row.getAttribute('data-status') || '').trim().toLowerCase();
        const startTimerBadge = row.querySelector('.start-timer');

        if (!startTimerBadge || !startTimeStr) return;

        const startTime = parseLocalDateTime(startTimeStr);
        const timeToStart = startTime - now;

        if (status === 'published' || status === 'edited/rescheduled' || status === 'active') {
            if (isNaN(startTime)) {
                startTimerBadge.textContent = 'Invalid Time';
            } else if (timeToStart > 0) {
                startTimerBadge.textContent = formatTime(timeToStart);
                startTimerBadge.className = 'timer-badge start-timer active';
            } else {
                startTimerBadge.textContent = 'Ready to Start';
                startTimerBadge.className = 'timer-badge start-timer ready';
            }
        } else if (status === 'running') {
            startTimerBadge.textContent = 'In Progress';
            startTimerBadge.className = 'timer-badge start-timer running';
        } else if (status === 'cancelled/on hold' || status === 'on hold') {
            startTimerBadge.textContent = 'On Hold';
            startTimerBadge.className = 'timer-badge start-timer on-hold';
        } else if (status === 'completed') {
            startTimerBadge.textContent = 'Completed';
            startTimerBadge.className = 'timer-badge start-timer finished';
        } else {
            startTimerBadge.textContent = 'N/A';
            startTimerBadge.className = 'timer-badge start-timer';
        }
    });
}

// Run timer interval when page loads
document.addEventListener('DOMContentLoaded', () => {
    setInterval(updateExamListTimers, 1000);
    updateExamListTimers();
});