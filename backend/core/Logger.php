<?php
/**
 * File: backend/core/Logger.php
 * Purpose: Ghi log các hành động nhạy cảm vào bảng audit_logs.
 * Liên quan: FR-SYS-03 (log mọi approval/rule-change), NFR-09 (traceability),
 * BR-06 (log khi Manager override PO), BR-12 (log mọi inventory adjustment).
 *
 * Bảng audit_logs (đã có trong DB):
 *   log_id, account_id, action_type, target_table, target_id, timestamp
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class Logger
{
    /**
     * Ghi 1 dòng audit log.
     *
     * @param int         $accountId   ID tài khoản thực hiện hành động (bắt buộc, NOT NULL trong DB)
     * @param string      $actionType  Mã hành động, quy ước UPPER_SNAKE_CASE
     *                                 (VD: 'APPROVE_PO', 'OVERRIDE_PO_QTY', 'UPDATE_REORDER_RULE',
     *                                 'LOGIN', 'LOGOUT', 'LOCK_ACCOUNT'...)
     * @param string|null $targetTable Tên bảng bị tác động (nullable, VD: 'purchase_orders')
     * @param int|null    $targetId    ID bản ghi bị tác động (nullable)
     */
    public static function log(
        int $accountId,
        string $actionType,
        ?string $targetTable = null,
        ?int $targetId = null
    ): bool {
        try {
            $pdo = Database::getConnection();

            $sql = 'INSERT INTO audit_logs (account_id, action_type, target_table, target_id, timestamp)
                    VALUES (:account_id, :action_type, :target_table, :target_id, NOW())';

            $stmt = $pdo->prepare($sql);

            return $stmt->execute([
                ':account_id'   => $accountId,
                ':action_type'  => $actionType,
                ':target_table' => $targetTable,
                ':target_id'    => $targetId,
            ]);
        } catch (PDOException $e) {
            // Nguyên tắc: lỗi ghi log KHÔNG được làm sập luồng nghiệp vụ chính
            // (VD: nếu ghi audit_logs lỗi thì việc approve PO vẫn phải thành công).
            // Chỉ ghi lỗi ra error_log của server để dev theo dõi.
            error_log('[Logger] Failed to write audit log: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Helper tiện dùng: log hành động của user đang đăng nhập (lấy từ session qua Auth).
     * Dùng trong Service khi đã chắc chắn có người dùng đăng nhập.
     */
    public static function logCurrentUser(
        string $actionType,
        ?string $targetTable = null,
        ?int $targetId = null
    ): bool {
        $accountId = Auth::id();

        if ($accountId === null) {
            // Không có user trong session (VD: cron job, system action) -> không log qua hàm này
            error_log('[Logger] logCurrentUser() called without an authenticated session.');
            return false;
        }

        return self::log($accountId, $actionType, $targetTable, $targetId);
    }
}