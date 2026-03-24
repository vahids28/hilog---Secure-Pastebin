<?php
/**
 * hilog Admin Panel - Version 3.1
 * Fixed content decoding for previews
 */

require_once __DIR__ . '/config.php';

session_start();

function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function getAdminUser() {
    $db = getDB();
    $result = $db->query("SELECT username FROM admin_users LIMIT 1");
    return $result->fetch_assoc();
}

function updateAdminPassword($newPassword) {
    $db = getDB();
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $db->query("UPDATE admin_users SET password_hash = '$hash' WHERE id = 1");
    return $db->affected_rows > 0;
}

function updateAdminUsername($newUsername) {
    $db = getDB();
    $db->query("UPDATE admin_users SET username = '" . $db->real_escape_string($newUsername) . "' WHERE id = 1");
    return $db->affected_rows > 0;
}

if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $db = getDB();
    $result = $db->query("SELECT * FROM admin_users WHERE username = '" . $db->real_escape_string($username) . "'");
    $admin = $result->fetch_assoc();
    
    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $admin['username'];
        $db->query("UPDATE admin_users SET last_login = " . time() . " WHERE id = 1");
        header('Location: admin.php');
        exit;
    } else {
        $loginError = 'Invalid username or password';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (isset($_POST['update_settings']) && isLoggedIn()) {
    $newUsername = trim($_POST['username'] ?? '');
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    if (!empty($newUsername)) {
        if (updateAdminUsername($newUsername)) {
            $_SESSION['admin_username'] = $newUsername;
            $success = "Username updated successfully";
        } else {
            $errors[] = "Failed to update username";
        }
    }
    
    if (!empty($newPassword)) {
        if (strlen($newPassword) < 6) {
            $errors[] = "Password must be at least 6 characters";
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = "Passwords do not match";
        } else {
            if (updateAdminPassword($newPassword)) {
                $success = "Password updated successfully";
            } else {
                $errors[] = "Failed to update password";
            }
        }
    }
}

if (isset($_POST['delete_paste']) && isLoggedIn()) {
    $pasteId = $_POST['paste_id'] ?? '';
    $db = getDB();
    $db->query("DELETE FROM pastes WHERE id = '" . $db->real_escape_string($pasteId) . "'");
    if ($db->affected_rows > 0) {
        header('Location: admin.php?deleted=' . urlencode($pasteId));
        exit;
    }
}

if (isset($_GET['view']) && isLoggedIn()) {
    $viewId = $_GET['view'] ?? '';
    if ($viewId && preg_match('/^[a-z]{4,6}$/', $viewId)) {
        $db = getDB();
        $result = $db->query("SELECT * FROM pastes WHERE id = '" . $db->real_escape_string($viewId) . "'");
        $paste = $result->fetch_assoc();
        if ($paste) {
            $content = $paste['content'];
            if ($paste['has_password']) {
                $content = '[🔒 Password protected - cannot view in admin]';
            } else {
                // Decode base64 content
                $decoded = base64_decode($content, true);
                if ($decoded !== false && strlen($decoded) > 0) {
                    // Try to detect if it's still base64
                    $doubleDecoded = base64_decode($decoded, true);
                    if ($doubleDecoded !== false && strlen($doubleDecoded) > 0 && strlen($doubleDecoded) < strlen($decoded)) {
                        $content = htmlspecialchars($doubleDecoded);
                    } else {
                        $content = htmlspecialchars($decoded);
                    }
                } else {
                    $content = htmlspecialchars($content);
                }
            }
            showPasteViewer($viewId, $content);
            exit;
        }
    }
}

if (!isLoggedIn()) {
    showLoginForm($loginError ?? '');
    exit;
}

$db = getDB();
$totalPastes = $db->query("SELECT COUNT(*) as count FROM pastes")->fetch_assoc()['count'];
$totalViews = $db->query("SELECT SUM(views) as sum FROM pastes")->fetch_assoc()['sum'] ?? 0;
$totalAttempts = $db->query("SELECT SUM(password_views) as sum FROM pastes")->fetch_assoc()['sum'] ?? 0;
$passwordProtected = $db->query("SELECT COUNT(*) as count FROM pastes WHERE has_password = 1")->fetch_assoc()['count'];
$expiredCount = $db->query("SELECT COUNT(*) as count FROM pastes WHERE expires_at IS NOT NULL AND expires_at < " . time())->fetch_assoc()['count'];

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 20;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'created_desc';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$validPerPage = [10, 20, 50, 100];
if (!in_array($perPage, $validPerPage)) $perPage = 20;

$where = "";
if (!empty($search)) {
    $where = " WHERE id LIKE '%" . $db->real_escape_string($search) . "%'";
}

$orderBy = "ORDER BY created_at DESC";
switch ($sort) {
    case 'created_asc': $orderBy = "ORDER BY created_at ASC"; break;
    case 'created_desc': $orderBy = "ORDER BY created_at DESC"; break;
    case 'expires_asc': $orderBy = "ORDER BY expires_at ASC"; break;
    case 'expires_desc': $orderBy = "ORDER BY expires_at DESC"; break;
    case 'views_desc': $orderBy = "ORDER BY views DESC"; break;
    case 'views_asc': $orderBy = "ORDER BY views ASC"; break;
}

$offset = ($page - 1) * $perPage;
$pastesResult = $db->query("SELECT * FROM pastes $where $orderBy LIMIT $offset, $perPage");
$totalResult = $db->query("SELECT COUNT(*) as count FROM pastes $where");
$totalFiltered = $totalResult->fetch_assoc()['count'];
$totalPages = ceil($totalFiltered / $perPage);

$currentAdmin = getAdminUser();

// اصلاح شده - تابع نمایش پیش‌نمایش با دیکود صحیح
function getPastePreview($id) {
    $db = getDB();
    $result = $db->query("SELECT content, has_password FROM pastes WHERE id = '" . $db->real_escape_string($id) . "'");
    $paste = $result->fetch_assoc();
    if (!$paste) return '[Not found]';
    if ($paste['has_password']) return '[🔒 Encrypted]';
    
    $content = $paste['content'];
    
    // دیکود base64
    $decoded = base64_decode($content, true);
    if ($decoded !== false && strlen($decoded) > 0) {
        // بررسی اینکه آیا دوباره base64 است
        $doubleDecoded = base64_decode($decoded, true);
        if ($doubleDecoded !== false && strlen($doubleDecoded) > 0 && strlen($doubleDecoded) < strlen($decoded)) {
            $content = $doubleDecoded;
        } else {
            $content = $decoded;
        }
    }
    
    // محدود کردن طول پیش‌نمایش
    if (strlen($content) > 150) {
        return htmlspecialchars(substr($content, 0, 150)) . '...';
    }
    return htmlspecialchars($content);
}

function formatDate($timestamp) {
    if (!$timestamp) return 'Never';
    return date('Y-m-d H:i:s', $timestamp);
}

function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $scriptPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $protocol . $domain . $scriptPath;
}

