# Civetta Blog System Documentation

## Architecture Overview

The blog system uses a **Vue.js frontend** with a **PHP/MySQL backend**. Authentication is session-based and the admin panel allows managing blog posts.

```
├── api/
│   ├── auth.php        # Authentication endpoints
│   └── posts.php       # Blog post CRUD API
├── admin/
│   ├── config.php      # Database connection & auth helpers
│   ├── login.php       # Admin login page
│   ├── index.php       # Admin dashboard
│   ├── posts.php       # Post management UI
│   └── post-edit.php   # Post editor
├── js/
│   └── blog-app.js     # Vue.js frontend application
└── .htaccess           # Environment variables (not in git)
```

---

## Frontend (Vue.js)

The public-facing blog is built with Vue.js (`js/blog-app.js`). It fetches posts from the API and displays them. Authenticated users can also create, edit, and delete posts directly from the frontend.

### Key Data Properties

| Property | Description |
|----------|-------------|
| `posts` | Array of blog posts |
| `isAuthenticated` | Whether user is logged in |
| `showLogin` | Toggle login modal |
| `showEditor` | Toggle post editor |
| `postForm` | Form data for creating/editing posts |

### Main Methods

- `checkAuth()` - Checks if user has active session
- `loadPosts()` - Fetches all posts from API
- `login()` - Authenticates user
- `savePost()` - Creates or updates a post
- `deletePost(post)` - Deletes a post

### Example: Loading Posts

```javascript
async loadPosts() {
    const response = await fetch('api/posts.php');
    const data = await response.json();
    if (data.success) {
        this.posts = data.posts;
    }
}
```

---

## Backend (PHP API)

### Database Connection

Database credentials are stored in environment variables (via `.htaccess`) and loaded in `admin/config.php`:

```php
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
```

### Authentication API (`api/auth.php`)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `?action=check` | GET | Check if user is authenticated |
| `?action=login` | POST | Login with username/password |
| `?action=logout` | GET | Destroy session |

#### Example: Login Request

```javascript
fetch('api/auth.php?action=login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        username: 'admin',
        password: 'secret'
    })
});
```

#### Response
```json
{ "success": true, "user": "admin" }
```

Passwords are hashed with `password_hash()` and verified with `password_verify()`.

---

### Blog Posts API (`api/posts.php`)

| Method | Description | Auth Required |
|--------|-------------|---------------|
| GET | Fetch all posts | No |
| POST | Create new post | Yes |
| PUT | Update existing post | Yes |
| DELETE | Delete post | Yes |

#### Example: Creating a Post

```javascript
fetch('api/posts.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        title: 'New Bread Recipe',
        content: 'Today we baked a delicious sourdough...',
        post_date: '2026-01-11'
    })
});
```

#### Response
```json
{ "success": true, "id": 42 }
```

#### Database Table Structure

```sql
CREATE TABLE blog_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    post_date DATE NOT NULL
);
```

---

## Admin Panel

The admin panel (`/admin/`) is a server-side PHP interface protected by session authentication.

### Login Flow

1. User visits `/admin/` → redirected to `login.php` if not authenticated
2. `login.php` submits credentials to `api/auth.php?action=login`
3. On success, session variables are set:
   - `$_SESSION['admin_logged_in'] = true`
   - `$_SESSION['admin_user'] = 'username'`
4. User is redirected to dashboard

### Protected Pages

All admin pages include:
```php
require_once 'config.php';
requireLogin();
```

The `requireLogin()` function checks the session and redirects to login if not authenticated.

---

## Security

- Database credentials stored in `.htaccess` (not in git)
- Passwords hashed with bcrypt (`password_hash()`)
- Session-based authentication
- API endpoints check `$_SESSION['admin_logged_in']` before allowing writes
- SQL injection prevented with PDO prepared statements
