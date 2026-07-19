<?php
/**
 * File: backend/models/Sales.php
 * Purpose: CRUD cho sales_transactions/sales_transaction_details; khi 1 giao dịch
 * được xác nhận, tự động trừ kho trong bảng `stock` và ghi log vào `stock_movements`
 * trong CÙNG một DB transaction (atomic) - nếu bất kỳ dòng nào không đủ tồn kho,
 * rollback toàn bộ giao dịch.
 *
 * Related: FR-STF-02, BR-02 (tự động trừ kho), BR-03 (không cho âm kho)
 *
 * Bảng liên quan (đã có trong DB, xem db.sql):
 *   sales_transactions(transaction_id, performed_by, transaction_time)
 *   sales_transaction_details(detail_id, transaction_id, product_id, quantity_sold, batch_id)
 *     - batch_id nullable, tham chiếu stock_batches (dùng khi có FEFO, xem Inventory.php)
 *   stock(stock_id, product_id, warehouse_id, quantity_on_hand, last_updated)
 *     - UNIQUE(product_id, warehouse_id), CHECK quantity_on_hand >= 0 (DB tự chặn âm kho)
 *   stock_movements(movement_id, product_id, movement_type, quantity_change, reason,
 *                   reference_id, performed_by, created_at)
 *
 * LƯU Ý QUAN TRỌNG (đã kiểm tra lại db.sql):
 *   - sales_transactions/sales_transaction_details KHÔNG có cột giá (unit_price)
 *     hay total_amount - schema hiện tại chỉ theo dõi SỐ LƯỢNG bán, không có
 *     doanh thu bằng tiền. Nếu dashboard/report cần "doanh thu", products cần
 *     thêm cột price ở Phase 1 (báo lại nhóm nếu cần bổ sung).
 *   - Việc chọn batch theo FEFO (batch_id) là trách nhiệm của Inventory.php
 *     (chưa code ở phase này) - Sales::createTransaction() chỉ CHẤP NHẬN batch_id
 *     nếu Controller/Service đã xác định sẵn, không tự chọn batch ở đây.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

class Sales
{
    private PDO $conn;
    private string $table = 'sales_transactions';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    /**
     * BR-02 + BR-03: Tạo giao dịch bán hàng và trừ kho ngay lập tức, atomic.
     *
     * @param int   $staffId     account_id của Store Staff thực hiện (performed_by)
     * @param int   $warehouseId Kho/kệ xuất hàng (VD: Kệ trưng bày = 1, Tủ mát = 2)
     * @param array $items       [['product_id'=>1,'quantity_sold'=>2,'batch_id'=>null], ...]
     *
     * @return array{success: bool, transaction_id: string|null, message: string}
     */
    public function createTransaction(int $staffId, int $warehouseId, array $items): array
    {
        if (empty($items)) {
            return ['success' => false, 'transaction_id' => null, 'message' => 'Giao dịch không có sản phẩm.'];
        }

        try {
            $this->conn->beginTransaction();

            // Khóa các dòng stock liên quan trước (SELECT ... FOR UPDATE) để 2 giao dịch
            // bán cùng sản phẩm/cùng lúc không thể cùng đọc thấy đủ hàng rồi cùng trừ âm.
            $lockStmt = $this->conn->prepare(
                "SELECT quantity_on_hand FROM stock
                 WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                 FOR UPDATE"
            );

            foreach ($items as $item) {
                $lockStmt->execute([
                    ':product_id'   => (int) $item['product_id'],
                    ':warehouse_id' => $warehouseId,
                ]);
                $row = $lockStmt->fetch();

                if (!$row) {
                    $this->conn->rollBack();
                    return [
                        'success' => false, 'transaction_id' => null,
                        'message' => "Sản phẩm ID {$item['product_id']} chưa có tồn kho tại kho này.",
                    ];
                }

                if ((int) $row['quantity_on_hand'] < (int) $item['quantity_sold']) {
                    $this->conn->rollBack();
                    return [
                        'success' => false, 'transaction_id' => null,
                        'message' => "Sản phẩm ID {$item['product_id']} không đủ tồn kho "
                                    . "(còn {$row['quantity_on_hand']}, cần bán {$item['quantity_sold']}).",
                    ];
                }
            }

            // Đủ hàng cho toàn bộ giỏ hàng -> tạo giao dịch
            $stmt = $this->conn->prepare(
                "INSERT INTO {$this->table} (performed_by, transaction_time) VALUES (:performed_by, NOW())"
            );
            $stmt->execute([':performed_by' => $staffId]);
            $transactionId = $this->conn->lastInsertId();

            $detailStmt = $this->conn->prepare(
                "INSERT INTO sales_transaction_details (transaction_id, product_id, quantity_sold, batch_id)
                 VALUES (:transaction_id, :product_id, :quantity_sold, :batch_id)"
            );

            $updateStockStmt = $this->conn->prepare(
                "UPDATE stock SET quantity_on_hand = quantity_on_hand - :qty, last_updated = NOW()
                 WHERE product_id = :product_id AND warehouse_id = :warehouse_id"
            );

            $movementStmt = $this->conn->prepare(
                "INSERT INTO stock_movements
                    (product_id, movement_type, quantity_change, reason, reference_id, performed_by, created_at)
                 VALUES
                    (:product_id, 'sale', :quantity_change, NULL, :reference_id, :performed_by, NOW())"
            );

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $qty       = (int) $item['quantity_sold'];

                $detailStmt->execute([
                    ':transaction_id' => $transactionId,
                    ':product_id'     => $productId,
                    ':quantity_sold'  => $qty,
                    ':batch_id'       => $item['batch_id'] ?? null,
                ]);

                // CHECK constraint chk_qty_positive ở DB là lớp bảo vệ cuối cùng cho BR-03,
                // nhưng ta đã khóa + kiểm tra trước nên về lý thuyết không thể âm ở đây.
                $updateStockStmt->execute([
                    ':qty'          => $qty,
                    ':product_id'   => $productId,
                    ':warehouse_id' => $warehouseId,
                ]);

                $movementStmt->execute([
                    ':product_id'       => $productId,
                    ':quantity_change'  => -$qty,
                    ':reference_id'     => $transactionId,
                    ':performed_by'     => $staffId,
                ]);
            }

            $this->conn->commit();

            return ['success' => true, 'transaction_id' => $transactionId, 'message' => 'Giao dịch bán hàng thành công.'];
        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            // CHECK constraint vi phạm (mã 23000 ở MySQL 8) cũng rơi vào đây -> vẫn là BR-03
            error_log('[Sales::createTransaction] ' . $e->getMessage());
            return ['success' => false, 'transaction_id' => null, 'message' => 'Có lỗi xảy ra, giao dịch đã được hủy.'];
        }
    }

    public function getById(int $transactionId): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE transaction_id = :id");
        $stmt->execute([':id' => $transactionId]);
        $transaction = $stmt->fetch();

        if ($transaction) {
            $itemStmt = $this->conn->prepare(
                "SELECT std.*, p.product_name, p.sku_code
                 FROM sales_transaction_details std
                 JOIN products p ON p.product_id = std.product_id
                 WHERE std.transaction_id = :id"
            );
            $itemStmt->execute([':id' => $transactionId]);
            $transaction['items'] = $itemStmt->fetchAll();
        }

        return $transaction;
    }

    public function getAll(?string $fromDate = null, ?string $toDate = null): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];

        if ($fromDate !== null) {
            $sql .= " AND transaction_time >= :from_date";
            $params[':from_date'] = $fromDate;
        }
        if ($toDate !== null) {
            $sql .= " AND transaction_time <= :to_date";
            $params[':to_date'] = $toDate;
        }

        $sql .= " ORDER BY transaction_time DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * FR-STF-13: lịch sử bán hàng 7/30 ngày cho 1 sản phẩm (theo SỐ LƯỢNG bán/ngày).
     * Dùng SALES_HISTORY_SHORT_RANGE_DAYS / SALES_HISTORY_LONG_RANGE_DAYS
     * (định nghĩa ở backend/config/app_config.php) làm giá trị mặc định hợp lệ.
     */
    public function getSalesHistory(int $productId, int $days = SALES_HISTORY_SHORT_RANGE_DAYS): array
    {
        $allowedRanges = [SALES_HISTORY_SHORT_RANGE_DAYS, SALES_HISTORY_LONG_RANGE_DAYS];
        $days = in_array($days, $allowedRanges, true) ? $days : SALES_HISTORY_SHORT_RANGE_DAYS;

        $sql = "SELECT DATE(st.transaction_time) AS sale_date,
                       SUM(std.quantity_sold) AS total_quantity
                FROM sales_transaction_details std
                JOIN {$this->table} st ON st.transaction_id = std.transaction_id
                WHERE std.product_id = :product_id
                  AND st.transaction_time >= (NOW() - INTERVAL {$days} DAY)
                GROUP BY DATE(st.transaction_time)
                ORDER BY sale_date ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':product_id' => $productId]);
        return $stmt->fetchAll();
    }
}