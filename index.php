<?php
/**
 * hilog Pastebin - Version 20.3
 * Auto-delete expired pastes on every load
 */

require_once __DIR__ . '/config.php';

session_start();

// Security headers
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

error_reporting(0);
ini_set('display_errors', 0);
ini_set('zlib.output_compression', 'On');

function getClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return hash('sha256', $ip . ($_SERVER['HTTP_USER_AGENT'] ?? '') . SALT);
}

// ============================================
// CLEAN EXPIRED PASTES - RUNS ON EVERY REQUEST
// ============================================
function cleanExpiredPastes() {
    $db = getDB();
    $currentTime = time();
    
    // حذف پیست‌های منقضی شده
    $result = $db->query("SELECT id FROM pastes WHERE expires_at IS NOT NULL AND expires_at < $currentTime");
    $expiredCount = $result->num_rows;
    
    if ($expiredCount > 0) {
        $db->query("DELETE FROM pastes WHERE expires_at IS NOT NULL AND expires_at < $currentTime");
        // می‌توانید لاگ یا اعلان اضافه کنید
        // error_log("Cleaned $expiredCount expired pastes");
    }
    
    return $expiredCount;
}

// حذف رکوردهای قدیمی rate limit (هر 10 بار یکبار اجرا شود)
function cleanOldRateLimits() {
    $db = getDB();
    $currentTime = time();
    $db->query("DELETE FROM rate_limits WHERE timestamp < " . ($currentTime - 7200));
}

// اجرای حذف در هر بار لود سایت
cleanExpiredPastes();

// اجرای پاکسازی rate limit با 10% شانس
if (rand(1, 10) === 1) {
    cleanOldRateLimits();
}

function checkRateLimit($pasteId) {
    $db = getDB();
    $clientIp = getClientIp();
    $currentTime = time();
    
    $db->query("DELETE FROM rate_limits WHERE timestamp < " . ($currentTime - ATTEMPT_WINDOW * 2));
    
    $pasteKey = "paste_{$pasteId}_{$clientIp}";
    $result = $db->query("SELECT attempts, blocked_until FROM rate_limits WHERE key_name = '" . $db->real_escape_string($pasteKey) . "'");
    $row = $result->fetch_assoc();
    
    if ($row) {
        if ($row['blocked_until'] > $currentTime) {
            $remaining = ceil(($row['blocked_until'] - $currentTime) / 60);
            return ['allowed' => false, 'message' => "Too many attempts. Try again in {$remaining} minute" . ($remaining > 1 ? 's' : '') . "."];
        }
        
        if ($row['blocked_until'] > 0 && $row['blocked_until'] <= $currentTime) {
            $db->query("DELETE FROM rate_limits WHERE key_name = '" . $db->real_escape_string($pasteKey) . "'");
            $row = null;
        }
        elseif ($row['attempts'] >= MAX_ATTEMPTS_PER_PASTE) {
            $newBlockUntil = $currentTime + BLOCK_DURATION;
            $db->query("UPDATE rate_limits SET blocked_until = $newBlockUntil, attempts = attempts + 1, timestamp = $currentTime WHERE key_name = '" . $db->real_escape_string($pasteKey) . "'");
            $remaining = ceil(BLOCK_DURATION / 60);
            return ['allowed' => false, 'message' => "Too many attempts. Try again in {$remaining} minute" . ($remaining > 1 ? 's' : '') . "."];
        }
    }
    
    $globalKey = "global_{$clientIp}";
    $result = $db->query("SELECT attempts, blocked_until FROM rate_limits WHERE key_name = '" . $db->real_escape_string($globalKey) . "'");
    $row = $result->fetch_assoc();
    
    if ($row) {
        if ($row['blocked_until'] > $currentTime) {
            $remaining = ceil(($row['blocked_until'] - $currentTime) / 60);
            return ['allowed' => false, 'message' => "Too many attempts from your IP. Try again in {$remaining} minute" . ($remaining > 1 ? 's' : '') . "."];
        }
        
        if ($row['blocked_until'] > 0 && $row['blocked_until'] <= $currentTime) {
            $db->query("DELETE FROM rate_limits WHERE key_name = '" . $db->real_escape_string($globalKey) . "'");
            $row = null;
        }
        elseif ($row['attempts'] >= MAX_ATTEMPTS_PER_IP) {
            $newBlockUntil = $currentTime + BLOCK_DURATION;
            $db->query("UPDATE rate_limits SET blocked_until = $newBlockUntil, attempts = attempts + 1, timestamp = $currentTime WHERE key_name = '" . $db->real_escape_string($globalKey) . "'");
            $remaining = ceil(BLOCK_DURATION / 60);
            return ['allowed' => false, 'message' => "Too many attempts from your IP. Try again in {$remaining} minute" . ($remaining > 1 ? 's' : '') . "."];
        }
    }
    
    return ['allowed' => true, 'message' => ''];
}

