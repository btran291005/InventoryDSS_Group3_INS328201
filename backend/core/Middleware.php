<?php
/**
 * File: backend/core/Middleware.php
 * Purpose: "Chốt chặn" RBAC ở tầng server - đảm bảo mỗi request tới 1 file/route
 * chỉ được xử lý nếu user đã đăng nhập VÀ có đúng role được phép.
 *
 * Đây chính là điểm hiện thực hóa NFR-03: "RBAC is enforced at the server layer
 * (not just hidden UI)" - tức là dù người dùng có gõ thẳng URL của 1 trang thuộc
 * role khác, họ vẫn bị chặn ở đây, KHÔNG chỉ dựa vào việc ẩn link trên menu.
 *
 * Cách dùng chuẩn: đặt ngay dòng đầu tiên (sau require config) của mỗi file
 * frontend/{admin,manager,staff}/*.php:
 *
 *   require_once __DIR__ . '/../../backend/core/Middleware.php';
 *   Middleware::guard([ROLE_ADMIN]); // chỉ Admin được vào trang này
 *
 * Liên quan: BR-19, BR-07 (chỉ Admin approve PO), BR-16 (chỉ Admin sửa reorder rule),
 * FR-SYS-01 (unauthorized routes trả về access-denied).
 */

declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

class Middleware
{
    /**
     * Chặn truy cập nếu user chưa đăng nhập hoặc không thuộc danh sách role cho phép.
     *
     * @param int[] $allowedRoles VD: [ROLE_ADMIN], [ROLE_ADMIN, ROLE_MANAGER]
     * @param string $accessDeniedRedirect Trang chuyển hướng khi bị từ chối truy cập
     */
    public static function guard(array $allowedRoles, string $accessDeniedRedirect = '/access-denied.php'): void
    {
        Auth::start();

        // Chưa đăng nhập -> luôn về trang login trước, không lộ thông tin trang đích
        if (!Auth::check()) {
            Auth::requireLogin();
            return; // requireLogin() đã exit, dòng này chỉ để rõ luồng
        }

        // Đã đăng nhập nhưng sai role -> chặn truy cập, KHÔNG được âm thầm cho qua
        if (!Auth::hasRole(...$allowedRoles)) {
            self::denyAccess($accessDeniedRedirect);
        }
    }

    /**
     * Biến thể trả JSON 403 thay vì redirect - dùng cho các file trong backend/api/
     * (VD: ForecastAPI.php, NotificationAPI.php) khi được gọi qua AJAX/fetch.
     *
     * @param int[] $allowedRoles
     */
    public static function guardApi(array $allowedRoles): void
    {
        Auth::start();

        if (!Auth::check()) {
            self::respondJsonError(401, 'Bạn cần đăng nhập để thực hiện thao tác này.');
        }

        if (!Auth::hasRole(...$allowedRoles)) {
            self::respondJsonError(403, 'Bạn không có quyền truy cập chức năng này.');
        }
    }

    /**
     * Kiểm tra không exit - dùng khi cần ẩn/hiện 1 phần UI thay vì chặn cả trang
     * (VD: chỉ Admin mới thấy nút "Duyệt" trên trang PO mà cả Admin/Manager đều xem được).
     *
     * @param int[] $allowedRoles
     */
    public static function can(array $allowedRoles): bool
    {
        return Auth::check() && Auth::hasRole(...$allowedRoles);
    }

    private static function denyAccess(string $redirectTo): void
    {
        http_response_code(403);
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . $redirectTo);
        exit;
    }

    private static function respondJsonError(int $httpCode, string $message): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}