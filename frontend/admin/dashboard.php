<?php
/**
 * File: frontend/admin/dashboard.php
 * TRẠNG THÁI: STUB TẠM THỜI - chỉ dùng để test luồng Auth/Middleware ở Phase 2-3.
 * Sẽ được thay thế bằng bản đầy đủ ở Phase 6 (AdminService::getSystemSummary()).
 * Related: FR-ADM-*
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';

// BR-19 / NFR-03: chỉ Admin được vào trang này, chặn ở tầng server
Middleware::guard([ROLE_ADMIN]);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - InventoryDSS</title>
    <style>
        body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f3f4f6; margin: 0; padding: 40px; }
        .box { background: #fff; max-width: 560px; margin: 0 auto; padding: 28px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        h1 { color: #1e3a5f; font-size: 20px; margin-bottom: 4px; }
        .badge { display: inline-block; background: #dbeafe; color: #1e3a5f; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 999px; margin-bottom: 16px; }
        .info-row { font-size: 14px; color: #374151; margin-bottom: 6px; }
        .info-row strong { color: #111827; }
        .stub-note { margin-top: 20px; padding: 12px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; font-size: 12px; color: #92400e; }
        a.logout { display: inline-block; margin-top: 20px; color: #b91c1c; font-size: 13px; text-decoration: none; }
        a.logout:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="box">
        <span class="badge">ADMIN</span>
        <h1>Chào mừng, <?= htmlspecialchars(Auth::fullName(), ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="info-row"><strong>Tài khoản:</strong> <?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="info-row"><strong>Vai trò:</strong> <?= htmlspecialchars(Auth::roleName(), ENT_QUOTES, 'UTF-8') ?> (role_id = <?= (int) Auth::roleId() ?>)</div>
        <div class="info-row"><strong>Account ID:</strong> <?= (int) Auth::id() ?></div>

        <div class="stub-note">
            Đây là trang tạm (stub) để kiểm tra luồng đăng nhập/phân quyền ở Phase 2-3.
            Dashboard đầy đủ (KPI, PO chờ duyệt, alert...) sẽ được xây ở Phase 6 theo FR-ADM-01 → FR-ADM-10.
        </div>

        <a href="../logout.php" class="logout">Đăng xuất</a>
    </div>
</body>
</html>