function recordFailedAttempt($pasteId) {
    $db = getDB();
    $clientIp = getClientIp();
    $currentTime = time();
    
    $pasteKey = "paste_{$pasteId}_{$clientIp}";
    $result = $db->query("SELECT attempts, blocked_until FROM rate_limits WHERE key_name = '" . $db->real_escape_string($pasteKey) . "'");
    $row = $result->fetch_assoc();
    
    if ($row) {
        if ($row['blocked_until'] > 0 && $row['blocked_until'] <= $currentTime) {
            $db->query("DELETE FROM rate_limits WHERE key_name = '" . $db->real_escape_string($pasteKey) . "'");
            $db->query("INSERT INTO rate_limits (key_name, attempts, timestamp, blocked_until) VALUES ('" . $db->real_escape_string($pasteKey) . "', 1, $currentTime, 0)");
        } 
        elseif ($row['blocked_until'] <= $currentTime) {
            $db->query("UPDATE rate_limits SET attempts = attempts + 1, timestamp = $currentTime WHERE key_name = '" . $db->real_escape_string($pasteKey) . "'");
        }
    } else {
        $db->query("INSERT INTO rate_limits (key_name, attempts, timestamp, blocked_until) VALUES ('" . $db->real_escape_string($pasteKey) . "', 1, $currentTime, 0)");
    }
    
    $globalKey = "global_{$clientIp}";
    $result = $db->query("SELECT attempts, blocked_until FROM rate_limits WHERE key_name = '" . $db->real_escape_string($globalKey) . "'");
    $row = $result->fetch_assoc();
    
    if ($row) {
        if ($row['blocked_until'] > 0 && $row['blocked_until'] <= $currentTime) {
            $db->query("DELETE FROM rate_limits WHERE key_name = '" . $db->real_escape_string($globalKey) . "'");
            $db->query("INSERT INTO rate_limits (key_name, attempts, timestamp, blocked_until) VALUES ('" . $db->real_escape_string($globalKey) . "', 1, $currentTime, 0)");
        } 
        elseif ($row['blocked_until'] <= $currentTime) {
            $db->query("UPDATE rate_limits SET attempts = attempts + 1, timestamp = $currentTime WHERE key_name = '" . $db->real_escape_string($globalKey) . "'");
        }
    } else {
        $db->query("INSERT INTO rate_limits (key_name, attempts, timestamp, blocked_until) VALUES ('" . $db->real_escape_string($globalKey) . "', 1, $currentTime, 0)");
    }
}

function generateLetterId() {
    $db = getDB();
    do {
        $length = rand(4, 6);
        $id = '';
        $characters = 'abcdefghijklmnopqrstuvwxyz';
        for ($i = 0; $i < $length; $i++) {
            $id .= $characters[rand(0, strlen($characters) - 1)];
        }
        $result = $db->query("SELECT id FROM pastes WHERE id = '" . $db->real_escape_string($id) . "'");
    } while ($result->num_rows > 0);
    return $id;
}

function validatePassword($password) {
    if (empty($password)) return true;
    if (strlen($password) < 3) return false;
    if (strlen($password) > 100) return false;
    return true;
}

function sanitizeForDisplay($content) {
    return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
}

