function navigate(direction) {
    const date = new Date(currentDate);
    if (currentMode === 'day') {
        date.setDate(date.getDate() + direction);
    } else if (currentMode === 'week') {
        date.setDate(date.getDate() + (direction * 7));
    } else {
        date.setMonth(date.getMonth() + direction);
    }
    window.location.href = `?date=${date.toISOString().split('T')[0]}&mode=${currentMode}`;
}

function goToday() {
    window.location.href = `?date=${new Date().toISOString().split('T')[0]}&mode=${currentMode}`;
}

function setViewMode(mode) {
    window.location.href = `?date=${currentDate}&mode=${mode}`;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function switchTotalsTab(btn, tab) {
    const section = btn.closest('.totals-section');
    section.querySelectorAll('.totals-tab').forEach(t => t.classList.remove('active'));
    section.querySelectorAll('.totals-tab-content').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    section.querySelector(`.totals-tab-content[data-tab="${tab}"]`).classList.add('active');
}

function closeDayModal() {
    document.getElementById('dayModal').classList.remove('active');
}

// Toast notification system
function showToast(message, type) {
    type = type || 'info';
    var container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    var icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
    toast.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i> ' + escapeHtml(message);
    container.appendChild(toast);
    setTimeout(function() {
        toast.classList.add('toast-out');
        setTimeout(function() { toast.remove(); }, 300);
    }, 3500);
}

// Confirm dialog (returns Promise)
function showConfirm(message, title) {
    return new Promise(function(resolve) {
        var overlay = document.createElement('div');
        overlay.className = 'confirm-overlay';
        overlay.innerHTML =
            '<div class="confirm-box">' +
                '<div class="confirm-header">' + escapeHtml(title || 'Bevestigen') + '</div>' +
                '<div class="confirm-body">' + escapeHtml(message) + '</div>' +
                '<div class="confirm-actions">' +
                    '<button class="confirm-btn confirm-btn-cancel">Annuleren</button>' +
                    '<button class="confirm-btn confirm-btn-ok">OK</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        overlay.querySelector('.confirm-btn-ok').onclick = function() { overlay.remove(); resolve(true); };
        overlay.querySelector('.confirm-btn-cancel').onclick = function() { overlay.remove(); resolve(false); };
        overlay.addEventListener('click', function(e) { if (e.target === overlay) { overlay.remove(); resolve(false); } });
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDayModal();
        if (typeof closeOrderModal === 'function') closeOrderModal();
        if (typeof closeNewOrderModal === 'function') closeNewOrderModal();
        if (typeof closeBakdagenModal === 'function') closeBakdagenModal();
        if (typeof closeAppointmentModal === 'function') closeAppointmentModal();
        var confirmOverlay = document.querySelector('.confirm-overlay');
        if (confirmOverlay) { confirmOverlay.remove(); }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const dayModal = document.getElementById('dayModal');
    if (dayModal) {
        dayModal.addEventListener('click', function(e) {
            if (e.target === this) closeDayModal();
        });
    }
    const bakdagenModal = document.getElementById('bakdagenModal');
    if (bakdagenModal) {
        bakdagenModal.addEventListener('click', function(e) {
            if (e.target === this && typeof closeBakdagenModal === 'function') closeBakdagenModal();
        });
    }
});
