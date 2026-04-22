// reCAPTCHA v3
window.RECAPTCHA_SITE_KEY = '6LfXDocsAAAAABbTwZUzQXnYdaRvyWa5cVGulfwd';

// Only load reCAPTCHA on pages that actually use it
const _rcPages = ['login.html', 'zakelijk.html', 'contact.html', 'bestelling-plaatsen.html', 'checkout.html', 'financiering.html'];
const _rcPath = window.location.pathname.split('/').pop() || '';
if (window.RECAPTCHA_SITE_KEY && _rcPages.some(p => _rcPath === p)) {
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

// Remove fade-out class when page is restored from bfcache (browser back/forward)
window.addEventListener('pageshow', function(e) {
    if (e.persisted) {
        document.body.classList.remove('page-leaving');
    }
});

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


function updateNavCart() {
    const navLinks = document.querySelector('header .nav-links');
    if (!navLinks) return;

    const existing = document.getElementById('nav-cart-item');

    if (!sessionStorage.getItem('businessAccount')) {
        if (existing) existing.remove();
        return;
    }

    let count = 0;
    try {
        const data = JSON.parse(sessionStorage.getItem('checkoutData') || '{}');
        count = (data.items || []).reduce(function(s, i) { return s + (parseInt(i.quantity) || 1); }, 0);
    } catch(e) {}

    const li = existing || document.createElement('li');
    li.id = 'nav-cart-item';
    li.innerHTML =
        '<a href="winkelwagen.html" class="nav-cart-link" title="Winkelwagen">' +
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>' +
        (count > 0 ? '<span class="nav-cart-count">' + count + '</span>' : '') +
        '</a>';

    if (!existing) {
        const navLogin = navLinks.querySelector('.nav-login');
        navLogin ? navLogin.insertAdjacentElement('afterend', li) : navLinks.appendChild(li);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateNavCart();
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

        const personIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';

        function attachDropdownToggle() {
            navLogin.querySelector('.login-trigger').addEventListener('click', function(e) {
                e.stopPropagation();
                navLogin.classList.toggle('open');
            });
        }

        // Always verify login state server-side to avoid stale cache/sessionStorage
        fetch('api/auth.php?action=check').then(r => r.json()).then(data => {
            if (data.authenticated) {
                const displayName = data.display_name || data.user || 'Mijn Account';
                navLogin.innerHTML =
                    '<button class="login-trigger nav-icon-btn" type="button" title="' + displayName + '">' + personIcon + '</button>' +
                    '<div class="login-dropdown">' +
                    '<div class="login-dropdown-name">' + displayName + '</div>' +
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
                        const name = bdata.account.bedrijfsnaam || bdata.account.naam || 'Mijn Account';
                        navLogin.innerHTML =
                            '<button class="login-trigger nav-icon-btn" type="button" title="' + name + '">' + personIcon + '</button>' +
                            '<div class="login-dropdown">' +
                            '<div class="login-dropdown-name">' + name + '</div>' +
                            '<a href="mijn-dashboard.html">Dashboard</a>' +
                            '<a href="#" id="nav-logout-business">Uitloggen</a>' +
                            '</div>';
                        attachDropdownToggle();
                        updateNavCart();
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