function deletePaste($id) {
    $db = getDB();
    $db->query("DELETE FROM pastes WHERE id = '" . $db->real_escape_string($id) . "'");
    return $db->affected_rows > 0;
}

$path = '';
if (isset($_GET['paste']) && !empty($_GET['paste'])) {
    $path = strtolower(trim($_GET['paste']));
} else {
    $requestUri = $_SERVER['REQUEST_URI'];
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $scriptPath = rtrim(dirname($scriptName), '/');
    $path = trim(str_replace($scriptPath, '', $requestUri), '/');
    if (($pos = strpos($path, '?')) !== false) {
        $path = substr($path, 0, $pos);
    }
    $path = strtolower($path);
    $path = str_replace('index.php', '', $path);
    $path = trim($path, '/');
}

function getBaseUrl() {
    static $baseUrl = null;
    if ($baseUrl !== null) return $baseUrl;
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $scriptPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $baseUrl = $protocol . $domain . $scriptPath;
    return $baseUrl;
}

if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $deleteId = strtolower(trim($_GET['delete']));
    if (preg_match('/^[a-z]{4,6}$/', $deleteId)) {
        if (deletePaste($deleteId)) {
            header('Location: ' . getBaseUrl() . '/?deleted=1');
            exit;
        }
    }
    header('Location: ' . getBaseUrl() . '/?error=Delete failed');
    exit;
}

if (empty($path)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
        createPaste();
    } else {
        showHomePage(isset($_GET['deleted']));
    }
} else {
    if (preg_match('/^[a-z]{4,6}$/', $path)) {
        viewPaste($path);
    } else {
        header('Location: ' . getBaseUrl() . '/');
        exit;
    }
}

function createPaste() {
    $content = trim($_POST['content'] ?? '');
    $password = $_POST['password'] ?? '';
    $expiration = (int)($_POST['expiration'] ?? 0);
    $db = getDB();
    
    if (empty($content)) {
        header('Location: ' . getBaseUrl() . '/?error=' . urlencode('Paste content cannot be empty'));
        exit;
    }
    if (strlen($content) > 1048576) {
        header('Location: ' . getBaseUrl() . '/?error=' . urlencode('Content too large (max 1MB)'));
        exit;
    }
    if (!validatePassword($password)) {
        header('Location: ' . getBaseUrl() . '/?error=' . urlencode('Password must be at least 3 characters'));
        exit;
    }
    
    $id = generateLetterId();
    $expiresAt = $expiration > 0 ? time() + $expiration : null;
    $hasPassword = !empty($password);
    $passwordHash = !empty($password) ? hash('sha256', $password) : null;
    $createdAt = time();
    
    if (!empty($password)) {
        $key = hash_pbkdf2('sha256', $password, $id, 10000, 32, true);
        $iv = random_bytes(12);
        $encrypted = openssl_encrypt($content, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        $content = base64_encode($iv . $tag . $encrypted);
    } else {
        $content = sanitizeForDisplay($content);
    }
    
    $stmt = $db->prepare("INSERT INTO pastes (id, content, has_password, password_hash, created_at, expires_at, views, password_views) VALUES (?, ?, ?, ?, ?, ?, 0, 0)");
    $stmt->bind_param("ssissi", $id, $content, $hasPassword, $passwordHash, $createdAt, $expiresAt);
    $stmt->execute();
    
    showCreatedPaste($id);
}

function viewPaste($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM pastes WHERE id = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $paste = $result->fetch_assoc();
    
    if (!$paste) {
        header('Location: ' . getBaseUrl() . '/?error=Paste not found');
        exit;
    }
    
    if ($paste['expires_at'] !== null && $paste['expires_at'] < time()) {
        $db->query("DELETE FROM pastes WHERE id = '" . $db->real_escape_string($id) . "'");
        header('Location: ' . getBaseUrl() . '/?error=Paste expired');
        exit;
    }
    
    $content = $paste['content'];
    
    if ($paste['has_password']) {
        $rateLimit = checkRateLimit($id);
        if (!$rateLimit['allowed']) {
            showRateLimitError($rateLimit['message']);
            return;
        }
        
        $db->query("UPDATE pastes SET password_views = password_views + 1 WHERE id = '" . $db->real_escape_string($id) . "'");
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
            $enteredPassword = $_POST['password'];
            
            if (hash('sha256', $enteredPassword) === $paste['password_hash']) {
                $key = hash_pbkdf2('sha256', $enteredPassword, $id, 10000, 32, true);
                $data = base64_decode($content);
                $iv = substr($data, 0, 12);
                $tag = substr($data, 12, 16);
                $encrypted = substr($data, 28);
                $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                
                if ($decrypted !== false) {
                    $content = sanitizeForDisplay($decrypted);
                    $db->query("UPDATE pastes SET views = views + 1 WHERE id = '" . $db->real_escape_string($id) . "'");
                    showPasteContent($id, $content, $paste);
                    return;
                }
            }
            recordFailedAttempt($id);
            showPasswordForm($id, 'Incorrect password');
            return;
        }
        showPasswordForm($id, '');
        return;
    }
    
    $db->query("UPDATE pastes SET views = views + 1 WHERE id = '" . $db->real_escape_string($id) . "'");
    showPasteContent($id, $content, $paste);
}

