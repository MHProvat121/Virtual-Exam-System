(() => {
    const app = document.querySelector('.notification-app');
    const feed = document.getElementById('notificationFeed');
    if (!app || !feed) return;

    const role = app.dataset.role || 'student';
    const endpoint = app.dataset.feedUrl || 'notification-page.php?ajax=feed';
    const bootstrap = window.notificationBootstrap || {};
    let notifications = Array.isArray(bootstrap.notifications) ? bootstrap.notifications : [];
    let selectedTab = bootstrap.tab || 'all';
    let selectedAudience = 'all';
    let refreshBusy = false;

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);

    function relativeTime(isoValue) {
        const timestamp = Date.parse(isoValue);
        if (!Number.isFinite(timestamp)) return 'Recently';
        const difference = timestamp - Date.now();
        const absoluteSeconds = Math.abs(difference) / 1000;
        if (absoluteSeconds < 45) return difference > 0 ? 'in a moment' : 'Just now';
        const units = [
            ['year', 31536000], ['month', 2592000], ['week', 604800],
            ['day', 86400], ['hour', 3600], ['minute', 60]
        ];
        for (const [unit, seconds] of units) {
            const count = Math.floor(absoluteSeconds / seconds);
            if (count >= 1) {
                const value = `${count} ${unit}${count === 1 ? '' : 's'}`;
                return difference > 0 ? `In ${value}` : `${value} ago`;
            }
        }
        return 'Just now';
    }

    function getVisibleNotifications() {
        return notifications.filter(item => {
            if (role === 'admin' && selectedAudience !== 'all' && item.audience !== selectedAudience) return false;
            if (selectedTab === 'critical') return item.severity === 'red';
            if (selectedTab === 'academic') return item.category === 'academic';
            if (selectedTab === 'system') return item.category === 'system';
            return true;
        });
    }

    function updateCounts() {
        const eligible = notifications.filter(item => role !== 'admin' || selectedAudience === 'all' || item.audience === selectedAudience);
        const counts = {
            all: eligible.length,
            critical: eligible.filter(item => item.severity === 'red').length,
            academic: eligible.filter(item => item.category === 'academic').length,
            system: eligible.filter(item => item.category === 'system').length
        };
        Object.entries(counts).forEach(([key, count]) => {
            const target = document.querySelector(`[data-count="${key}"]`);
            if (target) target.textContent = String(count);
        });
    }

    function updateHeading() {
        const heading = document.getElementById('feedHeading');
        const description = document.getElementById('feedDescription');
        const labels = {
            all: ['All notifications', 'Your latest role-relevant exam and account activity.'],
            critical: ['Critical notifications', 'Urgent closures and red-alert items that need attention.'],
            academic: ['Academic notifications', 'Exam schedules, submissions, and evaluation updates.'],
            system: ['System logs', 'Administrative activity and system configuration events.']
        };
        const [title, copy] = labels[selectedTab] || labels.all;
        if (heading) heading.textContent = title;
        if (description) description.textContent = role === 'admin' && selectedAudience !== 'all'
            ? `${copy} Audience: ${selectedAudience}.`
            : copy;
    }

    function emptyCopy() {
        if (selectedTab === 'critical') return ['No critical alerts', 'There are no red-alert items for this audience right now.'];
        if (selectedTab === 'academic') return ['No academic updates', 'Exam and grading updates will appear here when they are available.'];
        if (selectedTab === 'system') return ['No system log entries', 'Relevant administrative records from the current schema will appear here.'];
        return ['You are all caught up', 'New exam, submission, and account updates will appear here.'];
    }

    function safeIcon(icon) {
        const className = String(icon || 'fa-bell');
        return /^fa-[a-z0-9-]+$/.test(className) ? className : 'fa-bell';
    }

    function safeActionUrl(value) {
        const url = String(value || '');
        if (url.startsWith('../') || url.startsWith('notification-page.php?')) return url;
        return 'notification-page.php';
    }

    function render() {
        updateCounts();
        updateHeading();
        const items = getVisibleNotifications();
        if (!items.length) {
            const [title, text] = emptyCopy();
            feed.innerHTML = `<div class="empty-feed"><span class="empty-feed-icon"><i class="fa-regular fa-bell-slash"></i></span><strong>${escapeHtml(title)}</strong><p>${escapeHtml(text)}</p></div>`;
            return;
        }

        feed.innerHTML = items.map(item => {
            const severity = ['red', 'yellow', 'green'].includes(item.severity) ? item.severity : 'yellow';
            const badge = severity === 'red' ? 'Critical' : severity === 'yellow' ? 'Action needed' : 'Update';
            const actionUrl = safeActionUrl(item.action_url);
            return `<article class="notification-card severity-${severity}" data-audience="${escapeHtml(item.audience)}" data-category="${escapeHtml(item.category)}">
                <div class="notification-indicator"><span class="indicator-icon"><i class="fa-solid ${safeIcon(item.icon)}"></i></span><span class="indicator-label">${badge}</span></div>
                <div class="notification-content">
                    <div class="notification-title-row"><h2>${escapeHtml(item.title)}</h2><time datetime="${escapeHtml(item.timestamp)}">${escapeHtml(relativeTime(item.timestamp))}</time></div>
                    <p>${escapeHtml(item.body)}</p>
                    ${role === 'admin' ? `<span class="audience-tag">${escapeHtml(item.audience)} audience</span>` : ''}
                </div>
                <div class="notification-action"><a href="${escapeHtml(actionUrl)}" class="action-button">${escapeHtml(item.action_text || 'Open')} <i class="fa-solid fa-arrow-right"></i></a></div>
            </article>`;
        }).join('');
    }

    function setTab(tab, updateUrl = true) {
        if (!document.querySelector(`[data-tab="${tab}"]`)) tab = 'all';
        selectedTab = tab;
        document.querySelectorAll('.notification-tab').forEach(button => {
            const active = button.dataset.tab === selectedTab;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', selectedTab);
            window.history.replaceState({}, '', url);
        }
        render();
    }

    document.querySelectorAll('.notification-tab').forEach(button => {
        button.addEventListener('click', () => setTab(button.dataset.tab || 'all'));
    });
    document.querySelectorAll('.audience-button').forEach(button => {
        button.addEventListener('click', () => {
            selectedAudience = button.dataset.audienceFilter || 'all';
            document.querySelectorAll('.audience-button').forEach(option => option.classList.toggle('active', option === button));
            render();
        });
    });

    async function refreshFeed() {
        if (refreshBusy || document.hidden) return;
        refreshBusy = true;
        try {
            const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`Notification refresh failed (${response.status})`);
            const payload = await response.json();
            if (payload.role !== role || !Array.isArray(payload.notifications)) throw new Error('Notification role changed; reload this page.');
            notifications = payload.notifications;
            render();
            const updated = document.getElementById('lastUpdated');
            if (updated) updated.textContent = `Last checked ${new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true }).format(new Date())} Asia/Dhaka`;
        } catch (error) {
            console.warn('Notification feed refresh:', error);
            const updated = document.getElementById('lastUpdated');
            if (updated) updated.textContent = 'Could not refresh; showing the latest loaded updates.';
        } finally {
            refreshBusy = false;
        }
    }

    render();
    const updated = document.getElementById('lastUpdated');
    if (updated) updated.textContent = `Loaded ${notifications.length} updates · ${new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Dhaka', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true }).format(new Date())} Asia/Dhaka`;
    window.setInterval(refreshFeed, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshFeed(); });
})();
