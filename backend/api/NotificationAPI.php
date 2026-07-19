<?php
/**
 * File: backend/api/NotificationAPI.php
 * Purpose: Low-level client gửi cảnh báo (notification, email/Zalo optional) khi
 *          tồn kho chạm ngưỡng an toàn. KHÔNG chứa logic xác định SẢN PHẨM nào
 *          cần cảnh báo (đó là BR-04/BR-13, đã có ở ManagerService::getLowStockAlerts()) -
 *          file này chỉ nhận payload đã chuẩn bị sẵn và lo việc GỬI đi.
 *
 * Related: FR-SYS-02 ("Alert is triggered within an acceptable time after stock
 *          crosses the threshold; alert reaches the intended channel"), BR-04.
 * Owner: Tran (Infra)
 *
 * QUY ƯỚC KÊNH GỬI:
 *   - 'in_app'  : luôn hoạt động (không cần cấu hình ngoài) - ghi lại một dòng
 *                 audit_log để phía frontend (dashboard) có thể truy vấn ra
 *                 "cảnh báo gần đây" mà không cần bảng notifications riêng
 *                 (schema hiện KHÔNG có bảng notifications - xem ghi chú trong
 *                 db.sql, KHÔNG tự thêm bảng mới ở Phase này).
 *   - 'email'   : optional, đọc SMTP config qua các hằng số trong app_config.php
 *                 (NOTIFICATION_EMAIL_* - CHƯA khai báo, cần Admin bổ sung ở
 *                 app_config.php nếu muốn bật kênh này - mặc định TẮT).
 *   - 'zalo'    : optional, gọi Zalo OA API qua endpoint cấu hình trong
 *                 api_configs (api_name = 'Zalo_OA') - mặc định TẮT nếu chưa
 *                 có dòng cấu hình tương ứng trong DB.
 *
 * Vì email/zalo đều là kênh "optional" theo đúng mô tả gốc trong FR-SYS-02
 * ("email/Zalo in optional"), hàm gửi luôn coi 'in_app' là kênh bắt buộc/luôn
 * thành công (ghi log), còn email/zalo lỗi KHÔNG được làm sập luồng cảnh báo
 * chính - chỉ ghi log lỗi và tiếp tục.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

class NotificationAPI
{
    /** Tên định danh Zalo OA API trong bảng api_configs (nếu đã cấu hình). */
    private const ZALO_API_NAME = 'Zalo_OA';

    private PDO $conn;

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    /**
     * Gửi cảnh báo tồn kho thấp (BR-04) tới Manager.
     *
     * @param int         $productId   Sản phẩm đang cảnh báo
     * @param string      $productName Tên sản phẩm (hiển thị trong nội dung cảnh báo)
     * @param int         $currentQty  Tồn kho hiện tại
     * @param int         $reorderPoint Ngưỡng Reorder Point đã chạm/dưới
     * @param int|null    $triggeredBy account_id gây ra hành động khiến tồn kho giảm
     *                                 (VD: staff vừa bán hàng) - null nếu do cron job
     *                                 hệ thống tự quét định kỳ, KHÔNG gắn với 1 user
     *                                 cụ thể nào (Logger yêu cầu account_id NOT NULL,
     *                                 xem xử lý bên dưới).
     *
     * @return array{success: bool, channels_sent: string[], message: string}
     */
    public function sendLowStockAlert(
        int $productId,
        string $productName,
        int $currentQty,
        int $reorderPoint,
        ?int $triggeredBy = null
    ): array {
        $channelsSent = [];

        $message = sprintf(
            'Sản phẩm "%s" đã chạm/dưới Reorder Point (tồn kho: %d, ngưỡng: %d) - cần lên kế hoạch đặt hàng.',
            $productName,
            $currentQty,
            $reorderPoint
        );

        // Kênh 'in_app' (bắt buộc, luôn hoạt động): ghi audit_log để dashboard/
        // trang cảnh báo có thể truy vấn lại lịch sử cảnh báo đã gửi.
        // account_id trong audit_logs là NOT NULL - nếu không có $triggeredBy
        // (cron job hệ thống), dùng account_id=1 (Admin mặc định, đã seed sẵn
        // trong db.sql) làm "hệ thống" thực hiện hành động, tương tự quy ước
        // đã dùng ở các Service khác khi cần một account_id đại diện hệ thống.
        $systemAccountId = $triggeredBy ?? 1;
        $logged = Logger::log($systemAccountId, 'LOW_STOCK_ALERT', 'products', $productId);
        if ($logged) {
            $channelsSent[] = 'in_app';
        }

        // Kênh 'email' (optional - FR-SYS-02: "email/Zalo in optional"):
        // chỉ gửi nếu Admin đã cấu hình đủ hằng số SMTP trong app_config.php.
        if (defined('NOTIFICATION_EMAIL_ENABLED') && NOTIFICATION_EMAIL_ENABLED === true) {
            if ($this->sendEmail($message)) {
                $channelsSent[] = 'email';
            }
        }

        // Kênh 'zalo' (optional): chỉ gửi nếu đã có dòng cấu hình Zalo_OA trong api_configs.
        $zaloConfig = $this->getZaloConfig();
        if ($zaloConfig !== false) {
            if ($this->sendZalo($zaloConfig, $message)) {
                $channelsSent[] = 'zalo';
            }
        }

        return [
            'success'       => !empty($channelsSent),
            'channels_sent' => $channelsSent,
            'message'       => !empty($channelsSent)
                ? 'Đã gửi cảnh báo qua kênh: ' . implode(', ', $channelsSent) . '.'
                : 'Không gửi được cảnh báo qua bất kỳ kênh nào.',
        ];
    }

    /**
     * Gửi email qua hàm mail() built-in của PHP (đủ dùng cho demo/local -
     * XAMPP cần cấu hình sendmail/SMTP riêng nếu muốn test thật, xem
     * php.ini [mail function] - KHÔNG thuộc phạm vi code PHP).
     * Lỗi gửi email KHÔNG được ném exception - chỉ log và trả false.
     */
    private function sendEmail(string $message): bool
    {
        try {
            $to      = defined('NOTIFICATION_EMAIL_TO') ? NOTIFICATION_EMAIL_TO : '';
            $subject = 'InventoryDSS - Canh bao ton kho thap';

            if ($to === '') {
                error_log('[NotificationAPI] NOTIFICATION_EMAIL_TO chưa được cấu hình.');
                return false;
            }

            $headers = 'Content-Type: text/plain; charset=utf-8' . "\r\n";
            $sent = mail($to, $subject, $message, $headers);

            if (!$sent) {
                error_log('[NotificationAPI] Gửi email thất bại (mail() trả về false).');
            }

            return $sent;
        } catch (\Throwable $e) {
            error_log('[NotificationAPI] Lỗi khi gửi email: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gửi tin nhắn qua Zalo OA API. Đọc endpoint_url/api_key từ api_configs
     * (api_name = 'Zalo_OA'). Đây là hợp đồng GIẢ ĐỊNH (chưa có Zalo OA thật
     * kết nối) - nếu tích hợp thật khác, chỉ cần sửa buildZaloPayload() bên dưới.
     */
    private function sendZalo(array $zaloConfig, string $message): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $zaloConfig['endpoint_url'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['message' => $message], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'access_token: ' . $zaloConfig['api_key'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => FORECAST_API_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => FORECAST_API_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0 || $httpCode < 200 || $httpCode >= 300) {
            error_log("[NotificationAPI] Gửi Zalo thất bại (errno={$curlErrno}, http={$httpCode}): {$curlError}");
            return false;
        }

        return true;
    }

    private function getZaloConfig(): array|false
    {
        $stmt = $this->conn->prepare(
            'SELECT endpoint_url, api_key FROM api_configs WHERE api_name = :api_name LIMIT 1'
        );
        $stmt->execute([':api_name' => self::ZALO_API_NAME]);
        return $stmt->fetch();
    }
}