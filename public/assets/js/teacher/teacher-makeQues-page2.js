/* ==========================================================================
   SECTION 1: INITIALIZATION & GLOBAL EVENT LISTENERS
   ========================================================================== */
let questionCounter = 0; // shared across all functions in this file

// Helper function to prevent XSS issues when displaying summary table text
function escapeHtml(str) {
    if (!str) return '';
    return str
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', () => {

    // Rebuild the summary table from the DB and sync questionCounter
    loadExistingQuestions();

    // Check if there is an unsaved question draft from accidents or previous session
    restoreUnsavedDraftIfAny();

    const addQuesBtn = document.getElementById('addQuesBtn');

    if (addQuesBtn) {
        addQuesBtn.addEventListener('click', () => {
            if (document.querySelector('.question-card')) {
                alert("Please save the current question before adding a new one.");
                return;
            }

            questionCounter++;
            createQuestionCard(questionCounter);
            setAddQuesBtnState(false);
            scheduleDraftAutosave(questionCounter);
        });
    }

    updateCreateExamBtnState();

    const mainForm = document.querySelector('form');
    if (mainForm) {
        mainForm.addEventListener('submit', function (e) {
            const submitBtn = document.getElementById('createExamBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerText = 'Creating Exam...';
            }
        });
    }

    // Window exit & tab visibility listeners to ensure draft is saved on accident
    window.addEventListener('beforeunload', () => {
        const openCard = document.querySelector('.question-card');
        if (openCard && openCard.id) {
            const qId = parseInt(openCard.id.replace('quesCard_', ''), 10);
            if (qId > 0) {
                saveDraftMeantime(qId, true);
            }
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            const openCard = document.querySelector('.question-card');
            if (openCard && openCard.id) {
                const qId = parseInt(openCard.id.replace('quesCard_', ''), 10);
                if (qId > 0) {
                    saveDraftMeantime(qId, false);
                }
            }
        }
    });
});

/* ==========================================================================
   SECTION 1B: AUTOSAVE DRAFT CONTROLLER (PERSISTS PROGRESS MEANTIME)
   ========================================================================== */
let draftAutosaveTimer = null;

function scheduleDraftAutosave(quesId) {
    const statusEl = document.getElementById(`draftAutosaveStatus_${quesId}`);
    if (statusEl) {
        statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color: #6366f1;"></i> <span style="color: #64748b;">Autosaving draft...</span>';
    }

    clearTimeout(draftAutosaveTimer);
    draftAutosaveTimer = setTimeout(() => {
        saveDraftMeantime(quesId);
    }, 450);
}

function getCardDraftData(quesId) {
    const typeSelect = document.getElementById(`type_${quesId}`);
    const isLocked = isExamTypeLocked();
    const type = isLocked ? getGlobalQuestionType() : (typeSelect ? typeSelect.value : getGlobalQuestionType());
    const marksInput = document.getElementById(`marks_${quesId}`);
    const marks = marksInput && marksInput.value ? parseFloat(marksInput.value) : 1;

    if (typeof tinymce !== 'undefined' && tinymce.get(`text_${quesId}`)) {
        tinymce.get(`text_${quesId}`).save();
    }
    const textEl = document.getElementById(`text_${quesId}`);
    const text = textEl ? textEl.value : '';

    const imageUrlInput = document.getElementById(`imgUrl_${quesId}`);
    const imageUrl = imageUrlInput ? imageUrlInput.value : '';

    let optionsList = [];
    let correctOptionIndex = 0;

    if (type === 'MCQ') {
        const optInputs = document.querySelectorAll(`#optionRows_${quesId} .opt-input`);
        optInputs.forEach(input => {
            optionsList.push(input.value);
        });

        const checkedRadio = document.querySelector(`input[name="correct_${quesId}"]:checked`);
        if (checkedRadio) {
            const radioRows = Array.from(document.querySelectorAll(`#optionRows_${quesId} .option-row`));
            correctOptionIndex = radioRows.indexOf(checkedRadio.closest('.option-row'));
            if (correctOptionIndex === -1) correctOptionIndex = 0;
        }
    }

    return {
        exam_id: window.EXAM_ID || 0,
        ques_no: quesId,
        type: type,
        text: text,
        marks: marks,
        options: optionsList,
        correct_index: correctOptionIndex,
        image: imageUrl,
        timestamp: Date.now()
    };
}

