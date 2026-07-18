<?php
/**
 * File: backend/models/StockCount.php
 * Purpose: CRUD cho stock_counts, stock_count_details, customer_feedback,
 * shortage_incidents.
 * Related: FR-STF-04, FR-STF-09, FR-STF-11, FR-ADM-09, BR-14
 *
 * Bảng liên quan (đã có trong DB, xem db.sql):
 *   stock_counts(count_id, performed_by, count_date)
 *   stock_count_details(count_detail_id, count_id, product_id, system_qty, actual_qty,
 *                        discrepancy) -- discrepancy là GENERATED COLUMN
 *                        (= actual_qty - system_qty), KHÔNG được INSERT/UPDATE thủ công.
 *   customer_feedback(feedback_id, product_id NULL, logged_by, feedback_text, created_at)
 *   shortage_incidents(incident_id, product_id, handled_by, resolution_action,
 *                       status ENUM('Open','Resolved'), created_at)
 *
 * ⚠️ SCHEMA GAP CẦN NHÓM XÁC NHẬN LẠI:
 *   stock_counts / stock_count_details KHÔNG có cột warehouse_id, trong khi tồn kho
 *   thực tế (`stock`) được lưu theo TỪNG warehouse (1 sản phẩm có thể có nhiều dòng
 *   stock ở nhiều kho khác nhau). Vì vậy:
 *     - system_qty ở đây được tính bằng TỔNG quantity_on_hand của sản phẩm trên
 *       TẤT CẢ các warehouse (khớp với cách dữ liệu mẫu trong db.sql được tạo,
 *       VD sản phẩm 10: kho 1 có 45 + kho 4 có 200 = 245, đúng bằng system_qty mẫu).
 *     - VÌ KHÔNG BIẾT chênh lệch phát sinh ở kho nào, completeSession() KHÔNG tự
 *       động sửa lại bảng `stock` (tránh trừ/cộng nhầm kho) - chỉ ghi nhận chênh
 *       lệch vào stock_movements để Admin/Manager xem xét và điều chỉnh thủ công.
 *   Nếu muốn kiểm kê theo TỪNG kho và tự động sửa đúng dòng `stock` tương ứng,
 *   cần bổ sung cột warehouse_id vào stock_counts (hoặc stock_count_details) ở
 *   Phase 1. Báo lại nếu muốn tôi đề xuất câu ALTER TABLE cho việc này.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class StockCount
{
    private PDO $conn;
    private string $table = 'stock_counts';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    // -------------------------------------------------------------------
    // stock_counts / stock_count_details
    // -------------------------------------------------------------------

    /** FR-STF-09: bắt đầu 1 phiên kiểm kê mới. */
    public function createSession(int $staffId): string
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table} (performed_by, count_date) VALUES (:performed_by, NOW())"
        );
        $stmt->execute([':performed_by' => $staffId]);
        return $this->conn->lastInsertId();
    }

    /**
     * FR-STF-04 / BR-14: ghi nhận số lượng đếm thực tế cho 1 sản phẩm.
     * system_qty được TỰ ĐỘNG lấy = tổng quantity_on_hand hiện tại của sản phẩm
     * trên tất cả các kho (xem ghi chú SCHEMA GAP ở đầu file).
     *
     * @return array{success: bool, detail_id?: string, system_qty?: int,
     *               actual_qty?: int, discrepancy?: int, message: string}
     */
    public function addCountItem(int $countId, int $productId, int $actualQty): array
    {
        $sysStmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(quantity_on_hand), 0) AS system_qty
             FROM stock WHERE product_id = :product_id"
        );
        $sysStmt->execute([':product_id' => $productId]);
        $systemQty = (int) $sysStmt->fetch()['system_qty'];

        $stmt = $this->conn->prepare(
            "INSERT INTO stock_count_details (count_id, product_id, system_qty, actual_qty)
             VALUES (:count_id, :product_id, :system_qty, :actual_qty)"
        );
        $stmt->execute([
            ':count_id'   => $countId,
            ':product_id' => $productId,
            ':system_qty' => $systemQty,
            ':actual_qty' => $actualQty,
        ]);

        return [
            'success'     => true,
            'detail_id'   => $this->conn->lastInsertId(),
            'system_qty'  => $systemQty,
            'actual_qty'  => $actualQty,
            'discrepancy' => $actualQty - $systemQty, // giống hệt cách DB tự tính (GENERATED)
            'message'     => 'Đã ghi nhận kết quả đếm.',
        ];
    }

    /**
     * FR-STF-09 / BR-14: hoàn tất phiên kiểm kê - với mỗi dòng có discrepancy != 0,
     * ghi 1 dòng vào stock_movements (movement_type = 'count_correction') để lưu
     * vết, KHÔNG tự sửa bảng `stock` (xem SCHEMA GAP ở đầu file).
     */
    public function finalizeSession(int $countId, int $staffId): array
    {
        try {
            $this->conn->beginTransaction();

            $itemStmt = $this->conn->prepare(
                "SELECT * FROM stock_count_details WHERE count_id = :count_id"
            );
            $itemStmt->execute([':count_id' => $countId]);
            $items = $itemStmt->fetchAll();

            $movementStmt = $this->conn->prepare(
                "INSERT INTO stock_movements
                    (product_id, movement_type, quantity_change, reason, reference_id, performed_by, created_at)
                 VALUES
                    (:product_id, 'count_correction', :quantity_change,
                     'Chênh lệch kiểm kê - cần đối chiếu thủ công theo từng kho', :reference_id, :performed_by, NOW())"
            );

            $discrepancyCount = 0;
            foreach ($items as $item) {
                $discrepancy = (int) $item['discrepancy'];
                if ($discrepancy !== 0) {
                    $discrepancyCount++;
                    $movementStmt->execute([
                        ':product_id'      => $item['product_id'],
                        ':quantity_change' => $discrepancy,
                        ':reference_id'    => $countId,
                        ':performed_by'    => $staffId,
                    ]);
                }
            }

            $this->conn->commit();

            return [
                'success'            => true,
                'total_items'        => count($items),
                'discrepancy_items'  => $discrepancyCount,
                'message'            => 'Đã hoàn tất phiên kiểm kê.',
            ];
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('[StockCount::finalizeSession] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Có lỗi xảy ra khi hoàn tất kiểm kê.'];
        }
    }

    public function getSessionById(int $countId): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE count_id = :id");
        $stmt->execute([':id' => $countId]);
        $session = $stmt->fetch();

        if ($session) {
            $itemStmt = $this->conn->prepare(
                "SELECT scd.*, p.product_name, p.sku_code
                 FROM stock_count_details scd
                 JOIN products p ON p.product_id = scd.product_id
                 WHERE scd.count_id = :id"
            );
            $itemStmt->execute([':id' => $countId]);
            $session['items'] = $itemStmt->fetchAll();
        }

        return $session;
    }

    /** FR-ADM-09: lịch sử kiểm kê toàn hệ thống + số dòng lệch, cho Admin theo dõi bất thường. */
    public function getHistory(): array
    {
        $sql = "SELECT sc.*, a.full_name AS performed_by_name,
                       COUNT(scd.count_detail_id) AS total_items,
                       SUM(CASE WHEN scd.discrepancy != 0 THEN 1 ELSE 0 END) AS discrepancy_items
                FROM {$this->table} sc
                JOIN accounts a ON a.account_id = sc.performed_by
                LEFT JOIN stock_count_details scd ON scd.count_id = sc.count_id
                GROUP BY sc.count_id
                ORDER BY sc.count_date DESC";

        $stmt = $this->conn->query($sql);
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------
    // customer_feedback (FR-STF-11)
    // -------------------------------------------------------------------

    public function addCustomerFeedback(?int $productId, int $loggedBy, string $feedbackText): string
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO customer_feedback (product_id, logged_by, feedback_text, created_at)
             VALUES (:product_id, :logged_by, :feedback_text, NOW())"
        );
        $stmt->execute([
            ':product_id'    => $productId,
            ':logged_by'     => $loggedBy,
            ':feedback_text' => $feedbackText,
        ]);

        return $this->conn->lastInsertId();
    }

    public function getCustomerFeedback(?int $productId = null): array
    {
        $sql = "SELECT cf.*, p.product_name, a.full_name AS logged_by_name
                FROM customer_feedback cf
                LEFT JOIN products p ON p.product_id = cf.product_id
                JOIN accounts a ON a.account_id = cf.logged_by
                WHERE 1=1";
        $params = [];

        if ($productId !== null) {
            $sql .= " AND cf.product_id = :product_id";
            $params[':product_id'] = $productId;
        }

        $sql .= " ORDER BY cf.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------
    // shortage_incidents (User Story: log stock-shortage incidents)
    // -------------------------------------------------------------------

    public function createShortageIncident(int $productId, int $handledBy, ?string $resolutionAction = null): string
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO shortage_incidents (product_id, handled_by, resolution_action, status, created_at)
             VALUES (:product_id, :handled_by, :resolution_action, 'Open', NOW())"
        );
        $stmt->execute([
            ':product_id'        => $productId,
            ':handled_by'        => $handledBy,
            ':resolution_action' => $resolutionAction,
        ]);

        return $this->conn->lastInsertId();
    }

    public function resolveShortageIncident(int $incidentId, string $resolutionAction): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE shortage_incidents
             SET status = 'Resolved', resolution_action = :resolution_action
             WHERE incident_id = :id"
        );
        return $stmt->execute([
            ':resolution_action' => $resolutionAction,
            ':id'                => $incidentId,
        ]);
    }

    public function getShortageIncidents(?string $status = null): array
    {
        $sql = "SELECT si.*, p.product_name, p.sku_code, a.full_name AS handled_by_name
                FROM shortage_incidents si
                JOIN products p ON p.product_id = si.product_id
                JOIN accounts a ON a.account_id = si.handled_by
                WHERE 1=1";
        $params = [];

        if ($status !== null) {
            $sql .= " AND si.status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY si.created_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}