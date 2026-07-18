<?php
/**
 * File: frontend/index.php
 * Purpose: Entry point. Chưa đăng nhập -> đẩy về login.php.
 * Đã đăng nhập -> redirect tới dashboard đúng role (FR-SYS-01).
 *
 * Ghi chú: Các trang dashboard thật (admin/dashboard.php, manager/dashboard.php,
 * staff/dashboard.php) sẽ được viết đầy đủ ở Phase 6. Ở Phase này, nếu dashboard
 * đích chưa tồn tại/còn placeholder rỗng, index.php vẫn redirect đúng URL —
 * bạn sẽ thấy trang trắng hoặc lỗi ở dashboard, đó là điều bình thường cho tới Phase 6.
 */

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/app_config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/core/Logger.php';
require_once __DIR__ . '/../backend/core/Auth.php';

Auth::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

switch (Auth::roleId()) {
    case ROLE_ADMIN:
        header('Location: admin/dashboard.php');
        break;

    case ROLE_MANAGER:
        header('Location: manager/dashboard.php');
        break;

    case ROLE_STAFF:
        header('Location: staff/dashboard.php');
        break;

    default:
        // Trường hợp bất thường: role_id trong session không khớp role nào đã biết
        // -> không đoán mò, buộc đăng xuất để tránh truy cập sai chỗ.
        header('Location: logout.php');
        break;
}
exit;