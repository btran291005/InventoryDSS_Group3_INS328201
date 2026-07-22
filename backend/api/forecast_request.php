<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Middleware.php';
require_once __DIR__ . '/../services/ManagerService.php';

Middleware::guardApi([ROLE_MANAGER]);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST is allowed.']);
    exit;
}

try {
    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dữ liệu yêu cầu không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = is_array($input) ? (string) ($input['csrf_token'] ?? '') : '';
$sessionToken = (string) ($_SESSION['forecast_csrf_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($productId === false) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Sản phẩm không hợp lệ.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = (new ManagerService())->getForecastSuggestion((int) $productId);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
