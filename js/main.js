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
        const isLoggedIn = sessionStorage.getItem('businessAccount');
        if (isLoggedIn) {
            navLogin.innerHTML = '<a href="zakelijk-dashboard.html" class="login-trigger">Mijn Dashboard</a>';
        }
    }
});
