<?php
/**
 * File: backend/config/database.php
 * Purpose: Quản lý kết nối tới MySQL bằng PDO theo mô hình Singleton.
 * Toàn bộ Model/Service trong hệ thống PHẢI lấy connection qua Database::getConnection()
 * thay vì tự new PDO(...) để đảm bảo chỉ có 1 connection duy nhất trong 1 request
 * và cấu hình PDO (error mode, charset...) được áp dụng nhất quán ở mọi nơi.
 *
 * Bảo mật: dùng PDO + Prepared Statements ở tầng Model để tránh SQL Injection
 * (tuân thủ QUY TẮC LÀM VIỆC CỐT LÕI #4).
 */

declare(strict_types=1);

class Database
{
    // Thông tin kết nối - môi trường local (XAMPP)
    private const DB_HOST    = '127.0.0.1';
    private const DB_PORT    = '3306';
    private const DB_NAME    = 'project2';
    private const DB_USER    = 'root';
    private const DB_PASS    = '';
    private const DB_CHARSET = 'utf8mb4';

    /** @var PDO|null Instance PDO duy nhất trong vòng đời request */
    private static ?PDO $instance = null;

    // Ngăn khởi tạo trực tiếp (Singleton)
    private function __construct()
    {
    }

    // Ngăn nhân bản instance
    private function __clone()
    {
    }

    /**
     * Lấy connection PDO dùng chung toàn hệ thống.
     * Tự động tạo mới nếu chưa tồn tại, tái sử dụng nếu đã có.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                self::DB_HOST,
                self::DB_PORT,
                self::DB_NAME,
                self::DB_CHARSET
            );

            $options = [
                // Ném exception khi có lỗi thay vì fail âm thầm -> dễ debug, dễ log
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // Trả về dữ liệu dạng associative array (giống tên cột trong DB)
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Không giả lập prepared statements -> dùng prepared statement thật của MySQL,
                // giúp chống SQL Injection triệt để hơn (bind param theo đúng kiểu dữ liệu)
                PDO::ATTR_EMULATE_PREPARES => false,
                // Giữ kết nối ổn định, tránh lỗi charset khi có tiếng Việt (unicode)
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
            ];

            try {
                self::$instance = new PDO($dsn, self::DB_USER, self::DB_PASS, $options);
            } catch (PDOException $e) {
                // Không lộ thông tin nhạy cảm (host/user/pass) ra ngoài khi ở production.
                // Log chi tiết lỗi thật, chỉ trả thông báo chung cho người dùng.
                error_log('[Database] Connection failed: ' . $e->getMessage());
                throw new RuntimeException('Không thể kết nối cơ sở dữ liệu. Vui lòng thử lại sau.');
            }
        }

        return self::$instance;
    }
}