async function saveDraftMeantime(quesId, syncBeacon = false) {
    const card = document.getElementById(`quesCard_${quesId}`);
    if (!card) return;

    const draft = getCardDraftData(quesId);
    const examId = window.EXAM_ID || 0;

    // 1. Immediately store in localStorage (survives crashes, power cuts, accidental reloads)
    try {
        localStorage.setItem(`teacher_question_draft_${examId}`, JSON.stringify(draft));
    } catch (e) {
        console.warn('localStorage draft save error:', e);
    }

    // 2. Sync to server session in background
    if (syncBeacon && navigator.sendBeacon) {
        const payload = JSON.stringify({
            action: 'save_draft',
            ...draft
        });
        const blob = new Blob([payload], { type: 'application/json' });
        navigator.sendBeacon('teacher-makeQues-page2-logic.php', blob);
        return;
    }

    try {
        const res = await fetch('teacher-makeQues-page2-logic.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save_draft',
                ...draft
            })
        });
        const data = await res.json();
        const statusEl = document.getElementById(`draftAutosaveStatus_${quesId}`);
        if (statusEl) {
            const timeStr = data.time || new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            statusEl.innerHTML = `<i class="fa-solid fa-cloud-arrow-up text-emerald-600"></i> <span style="color: #10b981;">Draft autosaved meantime (${timeStr})</span>`;
        }
    } catch (err) {
        // Fallback: local draft successfully saved
        const statusEl = document.getElementById(`draftAutosaveStatus_${quesId}`);
        if (statusEl) {
            statusEl.innerHTML = `<i class="fa-solid fa-cloud text-emerald-600"></i> <span style="color: #10b981;">Draft saved locally</span>`;
        }
    }
}

function restoreUnsavedDraftIfAny() {
    const examId = window.EXAM_ID || 0;
    let draft = window.SAVED_DRAFT_QUESTION || null;

    if (!draft) {
        try {
            const localRaw = localStorage.getItem(`teacher_question_draft_${examId}`);
            if (localRaw) {
                draft = JSON.parse(localRaw);
            }
        } catch (e) {}
    }

    if (!draft || !draft.ques_no) return;

    // Check if question is already in summary table
    const existingSummaryRow = document.getElementById(`summaryRow_${draft.ques_no}`);
    if (existingSummaryRow) {
        return;
    }

    // Restore the drafted card
    if (!document.querySelector('.question-card')) {
        if (draft.ques_no > questionCounter) {
            questionCounter = draft.ques_no;
        }

        createQuestionCard(draft.ques_no, {
            type: draft.type,
            marks: draft.marks,
            text: draft.text,
            options: draft.options,
            correctIndex: draft.correct_index,
            image: draft.image
        });

        const statusEl = document.getElementById(`draftAutosaveStatus_${draft.ques_no}`);
        if (statusEl) {
            statusEl.innerHTML = `<i class="fa-solid fa-shield-halved text-indigo-600"></i> <span style="color: #4f46e5; font-weight: 600;">Draft restored from autosave</span>`;
        }
    }
}

/**
 * Utility function to enable or disable the "Add Question" button
 */
function setAddQuesBtnState(enabled) {
    const addQuesBtn = document.getElementById('addQuesBtn');
    if (addQuesBtn) {
        addQuesBtn.disabled = !enabled;
        if (!enabled) {
            addQuesBtn.style.opacity = '0.5';
            addQuesBtn.style.cursor = 'not-allowed';
        } else {
            addQuesBtn.style.opacity = '1';
            addQuesBtn.style.cursor = 'pointer';
        }
    }
}

/**
 * Checks the summary table row count and enables/disables the "Create Exam" button.
 * Enables button if questionCount >= 1, otherwise disables it.
 */
