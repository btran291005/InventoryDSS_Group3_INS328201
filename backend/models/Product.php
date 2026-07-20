<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

class Product
{
    private PDO $conn;
    private string $table = 'products';

    public function __construct()
    {
        $this->conn = Database::getConnection();
    }

    // CRUD: products

    /* FR-ADM-01 / FR-STF-01: danh sách sản phẩm, JOIN sẵn category để hiển thị. */
    public function getAll(?int $categoryId = null, ?string $keyword = null, bool $activeOnly = false): array
    {
        $sql = "SELECT p.*, c.category_name, c.category_type, c.requires_fefo
                FROM {$this->table} p
                JOIN categories c ON c.category_id = p.category_id
                WHERE 1=1";
        $params = [];

        if ($categoryId !== null) {
            $sql .= " AND p.category_id = :category_id";
            $params[':category_id'] = $categoryId;
        }

        if ($keyword !== null && $keyword !== '') {
            $sql .= " AND (p.product_name LIKE :keyword OR p.sku_code LIKE :keyword)";
            $params[':keyword'] = "%{$keyword}%";
        }

        if ($activeOnly) {
            $sql .= " AND p.is_active = 1";
        }

        $sql .= " ORDER BY p.product_name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getById(int $productId): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT p.*, c.category_name, c.category_type, c.requires_fefo
             FROM {$this->table} p
             JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_id = :id"
        );
        $stmt->execute([':id' => $productId]);
        return $stmt->fetch();
    }

    public function getBySkuCode(string $skuCode): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table} WHERE sku_code = :sku_code");
        $stmt->execute([':sku_code' => $skuCode]);
        return $stmt->fetch();
    }

    /* BR-01: sku_code là UNIQUE KEY trong DB; nếu trùng, PDO ném PDOException mã lỗi 23000 -> bắt lại và trả về thông báo rõ ràng thay vì để lộ lỗi SQL thô.
     * unit_cost (giá nhập từ NCC) bắt buộc >= 0 - dùng để tính giá trị đơn đặt hàng (PO Amount) ở PurchaseOrderService/AdminService, KHÔNG phải giá bán lẻ. */
    public function create(array $data): array
    {
        $unitCost = (float) ($data['unit_cost'] ?? 0);
        if ($unitCost < 0) {
            return ['success' => false, 'message' => 'Giá nhập (unit_cost) không được là số âm.'];
        }

        $sql = "INSERT INTO {$this->table}
                    (sku_code, product_name, category_id, supplier_id, unit, shelf_life_days, unit_cost, is_active)
                VALUES
                    (:sku_code, :product_name, :category_id, :supplier_id, :unit, :shelf_life_days, :unit_cost, :is_active)";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':sku_code'        => $data['sku_code'],
                ':product_name'    => $data['product_name'],
                ':category_id'     => $data['category_id'],
                ':supplier_id'     => $data['supplier_id'],
                ':unit'            => $data['unit'],
                ':shelf_life_days' => $data['shelf_life_days'] ?? null,
                ':unit_cost'       => $unitCost,
                ':is_active'       => $data['is_active'] ?? true,
            ]);

            return [
                'success'    => true,
                'product_id' => $this->conn->lastInsertId(),
                'message'    => 'Tạo sản phẩm thành công.',
            ];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['success' => false, 'message' => "Mã SKU '{$data['sku_code']}' đã tồn tại."];
            }
            error_log('[Product::create] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Có lỗi xảy ra khi tạo sản phẩm.'];
        }
    }

    /* $allowed field 'unit_cost': giá nhập từ NCC - PHẢI ép kiểu float >= 0 nếu có mặt trong $data (validate ở Service trước khi gọi). */
    public function update(int $productId, array $data): bool
    {
        $allowed = ['sku_code', 'product_name', 'category_id', 'supplier_id', 'unit', 'shelf_life_days', 'unit_cost', 'is_active'];

        $fields = [];
        $params = [':id' => $productId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $field === 'unit_cost' ? (float) $data[$field] : $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE product_id = :id";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($params);
    }

    /* "Xóa" sản phẩm = vô hiệu hóa (is_active = 0), KHÔNG xóa cứng.
     * Lý do: product_id được nhiều bảng khác tham chiếu (stock, stock_batches, reorder_rules, sales_transaction_details, purchase_order_details, customer_feedback, shortage_incidents...) mà không có ON DELETE CASCADE -> DELETE cứng sẽ gây lỗi ràng buộc khóa ngoại và mất lịch sử giao dịch. */
    public function deactivate(int $productId): bool
    {
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET is_active = 0 WHERE product_id = :id");
        return $stmt->execute([':id' => $productId]);
    }

    public function activate(int $productId): bool
    {
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET is_active = 1 WHERE product_id = :id");
        return $stmt->execute([':id' => $productId]);
    }

    /* Xóa cứng - chỉ dùng khi chắc chắn sản phẩm chưa từng phát sinh giao dịch nào.
     * Nếu còn bị FK tham chiếu, MySQL sẽ từ chối (error code 23000) -> trả về false thay vì để lộ lỗi SQL, gợi ý Controller dùng deactivate() thay thế. */
    public function delete(int $productId): bool
    {
        try {
            $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE product_id = :id");
            return $stmt->execute([':id' => $productId]);
        } catch (PDOException $e) {
            error_log('[Product::delete] FK constraint hoặc lỗi khác: ' . $e->getMessage());
            return false;
        }
    }

    // Helper: categories (không có Category.php riêng, đọc trực tiếp ở đây)

    /* FR-ADM-05: dropdown category khi tạo/sửa sản phẩm */
    public function getCategories(): array
    {
        $stmt = $this->conn->query("SELECT * FROM categories ORDER BY category_name ASC");
        return $stmt->fetchAll();
    }

    // reorder_rules

    /* BR-05: Lấy rule đang có hiệu lực cho 1 sản phẩm. Ưu tiên rule riêng theo product_id; nếu không có, fallback về rule chung của category_id mà sản phẩm đó thuộc về. */
    public function getEffectiveReorderRule(int $productId): array|false
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM reorder_rules WHERE product_id = :product_id LIMIT 1"
        );
        $stmt->execute([':product_id' => $productId]);
        $rule = $stmt->fetch();

        if ($rule) {
            return $rule;
        }

        $product = $this->getById($productId);
        if (!$product) {
            return false;
        }

        $stmt = $this->conn->prepare(
            "SELECT * FROM reorder_rules WHERE category_id = :category_id AND product_id IS NULL LIMIT 1"
        );
        $stmt->execute([':category_id' => $product['category_id']]);
        return $stmt->fetch();
    }

    public function getReorderRuleById(int $ruleId): array|false
    {
        $stmt = $this->conn->prepare("SELECT * FROM reorder_rules WHERE rule_id = :id");
        $stmt->execute([':id' => $ruleId]);
        return $stmt->fetch();
    }

    public function getAllReorderRules(): array
    {
        $sql = "SELECT rr.*, c.category_name, p.product_name, p.sku_code
                FROM reorder_rules rr
                LEFT JOIN categories c ON c.category_id = rr.category_id
                LEFT JOIN products p ON p.product_id = rr.product_id
                ORDER BY (rr.product_id IS NOT NULL) DESC, c.category_name ASC";
        $stmt = $this->conn->query($sql);
        return $stmt->fetchAll();
    }

    /* BR-16: chỉ Admin được cấu hình reorder_point/min/max/safety_stock.
     * Model không kiểm tra role (đã có Middleware::guard([ROLE_ADMIN]) ở Controller) - nhưng LUÔN ghi audit log vì đây là hành động thay đổi chính sách nhạy cảm (FR-SYS-03, khớp mẫu 'UPDATE_REORDER_RULE' đã có sẵn trong audit_logs seed data).
     * $target: ['category_id' => int] HOẶC ['product_id' => int] (đúng 1 trong 2). */
    public function upsertReorderRule(array $target, array $data, int $updatedBy): array
    {
        $categoryId = $target['category_id'] ?? null;
        $productId  = $target['product_id'] ?? null;

        if (($categoryId === null) === ($productId === null)) {
            return ['success' => false, 'message' => 'Phải chỉ định đúng 1 trong 2: category_id hoặc product_id.'];
        }

        try {
            if ($productId !== null) {
                $existing = $this->conn->prepare("SELECT rule_id FROM reorder_rules WHERE product_id = :product_id");
                $existing->execute([':product_id' => $productId]);
            } else {
                $existing = $this->conn->prepare(
                    "SELECT rule_id FROM reorder_rules WHERE category_id = :category_id AND product_id IS NULL"
                );
                $existing->execute([':category_id' => $categoryId]);
            }
            $existingRule = $existing->fetch();

            $params = [
                ':min_stock'     => $data['min_stock'],
                ':max_stock'     => $data['max_stock'],
                ':safety_stock'  => $data['safety_stock'],
                ':reorder_point' => $data['reorder_point'],
                ':updated_by'    => $updatedBy,
            ];

            if ($existingRule) {
                $params[':id'] = $existingRule['rule_id'];
                $stmt = $this->conn->prepare(
                    "UPDATE reorder_rules
                     SET min_stock = :min_stock, max_stock = :max_stock,
                         safety_stock = :safety_stock, reorder_point = :reorder_point,
                         updated_by = :updated_by
                     WHERE rule_id = :id"
                );
                $stmt->execute($params);
                $ruleId = $existingRule['rule_id'];
            } else {
                $params[':category_id'] = $categoryId;
                $params[':product_id']  = $productId;
                $stmt = $this->conn->prepare(
                    "INSERT INTO reorder_rules
                        (category_id, product_id, min_stock, max_stock, safety_stock, reorder_point, updated_by)
                     VALUES
                        (:category_id, :product_id, :min_stock, :max_stock, :safety_stock, :reorder_point, :updated_by)"
                );
                $stmt->execute($params);
                $ruleId = (int) $this->conn->lastInsertId();
            }

            Logger::log($updatedBy, 'UPDATE_REORDER_RULE', 'reorder_rules', (int) $ruleId);

            return ['success' => true, 'rule_id' => $ruleId, 'message' => 'Đã lưu reorder rule.'];
        } catch (PDOException $e) {
            error_log('[Product::upsertReorderRule] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Có lỗi xảy ra khi lưu reorder rule.'];
        }
    }
}