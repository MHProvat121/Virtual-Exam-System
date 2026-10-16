/**
 * Dynamic Modal and Profile Editing JS for Student Portal
 */

function openEditModal(sectionType) {
    const modal = document.getElementById('editDataModal');
    const modalTitle = document.getElementById('modalTitle');
    const inputsContainer = document.getElementById('modalInputsContainer');
    const modalSectionInput = document.getElementById('modalSectionInput');

    if (!modal || !inputsContainer) return;

    modalSectionInput.value = sectionType;
    inputsContainer.innerHTML = '';

    if (sectionType === 'personal') {
        modalTitle.textContent = 'Edit Personal Information';

        const fields = [
            { label: 'Full Name', name: 'full_name', value: getVal('val-fullname'), type: 'text' },
            { label: 'Username', name: 'username', value: getVal('val-username'), type: 'text' },
            { label: 'Email', name: 'email', value: getVal('val-email'), type: 'email' },
            { label: 'Student ID', name: 'student_code', value: getVal('val-student-id'), type: 'text' },
            { label: 'Assigned Batch', name: 'batch', value: getVal('val-batch'), type: 'text' },
            { label: 'Contact Number', name: 'contact_number', value: getVal('val-contact'), type: 'text' },
            { label: 'Guardian Contact', name: 'guardian_contact', value: getVal('val-guardian'), type: 'text' },
            { label: 'Present Address', name: 'present_address', value: getVal('val-present'), type: 'textarea' },
            { label: 'Permanent Address', name: 'permanent_address', value: getVal('val-permanent'), type: 'textarea' }
        ];

        fields.forEach(field => {
            inputsContainer.appendChild(createInputField(field));
        });

    } else if (sectionType === 'education') {
        modalTitle.textContent = 'Edit Education Details';

        const fields = [
            { label: 'Institution', name: 'institution', value: getVal('val-institution'), type: 'text' },
            { label: 'Program / Degree', name: 'program', value: getVal('val-program'), type: 'text' }
        ];

        fields.forEach(field => {
            inputsContainer.appendChild(createInputField(field));
        });

    } else if (sectionType === 'payment') {
        modalTitle.textContent = 'Edit Payment Information';

        const fields = [
            { label: 'Bank Name', name: 'bank_name', value: getVal('val-bank-name'), type: 'text' },
            { label: 'Account Name', name: 'account_name', value: getVal('val-account-name'), type: 'text' },
            { label: 'Account Number', name: 'account_number', value: getVal('val-account-number'), type: 'text' },
            { label: 'Mobile Banking Number', name: 'mobile_banking_number', value: getVal('val-mobile-banking'), type: 'text' }
        ];

        fields.forEach(field => {
            inputsContainer.appendChild(createInputField(field));
        });
    }

    modal.classList.add('active');
    modal.style.display = 'flex';
}

function closeEditModal() {
    const modal = document.getElementById('editDataModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function getVal(elementId) {
    const el = document.getElementById(elementId);
    if (!el) return '';
    const txt = el.textContent.trim();
    return (txt === 'N/A' || txt === 'n/a') ? '' : txt;
}

function createInputField(field) {
    const group = document.createElement('div');
    group.className = 'form-group';

    const label = document.createElement('label');
    label.textContent = field.label;

    let input;
    if (field.type === 'textarea') {
        input = document.createElement('textarea');
        input.rows = 3;
    } else {
        input = document.createElement('input');
        input.type = field.type;
    }

    input.name = field.name;
    input.value = field.value;

    group.appendChild(label);
    group.appendChild(input);
    return group;
}

document.addEventListener('DOMContentLoaded', () => {
    // Active Navigation Highlighting
    const profileLinks = document.querySelectorAll('.nav-link');
    profileLinks.forEach(link => {
        if (link.getAttribute('href') === 'student-profile-page.php') {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });

    const avatarTrigger = document.getElementById('trigger-avatar-upload');
    const avatarInput = document.getElementById('avatar-file-input');

    if (avatarTrigger && avatarInput) {
        avatarTrigger.addEventListener('click', () => {
            avatarInput.click();
        });
    }

    window.addEventListener('click', (event) => {
        const modal = document.getElementById('editDataModal');
        if (event.target === modal) {
            closeEditModal();
        }
    });
});