// ============================================
// توابع نمایش (همانند نسخه قبل)
// ============================================

function showHomePage($deleted = false) {
    $error = isset($_GET['error']) ? htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') : '';
    $baseUrl = getBaseUrl();
    
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>hilog · Secure Pastebin</title>
<link rel="stylesheet" href="' . $baseUrl . '/style.css?v=20.3">
</head>
<body>
<div class="noise"></div>
<div class="gradient-bg"></div>

<header class="header">
    <div class="header-container">
        <a href="' . $baseUrl . '/" class="logo">
            <span class="logo-icon">✨</span>
            <span class="logo-text">hilog</span>
            <span class="logo-badge">v20.3</span>
        </a>
        <nav class="nav">
            <a href="' . $baseUrl . '/" class="nav-btn">+ Create</a>
        </nav>
    </div>
</header>

<main class="main">';
    
    if ($error) {
        echo '<div class="toast-notification show">⚠️ ' . $error . '</div>';
    }
    if ($deleted) {
        echo '<div class="toast-success show">🗑️ Paste deleted successfully</div>';
    }
    
    echo '<div class="hero">
        <div class="hero-badge"><span class="badge-dot"></span> Auto-Expire · Delete Your Pastes</div>
        <h1 class="hero-title">Share code<br>in <span class="gradient-text">seconds</span></h1>
        <p class="hero-desc">Clean URLs with only letters (a-z). Secure, encrypted, and private. Expired pastes are automatically deleted.</p>
    </div>
    
    <div class="form-wrapper">
        <div class="form-card glass">
            <form method="POST" action="' . $baseUrl . '/">
                <div class="form-group">
                    <div class="input-header"><span class="input-icon">📝</span><label>Content</label></div>
                    <textarea name="content" class="code-input" required placeholder="Paste your text, code, or notes here..."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <div class="input-header"><span class="input-icon">🔒</span><label>Password (min. 3 characters)</label></div>
                        <input type="password" name="password" class="input-modern" placeholder="Leave empty for no password">
                        <small class="input-hint">Password must be at least 3 characters if set</small>
                    </div>
                    <div class="form-group">
                        <div class="input-header"><span class="input-icon">⏱️</span><label>Expires</label></div>
                        <select name="expiration" class="select-modern">
                            <option value="3600">1 hour</option>
                            <option value="21600">6 hours</option>
                            <option value="43200">12 hours</option>
                            <option value="86400" selected>1 day</option>
                            <option value="604800">1 week</option>
                            <option value="2592000">1 month</option>
                            <option value="15552000">6 months</option>
                            <option value="31536000">1 year</option>
                            <option value="0">Permanent</option>
                        </select>
                    </div>
                </div>
                <button type="submit" name="create" class="btn-primary">
                    <span>Create Paste</span>
                    <span class="btn-arrow">→</span>
                </button>
            </form>
        </div>
    </div>
    
    <div class="info-section">
        <div class="section-header">
            <h2>How it works</h2>
            <p>Simple, secure, private — no account needed</p>
        </div>
        
        <div class="info-grid">
            <div class="info-card">
                <div class="info-icon">📝</div>
                <h3>Create & Share</h3>
                <p>Paste your text, set an optional password, choose expiration time. Get a short, clean URL with 4-6 random lowercase letters.</p>
            </div>
            <div class="info-card">
                <div class="info-icon">🔒</div>
                <h3>End-to-End Encryption</h3>
                <p>Pastes with passwords are encrypted using AES-256-GCM before storage. Only those with the correct password can view the content.</p>
            </div>
            <div class="info-card">
                <div class="info-icon">🗑️</div>
                <h3>Auto-Expiration</h3>
                <p>Pastes are automatically deleted when they expire. No leftover data, no manual cleanup needed.</p>
            </div>
            <div class="info-card">
                <div class="info-icon">🛡️</div>
                <h3>Brute Force Protection</h3>
                <p>Rate limiting: 10 attempts per paste, 30 attempts per IP per hour. Blocks for 15 minutes after limit reached. Auto-reset after block expires.</p>
            </div>
            <div class="info-card">
                <div class="info-icon">🔗</div>
                <h3>Clean URLs</h3>
                <p>URLs use only lowercase letters (a-z), 4-6 characters long. Case-insensitive — hilog, HILOG, and HiLoG all work the same.</p>
            </div>
            <div class="info-card">
                <div class="info-icon">🚫</div>
                <h3>Zero Tracking</h3>
                <p>No analytics, no cookies, no third-party requests. Your data never leaves this server. Complete privacy by design.</p>
            </div>
        </div>
        
        <div class="security-box">
            <div class="security-header">
                <span class="security-icon">🔐</span>
                <h3>Security & Privacy Features</h3>
            </div>
            <div class="security-content">
                <div class="security-item"><strong>Encryption:</strong> AES-256-GCM for password-protected pastes</div>
                <div class="security-item"><strong>Password Storage:</strong> Only SHA-256 hashes, never plain text</div>
                <div class="security-item"><strong>Key Derivation:</strong> PBKDF2 with 10,000 iterations</div>
                <div class="security-item"><strong>Rate Limiting:</strong> 10 attempts per paste, 30 per IP/hour</div>
                <div class="security-item"><strong>Auto-Expire:</strong> Pastes deleted immediately after expiration</div>
                <div class="security-item"><strong>Block Duration:</strong> 15 minutes after limit reached (auto-reset)</div>
                <div class="security-item"><strong>Delete Feature:</strong> Instant paste deletion after viewing</div>
                <div class="security-item"><strong>IP Hashing:</strong> Client IPs are hashed for privacy</div>
            </div>
        </div>
        
        <div class="footer-note">
            <p>✨ Open source · No accounts · No tracking · Auto-expire · Brute force protected · Your data, your control</p>
        </div>
    </div>
</main>

<footer class="footer">
    <p>hilog · v20.3 · auto-expire · delete your pastes · brute force protected · letters only URLs</p>
</footer>

<script>
setTimeout(() => {
    document.querySelectorAll(".toast-notification, .toast-success").forEach(toast => {
        toast.classList.remove("show");
        setTimeout(() => toast.remove(), 300);
    });
}, 4000);
</script>
</body>
</html>';
}

