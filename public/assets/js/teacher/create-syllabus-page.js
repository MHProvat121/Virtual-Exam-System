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
});

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
 * and set questionCounter to the highest existing question number so
 * new questions continue numbering instead of colliding.
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
                <div class="summary-text-content">${escapeHtml(q.text)}</div>
                ${q.image ? `<img src="${q.image}" class="summary-thumb" style="max-width: 140px; max-height: 90px; object-fit: contain; margin-top: 6px; border-radius: 4px; border: 1px solid #cbd5e1; display: block;" alt="Attached diagram">` : ''}
            </td>
            <td><span class="badge badge-blue">${q.type}</span></td>
            <td>${q.marks}</td>
            <td>${q.options && q.options.length > 0 ? q.options.join(', ') : 'N/A'}</td>
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
    const rawType = (window.SELECTED_EXAM_TYPE || 'Hybrid').toString().trim().toUpperCase();

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
   SECTION 3: QUESTION CARD DOM CREATION
   ========================================================================== */

const SPECIAL_CHAR_BUTTONS = [
    { char: '@', label: '@' },
    { char: '√', label: '√' },
    { char: '±', label: '±' },
    { char: '×', label: '×' },
    { char: '÷', label: '÷' },
    { char: '≤', label: '≤' },
    { char: '≥', label: '≥' },
    { char: '≠', label: '≠' },
    { char: '∞', label: '∞' },
    { char: 'π', label: 'π' },
    { char: 'Δ', label: 'Δ' },
    { char: '°', label: '°' },
    { char: '→', label: '→' },
    { char: '∑', label: '∑' },
    { char: '∫', label: '∫' }
];

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

    const charButtonsHtml = SPECIAL_CHAR_BUTTONS.map(b =>
        `<button type="button" class="char-btn" onclick="insertAtCursor(${quesId}, '${b.char}')" title="Insert ${b.char}">${b.label}</button>`
    ).join('');

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
                <input type="number" id="marks_${quesId}" min="1" value="${marks}" placeholder="e.g. 5" style="width: 80px;" required>
            </div>
        </div>

        <div class="question-body">
            <label>Question Statement / Text:</label>

            <!-- Formatting toolbar -->
            <div class="editor-toolbar" id="toolbar_${quesId}" style="display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin-bottom:6px; padding:6px 8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                <span style="font-size:0.8rem; color:#64748b; margin-right:4px;">Insert:</span>
                ${charButtonsHtml}
                <button type="button" class="char-btn" onclick="applyScript(${quesId}, 'sup')" title="Superscript selected text (e.g. x²)">x²</button>
                <button type="button" class="char-btn" onclick="applyScript(${quesId}, 'sub')" title="Subscript selected text (e.g. x₂)">x₂</button>
                <span style="border-left:1px solid #cbd5e1; height:20px; margin:0 4px;"></span>
                <label class="char-btn" style="cursor:pointer; display:inline-flex; align-items:center; gap:4px;" title="Attach an image to this question">
                    <i class="fa-solid fa-image"></i> Attach Image
                    <input type="file" id="imgFile_${quesId}" accept="image/*" style="display:none;" onchange="handleImageUpload(${quesId}, this)">
                </label>
            </div>

            <textarea id="text_${quesId}" placeholder="Enter question description...">${text}</textarea>

            <!-- Image preview area with strict dimension limits -->
            <div id="imgPreviewArea_${quesId}" class="image-preview-area" style="display:${imageUrl ? 'flex' : 'none'}; align-items:center; gap:12px; margin-top:10px; padding:8px; background:#f1f5f9; border-radius:6px;">
                <img id="imgPreview_${quesId}" src="${imageUrl}" alt="Attached image preview" style="max-width:180px; max-height:140px; width:auto; height:auto; object-fit:contain; border-radius:6px; border:1px solid #cbd5e1; background:#ffffff;">
                <button type="button" class="btn-delete" onclick="removeImage(${quesId})" title="Remove image">✕ Remove Image</button>
                <input type="hidden" id="imgUrl_${quesId}" value="${imageUrl}">
            </div>

            <div id="optionsArea_${quesId}" class="options-container" style="display: ${type === 'Theory' ? 'none' : 'flex'};">
                <label>Options (Minimum 2 required, select radio button for correct answer):</label>
                <div id="optionRows_${quesId}"></div>
                <button type="button" class="btn-secondary" onclick="addOptionField(${quesId})">+ Add Option</button>
            </div>

            <button type="button" id="saveBtn_${quesId}" class="btn-save" onclick="saveQuestion(${quesId})">Save Question</button>
        </div>
    `;

    questionContainer.appendChild(card);

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


/* ==========================================================================
   SECTION 3B: FORMATTING TOOLBAR — SPECIAL CHARACTERS, SUP/SUB, IMAGE UPLOAD
   ========================================================================== */

function insertAtCursor(quesId, charToInsert) {
    const textarea = document.getElementById(`text_${quesId}`);
    if (!textarea) return;

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const value = textarea.value;

    textarea.value = value.slice(0, start) + charToInsert + value.slice(end);

    const newPos = start + charToInsert.length;
    textarea.focus();
    textarea.setSelectionRange(newPos, newPos);
}

const SUPERSCRIPT_MAP = {
    '0': '⁰', '1': '¹', '2': '²', '3': '³', '4': '⁴', '5': '⁵', '6': '⁶', '7': '⁷', '8': '⁸', '9': '⁹',
    '+': '⁺', '-': '⁻', '=': '⁼', '(': '⁽', ')': '⁾',
    'n': 'ⁿ', 'i': 'ⁱ', 'x': 'ˣ', 'y': 'ʸ'
};
const SUBSCRIPT_MAP = {
    '0': '₀', '1': '₁', '2': '₂', '3': '₃', '4': '₄', '5': '₅', '6': '₆', '7': '₇', '8': '₈', '9': '₉',
    '+': '₊', '-': '₋', '=': '₌', '(': '₍', ')': '₎',
    'a': 'ₐ', 'e': 'ₑ', 'i': 'ᵢ', 'n': 'ₙ', 'x': 'ₓ'
};

function applyScript(quesId, mode) {
    const textarea = document.getElementById(`text_${quesId}`);
    if (!textarea) return;

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;

    if (start === end) {
        alert("Select the text you want to convert (e.g. highlight the '2' in x2) before clicking this button.");
        return;
    }

    const map = mode === 'sup' ? SUPERSCRIPT_MAP : SUBSCRIPT_MAP;
    const selected = textarea.value.slice(start, end);
    const converted = selected.split('').map(ch => map[ch.toLowerCase()] || ch).join('');

    textarea.value = textarea.value.slice(0, start) + converted + textarea.value.slice(end);

    const newPos = start + converted.length;
    textarea.focus();
    textarea.setSelectionRange(newPos, newPos);
}

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
        const response = await fetch('upload-question-image-logic.php', {
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
 * Clears the attached image from a question card and resets inputs
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
}

function addOptionField(quesId, value = '', isChecked = false) {
    const container = document.getElementById(`optionRows_${quesId}`);
    if (!container) return;

    const currentCount = container.children.length;

    const row = document.createElement('div');
    row.className = 'option-row';
    row.innerHTML = `
        <input type="radio" name="correct_${quesId}" value="${currentCount}" ${isChecked || currentCount === 0 ? 'checked' : ''}>
        <input type="text" class="opt-input" value="${value}" placeholder="Option ${currentCount + 1}" required>
        <button type="button" class="btn-edit" onclick="editOptionField(this)" title="Edit Option">✏️</button>
        <button type="button" class="btn-delete" onclick="this.parentElement.remove()" title="Delete Option">✕</button>
    `;
    container.appendChild(row);
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
    exam_id: window.EXAM_ID || 0, // <--- Sends exam_id in JSON payload as fallback
    ques_no: quesId,
    type: type,
    text: text,
    marks: marks,
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
                    <div class="summary-text-content">${escapeHtml(text)}</div>
                    ${imageUrl ? `<img src="${imageUrl}" class="summary-thumb" style="max-width: 140px; max-height: 90px; object-fit: contain; margin-top: 6px; border-radius: 4px; border: 1px solid #cbd5e1; display: block;" alt="Attached diagram">` : ''}
                </td>
                <td><span class="badge badge-blue">${type}</span></td>
                <td>${marks}</td>
                <td>${optionsList.length > 0 ? optionsList.join(', ') : 'N/A'}</td>
                <td>
                    <button class="btn-edit" onclick="editQuestion(${quesId})" title="Edit Question">✏️</button>
                    <button class="btn-delete" onclick="deleteSummaryRow(${quesId})" title="Remove Question">✕</button>
                </td>
            `;

            // Remove editing question card from screen
            const existingCard = document.getElementById(`quesCard_${quesId}`);
            if (existingCard) existingCard.remove();

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

 