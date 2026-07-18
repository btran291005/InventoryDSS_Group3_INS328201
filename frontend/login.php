<?php
/**
 * File: frontend/login.php
 * Purpose: Trang đăng nhập. Gọi Auth::login(), thành công thì redirect
 * tới dashboard đúng role; thất bại thì hiển thị lỗi ngay trên form.
 * Related: FR-SYS-01, NFR-03
 */

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/app_config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/core/Logger.php';
require_once __DIR__ . '/../backend/core/Auth.php';

Auth::start();

// Đã đăng nhập rồi thì không cần xem lại form login -> đẩy thẳng về dashboard
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $result = Auth::login($username, $password);

    if ($result['success']) {
        // FR-SYS-01: redirect đúng dashboard theo role sau khi login
        header('Location: index.php');
        exit;
    }

    $errorMessage = $result['message'];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - InventoryDSS</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            background: linear-gradient(135deg, #1e3a5f 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .login-card {
            background: #ffffff;
            width: 100%;
            max-width: 380px;
            border-radius: 12px;
            padding: 36px 32px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
        }
        .login-card h1 {
            font-size: 20px;
            color: #1e3a5f;
            margin-bottom: 4px;
            text-align: center;
        }
        .login-card p.subtitle {
            font-size: 13px;
            color: #6b7280;
            text-align: center;
            margin-bottom: 24px;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color .15s;
        }
        .form-group input:focus {
            border-color: #1e3a5f;
        }
        .btn-submit {
            width: 100%;
            padding: 11px;
            background: #1e3a5f;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            transition: background .15s;
        }
        .btn-submit:hover { background: #16304d; }
        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .demo-hint {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
        }
        .demo-hint .demo-title {
            font-size: 12px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .04em;
            text-align: center;
            margin-bottom: 10px;
        }
        .demo-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .demo-table th {
            text-align: left;
            color: #9ca3af;
            font-weight: 600;
            padding: 4px 6px;
            border-bottom: 1px solid #e5e7eb;
        }
        .demo-table td {
            padding: 6px 6px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
        }
        .demo-table td.role-cell {
            font-weight: 600;
        }
        .demo-table td.role-admin { color: #1e3a5f; }
        .demo-table td.role-manager { color: #166534; }
        .demo-table td.role-staff { color: #92400e; }
        .demo-table code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: "SFMono-Regular", Consolas, monospace;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h1>InventoryDSS</h1>
        <p class="subtitle">Hệ thống hỗ trợ quyết định tồn kho</p>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert-error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off">
            <div class="form-group">
                <label for="username">Tên đăng nhập</label>
                <input type="text" id="username" name="username" required autofocus
                       value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="form-group">
                <label for="password">Mật khẩu</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn-submit">Đăng nhập</button>
        </form>

        <div class="demo-hint">
            <div class="demo-title">Demo Account</div>
            <table class="demo-table">
                <thead>
                    <tr>
                        <th>Vai trò</th>
                        <th>Username</th>
                        <th>Mật khẩu</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="role-cell role-admin">Admin</td>
                        <td><code>admin</code></td>
                        <td><code>Admin@123</code></td>
                    </tr>
                    <tr>
                        <td class="role-cell role-manager">Manager</td>
                        <td><code>manager1</code></td>
                        <td><code>Manager@123</code></td>
                    </tr>
                    <tr>
                        <td class="role-cell role-staff">Store Staff</td>
                        <td><code>staff1</code></td>
                        <td><code>Staff@123</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>