// توابع دیگر (showCreatedPaste, showRateLimitError, showPasswordForm, showPasteContent) 
// مانند نسخه قبلی باقی می‌مانند

function showCreatedPaste($id) {
    $baseUrl = getBaseUrl();
    $fullUrl = $baseUrl . '/' . $id;
    
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Paste Created · hilog</title>
<link rel="stylesheet" href="' . $baseUrl . '/style.css?v=20.3">
</head>
<body>
<div class="noise"></div>
<div class="gradient-bg"></div>

<header class="header">
    <div class="header-container">
        <a href="' . $baseUrl . '/" class="logo">
            <span class="logo-icon">✨</span>
            <span class="logo-text">hilog</span>
            <span class="logo-badge">v20.3</span>
        </a>
        <nav class="nav">
            <a href="' . $baseUrl . '/" class="nav-btn">+ Create</a>
        </nav>
    </div>
</header>

<main class="main">
    <div class="success-card glass">
        <div class="success-icon">🎉</div>
        <h2 class="success-title">Paste created!</h2>
        <p class="success-desc">Your secure link is ready to share</p>
        
        <div class="url-container">
            <div class="url-box">
                <div class="url" id="link">' . htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') . '</div>
                <button class="copy-button" onclick="copyLink()">
                    <span>📋</span> Copy
                </button>
            </div>
        </div>
        
        <div class="action-group">
            <a href="' . htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') . '" class="btn-primary-outline">
                <span>🔗</span> View Paste
            </a>
            <a href="' . $baseUrl . '/" class="btn-secondary">
                <span>✨</span> Create Another
            </a>
        </div>
        
        <div class="paste-tip">
            <span>💡</span>
            <small>Case-insensitive · ' . $id . ', ' . strtoupper($id) . ', or ' . ucfirst($id) . ' all work</small>
        </div>
        <div class="paste-tip" style="margin-top: 0.5rem;">
            <span>🗑️</span>
            <small>You can delete this paste after viewing it, or it will expire automatically</small>
        </div>
    </div>
