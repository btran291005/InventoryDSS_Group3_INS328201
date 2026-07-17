// public/components/sidebar.php
/**
 * File: sidebar.php
 * Purpose: Renders navigation menu based on $_SESSION['role'].
 * Warning: This controls menu VISIBILITY only. It does NOT enforce access —
 *          Middleware.php is the real access control. Never rely on a hidden
 *          menu item as a security measure.
 */