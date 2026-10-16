/* WebRTC proctoring: peer-to-peer media, with short-lived PHP signaling only. */
(() => {
    const state = {
        app: null, role: '', examId: '', endpoint: '', iceServers: [], peerId: '', stream: null,
        running: false, stopped: false, sinceId: 0, pollTimer: null, heartbeatTimer: null,
        pollBusy: false, participants: [], callbacks: {}, teacherPeers: new Map(),
        studentPeer: null, teacherPeerId: '', pendingIce: new Map(),
        localPreview: null, studentGrid: null, teacherVideo: null, teacherStatus: null,
        enableAudioButton: null
    };

    function makePeerId() {
        return window.crypto?.randomUUID?.() || `peer-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    function init(callbacks = {}) {
        if (state.running) return;
        state.app = document.querySelector('[data-proctor-role]');
        if (!state.app) return;
        state.role = state.app.dataset.proctorRole;
        state.examId = state.app.dataset.examId || '';
        state.endpoint = state.app.dataset.signalUrl || '../exam-proctoring-signal.php';
        state.peerId = makePeerId();
        state.callbacks = callbacks;
        try { state.iceServers = JSON.parse(state.app.dataset.iceServers || '[]'); } catch (_) { state.iceServers = []; }
        if (!state.iceServers.length) state.iceServers = [{ urls: 'stun:stun.l.google.com:19302' }];
        state.localPreview = document.getElementById('teacherLocalPreview');
        state.studentGrid = document.getElementById('teacherStudentVideoGrid');
        state.teacherVideo = document.getElementById('teacherProctorVideo');
        state.teacherStatus = document.getElementById('teacherConnectionStatus');
        state.enableAudioButton = document.getElementById('enableTeacherAudioBtn');
        state.enableAudioButton?.addEventListener('click', () => {
            if (!state.teacherVideo) return;
            state.teacherVideo.muted = false;
            state.teacherVideo.volume = 1;
            state.teacherVideo.play().catch(() => {});
            state.enableAudioButton.textContent = 'Teacher audio enabled';
        });
        document.getElementById('enableStudentAudioBtn')?.addEventListener('click', () => {
            state.studentGrid?.querySelectorAll('video').forEach(video => {
                video.muted = false;
                video.volume = 1;
                video.play().catch(() => {});
            });
        });
        window.addEventListener('pagehide', stop);
        window.addEventListener('beforeunload', stop);
    }

    async function post(payload, keepalive = false) {
        const response = await fetch(state.endpoint, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive,
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!data.success) throw new Error(data.message || 'Proctoring server rejected the request.');
        return data;
    }

    async function sendSignal(recipientRole, recipientId, recipientPeerId, type, payload) {
        return post({
            action: 'send', exam_id: state.examId, role: state.role, peer_id: state.peerId,
            recipient_role: recipientRole, recipient_id: recipientId,
            recipient_peer_id: recipientPeerId, type, payload
        });
    }

    async function heartbeat() {
        if (!state.running || state.stopped) return;
        try {
            await post({ action: 'heartbeat', exam_id: state.examId, role: state.role, peer_id: state.peerId });
        } catch (error) {
            state.callbacks.onError?.(error.message);
        }
    }

    function connectionStates() {
        const entries = [...state.teacherPeers.entries()].map(([peerId, record]) => [peerId, {
            student_id: record.student.student_id, state: record.pc.connectionState || 'new'
        }]);
        return new Map(entries);
    }

    function renderTeacherParticipant(participant) {
        if (!state.studentGrid) return null;
        let tile = state.studentGrid.querySelector(`[data-proctor-peer="${CSS.escape(participant.peer_id)}"]`);
        if (tile) return tile;
        const empty = state.studentGrid.querySelector('.proctor-empty-state');
        empty?.remove();
        tile = document.createElement('article');
        tile.className = 'student-proctor-tile';
        tile.dataset.proctorPeer = participant.peer_id;
        const title = document.createElement('div');
        title.className = 'student-proctor-name';
        title.textContent = `${participant.student_name || 'Student'} (${participant.student_id})`;
        const status = document.createElement('span');
        status.className = 'student-proctor-state';
        status.textContent = 'Connecting…';
        const video = document.createElement('video');
        video.autoplay = true;
        video.playsInline = true;
        video.muted = false;
        video.dataset.proctorVideo = 'student';
        tile.append(title, status, video);
        state.studentGrid.append(tile);
        return tile;
    }

    function setTeacherStatus(peerId, text) {
        const tile = state.studentGrid?.querySelector(`[data-proctor-peer="${CSS.escape(peerId)}"]`);
        const status = tile?.querySelector('.student-proctor-state');
        if (status) status.textContent = text;
    }

    async function addCandidates(pc, peerId) {
        const candidates = state.pendingIce.get(peerId) || [];
        state.pendingIce.delete(peerId);
        for (const candidate of candidates) {
            try { await pc.addIceCandidate(new RTCIceCandidate(candidate)); } catch (error) { console.warn('Ignoring ICE candidate:', error); }
        }
    }

    async function queueOrAddCandidate(pc, peerId, candidate) {
        if (!candidate) return;
        if (!pc.remoteDescription) {
            const queue = state.pendingIce.get(peerId) || [];
            queue.push(candidate);
            state.pendingIce.set(peerId, queue);
            return;
        }
        try { await pc.addIceCandidate(new RTCIceCandidate(candidate)); } catch (error) { console.warn('Ignoring ICE candidate:', error); }
    }

    function attachRemoteTeacherTrack(event) {
        if (!state.teacherVideo) return;
        state.teacherVideo.srcObject = event.streams?.[0] || new MediaStream([event.track]);
        state.teacherVideo.muted = false;
        state.teacherVideo.play().catch(() => {});
        if (state.teacherStatus) state.teacherStatus.textContent = 'Teacher audio/video connected';
    }

    async function handleTeacherOffer(signal) {
        const teacherPeerId = signal.sender_peer_id;
        if (!teacherPeerId || !state.stream) return;
        if (state.studentPeer && state.teacherPeerId !== teacherPeerId) {
            state.studentPeer.close();
            state.studentPeer = null;
        }
        if (!state.studentPeer) {
            const pc = new RTCPeerConnection({ iceServers: state.iceServers });
            state.studentPeer = pc;
            state.teacherPeerId = teacherPeerId;
            state.stream.getTracks().forEach(track => pc.addTrack(track, state.stream));
            pc.ontrack = attachRemoteTeacherTrack;
            pc.onicecandidate = event => {
                if (event.candidate) sendSignal('teacher', '', teacherPeerId, 'ice', event.candidate.toJSON()).catch(() => {});
            };
            pc.onconnectionstatechange = () => {
                if (state.teacherStatus) state.teacherStatus.textContent = pc.connectionState === 'connected' ? 'Teacher audio/video connected' : `Teacher ${pc.connectionState}`;
            };
        }
        const pc = state.studentPeer;
        await pc.setRemoteDescription(new RTCSessionDescription(signal.payload));
        await addCandidates(pc, teacherPeerId);
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        await sendSignal('teacher', '', teacherPeerId, 'answer', pc.localDescription.toJSON());
    }

    async function handleTeacherAnswer(signal) {
        const record = state.teacherPeers.get(signal.sender_peer_id);
        if (!record || record.pc.remoteDescription) return;
        await record.pc.setRemoteDescription(new RTCSessionDescription(signal.payload));
        await addCandidates(record.pc, signal.sender_peer_id);
    }

    async function handleIce(signal) {
        if (state.role === 'student') {
            if (state.studentPeer && state.teacherPeerId === signal.sender_peer_id) {
                await queueOrAddCandidate(state.studentPeer, signal.sender_peer_id, signal.payload);
            } else {
                const queue = state.pendingIce.get(signal.sender_peer_id) || [];
                queue.push(signal.payload);
                state.pendingIce.set(signal.sender_peer_id, queue);
            }
            return;
        }
        const record = state.teacherPeers.get(signal.sender_peer_id);
        if (record) await queueOrAddCandidate(record.pc, signal.sender_peer_id, signal.payload);
    }

    async function createTeacherPeer(participant) {
        const peerId = participant.peer_id;
        const current = state.teacherPeers.get(peerId);
        if (current && !['failed', 'closed'].includes(current.pc.connectionState)) return;
        current?.pc.close();
        renderTeacherParticipant(participant);
        const pc = new RTCPeerConnection({ iceServers: state.iceServers });
        const record = { student: participant, pc };
        state.teacherPeers.set(peerId, record);
        state.stream.getTracks().forEach(track => pc.addTrack(track, state.stream));
        pc.ontrack = event => {
            const tile = renderTeacherParticipant(participant);
            const video = tile?.querySelector('video');
            if (video) {
                video.muted = false;
                video.srcObject = event.streams?.[0] || new MediaStream([event.track]);
                video.play().catch(() => {});
            }
        };
        pc.onicecandidate = event => {
            if (event.candidate) sendSignal('student', participant.student_id, peerId, 'ice', event.candidate.toJSON()).catch(() => {});
        };
        pc.onconnectionstatechange = () => {
            const stateText = pc.connectionState;
            setTeacherStatus(peerId, stateText === 'connected' ? 'Audio & video connected' : `Connection ${stateText}`);
            state.callbacks.onParticipants?.(state.participants, connectionStates());
        };
        try {
            const offer = await pc.createOffer();
            await pc.setLocalDescription(offer);
            await sendSignal('student', participant.student_id, peerId, 'offer', pc.localDescription.toJSON());
        } catch (error) {
            console.warn('Could not offer proctoring connection:', error);
            pc.close();
            state.teacherPeers.delete(peerId);
        }
    }

    async function handleSignal(signal) {
        try {
            if (signal.type === 'offer' && state.role === 'student') await handleTeacherOffer(signal);
            else if (signal.type === 'answer' && state.role === 'teacher') await handleTeacherAnswer(signal);
            else if (signal.type === 'ice') await handleIce(signal);
            else if (signal.type === 'leave' && state.role === 'teacher') {
                const record = state.teacherPeers.get(signal.sender_peer_id);
                record?.pc.close();
                state.teacherPeers.delete(signal.sender_peer_id);
            }
        } catch (error) {
            console.warn('Could not process proctoring signal:', error);
        }
    }

    async function poll() {
        if (!state.running || state.stopped || state.pollBusy) return;
        state.pollBusy = true;
        try {
            const url = new URL(state.endpoint, window.location.href);
            url.searchParams.set('action', 'poll');
            url.searchParams.set('exam_id', state.examId);
            url.searchParams.set('role', state.role);
            url.searchParams.set('peer_id', state.peerId);
            url.searchParams.set('since_id', String(state.sinceId));
            const response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!data.success) throw new Error(data.message || 'Proctoring connection failed.');
            for (const signal of data.signals || []) {
                state.sinceId = Math.max(state.sinceId, Number(signal.id) || 0);
                await handleSignal(signal);
            }
            if (state.role === 'teacher') {
                state.participants = data.participants || [];
                const livePeerIds = new Set(state.participants.map(item => item.peer_id));
                for (const [peerId, record] of state.teacherPeers) {
                    if (!livePeerIds.has(peerId)) {
                        record.pc.close();
                        state.teacherPeers.delete(peerId);
                        state.studentGrid?.querySelector(`[data-proctor-peer="${CSS.escape(peerId)}"]`)?.remove();
                    }
                }
                for (const participant of state.participants) {
                    renderTeacherParticipant(participant);
                    await createTeacherPeer(participant);
                }
                if (state.studentGrid && !state.participants.length && !state.studentGrid.querySelector('.proctor-empty-state')) {
                    const empty = document.createElement('p');
                    empty.className = 'proctor-empty-state';
                    empty.textContent = 'Student camera and microphone feeds appear here when students open the exam.';
                    state.studentGrid.append(empty);
                }
                state.callbacks.onParticipants?.(state.participants, connectionStates());
            }
        } catch (error) {
            state.callbacks.onError?.(error.message);
        } finally {
            state.pollBusy = false;
        }
    }

    async function start(stream = null) {
        if (!state.app) init();
        if (!state.app || !state.examId || !['student', 'teacher'].includes(state.role)) throw new Error('Proctoring page configuration is missing.');
        if (state.running) return;
        if (state.role === 'teacher' && !stream) {
            if (!navigator.mediaDevices?.getUserMedia) throw new Error('This browser does not support live camera and microphone access.');
            stream = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 640 }, height: { ideal: 480 } }, audio: true });
        }
        if (!stream || !stream.getVideoTracks().length || !stream.getAudioTracks().length) throw new Error('Allow both camera and microphone to start live proctoring.');
        state.stream = stream;
        state.stopped = false;
        state.running = true;
        if (state.role === 'teacher' && state.localPreview) {
            state.localPreview.srcObject = stream;
            state.localPreview.play().catch(() => {});
        }
        await heartbeat();
        await poll();
        state.heartbeatTimer = window.setInterval(heartbeat, 5000);
        state.pollTimer = window.setInterval(poll, 1100);
    }

    function stop() {
        if (!state.running || state.stopped) return;
        state.stopped = true;
        state.running = false;
        window.clearInterval(state.heartbeatTimer);
        window.clearInterval(state.pollTimer);
        const payload = JSON.stringify({ action: 'leave', exam_id: state.examId, role: state.role, peer_id: state.peerId });
        try {
            const blob = new Blob([payload], { type: 'application/json' });
            navigator.sendBeacon(state.endpoint, blob);
        } catch (_) {}
        for (const record of state.teacherPeers.values()) record.pc.close();
        state.teacherPeers.clear();
        state.studentPeer?.close();
        state.studentPeer = null;
        if (state.role === 'teacher') state.stream?.getTracks().forEach(track => track.stop());
    }

    window.ExamProctoring = { init, start, stop };
})();