function updateCreateExamBtnState() {
    const createExamBtn = document.getElementById('createExamBtn');
    const summaryTableBody = document.getElementById('summaryTableBody');

    if (!createExamBtn || !summaryTableBody) return;

    const questionCount = summaryTableBody.children.length;

    if (questionCount >= 1) {
        createExamBtn.disabled = false;
        createExamBtn.style.opacity = '1';
        createExamBtn.style.cursor = 'pointer';
    } else {
        createExamBtn.disabled = true;
        createExamBtn.style.opacity = '0.5';
        createExamBtn.style.cursor = 'not-allowed';
    }
}

/**
 * Populate the summary table with questions already saved in the DB,
 * and set questionCounter to the highest existing question number.
 */
function loadExistingQuestions() {
    const existing = window.EXISTING_QUESTIONS || [];
    if (existing.length === 0) return;

    const summaryTableWrapper = document.getElementById('summaryTableWrapper');
    const summaryTableBody = document.getElementById('summaryTableBody');

    existing.forEach(q => {
        if (q.ques_no > questionCounter) {
            questionCounter = q.ques_no;
        }

        const tr = document.createElement('tr');
        tr.id = `summaryRow_${q.ques_no}`;
        
        // Stash raw text and image URL on dataset attributes for lossless retrieval during edit
        tr.dataset.text = q.text || '';
        if (q.image) tr.dataset.image = q.image;

        tr.innerHTML = `
            <td><strong>#${q.ques_no}</strong></td>
            <td>
                <div class="summary-text-content">${q.text}</div>
                ${q.image ? `<img src="${q.image}" class="summary-thumb" style="max-width: 140px; max-height: 90px; object-fit: contain; margin-top: 6px; border-radius: 4px; border: 1px solid #cbd5e1; display: block;" alt="Attached diagram">` : ''}
            </td>
            <td><span class="badge badge-blue">${q.type}</span></td>
            <td>${q.marks}</td>
            <td>${q.options && q.options.length > 0 ? q.options.map(escapeHtml).join(', ') : 'N/A'}</td>
            <td>
                <button class="btn-edit" onclick="editQuestion(${q.ques_no})" title="Edit Question">✏️</button>
                <button class="btn-delete" onclick="deleteSummaryRow(${q.ques_no})" title="Remove Question">✕</button>
            </td>
        `;
        summaryTableBody.appendChild(tr);
    });

    summaryTableWrapper.style.display = 'block';
    updateCreateExamBtnState();
}


/* ==========================================================================
   SECTION 2: EXAM CATEGORY & TYPE DETERMINATION UTILITIES
   ========================================================================== */

function getExamTypeCategory() {
    const rawType = (window.SELECTED_EXAM_TYPE || 'Hybrid (MCQ+Theory)').toString().trim().toUpperCase();

    if (rawType.includes('HYBRID') ||
        rawType.includes('BOTH') ||
        (rawType.includes('MCQ') && (rawType.includes('THEORY') || rawType.includes('WRITTEN')))) {
        return 'HYBRID';
    }

    if (rawType.includes('THEORY') || rawType.includes('WRITTEN')) {
        return 'THEORY';
    }

    if (rawType.includes('MCQ')) {
        return 'MCQ';
    }

    return 'HYBRID';
}

function getGlobalQuestionType() {
    const category = getExamTypeCategory();
    if (category === 'THEORY') return 'Theory';
    if (category === 'MCQ') return 'MCQ';
    return 'MCQ';
}

function isExamTypeLocked() {
    const category = getExamTypeCategory();
    return category === 'MCQ' || category === 'THEORY';
}


/* ==========================================================================
   SECTION 3: QUESTION CARD DOM CREATION & TINYMCE EDITOR INTEGRATION
   ========================================================================== */

