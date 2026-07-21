<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/app_config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/core/Logger.php';
require_once __DIR__ . '/../backend/core/Auth.php';

Auth::start();

// Đã đăng nhập rồi thì không cần xem lại form login -> đẩy thẳng về dashboard
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

// 3 tab role hiển thị trên UI - dùng thẳng ROLE_NAMES đã định nghĩa ở app_config.php (single source of truth cho map role_id -> tên hiển thị, tránh duplicate dữ liệu giữa 2 nơi).
$roleTabs = ROLE_NAMES;

$errorMessage = '';
$selectedRole = ROLE_ADMIN; // mặc định tab đầu tiên khi mới vào trang

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username     = $_POST['username'] ?? '';
    $password     = $_POST['password'] ?? '';
    $postedRoleId = (int) ($_POST['role_id'] ?? 0);

    // Chặn giá trị role_id lạ (không thuộc 3 role đã biết) bị POST thủ công
    if (!array_key_exists($postedRoleId, $roleTabs)) {
        $errorMessage = 'Please select a valid role before signing in.';
    } else {
        $selectedRole = $postedRoleId;

        // Role đã chọn được truyền vào Auth::login() -> Auth.php tự so sánh với role_id thật trong DB SAU khi xác thực đúng mật khẩu, và từ chối nếu lệch (không tạo session trong trường hợp đó).
        $result = Auth::login($username, $password, $postedRoleId);

        if ($result['success']) {
            // FR-SYS-01: redirect đúng dashboard theo role sau khi login
            header('Location: index.php');
            exit;
        }

        $errorMessage = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - InventoryDSS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/login.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid px-4 px-xl-5 py-4 py-lg-5 position-relative" style="z-index: 1;">
        <div class="row g-4 g-lg-5 mx-auto align-items-stretch" style="max-width: 1400px;">

            <!-- Cột trái: logo + hero panel -->
            <div class="col-12 col-lg-7 d-flex flex-column">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div class="brand-mark rounded-3 d-flex align-items-center justify-content-center flex-shrink-0">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="3" width="8" height="8" rx="1.5" fill="#ffffff"/>
                            <rect x="13" y="3" width="8" height="8" rx="1.5" fill="#ffffff" opacity=".55"/>
                            <rect x="3" y="13" width="8" height="8" rx="1.5" fill="#ffffff" opacity=".55"/>
                            <rect x="13" y="13" width="8" height="8" rx="1.5" fill="#ffffff"/>
                        </svg>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-dark lh-sm" style="color: var(--brand-primary) !important;">InventoryDSS</div>
                        <div class="text-uppercase text-muted fw-bold small" style="font-size: .78rem; letter-spacing: .08em;">DSS AI System</div>
                    </div>
                </div>

                <div class="hero-panel flex-grow-1 rounded-4">
                    <div class="hero-panel-glow"></div>
                    <div class="hero-panel-grid"></div>
                    <svg class="hero-panel-nodes" viewBox="0 0 400 400" preserveAspectRatio="none">
                        <g stroke="rgba(255,255,255,.35)" stroke-width="1">
                            <line x1="60" y1="70" x2="180" y2="110" />
                            <line x1="180" y1="110" x2="300" y2="60" />
                            <line x1="180" y1="110" x2="230" y2="200" />
                            <line x1="230" y1="200" x2="340" y2="180" />
                            <line x1="230" y1="200" x2="120" y2="230" />
                            <line x1="300" y1="60" x2="340" y2="180" />
                        </g>
                        <g opacity=".9">
                            <circle cx="60" cy="70" r="4" />
                            <circle cx="180" cy="110" r="5" />
                            <circle cx="300" cy="60" r="3.5" />
                            <circle cx="230" cy="200" r="4.5" />
                            <circle cx="340" cy="180" r="3.5" />
                            <circle cx="120" cy="230" r="3.5" />
                        </g>
                    </svg>
                    <div class="hero-card position-absolute rounded-3 p-4">
                        <h2 class="text-white fw-bold fs-4 mb-2">Predictive Logistics Engine</h2>
                        <p class="text-white-50 small mb-1">Harnessing real-time AI to optimize GS25's global supply chain.</p>
                        <p class="text-white-50 small mb-3">Intelligent inventory management and autonomous operations start here.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="hero-badge d-inline-flex align-items-center gap-2 rounded-pill px-3 py-2 small fw-bold">
                                <span class="dot-icon rounded-circle"></span>Neural Network Analysis
                            </span>
                            <span class="hero-badge d-inline-flex align-items-center gap-2 rounded-pill px-3 py-2 small fw-bold">
                                <span class="dot-icon rounded-circle"></span>Real-time Optimization
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cột phải: form -->
            <div class="col-12 col-lg-5 d-flex flex-column">
                <h1 class="fw-bold display-6 mb-2">Enterprise Sign In</h1>
                <p class="text-muted mb-4">Select a role and sign in to the InventoryDSS system.</p>

                <div class="role-tabs d-grid gap-2 p-1 rounded-3 mb-4" style="grid-template-columns: repeat(3, 1fr);" role="tablist" aria-label="Select login role">
                    <?php foreach ($roleTabs as $roleIdOption => $roleLabel): ?>
                        <button type="button"
                                class="role-tab btn btn-sm fw-bold border-0 rounded-2 py-2<?= $selectedRole === $roleIdOption ? ' active' : '' ?>"
                                data-role-id="<?= (int) $roleIdOption ?>"
                                role="tab"
                                aria-selected="<?= $selectedRole === $roleIdOption ? 'true' : 'false' ?>">
                            <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="login-card bg-white rounded-4 p-4 border">
                    <?php if ($errorMessage !== ''): ?>
                        <div class="alert alert-danger py-2 small mb-3"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="POST" action="login.php" autocomplete="off">
                        <input type="hidden" name="role_id" id="role_id" value="<?= (int) $selectedRole ?>">

                        <div class="mb-3">
                            <label for="username" class="form-label text-uppercase small fw-bold text-secondary" style="letter-spacing: .04em; font-size: .72rem;">Username</label>
                            <div class="field-wrap">
                                <svg class="field-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8">
                                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                                    <path d="M3 7l9 6 9-6"/>
                                </svg>
                                <input type="text" id="username" name="username" class="form-control rounded-3" required autofocus
                                       placeholder="name@gs25.com"
                                       value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label text-uppercase small fw-bold text-secondary" style="letter-spacing: .04em; font-size: .72rem;">Password</label>
                            <div class="field-wrap">
                                <svg class="field-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8">
                                    <rect x="4" y="10" width="16" height="10" rx="2"/>
                                    <path d="M8 10V7a4 4 0 018 0v3"/>
                                </svg>
                                <input type="password" id="password" name="password" class="form-control rounded-3" required
                                       placeholder="••••••••">
                                <button type="button" class="toggle-pw" id="togglePasswordBtn" aria-label="Show or hide password">
                                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8">
                                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="form-check d-flex align-items-center gap-2 mb-4">
                            <input type="checkbox" id="remember" name="remember" class="form-check-input mt-0" style="cursor: pointer;">
                            <label for="remember" class="form-check-label small text-secondary" style="cursor: pointer;">Keep me signed in</label>
                        </div>

                        <button type="submit" class="btn btn-brand w-100 fw-bold py-2 rounded-3 d-flex align-items-center justify-content-center gap-2">
                            Sign In
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14M13 6l6 6-6 6"/>
                            </svg>
                        </button>
                    </form>

                    <div class="mt-4 pt-3 border-top border-dashed">
                        <div class="text-center text-uppercase text-muted fw-bold small mb-3" style="letter-spacing: .06em; font-size: .68rem;">Demo Account</div>
                        <table class="table table-sm demo-table mb-0 align-middle">
                            <thead>
                                <tr class="text-muted small">
                                    <th class="fw-semibold">Role</th>
                                    <th class="fw-semibold">Username</th>
                                    <th class="fw-semibold">Password</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <tr>
                                    <td class="fw-bold role-admin">Admin</td>
                                    <td><code>admin</code></td>
                                    <td><code>Admin@123</code></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold role-manager">Manager</td>
                                    <td><code>manager1</code></td>
                                    <td><code>Manager@123</code></td>
                                </tr>
                                <tr>
                                    <td class="fw-bold role-staff">Store Staff</td>
                                    <td><code>staff1</code></td>
                                    <td><code>Staff@123</code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="text-center small text-muted mt-3 pt-3 border-top border-dashed">
                        Need access? Contact the system administrator.
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle hiện/ẩn mật khẩu - thuần JS, không đụng tới logic PHP submit
        (function () {
            var btn = document.getElementById('togglePasswordBtn');
            var input = document.getElementById('password');
            if (!btn || !input) return;
            btn.addEventListener('click', function () {
                input.type = input.type === 'password' ? 'text' : 'password';
            });
        })();

        // Chọn tab role -> đồng bộ vào hidden input #role_id, gửi kèm khi submit form. Việc chọn sai role thật của account vẫn bị chặn ở server (Auth::login()) dù JS này có bị tắt hay bị can thiệp - đây chỉ là tiện ích UI.
        (function () {
            var tabs = document.querySelectorAll('.role-tab');
            var roleInput = document.getElementById('role_id');
            if (!tabs.length || !roleInput) return;

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    tabs.forEach(function (t) {
                        t.classList.remove('active');
                        t.setAttribute('aria-selected', 'false');
                    });
                    tab.classList.add('active');
                    tab.setAttribute('aria-selected', 'true');
                    roleInput.value = tab.getAttribute('data-role-id');
                });
            });
        })();
    </script>
</body>
</html>