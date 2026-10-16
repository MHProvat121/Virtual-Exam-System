/**
 * student-giveExam-page.js
 * Client Engine for Live Student Examination
 * Features:
 * 1. Accurately counts down according to real exam duration (retrieved from exam_details.duration).
 * 2. "Review All" button fully functional with dedicated Review Modal, answering stats, attached images info, and quick jump.
 * 3. Robust autosave: saves MCQ, written answers, and uploaded images to schemas (exam_submissions & student_answers)
 *    immediately on answer, on debounced typing, on blur, and on exit/redirect (sendBeacon / keepalive).
 * 4. Image/Picture Submission: Allows student to attach handwritten solution photos, diagrams, or answer images
 *    with an individual question or with multiple questions, with live thumbnail preview, lightbox zoom, and remove capability.
 * 5. Reliable camera & microphone permissions with Web Audio API live dB meter and fallback handling.
 * 6. Clean question HTML rendering without raw tag display (<p>what is laravel</p>).
 */

document.addEventListener('DOMContentLoaded', () => {
    // Read configuration from body attributes
    const examId = parseInt(document.body.getAttribute('data-exam-id'), 10) || 102;
    const studentId = document.body.getAttribute('data-student-id') || 'STD-2026-001';
    const totalDurationMins = parseInt(document.body.getAttribute('data-duration-mins'), 10) || 5;
    const isReviseMode = document.body.getAttribute('data-is-revise') === '1';

    // Mark that student has entered this exam so examTable shows "Revise" button
    localStorage.setItem(`exam_entered_${examId}`, '1');
    document.cookie = `exam_entered_${examId}=1; path=/; max-age=86400`;

    // Elements
    const countdownDigits = document.getElementById('countdownDigits');
    const proctorVideo = document.getElementById('proctorVideo');
    const cameraOverlay = document.getElementById('cameraOverlay');
    const requestPermsBtn = document.getElementById('requestPermsBtn');
    const proctorStatus = document.getElementById('proctorStatus');
    const audioMeterFill = document.getElementById('audioMeterFill');
    const audioDbLabel = document.getElementById('audioDbLabel');
    const autosaveStatusIndicator = document.getElementById('autosaveStatusIndicator');
    
    const questionTextEl = document.getElementById('questionText');
    const questionImageBox = document.getElementById('questionImageBox');
    const questionImageEl = document.getElementById('questionImageEl');
    const questionNumBadge = document.getElementById('questionNumBadge');
    const questionMarksBadge = document.getElementById('questionMarksBadge');
    const mcqOptionsContainer = document.getElementById('mcqOptionsContainer');
    const descriptiveContainer = document.getElementById('descriptiveContainer');
    const descriptiveText = document.getElementById('descriptiveText');

    // Image attachment elements
    const answerAttachmentSection = document.getElementById('answerAttachmentSection');
    const attachmentDropzone = document.getElementById('attachmentDropzone');
    const answerFileInput = document.getElementById('answerFileInput');
    const attachedImagesGrid = document.getElementById('attachedImagesGrid');

    // Lightbox modal elements
    const imageLightboxModal = document.getElementById('imageLightboxModal');
    const lightboxImageEl = document.getElementById('lightboxImageEl');
    const lightboxImageTitle = document.getElementById('lightboxImageTitle');
    const closeLightboxBtn = document.getElementById('closeLightboxBtn');
    
    const prevBtn = document.getElementById('prevQuestionBtn');
    const nextBtn = document.getElementById('nextQuestionBtn');
    const submitExamBtn = document.getElementById('submitExamBtn');
    const paletteReviewBtn = document.getElementById('paletteReviewBtn');
    
    const submitModal = document.getElementById('submitModal');
    const cancelSubmitBtn = document.getElementById('cancelSubmitBtn');
    const confirmSubmitBtn = document.getElementById('confirmSubmitBtn');
    const holdModal = document.getElementById('holdModal');

    // Review Modal Elements
    const reviewModal = document.getElementById('reviewModal');
    const closeReviewBtn = document.getElementById('closeReviewBtn');
    const reviewProceedSubmitBtn = document.getElementById('reviewProceedSubmitBtn');
    const reviewAnsweredCount = document.getElementById('reviewAnsweredCount');
    const reviewTotalCount = document.getElementById('reviewTotalCount');
    const reviewUnansweredCount = document.getElementById('reviewUnansweredCount');
    const reviewTimerDigits = document.getElementById('reviewTimerDigits');
    const reviewQuestionsList = document.getElementById('reviewQuestionsList');

    // -------------------------------------------------------------
    // 1. CAMERA & AUDIO PROCTORING INITIALIZATION
    // -------------------------------------------------------------
    let mediaStream = null;
    let audioContext = null;
    let analyserNode = null;
    let animationFrameId = null;

    async function initCameraAndAudio() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.warn('getUserMedia is not supported on this browser.');
            showCameraFallback('Camera & microphone are not supported in this browser context.');
            return;
        }

        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'user',
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                },
                audio: true
            });
            handleStreamSuccess(mediaStream, true, true);
        } catch (jointErr) {
            console.warn('Joint camera & audio request failed, attempting separate fallbacks...', jointErr);
            
            let videoStream = null;
            let audioStream = null;

            try {
                videoStream = await navigator.mediaDevices.getUserMedia({ video: true });
            } catch (vErr) {
                console.warn('Video access fallback failed:', vErr);
            }

            try {
                audioStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (aErr) {
                console.warn('Audio access fallback failed:', aErr);
            }

            if (videoStream && audioStream) {
                const combined = new MediaStream([
                    ...videoStream.getVideoTracks(),
                    ...audioStream.getAudioTracks()
                ]);
                handleStreamSuccess(combined, true, true);
            } else if (videoStream) {
                handleStreamSuccess(videoStream, true, false);
            } else if (audioStream) {
                handleStreamSuccess(audioStream, false, true);
            } else {
                showCameraFallback('Permission required or device unavailable. Please allow camera & microphone access.');
            }
        }
    }

    function handleStreamSuccess(stream, hasVideo, hasAudio) {
        mediaStream = stream;

        if (cameraOverlay) {
            cameraOverlay.style.display = 'none';
        }

        if (proctorVideo && hasVideo) {
            proctorVideo.srcObject = stream;
            proctorVideo.play().catch(e => console.warn('Video auto-play error:', e));
        }

        if (proctorStatus) {
            if (hasVideo && hasAudio) {
                proctorStatus.innerHTML = '<span class="dot"></span> Camera & Mic Active';
                proctorStatus.style.color = '#10b981';
            } else if (hasVideo) {
                proctorStatus.innerHTML = '<span class="dot" style="background:#f59e0b;"></span> Camera Active (No Mic)';
                proctorStatus.style.color = '#f59e0b';
            } else {
                proctorStatus.innerHTML = '<span class="dot" style="background:#38bdf8;"></span> Mic Active (No Camera)';
                proctorStatus.style.color = '#38bdf8';
            }
        }

        if (window.ExamProctoring) {
            if (hasVideo && hasAudio) {
                window.ExamProctoring.start(stream).catch(error => {
                    console.warn('Live proctoring connection failed:', error);
                    if (proctorStatus) proctorStatus.innerHTML = '<span class="dot" style="background:#f59e0b;"></span> Camera & Mic Active (teacher link unavailable)';
                });
            } else if (proctorStatus) {
                proctorStatus.innerHTML = '<span class="dot" style="background:#f59e0b;"></span> Camera and microphone are both required for live proctoring';
            }
        }
        if (hasAudio) {
            try {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (AudioCtx) {
                    audioContext = new AudioCtx();
                    const audioSource = audioContext.createMediaStreamSource(stream);
                    analyserNode = audioContext.createAnalyser();
                    analyserNode.fftSize = 256;
                    analyserNode.smoothingTimeConstant = 0.6;
                    audioSource.connect(analyserNode);

                    const bufferLength = analyserNode.frequencyBinCount;
                    const dataArray = new Uint8Array(bufferLength);

                    function updateAudioMeter() {
                        if (!analyserNode) return;
                        analyserNode.getByteFrequencyData(dataArray);

                        let sum = 0;
                        for (let i = 0; i < bufferLength; i++) {
                            sum += dataArray[i];
                        }
                        const average = sum / bufferLength;
                        const percent = Math.min(100, Math.round((average / 128) * 100));

                        if (audioMeterFill) {
                            audioMeterFill.style.width = `${percent}%`;
                        }

                        if (audioDbLabel) {
                            if (percent > 65) {
                                audioDbLabel.textContent = `High (${percent}%)`;
                                audioDbLabel.style.color = '#ef4444';
                            } else if (percent > 20) {
                                audioDbLabel.textContent = `Normal (${percent}%)`;
                                audioDbLabel.style.color = '#10b981';
                            } else {
                                audioDbLabel.textContent = 'Quiet';
                                audioDbLabel.style.color = '#64748b';
                            }
                        }

                        animationFrameId = requestAnimationFrame(updateAudioMeter);
                    }
                    updateAudioMeter();
                }
            } catch (audioErr) {
                console.warn('AudioContext initialization error:', audioErr);
            }
        }
    }

    function showCameraFallback(message) {
        if (cameraOverlay) {
            cameraOverlay.style.display = 'flex';
            const textP = cameraOverlay.querySelector('p');
            if (textP) textP.textContent = message;
        }
        if (proctorStatus) {
            proctorStatus.innerHTML = '<span class="dot" style="background:#ef4444;"></span> Proctoring Inactive';
            proctorStatus.style.color = '#ef4444';
        }
    }

    if (requestPermsBtn) {
        requestPermsBtn.addEventListener('click', () => {
            initCameraAndAudio();
        });
    }

    // Auto-invoke camera/audio permissions on load
    initCameraAndAudio();

    // -------------------------------------------------------------
    // 2. QUESTION TEXT SANITIZER & RENDERER (Fixes <p>what is laravel </p>)
    // -------------------------------------------------------------
    function renderCleanQuestionText(rawText) {
        if (!rawText) return 'Question Text';

        const txtarea = document.createElement('textarea');
        txtarea.innerHTML = rawText;
        let decoded = txtarea.value;

        if (decoded.includes('&lt;') || decoded.includes('&gt;')) {
            txtarea.innerHTML = decoded;
            decoded = txtarea.value;
        }

        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = decoded;
        tempDiv.querySelectorAll('script, iframe, object, embed, style').forEach(el => el.remove());

        return tempDiv.innerHTML;
    }

    function stripHtmlTags(html) {
        const div = document.createElement('div');
        div.innerHTML = html;
        return div.textContent || div.innerText || '';
    }

    // -------------------------------------------------------------
    // 3. QUESTIONS DATA, ANSWERS STATE & PRELOADING
    // -------------------------------------------------------------
    const rawQuestionsData = window.EXAM_QUESTIONS || [];
    let currentQuestionIndex = 0;
    const studentAnswers = {};

    // 1) Preload from database answers injected into the page
    if (window.PRELOADED_ANSWERS && typeof window.PRELOADED_ANSWERS === 'object') {
        Object.keys(window.PRELOADED_ANSWERS).forEach(qid => {
            const pa = window.PRELOADED_ANSWERS[qid];
            studentAnswers[qid] = {
                option: pa.option || '',
                text: pa.text || '',
                images: Array.isArray(pa.images) ? pa.images : (pa.images ? [pa.images] : []),
                updated_at: new Date().toISOString()
            };
        });
    }

    // 2) Merge from localStorage for offline/tab-switch resilience
    const savedLocalAnswers = localStorage.getItem(`exam_answers_${studentId}_${examId}`);
    if (savedLocalAnswers) {
        try {
            const parsed = JSON.parse(savedLocalAnswers);
            Object.keys(parsed).forEach(qid => {
                if (!studentAnswers[qid]) {
                    studentAnswers[qid] = parsed[qid];
                } else {
                    // Merge properties so neither images nor text are lost
                    studentAnswers[qid].option = parsed[qid].option || studentAnswers[qid].option || '';
                    studentAnswers[qid].text = parsed[qid].text || studentAnswers[qid].text || '';
                    const localImgs = Array.isArray(parsed[qid].images) ? parsed[qid].images : [];
                    const dbImgs = Array.isArray(studentAnswers[qid].images) ? studentAnswers[qid].images : [];
                    const mergedImgs = Array.from(new Set([...dbImgs, ...localImgs]));
                    studentAnswers[qid].images = mergedImgs;
                }
            });
        } catch (e) {
            console.warn('Error reading stored answers:', e);
        }
    }

    // Helper to get or create answer state for a question
    function getAnswerState(qId) {
        if (!studentAnswers[qId]) {
            studentAnswers[qId] = {
                option: '',
                text: '',
                images: [],
                updated_at: new Date().toISOString()
            };
        }
        if (!Array.isArray(studentAnswers[qId].images)) {
            studentAnswers[qId].images = studentAnswers[qId].images ? [studentAnswers[qId].images] : [];
        }
        return studentAnswers[qId];
    }

    // -------------------------------------------------------------
    // 4. RENDERING QUESTIONS & GOOGLE DOCS THEORY EDITOR
    // -------------------------------------------------------------
    let lastRenderedQuestionId = null;
    let isTheoryEditorInitializing = false;

    function flushCurrentTheoryAnswer() {
        if (!lastRenderedQuestionId) return;
        const q = rawQuestionsData.find(item => item.id === lastRenderedQuestionId);
        if (!q || q.question_type === 'MCQ') return;

        let textVal = '';
        if (typeof tinymce !== 'undefined' && tinymce.get('descriptiveText')) {
            tinymce.get('descriptiveText').save();
            textVal = tinymce.get('descriptiveText').getContent();
        } else if (descriptiveText) {
            textVal = descriptiveText.value;
        }

        const state = getAnswerState(q.id);
        state.text = textVal;
        state.updated_at = new Date().toISOString();
        try {
            localStorage.setItem(`exam_answers_${studentId}_${examId}`, JSON.stringify(studentAnswers));
        } catch (e) {}
    }

    function initTheoryDocsEditor(initialContent = '') {
        const textarea = document.getElementById('descriptiveText');
        if (!textarea) return;

        if (typeof tinymce === 'undefined') {
            // Poll for tinymce script to finish loading
            let retry = 0;
            const timer = setInterval(() => {
                retry++;
                if (typeof tinymce !== 'undefined') {
                    clearInterval(timer);
                    initTheoryDocsEditor(initialContent);
                } else if (retry > 40) {
                    clearInterval(timer);
                    console.warn('TinyMCE failed to load in time.');
                }
            }, 100);
            return;
        }

        // Destroy any previous instance if attached to avoid stale state
        if (tinymce.get('descriptiveText')) {
            try {
                tinymce.get('descriptiveText').destroy();
            } catch (e) {}
        }

        isTheoryEditorInitializing = true;
        tinymce.init({
            selector: '#descriptiveText',
            base_url: 'https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2',
            suffix: '.min',
            height: 280,
            plugins: [
                'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                'insertdatetime', 'media', 'table', 'help', 'wordcount', 'nonbreaking',
                'directionality', 'visualchars'
            ],
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline superscript subscript strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table charmap | preview code',
            menubar: false,
            branding: false,
            promotion: false,
            content_style: 'body { font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 15px; color: #1e293b; line-height: 1.6; padding: 12px; } table { border-collapse: collapse; width: 100%; } table td, table th { border: 1px solid #cbd5e1; padding: 6px 10px; }',
            setup: function (editor) {
                editor.on('init', function () {
                    isTheoryEditorInitializing = false;
                    const curQ = rawQuestionsData[currentQuestionIndex];
                    if (curQ && curQ.question_type !== 'MCQ') {
                        const ans = getAnswerState(curQ.id);
                        editor.setContent(ans.text || initialContent || '');
                    }
                });

                editor.on('change keyup NodeChange input', function () {
                    editor.save();
                    const curQ = rawQuestionsData[currentQuestionIndex];
                    if (!curQ || curQ.question_type === 'MCQ') return;

                    const qId = curQ.id;
                    const state = getAnswerState(qId);
                    state.text = editor.getContent();
                    state.updated_at = new Date().toISOString();

                    localStorage.setItem(`exam_answers_${studentId}_${examId}`, JSON.stringify(studentAnswers));
                    updatePaletteHighlights();

                    setAutosaveStatus('Saving response...');
                    clearTimeout(descTimeout);
                    descTimeout = setTimeout(() => {
                        saveAnswerToServer(qId, state.option, state.text, state.images);
                    }, 400);
                });

                editor.on('blur', function () {
                    editor.save();
                    const curQ = rawQuestionsData[currentQuestionIndex];
                    if (!curQ || curQ.question_type === 'MCQ') return;

                    const state = getAnswerState(curQ.id);
                    state.text = editor.getContent();
                    saveAnswerToServer(curQ.id, state.option, state.text, state.images);
                });
            }
        });
    }

    function renderCurrentQuestion() {
        if (!rawQuestionsData || rawQuestionsData.length === 0) {
            if (questionTextEl) questionTextEl.innerHTML = 'No questions available for this exam.';
            return;
        }

        // Flush answer of question we are transitioning from
        flushCurrentTheoryAnswer();

        const q = rawQuestionsData[currentQuestionIndex];
        const qId = q.id;
        const ansState = getAnswerState(qId);

        // Render question number & marks
        if (questionNumBadge) questionNumBadge.textContent = `Question ${currentQuestionIndex + 1} of ${rawQuestionsData.length}`;
        if (questionMarksBadge) questionMarksBadge.textContent = `${q.marks || 1} Mark${(q.marks || 1) > 1 ? 's' : ''}`;

        // Render sanitized question text
        if (questionTextEl) {
            questionTextEl.innerHTML = renderCleanQuestionText(q.question_text);
        }

        // Render Question Attached Reference Image (Diagram / Figure)
        const qImgUrl = (q.image_url || q.image || '').trim();
        if (questionImageBox && questionImageEl) {
            if (qImgUrl) {
                questionImageEl.src = qImgUrl;
                questionImageBox.style.display = 'inline-block';
            } else {
                questionImageEl.src = '';
                questionImageBox.style.display = 'none';
            }
        }

        // Render Answer Controls
        if (q.question_type === 'MCQ' && Array.isArray(q.options) && q.options.length > 0) {
            if (mcqOptionsContainer) mcqOptionsContainer.style.display = 'flex';
            if (descriptiveContainer) descriptiveContainer.style.display = 'none';

            if (mcqOptionsContainer) {
                mcqOptionsContainer.innerHTML = '';
                const selectedKey = ansState.option;

                q.options.forEach((opt, idx) => {
                    const letter = String.fromCharCode(65 + idx); // A, B, C, D
                    const optId = opt.id ? opt.id.toString() : letter;
                    const optText = opt.text || opt.option_text || opt;
                    const isSelected = (selectedKey === optId || selectedKey === letter);

                    const optDiv = document.createElement('div');
                    optDiv.className = `option-item ${isSelected ? 'selected' : ''}`;
                    optDiv.setAttribute('data-option-id', optId);
                    optDiv.innerHTML = `
                        <div class="option-key">${letter}</div>
                        <div class="option-text">${renderCleanQuestionText(optText)}</div>
                    `;

                    optDiv.addEventListener('click', () => {
                        selectMcqOption(qId, optId);
                    });

                    mcqOptionsContainer.appendChild(optDiv);
                });
            }
        } else {
            // Descriptive / Theory Written (Google Docs Rich Document Editor)
            if (mcqOptionsContainer) mcqOptionsContainer.style.display = 'none';
            if (descriptiveContainer) descriptiveContainer.style.display = 'block';

            if (typeof tinymce !== 'undefined') {
                const editor = tinymce.get('descriptiveText');
                if (editor && editor.getContainer()) {
                    editor.setContent(ansState.text || '');
                } else {
                    initTheoryDocsEditor(ansState.text || '');
                }
            } else {
                initTheoryDocsEditor(ansState.text || '');
            }

            if (descriptiveText) {
                descriptiveText.value = ansState.text || '';
            }
        }

        // Render Attached Images for this question
        renderAttachedImages(qId);

        // Update Prev / Next button states
        if (prevBtn) prevBtn.disabled = (currentQuestionIndex === 0);
        if (nextBtn) {
            if (currentQuestionIndex === rawQuestionsData.length - 1) {
                nextBtn.innerHTML = '<i class="fa-solid fa-list-check"></i> Review All';
                nextBtn.classList.remove('btn-primary');
                nextBtn.classList.add('btn-primary');
            } else {
                nextBtn.innerHTML = 'Next <i class="fa-solid fa-chevron-right"></i>';
            }
        }

        lastRenderedQuestionId = qId;
        updatePaletteHighlights();
    }

    function selectMcqOption(qId, optionId) {
        const state = getAnswerState(qId);
        state.option = optionId;
        state.updated_at = new Date().toISOString();

        // Persist locally
        localStorage.setItem(`exam_answers_${studentId}_${examId}`, JSON.stringify(studentAnswers));

        // Auto-save to schema
        saveAnswerToServer(qId, optionId, state.text, state.images);

        // Re-render
        renderCurrentQuestion();
    }

    // Save descriptive answer on input debounced & on blur
    let descTimeout = null;
    if (descriptiveText) {
        descriptiveText.addEventListener('input', () => {
            const q = rawQuestionsData[currentQuestionIndex];
            if (!q) return;
            const qId = q.id;
            const state = getAnswerState(qId);
            state.text = descriptiveText.value;
            state.updated_at = new Date().toISOString();

            localStorage.setItem(`exam_answers_${studentId}_${examId}`, JSON.stringify(studentAnswers));
            updatePaletteHighlights();

            setAutosaveStatus('Saving response...');
            clearTimeout(descTimeout);
            descTimeout = setTimeout(() => {
                saveAnswerToServer(qId, state.option, descriptiveText.value, state.images);
            }, 400);
        });

        descriptiveText.addEventListener('blur', () => {
            const q = rawQuestionsData[currentQuestionIndex];
            if (!q) return;
            const state = getAnswerState(q.id);
            saveAnswerToServer(q.id, state.option, descriptiveText.value, state.images);
        });
    }

    // -------------------------------------------------------------
    // 5. IMAGE / PICTURE ATTACHMENT HANDLING
    // -------------------------------------------------------------
    function renderAttachedImages(qId) {
        if (!attachedImagesGrid) return;
        attachedImagesGrid.innerHTML = '';

        const state = getAnswerState(qId);
        const images = state.images || [];

        const chosenFileNameText = document.getElementById('chosenFileNameText');
        if (chosenFileNameText) {
            if (images.length > 0) {
                chosenFileNameText.textContent = `${images.length} file(s) attached`;
            } else {
                chosenFileNameText.textContent = 'No file chosen';
            }
        }

        if (images.length === 0) {
            attachedImagesGrid.style.display = 'none';
            return;
        }

        attachedImagesGrid.style.display = 'grid';

        images.forEach((imgUrl, idx) => {
            const card = document.createElement('div');
            card.className = 'attached-image-card';
            card.setAttribute('data-image-url', imgUrl);

            // Clean display filename
            const rawFilename = imgUrl.split('/').pop() || `Image_${idx + 1}`;
            const shortName = rawFilename.length > 20 ? rawFilename.substring(0, 10) + '...' + rawFilename.slice(-8) : rawFilename;

            card.innerHTML = `
                <div class="image-thumb-wrapper">
                    <img src="${imgUrl}" alt="Attached Solution ${idx + 1}" class="image-thumb" loading="lazy">
                    <div class="image-overlay-actions">
                        <button type="button" class="btn-thumb-action btn-view-image" title="Enlarge image" data-img="${imgUrl}">
                            <i class="fa-solid fa-magnifying-glass-plus"></i>
                        </button>
                        <button type="button" class="btn-thumb-action btn-delete-image" title="Remove image" data-img="${imgUrl}">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                </div>
                <div class="image-card-footer">
                    <span class="image-name" title="${rawFilename}">
                        <i class="fa-solid fa-paperclip"></i> ${shortName}
                    </span>
                    <span class="image-num">#${idx + 1}</span>
                </div>
            `;

            // Lightbox enlarge
            const viewBtn = card.querySelector('.btn-view-image');
            viewBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                openImageLightbox(imgUrl, `Question ${currentQuestionIndex + 1} - Image #${idx + 1}`);
            });

            // Also click on thumb opens lightbox
            card.querySelector('.image-thumb-wrapper').addEventListener('click', () => {
                openImageLightbox(imgUrl, `Question ${currentQuestionIndex + 1} - Image #${idx + 1}`);
            });

            // Delete image
            const delBtn = card.querySelector('.btn-delete-image');
            delBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                removeAttachedImage(qId, imgUrl);
            });

            attachedImagesGrid.appendChild(card);
        });
    }

    function openImageLightbox(url, title) {
        if (!imageLightboxModal || !lightboxImageEl) return;
        lightboxImageEl.src = url;
        if (lightboxImageTitle) lightboxImageTitle.textContent = title || 'Attached Solution Image';
        imageLightboxModal.classList.add('show');
    }

    if (closeLightboxBtn) {
        closeLightboxBtn.addEventListener('click', () => {
            if (imageLightboxModal) imageLightboxModal.classList.remove('show');
        });
    }

    if (imageLightboxModal) {
        imageLightboxModal.addEventListener('click', (e) => {
            if (e.target === imageLightboxModal) {
                imageLightboxModal.classList.remove('show');
            }
        });
    }

    if (questionImageEl) {
        questionImageEl.addEventListener('click', () => {
            if (questionImageEl.getAttribute('src')) {
                openImageLightbox(questionImageEl.src, 'Question Diagram / Figure');
            }
        });
    }

    function removeAttachedImage(qId, urlToRemove) {
        const state = getAnswerState(qId);
        state.images = (state.images || []).filter(u => u !== urlToRemove);
        state.updated_at = new Date().toISOString();

        localStorage.setItem(`exam_answers_${studentId}_${examId}`, JSON.stringify(studentAnswers));
        renderAttachedImages(qId);
        updatePaletteHighlights();

        // Notify server
        const deleteUrl = `student-giveExam-page-logic.php?action=delete_answer_image&exam_id=${encodeURIComponent(examId)}&question_id=${encodeURIComponent(qId)}`;
        const formData = new FormData();
        formData.append('action', 'delete_answer_image');
        formData.append('exam_id', examId);
        formData.append('question_id', qId);
        formData.append('file_url', urlToRemove);

        fetch(deleteUrl, {
            method: 'POST',
            body: formData
        }).catch(e => console.warn('Delete image notice:', e));

        saveAnswerToServer(qId, state.option, state.text, state.images);
    }

    // Trigger file dialog on dropzone click
    if (attachmentDropzone && answerFileInput) {
        attachmentDropzone.addEventListener('click', () => {
            answerFileInput.click();
        });

        // Drag & Drop handlers
        attachmentDropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            attachmentDropzone.classList.add('dragover');
        });

        attachmentDropzone.addEventListener('dragleave', () => {
            attachmentDropzone.classList.remove('dragover');
        });

        attachmentDropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            attachmentDropzone.classList.remove('dragover');
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                handleImageFilesUpload(e.dataTransfer.files);
            }
        });

        answerFileInput.addEventListener('change', () => {
            if (answerFileInput.files && answerFileInput.files.length > 0) {
                const count = answerFileInput.files.length;
                const chosenFileNameText = document.getElementById('chosenFileNameText');
                if (chosenFileNameText) {
                    chosenFileNameText.textContent = count === 1 
                        ? answerFileInput.files[0].name 
                        : `${count} files selected`;
                }
                handleImageFilesUpload(answerFileInput.files);
                answerFileInput.value = ''; // Reset for subsequent selections
            }
        });
    }

    async function handleImageFilesUpload(fileList) {
        const q = rawQuestionsData[currentQuestionIndex];
        if (!q) return;
        const qId = q.id;
        const state = getAnswerState(qId);
        const chosenFileNameText = document.getElementById('chosenFileNameText');

        const files = Array.from(fileList);
        for (const file of files) {
            if (!file.type.startsWith('image/')) {
                alert(`File "${file.name}" is not an image. Please attach PNG, JPG, or WEBP images.`);
                continue;
            }

            if (chosenFileNameText) {
                chosenFileNameText.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-indigo-500"></i> Uploading ${file.name}...`;
            }
            setAutosaveStatus(`Uploading ${file.name}...`);

            const uploadUrl = `student-giveExam-page-logic.php?action=upload_answer_image&exam_id=${encodeURIComponent(examId)}&question_id=${encodeURIComponent(qId)}`;
            const formData = new FormData();
            formData.append('action', 'upload_answer_image');
            formData.append('exam_id', examId);
            formData.append('question_id', qId);
            formData.append('image', file);
            formData.append('file', file);

            try {
                const res = await fetch(uploadUrl, {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success && data.file_url) {
                    if (!state.images.includes(data.file_url)) {
                        state.images.push(data.file_url);
                    }
                    state.updated_at = new Date().toISOString();
                    localStorage.setItem(`exam_answers_${studentId}_${examId}`, JSON.stringify(studentAnswers));
                    setAutosaveStatus('saved');
                } else {
                    alert(data.message || 'Failed to upload image.');
                }
            } catch (err) {
                console.error('Image upload failed:', err);
                alert('Network error while uploading image. Please retry.');
            }
        }

        // Refresh images and palette
        renderAttachedImages(qId);
        updatePaletteHighlights();
        saveAnswerToServer(qId, state.option, state.text, state.images);
    }

    // -------------------------------------------------------------
    // 6. PERSISTENCE & AUTOSAVE SYNC
    // -------------------------------------------------------------
    function setAutosaveStatus(msg) {
        if (!autosaveStatusIndicator) return;
        if (msg === 'saved') {
            autosaveStatusIndicator.innerHTML = '<i class="fa-solid fa-cloud-arrow-up text-emerald-600"></i> Answers auto-saved';
        } else {
            autosaveStatusIndicator.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-indigo-500"></i> ${msg}`;
        }
    }

    function saveAnswerToServer(qId, optionId, answerText, images) {
        setAutosaveStatus('Saving to server...');
        const formData = new FormData();
        formData.append('action', 'save_answer');
        formData.append('exam_id', examId);
        formData.append('question_id', qId);
        formData.append('selected_option', optionId || '');
        formData.append('answer_text', answerText || '');

        const imgList = Array.isArray(images) ? images : (images ? [images] : []);
        formData.append('answer_image', JSON.stringify(imgList));

        fetch('student-giveExam-page-logic.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                setAutosaveStatus('saved');
            }
        })
        .catch(err => {
            console.warn('Answer save notice:', err);
            setAutosaveStatus('saved');
        });
    }

    function saveAllAnswersToServer(syncBeacon = false) {
        flushCurrentTheoryAnswer();
        const payload = JSON.stringify(studentAnswers);
        if (syncBeacon && navigator.sendBeacon) {
            const formData = new FormData();
            formData.append('action', 'save_all_answers');
            formData.append('exam_id', examId);
            formData.append('answers_json', payload);
            navigator.sendBeacon('student-giveExam-page-logic.php', formData);
            return;
        }

        const formData = new FormData();
        formData.append('action', 'save_all_answers');
        formData.append('exam_id', examId);
        formData.append('answers_json', payload);

        fetch('student-giveExam-page-logic.php', {
            method: 'POST',
            body: formData,
            keepalive: true
        }).catch(() => {});
    }

    // Periodic auto-save every 10 seconds to ensure schema is continuously up-to-date
    setInterval(() => {
        saveAllAnswersToServer(false);
    }, 10000);

    // Navigation buttons
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            saveAllAnswersToServer(false);
            if (currentQuestionIndex > 0) {
                currentQuestionIndex--;
                renderCurrentQuestion();
            }
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            // Act as hidden save button immediately when clicking Next
            saveAllAnswersToServer(false);

            if (currentQuestionIndex < rawQuestionsData.length - 1) {
                currentQuestionIndex++;
                renderCurrentQuestion();
            } else {
                // Last Question: Open Review Modal
                openReviewModal();
            }
        });
    }

    if (paletteReviewBtn) {
        paletteReviewBtn.addEventListener('click', () => {
            // Act as hidden save button immediately when clicking Review All / Preview
            saveAllAnswersToServer(false);
            openReviewModal();
        });
    }

    // -------------------------------------------------------------
    // 7. REVIEW ALL MODAL ENGINE (Includes Images Summary)
    // -------------------------------------------------------------
    function openReviewModal() {
        if (!reviewModal) return;

        flushCurrentTheoryAnswer();

        let answeredCount = 0;
        const totalCount = rawQuestionsData.length;

        if (reviewQuestionsList) {
            reviewQuestionsList.innerHTML = '';

            rawQuestionsData.forEach((q, idx) => {
                const ans = studentAnswers[q.id];
                const hasOption = ans && ans.option && ans.option.toString().trim().length > 0;
                const cleanText = stripHtmlTags(ans && ans.text ? ans.text : '').trim();
                const hasText = cleanText.length > 0;
                const hasImages = ans && Array.isArray(ans.images) && ans.images.length > 0;

                const isAnswered = hasOption || hasText || hasImages;
                if (isAnswered) answeredCount++;

                let previewStr = 'Not answered yet';
                if (isAnswered) {
                    const parts = [];
                    if (q.question_type === 'MCQ' && hasOption) {
                        let optLabel = ans.option;
                        if (Array.isArray(q.options)) {
                            const matched = q.options.find(o => (o.id && o.id.toString() === ans.option) || o === ans.option);
                            if (matched) {
                                optLabel = stripHtmlTags(matched.option_text || matched.text || matched);
                            }
                        }
                        parts.push(`Option: ${optLabel}`);
                    } else if (hasText) {
                        parts.push(`Text: "${cleanText.substring(0, 50)}${cleanText.length > 50 ? '...' : ''}"`);
                    }

                    if (hasImages) {
                        parts.push(`Attached: ${ans.images.length} Image${ans.images.length > 1 ? 's' : ''}`);
                    }

                    previewStr = parts.join(' &bull; ');
                }

                const item = document.createElement('div');
                item.className = `review-item ${isAnswered ? 'is-answered' : ''}`;
                item.innerHTML = `
                    <div class="review-item-left">
                        <div class="review-badge-num">${idx + 1}</div>
                        <div>
                            <div class="review-item-text">
                                Question ${idx + 1} (${q.marks || 1} Mark${(q.marks || 1) > 1 ? 's' : ''})
                                <span style="font-size: 0.76rem; font-weight: 700; margin-left: 6px; color: ${isAnswered ? '#16a34a' : '#d97706'};">
                                    <i class="fa-solid ${isAnswered ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i> ${isAnswered ? 'Answered' : 'Pending'}
                                </span>
                                ${hasImages ? `<span class="review-image-pill"><i class="fa-regular fa-image"></i> ${ans.images.length} Image${ans.images.length > 1 ? 's' : ''}</span>` : ''}
                            </div>
                            <div class="review-item-preview">${previewStr}</div>
                        </div>
                    </div>
                    <button type="button" class="btn-jump-question" data-question-idx="${idx}">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i> Edit
                    </button>
                `;

                // Handle jump to question
                const jumpBtn = item.querySelector('.btn-jump-question');
                jumpBtn.addEventListener('click', () => {
                    currentQuestionIndex = idx;
                    renderCurrentQuestion();
                    reviewModal.classList.remove('show');
                });

                reviewQuestionsList.appendChild(item);
            });
        }

        const unansweredCount = totalCount - answeredCount;
        if (reviewAnsweredCount) reviewAnsweredCount.textContent = answeredCount.toString();
        if (reviewTotalCount) reviewTotalCount.textContent = totalCount.toString();
        if (reviewUnansweredCount) reviewUnansweredCount.textContent = unansweredCount.toString();
        if (reviewTimerDigits && countdownDigits) reviewTimerDigits.textContent = countdownDigits.textContent;

        reviewModal.classList.add('show');
    }

    if (closeReviewBtn) {
        closeReviewBtn.addEventListener('click', () => {
            if (reviewModal) reviewModal.classList.remove('show');
        });
    }

    if (reviewProceedSubmitBtn) {
        reviewProceedSubmitBtn.addEventListener('click', () => {
            saveAllAnswersToServer(false);
            if (reviewModal) reviewModal.classList.remove('show');
            if (submitModal) submitModal.classList.add('show');
        });
    }

    // -------------------------------------------------------------
    // 8. QUESTION PALETTE NAVIGATION
    // -------------------------------------------------------------
    function initQuestionPalette() {
        const paletteContainer = document.getElementById('paletteGrid');
        if (!paletteContainer) return;
        paletteContainer.innerHTML = '';

        rawQuestionsData.forEach((q, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'palette-btn';
            btn.id = `palette-btn-${idx}`;
            btn.textContent = (idx + 1).toString();
            btn.addEventListener('click', () => {
                saveAllAnswersToServer(false);
                currentQuestionIndex = idx;
                renderCurrentQuestion();
            });
            paletteContainer.appendChild(btn);
        });
        updatePaletteHighlights();
    }

    function updatePaletteHighlights() {
        rawQuestionsData.forEach((q, idx) => {
            const btn = document.getElementById(`palette-btn-${idx}`);
            if (!btn) return;
            const ans = studentAnswers[q.id];
            const hasOption = ans && ans.option && ans.option.toString().trim().length > 0;
            const cleanText = stripHtmlTags(ans && ans.text ? ans.text : '').trim();
            const hasText = cleanText.length > 0;
            const hasImages = ans && Array.isArray(ans.images) && ans.images.length > 0;
            const isAnswered = hasOption || hasText || hasImages;

            btn.className = 'palette-btn';
            if (idx === currentQuestionIndex) {
                btn.classList.add('active');
            }
            if (isAnswered) {
                btn.classList.add('answered');
            }
        });
    }

    // -------------------------------------------------------------
    // 9. ACCURATE REAL DURATION COUNTDOWN & HOLD STATE MANAGEMENT
    // -------------------------------------------------------------
    function formatTime(ms) {
        if (ms <= 0) return '00:00:00';
        const totalSec = Math.floor(ms / 1000);
        const hrs = Math.floor(totalSec / 3600);
        const mins = Math.floor((totalSec % 3600) / 60);
        const secs = totalSec % 60;
        return [
            hrs.toString().padStart(2, '0'),
            mins.toString().padStart(2, '0'),
            secs.toString().padStart(2, '0')
        ].join(':');
    }

    const totalDurationMs = totalDurationMins * 60 * 1000;
    let examStatus = document.body.getAttribute('data-status') || 'Published';
    let remainingSeconds = Math.max(0, parseInt(document.body.getAttribute('data-remaining-seconds') || String(totalDurationMins * 60), 10));
    let timerUpdatedAtEpoch = parseInt(document.body.getAttribute('data-timer-updated-at-epoch') || '0', 10);
    const initialServerTimeMs = Number(document.body.getAttribute('data-server-time-ms'));
    let serverOffsetMs = Number.isFinite(initialServerTimeMs) ? initialServerTimeMs - Date.now() : 0;
    const serverNowMs = () => Date.now() + serverOffsetMs;
    let isExamOnHold = examStatus.toLowerCase() === 'cancelled/on hold';

    function getRemainingMs() {
        const status = examStatus.toLowerCase();
        if (status === 'cancelled/on hold') {
            isExamOnHold = true;
            return Math.max(0, remainingSeconds * 1000);
        }

        isExamOnHold = false;
        if (status === 'completed') {
            return 0;
        }
        if (status !== 'running') return totalDurationMs;
        const baseRemainingMs = Math.max(0, remainingSeconds || totalDurationMins * 60) * 1000;
        return Math.max(0, baseRemainingMs - Math.max(0, serverNowMs() - timerUpdatedAtEpoch * 1000));
    }

    function tickCountdown() {
        const remainingMs = getRemainingMs();

        if (countdownDigits) {
            countdownDigits.textContent = formatTime(remainingMs);
            if (remainingMs < 120000) { // Under 2 minutes
                countdownDigits.classList.add('urgent');
            } else {
                countdownDigits.classList.remove('urgent');
            }
        }

        if (reviewTimerDigits) {
            reviewTimerDigits.textContent = formatTime(remainingMs);
        }

        // Hold Modal Check
        if (holdModal) {
            if (isExamOnHold) {
                holdModal.classList.add('show');
            } else {
                holdModal.classList.remove('show');
            }
        }

        // If time reaches 00:00:00, auto-submit
        if (remainingMs <= 0 && !isExamOnHold) {
            handleTimeExpired();
        }
    }

    let timeExpiredHandled = false;
    function handleTimeExpired() {
        if (timeExpiredHandled) return;
        timeExpiredHandled = true;
        clearInterval(countdownTimer);
        saveAllAnswersToServer(true);
        submitExamNow(true);
    }

    const countdownTimer = setInterval(tickCountdown, 1000);
    tickCountdown();

    // Check teacher status via polling every 4 seconds
    function pollExamStatus() {
        const requestStartedAt = Date.now();
        fetch(`student-giveExam-page-logic.php?action=check_exam_live_status&exam_id=${examId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const responseReceivedAt = Date.now();
                    if (Number.isFinite(Number(data.server_time_ms))) {
                        serverOffsetMs = Number(data.server_time_ms) - ((requestStartedAt + responseReceivedAt) / 2);
                    }
                    examStatus = data.status || examStatus;
                    remainingSeconds = Math.max(0, parseInt(data.remaining_seconds ?? remainingSeconds, 10) || 0);
                    timerUpdatedAtEpoch = parseInt(data.timer_updated_at_epoch || '0', 10);
                    tickCountdown();

                    if (examStatus === 'Cancelled/On Hold') {
                        isExamOnHold = true;
                        if (holdModal) holdModal.classList.add('show');
                    } else if (examStatus === 'Running') {
                        isExamOnHold = false;
                        if (holdModal) holdModal.classList.remove('show');
                    }
                }
            })
            .catch(e => console.warn('Status poll warning:', e));
    }

    pollExamStatus();
    const pollInterval = setInterval(pollExamStatus, 4000);

    // -------------------------------------------------------------
    // 10. EXAM SUBMISSION & REVISE TRANSITION
    // -------------------------------------------------------------
    if (submitExamBtn) {
        submitExamBtn.addEventListener('click', () => {
            if (submitModal) {
                submitModal.classList.add('show');
            }
        });
    }

    if (cancelSubmitBtn) {
        cancelSubmitBtn.addEventListener('click', () => {
            if (submitModal) {
                submitModal.classList.remove('show');
            }
        });
    }

    if (confirmSubmitBtn) {
        confirmSubmitBtn.addEventListener('click', () => {
            submitExamNow(false);
        });
    }

    function submitExamNow(isAuto = false) {
        flushCurrentTheoryAnswer();
        const payload = JSON.stringify(studentAnswers);

        // Also broadcast beacon save for fail-safe
        saveAllAnswersToServer(false);

        const formData = new FormData();
        formData.append('action', 'submit_exam');
        formData.append('exam_id', examId);
        formData.append('answers_json', payload);

        fetch('student-giveExam-page-logic.php', {
            method: 'POST',
            body: formData,
            keepalive: true
        })
        .then(res => res.json())
        .then(() => {
            finalizeSubmission(isAuto);
        })
        .catch(() => {
            finalizeSubmission(isAuto);
        });
    }

    function finalizeSubmission(isAuto) {
        localStorage.setItem(`exam_submitted_${studentId}_${examId}`, '1');
        localStorage.setItem(`exam_submitted_${examId}`, '1');

        if (window.ExamProctoring) window.ExamProctoring.stop();
        if (mediaStream) {
            mediaStream.getTracks().forEach(track => track.stop());
        }
        if (audioContext) {
            audioContext.close().catch(() => {});
        }
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }

        const url = isAuto 
            ? `student-examTable-page.php?timeup=1&exam_id=${examId}`
            : `student-examTable-page.php?submitted=1&exam_id=${examId}`;
        window.location.href = url;
    }

    // Auto-save on page exit or visibility switch
    window.addEventListener('beforeunload', () => {
        clearInterval(countdownTimer);
        clearInterval(pollInterval);
        saveAllAnswersToServer(true);
        if (window.ExamProctoring) window.ExamProctoring.stop();
        if (mediaStream) {
            mediaStream.getTracks().forEach(t => t.stop());
        }
        if (audioContext) {
            audioContext.close().catch(() => {});
        }
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            saveAllAnswersToServer(true);
        }
    });

    // Initialize UI
    initQuestionPalette();
    renderCurrentQuestion();
});
