<?php
/**
 * File: frontend/manager/purchase_order/po_submit.php
 * Purpose: UI xem chi tiết PO Draft, sửa approved_qty từng dòng (BR-06), rồi
 * submit cho Admin duyệt (Draft -> Pending).
 * Related: FR-MGR-03, FR-MGR-04, FR-MGR-05, BR-06, BR-07, BR-20
 * Calls: ManagerService::getPurchaseOrderDetail(), overridePoLineQuantity(),
 *        submitPurchaseOrder(), listMyPurchaseOrders()
 *
 * LUỒNG TRANG:
 *   - Không có ?po_id -> danh sách toàn bộ PO Draft của Manager hiện tại,
 *     chọn 1 đơn để vào sửa/submit (đơn đến từ reorder_suggestions.php cũng
 *     redirect thẳng vào đây kèm po_id).
 *   - Có ?po_id -> chi tiết đơn, Manager sửa approved_qty từng dòng (BR-06:
 *     mỗi lần sửa tự lưu ngay, không cần đợi submit cả đơn), rồi bấm "Gửi
 *     Admin duyệt" để chuyển Draft -> Pending (BR-07: sau bước này đơn coi
 *     như đã khóa, không sửa được nữa - BR-20).
 *
 * Style/layout đồng bộ frontend/admin/*.php.
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
$actorId = Auth::id();

$flashMessage = '';
$flashIsError = false;

// =========================================================================
// XỬ LÝ FORM SUBMIT
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $poId   = (int) ($_POST['po_id'] ?? 0);

    if ($action === 'update_line') {
        $poDetailId = (int) ($_POST['po_detail_id'] ?? 0);
        $newQty     = (int) ($_POST['new_qty'] ?? 0);
        $result = $managerService->overridePoLineQuantity($poId, $poDetailId, $newQty, $actorId);
        header('Location: po_submit.php?po_id=' . $poId . '&flash=' . urlencode($result['message']) . '&err=' . ($result['success'] ? '0' : '1'));
        exit;
    }

    if ($action === 'submit_po') {
        $result = $managerService->submitPurchaseOrder($poId);
        if ($result['success']) {
            // BR-20: sau khi submit, đơn bị khóa - quay về danh sách thay vì ở
            // lại trang chi tiết (trang chi tiết sẽ tự ẩn form sửa nếu status
            // != Draft, nhưng quay về danh sách rõ ràng hơn cho UX).
            header('Location: po_submit.php?flash=' . urlencode($result['message']));
            exit;
        }
        header('Location: po_submit.php?po_id=' . $poId . '&flash=' . urlencode($result['message']) . '&err=1');
        exit;
    }
}

if (isset($_GET['flash'])) {
    $flashMessage = (string) $_GET['flash'];
    $flashIsError = ($_GET['err'] ?? '0') === '1';
}

// =========================================================================
// DỮ LIỆU HIỂN THỊ
// =========================================================================
$selectedPoId = isset($_GET['po_id']) ? (int) $_GET['po_id'] : null;
$selectedPo = null;

if ($selectedPoId !== null) {
    $selectedPo = $managerService->getPurchaseOrderDetail($selectedPoId);
    if ($selectedPo === false) {
        $flashMessage = 'Không tìm thấy đơn đặt hàng.';
        $flashIsError = true;
        $selectedPoId = null;
    }
}

$myOrders = $selectedPoId === null ? $managerService->listMyPurchaseOrders($actorId) : [];

/** Màu badge theo trạng thái PO - khớp inline style đã dùng ở admin/po_approval.php. */
function poStatusBadgeStyle(string $status): string
{
    return match ($status) {
        'Draft'     => 'background: var(--color-info-bg); color: var(--color-info);',
        'Pending'   => 'background: var(--color-warning-bg); color: var(--color-warning);',
        'Approved'  => 'background: var(--color-success-bg); color: var(--color-success);',
        'Rejected'  => 'background: var(--color-danger-bg); color: var(--color-danger);',
        'Delivered' => 'background: var(--color-success-bg); color: var(--color-success);',
        default     => 'background: var(--surface-alt); color: var(--text-muted);',
    };
}

