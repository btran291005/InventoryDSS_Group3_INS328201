<?php
/**
 * File: frontend/access-denied.php
 * Purpose: Trang 403 hiển thị khi Middleware::guard() chặn user đăng nhập
 * nhưng sai role truy cập 1 trang không thuộc quyền của họ.
 * Related: NFR-03 ("unauthorized routes return access-denied"), BR-19.
 *
 * File này là đích redirect mặc định của Middleware::guard() / denyAccess()
 * (xem backend/core/Middleware.php - $accessDeniedRedirect = '/access-denied.php').
 *
 * Không cần Middleware::guard() ở đây (trang này PHẢI xem được bởi mọi role,
 * kể cả người chưa đăng nhập bị văng về đây) - chỉ cần Auth để hiển thị
 * link "Return Home" đúng dashboard nếu user đang có phiên đăng nhập.
 */

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/app_config.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/core/Logger.php';
require_once __DIR__ . '/../backend/core/Auth.php';

Auth::start();

// "Return Home" trỏ đúng dashboard theo role nếu đã đăng nhập, ngược lại về login.
$homeHref = BASE_URL . '/login.php';

if (Auth::check()) {
    switch (Auth::roleId()) {
        case ROLE_ADMIN:
            $homeHref = BASE_URL . '/admin/dashboard.php';
            break;
        case ROLE_MANAGER:
            $homeHref = BASE_URL . '/manager/dashboard.php';
            break;
        case ROLE_STAFF:
            $homeHref = BASE_URL . '/staff/dashboard.php';
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Không có quyền truy cập - InventoryDSS</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --color-primary: #1e3a5f;
            --color-primary-dark: #16304d;
            --color-text-primary: #111827;
            --color-text-secondary: #374151;
            --color-text-muted: #6b7280;
            --color-border: #e5e7eb;
            --color-danger: #b91c1c;
            --color-danger-bg: #fef2f2;
            --color-danger-border: #fecaca;
            --color-warning: #92400e;
            --color-success: #16a34a;
        }

        html, body { height: 100%; }

        body {
            font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            background: radial-gradient(circle at 20% 10%, #eef2ff 0%, #f8fafc 45%, #eef2f7 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: radial-gradient(circle, #c7d2fe 1.5px, transparent 1.5px);
            background-size: 100px 100px;
            opacity: .3;
            pointer-events: none;
        }

        .denied-shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 520px;
            text-align: center;
        }

        .icon-orbit {
            position: relative;
            width: 260px;
            height: 260px;
            margin: 0 auto 28px;
            border-radius: 50%;
            background: radial-gradient(circle at 35% 30%, #ffffff, #eef2ff 70%);
            box-shadow: 0 30px 60px -25px rgba(30,58,95,.35);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon-lock {
            width: 92px;
            height: 92px;
            border-radius: 50%;
            background: var(--color-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 14px 30px -10px rgba(30,58,95,.55);
        }

        .icon-lock svg { width: 40px; height: 40px; color: #fff; }

        .orbit-chip {
            position: absolute;
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 10px 24px -10px rgba(15,23,42,.25);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .orbit-chip.top-right { top: 6px; right: 14px; }
        .orbit-chip.top-right svg { width: 20px; height: 20px; color: var(--color-primary); }

        .orbit-chip.mid-left { bottom: 46px; left: -6px; }
        .orbit-chip.mid-left svg { width: 20px; height: 20px; color: var(--color-warning); }

        .error-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            background: var(--color-danger-bg);
            color: var(--color-danger);
            border: 1px solid var(--color-danger-border);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .03em;
            margin-bottom: 18px;
        }
        .error-badge svg { width: 13px; height: 13px; }

        h1 {
            font-size: 32px;
            font-weight: 800;
            color: var(--color-primary);
            margin-bottom: 14px;
        }

        .denied-shell p.desc {
            font-size: 14.5px;
            line-height: 1.65;
            color: var(--color-text-muted);
            max-width: 420px;
            margin: 0 auto 30px;
        }

        .action-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 26px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: background .15s, border-color .15s, transform .1s;
        }
        .btn:active { transform: translateY(1px); }
        .btn svg { width: 16px; height: 16px; }

        .btn-primary {
            background: var(--color-primary);
            color: #fff;
            border: 1px solid var(--color-primary);
        }
        .btn-primary:hover { background: var(--color-primary-dark); }

        .btn-outline {
            background: #fff;
            color: var(--color-primary);
            border: 1px solid var(--color-primary);
        }
        .btn-outline:hover { background: #eef2ff; }

        .status-line {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--color-text-muted);
        }

        .dot-live {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--color-success);
            box-shadow: 0 0 0 3px rgba(22,163,74,.18);
            flex-shrink: 0;
        }
    </style>
</head>
<body>
    <div class="denied-shell">
        <div class="icon-orbit">
            <div class="orbit-chip top-right">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8">
                    <path d="M12 2l8 4v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-4z"/>
                </svg>
            </div>
            <div class="orbit-chip mid-left">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="1.8">
                    <path d="M12 2l8 4v6c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-4z"/>
                    <path d="M9.5 12.5l1.8 1.8L15 10.5"/>
                </svg>
            </div>
            <div class="icon-lock">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2">
                    <rect x="4" y="10" width="16" height="10" rx="2"/>
                    <path d="M8 10V7a4 4 0 018 0v3"/>
                </svg>
            </div>
        </div>

        <div class="error-badge">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2">
                <path d="M12 9v4M12 17h.01M10.3 3.9L2.5 17a2 2 0 001.7 3h15.6a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>
            </svg>
            ERROR 403
        </div>

        <h1>Permission Denied</h1>
        <p class="desc">
            Bạn không có đủ quyền để truy cập chức năng này.
            Vui lòng liên hệ Admin hệ thống nếu cần được cấp thêm quyền.
        </p>

        <div class="action-row">
            <a href="<?= htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
                Return Home
            </a>
            <a href="mailto:" class="btn btn-outline">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="M3 7l9 6 9-6"/>
                </svg>
                Contact Administrator
            </a>
        </div>

        <div class="status-line">
            <span class="dot-live"></span>
            System Security Core Active &middot; SEC-0X403
        </div>
    </div>
</body>
</html>