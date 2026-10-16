/**
 * teacher-courseSyllabus-page.js
 * Supporting client-side interactions:
 * Feature 1: Real-time search filter for courses & batches
 * Feature 2: Pinning tables to top
 * Feature 3: Archiving tables
 * Feature 4: Toggling archived courses view
 * Feature 5: In archive section: Restore and Pin only
 * Teacher Modal Handlers: Create Course, Add Subject, Edit Course, Clear Table, Remove Batch
 */

let deleteTimerInterval = null;
let deleteBatchTimerInterval = null;

// Feature 1: Real-time search filter for courses & batches
function filterCourseBatches() {
    const input = document.getElementById('courseBatchSearch');
    if (!input) return;
    const term = input.value.toLowerCase().trim();
    const cards = document.querySelectorAll('.batch-card');

    let visibleCount = 0;
    cards.forEach(card => {
        const cardText = card.innerText.toLowerCase();
        if (cardText.includes(term)) {
            card.style.display = 'block';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    const emptyContainer = document.getElementById('searchNoResultsMessage');
    if (emptyContainer) {
        emptyContainer.style.display = visibleCount === 0 && cards.length > 0 ? 'block' : 'none';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.style.display = 'none';
    
    if (modalId === 'deleteTableModal' && deleteTimerInterval) {
        clearInterval(deleteTimerInterval);
        const confirmBtn = document.getElementById('confirmDeleteTableBtn');
        if (confirmBtn) confirmBtn.disabled = true;
    }
    
    if (modalId === 'deleteBatchModal' && deleteBatchTimerInterval) {
        clearInterval(deleteBatchTimerInterval);
        const confirmBtn = document.getElementById('confirmDeleteBatchBtn');
        if (confirmBtn) confirmBtn.disabled = true;
    }
}

function syncTeacherSelect(sourceSelect, targetSelectId) {
    const targetSelect = document.getElementById(targetSelectId);
    if (targetSelect) {
        targetSelect.value = sourceSelect.value;
    }
}

function openCreateCourseModal() {
    const modal = document.getElementById('createCourseModal');
    const teacherName = document.getElementById('createTeacherName');
    const teacherId = document.getElementById('createTeacherId');
    if (teacherName) teacherName.value = '';
    if (teacherId) teacherId.value = '';
    if (modal) modal.style.display = 'block';
}

function openEditBatchHeaderModal(batchName, teacherId) {
    const modal = document.getElementById('editBatchHeaderModal');
    const oldBatchInput = document.getElementById('editBatchHeaderOldName');
    const batchInput = document.getElementById('editBatchHeaderName');
    const teacherNameSelect = document.getElementById('editBatchTeacherName');
    const teacherIdSelect = document.getElementById('editBatchTeacherId');

    if (oldBatchInput) oldBatchInput.value = batchName || '';
    if (batchInput) batchInput.value = batchName || '';
    if (teacherNameSelect) teacherNameSelect.value = teacherId || '';
    if (teacherIdSelect) teacherIdSelect.value = teacherId || '';
    if (modal) modal.style.display = 'block';
}

function openAddSubjectModal(batchName) {
    const batchInput = document.getElementById('addSubjectBatchInput');
    const batchDisplay = document.getElementById('addSubjectBatchDisplay');
    const modal = document.getElementById('addSubjectModal');
    if (batchInput) batchInput.value = batchName;
    if (batchDisplay) batchDisplay.value = batchName;
    if (modal) modal.style.display = 'block';
}

function openEditModal(examData) {
    const editId = document.getElementById('editExamId');
    const editBatch = document.getElementById('editBatch');
    const editCourseCode = document.getElementById('editCourseCode');
    const editCredit = document.getElementById('editCredit');
    const editSubject = document.getElementById('editSubject');
    const editTitle = document.getElementById('editExamTitle');
    const modal = document.getElementById('editModal');

    if (editId) editId.value = examData.id;
    if (editBatch) editBatch.value = examData.batch;
    if (editCourseCode) editCourseCode.value = examData.course_code || '';
    if (editCredit) editCredit.value = examData.credit || '';
    if (editSubject) editSubject.value = examData.subject;
    if (editTitle) editTitle.value = examData.exam_title;
    if (modal) modal.style.display = 'block';
}

function openDeleteTableModal(batchName) {
    const modal = document.getElementById('deleteTableModal');
    const confirmBtn = document.getElementById('confirmDeleteTableBtn');
    const batchInput = document.getElementById('deleteBatchInput');
    const batchSpan = document.getElementById('deleteBatchNameSpan');

    if (batchInput) batchInput.value = batchName;
    if (batchSpan) batchSpan.innerText = batchName;

    if (modal) modal.style.display = 'block';
    if (confirmBtn) {
        confirmBtn.disabled = true;
        let countdown = 3;
        confirmBtn.innerText = `Clear (${countdown}s)`;

        if (deleteTimerInterval) clearInterval(deleteTimerInterval);
        deleteTimerInterval = setInterval(() => {
            countdown--;
            if (countdown > 0) {
                confirmBtn.innerText = `Clear (${countdown}s)`;
            } else {
                clearInterval(deleteTimerInterval);
                confirmBtn.disabled = false;
                confirmBtn.innerText = 'Confirm Clear Table';
            }
        }, 1000);
    }
}

function openDeleteBatchModal(batchName) {
    const modal = document.getElementById('deleteBatchModal');
    const confirmBtn = document.getElementById('confirmDeleteBatchBtn');
    const batchInput = document.getElementById('deleteBatchNameInput');
    const batchDisplay = document.getElementById('deleteBatchNameDisplay');

    if (batchInput) batchInput.value = batchName;
    if (batchDisplay) batchDisplay.innerText = batchName;

    if (modal) modal.style.display = 'block';
    if (confirmBtn) {
        confirmBtn.disabled = true;
        let countdown = 3;
        confirmBtn.innerText = `Delete (${countdown}s)`;

        if (deleteBatchTimerInterval) clearInterval(deleteBatchTimerInterval);
        deleteBatchTimerInterval = setInterval(() => {
            countdown--;
            if (countdown > 0) {
                confirmBtn.innerText = `Delete (${countdown}s)`;
            } else {
                clearInterval(deleteBatchTimerInterval);
                confirmBtn.disabled = false;
                confirmBtn.innerText = 'Delete';
            }
        }, 1000);
    }
}

// Close on outside click
window.onclick = (event) => {
    if (event.target && event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        if (deleteTimerInterval) clearInterval(deleteTimerInterval);
        if (deleteBatchTimerInterval) clearInterval(deleteBatchTimerInterval);
    }
};