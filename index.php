<?php
/**
 * Điểm vào ứng dụng: xác thực, CSRF, điều hướng module và layout chung.
 * Module chạy trước layout (trong output buffer) để có thể redirect sau POST hoặc xuất CSV.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/classes/Login.php';
require_once __DIR__ . '/classes/Registration.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\View;

$login = new Login();
$registration = new Registration();

if (!$login->isUserLoggedIn()) {
    $action = $_GET['action'] ?? '';
    if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) || $action === 'login') {
        include 'views/not_logged_in.php';
    } elseif ($action === 'register') {
        include 'views/register.php';
    } else {
        include 'landing.php';
    }
    exit;
}

// Vừa đăng nhập thành công bằng form ⇒ chuyển hướng (PRG) để không xử lý POST đăng nhập như thao tác nghiệp vụ.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['login']) || isset($_POST['register_google_profile']))) {
    View::redirect(View::url('dashboard'));
}

$pdo = Db::shared();
$actor = Auth::actor($pdo);
Csrf::verifyRequest();

$menu = [
    'Tổng quan' => [
        'dashboard' => ['📊', 'Tổng quan'],
    ],
    'Nghiệp vụ kho' => [
        'inventory' => ['📦', 'Tồn kho theo vị trí'],
        'inbound' => ['📥', 'Nhập kho (Inbound)'],
        'outbound' => ['📤', 'Xuất kho (Outbound)'],
        'audit' => ['📋', 'Kiểm kê (Audit)'],
    ],
    'Truy vết & kiểm chứng' => [
        'ledger' => ['🧾', 'Stock Movement'],
        'reconcile' => ['⚖️', 'Đối soát'],
        'evidence' => ['✅', 'Bằng chứng'],
    ],
    'Trình diễn' => [
        'demo' => ['🎬', 'Bảng điều khiển Demo'],
        'about-data' => ['ℹ️', 'Về dữ liệu'],
    ],
    'Danh mục' => [
        'products' => ['🏷️', 'Sản phẩm'],
        'partners' => ['👥', 'Đối tác'],
        'reports' => ['📈', 'Báo cáo'],
        'warehouse' => ['🗄️', 'Phiếu kế thừa'],
        'settings' => ['⚙️', 'Cài đặt'],
    ],
];
if (getenv('GEMINI_API_KEY')) {
    $menu['Danh mục']['chat-ai'] = ['🤖', 'Trợ lý AI'];
}

$pages = [];
foreach ($menu as $items) {
    $pages += $items;
}
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!isset($pages[$page])) {
    $page = 'dashboard';
}
$pageTitle = $pages[$page][1];

// Chạy module trước, giữ HTML trong buffer.
ob_start();
try {
    include __DIR__ . "/modules/{$page}.php";
} catch (\Throwable $e) {
    error_log((string) $e);
    echo '<div class="flash flash-error">Lỗi hệ thống khi hiển thị trang. Xem log máy chủ để biết chi tiết.</div>';
}
$moduleHtml = ob_get_clean();
$flashes = View::takeFlash();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::e($pageTitle) ?> – WMS Tool (demo)</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css?v=4">
</head>
<body>
<div class="demo-banner">
    <strong>DEMO – dữ liệu giả lập.</strong> Seedcom Logistics chỉ là bối cảnh; công cụ này không phản ánh hệ thống thật và không khẳng định Seedcom sử dụng.
</div>
<div class="shell">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="2" y="2" width="8" height="8" stroke="white" stroke-width="1.2" rx="1"/>
                <rect x="14" y="2" width="8" height="8" stroke="#34d399" stroke-width="1.2" rx="1"/>
                <rect x="2" y="14" width="8" height="8" stroke="#34d399" stroke-width="1.2" rx="1"/>
                <rect x="14" y="14" width="8" height="8" stroke="white" stroke-width="1.2" rx="1"/>
            </svg>
            <div>
                <div class="title">WMS <span>Tool</span></div>
                <div class="sub">Demo seminar – UIT</div>
            </div>
        </div>
        <?php foreach ($menu as $group => $items): ?>
            <nav class="nav-group">
                <div class="nav-group-title"><?= View::e($group) ?></div>
                <?php foreach ($items as $key => [$icon, $label]): ?>
                    <a href="<?= View::e(View::url($key)) ?>" class="<?= $key === $page ? 'active' : '' ?>"><span><?= $icon ?></span><?= View::e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endforeach; ?>
        <div class="sidebar-foot">Kế thừa source FlowLink-SCM (nchphuong06). Dữ liệu mô phỏng.</div>
    </aside>
    <div class="main">
        <header class="topbar">
            <h1><?= $pages[$page][0] ?> <?= View::e($pageTitle) ?></h1>
            <div class="userbox">
                <div class="who">
                    <strong><?= View::e($actor->name) ?></strong>
                    <?= View::badge($actor->roleLabel(), $actor->isSupervisor() ? 'warn' : 'info') ?>
                </div>
                <div class="avatar"><?= View::e(mb_strtoupper(mb_substr($actor->name, 0, 1))) ?></div>
                <a class="btn btn-secondary btn-small" href="index.php?logout">Đăng xuất</a>
            </div>
        </header>
        <main class="content">
            <?php foreach ($flashes as $f): ?>
                <div class="flash flash-<?= View::e($f['type']) ?>"><?= View::e($f['message']) ?></div>
            <?php endforeach; ?>
            <?= $moduleHtml ?>
        </main>
    </div>
</div>
</body>
</html>
