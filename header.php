<?php
// Oturum başlatılmamışsa başlat
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Config dosyasını bul ve yükle
$config_paths = [
    __DIR__ . '/../config/config.php',
    __DIR__ . '/../../config/config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/config/config.php'
];

foreach ($config_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

// Oturum Kontrolü
if (!isset($_SESSION['customer_id'])) {
    header("Location: /customer/login.php");
    exit;
}

$customer_id = $_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'] ?? 'Müşteri';

// İŞLETME KONTROLÜ
$header_tenant_name = '';
$is_tenant_context = false;
$current_host = str_replace('www.', '', $_SERVER['HTTP_HOST']);
$main_domain = 'randu.tr';

if ($current_host === $main_domain) {
    $is_tenant_context = false;
    $header_tenant_name = 'Randu.tr';
} else {
    if (defined('TENANT_ID') && TENANT_ID) {
        $is_tenant_context = true;
        try {
            $stmt = $db->prepare("SELECT firma_adi FROM tenants WHERE id = ?");
            $stmt->execute([TENANT_ID]);
            $header_tenant_name = $stmt->fetchColumn();
        } catch (Exception $e) {
            $header_tenant_name = 'İşletme';
        }
    }
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#3b82f6">
    <title>Müşteri Paneli - <?php echo htmlspecialchars($header_tenant_name); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --accent-color: #3b82f6;
            --danger-color: #ef4444;
            --success-color: #10b981;
            --border-color: #e2e8f0;
        }

        * {
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: var(--primary-bg);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding-bottom: 70px; /* Bottom nav için alan */
        }
        
        main { 
            flex: 1;
            padding-bottom: 2rem;
        }

        /* Navbar - Desktop */
        .navbar-desktop {
            background: white;
            border-bottom: 1px solid var(--border-color);
            padding: 0.8rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        }

        .navbar-brand {
            font-weight: 800;
            color: #0f172a;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .navbar-brand i {
            font-size: 1.8rem;
        }

        .nav-link {
            font-weight: 500;
            color: var(--text-muted);
            padding: 0.5rem 1rem !important;
            border-radius: 10px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-link:hover {
            color: var(--accent-color);
            background: #eff6ff;
        }

        .nav-link.active {
            color: var(--accent-color);
            background: #eff6ff;
            font-weight: 600;
        }

        /* Bottom Navigation - Mobile */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid var(--border-color);
            box-shadow: 0 -2px 20px rgba(0,0,0,0.08);
            z-index: 1000;
            display: none;
        }

        .bottom-nav-content {
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 8px 0;
        }

        .bottom-nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 8px 4px;
            text-decoration: none;
            color: var(--text-muted);
            transition: all 0.2s;
            position: relative;
            min-height: 60px;
        }

        .bottom-nav-item i {
            font-size: 1.4rem;
            margin-bottom: 4px;
            transition: all 0.2s;
        }

        .bottom-nav-item span {
            font-size: 0.7rem;
            font-weight: 500;
        }

        .bottom-nav-item.active {
            color: var(--accent-color);
        }

        .bottom-nav-item.active i {
            transform: scale(1.1);
        }

        .bottom-nav-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 3px;
            background: var(--accent-color);
            border-radius: 0 0 3px 3px;
            transition: width 0.3s;
        }

        .bottom-nav-item.active::before {
            width: 60%;
        }

        /* Buttons */
        .btn-primary-custom {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: none;
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            transition: all 0.3s;
        }

        .btn-primary-custom:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
        }

        .btn-primary-custom:active {
            transform: translateY(0);
        }

        /* Cards */
        .card {
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            transition: all 0.3s;
        }

        .card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }

        /* Container Padding */
        .container {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        /* Mobile Optimizations */
        @media (max-width: 991.98px) {
            .navbar-desktop {
                display: none !important;
            }

            .bottom-nav {
                display: block;
            }

            .container {
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }

            main {
                padding-top: 1rem;
            }

            .btn-primary-custom {
                width: 100%;
                padding: 0.875rem 1rem;
            }

            /* Mobile Header Minimal */
            .mobile-header {
                background: white;
                border-bottom: 1px solid var(--border-color);
                padding: 1rem;
                position: sticky;
                top: 0;
                z-index: 999;
                box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            }

            .mobile-header-brand {
                font-weight: 800;
                color: #0f172a;
                font-size: 1.3rem;
                margin: 0;
            }
        }

        @media (min-width: 992px) {
            .mobile-header {
                display: none;
            }

            body {
                padding-bottom: 0;
            }
        }

        /* Touch Feedback */
        @media (hover: none) {
            .nav-link:active,
            .bottom-nav-item:active,
            .btn:active {
                opacity: 0.7;
            }
        }

        /* Loading State */
        .btn-loading {
            position: relative;
            color: transparent !important;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin-left: -8px;
            margin-top: -8px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spinner 0.6s linear infinite;
        }

        @keyframes spinner {
            to { transform: rotate(360deg); }
        }

        /* Smooth Scrolling */
        html {
            scroll-behavior: smooth;
        }

        /* Form Controls Mobile */
        @media (max-width: 767.98px) {
            .form-control,
            .form-select {
                font-size: 16px; /* Prevents zoom on iOS */
                padding: 0.875rem;
            }
        }
    </style>
</head>
<body>

    <!-- Desktop Navigation -->
    <nav class="navbar navbar-desktop navbar-expand-lg sticky-top">
        <div class="container">
            <?php if($is_tenant_context): ?>
                <a class="navbar-brand text-primary" href="index.php">
                   <i class="bi bi-shop-window"></i>
                   <span><?php echo htmlspecialchars($header_tenant_name); ?></span>
                </a>
            <?php else: ?>
                <a class="navbar-brand" href="index.php">
                    Randu<span class="text-primary">.tr</span>
                </a>
            <?php endif; ?>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">
                            <i class="bi bi-grid-fill"></i> Panel
                        </a>
                    </li>

                    <?php if(!$is_tenant_context): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page == 'isletmeler.php' ? 'active' : ''; ?>" href="isletmeler.php">
                                <i class="bi bi-search"></i> İşletmeler
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page == 'favorilerim.php' ? 'active' : ''; ?>" href="favorilerim.php">
                                <i class="bi bi-heart-fill text-danger"></i> Favorilerim
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>" href="profile.php">
                            <i class="bi bi-person-circle"></i> Profilim
                        </a>
                    </li>
                    
                    <li class="nav-item ms-lg-3">
                        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                            <i class="bi bi-box-arrow-right"></i> Çıkış
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Mobile Header -->
    <div class="mobile-header d-lg-none">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="mobile-header-brand mb-0">
                <?php if($is_tenant_context): ?>
                    <i class="bi bi-shop-window text-primary"></i>
                    <?php echo htmlspecialchars($header_tenant_name); ?>
                <?php else: ?>
                    Randu<span class="text-primary">.tr</span>
                <?php endif; ?>
            </h1>
            <a href="logout.php" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>

    <!-- Bottom Navigation - Mobile -->
    <nav class="bottom-nav d-lg-none">
        <div class="bottom-nav-content">
            <a href="index.php" class="bottom-nav-item <?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
                <i class="bi bi-grid-fill"></i>
                <span>Panel</span>
            </a>

            <?php if(!$is_tenant_context): ?>
                <a href="isletmeler.php" class="bottom-nav-item <?php echo $current_page == 'isletmeler.php' ? 'active' : ''; ?>">
                    <i class="bi bi-search"></i>
                    <span>Keşfet</span>
                </a>

                <a href="favorilerim.php" class="bottom-nav-item <?php echo $current_page == 'favorilerim.php' ? 'active' : ''; ?>">
                    <i class="bi bi-heart-fill"></i>
                    <span>Favoriler</span>
                </a>
            <?php else: ?>
                <a href="reservation.php" class="bottom-nav-item <?php echo $current_page == 'reservation.php' ? 'active' : ''; ?>">
                    <i class="bi bi-calendar-plus"></i>
                    <span>Randevu</span>
                </a>
            <?php endif; ?>

            <a href="profile.php" class="bottom-nav-item <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
                <i class="bi bi-person-circle"></i>
                <span>Profil</span>
            </a>
        </div>
    </nav>
    
    <main class="py-3 py-md-4">