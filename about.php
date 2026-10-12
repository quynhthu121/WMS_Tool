<?php
/**
 * About Us Page - FlowLink SCM
 * Ngôn ngữ: PHP thuần, Bootstrap 5
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check login status
$is_logged_in = false;
if (isset($_SESSION['user_login_status']) && $_SESSION['user_login_status'] == 1) {
    $is_logged_in = true;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - FlowLink SCM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #1E2A38; /* Primary Navy */
            --accent-color: #178978;  /* Accent Emerald */
            --text-muted: #64748b;
            --bg-light: #F4F6F9;
        }

        /* BODY - Giữ nguyên các thuộc tính đè mới nhất và font-family cũ có !important */
        body {
            font-family: 'Roboto', sans-serif !important;
            overflow-x: hidden;
            background: #f8fafc;
            color: #0f172a;
        }

        /* NAVBAR & SITE HEADER - Đã gộp và giữ lại dải màu Emerald Gradient mới nhất */
        .navbar,
        .site-header,
        header {
            background: linear-gradient(135deg, #0f172a 0%, #064e3b 100%) !important;
            border-bottom: 1px solid rgba(167, 243, 208, 0.18);
            transition: all 0.3s ease; /* Giữ hiệu ứng chuyển động từ code cũ */
        }

        /* HERO SECTION - Giữ hiệu ứng sóng SVG cũ và đè dải màu radial gradient mới nhất xuống cuối */
        .hero-section {
            color: #ffffff !important;
            overflow: hidden;
            padding: 120px 0 80px 0;
            position: relative;
            background:
                linear-gradient(180deg, #022c22 0%, #064e3b 42%, #059669 76%, #a7f3d0 100%) !important;
        }
        .hero-section::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0; height: 60px;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%23ffffff" fill-opacity="1" d="M0,160L120,176C240,192,480,224,720,224C960,224,1200,192,1320,176L1440,160L1440,320L1320,320C1200,320,960,320,720,320C480,320,240,320,120,320L0,320Z"></path></svg>');
            background-size: cover;
        }
        .hero-section .container {
            position: relative;
            z-index: 2;
        }

        /* HERO ABOUT US - Các khối Hero trang con */
        .about-hero,
        .hero,
        .page-hero,
        .about-header {
            background:
                linear-gradient(180deg, #022c22 0%, #064e3b 42%, #059669 76%, #a7f3d0 100%) !important;
            color: #ffffff !important;
        }
        .hero-section h1,
        .about-hero h1,
        .hero h1,
        .page-hero h1,
        .about-header h1 {
            color: #ffffff !important;
            opacity: 1 !important;
            font-weight: 850;
            letter-spacing: -0.04em;
            text-shadow: 0 8px 28px rgba(2, 44, 34, 0.35);
        }
        .hero-section, .about-hero p, .hero p, .page-hero p, .about-header p {
            color: rgba(255, 255, 255, 0.92) !important;
            opacity: 1 !important;
        }
        .hero-section .card, .about-hero .card, .hero .card, .page-hero .card, .about-header .card {
            background: rgba(255, 255, 255, 0.13) !important;
            border: 1px solid rgba(209, 250, 229, 0.38) !important;
            box-shadow: 0 24px 60px rgba(2, 44, 34, 0.22);
            backdrop-filter: blur(14px);
            color: #ffffff !important;
        }

        /* BUTTONS - Loại bỏ hoàn toàn các màu nền cũ (đã bị đè bởi dải màu Gradient) */
        .btn-primary,
        .btn-accent,
        .btn-success {
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 8px;
            transition: all 0.18s ease;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%) !important;
            border-color: #059669 !important;
            color: #ffffff !important;
            box-shadow: 0 12px 28px rgba(5, 150, 105, 0.28);
        }
        .btn-primary:hover,
        .btn-accent:hover,
        .btn-success:hover {
            background: linear-gradient(135deg, #047857 0%, #059669 100%) !important;
            border-color: #047857 !important;
            color: #ffffff !important;
            transform: translateY(-2px);
        }
        .btn-outline-light,
        .btn-outline-primary {
            border-color: rgba(167, 243, 208, 0.7) !important;
            color: #ffffff !important;
        }
        .btn-outline-light:hover,
        .btn-outline-primary:hover {
            background: rgba(16, 185, 129, 0.16) !important;
            border-color: #34d399 !important;
        }

        /* LINKS & BRAND HOVER */
        .navbar a:hover,
        .site-header a:hover,
        header a:hover,
        .text-emerald,
        .brand span,
        .logo span {
            color: #34d399 !important;
        }

        /* ABOUT CONTENT - Đồng bộ cỡ chữ 18px mới và loại bỏ các thuộc tính nền cũ */
        .about-content,
        .about-section,
        main section {
            background: #ffffff;
            padding: 60px 0;
            border-top: 1px solid rgba(30,42,56,0.04);
        }
        .about-text {
            font-family: 'Roboto', sans-serif;
            color: #334155;
            font-size: 1.08rem;
            line-height: 1.9;
            text-align: left;
        }
        .about-text p {
            margin-bottom: 20px;
        }
        .about-content p,
        .about-section p,
        main section p {
            color: #334155;
            font-size: 18px;
            line-height: 1.9;
        }

        /* FOOTER - Đồng bộ màu tối và dải màu Gradient mới nhất */
        footer,
        .footer {
            padding: 48px 0 28px 0;
            font-size: 0.95rem;
            background: linear-gradient(135deg, #0f172a 0%, #022c22 100%) !important;
            color: #e2e8f0 !important;
            border-top: 1px solid rgba(167, 243, 208, 0.16);
        }
        footer h5, footer h6, .footer h5, .footer h6 {
            color: #ffffff !important;
        }
        footer a, .footer a {
            color: #cbd5e1 !important;
            text-decoration: none;
        }
        footer a:hover, .footer a:hover {
            color: #34d399 !important;
        }
        .footer-link { color: rgba(255,255,255,0.9); text-decoration: none; }
        .footer-small { color: rgba(255,255,255,0.7); }
        .social-icon { color: white; opacity: 0.9; margin-left: 8px; }

        /* FEATURE GRID (Giữ nguyên vì không bị trùng lặp) */
        .about-feature-grid {
            margin-top: 36px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }
        .about-feature-card {
            background: #ffffff;
            border: 1px solid rgba(16, 185, 129, 0.18);
            border-radius: 22px;
            padding: 28px;
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
            transition: all 0.22s ease;
        }
        .about-feature-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 24px 54px rgba(15, 23, 42, 0.12);
            border-color: rgba(16, 185, 129, 0.38);
        }
        .feature-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            font-size: 20px;
        }
        .about-feature-card h3 {
            color: #064e3b;
            font-size: 1.15rem;
            font-weight: 800;
            margin-bottom: 12px;
        }
        .about-feature-card p {
            color: #475569;
            font-size: 0.98rem;
            line-height: 1.7;
            margin: 0;
        }

        @media (max-width: 768px) {
            .about-feature-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg fixed-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center fw-bold text-white" href="landing.php" style="gap:10px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="2" y="2" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                    <rect x="14" y="2" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                    <rect x="2" y="14" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                    <rect x="14" y="14" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                </svg>
                <span style="font-size:1rem;">FlowLink <span style="color: #178978;">SCM</span></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center gap-2 mt-3 mt-lg-0">
                    <li class="nav-item"><a class="nav-link px-3 text-white" href="landing.php#features">Tính năng</a></li>
                    <li class="nav-item"><a class="nav-link px-3 text-white" href="about.php">About us</a></li>
                    <li class="nav-item"><a class="nav-link px-3 text-white" href="landing.php#stats">Số liệu thực tế</a></li>
                    <li class="nav-item ms-lg-2">
                        <?php if ($is_logged_in): ?>
                            <a class="btn btn-accent" href="index.php"><i class="fa-solid fa-chart-pie me-2"></i>Vào Dashboard</a>
                        <?php else: ?>
                            <a class="btn btn-outline-light me-2 px-3" href="index.php?action=login">Đăng nhập</a>
                            <a class="btn btn-accent" href="index.php?action=register">Dùng thử ngay</a>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <section class="hero-section d-flex align-items-center">
        <div class="container-fluid px-4 px-md-5">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <h1 style="font-size: 2.8rem; font-weight: 700; margin-bottom: 20px; line-height: 1.15;">
                        Về FlowLink SCM
                    </h1>
                    <p style="font-size: 1.15rem; opacity: 0.95; margin-bottom: 30px; line-height: 1.7;">
                        Hệ thống quản trị chuỗi cung ứng toàn diện dành cho các doanh nghiệp logistics hiện đại.
                    </p>
                </div>
                <div class="col-lg-6">
                    <div style="background: rgba(255,255,255,0.1); border-radius: 16px; padding: 40px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2); text-align: center;">
                        <p style="font-size: 1.2rem; font-weight: 600; margin-bottom: 15px;">Giải pháp kỹ thuật số</p>
                        <p style="opacity: 0.9;">Tối ưu hóa hiệu suất khai thác mặt bằng kho lên đến 200%</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="about-content">
        <div class="container">
            <div class="row">
                <div class="col-lg-10 mx-auto">
                    <div class="about-text">
                        <p>
                            FlowLink SCM là nền tảng quản trị chuỗi cung ứng và vận hành kho được thiết kế cho doanh nghiệp hiện đại.
                            Hệ thống giúp kết nối dữ liệu giữa sản phẩm, đối tác, kho hàng, kiểm kho và báo cáo trong cùng một quy trình thống nhất.
                            Thay vì quản lý thủ công bằng nhiều file rời rạc, FlowLink SCM hỗ trợ doanh nghiệp theo dõi tồn kho, luân chuyển hàng hóa và biến động vận hành theo thời gian thực.
                        </p>
                        <p>
                        Các nghiệp vụ như nhập kho, xuất kho, kiểm kê, cảnh báo tồn kho thấp và tổng hợp báo cáo được tổ chức rõ ràng, dễ thao tác và dễ kiểm soát.
                        Với giao diện trực quan, người dùng có thể nhanh chóng nắm bắt tình trạng kho, lịch sử giao dịch và hiệu suất vận hành chỉ trong vài thao tác.
                        FlowLink SCM không chỉ giúp giảm sai sót trong quá trình quản lý mà còn hỗ trợ ra quyết định dựa trên dữ liệu thực tế.
                        </p>
                        <p>
                        Đối với doanh nghiệp logistics, bán lẻ hoặc sản xuất, hệ thống đóng vai trò như một trung tâm điều phối giúp tối ưu dòng hàng, dòng thông tin và nguồn lực vận hành.
                        Mục tiêu của FlowLink SCM là tạo ra một giải pháp quản trị tinh gọn, linh hoạt và dễ mở rộng theo nhu cầu thực tế của từng doanh nghiệp.
                        Thông qua việc số hóa quy trình kho và chuỗi cung ứng, FlowLink SCM góp phần nâng cao hiệu suất, tiết kiệm thời gian và tăng tính minh bạch trong toàn bộ hoạt động vận hành.
                        </p>
                    </div>
                    <div class="about-feature-grid">
                        <div class="about-feature-card">
                            <div class="feature-icon">
                                <i class="fa-solid fa-boxes-stacked"></i>
                            </div>
                            <h3>Quản lý kho tập trung</h3>
                            <p>Theo dõi sản phẩm, tồn kho và lịch sử nhập xuất trong cùng một hệ thống thống nhất.</p>
                        </div>
                        <div class="about-feature-card">
                            <div class="feature-icon">
                                <i class="fa-solid fa-arrows-rotate"></i>
                            </div>
                            <h3>Vận hành theo thời gian thực</h3>
                            <p>Cập nhật biến động kho nhanh chóng, giúp doanh nghiệp kiểm soát dữ liệu chính xác hơn.</p>
                        </div>
                        <div class="about-feature-card">
                            <div class="feature-icon">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                            <h3>Báo cáo trực quan</h3>
                            <p>Tổng hợp số liệu tồn kho, cảnh báo ngưỡng và hỗ trợ ra quyết định vận hành.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="row mb-4 align-items-start">
                <div class="col-12 d-flex align-items-center mb-3">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" style="margin-right:12px;">
                        <rect x="2" y="2" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                        <rect x="14" y="2" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                        <rect x="2" y="14" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                        <rect x="14" y="14" width="8" height="8" stroke="white" stroke-width="1.2" fill="none" rx="1" />
                    </svg>
                    <div>
                        <div style="font-weight:700; color: white; font-size:1.05rem;">FlowLink <span style="color: #17B978;">SCM</span></div>
                        <div class="footer-small">Kết nối dữ liệu — Điều phối thông minh — Tối ưu vận hành</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <h6 class="text-white fw-bold">GIẢI PHÁP (Mô-đun)</h6>
                    <ul class="list-unstyled mt-3">
                        <li class="mb-2"><span class="footer-small">Quản lý kho hàng (WMS)</span></li>
                        <li class="mb-2"><span class="footer-small">Điều phối dịch chuyển</span></li>
                        <li class="mb-2"><span class="footer-small">Kiểm kê & Đối soát</span></li>
                        <li class="mb-2"><span class="footer-small">Báo cáo vĩ mô</span></li>
                        <li class="mb-2"><span class="footer-small">Trợ lý AI Cố vấn</span></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h6 class="text-white fw-bold">GIỚI THIỆU CÔNG TY</h6>
                    <ul class="list-unstyled mt-3">
                        <li class="mb-2"><a class="footer-link" href="about.php">About us</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h6 class="text-white fw-bold">KHUNG THÔNG TIN ĐỊNH DANH</h6>
                    <p class="footer-small mt-3">FlowLink SCM là hệ thống quản trị chuỗi cung ứng và vận hành linh hoạt, giúp kết nối dữ liệu, tối ưu quy trình và nâng cao hiệu quả điều phối cho mọi quy mô doanh nghiệp.</p>
                </div>
            </div>
            <div class="row">
                <div class="col-12 text-center footer-small">© <?php echo date('Y'); ?> FlowLink SCM. All rights reserved.</div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
