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
        // Always verify login state server-side to avoid stale cache/sessionStorage
        fetch('api/auth.php?action=check').then(r => r.json()).then(data => {
            if (data.authenticated) {
                navLogin.innerHTML = '<a href="#" class="login-trigger">Mijn Account</a>' +
                    '<div class="login-dropdown">' +
                    '<a href="admin/index.php">Admin Dashboard</a>' +
                    '<a href="#" id="nav-logout-admin">Uitloggen</a>' +
                    '</div>';
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
                        navLogin.innerHTML = '<a href="#" class="login-trigger">Mijn Account</a>' +
                            '<div class="login-dropdown">' +
                            '<a href="zakelijk-dashboard.html">Dashboard</a>' +
                            '<a href="#" id="nav-logout-business">Uitloggen</a>' +
                            '</div>';
                        navLogin.querySelector('#nav-logout-business').addEventListener('click', function(e) {
                            e.preventDefault();
                            sessionStorage.removeItem('businessAccount');
                            // Destroy server session too
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