$pageTitle   = 'Purchase Orders';
$breadcrumbs = ['Manager', 'Purchase Order'];
$activeMenu  = 'po';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/theme_variables.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/custom.css" rel="stylesheet">
</head>
<body>
    <div class="app-shell">
        <?php require __DIR__ . '/../../components/sidebar.php'; ?>

        <div class="app-content">
            <?php require __DIR__ . '/../../components/header.php'; ?>

            <main class="app-main">

                <?php if ($flashMessage !== ''): ?>
                    <div class="alert <?= $flashIsError ? 'alert-danger' : 'alert-success' ?> py-2 px-3" style="font-size: .87rem;">
                        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($selectedPo === null): ?>

                    <!-- ================= DANH SÁCH PO CỦA TÔI ================= -->
                    <div class="mb-4">
                        <h2 class="page-heading mb-1">Purchase Orders</h2>
                        <p class="page-subheading mb-0">Đơn đặt hàng đã tạo - vào từng đơn để sửa số lượng (BR-06) hoặc gửi Admin duyệt.</p>
                    </div>

                    <div class="panel-card">
                        <div class="panel-card-header">
                            <h3 class="panel-card-title">Đơn của tôi</h3>
                            <span class="panel-card-note"><?= count($myOrders) ?> đơn</span>
                        </div>

                        <?php if (empty($myOrders)): ?>
                            <div class="empty-state">Chưa có đơn đặt hàng nào. Vào <a href="../reorder/reorder_suggestions.php">Reorder Suggestions</a> để tạo đơn mới.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table data-table align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Mã PO</th>
                                            <th>Nhà cung cấp</th>
                                            <th>Trạng thái</th>
                                            <th class="text-end">Tổng giá trị</th>
                                            <th>Ngày tạo</th>
                                            <th class="text-end">Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($myOrders as $po): ?>
                                            <tr>
                                                <td class="fw-semibold">#<?= (int) $po['po_id'] ?></td>
                                                <td><?= htmlspecialchars($po['supplier_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><span class="stock-pill" style="<?= poStatusBadgeStyle($po['status']) ?>"><?= htmlspecialchars($po['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                                <td class="text-end"><?= number_format((float) $po['total_amount']) ?> đ</td>
                                                <td class="text-muted small"><?= htmlspecialchars(date('d/m/Y', strtotime($po['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-end">
                                                    <a href="po_submit.php?po_id=<?= (int) $po['po_id'] ?>" class="btn btn-outline-secondary btn-sm">Xem chi tiết</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>

                    <!-- ================= CHI TIẾT PO ================= -->
                    <?php $isDraft = $selectedPo['status'] === 'Draft'; ?>

                    <a href="po_submit.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Quay lại danh sách</a>

                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h2 class="page-heading mb-1">
                                PO #<?= (int) $selectedPo['po_id'] ?>
                                <span class="stock-pill ms-2" style="<?= poStatusBadgeStyle($selectedPo['status']) ?>"><?= htmlspecialchars($selectedPo['status'], ENT_QUOTES, 'UTF-8') ?></span>
                            </h2>
                            <p class="page-subheading mb-0">
                                Nhà cung cấp: <strong><?= htmlspecialchars($selectedPo['supplier_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                &middot; Tạo bởi <?= htmlspecialchars($selectedPo['created_by_name'], ENT_QUOTES, 'UTF-8') ?>
                                &middot; <?= htmlspecialchars(date('d/m/Y H:i', strtotime($selectedPo['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>

                        <?php if ($isDraft): ?>
                            <form method="POST" onsubmit="return confirm('Gửi đơn PO #<?= (int) $selectedPo['po_id'] ?> cho Admin duyệt? Sau khi gửi, đơn sẽ bị khóa và không thể sửa (BR-20).');">
                                <input type="hidden" name="action" value="submit_po">
                                <input type="hidden" name="po_id" value="<?= (int) $selectedPo['po_id'] ?>">
                                <button type="submit" class="btn btn-brand btn-sm">Gửi Admin duyệt</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (!$isDraft): ?>
                        <div class="alert alert-info py-2 px-3 mb-3" style="font-size: .82rem;">
                            Đơn đã ở trạng thái <strong><?= htmlspecialchars($selectedPo['status'], ENT_QUOTES, 'UTF-8') ?></strong> - không thể sửa số lượng (BR-20: đơn Draft mới được chỉnh sửa).
                        </div>
                    <?php endif; ?>

                    <div class="panel-card">
                        <div class="panel-card-header">
                            <h3 class="panel-card-title">Chi tiết sản phẩm</h3>
                        </div>

                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Sản phẩm</th>
                                        <th class="text-end">SL gợi ý</th>
                                        <th class="text-end">SL đặt (BR-06)</th>
                                        <th class="text-end">Đơn giá</th>
                                        <th class="text-end">Thành tiền</th>
                                        <?php if ($isDraft): ?><th class="text-end">Thao tác</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $totalAmount = 0; ?>
                                    <?php foreach ($selectedPo['details'] as $line): ?>
                                        <?php $totalAmount += (float) $line['line_cost']; ?>
                                        <tr>
                                            <td>
                                                <span class="fw-semibold"><?= htmlspecialchars($line['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <div class="text-muted small"><?= htmlspecialchars($line['sku_code'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </td>
                                            <td class="text-end text-muted"><?= number_format((int) $line['suggested_qty']) ?></td>
                                            <td class="text-end fw-semibold"><?= number_format((int) $line['approved_qty']) ?></td>
                                            <td class="text-end"><?= number_format((float) $line['unit_cost']) ?> đ</td>
                                            <td class="text-end"><?= number_format((float) $line['line_cost']) ?> đ</td>
                                            <?php if ($isDraft): ?>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm"
                                                            data-bs-toggle="modal" data-bs-target="#editLineModal"
                                                            data-po-detail-id="<?= (int) $line['po_detail_id'] ?>"
                                                            data-product-name="<?= htmlspecialchars($line['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-current-qty="<?= (int) $line['approved_qty'] ?>">
                                                        Sửa SL
                                                    </button>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="text-end fw-semibold">Tổng giá trị đơn</td>
                                        <td class="text-end fw-bold"><?= number_format($totalAmount) ?> đ</td>
                                        <?php if ($isDraft): ?><td></td><?php endif; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                <?php endif; ?>

            </main>
        </div>
    </div>

    <?php if ($selectedPo !== null && $selectedPo['status'] === 'Draft'): ?>
    <!-- Modal: Sửa số lượng 1 dòng (BR-06) -->
    <div class="modal fade" id="editLineModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="action" value="update_line">
                <input type="hidden" name="po_id" value="<?= (int) $selectedPo['po_id'] ?>">
                <input type="hidden" name="po_detail_id" id="editPoDetailId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Sửa số lượng - <span id="editProductName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small">Số lượng đặt mới</label>
                    <input type="number" name="new_qty" id="editNewQty" class="form-control" min="1" required>
                    <div class="form-text">Thay đổi được ghi lại vào Audit Log (BR-06).</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-brand btn-sm">Lưu số lượng</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const editLineModal = document.getElementById('editLineModal');
        if (editLineModal) {
            editLineModal.addEventListener('show.bs.modal', function (event) {
                const btn = event.relatedTarget;
                document.getElementById('editPoDetailId').value = btn.getAttribute('data-po-detail-id');
                document.getElementById('editProductName').textContent = btn.getAttribute('data-product-name');
                document.getElementById('editNewQty').value = btn.getAttribute('data-current-qty');
            });
        }
    </script>
    <?php require __DIR__ . '/../../components/footer.php'; ?>