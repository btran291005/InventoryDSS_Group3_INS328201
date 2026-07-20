<?php

declare(strict_types=1);

// 1. Múi giờ hệ thống - Việt Nam (ảnh hưởng tới mọi NOW()/date() trong PHP & MySQL)
date_default_timezone_set('Asia/Ho_Chi_Minh');

// 2. Đường dẫn thư mục gốc (dùng require/include tuyệt đối, tránh lỗi path)

define('ROOT_PATH', dirname(__DIR__, 2));          // .../InventoryDSS_Group3_INS328201
define('BACKEND_PATH', ROOT_PATH . '/backend');
define('FRONTEND_PATH', ROOT_PATH . '/frontend');
define('CORE_PATH', BACKEND_PATH . '/core');
define('MODELS_PATH', BACKEND_PATH . '/models');
define('SERVICES_PATH', BACKEND_PATH . '/services');
define('API_PATH', BACKEND_PATH . '/api');

// URL gốc của ứng dụng (dùng cho redirect, link tuyệt đối trong header/sidebar)
// Điều chỉnh lại nếu deploy ngoài localhost.
define('BASE_URL', '/InventoryDSS_Group3_INS328201/frontend');

// 3. Cấu hình Session (đăng nhập/đăng xuất - FR-SYS-01)
define('SESSION_NAME', 'INVENTORYDSS_SESSID');
define('SESSION_LIFETIME_SECONDS', 8 * 60 * 60); // 8 tiếng ~ 1 ca làm việc

// 4. RBAC - Định danh Role (khớp bảng roles trong DB: role_id 1/2/3)
// Dùng hằng số thay vì hardcode số/string rải rác khắp code -> dễ bảo trì (NFR-07)
define('ROLE_ADMIN', 1);
define('ROLE_MANAGER', 2);
define('ROLE_STAFF', 3);

define('ROLE_NAMES', [
    ROLE_ADMIN   => 'Admin',
    ROLE_MANAGER => 'Manager',
    ROLE_STAFF   => 'Store Staff',
]);

// 5. Hằng số nghiệp vụ (Business Rules) - tránh magic number rải rác trong Service

// BR-18 / NFR-06: Timeout gọi AI Forecast API trước khi fallback về Reorder Point
define('FORECAST_API_TIMEOUT_SECONDS', 5);

// FR-MGR-12: "Top 10 Stock-out Risk" tính theo doanh số bán trung bình N ngày gần nhất
define('STOCKOUT_RISK_SALES_WINDOW_DAYS', 7);

// FR-STF-13: Toggle xem lịch sử bán hàng 7/30 ngày
define('SALES_HISTORY_SHORT_RANGE_DAYS', 7);
define('SALES_HISTORY_LONG_RANGE_DAYS', 30);

// User Story: cảnh báo lô hàng tươi sống sắp hết hạn trong 12-24h tới
define('EXPIRY_ALERT_WINDOW_HOURS', 24);

// 6. Chế độ môi trường (dùng để bật/tắt hiển thị lỗi chi tiết)
define('APP_ENV', 'development'); // 'development' | 'production'

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}