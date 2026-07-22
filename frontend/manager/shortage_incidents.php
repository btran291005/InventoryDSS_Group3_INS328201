<?php
/**
 * File: frontend/manager/shortage_incidents.php
 * Purpose: Ghi nhận sự cố thiếu hàng (VD: phát hiện qua phản hồi khách hàng
 * dù dashboard chưa cảnh báo kịp) và xử lý đóng sự cố. Dùng
 * ManagerService::logShortageIncident()/resolveShortageIncident()/
 * listShortageIncidents() (đã có sẵn).
 * Related: FR-MGR-07
 *
 * Đây chính là mảnh ghép "đóng vòng lặp" đã bàn từ Tutorial 2/3: có cảnh
 * báo sớm (FR-MGR-12 Top 10 Stock-out Risk) NHƯNG vẫn cần cơ chế xử lý khi
 * lỡ vẫn xảy ra thiếu hàng ngoài dự kiến - đúng root cause "shortages
 * detected only after customer complaints" từ Tutorial 1.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/app_config.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/core/Logger.php';
require_once __DIR__ . '/../../backend/core/Auth.php';
require_once __DIR__ . '/../../backend/core/Middleware.php';
require_once __DIR__ . '/../../backend/services/ManagerService.php';
require_once __DIR__ . '/../../backend/models/Product.php';

Middleware::guard([ROLE_MANAGER]);

$managerService = new ManagerService();
$productModel = new Product();

$flashMessage = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $result = $managerService->logShortageIncident(
            (int) ($_POST['product_id'] ?? 0),
            Auth::id(),
            $_POST['resolution_action'] ?: null
        );
    } elseif ($action === 'resolve') {
        $result = $managerService->resolveShortageIncident(
            (int) ($_POST['incident_id'] ?? 0),
            $_POST['resolution_action'] ?? ''
        );
    } else {
        $result = ['success' => false, 'message' => 'Invalid action.'];
    }

    $flashMessage = $result['message'];
    $flashType = $result['success'] ? 'success' : 'danger';
}

$filterStatus = $_GET['status'] ?? null;
$incidents = $managerService->listShortageIncidents($filterStatus ?: null);
$products = $productModel->getAll();

$pageTitle = 'Shortage Incidents';
require_once __DIR__ . '/../components/header.php';
require_once __DIR__ . '/../components/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">Shortage Incidents</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#logIncidentModal">
        <i class="bi bi-plus-lg"></i> Log Incident
    </button>
</div>

<?php if ($flashMessage): ?>
    <div class="alert alert-<?= $flashType ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flashMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="get" class="row g-2 mb-3">
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">-- All statuses --</option>
            <option value="Open" <?= $filterStatus === 'Open' ? 'selected' : '' ?>>Open</option>
            <option value="Resolved" <?= $filterStatus === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
        </select>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th>Logged By</th>
                    <th>Status</th>
                    <th>Resolution</th>
                    <th>Created At</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($incidents)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No shortage incidents found.</td></tr>
                <?php endif; ?>
                <?php foreach ($incidents as $incident): ?>
                    <tr>
                        <td><?= htmlspecialchars($incident['sku_code']) ?></td>
                        <td><?= htmlspecialchars($incident['product_name']) ?></td>
                        <td><?= htmlspecialchars($incident['handled_by_name']) ?></td>
                        <td>
                            <?php if ($incident['status'] === 'Open'): ?>
                                <span class="badge bg-warning text-dark">Open</span>
                            <?php else: ?>
                                <span class="badge bg-success">Resolved</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($incident['resolution_action'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($incident['created_at']) ?></td>
                        <td class="text-end">
                            <?php if ($incident['status'] === 'Open'): ?>
                                <button type="button" class="btn btn-sm btn-outline-success"
                                        data-bs-toggle="modal" data-bs-target="#resolveModal-<?= (int) $incident['incident_id'] ?>">
                                    Resolve
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($incident['status'] === 'Open'): ?>
                        <div class="modal fade" id="resolveModal-<?= (int) $incident['incident_id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <form method="post" class="modal-content">
                                    <input type="hidden" name="action" value="resolve">
                                    <input type="hidden" name="incident_id" value="<?= (int) $incident['incident_id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Resolve Incident: <?= htmlspecialchars($incident['product_name']) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <label class="form-label">Resolution Action</label>
                                        <textarea name="resolution_action" class="form-control" rows="3" required></textarea>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-success">Mark Resolved</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: log new incident -->
<div class="modal fade" id="logIncidentModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title">Log Shortage Incident</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Product</label>
                    <select name="product_id" class="form-select" required>
                        <option value="">-- Select product --</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= (int) $product['product_id'] ?>">
                                <?= htmlspecialchars($product['sku_code'] . ' - ' . $product['product_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Initial Note (optional)</label>
                    <textarea name="resolution_action" class="form-control" rows="2"
                              placeholder="e.g. Customer complaint received, checking with staff..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Log Incident</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../components/footer.php'; ?>