function createQuestionCard(quesId, existingData = null) {
    const questionContainer = document.getElementById('questionContainer');
    const card = document.createElement('div');
    card.className = 'question-card';
    card.id = `quesCard_${quesId}`;

    const category = getExamTypeCategory();
    const isLocked = isExamTypeLocked();

    let type;
    if (existingData && existingData.type) {
        type = existingData.type;
    } else if (category === 'THEORY') {
        type = 'Theory';
    } else if (category === 'MCQ') {
        type = 'MCQ';
    } else {
        type = 'MCQ';
    }

    const marks = existingData ? existingData.marks : '';
    const text = existingData ? existingData.text : '';
    const imageUrl = existingData && existingData.image ? existingData.image : '';

    card.innerHTML = `
        <div class="question-card-header">
            <div class="form-group-inline">
                <label>Ques No:</label>
                <span><strong>#${quesId}</strong></span>
            </div>

            <div class="form-group-inline">
                <label for="type_${quesId}">Ques Type:</label>
                <select id="type_${quesId}" onchange="toggleQuesType(${quesId})" ${isLocked ? 'disabled' : ''}>
                    <option value="MCQ" ${type === 'MCQ' ? 'selected' : ''}>MCQ</option>
                    <option value="Theory" ${type === 'Theory' ? 'selected' : ''}>Theory</option>
                </select>
            </div>

            <div class="form-group-inline">
                <label for="marks_${quesId}">Marks:</label>
                <input type="number" id="marks_${quesId}" min="1" step="0.5" value="${marks}" placeholder="e.g. 5" style="width: 80px;" required>
            </div>
        </div>

        <div class="question-body">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <label style="font-weight: 600; margin: 0;">Question Statement / Text:</label>
                <label class="char-btn" style="cursor:pointer; display:inline-flex; align-items:center; gap:6px; padding: 5px 12px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.85rem;" title="Attach an image to this question">
                    <i class="fa-solid fa-image"></i> Attach Image
                    <input type="file" id="imgFile_${quesId}" accept="image/*" style="display:none;" onchange="handleImageUpload(${quesId}, this)">
                </label>
            </div>

            <textarea id="text_${quesId}" placeholder="Enter question description...">${text}</textarea>

            <!-- Image preview area -->
            <div id="imgPreviewArea_${quesId}" class="image-preview-area" style="display:${imageUrl ? 'flex' : 'none'}; align-items:center; gap:12px; margin-top:10px; padding:8px; background:#f1f5f9; border-radius:6px;">
                <img id="imgPreview_${quesId}" src="${imageUrl}" alt="Attached image preview" style="max-width:180px; max-height:140px; width:auto; height:auto; object-fit:contain; border-radius:6px; border:1px solid #cbd5e1; background:#ffffff;">
                <button type="button" class="btn-delete" onclick="removeImage(${quesId})" title="Remove image">✕ Remove Image</button>
                <input type="hidden" id="imgUrl_${quesId}" value="${imageUrl}">
            </div>

            <div id="optionsArea_${quesId}" class="options-container" style="display: ${type === 'Theory' ? 'none' : 'flex'}; margin-top: 15px;">
                <label>Options (Minimum 2 required, select radio button for correct answer):</label>
                <div id="optionRows_${quesId}"></div>
                <button type="button" class="btn-secondary" onclick="addOptionField(${quesId})">+ Add Option</button>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; flex-wrap: wrap; gap: 10px;">
                <div id="draftAutosaveStatus_${quesId}" class="draft-autosave-indicator" style="font-size: 0.82rem; color: #10b981; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-cloud-arrow-up text-emerald-600"></i> Draft autosaved meantime
                </div>
                <button type="button" id="saveBtn_${quesId}" class="btn-save" onclick="saveQuestion(${quesId})" style="margin-top: 0;">Save Question</button>
            </div>
        </div>
    `;

    questionContainer.appendChild(card);

    // Initialize TinyMCE rich text editor
    initQuestionEditor(quesId);

    // Attach listeners for autosaving draft changes immediately in meantime
    const marksInput = document.getElementById(`marks_${quesId}`);
    if (marksInput) {
        marksInput.addEventListener('input', () => scheduleDraftAutosave(quesId));
        marksInput.addEventListener('change', () => scheduleDraftAutosave(quesId));
    }
    const typeSelect = document.getElementById(`type_${quesId}`);
    if (typeSelect) {
        typeSelect.addEventListener('change', () => scheduleDraftAutosave(quesId));
    }
    const optRows = document.getElementById(`optionRows_${quesId}`);
    if (optRows) {
        optRows.addEventListener('input', () => scheduleDraftAutosave(quesId));
        optRows.addEventListener('change', () => scheduleDraftAutosave(quesId));
    }

    // Disable 'Add Question' button whenever an unsaved question card exists
    setAddQuesBtnState(false);

    if (type === 'MCQ') {
        if (existingData && existingData.options && existingData.options.length > 0) {
            existingData.options.forEach((optText, idx) => {
                const isCorrect = (existingData.correctIndex === idx);
                addOptionField(quesId, optText, isCorrect);
            });
        } else {
            addOptionField(quesId, '', true);
            addOptionField(quesId, '', false);
        }
    }
}

