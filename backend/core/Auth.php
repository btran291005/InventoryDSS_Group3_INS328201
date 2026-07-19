<?php
/**
 * File: backend/core/Auth.php
 * Purpose: Xử lý xác thực (login/logout) và cung cấp thông tin user hiện tại
 * từ session, dùng làm nền tảng cho RBAC (Middleware.php sẽ dùng class này
 * để kiểm tra quyền truy cập theo role).
 *
 * Liên quan:
 *   - BR-17: chỉ Admin tạo account (Auth chỉ xử lý login, KHÔNG xử lý tạo account
 *            - việc đó thuộc AdminService ở Phase 4).
 *   - BR-19: user chỉ truy cập đúng chức năng theo role.
 *   - FR-SYS-01: login/logout, redirect đúng dashboard theo role.
 *   - FR-ADM-02: tài khoản bị khóa (status = 'locked') không được đăng nhập.
 *   - NFR-03: RBAC thực thi ở tầng server, mật khẩu phải hash (password_hash/password_verify).
 *
 * Bảng accounts (đã có trong DB):
 *   account_id, username, password_hash, full_name, role_id, status, created_at
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Logger.php';

class Auth
{
    /**
     * Khởi động session nếu chưa có.
     * PHẢI gọi hàm này (hoặc Auth::check()) ở đầu mọi file PHP cần biết trạng thái đăng nhập,
     * trước khi có bất kỳ output HTML nào (session_start() yêu cầu chưa gửi header).
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(defined('SESSION_NAME') ? SESSION_NAME : 'INVENTORYDSS_SESSID');

            session_set_cookie_params([
                'lifetime' => defined('SESSION_LIFETIME_SECONDS') ? SESSION_LIFETIME_SECONDS : 28800,
                'path'     => '/',
                'httponly' => true,   // Chặn JS đọc cookie session -> giảm rủi ro XSS đánh cắp session
                'samesite' => 'Lax',  // Giảm rủi ro CSRF cơ bản
            ]);

            session_start();
        }
    }

    /**
     * Kiểm tra độ mạnh mật khẩu khi TẠO MỚI hoặc ĐỔI mật khẩu (KHÔNG dùng ở login()).
     * Rule: tối thiểu 8 ký tự, có ít nhất 1 chữ hoa, 1 chữ thường, 1 chữ số,
     * và 1 ký tự đặc biệt.
     *
     * Dùng ở AdminService (FR-ADM-02: create/edit user) và bất kỳ chức năng
     * đổi mật khẩu nào khác sau này - gọi hàm này TRƯỚC khi password_hash().
     *
     * @return array{valid: bool, message: string}
     */
    public static function validatePasswordStrength(string $password): array
    {
        if (mb_strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Mật khẩu phải có ít nhất 8 ký tự.'];
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Mật khẩu phải chứa ít nhất 1 chữ hoa.'];
        }

        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Mật khẩu phải chứa ít nhất 1 chữ thường.'];
        }

        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Mật khẩu phải chứa ít nhất 1 chữ số.'];
        }

        // Ký tự đặc biệt: bất kỳ ký tự nào không phải chữ/số (khớp @, #, $, %, !, ... )
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Mật khẩu phải chứa ít nhất 1 ký tự đặc biệt (VD: @, #, $, !).'];
        }

        return ['valid' => true, 'message' => 'Mật khẩu hợp lệ.'];
    }

    /**
     * Thực hiện đăng nhập.
     *
     * @param int|null $expectedRoleId Role được chọn ở UI (tab Admin/Manager/Store Staff
     *        trên login.php - FR-SYS-01). Nếu truyền vào và KHÁC role_id thật của account
     *        trong DB -> từ chối đăng nhập, KHÔNG tạo session, dù username/password đúng.
     *        Truyền null (mặc định) để bỏ qua kiểm tra này (giữ hành vi cũ).
     *
     *        Lưu ý bảo mật: kiểm tra này được đặt SAU password_verify(), không phải trước.
     *        Nếu check trước, kẻ tấn công có thể dò ra "username X thuộc role nào" chỉ bằng
     *        cách thử các tab role khác nhau mà không cần biết mật khẩu đúng - vi phạm
     *        nguyên tắc "không tiết lộ thông tin tài khoản" đã áp dụng ở nhánh !$account
     *        và nhánh password sai bên dưới.
     *
     * @return array{success: bool, message: string, role_id?: int}
     */
    public static function login(string $username, string $password, ?int $expectedRoleId = null): array
    {
        self::start();

        $username = trim($username);

        if ($username === '' || $password === '') {
            return ['success' => false, 'message' => 'Vui lòng nhập đầy đủ tên đăng nhập và mật khẩu.'];
        }

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare(
                'SELECT account_id, username, password_hash, full_name, role_id, status
                 FROM accounts
                 WHERE username = :username
                 LIMIT 1'
            );
            $stmt->execute([':username' => $username]);
            $account = $stmt->fetch();

            // Không tiết lộ "sai username" hay "sai password" riêng biệt -> tránh dò tài khoản tồn tại
            if (!$account) {
                return ['success' => false, 'message' => 'Tên đăng nhập hoặc mật khẩu không đúng.'];
            }

            // FR-ADM-02: tài khoản bị khóa không được đăng nhập
            if ($account['status'] === 'locked') {
                return ['success' => false, 'message' => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin.'];
            }

            if (!password_verify($password, $account['password_hash'])) {
                return ['success' => false, 'message' => 'Tên đăng nhập hoặc mật khẩu không đúng.'];
            }

            // Đã xác thực đúng username/password ở trên -> giờ mới kiểm tra role đã chọn
            // trên UI có khớp role thật của account không (BR-19, NFR-03).
            if ($expectedRoleId !== null && (int) $account['role_id'] !== $expectedRoleId) {
                Logger::log(
                    (int) $account['account_id'],
                    'LOGIN_ROLE_MISMATCH',
                    'accounts',
                    (int) $account['account_id']
                );

                return [
                    'success' => false,
                    'message' => 'Tài khoản này không thuộc vai trò bạn đã chọn. Vui lòng chọn đúng vai trò hoặc liên hệ Admin.',
                ];
            }

            // Chống session fixation: cấp session ID mới sau khi xác thực thành công
            session_regenerate_id(true);

            $_SESSION['account_id'] = (int) $account['account_id'];
            $_SESSION['username']   = $account['username'];
            $_SESSION['full_name']  = $account['full_name'];
            $_SESSION['role_id']    = (int) $account['role_id'];
            $_SESSION['login_time'] = time();

            Logger::log((int) $account['account_id'], 'LOGIN', 'accounts', (int) $account['account_id']);

            return ['success' => true, 'message' => 'Đăng nhập thành công.', 'role_id' => (int) $account['role_id']];
        } catch (PDOException $e) {
            error_log('[Auth] Login query failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Có lỗi xảy ra, vui lòng thử lại sau.'];
        }
    }

    /**
     * Đăng xuất: ghi log rồi hủy toàn bộ session.
     */
    public static function logout(): void
    {
        self::start();

        if (self::check()) {
            Logger::log(self::id(), 'LOGOUT', 'accounts', self::id());
        }

        $_SESSION = [];

        // Xóa cookie session ở phía trình duyệt
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /** Kiểm tra đã đăng nhập hay chưa. */
    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['account_id'], $_SESSION['role_id']);
    }

    /** Lấy account_id của user hiện tại, null nếu chưa đăng nhập. */
    public static function id(): ?int
    {
        self::start();
        return $_SESSION['account_id'] ?? null;
    }

    /** Lấy role_id của user hiện tại (1=Admin, 2=Manager, 3=Store Staff), null nếu chưa đăng nhập. */
    public static function roleId(): ?int
    {
        self::start();
        return $_SESSION['role_id'] ?? null;
    }

    /** Lấy họ tên hiển thị của user hiện tại. */
    public static function fullName(): ?string
    {
        self::start();
        return $_SESSION['full_name'] ?? null;
    }

    /** Lấy tên role dạng chữ ('Admin' | 'Manager' | 'Store Staff'). */
    public static function roleName(): ?string
    {
        $roleId = self::roleId();

        if ($roleId === null) {
            return null;
        }

        return ROLE_NAMES[$roleId] ?? null;
    }

    /**
     * Kiểm tra user hiện tại có thuộc 1 trong các role được truyền vào không.
     * Dùng ở Middleware và ở những nơi cần kiểm tra nhanh (VD: ẩn/hiện nút bấm trên UI).
     *
     * @param int ...$roleIds VD: Auth::hasRole(ROLE_ADMIN, ROLE_MANAGER)
     */
    public static function hasRole(int ...$roleIds): bool
    {
        $current = self::roleId();
        return $current !== null && in_array($current, $roleIds, true);
    }

    /**
     * Bắt buộc phải đăng nhập, nếu không thì redirect về trang login.
     * Dùng ở đầu các file PHP thuộc khu vực cần xác thực.
     */
    public static function requireLogin(string $redirectTo = '/login.php'): void
    {
        if (!self::check()) {
            header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . $redirectTo);
            exit;
        }
    }
}