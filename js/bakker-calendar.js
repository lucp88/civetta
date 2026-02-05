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

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDayModal();
        if (typeof closeOrderModal === 'function') closeOrderModal();
        if (typeof closeNewOrderModal === 'function') closeNewOrderModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const dayModal = document.getElementById('dayModal');
    if (dayModal) {
        dayModal.addEventListener('click', function(e) {
            if (e.target === this) closeDayModal();
        });
    }
});
