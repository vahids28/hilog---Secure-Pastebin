<?php
/**
 * hilog Installation Script
 * Version 20.0 - Database Setup
 */

$lockFile = __DIR__ . '/.installed';
if (file_exists($lockFile)) {
    die('<div style="text-align:center;padding:2rem;font-family:sans-serif;">⚠️ Installation already completed. Remove .installed file to reinstall.</div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = $_POST['db_host'] ?? 'localhost';
    $dbName = $_POST['db_name'] ?? '';
    $dbUser = $_POST['db_user'] ?? '';
    $dbPass = $_POST['db_password'] ?? '';
    $adminUser = $_POST['admin_user'] ?? 'admin';
    $adminPass = $_POST['admin_pass'] ?? '';
    $adminPassConfirm = $_POST['admin_pass_confirm'] ?? '';
    
    $errors = [];
    
    if (empty($dbName)) $errors[] = 'Database name is required';
    if (empty($dbUser)) $errors[] = 'Database user is required';
    if (empty($adminUser)) $errors[] = 'Admin username is required';
    if (empty($adminPass)) $errors[] = 'Admin password is required';
    if ($adminPass !== $adminPassConfirm) $errors[] = 'Passwords do not match';
    if (strlen($adminPass) < 6) $errors[] = 'Admin password must be at least 6 characters';
    
    if (empty($errors)) {
        $conn = new mysqli($dbHost, $dbUser, $dbPass);
        if ($conn->connect_error) {
            $errors[] = 'Database connection failed: ' . $conn->connect_error;
        } else {
            if (!$conn->select_db($dbName)) {
                $conn->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $conn->select_db($dbName);
            }
            
            $sql = "
            CREATE TABLE IF NOT EXISTS `pastes` (
                `id` VARCHAR(6) NOT NULL,
                `content` LONGTEXT NOT NULL,
                `has_password` TINYINT(1) NOT NULL DEFAULT 0,
                `password_hash` VARCHAR(255) DEFAULT NULL,
                `created_at` INT(11) NOT NULL,
                `expires_at` INT(11) DEFAULT NULL,
                `views` INT(11) NOT NULL DEFAULT 0,
                `password_views` INT(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                INDEX `idx_expires_at` (`expires_at`),
                INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            CREATE TABLE IF NOT EXISTS `rate_limits` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `key_name` VARCHAR(255) NOT NULL,
                `attempts` INT(11) NOT NULL DEFAULT 1,
                `timestamp` INT(11) NOT NULL,
                `blocked_until` INT(11) DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `key_name` (`key_name`),
                INDEX `idx_timestamp` (`timestamp`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            CREATE TABLE IF NOT EXISTS `admin_users` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `username` VARCHAR(50) NOT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `created_at` INT(11) NOT NULL,
                `last_login` INT(11) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            
            if ($conn->multi_query($sql)) {
                while ($conn->next_result()) {;}
                
                $passwordHash = password_hash($adminPass, PASSWORD_DEFAULT);
                $createdAt = time();
                $stmt = $conn->prepare("INSERT INTO admin_users (username, password_hash, created_at) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $adminUser, $passwordHash, $createdAt);
                $stmt->execute();
                
                $configContent = "<?php
/**
 * hilog Database Configuration
 * Generated on " . date('Y-m-d H:i:s') . "
 */

define('DB_HOST', '$dbHost');
define('DB_NAME', '$dbName');
define('DB_USER', '$dbUser');
define('DB_PASSWORD', '$dbPass');
define('SALT', '" . bin2hex(random_bytes(32)) . "');
define('MAX_ATTEMPTS_PER_PASTE', 10);
define('MAX_ATTEMPTS_PER_IP', 30);
define('BLOCK_DURATION', 900);
define('ATTEMPT_WINDOW', 3600);
define('SITE_NAME', 'hilog');
define('SITE_VERSION', '20.0');

function getDB() {
    static \$db = null;
    if (\$db === null) {
        try {
            \$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            if (\$db->connect_error) {
                die(\"Database connection failed\");
            }
            \$db->set_charset(\"utf8mb4\");
        } catch (Exception \$e) {
            die(\"Database connection error\");
        }
    }
    return \$db;
}
";
                file_put_contents(__DIR__ . '/config.php', $configContent);
                file_put_contents($lockFile, date('Y-m-d H:i:s'));
                $success = true;
            } else {
                $errors[] = 'Failed to create tables: ' . $conn->error;
            }
        }
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>hilog Installation</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:linear-gradient(135deg,#0a0c10,#1a1c2c);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
.container{max-width:500px;width:100%}
.card{background:rgba(15,23,42,0.8);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.1);border-radius:28px;padding:2rem}
h1{font-size:1.5rem;margin-bottom:0.5rem;color:#f1f5f9}
p{color:#94a3b8;margin-bottom:1.5rem;font-size:0.875rem}
.form-group{margin-bottom:1rem}
label{display:block;margin-bottom:0.5rem;font-size:0.75rem;font-weight:500;color:#94a3b8}
input{width:100%;padding:0.75rem;background:rgba(0,0,0,0.3);border:1px solid rgba(255,255,255,0.1);border-radius:12px;color:#f1f5f9;font-size:0.875rem}
input:focus{outline:none;border-color:#10b981}
button{width:100%;padding:0.75rem;background:linear-gradient(135deg,#10b981,#3b82f6);border:none;border-radius:40px;color:white;font-weight:600;cursor:pointer;margin-top:1rem}
button:hover{transform:translateY(-2px)}
.error{background:rgba(239,68,68,0.1);border:1px solid #ef4444;color:#ef4444;padding:0.75rem;border-radius:12px;margin-bottom:1rem;font-size:0.875rem}
.success{background:rgba(16,185,129,0.1);border:1px solid #10b981;color:#10b981;padding:0.75rem;border-radius:12px;margin-bottom:1rem;font-size:0.875rem}
hr{margin:1rem 0;border-color:rgba(255,255,255,0.1)}
</style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>🚀 hilog Installation</h1>
        <p>Set up your database and admin account</p>
        
        <?php if (isset($success) && $success): ?>
            <div class="success">
                ✓ Installation completed successfully!<br>
                <a href="index.php" style="color:#10b981;">Go to site →</a> | 
                <a href="admin.php" style="color:#10b981;">Go to admin panel →</a>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach($errors as $err): ?>
                    ⚠️ <?php echo htmlspecialchars($err); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!isset($success) || !$success): ?>
        <form method="POST">
            <h3 style="margin-bottom:1rem;font-size:1rem;">Database Configuration</h3>
            <div class="form-group">
                <label>Database Host</label>
                <input type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>Database Name</label>
                <input type="text" name="db_name" required>
            </div>
            <div class="form-group">
                <label>Database Username</label>
                <input type="text" name="db_user" required>
            </div>
            <div class="form-group">
                <label>Database Password</label>
                <input type="password" name="db_password">
            </div>
            
            <hr>
            
            <h3 style="margin-bottom:1rem;font-size:1rem;">Admin Account</h3>
            <div class="form-group">
                <label>Admin Username</label>
                <input type="text" name="admin_user" value="admin" required>
            </div>
            <div class="form-group">
                <label>Admin Password (min 6 characters)</label>
                <input type="password" name="admin_pass" required>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="admin_pass_confirm" required>
            </div>
            
            <button type="submit">Install hilog →</button>
        </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