</main>

<footer class="footer">
    <p>hilog · v20.3 · auto-expire</p>
</footer>

<script>
function copyLink() {
    navigator.clipboard.writeText(document.getElementById("link").innerText).then(() => {
        let toast = document.createElement("div");
        toast.className = "toast-success";
        toast.innerHTML = "✓ Copied!";
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    });
}
</script>
</body>
</html>';
    exit;
}

function showRateLimitError($message) {
    $baseUrl = getBaseUrl();
    
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rate Limited · hilog</title>
<link rel="stylesheet" href="' . $baseUrl . '/style.css?v=20.3">
</head>
<body class="password-page">
<div class="noise"></div>

<header class="header">
    <div class="header-container">
        <a href="' . $baseUrl . '/" class="logo">
            <span class="logo-icon">✨</span>
            <span class="logo-text">hilog</span>
            <span class="logo-badge">v20.3</span>
        </a>
        <nav class="nav">
            <a href="' . $baseUrl . '/" class="nav-btn">+ Create</a>
        </nav>
    </div>
</header>

<main class="main password-main">
    <div class="password-card glass">
        <div class="lock-ring" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
            <div class="lock-icon">⚠️</div>
        </div>
        <h2 class="password-title">Rate Limit Exceeded</h2>
        <p class="password-desc">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
        <div class="security-hint">
            <small>This protects against brute force attacks. The block will automatically expire after 15 minutes and you can try again.</small>
        </div>
        <div style="margin-top: 1.5rem;">
            <a href="' . $baseUrl . '/" class="btn-unlock" style="display: inline-block; text-decoration: none;">← Back to Home</a>
        </div>
    </div>
</main>

<footer class="footer">
    <p>hilog · v20.3 · auto-reset after 15 minutes</p>
</footer>
</body>
</html>';
    exit;
}

