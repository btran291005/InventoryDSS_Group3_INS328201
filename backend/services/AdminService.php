<?php
/**
 * File: backend/services/AdminService.php
 * Purpose: Business logic for all Admin actions — account/permission management,
 *          category-based reorder rule configuration, PO approval, API config,
 *          audit log retrieval.
 * Related: FR-ADM-01 through FR-ADM-11, BR-16, BR-17, BR-20
 * Note: PO approval logic (including the BR-20 edit-lock check) lives here,
 *       not in the UI page.
 *
 * QUY ƯỚC CHUNG CỦA SERVICE LAYER (áp dụng cho toàn bộ file trong Phase 4):
 *   - Service KHÔNG tự kiểm tra role (BR-17/BR-19) - việc đó đã được chặn ở
 *     Middleware::guard([ROLE_ADMIN]) ngay đầu file frontend/admin/*.php, TRƯỚC
 *     khi Controller gọi tới Service. Service chỉ nhận $actorId (account_id của
 *     người đang thao tác) để truyền xuống Model/Logger.
 *   - Service không tự mở PDO transaction chồng lên Model - các Model liên quan
 *     (Account, Product, Order...) đã tự atomic hóa (beginTransaction/commit) ở
 *     đúng chỗ cần. Service chỉ điều phối gọi nhiều Model theo đúng thứ tự
 *     nghiệp vụ và validate input trước khi xuống tầng data-access.
 *   - Input từ Controller (thường là $_POST) LUÔN được ép kiểu (int)/trim() ở
 *     đây trước khi truyền vào Model, không tin tưởng dữ liệu thô từ request.
 *   - Return value thống nhất dạng array ['success' => bool, 'message' => string, ...]
 *     giống hệt pattern đã có ở Model, để Controller xử lý nhất quán.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Supplier.php';
require_once __DIR__ . '/../models/Warehouse.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/StockCount.php';

class AdminService
{
    private Account $accountModel;
    private Product $productModel;
    private Supplier $supplierModel;
    private Warehouse $warehouseModel;
    private Order $orderModel;
    private StockCount $stockCountModel;

    public function __construct()
    {
        $this->accountModel    = new Account();
        $this->productModel    = new Product();
        $this->supplierModel   = new Supplier();
        $this->warehouseModel  = new Warehouse();
        $this->orderModel      = new Order();
        $this->stockCountModel = new StockCount();
    }

    // =====================================================================
    // 1. USER MANAGEMENT (FR-ADM-02, BR-17)
    // =====================================================================

    /**
     * FR-ADM-02: tạo tài khoản mới. Kiểm tra độ mạnh mật khẩu (Auth) trước khi
     * xuống Model - Model chỉ chịu trách nhiệm hash + INSERT, không validate rule.
     *
     * @param array $data ['username'=>string,'password'=>string,'full_name'=>string,'role_id'=>int]
     * @return array{success: bool, account_id?: string, message: string}
     */
    public function createAccount(array $data, int $actorId): array
    {
        $username = trim((string) ($data['username'] ?? ''));
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $roleId   = (int) ($data['role_id'] ?? 0);

        if ($username === '' || $fullName === '') {
            return ['success' => false, 'message' => 'Tên đăng nhập và họ tên không được để trống.'];
        }

        if (!in_array($roleId, [ROLE_ADMIN, ROLE_MANAGER, ROLE_STAFF], true)) {
            return ['success' => false, 'message' => 'Vai trò không hợp lệ.'];
        }

        $strength = Auth::validatePasswordStrength($password);
        if (!$strength['valid']) {
            return ['success' => false, 'message' => $strength['message']];
        }

        $result = $this->accountModel->create([
            'username'  => $username,
            'password'  => $password,
            'full_name' => $fullName,
            'role_id'   => $roleId,
            'status'    => 'active',
        ]);

        if ($result['success']) {
            Logger::log($actorId, 'CREATE_ACCOUNT', 'accounts', (int) $result['account_id']);
        }

        return $result;
    }

    /**
     * FR-ADM-02: cập nhật họ tên/vai trò của 1 tài khoản. Đổi mật khẩu xử lý
     * riêng ở resetPassword() để tránh vô tình ghi đè bằng chuỗi rỗng.
     *
     * @param array $data ['full_name'=>string?, 'role_id'=>int?]
     */
    public function updateAccount(int $accountId, array $data, int $actorId): array
    {
        $payload = [];

        if (array_key_exists('full_name', $data)) {
            $fullName = trim((string) $data['full_name']);
            if ($fullName === '') {
                return ['success' => false, 'message' => 'Họ tên không được để trống.'];
            }
            $payload['full_name'] = $fullName;
        }

        if (array_key_exists('role_id', $data)) {
            $roleId = (int) $data['role_id'];
            if (!in_array($roleId, [ROLE_ADMIN, ROLE_MANAGER, ROLE_STAFF], true)) {
                return ['success' => false, 'message' => 'Vai trò không hợp lệ.'];
            }
            $payload['role_id'] = $roleId;
        }

        if (empty($payload)) {
            return ['success' => false, 'message' => 'Không có dữ liệu để cập nhật.'];
        }

        $ok = $this->accountModel->update($accountId, $payload);

        if ($ok) {
            Logger::log($actorId, 'UPDATE_ACCOUNT', 'accounts', $accountId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Đã cập nhật tài khoản.' : 'Có lỗi xảy ra khi cập nhật.'];
    }

    /** FR-ADM-02: Admin đặt lại mật khẩu cho 1 tài khoản (không cần biết mật khẩu cũ). */
    public function resetPassword(int $accountId, string $newPassword, int $actorId): array
    {
        $strength = Auth::validatePasswordStrength($newPassword);
        if (!$strength['valid']) {
            return ['success' => false, 'message' => $strength['message']];
        }

        $ok = $this->accountModel->updatePassword($accountId, $newPassword);

        if ($ok) {
            Logger::log($actorId, 'RESET_PASSWORD', 'accounts', $accountId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Đã đặt lại mật khẩu.' : 'Có lỗi xảy ra khi đặt lại mật khẩu.'];
    }

    /**
     * FR-ADM-02: khóa tài khoản. Chặn Admin tự khóa chính mình để tránh tình
     * huống hệ thống mất hết Admin còn hoạt động (không có trong BR nhưng là
     * an toàn vận hành tối thiểu).
     */
    public function lockAccount(int $accountId, int $actorId): array
    {
        if ($accountId === $actorId) {
            return ['success' => false, 'message' => 'Không thể tự khóa chính tài khoản đang đăng nhập.'];
        }

        $ok = $this->accountModel->lock($accountId);

        if ($ok) {
            Logger::log($actorId, 'LOCK_ACCOUNT', 'accounts', $accountId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Đã khóa tài khoản.' : 'Có lỗi xảy ra khi khóa tài khoản.'];
    }

    public function unlockAccount(int $accountId, int $actorId): array
    {
        $ok = $this->accountModel->unlock($accountId);

        if ($ok) {
            Logger::log($actorId, 'UNLOCK_ACCOUNT', 'accounts', $accountId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Đã mở khóa tài khoản.' : 'Có lỗi xảy ra khi mở khóa tài khoản.'];
    }

    /** FR-ADM-02: danh sách tài khoản, lọc theo role/status nếu cần (truyền qua từ UI filter). */
    public function listAccounts(?int $roleId = null, ?string $status = null): array
    {
        return $this->accountModel->getAll($roleId, $status);
    }

    public function getAccountDetail(int $accountId): array|false
    {
        return $this->accountModel->getById($accountId);
    }

    // =====================================================================
    // 2. ROLE & PERMISSION MANAGEMENT (FR-ADM-03, BR-17)
    // =====================================================================

    public function listRoles(): array
    {
        return $this->accountModel->getAllRoles();
    }

    public function listPermissions(): array
    {
        return $this->accountModel->getAllPermissions();
    }

    /** FR-ADM-03: xem chi tiết các permission đang gán cho 1 role - dùng để render checkbox trên UI. */
    public function getPermissionsForRole(int $roleId): array
    {
        return $this->accountModel->getPermissionsForRole($roleId);
    }

    /**
     * FR-ADM-03: đồng bộ toàn bộ permission của 1 role theo danh sách permission_id
     * gửi từ form (checkbox) - permission nào có trong $permissionIds thì gán,
     * permission nào đang có mà không còn trong danh sách thì thu hồi.
     * Gộp thành 1 hàm duy nhất để UI chỉ cần submit checkbox state hiện tại,
     * không cần tính diff ở Controller.
     *
     * @param int[] $permissionIds
     */
    public function syncRolePermissions(int $roleId, array $permissionIds, int $actorId): array
    {
        $current       = array_column($this->accountModel->getPermissionsForRole($roleId), 'permission_id');
        $desired       = array_map('intval', $permissionIds);
        $toAssign      = array_diff($desired, $current);
        $toRevoke      = array_diff($current, $desired);

        foreach ($toAssign as $permissionId) {
            $this->accountModel->assignPermissionToRole($roleId, (int) $permissionId);
        }
        foreach ($toRevoke as $permissionId) {
            $this->accountModel->revokePermissionFromRole($roleId, (int) $permissionId);
        }

        if (!empty($toAssign) || !empty($toRevoke)) {
            Logger::log($actorId, 'UPDATE_ROLE_PERMISSIONS', 'role_permissions', $roleId);
        }

        return ['success' => true, 'message' => 'Đã cập nhật quyền cho vai trò.'];
    }

    // =====================================================================
    // 3. MASTER DATA MANAGEMENT (FR-ADM-01: Product, Supplier, Warehouse)
    // =====================================================================
    // Ghi chú: master data đã có CRUD đầy đủ ở tầng Model (Product/Supplier/
    // Warehouse). Service ở đây chủ yếu validate input tối thiểu + đảm bảo mọi
    // thay đổi master data đều có audit log (FR-SYS-03), vì các Model này
    // (khác với Product::upsertReorderRule()) KHÔNG tự ghi Logger.

    public function listProducts(?int $categoryId = null, ?string $keyword = null, bool $activeOnly = false): array
    {
        return $this->productModel->getAll($categoryId, $keyword, $activeOnly);
    }

    public function getProductDetail(int $productId): array|false
    {
        return $this->productModel->getById($productId);
    }

    public function listCategories(): array
    {
        return $this->productModel->getCategories();
    }

    /**
     * FR-ADM-01 / FR-ADM-05: tạo sản phẩm mới. category_id bắt buộc (đã được
     * DB enforce qua NOT NULL, nhưng validate sớm ở đây để trả lỗi rõ ràng
     * thay vì để PDOException).
     */
    public function createProduct(array $data, int $actorId): array
    {
        if (empty($data['sku_code']) || empty($data['product_name'])) {
            return ['success' => false, 'message' => 'Mã SKU và tên sản phẩm không được để trống.'];
        }
        if (empty($data['category_id'])) {
            return ['success' => false, 'message' => 'Sản phẩm phải được gán category_type trước khi lưu (FR-ADM-05).'];
        }
        if (empty($data['supplier_id'])) {
            return ['success' => false, 'message' => 'Sản phẩm phải gán nhà cung cấp.'];
        }

        $result = $this->productModel->create($data);

        if ($result['success']) {
            Logger::log($actorId, 'CREATE_PRODUCT', 'products', (int) $result['product_id']);
        }

        return $result;
    }

    public function updateProduct(int $productId, array $data, int $actorId): array
    {
        $ok = $this->productModel->update($productId, $data);

        if ($ok) {
            Logger::log($actorId, 'UPDATE_PRODUCT', 'products', $productId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Đã cập nhật sản phẩm.' : 'Có lỗi xảy ra hoặc không có gì thay đổi.'];
    }

    /**
     * "Xóa" sản phẩm = vô hiệu hóa (deactivate), KHÔNG xóa cứng - lý do đã ghi
     * rõ trong Product::deactivate() (product_id bị nhiều bảng khác tham chiếu).
     */
    public function deactivateProduct(int $productId, int $actorId): array
    {
        $ok = $this->productModel->deactivate($productId);

        if ($ok) {
            Logger::log($actorId, 'DEACTIVATE_PRODUCT', 'products', $productId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Đã ngừng kinh doanh sản phẩm.' : 'Có lỗi xảy ra.'];
    }

    public function activateProduct(int $productId, int $actorId): array
    {
        $ok = $this->productModel->activate($productId);

        if ($ok) {
            Logger::log($actorId, 'ACTIVATE_PRODUCT', 'products', $productId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Đã kích hoạt lại sản phẩm.' : 'Có lỗi xảy ra.'];
    }

    // --- Suppliers ---

    public function listSuppliers(): array
    {
        return $this->supplierModel->getAll();
    }

    public function getSupplierDetail(int $supplierId): array|false
    {
        return $this->supplierModel->getById($supplierId);
    }

    public function createSupplier(array $data, int $actorId): array
    {
        if (empty($data['supplier_name'])) {
            return ['success' => false, 'message' => 'Tên nhà cung cấp không được để trống.'];
        }

        $supplierId = $this->supplierModel->create($data);
        Logger::log($actorId, 'CREATE_SUPPLIER', 'suppliers', (int) $supplierId);

        return ['success' => true, 'supplier_id' => $supplierId, 'message' => 'Đã tạo nhà cung cấp.'];
    }

    public function updateSupplier(int $supplierId, array $data, int $actorId): array
    {
        $ok = $this->supplierModel->update($supplierId, $data);

        if ($ok) {
            Logger::log($actorId, 'UPDATE_SUPPLIER', 'suppliers', $supplierId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Đã cập nhật nhà cung cấp.' : 'Có lỗi xảy ra hoặc không có gì thay đổi.'];
    }

    public function deleteSupplier(int $supplierId, int $actorId): array
    {
        $ok = $this->supplierModel->delete($supplierId);

        if ($ok) {
            Logger::log($actorId, 'DELETE_SUPPLIER', 'suppliers', $supplierId);
        }

        return [
            'success' => $ok,
            'message' => $ok
                ? 'Đã xóa nhà cung cấp.'
                : 'Không thể xóa: nhà cung cấp đang được sản phẩm hoặc đơn hàng tham chiếu.',
        ];
    }

    // --- Warehouses ---

    public function listWarehouses(): array
    {
        return $this->warehouseModel->getAll();
    }

    public function createWarehouse(array $data, int $actorId): array
    {
        if (empty($data['warehouse_name'])) {
            return ['success' => false, 'message' => 'Tên kho không được để trống.'];
        }

        $warehouseId = $this->warehouseModel->create($data);
        Logger::log($actorId, 'CREATE_WAREHOUSE', 'warehouses', (int) $warehouseId);

        return ['success' => true, 'warehouse_id' => $warehouseId, 'message' => 'Đã tạo kho.'];
    }

    public function updateWarehouse(int $warehouseId, array $data, int $actorId): array
    {
        $ok = $this->warehouseModel->update($warehouseId, $data);

        if ($ok) {
            Logger::log($actorId, 'UPDATE_WAREHOUSE', 'warehouses', $warehouseId);
        }

        return ['success' => $ok, 'message' => $ok ? 'Đã cập nhật kho.' : 'Có lỗi xảy ra hoặc không có gì thay đổi.'];
    }

    public function deleteWarehouse(int $warehouseId, int $actorId): array
    {
        $ok = $this->warehouseModel->delete($warehouseId);

        if ($ok) {
            Logger::log($actorId, 'DELETE_WAREHOUSE', 'warehouses', $warehouseId);
        }

        return [
            'success' => $ok,
            'message' => $ok ? 'Đã xóa kho.' : 'Không thể xóa: kho đang còn tồn kho hoặc bị tham chiếu.',
        ];
    }

    // =====================================================================
    // 4. CATEGORY & REORDER RULE CONFIGURATION (FR-ADM-04, BR-16)
    // =====================================================================

    public function listReorderRules(): array
    {
        return $this->productModel->getAllReorderRules();
    }

    public function getReorderRule(int $ruleId): array|false
    {
        return $this->productModel->getReorderRuleById($ruleId);
    }

    /**
     * BR-16: chỉ Admin cấu hình reorder_point/min/max/safety_stock. Validate
     * tính hợp lý của các mốc TRƯỚC khi xuống Model, vì DB không có CHECK ràng
     * buộc thứ tự safety_stock <= reorder_point <= max_stock giữa các cột này.
     *
     * $target: ['category_id'=>int] HOẶC ['product_id'=>int] - đúng 1 trong 2.
     * $data:   ['min_stock','max_stock','safety_stock','reorder_point']
     */
    public function configureReorderRule(array $target, array $data, int $actorId): array
    {
        foreach (['min_stock', 'max_stock', 'safety_stock', 'reorder_point'] as $field) {
            if (!isset($data[$field]) || (int) $data[$field] < 0) {
                return ['success' => false, 'message' => "Giá trị '{$field}' phải là số nguyên không âm."];
            }
        }

        $minStock     = (int) $data['min_stock'];
        $maxStock     = (int) $data['max_stock'];
        $safetyStock  = (int) $data['safety_stock'];
        $reorderPoint = (int) $data['reorder_point'];

        if ($maxStock < $minStock) {
            return ['success' => false, 'message' => 'max_stock phải lớn hơn hoặc bằng min_stock.'];
        }
        if ($reorderPoint < $safetyStock) {
            return ['success' => false, 'message' => 'reorder_point phải lớn hơn hoặc bằng safety_stock.'];
        }
        if ($reorderPoint > $maxStock) {
            return ['success' => false, 'message' => 'reorder_point không được vượt quá max_stock.'];
        }

        return $this->productModel->upsertReorderRule(
            $target,
            [
                'min_stock'     => $minStock,
                'max_stock'     => $maxStock,
                'safety_stock'  => $safetyStock,
                'reorder_point' => $reorderPoint,
            ],
            $actorId
        );
        // Product::upsertReorderRule() đã tự ghi Logger::log('UPDATE_REORDER_RULE')
        // bên trong Model - Service không log lại để tránh trùng audit log.
    }

    // =====================================================================
    // 5. SYSTEM CONFIGURATION (alert levels, api_configs)
    // =====================================================================
    // ⚠️ GHI CHÚ CẦN NHÓM XÁC NHẬN: bảng `api_configs` (config_id, api_name,
    // endpoint_url, api_key, configured_by) đã có trong db.sql nhưng CHƯA có
    // Model riêng (không có ApiConfig.php trong backend/models/). Vì Phase 3
    // (Models) coi như đã đóng, Service ở đây tạm thời query PDO trực tiếp qua
    // Database::getConnection() cho phần này - nếu nhóm muốn đúng chuẩn tách
    // lớp (Model/Service riêng biệt) như các bảng khác, nên bổ sung
    // backend/models/ApiConfig.php ở Phase 3.5 rồi thay lại các hàm bên dưới
    // để gọi qua Model thay vì PDO trực tiếp.

    /** FR-SYS-04 / System Configuration: xem cấu hình API hiện tại (VD: Forecast API endpoint). */
    public function listApiConfigs(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            "SELECT ac.config_id, ac.api_name, ac.endpoint_url, ac.configured_by, a.full_name AS configured_by_name
             FROM api_configs ac
             LEFT JOIN accounts a ON a.account_id = ac.configured_by
             ORDER BY ac.api_name ASC"
        );
        return $stmt->fetchAll();
        // Lưu ý: KHÔNG select api_key ra ngoài danh sách để tránh lộ secret lên UI listing.
    }

    /**
     * Cấu hình/tạo mới 1 API config (VD: Demand Forecast API). Nếu đã tồn tại
     * config cùng api_name thì cập nhật đè, ngược lại tạo mới - giữ 1 api_name
     * chỉ có đúng 1 dòng cấu hình hiệu lực tại một thời điểm.
     */
    public function upsertApiConfig(string $apiName, string $endpointUrl, string $apiKey, int $actorId): array
    {
        $apiName     = trim($apiName);
        $endpointUrl = trim($endpointUrl);

        if ($apiName === '' || $endpointUrl === '' || trim($apiKey) === '') {
            return ['success' => false, 'message' => 'Tên API, endpoint và API key không được để trống.'];
        }

        try {
            $pdo = Database::getConnection();

            $existing = $pdo->prepare("SELECT config_id FROM api_configs WHERE api_name = :api_name");
            $existing->execute([':api_name' => $apiName]);
            $row = $existing->fetch();

            if ($row) {
                $stmt = $pdo->prepare(
                    "UPDATE api_configs
                     SET endpoint_url = :endpoint_url, api_key = :api_key, configured_by = :configured_by
                     WHERE config_id = :id"
                );
                $stmt->execute([
                    ':endpoint_url'  => $endpointUrl,
                    ':api_key'       => $apiKey,
                    ':configured_by' => $actorId,
                    ':id'            => $row['config_id'],
                ]);
                $configId = (int) $row['config_id'];
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO api_configs (api_name, endpoint_url, api_key, configured_by)
                     VALUES (:api_name, :endpoint_url, :api_key, :configured_by)"
                );
                $stmt->execute([
                    ':api_name'      => $apiName,
                    ':endpoint_url'  => $endpointUrl,
                    ':api_key'       => $apiKey,
                    ':configured_by' => $actorId,
                ]);
                $configId = (int) $pdo->lastInsertId();
            }

            Logger::log($actorId, 'UPDATE_API_CONFIG', 'api_configs', $configId);

            return ['success' => true, 'config_id' => $configId, 'message' => 'Đã lưu cấu hình API.'];
        } catch (PDOException $e) {
            error_log('[AdminService::upsertApiConfig] ' . $e->getMessage());
            return ['success' => false, 'message' => 'Có lỗi xảy ra khi lưu cấu hình API.'];
        }
    }

    // =====================================================================
    // 6. PURCHASE ORDER APPROVAL (FR-ADM-06, BR-07, BR-20)
    // =====================================================================
    // Business logic PO approval SỐNG Ở ĐÂY (Service), không phải ở UI page -
    // đúng ghi chú trong docblock gốc của file này. Order::approve()/reject()
    // đã tự kiểm tra status='Pending' và tự ghi Logger, Service chỉ điều phối.

    /** FR-ADM-06: danh sách PO đang chờ duyệt, kèm chi tiết dòng sản phẩm để Admin xem trước khi quyết định. */
    public function listPendingApprovals(): array
    {
        $orders = $this->orderModel->getPendingApproval();

        foreach ($orders as &$po) {
            $po['details'] = $this->orderModel->getDetails((int) $po['po_id']);
        }
        unset($po);

        return $orders;
    }

    /**
     * FR-ADM-06 / BR-07: duyệt 1 PO - Pending -> Approved. Sau bước này PO mới
     * được coi là "đã gửi nhà cung cấp" về mặt nghiệp vụ (BR-07: PO chỉ được
     * gửi NCC sau khi Admin duyệt). Việc gọi API/gửi email thông báo NCC thực
     * tế thuộc về IntegrationService (Phase sau) - AdminService chỉ đổi status.
     */
    public function approvePurchaseOrder(int $poId, int $actorId): array
    {
        return $this->orderModel->approve($poId, $actorId);
    }

    /**
     * FR-ADM-06 / BR-20: từ chối 1 PO - Pending -> Rejected. Theo đúng thiết kế
     * của Order::reject() (đã ghi rõ trong Model), PO bị reject KHÔNG tự mở khóa
     * để Manager sửa lại - Manager phải tạo Draft mới nếu muốn gửi lại.
     */
    public function rejectPurchaseOrder(int $poId, string $reason, int $actorId): array
    {
        if (trim($reason) === '') {
            return ['success' => false, 'message' => 'Vui lòng nhập lý do từ chối đơn hàng.'];
        }

        $result = $this->orderModel->reject($poId, $actorId);

        // Order::reject() chỉ nhận (poId, rejectedBy) và không có cột lưu lý do
        // từ chối trên purchase_orders - ghi lý do vào audit log riêng để không
        // mất thông tin (⚠️ nếu cần hiển thị lý do từ chối lại cho Manager trên
        // UI, cân nhắc bổ sung cột rejection_reason vào purchase_orders ở Phase 1).
        if ($result['success']) {
            Logger::log($actorId, 'REJECT_PO_REASON: ' . $reason, 'purchase_orders', $poId);
        }

        return $result;
    }

    public function getPurchaseOrderDetail(int $poId): array|false
    {
        $po = $this->orderModel->getById($poId);
        if ($po === false) {
            return false;
        }

        $po['details'] = $this->orderModel->getDetails($poId);
        return $po;
    }

    public function listPurchaseOrders(?string $status = null): array
    {
        return $this->orderModel->getAll($status);
    }

    // =====================================================================
    // 7. AUDIT & ACTIVITY LOGS (FR-ADM-07)
    // =====================================================================
    // ⚠️ GHI CHÚ: chưa có AuditLog.php Model riêng (tương tự api_configs ở trên).
    // Query trực tiếp qua PDO tại đây; nên tách ra Model riêng nếu Phase 3 mở lại.

    /**
     * FR-ADM-07: xem audit log toàn hệ thống, filter theo user/loại hành động/khoảng thời gian.
     *
     * @param array $filters ['account_id'=>int?, 'action_type'=>string?, 'from_date'=>string?, 'to_date'=>string?]
     */
    public function getAuditLogs(array $filters = []): array
    {
        $sql = "SELECT al.log_id, al.account_id, a.full_name AS account_name, al.action_type,
                       al.target_table, al.target_id, al.timestamp
                FROM audit_logs al
                JOIN accounts a ON a.account_id = al.account_id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['account_id'])) {
            $sql .= " AND al.account_id = :account_id";
            $params[':account_id'] = (int) $filters['account_id'];
        }
        if (!empty($filters['action_type'])) {
            $sql .= " AND al.action_type LIKE :action_type";
            $params[':action_type'] = '%' . $filters['action_type'] . '%';
        }
        if (!empty($filters['from_date'])) {
            $sql .= " AND al.timestamp >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= " AND al.timestamp <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }

        $sql .= " ORDER BY al.timestamp DESC";

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // =====================================================================
    // 8. KPI COMPARISON REPORT (FR-ADM-08)
    // =====================================================================
    // ⚠️ GHI CHÚ QUAN TRỌNG: 3 chỉ số yêu cầu (stock-out rate, turnover, forecast
    // error) hiện KHÔNG THỂ tính đầy đủ với schema/data hiện có:
    //   - stock-out rate: có thể ước lượng gián tiếp qua stock_movements/shortage_incidents.
    //   - turnover (vòng quay tồn kho): cần dữ liệu GIÁ TRỊ bán ra (COGS/doanh thu),
    //     nhưng products/sales_transaction_details KHÔNG có cột giá (đã ghi chú ở
    //     Product.php/Sales.php) -> chỉ tính được theo SỐ LƯỢNG, không phải giá trị.
    //   - forecast error: cần so sánh demand_forecasts.suggested_qty với sales thực
    //     tế SAU đó cùng kỳ - có thể làm được vì demand_forecasts đã có trong DB.
    // Hàm dưới đây trả về approximation tốt nhất có thể với data hiện tại, kèm
    // ghi chú rõ trong kết quả trả về; KHÔNG bịa thêm cột giá không có trong schema.

    /**
     * FR-ADM-08: so sánh KPI giữa 2 khoảng thời gian.
     *
     * @param string $period1From Y-m-d
     * @param string $period1To   Y-m-d
     * @param string $period2From Y-m-d
     * @param string $period2To   Y-m-d
     */
    public function getKpiComparison(
        string $period1From,
        string $period1To,
        string $period2From,
        string $period2To
    ): array {
        return [
            'period_1' => $this->calculateKpisForPeriod($period1From, $period1To),
            'period_2' => $this->calculateKpisForPeriod($period2From, $period2To),
            'note'     => 'Turnover hiện tính theo SỐ LƯỢNG bán/tồn kho trung bình '
                        . '(schema chưa có cột giá bán để tính theo giá trị - xem ghi chú trong Product.php/Sales.php).',
        ];
    }

    private function calculateKpisForPeriod(string $fromDate, string $toDate): array
    {
        $pdo = Database::getConnection();

        // Stock-out rate xấp xỉ: tỉ lệ sự cố thiếu hàng (shortage_incidents) trên
        // tổng số sản phẩm active trong kỳ - proxy hợp lý nhất với data hiện có.
        $shortageStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT product_id) AS products_with_shortage
             FROM shortage_incidents
             WHERE created_at BETWEEN :from_date AND :to_date"
        );
        $shortageStmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $shortageCount = (int) $shortageStmt->fetch()['products_with_shortage'];

        $totalActiveStmt = $pdo->query("SELECT COUNT(*) AS total FROM products WHERE is_active = 1");
        $totalActive = (int) $totalActiveStmt->fetch()['total'];

        $stockoutRate = $totalActive > 0 ? round(($shortageCount / $totalActive) * 100, 1) : 0.0;

        // Turnover xấp xỉ theo SỐ LƯỢNG: tổng số lượng bán ra / tồn kho trung bình
        // hiện tại (không có snapshot tồn kho đầu/cuối kỳ trong schema, nên dùng
        // tồn kho hiện tại làm mẫu số tham khảo, không phải turnover chuẩn kế toán).
        $soldQtyStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(std.quantity_sold), 0) AS total_sold
             FROM sales_transaction_details std
             JOIN sales_transactions st ON st.transaction_id = std.transaction_id
             WHERE st.transaction_time BETWEEN :from_date AND :to_date"
        );
        $soldQtyStmt->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
        $totalSold = (int) $soldQtyStmt->fetch()['total_sold'];

        $currentStockStmt = $pdo->query("SELECT COALESCE(SUM(quantity_on_hand), 0) AS total_stock FROM stock");
        $currentStock = (int) $currentStockStmt->fetch()['total_stock'];

        $turnoverRatio = $currentStock > 0 ? round($totalSold / $currentStock, 2) : 0.0;

        // Forecast error: so sánh demand_forecasts.suggested_qty (api_status='success')
        // với tổng thực bán của cùng sản phẩm trong kỳ - MAE đơn giản theo số lượng.
        $forecastStmt = $pdo->prepare(
            "SELECT df.product_id, df.suggested_qty,
                    COALESCE((
                        SELECT SUM(std.quantity_sold)
                        FROM sales_transaction_details std
                        JOIN sales_transactions st ON st.transaction_id = std.transaction_id
                        WHERE std.product_id = df.product_id
                          AND st.transaction_time BETWEEN :from_date AND :to_date
                    ), 0) AS actual_sold
             FROM demand_forecasts df
             WHERE df.api_status = 'success'
               AND df.requested_at BETWEEN :from_date2 AND :to_date2"
        );
        $forecastStmt->execute([
            ':from_date'  => $fromDate,
            ':to_date'    => $toDate,
            ':from_date2' => $fromDate,
            ':to_date2'   => $toDate,
        ]);
        $forecastRows = $forecastStmt->fetchAll();

        $forecastError = null;
        if (!empty($forecastRows)) {
            $totalAbsError = 0;
            foreach ($forecastRows as $row) {
                $totalAbsError += abs((int) $row['suggested_qty'] - (int) $row['actual_sold']);
            }
            $forecastError = round($totalAbsError / count($forecastRows), 2);
        }

        return [
            'from_date'             => $fromDate,
            'to_date'               => $toDate,
            'stockout_rate_percent' => $stockoutRate,
            'turnover_ratio'        => $turnoverRatio,
            'forecast_mae'          => $forecastError, // null nếu kỳ đó không có forecast nào api_status='success'
        ];
    }

    // =====================================================================
    // 9. INVENTORY COUNT HISTORY (FR-ADM-09)
    // =====================================================================

    /** FR-ADM-09: lịch sử kiểm kê toàn hệ thống, kèm số dòng lệch mỗi phiên. */
    public function getInventoryCountHistory(): array
    {
        return $this->stockCountModel->getHistory();
    }

    public function getInventoryCountDetail(int $countId): array|false
    {
        return $this->stockCountModel->getSessionById($countId);
    }

    // =====================================================================
    // 10. DATA BACKUP / RESTORE (FR-ADM-10)
    // =====================================================================
    // ⚠️ GHI CHÚ: mysqldump/mysql binary cần được gọi qua exec()/shell_exec(),
    // đây là hành động nhạy cảm cấp hệ điều hành (không chỉ là 1 SQL query như
    // các hàm khác trong Service này). Cân nhắc kỹ về quyền thực thi trên môi
    // trường host thực tế (XAMPP local vs server production) trước khi bật tính
    // năng này cho người dùng cuối. Vì đây là mức ưu tiên "Could" (thấp nhất
    // theo MoSCoW) và cần thông tin hạ tầng cụ thể (đường dẫn mysqldump, quyền
    // ghi file) mà nhóm chưa cung cấp, tạm để placeholder rõ ràng thay vì đoán
    // đường dẫn binary có thể sai trên máy người dùng.

    /**
     * FR-ADM-10: sao lưu dữ liệu ra file .sql.
     * TODO: cần nhóm xác nhận đường dẫn mysqldump.exe (XAMPP thường ở
     * C:\xampp\mysql\bin\mysqldump.exe) và thư mục lưu backup trước khi implement.
     */
    public function backupDatabase(int $actorId): array
    {
        return [
            'success' => false,
            'message' => 'Chức năng backup chưa được cấu hình - cần xác nhận đường dẫn mysqldump và thư mục lưu trữ.',
        ];
    }

    /**
     * FR-ADM-10: phục hồi dữ liệu từ file .sql đã backup.
     * TODO: tương tự backupDatabase() - cần xác nhận đường dẫn mysql.exe.
     */
    public function restoreDatabase(string $backupFilePath, int $actorId): array
    {
        return [
            'success' => false,
            'message' => 'Chức năng restore chưa được cấu hình - cần xác nhận đường dẫn mysql binary và quy trình an toàn trước khi phục hồi.',
        ];
    }
}