/**
 * Initializes TinyMCE with Symbol Picker (charmap) and Rich Formatting tools.
 */
function initQuestionEditor(quesId) {
    if (typeof tinymce !== 'undefined') {
        if (tinymce.get(`text_${quesId}`)) {
            tinymce.get(`text_${quesId}`).destroy();
        }

        tinymce.init({
            selector: `#text_${quesId}`,
            height: 220,
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
            setup: function (editor) {
                editor.on('change keyup NodeChange input', function () {
                    editor.save();
                    scheduleDraftAutosave(quesId);
                });
            }
        });
    }
}


/* ==========================================================================
   SECTION 3B: IMAGE UPLOAD CONTROLLER
   ========================================================================== */

async function handleImageUpload(quesId, inputEl) {
    const file = inputEl.files[0];
    if (!file) return;

    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        alert("Please choose a JPG, PNG, GIF, or WEBP image.");
        inputEl.value = '';
        return;
    }
    const maxSizeMB = 5;
    if (file.size > maxSizeMB * 1024 * 1024) {
        alert(`Image is too large. Please choose a file under ${maxSizeMB}MB.`);
        inputEl.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('question_image', file);

    const toolbarLabel = inputEl.closest('label');
    const originalLabelHtml = toolbarLabel ? toolbarLabel.innerHTML : null;
    if (toolbarLabel) toolbarLabel.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';

    try {
        const response = await fetch('teacher-makeQues-page2-logic.php', {
            method: 'POST',
            body: formData
        });

        if (!response.ok) {
            throw new Error(`HTTP Error: status ${response.status}`);
        }

        const result = await response.json();

        if (result.success) {
            const previewArea = document.getElementById(`imgPreviewArea_${quesId}`);
            const previewImg = document.getElementById(`imgPreview_${quesId}`);
            const urlInput = document.getElementById(`imgUrl_${quesId}`);

            previewImg.src = result.url;
            urlInput.value = result.url;
            previewArea.style.display = 'flex';
            scheduleDraftAutosave(quesId);
        } else {
            alert("Image upload failed: " + (result.message || "Unknown error."));
        }
    } catch (error) {
        console.error("Image upload error:", error);
        alert("Failed to upload image. Please check your connection and try again.");
    } finally {
        if (toolbarLabel && originalLabelHtml) toolbarLabel.innerHTML = originalLabelHtml;
        inputEl.value = '';
    }
}

/**
 * Clears the attached image from a question card
 */
function removeImage(quesId) {
    const previewArea = document.getElementById(`imgPreviewArea_${quesId}`);
    const previewImg = document.getElementById(`imgPreview_${quesId}`);
    const urlInput = document.getElementById(`imgUrl_${quesId}`);
    const fileInput = document.getElementById(`imgFile_${quesId}`);

    if (urlInput) urlInput.value = '';
    if (previewImg) previewImg.src = '';
    if (fileInput) fileInput.value = '';
    if (previewArea) previewArea.style.display = 'none';
    scheduleDraftAutosave(quesId);
}


/* ==========================================================================
   SECTION 4: DYNAMIC MCQ OPTIONS CONTROLLER
   ========================================================================== */

