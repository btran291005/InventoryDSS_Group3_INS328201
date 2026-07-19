<?php
/**
 * File: backend/api/ForecastAPI.php
 * Purpose: Low-level HTTP client (cURL) cho AI Demand Forecast API.
 *          KHÔNG chứa logic fallback (BR-18) - phần đó thuộc về IntegrationService.php.
 *          File này chỉ có 2 việc: (1) đọc cấu hình endpoint/api_key từ bảng
 *          api_configs, (2) gọi HTTP POST và trả về kết quả thô (raw), hoặc báo lỗi
 *          rõ ràng nếu timeout/lỗi mạng/response không hợp lệ.
 *
 * Related: FR-SYS-04 (gọi demand-forecast API theo lịch/ theo yêu cầu Manager),
 *          NFR-06 (gracefully handle failures/timeouts).
 *
 * Bảng liên quan (đã có trong DB, xem db.sql):
 *   api_configs(config_id, api_name, endpoint_url, api_key, configured_by)
 *     - Seed data: api_name = 'AI_Demand_Forecast' -> endpoint_url + api_key thật.
 *
 * QUY ƯỚC HỢP ĐỒNG (contract) VỚI API BÊN NGOÀI (giả định, vì chưa có API thật):
 *   Request:  POST {endpoint_url}?  Header: Authorization: Bearer {api_key}
 *             Body JSON: {"product_id": int, "sku_code": string,
 *                         "history_days": int, "current_stock": int}
 *   Response: 200 OK, Body JSON: {"suggested_qty": int, "confidence": float}
 *
 * Nếu API thật có hợp đồng khác, CHỈ cần sửa buildRequestPayload()/parseResponse()
 * bên dưới - phần còn lại (timeout, error handling, đọc config) không đổi.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';

class ForecastAPI
{
    private PDO $conn;

    /** Tên định danh API trong bảng api_configs - PHẢI khớp đúng seed data. */
    private const API_NAME = 'AI_Demand_Forecast';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    /**
     * Gọi AI Demand Forecast API cho 1 sản phẩm.
     *
     * KHÔNG throw exception ra ngoài - mọi lỗi (timeout, lỗi mạng, response sai
     * định dạng, chưa cấu hình api_configs...) đều trả về qua 'success' => false
     * kèm 'error' mô tả rõ nguyên nhân, để IntegrationService quyết định fallback
     * (BR-18) mà không cần try/catch phức tạp ở tầng trên.
     *
     * @return array{success: bool, suggested_qty?: int, confidence?: float, error?: string}
     */
    public function getSuggestedQuantity(int $productId, string $skuCode, int $currentStock, int $historyDays = STOCKOUT_RISK_SALES_WINDOW_DAYS): array
    {
        $config = $this->getApiConfig();
        if ($config === false) {
            return [
                'success' => false,
                'error'   => "Chưa cấu hình API '" . self::API_NAME . "' trong bảng api_configs.",
            ];
        }

        $payload = json_encode([
            'product_id'    => $productId,
            'sku_code'      => $skuCode,
            'history_days'  => $historyDays,
            'current_stock' => $currentStock,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return ['success' => false, 'error' => 'Không thể mã hóa payload gửi tới Forecast API.'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $config['endpoint_url'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['api_key'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            // BR-18/NFR-06: timeout cứng, PHẢI luôn set - đây là điều kiện để
            // IntegrationService kích hoạt fallback đúng lúc, không để Manager
            // chờ vô hạn khi API bên thứ 3 bị treo.
            CURLOPT_CONNECTTIMEOUT => FORECAST_API_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => FORECAST_API_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $rawResponse = curl_exec($ch);
        $curlErrno   = curl_errno($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Lỗi mạng/timeout (bao gồm CURLE_OPERATION_TIMEDOUT) - đây chính là ca
        // BR-18 mô tả: "AI Forecast service is unavailable".
        if ($curlErrno !== 0) {
            error_log("[ForecastAPI] cURL error (errno={$curlErrno}): {$curlError}");
            return ['success' => false, 'error' => "Không thể kết nối Forecast API: {$curlError}"];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("[ForecastAPI] HTTP {$httpCode} - response: " . substr((string) $rawResponse, 0, 500));
            return ['success' => false, 'error' => "Forecast API trả về mã lỗi HTTP {$httpCode}."];
        }

        return $this->parseResponse($rawResponse);
    }

    /**
     * Đọc endpoint_url + api_key hiện hành từ bảng api_configs.
     * Trả về false nếu chưa cấu hình (chưa từng chạy seed hoặc bị Admin xóa).
     */
    private function getApiConfig(): array|false
    {
        $stmt = $this->conn->prepare(
            'SELECT endpoint_url, api_key FROM api_configs WHERE api_name = :api_name LIMIT 1'
        );
        $stmt->execute([':api_name' => self::API_NAME]);
        return $stmt->fetch();
    }

    /**
     * Parse response JSON thô từ API thành mảng chuẩn hóa.
     * Tách riêng private để dễ sửa nếu hợp đồng API thật khác với giả định ở
     * đầu file - chỉ cần sửa hàm này, không ảnh hưởng phần cURL/timeout ở trên.
     */
    private function parseResponse(string|false $rawResponse): array
    {
        if ($rawResponse === false || $rawResponse === '') {
            return ['success' => false, 'error' => 'Forecast API trả về response rỗng.'];
        }

        $data = json_decode($rawResponse, true);

        if (!is_array($data) || !isset($data['suggested_qty']) || !is_numeric($data['suggested_qty'])) {
            error_log('[ForecastAPI] Response không đúng định dạng mong đợi: ' . substr($rawResponse, 0, 500));
            return ['success' => false, 'error' => 'Forecast API trả về dữ liệu không đúng định dạng.'];
        }

        return [
            'success'       => true,
            'suggested_qty' => (int) $data['suggested_qty'],
            'confidence'    => isset($data['confidence']) ? (float) $data['confidence'] : null,
        ];
    }
}