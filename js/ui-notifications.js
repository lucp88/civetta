/**
 * On-page toast notifications and confirm dialogs.
 * Include this file to use showToast() and showConfirm() anywhere.
 *
 * Also handles declarative data-confirm="message" on <form> elements.
 */

(function() {
    // Inject styles once
    if (!document.getElementById('ui-notif-styles')) {
        const style = document.createElement('style');
        style.id = 'ui-notif-styles';
        style.textContent = `
            .toast-container { position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%); z-index: 100000; display: flex; flex-direction: column; gap: 0.5rem; pointer-events: none; align-items: center; }
            .toast { padding: 0.85rem 1.25rem; border-radius: 10px; color: #fff; font-size: 0.9rem; box-shadow: 0 4px 15px rgba(0,0,0,0.2); animation: toastIn 0.3s ease; max-width: 420px; line-height: 1.4; pointer-events: auto; white-space: pre-line; }
            .toast.success { background: #2e7d32; }
            .toast.error { background: #c62828; }
            .toast.warning { background: #e65100; }
            .toast.info { background: #1565c0; }
            @keyframes toastIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes toastOut { from { opacity: 1; transform: translateY(0); } to { opacity: 0; transform: translateY(10px); } }
            .confirm-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100001; display: flex; align-items: center; justify-content: center; animation: toastIn 0.2s ease; }
            .confirm-box { background: #fff; border-radius: 12px; padding: 1.5rem; max-width: 420px; width: 90%; box-shadow: 0 8px 30px rgba(0,0,0,0.25); }
            .confirm-box h3 { margin: 0 0 0.75rem 0; color: #2d4a2d; font-size: 1.1rem; }
            .confirm-box p { margin: 0 0 1.25rem 0; color: #555; font-size: 0.95rem; line-height: 1.5; }
            .confirm-box .confirm-actions { display: flex; gap: 0.75rem; justify-content: flex-end; }
            .confirm-box .btn-confirm { padding: 0.5rem 1.25rem; border: none; border-radius: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 500; }
            .confirm-box .btn-confirm.primary { background: #3d6b3d; color: #fff; }
            .confirm-box .btn-confirm.primary:hover { background: #2d4a2d; }
            .confirm-box .btn-confirm.secondary { background: #e0e0e0; color: #333; }
            .confirm-box .btn-confirm.secondary:hover { background: #ccc; }
        `;
        document.head.appendChild(style);
    }

    // Ensure container exists
    function getContainer() {
        let c = document.getElementById('toastContainer');
        if (!c) {
            c = document.createElement('div');
            c.className = 'toast-container';
            c.id = 'toastContainer';
            document.body.appendChild(c);
        }
        return c;
    }

    window.showToast = function(message, type, duration) {
        type = type || 'info';
        if (duration === undefined) {
            duration = type === 'error' ? 5000 : type === 'success' ? 3500 : 4000;
        }
        const container = getContainer();
        const toast = document.createElement('div');
        toast.className = 'toast ' + type;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(function() {
            toast.style.animation = 'toastOut 0.3s ease forwards';
            setTimeout(function() { toast.remove(); }, 300);
        }, duration);
    };

    window.showConfirm = function(message, title) {
        title = title || 'Bevestigen';
        return new Promise(function(resolve) {
            const overlay = document.createElement('div');
            overlay.className = 'confirm-overlay';
            overlay.innerHTML =
                '<div class="confirm-box">' +
                    '<h3>' + title + '</h3>' +
                    '<p>' + message + '</p>' +
                    '<div class="confirm-actions">' +
                        '<button class="btn-confirm secondary" data-action="cancel">Annuleren</button>' +
                        '<button class="btn-confirm primary" data-action="ok">Doorgaan</button>' +
                    '</div>' +
                '</div>';
            document.body.appendChild(overlay);
            var _md = false;
            overlay.addEventListener('mousedown', function(e) { _md = e.target === overlay; });
            overlay.addEventListener('click', function(e) {
                var action = e.target.dataset.action;
                if (action === 'ok') { overlay.remove(); resolve(true); }
                else if (action === 'cancel' || (e.target === overlay && _md)) { overlay.remove(); resolve(false); }
            });
            document.addEventListener('keydown', function esc(e) {
                if (e.key === 'Escape') { overlay.remove(); resolve(false); document.removeEventListener('keydown', esc); }
            });
        });
    };

    window.confirmSubmit = function(form, message, title) {
        showConfirm(message, title).then(function(ok) {
            if (ok) form.submit();
        });
        return false;
    };

    window.confirmLink = function(url, message, title) {
        showConfirm(message, title).then(function(ok) {
            if (ok) window.location.href = url;
        });
        return false;
    };

    // Declarative data-confirm="message" on <form> elements
    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('submit', function(e) {
            var form = e.target;
            var msg = form.dataset.confirm;
            if (!msg || form.dataset.confirmPassed) return;
            e.preventDefault();
            showConfirm(msg).then(function(ok) {
                if (ok) {
                    form.dataset.confirmPassed = '1';
                    form.submit();
                    delete form.dataset.confirmPassed;
                }
            });
        });
    });
})();