function toggleQuesType(quesId) {
    const typeSelect = document.getElementById(`type_${quesId}`);
    if (!typeSelect) return;

    const type = typeSelect.value;
    const optionsArea = document.getElementById(`optionsArea_${quesId}`);

    if (type === 'Theory') {
        optionsArea.style.display = 'none';
    } else {
        optionsArea.style.display = 'flex';
        const container = document.getElementById(`optionRows_${quesId}`);
        if (container && container.children.length === 0) {
            addOptionField(quesId, '', true);
            addOptionField(quesId, '', false);
        }
    }
    scheduleDraftAutosave(quesId);
}

function addOptionField(quesId, value = '', isChecked = false) {
    const container = document.getElementById(`optionRows_${quesId}`);
    if (!container) return;

    const currentCount = container.children.length;

    const row = document.createElement('div');
    row.className = 'option-row';
    row.innerHTML = `
        <input type="radio" name="correct_${quesId}" value="${currentCount}" ${isChecked || currentCount === 0 ? 'checked' : ''}>
        <input type="text" class="opt-input" value="${escapeHtml(value)}" placeholder="Option ${currentCount + 1}" required>
        <button type="button" class="btn-edit" onclick="editOptionField(this)" title="Edit Option">✏️</button>
        <button type="button" class="btn-delete" onclick="this.parentElement.remove(); scheduleDraftAutosave(${quesId});" title="Delete Option">✕</button>
    `;
    container.appendChild(row);
    scheduleDraftAutosave(quesId);
}

function editOptionField(btn) {
    const row = btn.parentElement;
    const input = row.querySelector('.opt-input');
    if (input) {
        input.focus();
        input.select();
    }
}


/* ==========================================================================
   SECTION 5: SAVE QUESTION TO SERVER & FLOW CONTROL
   ========================================================================== */