function showPasswordForm($id, $error) {
    $baseUrl = getBaseUrl();
    
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Protected · hilog</title>
<link rel="stylesheet" href="' . $baseUrl . '/style.css?v=20.3">
</head>
<body class="password-page">
<div class="noise"></div>

<header class="header">
    <div class="header-container">
        <a href="' . $baseUrl . '/" class="logo">
            <span class="logo-icon">✨</span>
            <span class="logo-text">hilog</span>
            <span class="logo-badge">v20.3</span>
        </a>
        <nav class="nav">
            <a href="' . $baseUrl . '/" class="nav-btn">+ Create</a>
        </nav>
    </div>
</header>

<main class="main password-main">
    <div class="password-card glass">
        <div class="lock-ring">
            <div class="lock-icon">🔒</div>
        </div>
        <h2 class="password-title">Protected content</h2>
        <p class="password-desc">This paste is encrypted with AES-256-GCM</p>';
        
        if ($error) {
            echo '<div class="error-badge">⚠️ ' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        
        echo '<form method="POST" class="password-form" action="' . $baseUrl . '/' . $id . '">
            <input type="password" name="password" class="password-field" placeholder="Enter password" autofocus>
            <button type="submit" class="btn-unlock">
                <span>🔓</span> Unlock paste
            </button>
        </form>
        <div class="security-hint">
            <small>🔐 End-to-end encrypted. Only the correct password can decrypt.</small>
            <small style="display: block; margin-top: 0.5rem;">⚠️ 10 attempts allowed per paste · Block resets after 15 minutes</small>
        </div>
    </div>
</main>

<footer class="footer">
    <p>hilog · v20.3 · brute force protected</p>
</footer>
</body>
</html>';
    exit;
}

function showPasteContent($id, $content, $metadata) {
    $baseUrl = getBaseUrl();
    $expText = $metadata['expires_at'] ? date('Y-m-d H:i', $metadata['expires_at']) : 'Never';
    
    header('Content-Type: text/html; charset=utf-8');
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Paste ' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . ' · hilog</title>
<link rel="stylesheet" href="' . $baseUrl . '/style.css?v=20.3">
</head>
<body>
<div class="noise"></div>
<div class="gradient-bg"></div>

<header class="header">
    <div class="header-container">
        <a href="' . $baseUrl . '/" class="logo">
            <span class="logo-icon">✨</span>
            <span class="logo-text">hilog</span>
            <span class="logo-badge">v20.3</span>
        </a>
        <nav class="nav">
            <a href="' . $baseUrl . '/" class="nav-btn">+ Create</a>
        </nav>
    </div>
</header>

<main class="main">
    <div class="paste-header glass">
        <div class="paste-id">
            <span class="id-label">Paste ID</span>
            <code class="id-code">' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '</code>
            <small class="case-note">(case-insensitive)</small>
        </div>
        <div class="paste-stats">
            <div class="stat-item">
                <span class="stat-icon">👁️</span>
                <span class="stat-value">' . intval($metadata['views'] ?? 0) . '</span>
                <span class="stat-label">views</span>
            </div>';
            if ($metadata['has_password']) {
                echo '<div class="stat-item">
                    <span class="stat-icon">🔒</span>
                    <span class="stat-value">' . intval($metadata['password_views'] ?? 0) . '</span>
                    <span class="stat-label">attempts</span>
                </div>';
            }
            echo '<div class="stat-item">
                <span class="stat-icon">⏱️</span>
                <span class="stat-value">' . htmlspecialchars($expText, ENT_QUOTES, 'UTF-8') . '</span>
                <span class="stat-label">expires</span>
            </div>
        </div>
    </div>
    
    <div class="content-card glass">
        <div class="content-header">
            <span class="content-title">📄 Content</span>
            <div class="action-buttons-group">
                <button class="copy-btn-modern" onclick="copyPaste()">
                    <span>📋</span> Copy
                </button>
                <button class="delete-btn-modern" onclick="confirmDelete(\'' . $id . '\')">
                    <span>🗑️</span> Delete
                </button>
            </div>
        </div>
        <pre class="code-block" id="pasteContent">' . $content . '</pre>
    </div>
    
    <div class="security-warning">
        <span>⚠️</span>
        <small>This paste will be automatically deleted after expiration or by manual deletion.</small>
    </div>
</main>

<footer class="footer">
    <p>hilog · v20.3 · auto-expire · ' . $id . '</p>
</footer>

<script>
function copyPaste() {
    navigator.clipboard.writeText(document.getElementById("pasteContent").innerText).then(() => {
        let toast = document.createElement("div");
        toast.className = "toast-success";
        toast.innerHTML = "✓ Copied!";
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    });
}

function confirmDelete(pasteId) {
    if (confirm("⚠️ Are you sure you want to delete this paste?\n\nThis action cannot be undone. The paste will be permanently removed.")) {
        window.location.href = "' . $baseUrl . '/?delete=" + pasteId;
    }
}
</script>
</body>
</html>';
    exit;
}
?>
