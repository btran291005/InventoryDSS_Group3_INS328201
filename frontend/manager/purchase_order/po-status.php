<?php
/**
 * File: frontend/manager/purchase_order/po-status.php
 * Purpose: Manager xem trạng thái các đơn đặt hàng (PO) do CHÍNH MÌNH tạo -
 * chờ Admin duyệt / đã duyệt / đã từ chối / đã giao. Dùng
 * ManagerService::listMyPurchaseOrders() + getPurchaseOrderDetail() (đã có
 * sẵn, ManagerService không tự lọc theo managerId nên Service truyền vào
 * Auth::id() ở phía trang này).
 * Related: FR-MGR-06
 *
 * Lưu ý path: file nằm trong subfolder purchase_order/, dùng 3 cấp '../../../'
 * để require backend, và '../../components/' (2 cấp) để include layout -
 * giống quy ước đã áp dụng ở frontend/admin/setting/*.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../backend/config/app_config.php';
require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/core/Logger.php';
require_once __DIR__ . '/../../../backend/core/Auth.php';
require_once __DIR__ . '/../../../backend/core/Middleware.php';
require_once __DIR__ . '/../../../backend/services/ManagerService.php';

Middleware::guard([ROLE_MANAGER]);

$managerService = new ManagerService();

$filterStatus = $_GET['status'] ?? null;
$orders = $managerService->listMyPurchaseOrders(Auth::id(), $filterStatus ?: null);

// Nếu có ?view=<po_id> -> hiển thị chi tiết dòng sản phẩm của đơn đó
$viewingPoId = isset($_GET['view']) ? (int) $_GET['view'] : null;
$viewingPo = $viewingPoId ? $managerService->getPurchaseOrderDetail($viewingPoId) : false;

// Ngăn Manager xem PO của người khác qua URL thủ công (?view=<po_id lạ>)
if ($viewingPo !== false && (int) $viewingPo['created_by'] !== Auth::id()) {
    $viewingPo = false;
}

$statusBadge = [
    'Draft'     => 'bg-secondary',
    'Pending'   => 'bg-warning text-dark',
    'Approved'  => 'bg-success',
    'Rejected'  => 'bg-danger',
    'Delivered' => 'bg-primary',
];

$pageTitle = 'Purchase Order Status';
require_once __DIR__ . '/../../components/header.php';
require_once __DIR__ . '/../../components/sidebar.php';
?>

<h1 class="h4 mb-4">My Purchase Orders</h1>

<form method="get" class="row g-2 mb-3">
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">-- All statuses --</option>
            <?php foreach (['Draft', 'Pending', 'Approved', 'Rejected', 'Delivered'] as $status): ?>
                <option value="<?= $status ?>" <?= $filterStatus === $status ? 'selected' : '' ?>>
                    <?= $status ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Orders (<?= count($orders) ?>)</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>#PO</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th class="text-end">Total Value</th>
                            <th>Created At</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No purchase orders found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($orders as $po): ?>
                            <tr class="<?= $viewingPoId === (int) $po['po_id'] ? 'table-active' : '' ?>">
                                <td>#<?= (int) $po['po_id'] ?></td>
                                <td><?= htmlspecialchars($po['supplier_name']) ?></td>
                                <td>
                                    <span class="badge <?= $statusBadge[$po['status']] ?? 'bg-secondary' ?>">
                                        <?= htmlspecialchars($po['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end"><?= number_format((float) $po['total_amount'], 0) ?></td>
                                <td><?= htmlspecialchars($po['created_at']) ?></td>
                                <td class="text-end">
                                    <a href="?view=<?= (int) $po['po_id'] ?><?= $filterStatus ? '&status=' . urlencode($filterStatus) : '' ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($viewingPo === false): ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    Select an order on the left to view its details.
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Order #<?= (int) $viewingPo['po_id'] ?></span>
                    <span class="badge <?= $statusBadge[$viewingPo['status']] ?? 'bg-secondary' ?>">
                        <?= htmlspecialchars($viewingPo['status']) ?>
                    </span>
                </div>
                <div class="card-body">
                    <dl class="row mb-3">
                        <dt class="col-5">Supplier</dt>
                        <dd class="col-7"><?= htmlspecialchars($viewingPo['supplier_name']) ?></dd>
                        <dt class="col-5">Created At</dt>
                        <dd class="col-7"><?= htmlspecialchars($viewingPo['created_at']) ?></dd>
                        <?php if ($viewingPo['approved_at']): ?>
                            <dt class="col-5"><?= $viewingPo['status'] === 'Rejected' ? 'Rejected At' : 'Approved At' ?></dt>
                            <dd class="col-7">
                                <?= htmlspecialchars($viewingPo['approved_at']) ?>
                                (<?= htmlspecialchars($viewingPo['approved_by_name'] ?? '—') ?>)
                            </dd>
                        <?php endif; ?>
                    </dl>

                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th class="text-end">Suggested</th>
                                <th class="text-end">Approved (mine)</th>
                                <th class="text-end">Received</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($viewingPo['details'] as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['sku_code']) ?></td>
                                    <td><?= htmlspecialchars($item['product_name']) ?></td>
                                    <td class="text-end"><?= (int) $item['suggested_qty'] ?></td>
                                    <td class="text-end"><?= (int) $item['approved_qty'] ?></td>
                                    <td class="text-end">
                                        <?= $item['received_qty'] !== null ? (int) $item['received_qty'] : '—' ?>
                                    </td>
                                </tr>
                                <?php if (!empty($item['discrepancy_reason'])): ?>
                                    <tr class="table-warning">
                                        <td colspan="5" class="small">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            Discrepancy: <?= htmlspecialchars($item['discrepancy_reason']) ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($viewingPo['status'] === 'Rejected'): ?>
                        <div class="alert alert-danger mb-0">
                            This order was rejected. Create a new draft if you still need to reorder these items (BR-20).
                        </div>
                    <?php elseif ($viewingPo['status'] === 'Pending'): ?>
                        <div class="alert alert-warning mb-0">
                            Waiting for Admin approval. This order cannot be edited while pending (BR-20).
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../components/footer.php'; ?>