function showLoginForm($error = '') {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login · hilog</title>
<link rel="stylesheet" href="<?php echo getBaseUrl(); ?>/admin-style.css?v=3.1">
<style>
body{background:linear-gradient(135deg,#0a0c10 0%,#1a1c2c 100%);display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-container{max-width:400px;width:100%;padding:2rem}
.login-card{background:rgba(15,23,42,0.8);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.1);border-radius:28px;padding:2rem}
.login-header{text-align:center;margin-bottom:2rem}
.login-icon{font-size:3rem;margin-bottom:1rem}
.login-header h1{font-size:1.5rem;color:#f1f5f9;margin-bottom:0.5rem}
.login-header p{font-size:0.875rem;color:#94a3b8}
.form-group{margin-bottom:1rem}
label{display:block;margin-bottom:0.5rem;font-size:0.75rem;color:#94a3b8}
input{width:100%;padding:0.75rem;background:rgba(0,0,0,0.3);border:1px solid rgba(255,255,255,0.1);border-radius:12px;color:#f1f5f9}
input:focus{outline:none;border-color:#10b981}
button{width:100%;padding:0.75rem;background:linear-gradient(135deg,#10b981,#3b82f6);border:none;border-radius:40px;color:white;font-weight:600;cursor:pointer;margin-top:0.5rem}
.error{background:rgba(239,68,68,0.1);border:1px solid #ef4444;color:#ef4444;padding:0.75rem;border-radius:12px;margin-bottom:1rem}
</style>
</head>
<body>
<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <div class="login-icon">🔐</div>
            <h1>Admin Login</h1>
            <p>Enter credentials to access dashboard</p>
        </div>
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" name="login">Login →</button>
        </form>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

function showPasteViewer($id, $content) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Paste · hilog Admin</title>
<link rel="stylesheet" href="<?php echo getBaseUrl(); ?>/admin-style.css?v=3.1">
<style>
body{background:#0a0c10;padding:2rem}
.container{max-width:1000px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;padding-bottom:1rem;border-bottom:1px solid rgba(255,255,255,0.1)}
h1{font-size:1.5rem}
.back-btn{background:rgba(59,130,246,0.2);color:#60a5fa;padding:0.5rem 1rem;border-radius:40px;text-decoration:none}
.content-box{background:#1e293b;border-radius:20px;padding:2rem;overflow-x:auto}
pre{font-family:monospace;font-size:0.875rem;line-height:1.6;white-space:pre-wrap;word-wrap:break-word}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📄 Paste: <?php echo htmlspecialchars($id); ?></h1>
        <a href="admin.php" class="back-btn">← Back</a>
    </div>
    <div class="content-box">
        <pre><?php echo $content; ?></pre>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

// Dashboard HTML
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard · hilog</title>
<link rel="stylesheet" href="<?php echo getBaseUrl(); ?>/admin-style.css?v=3.1">
</head>
<body>

<header class="admin-header">
    <div class="admin-header-container">
        <a href="admin.php" class="admin-logo">
            <span>📊</span>
            <span class="admin-logo-text">hilog Admin</span>
            <span class="admin-logo-badge">v3.1</span>
        </a>
        <div class="admin-nav">
            <a href="<?php echo getBaseUrl(); ?>/" class="admin-nav-link" target="_blank">View Site →</a>
            <a href="?logout=1" class="admin-logout-btn">Logout</a>
        </div>
    </div>
</header>

<main class="admin-main">
    <!-- Stats -->
    <div class="admin-stats-grid">
        <div class="admin-stat-card"><div class="admin-stat-icon">📄</div><div class="admin-stat-value"><?php echo $totalPastes; ?></div><div class="admin-stat-label">Total Pastes</div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">👁️</div><div class="admin-stat-value"><?php echo $totalViews; ?></div><div class="admin-stat-label">Total Views</div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">🔒</div><div class="admin-stat-value"><?php echo $passwordProtected; ?></div><div class="admin-stat-label">Protected</div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">⚠️</div><div class="admin-stat-value"><?php echo $totalAttempts; ?></div><div class="admin-stat-label">Failed Attempts</div></div>
        <div class="admin-stat-card"><div class="admin-stat-icon">⏰</div><div class="admin-stat-value"><?php echo $expiredCount; ?></div><div class="admin-stat-label">Expired</div></div>
    </div>
    
    <!-- Settings -->
    <div class="admin-settings-card">
        <h3>⚙️ Admin Settings</h3>
        <?php if (isset($success)): ?>
            <div class="admin-success">✓ <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): foreach($errors as $err): ?>
            <div class="admin-error">⚠️ <?php echo $err; ?></div>
        <?php endforeach; endif; ?>
        <form method="POST" class="admin-settings-form">
            <div>
                <label>Username</label>
                <input type="text" name="username" value="<?php echo htmlspecialchars($currentAdmin['username'] ?? ''); ?>" placeholder="New username">
            </div>
            <div>
                <label>New Password</label>
                <input type="password" name="password" placeholder="Leave empty to keep current">
            </div>
            <div>
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" placeholder="Confirm new password">
            </div>
            <div class="full-width">
                <button type="submit" name="update_settings">Update Settings</button>
            </div>
        </form>
    </div>
    
    <!-- Controls -->
    <div class="admin-controls-bar">
        <form method="GET" class="admin-search-box">
            <input type="text" name="search" placeholder="Search by ID..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit">Search</button>
            <?php if (!empty($search)): ?>
                <a href="admin.php" class="admin-clear-btn">Clear</a>
            <?php endif; ?>
        </form>
        <select class="admin-select" onchange="window.location.href=this.value">
            <option value="?sort=created_desc&per_page=<?php echo $perPage; ?>&search=<?php echo urlencode($search); ?>" <?php echo $sort == 'created_desc' ? 'selected' : ''; ?>>📅 Newest First</option>
            <option value="?sort=created_asc&per_page=<?php echo $perPage; ?>&search=<?php echo urlencode($search); ?>" <?php echo $sort == 'created_asc' ? 'selected' : ''; ?>>📅 Oldest First</option>
            <option value="?sort=expires_asc&per_page=<?php echo $perPage; ?>&search=<?php echo urlencode($search); ?>" <?php echo $sort == 'expires_asc' ? 'selected' : ''; ?>>⏰ Expiring Soon</option>
            <option value="?sort=views_desc&per_page=<?php echo $perPage; ?>&search=<?php echo urlencode($search); ?>" <?php echo $sort == 'views_desc' ? 'selected' : ''; ?>>👁️ Most Viewed</option>
        </select>
        <select class="admin-select" onchange="window.location.href=this.value">
            <option value="?per_page=10&sort=<?php echo $sort; ?>&search=<?php echo urlencode($search); ?>" <?php echo $perPage == 10 ? 'selected' : ''; ?>>10 per page</option>
            <option value="?per_page=20&sort=<?php echo $sort; ?>&search=<?php echo urlencode($search); ?>" <?php echo $perPage == 20 ? 'selected' : ''; ?>>20 per page</option>
            <option value="?per_page=50&sort=<?php echo $sort; ?>&search=<?php echo urlencode($search); ?>" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50 per page</option>
            <option value="?per_page=100&sort=<?php echo $sort; ?>&search=<?php echo urlencode($search); ?>" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100 per page</option>
        </select>
    </div>
    
    <!-- Table -->
    <div class="admin-table-container">
        <table class="admin-table">
            <thead>
                <tr><th>ID</th><th>Preview</th><th>Status</th><th>Views</th><th>Attempts</th><th>Created</th><th>Expires</th><th>Actions</th> </thead>
            <tbody>
                <?php while($paste = $pastesResult->fetch_assoc()): 
                    $isExpired = $paste['expires_at'] !== null && $paste['expires_at'] < time();
                    $statusBadge = $isExpired ? 'admin-badge-expired' : ($paste['has_password'] ? 'admin-badge-protected' : 'admin-badge-public');
                    $statusText = $isExpired ? 'Expired' : ($paste['has_password'] ? 'Protected' : 'Public');
                ?>
                <tr>
                    <td><a href="<?php echo getBaseUrl() . '/' . $paste['id']; ?>" target="_blank" class="admin-paste-id"><?php echo $paste['id']; ?></a> </td>
                    <td class="admin-paste-preview"><?php echo getPastePreview($paste['id']); ?> </td>
                    <td><span class="admin-badge <?php echo $statusBadge; ?>"><?php echo $statusText; ?></span> </td>
                    <td><?php echo $paste['views']; ?> </td>
                    <td><?php echo $paste['password_views']; ?> </td>
                    <td><?php echo formatDate($paste['created_at']); ?> </td>
                    <td><?php echo formatDate($paste['expires_at']); ?> </td>
                    <td class="admin-action-buttons">
                        <a href="?view=<?php echo $paste['id']; ?>" class="admin-btn-view">View</a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this paste?')">
                            <input type="hidden" name="paste_id" value="<?php echo $paste['id']; ?>">
                            <button type="submit" name="delete_paste" class="admin-btn-delete">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if ($pastesResult->num_rows == 0): ?>
                <tr><td colspan="8" style="text-align:center;padding:3rem">No pastes found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="admin-pagination">
        <?php if ($page > 1): ?>
            <a href="?page=1&per_page=<?php echo $perPage; ?>&sort=<?php echo $sort; ?>&search=<?php echo urlencode($search); ?>">« First</a>
            <a href="?page=<?php echo $page-1; ?>&per_page=<?php echo $perPage; ?>&sort=<?php echo $sort; ?>&search=<?php echo urlencode($search); ?>">‹ Prev</a>
        <?php else: ?>
            <span class="disabled">« First</span>
            <span class="disabled">‹ Prev</span>
        <?php endif; ?>
        <?php for($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
            <?php if($i == $page): ?>
                <span class="current"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="?page=<?php echo $i; ?>&per_page=<?php echo $perPage; ?>&sort=<?php echo $sort; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page+1; ?>&per_page=<?php echo $perPage; ?>&sort=<?php echo $sort; ?>&search=<?php echo urlencode($search); ?>">Next ›</a>
            <a href="?page=<?php echo $totalPages; ?>&per_page=<?php echo $perPage; ?>&sort=<?php echo $sort; ?>&search=<?php echo urlencode($search); ?>">Last »</a>
        <?php else: ?>
            <span class="disabled">Next ›</span>
            <span class="disabled">Last »</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>

<footer class="admin-footer">
    <p>hilog Admin Panel v3.1 · Database Edition</p>
</footer>

<?php if (isset($_GET['deleted'])): ?>
<div class="admin-toast">✓ Paste deleted</div>
<script>setTimeout(()=>{document.querySelector('.admin-toast')?.remove()},3000);</script>
<?php endif; ?>
</body>
</html>
