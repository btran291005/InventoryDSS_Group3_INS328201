<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Logger.php';

class Auth
{
    /* Start session if not already started. MUST call this function (or Auth::check()) at the beginning of every PHP file that needs to know login status, before any HTML output (session_start() requires no headers to be sent). */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(defined('SESSION_NAME') ? SESSION_NAME : 'INVENTORYDSS_SESSID');

            session_set_cookie_params([
                'lifetime' => defined('SESSION_LIFETIME_SECONDS') ? SESSION_LIFETIME_SECONDS : 28800,
                'path'     => '/',
                'httponly' => true,   // Prevent JS from reading session cookie -> reduce XSS session theft risk
                'samesite' => 'Lax',  // Basic CSRF protection
            ]);

            session_start();
        }
    }

    /* Check password strength when CREATING NEW or CHANGING password (NOT used in login()).
     * Rule: minimum 8 characters, at least 1 uppercase, 1 lowercase, 1 number, and 1 special character.
     * Used in AdminService (FR-ADM-02: create/edit user) and any other password change functionality later - call this function BEFORE password_hash(). */
    public static function validatePasswordStrength(string $password): array
    {
        if (mb_strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Password must be at least 8 characters long.'];
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least 1 uppercase letter.'];
        }

        if (!preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least 1 lowercase letter.'];
        }

        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least 1 number.'];
        }

        // Special character: any character that is not a letter or number (matches @, #, $, %, !, etc.)
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least 1 special character (e.g.: @, #, $, !).'];
        }

        return ['valid' => true, 'message' => 'Password is valid.'];
    }

    /* Perform login - Role selected in UI (Admin/Manager/Store Staff tabs on login.php - FR-SYS-01). If passed and DIFFERENT from the account's actual role_id in DB -> reject login, do NOT create session, even if username/password are correct. Pass null (default) to skip this check. */
    public static function login(string $username, string $password, ?int $expectedRoleId = null): array
    {
        self::start();

        $username = trim($username);

        if ($username === '' || $password === '') {
            return ['success' => false, 'message' => 'Please enter both username and password.'];
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

            // Do not reveal "wrong username" or "wrong password" separately -> prevent account enumeration
            if (!$account) {
                return ['success' => false, 'message' => 'Username or password is incorrect.'];
            }

            // FR-ADM-02: locked accounts cannot login
            if ($account['status'] === 'locked') {
                return ['success' => false, 'message' => 'Your account has been locked. Please contact Admin.'];
            }

            if (!password_verify($password, $account['password_hash'])) {
                return ['success' => false, 'message' => 'Username or password is incorrect.'];
            }

            // Username/password verified above -> now check if UI-selected role matches actual role (BR-19, NFR-03).
            if ($expectedRoleId !== null && (int) $account['role_id'] !== $expectedRoleId) {
                Logger::log(
                    (int) $account['account_id'],
                    'LOGIN_ROLE_MISMATCH',
                    'accounts',
                    (int) $account['account_id']
                );

                return [
                    'success' => false,
                    'message' => 'This account does not belong to the role you selected. Please choose the correct role or contact Admin.',
                ];
            }

            // Prevent session fixation: issue new session ID after successful authentication
            session_regenerate_id(true);

            $_SESSION['account_id'] = (int) $account['account_id'];
            $_SESSION['username']   = $account['username'];
            $_SESSION['full_name']  = $account['full_name'];
            $_SESSION['role_id']    = (int) $account['role_id'];
            $_SESSION['login_time'] = time();

            Logger::log((int) $account['account_id'], 'LOGIN', 'accounts', (int) $account['account_id']);

            return ['success' => true, 'message' => 'Login successful.', 'role_id' => (int) $account['role_id']];
        } catch (PDOException $e) {
            error_log('[Auth] Login query failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred, please try again later.'];
        }
    }

    /* Logout: log the action then destroy the entire session. */
    public static function logout(): void
    {
        self::start();

        if (self::check()) {
            Logger::log(self::id(), 'LOGOUT', 'accounts', self::id());
        }

        $_SESSION = [];

        // Delete session cookie from browser
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

    /* Check if user is logged in or not. */
    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['account_id'], $_SESSION['role_id']);
    }

    /* Get account_id of current user, null if not logged in. */
    public static function id(): ?int
    {
        self::start();
        return $_SESSION['account_id'] ?? null;
    }

    /* Get role_id of current user (1=Admin, 2=Manager, 3=Store Staff), null if not logged in. */
    public static function roleId(): ?int
    {
        self::start();
        return $_SESSION['role_id'] ?? null;
    }

    /* Get display full name of current user. */
    public static function fullName(): ?string
    {
        self::start();
        return $_SESSION['full_name'] ?? null;
    }

    /* Get role name as text ('Admin' | 'Manager' | 'Store Staff'). */
    public static function roleName(): ?string
    {
        $roleId = self::roleId();

        if ($roleId === null) {
            return null;
        }

        return ROLE_NAMES[$roleId] ?? null;
    }

    /* Check if current user has one of the specified roles. Used in Middleware and anywhere quick role checking is needed (e.g.: show/hide UI buttons). */
    public static function hasRole(int ...$roleIds): bool
    {
        $current = self::roleId();
        return $current !== null && in_array($current, $roleIds, true);
    }

    /* Require login, redirect to login page if not authenticated. Used at the beginning of PHP files in protected areas. */
    public static function requireLogin(string $redirectTo = '/login.php'): void
    {
        if (!self::check()) {
            header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . $redirectTo);
            exit;
        }
    }
}