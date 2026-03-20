// reCAPTCHA v3 - set your site key here (public key, safe to expose)
window.RECAPTCHA_SITE_KEY = '6LfXDocsAAAAABbTwZUzQXnYdaRvyWa5cVGulfwd';

// Load reCAPTCHA script if site key is configured
if (window.RECAPTCHA_SITE_KEY) {
    const script = document.createElement('script');
    script.src = 'https://www.google.com/recaptcha/api.js?render=' + window.RECAPTCHA_SITE_KEY;
    script.async = true;
    document.head.appendChild(script);
}

// Helper: get reCAPTCHA token for an action
window.getRecaptchaToken = function(action) {
    if (!window.RECAPTCHA_SITE_KEY || !window.grecaptcha) {
        return Promise.resolve('');
    }
    return new Promise(function(resolve) {
        grecaptcha.ready(function() {
            grecaptcha.execute(window.RECAPTCHA_SITE_KEY, { action: action }).then(resolve).catch(function() { resolve(''); });
        });
    });
};

// Page-leaving fade-out transition
document.addEventListener('click', function(e) {
    const link = e.target.closest('a[href]');
    if (link && !link.target && !e.ctrlKey && !e.metaKey && !e.shiftKey &&
        link.origin === location.origin &&
        !link.href.includes('#') &&
        link.href !== location.href) {
        e.preventDefault();
        const href = link.href;
        document.body.classList.add('page-leaving');
        setTimeout(function() { window.location.href = href; }, 150);
    }
});


document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const navLinks = document.querySelector('.nav-links');
    
    if (mobileMenuBtn && navLinks) {
        mobileMenuBtn.addEventListener('click', function() {
            mobileMenuBtn.classList.toggle('active');
            navLinks.classList.toggle('active');
        });
        
        document.addEventListener('click', function(e) {
            if (!mobileMenuBtn.contains(e.target) && !navLinks.contains(e.target)) {
                mobileMenuBtn.classList.remove('active');
                navLinks.classList.remove('active');
            }
        });
    }
    
    const navLogin = document.querySelector('.nav-login');
    if (navLogin) {
        // Close login dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!navLogin.contains(e.target)) {
                navLogin.classList.remove('open');
            }
        });

        // Always verify login state server-side to avoid stale cache/sessionStorage
        fetch('api/auth.php?action=check').then(r => r.json()).then(data => {
            const chevron = '<svg class="nav-login-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';

            function attachDropdownToggle() {
                navLogin.querySelector('.login-trigger').addEventListener('click', function(e) {
                    e.stopPropagation();
                    navLogin.classList.toggle('open');
                });
            }

            if (data.authenticated) {
                const displayName = data.display_name || data.user || 'Mijn Account';
                navLogin.innerHTML =
                    '<button class="login-trigger" type="button">' + displayName + chevron + '</button>' +
                    '<div class="login-dropdown">' +
                    '<a href="admin/index.php">Mijn account</a>' +
                    '<a href="admin/index.php">Admin Dashboard</a>' +
                    '<a href="#" id="nav-logout-admin">Uitloggen</a>' +
                    '</div>';
                attachDropdownToggle();
                navLogin.querySelector('#nav-logout-admin').addEventListener('click', function(e) {
                    e.preventDefault();
                    fetch('api/auth.php?action=logout').then(() => {
                        sessionStorage.removeItem('adminLoggedIn');
                        window.location.reload();
                    });
                });
            } else {
                sessionStorage.removeItem('adminLoggedIn');
                // Check business session
                return fetch('api/business-login.php?action=check').then(r => r.json()).then(bdata => {
                    if (bdata.success && bdata.account) {
                        navLogin.innerHTML =
                            '<button class="login-trigger" type="button">Mijn Account' + chevron + '</button>' +
                            '<div class="login-dropdown">' +
                            '<a href="zakelijk-dashboard.html">Dashboard</a>' +
                            '<a href="#" id="nav-logout-business">Uitloggen</a>' +
                            '</div>';
                        attachDropdownToggle();
                        navLogin.querySelector('#nav-logout-business').addEventListener('click', function(e) {
                            e.preventDefault();
                            sessionStorage.removeItem('businessAccount');
                            fetch('api/auth.php?action=logout').then(() => {
                                window.location.reload();
                            });
                        });
                    } else {
                        sessionStorage.removeItem('businessAccount');
                    }
                });
            }
        }).catch(() => {});
    }
});
