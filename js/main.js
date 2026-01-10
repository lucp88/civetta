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
    
    const blogContainer = document.getElementById('blog-posts');
    if (blogContainer) {
        loadBlogPosts();
    }
});

function formatDate(dateString) {
    const months = [
        'januari', 'februari', 'maart', 'april', 'mei', 'juni',
        'juli', 'augustus', 'september', 'oktober', 'november', 'december'
    ];
    const date = new Date(dateString);
    return `${date.getDate()} ${months[date.getMonth()]} ${date.getFullYear()}`;
}

function formatContent(content) {
    return content
        .split('\n\n')
        .filter(p => p.trim())
        .map(p => `<p>${p.replace(/\n/g, '<br>')}</p>`)
        .join('');
}

function loadBlogPosts() {
    const container = document.getElementById('blog-posts');
    
    fetch('api/posts.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.posts.length > 0) {
                container.innerHTML = data.posts.map(post => `
                    <article class="blog-post">
                        <div class="post-date">${formatDate(post.post_date)}</div>
                        <h3>${escapeHtml(post.title)}</h3>
                        ${formatContent(post.content)}
                    </article>
                `).join('');
            } else {
                container.innerHTML = '<p class="placeholder-text">Nog geen nieuws. Kom binnenkort terug!</p>';
            }
        })
        .catch(error => {
            console.error('Error loading posts:', error);
            container.innerHTML = '<p class="placeholder-text">Kon posts niet laden.</p>';
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
