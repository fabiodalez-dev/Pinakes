<?php
/**
 * API Book Scraper Server - Simple Admin Interface
 */

declare(strict_types=1);

// Load environment
if (file_exists(__DIR__ . '/.env')) {
    $env = parse_ini_file(__DIR__ . '/.env');
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Check if admin is enabled
if (!($_ENV['ADMIN_ENABLED'] ?? true)) {
    http_response_code(403);
    die('Admin interface is disabled.');
}

// Simple authentication
session_start();

$adminUsername = $_ENV['ADMIN_USERNAME'] ?? 'admin';
$adminPasswordHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? '';

// Handle login
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === $adminUsername && password_verify($password, $adminPasswordHash)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $loginError = 'Invalid credentials.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Check if logged in
$isLoggedIn = $_SESSION['admin_logged_in'] ?? false;

// Autoloader for Database class
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Handle actions
if ($isLoggedIn) {
    // Create new API key
    if (isset($_POST['create_key'])) {
        $name = trim($_POST['name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (!empty($name)) {
            $newKey = Database::createApiKey($name, $notes);
            $successMessage = "API key created successfully: {$newKey}";
        }
    }

    // Delete API key
    if (isset($_GET['delete_key'])) {
        Database::deleteApiKey($_GET['delete_key']);
        header('Location: admin.php');
        exit;
    }

    // Toggle API key
    if (isset($_GET['toggle_key'])) {
        Database::toggleApiKey($_GET['toggle_key']);
        header('Location: admin.php');
        exit;
    }

    // Get data
    $apiKeys = Database::getApiKeys();
    $stats = Database::getStats(50, 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Book Scraper - Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        header {
            background: #fff;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 8px;
        }
        header h1 {
            font-size: 24px;
            color: #2c3e50;
        }
        header p {
            color: #7f8c8d;
            margin-top: 5px;
        }
        .logout {
            float: right;
            color: #e74c3c;
            text-decoration: none;
            font-weight: 500;
        }
        .logout:hover {
            text-decoration: underline;
        }
        .card {
            background: #fff;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 8px;
        }
        .card h2 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            background: #3498db;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #2980b9;
        }
        .btn-danger {
            background: #e74c3c;
        }
        .btn-danger:hover {
            background: #c0392b;
        }
        .btn-warning {
            background: #f39c12;
        }
        .btn-warning:hover {
            background: #e67e22;
        }
        .btn-sm {
            padding: 4px 8px;
            font-size: 12px;
        }
        input[type="text"], input[type="password"], textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            margin-bottom: 10px;
        }
        textarea {
            resize: vertical;
            min-height: 60px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #2c3e50;
        }
        .alert {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 3px;
            font-weight: 500;
        }
        .badge-success {
            background: #27ae60;
            color: #fff;
        }
        .badge-danger {
            background: #e74c3c;
            color: #fff;
        }
        .code {
            font-family: 'Courier New', monospace;
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
            word-break: break-all;
        }
        .login-form {
            max-width: 400px;
            margin: 100px auto;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-card h3 {
            font-size: 32px;
            margin-bottom: 5px;
        }
        .stat-card p {
            opacity: 0.9;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$isLoggedIn): ?>
            <!-- Login Form -->
            <div class="login-form card">
                <h2>Admin Login</h2>
                <?php if (isset($loginError)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($loginError) ?></div>
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
                    <button type="submit" name="login" class="btn">Login</button>
                </form>
            </div>
        <?php else: ?>
            <!-- Admin Dashboard -->
            <header>
                <a href="?logout" class="logout">Logout</a>
                <h1>API Book Scraper Server</h1>
                <p>Admin Dashboard</p>
            </header>

            <?php if (isset($successMessage)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>

            <!-- Statistics Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?= count($apiKeys) ?></h3>
                    <p>API Keys</p>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <h3><?= count(array_filter($apiKeys, fn($k) => $k['is_active'])) ?></h3>
                    <p>Active Keys</p>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <h3><?= array_sum(array_column($apiKeys, 'requests_count')) ?></h3>
                    <p>Total Requests</p>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <h3><?= count(array_filter($stats, fn($s) => $s['success'])) ?></h3>
                    <p>Successful Scrapes</p>
                </div>
            </div>

            <!-- Create New API Key -->
            <div class="card">
                <h2>Create New API Key</h2>
                <form method="POST">
                    <div class="form-group">
                        <label>Name *</label>
                        <input type="text" name="name" placeholder="e.g., Pinakes Production" required>
                    </div>
                    <div class="form-group">
                        <label>Notes (optional)</label>
                        <textarea name="notes" placeholder="Optional description or notes"></textarea>
                    </div>
                    <button type="submit" name="create_key" class="btn">Create API Key</button>
                </form>
            </div>

            <!-- API Keys List -->
            <div class="card">
                <h2>API Keys (<?= count($apiKeys) ?>)</h2>
                <?php if (empty($apiKeys)): ?>
                    <p style="color: #7f8c8d;">No API keys yet. Create one above.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>API Key</th>
                                <th>Requests</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Last Used</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiKeys as $key): ?>
                                <tr>
                                    <td><?= htmlspecialchars($key['name']) ?></td>
                                    <td><code class="code"><?= htmlspecialchars(substr($key['api_key'], 0, 20)) ?>...</code></td>
                                    <td><?= number_format($key['requests_count']) ?></td>
                                    <td>
                                        <?php if ($key['is_active']): ?>
                                            <span class="badge badge-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('Y-m-d H:i', strtotime($key['created_at'])) ?></td>
                                    <td><?= $key['last_used_at'] ? date('Y-m-d H:i', strtotime($key['last_used_at'])) : '-' ?></td>
                                    <td>
                                        <a href="?toggle_key=<?= urlencode($key['api_key']) ?>" class="btn btn-warning btn-sm">
                                            <?= $key['is_active'] ? 'Disable' : 'Enable' ?>
                                        </a>
                                        <a href="?delete_key=<?= urlencode($key['api_key']) ?>"
                                           onclick="return confirm('Are you sure you want to delete this API key?')"
                                           class="btn btn-danger btn-sm">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <h2>Recent Activity (Last 50)</h2>
                <?php if (empty($stats)): ?>
                    <p style="color: #7f8c8d;">No activity yet.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>API Key</th>
                                <th>ISBN</th>
                                <th>Scraper</th>
                                <th>Status</th>
                                <th>Response Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats as $stat): ?>
                                <tr>
                                    <td><?= date('Y-m-d H:i:s', strtotime($stat['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($stat['api_key_name'] ?? 'Unknown') ?></td>
                                    <td><code class="code"><?= htmlspecialchars($stat['isbn']) ?></code></td>
                                    <td><?= htmlspecialchars($stat['scraper_used'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php if ($stat['success']): ?>
                                            <span class="badge badge-success">Success</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Failed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $stat['response_time_ms'] ?> ms</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