async function saveQuestion(quesId) {
    const saveBtn = document.getElementById(`saveBtn_${quesId}`);
    const typeSelect = document.getElementById(`type_${quesId}`);
    const isLocked = isExamTypeLocked();
    const type = isLocked ? getGlobalQuestionType() : (typeSelect ? typeSelect.value : getGlobalQuestionType());
    const marks = document.getElementById(`marks_${quesId}`).value;
    
    // Save tinyMCE content back to textarea
    if (typeof tinymce !== 'undefined' && tinymce.get(`text_${quesId}`)) {
        tinymce.get(`text_${quesId}`).save();
    }
    const text = document.getElementById(`text_${quesId}`).value;

    const imageUrlInput = document.getElementById(`imgUrl_${quesId}`);
    const imageUrl = imageUrlInput ? imageUrlInput.value : '';

    if (!marks || !text.trim()) {
        alert("Please fill in the Marks and Question text.");
        return;
    }

    let optionsList = [];
    let correctOptionIndex = 0;

    if (type === 'MCQ') {
        const optInputs = document.querySelectorAll(`#optionRows_${quesId} .opt-input`);

        if (optInputs.length < 2) {
            alert("At least 2 options are required for MCQ questions.");
            return;
        }

        for (let input of optInputs) {
            if (!input.value.trim()) {
                alert("Option fields cannot be left empty.");
                return;
            }
            optionsList.push(input.value.trim());
        }

        const checkedRadio = document.querySelector(`input[name="correct_${quesId}"]:checked`);
        if (checkedRadio) {
            const radioRows = Array.from(document.querySelectorAll(`#optionRows_${quesId} .option-row`));
            correctOptionIndex = radioRows.indexOf(checkedRadio.closest('.option-row'));
            if (correctOptionIndex === -1) correctOptionIndex = 0;
        }
    }

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerText = "Saving Question...";
    }

    const payload = {
        exam_id: window.EXAM_ID || 0,
        ques_no: quesId,
        type: type,
        text: text,
        marks: parseFloat(marks),
        options: optionsList,
        correct_index: correctOptionIndex,
        image: imageUrl
    };

    try {
        const response = await fetch('teacher-makeQues-page2-logic.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        if (!response.ok) {
            throw new Error(`HTTP Error: status ${response.status}`);
        }

        const result = await response.json();

        if (result.success) {
            // STEP 2: Live update of Total Marks in table section from server calculation
            const totalMarksCell = document.getElementById('totalMarksCell');
            if (totalMarksCell && result.total_marks !== undefined) {
                totalMarksCell.innerText = result.total_marks;
            }

            const summaryTableWrapper = document.getElementById('summaryTableWrapper');
            const summaryTableBody = document.getElementById('summaryTableBody');
            summaryTableWrapper.style.display = 'block';

            let tr = document.getElementById(`summaryRow_${quesId}`);
            if (!tr) {
                tr = document.createElement('tr');
                tr.id = `summaryRow_${quesId}`;
                summaryTableBody.appendChild(tr);
            }

            // Save raw data to dataset attributes to preserve exact structure for editing
            tr.dataset.text = text;
            if (imageUrl) tr.dataset.image = imageUrl;
            else delete tr.dataset.image;

            tr.innerHTML = `
                <td><strong>#${quesId}</strong></td>
                <td>
                    <div class="summary-text-content">${text}</div>
                    ${imageUrl ? `<img src="${imageUrl}" class="summary-thumb" style="max-width: 140px; max-height: 90px; object-fit: contain; margin-top: 6px; border-radius: 4px; border: 1px solid #cbd5e1; display: block;" alt="Attached diagram">` : ''}
                </td>
                <td><span class="badge badge-blue">${type}</span></td>
                <td>${marks}</td>
                <td>${optionsList.length > 0 ? optionsList.map(escapeHtml).join(', ') : 'N/A'}</td>
                <td>
                    <button class="btn-edit" onclick="editQuestion(${quesId})" title="Edit Question">✏️</button>
                    <button class="btn-delete" onclick="deleteSummaryRow(${quesId})" title="Remove Question">✕</button>
                </td>
            `;

            // Destroy TinyMCE instance and remove editing card
            if (typeof tinymce !== 'undefined' && tinymce.get(`text_${quesId}`)) {
                tinymce.get(`text_${quesId}`).destroy();
            }

            const existingCard = document.getElementById(`quesCard_${quesId}`);
            if (existingCard) existingCard.remove();

            // Clear draft storage now that question is permanently committed
            try {
                localStorage.removeItem(`teacher_question_draft_${window.EXAM_ID || 0}`);
            } catch (e) {}

            fetch('teacher-makeQues-page2-logic.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'clear_draft', exam_id: window.EXAM_ID || 0 })
            }).catch(() => {});

            // RE-ENABLE "Add Question" button
            setAddQuesBtnState(true);

            // UPDATE "Create Exam" button state
            updateCreateExamBtnState();

        } else {
            alert("Error saving question: " + result.message);
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerText = "Save Question";
            }
        }
    } catch (error) {
        console.error("Fetch error:", error);
        alert("Failed to communicate with server. Please check console for details.");
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerText = "Save Question";
        }
    }
}


/* ==========================================================================
   SECTION 6: CANCEL EXAM CREATION MODAL & SUMMARY TABLE MANAGEMENT
   ========================================================================== */

let cancelModalTimer = null;

function cancelExamCreation() {
    const modal = document.getElementById('cancelConfirmModal');
    const confirmBtn = document.getElementById('confirmDeleteExamBtn');
    const dismissBtn = document.getElementById('modalDismissBtn');
    const closeX = document.getElementById('modalCloseX');

    if (!modal || !confirmBtn) return;

    if (dismissBtn) dismissBtn.disabled = false;
    if (closeX) closeX.disabled = false;

    modal.style.display = 'flex';

    if (cancelModalTimer) {
        clearInterval(cancelModalTimer);
    }

    let secondsLeft = 3;
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = `<i class="fa-solid fa-trash-can"></i> Yes, Cancel & Delete (${secondsLeft}s)`;

    cancelModalTimer = setInterval(() => {
        secondsLeft--;
        if (secondsLeft > 0) {
            confirmBtn.innerHTML = `<i class="fa-solid fa-trash-can"></i> Yes, Cancel & Delete (${secondsLeft}s)`;
        } else {
            clearInterval(cancelModalTimer);
            cancelModalTimer = null;
            confirmBtn.disabled = false;
            confirmBtn.innerHTML = `<i class="fa-solid fa-trash-can"></i> Yes, Cancel & Delete`;
        }
    }, 1000);
}

function closeCancelModal() {
    const modal = document.getElementById('cancelConfirmModal');
    if (modal) {
        modal.style.display = 'none';
    }
    if (cancelModalTimer) {
        clearInterval(cancelModalTimer);
        cancelModalTimer = null;
    }
}

async function proceedCancelExam() {
    const confirmBtn = document.getElementById('confirmDeleteExamBtn');
    const dismissBtn = document.getElementById('modalDismissBtn');
    const closeX = document.getElementById('modalCloseX');

    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Deleting...`;
    }
    if (dismissBtn) dismissBtn.disabled = true;
    if (closeX) closeX.disabled = true;

    try {
        const response = await fetch('teacher-makeQues-page2-logic.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'cancel',
                exam_id: window.EXAM_ID || 0
            })
        });

        const result = await response.json();

        try {
            localStorage.removeItem(`teacher_question_draft_${window.EXAM_ID || 0}`);
        } catch (e) {}

        if (result.success) {
            window.location.href = result.redirect || 'teacher-quesTable-page.php';
        } else {
            alert("Error cancelling exam: " + (result.message || "Unknown error"));
            window.location.href = result.redirect || 'teacher-quesTable-page.php';
        }
    } catch (error) {
        console.error("Cancel exam error:", error);
        window.location.href = 'teacher-quesTable-page.php';
    }
}

function editQuestion(quesId) {
    if (document.querySelector('.question-card')) {
        alert("Please save or complete the current question card before editing another.");
        return;
    }

    const tr = document.getElementById(`summaryRow_${quesId}`);
    if (!tr) return;

    const text = tr.dataset.text !== undefined ? tr.dataset.text : tr.children[1].querySelector('.summary-text-content')?.innerHTML || '';
    const type = tr.children[2].innerText.trim();
    const marks = tr.children[3].innerText.trim();
    const optionsText = tr.children[4].innerText.trim();
    const image = tr.dataset.image || '';

    let options = [];
    if (type === 'MCQ' && optionsText !== 'N/A') {
        options = optionsText.split(', ');
    }

    createQuestionCard(quesId, { type, marks, text, options, image });
}

async function deleteSummaryRow(quesId) {
    if (typeof tinymce !== 'undefined' && tinymce.get(`text_${quesId}`)) {
        tinymce.get(`text_${quesId}`).destroy();
    }

    try {
        const response = await fetch('teacher-makeQues-page2-logic.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'delete_question',
                exam_id: window.EXAM_ID || 0,
                ques_no: quesId
            })
        });

        const result = await response.json();
        // Live update total marks in table header after question removal
        if (result.success && result.total_marks !== undefined) {
            const totalMarksCell = document.getElementById('totalMarksCell');
            if (totalMarksCell) totalMarksCell.innerText = result.total_marks;
        }
    } catch (error) {
        console.error("Error removing question from DB:", error);
    }

    const row = document.getElementById(`summaryRow_${quesId}`);
    if (row) row.remove();

    try {
        const rawDraft = localStorage.getItem(`teacher_question_draft_${window.EXAM_ID || 0}`);
        if (rawDraft) {
            const dObj = JSON.parse(rawDraft);
            if (dObj && dObj.ques_no === quesId) {
                localStorage.removeItem(`teacher_question_draft_${window.EXAM_ID || 0}`);
            }
        }
    } catch (e) {}

    const summaryTableBody = document.getElementById('summaryTableBody');
    if (summaryTableBody && summaryTableBody.children.length === 0) {
        document.getElementById('summaryTableWrapper').style.display = 'none';
    }

    if (!document.querySelector('.question-card')) {
        setAddQuesBtnState(true);
    }

    updateCreateExamBtnState();
}
