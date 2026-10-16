<?php
// Config dosyasını iki üst dizinden çağırıyoruz (admin/includes/ -> admin/ -> root/)
require_once __DIR__ . '/../../config/config.php';

// AUTO CRON FALLBACK: Sunucuda gerçek cron job kurulu değilse de
// hatırlatma SMS'leri çalışsın diye dakikada 1 kere tetikleyici çağrılır.
// Kilit dosyası ile race condition korumalı.
// Gerçek cron kurulduktan sonra fallback tetik kapatıldı.
// Hatırlatmalar sadece cron_reminders.php üzerinden çalışmalıdır.

// CSRF Token'ı initialize et (eğer yoksa oluştur)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}

global $site_settings, $db;

// 1. GÜVENLİK: TENANT ID KONTROLÜ
if (!defined('TENANT_ID') || TENANT_ID === null) {
    if (session_status() == PHP_SESSION_NONE) session_start();
    
    if (isset($_SESSION['tenant_id']) && !empty($_SESSION['tenant_id'])) {
        define('TENANT_ID', $_SESSION['tenant_id']);
    } else {
        header("Location: ../login.php");
        exit;
    }
}

// 2. AYARLARI ÇEK
$current_page = basename($_SERVER['PHP_SELF']);
$is_settings_page = ($current_page == 'settings.php');

if ($is_settings_page || $_SERVER['REQUEST_METHOD'] == 'POST') {
    $site_settings = null;
    // POST veya settings sayfasında session cache'i temizle — ayarlar değişmiş olabilir
    // Timestamp key: _sc_st_{id}_t  (eski yanlış key: _sc_st_t_{id})
    unset($_SESSION['_sc_st_' . TENANT_ID], $_SESSION['_sc_st_' . TENANT_ID . '_t']);
}

if (!isset($site_settings) || $site_settings === null || empty($site_settings)) {
    $_sc_k = '_sc_st_' . TENANT_ID;
    if (isset($_SESSION[$_sc_k], $_SESSION[$_sc_k . '_t'])
        && (time() - $_SESSION[$_sc_k . '_t']) < 300
        && !empty($_SESSION[$_sc_k])) {
        $site_settings = $_SESSION[$_sc_k];
    } else {
        try {
            if (!isset($db)) {
                 $database = new Database();
                 $db = $database->getConnection();
                 $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                 $db->exec("SET NAMES 'utf8mb4'");
            }
            $stmt_settings = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE tenant_id = ?");
            $stmt_settings->execute([TENANT_ID]);
            $site_settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);
            $_SESSION[$_sc_k] = $site_settings;
            $_SESSION[$_sc_k . '_t'] = time();
        } catch (Exception $e) {
            $site_settings = [];
        }
    }
}

// --- DARK MODE TOGGLE İŞLEMİ (POST İsteği ile) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_dark_mode'])) {
    Csrf::verifyOrDie();
    
    $new_mode = ($site_settings['dark_mode'] ?? 'off') === 'on' ? 'off' : 'on';
    
    // Veritabanını güncelle
    $stmt = $db->prepare("INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?, 'dark_mode', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([TENANT_ID, $new_mode]);

    // Session cache'i yeni değerle güncelle (eski değerle 300sn kilidi engelle)
    $site_settings['dark_mode'] = $new_mode;
    $_SESSION['_sc_st_' . TENANT_ID] = $site_settings;
    $_SESSION['_sc_st_' . TENANT_ID . '_t'] = time();
    
    // AJAX isteği ise JSON döndür
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'new_mode' => $new_mode]);
        exit;
    }
    
    // Normal form submit ise sayfayı yenile (open redirect koruması)
    $safe_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($safe_path && strpos($safe_path, '/admin/') === 0) {
        header('Location: ' . $safe_path);
    } else {
        header('Location: /admin/index.php');
    }
    exit;
}

// Site İsmi
$site_name_from_db = 'Yönetim Paneli';
if (!empty($site_settings['site_name'])) {
    $site_name_from_db = $site_settings['site_name'];
} elseif (defined('TENANT_DATA') && isset(TENANT_DATA['firma_adi'])) {
    $site_name_from_db = TENANT_DATA['firma_adi'];
}

// Oturum Kontrolü
if (!isLoggedIn()) {
    redirect('login.php');
}
if (!checkSessionTimeout()) {
     redirect('login.php?timeout=1');
}

// 3. PAKET SÜRESİ KONTROLÜ (session cache, 60 saniye TTL)
$package_expired = false;
$sidebar_days_left = null;
$sidebar_sms_remaining = null;
try {
    $_pc_k = '_sc_pkg_' . TENANT_ID;
    $_sb_k = '_sc_sbmeta_' . TENANT_ID;
    if (isset($_SESSION[$_pc_k . '_t']) && (time() - $_SESSION[$_pc_k . '_t']) < 60) {
        $package_expired = $_SESSION[$_pc_k] ?? false;
        if (isset($_SESSION[$_sb_k . '_days'])) {
            $sidebar_days_left = (int) $_SESSION[$_sb_k . '_days'];
        } else {
            $stmt_exp = $db->prepare("SELECT package_expiry, bitis_tarihi FROM tenants WHERE id = ?");
            $stmt_exp->execute([TENANT_ID]);
            $exp_row = $stmt_exp->fetch(PDO::FETCH_ASSOC);
            $expiry_raw = $exp_row['package_expiry'] ?? ($exp_row['bitis_tarihi'] ?? null);
            if (!empty($expiry_raw)) {
                $sidebar_days_left = (int) max(0, ceil((strtotime($expiry_raw) - time()) / 86400));
                $_SESSION[$_sb_k . '_days'] = $sidebar_days_left;
            }
        }
    } else {
        $stmt_exp = $db->prepare("SELECT package_expiry, bitis_tarihi FROM tenants WHERE id = ?");
        $stmt_exp->execute([TENANT_ID]);
        $exp_row = $stmt_exp->fetch(PDO::FETCH_ASSOC);
        $expiry_raw = $exp_row['package_expiry'] ?? ($exp_row['bitis_tarihi'] ?? null);
        if (!empty($expiry_raw)) {
            $expiry_ts = strtotime($expiry_raw);
            $package_expired = ($expiry_ts < time());
            $sidebar_days_left = (int) max(0, ceil(($expiry_ts - time()) / 86400));
            $_SESSION[$_sb_k . '_days'] = $sidebar_days_left;
        }
        $_SESSION[$_pc_k] = $package_expired;
        $_SESSION[$_pc_k . '_t'] = time();
    }
} catch (Exception $e) {
    // Sessizce devam et
}

try {
    $_sb_k = '_sc_sbmeta_' . TENANT_ID;
    if (isset($_SESSION[$_sb_k . '_sms'], $_SESSION[$_sb_k . '_sms_t']) && (time() - $_SESSION[$_sb_k . '_sms_t']) < 120) {
        $sidebar_sms_remaining = (int) $_SESSION[$_sb_k . '_sms'];
    } elseif (function_exists('getSmsStatus')) {
        $smsStat = getSmsStatus();
        $sidebar_sms_remaining = (int) ($smsStat['remaining'] ?? 0);
        $_SESSION[$_sb_k . '_sms'] = $sidebar_sms_remaining;
        $_SESSION[$_sb_k . '_sms_t'] = time();
    }
} catch (Exception $e) {
    $sidebar_sms_remaining = null;
}

if ($sidebar_days_left === null) {
    $sidebar_days_left = 0;
}
if ($sidebar_sms_remaining === null) {
    $sidebar_sms_remaining = 0;
}

$allowed_when_expired = ['billing.php', 'upgrade_package.php', 'logout.php', 'profile.php', 'packages_and_sms.php', 'checkout.php', 'welcome.php', 'emergency.php'];
if ($package_expired && !in_array($current_page, $allowed_when_expired)) {
    header("Location: billing.php?expired=1");
    exit;
}

// Rol ve İzinler
$user_role = $_SESSION['role'] ?? 'employee';
$allowed_pages_string = $_SESSION['allowed_pages'] ?? '';
$allowed_pages_array = [];
if ($user_role === 'employee' && !empty($allowed_pages_string)) {
    $allowed_pages_array = explode(',', $allowed_pages_string);
}

// Profil Kartı Verileri
$profile_name = $_SESSION['name'] ?? 'Kullanıcı';
$profile_role = $user_role;
$profile_role_labels = [
    'owner' => 'İşletme Sahibi',
    'manager' => 'Yönetici',
    'employee' => 'Çalışan',
    'admin' => 'Admin',
];
$profile_role_label = $profile_role_labels[$profile_role] ?? 'Kullanıcı';

// Profil fotoğrafı (session cache, 1 saat TTL — profile.php ziyaretinde otomatik temizlenir)
$profile_photo_url = '';
try {
    $user_id_for_photo = $_SESSION['user_id'] ?? 0;
    $_ph_k = '_sc_ph_' . $user_id_for_photo . '_' . TENANT_ID;
    $_ph_t = $_ph_k . '_t';
    // Profile sayfasında veya POST isteklerinde fotoğraf cache'ini temizle
    if ($current_page === 'profile.php' || ($current_page === 'employees.php' && $_SERVER['REQUEST_METHOD'] === 'POST')) {
        unset($_SESSION[$_ph_k], $_SESSION[$_ph_t]);
    }
    if (isset($_SESSION[$_ph_k], $_SESSION[$_ph_t]) && (time() - $_SESSION[$_ph_t]) < 3600) {
        $profile_photo_url = $_SESSION[$_ph_k];
    } else {
        $photo_file = null;
        if ($profile_role === 'owner') {
            // Owner: admins tablosundaki phone/email ile employees tablosunda eşleşen çalışanın fotoğrafını çek
            $stmt_admin = $db->prepare("SELECT phone, email FROM admins WHERE id = ? AND tenant_id = ?");
            $stmt_admin->execute([$user_id_for_photo, TENANT_ID]);
            $admin_info = $stmt_admin->fetch(PDO::FETCH_ASSOC);
            if ($admin_info) {
                $conditions = [];
                $params = [TENANT_ID];
                if (!empty($admin_info['phone'])) {
                    $conditions[] = 'phone = ?';
                    $params[] = $admin_info['phone'];
                }
                if (!empty($admin_info['email'])) {
                    $conditions[] = 'email = ?';
                    $params[] = $admin_info['email'];
                }
                if ($conditions) {
                    $where = implode(' OR ', $conditions);
                    $stmt_photo = $db->prepare("SELECT photo FROM employees WHERE tenant_id = ? AND ($where) AND status = 'active' LIMIT 1");
                    $stmt_photo->execute($params);
                    $photo_file = $stmt_photo->fetchColumn();
                }
            }
        } else {
            // Employee/Manager: employees tablosundan fotoğraf çek
            $stmt_photo = $db->prepare("SELECT photo FROM employees WHERE id = ? AND tenant_id = ?");
            $stmt_photo->execute([$user_id_for_photo, TENANT_ID]);
            $photo_file = $stmt_photo->fetchColumn();
        }
        if (!empty($photo_file)) {
            // R2 CDN URL kontrolü - https:// ile başlıyorsa direkt kullan
            if (strpos($photo_file, 'http') === 0) {
                $profile_photo_url = $photo_file;
            } else {
                // Eski local dosya için backward compatibility
                if (strpos($photo_file, 'assets/') !== 0) {
                    $photo_file = 'assets/uploads/employees/' . $photo_file;
                }
                $profile_photo_url = '../' . $photo_file;
            }
        }
        $_SESSION[$_ph_k] = $profile_photo_url;
        $_SESSION[$_ph_t] = time();
    }
} catch (Exception $e) {}

// Badge sayıları — async AJAX ile yükleniyor (sayfa render hızını etkilemez)
$unread_messages_count = 0;
$pending_tasks_count = 0;
$admin_notif_count = 0;

// Menü Yapısı
$sidebar_menu_items = [
    'index.php' => ['icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
    'daily_view.php' => ['icon' => 'fa-calendar-check', 'label' => 'Defter'],
    'appointments.php' => ['icon' => 'fa-calendar-alt', 'label' => 'Randevular'],
    'revenues_group' => ['icon' => 'fa-chart-line', 'label' => 'Gelirler', 'submenu' => [
        'revenues.php' => ['icon' => 'fa-chart-line', 'label' => 'Gelir Raporu'],
        'bekleyen_odemeler.php' => ['icon' => 'fa-exclamation-circle', 'label' => 'Bekleyen Ödemeler'],
    ]],
    'expenses.php' => ['icon' => 'fa-wallet', 'label' => 'Giderler'],
    'customers_group' => ['icon' => 'fa-users', 'label' => 'Müşteriler', 'submenu' => [
        'customers.php' => ['icon' => 'fa-users', 'label' => 'Müşteriler'],
        'reviews.php' => ['icon' => 'fa-star', 'label' => 'Değerlendirmeler'],
        'campaigns.php' => ['icon' => 'fa-bullhorn', 'label' => 'Kampanyalar'],
    ]],
    'employees_group' => ['icon' => 'fa-user-tie', 'label' => 'Çalışanlar', 'submenu' => [
        'employees.php' => ['icon' => 'fa-user-tie', 'label' => 'Çalışan Listesi'],
        'employee_salaries.php' => ['icon' => 'fa-money-bill-wave', 'label' => 'Maaş Yönetimi'],
    ], 'restricted_access' => true],
    'services.php' => ['icon' => 'fa-cut', 'label' => 'Hizmetler'],
    'products_group' => ['icon' => 'fa-box-open', 'label' => 'Ürün & Stok', 'submenu' => [
        'products.php' => ['icon' => 'fa-box-open', 'label' => 'Ürün Yönetimi'],
        'product_revenues.php' => ['icon' => 'fa-chart-bar', 'label' => 'Satış Gelirleri'],
    ]],
    'gallery.php' => ['icon' => 'fa-images', 'label' => 'Galeri Yönetimi'],
    'billing.php' => ['icon' => 'fa-credit-card', 'label' => 'Paket & Ödemeler', 'hidden_except_owner' => true],
    'upgrade_package.php' => ['icon' => 'fa-box-open', 'label' => 'Paket & SMS Satın Al', 'hidden_except_owner' => true],
    'settings.php' => ['icon' => 'fa-cog', 'label' => 'Site Ayarları', 'restricted_access' => true],
    'emergency.php' => ['icon' => 'fa-power-off', 'label' => 'Acil Durum', 'hidden_except_owner' => true],
    'trash.php' => ['icon' => 'fa-trash-alt', 'label' => 'Çöp Kutusu'],
];

// ============================================================
// KATEGORİ BAZLI MENÜ FARKLILASTIRMASI
// ============================================================
$_tenant_category = defined('TENANT_CATEGORY') ? TENANT_CATEGORY : '';

if (in_array($_tenant_category, ['motosiklet-servisi', 'oto-yikama'], true)) {
    $_is_oto_wash_menu = ($_tenant_category === 'oto-yikama');

    // Etiket değişiklikleri
    $sidebar_menu_items['daily_view.php']['hidden']                                          = true;
    $sidebar_menu_items['appointments.php']['hidden']                                       = true;
    $sidebar_menu_items['customers_group']['label']                                         = 'Araç Sahipleri';
    $sidebar_menu_items['customers_group']['submenu']['customers.php']['label']             = 'Araç Sahipleri';
    $sidebar_menu_items['employees_group']['label']                                         = $_is_oto_wash_menu ? 'Yıkama Ekibi' : 'Teknisyenler';
    $sidebar_menu_items['employees_group']['submenu']['employees.php']['label']             = $_is_oto_wash_menu ? 'Ekip Listesi' : 'Teknisyen Listesi';
    $sidebar_menu_items['employees_group']['submenu']['employee_salaries.php']['label']     = $_is_oto_wash_menu ? 'Ekip Maaşları' : 'Teknisyen Maaşları';
    $sidebar_menu_items['services.php']['label']                                            = $_is_oto_wash_menu ? 'Yıkama Paketleri' : 'İşlem Türleri';
    $sidebar_menu_items['services.php']['icon']                                             = $_is_oto_wash_menu ? 'fa-soap' : 'fa-tools';
    $sidebar_menu_items['products_group']['label']                                          = $_is_oto_wash_menu ? 'Ürünler & Stok' : 'Parça & Stok';
    $sidebar_menu_items['products_group']['submenu']['products.php']['label']               = $_is_oto_wash_menu ? 'Ürün Yönetimi' : 'Parça Yönetimi';
    $sidebar_menu_items['products_group']['submenu']['product_revenues.php']['label']       = $_is_oto_wash_menu ? 'Ürün Satış Gelirleri' : 'Parça Satış Gelirleri';

    // Gizlenecek sayfalar (menüden kaldır)
    $sidebar_menu_items['gallery.php']['hidden']                                            = true;
    $sidebar_menu_items['customers_group']['hidden']                                        = true;

    // Araç akış sayfaları
    $new_items = [];
    foreach ($sidebar_menu_items as $k => $v) {
        $new_items[$k] = $v;
        if ($k === 'index.php') {
            $new_items['servis_kabulleri.php']   = ['icon' => 'fa-calendar-check','label' => $_is_oto_wash_menu ? 'İş Takibi' : 'Günlük İş Takibi'];
            $new_items['service_orders.php']     = ['icon' => 'fa-clipboard-list', 'label' => $_is_oto_wash_menu ? 'Yikama Emirleri' : 'İş Emirleri'];
        }
        if ($k === 'customers_group') {
            $new_items['vehicles.php']           = ['icon' => $_is_oto_wash_menu ? 'fa-car-side' : 'fa-motorcycle', 'label' => 'Araç Kayıtları'];
        }
    }
    $sidebar_menu_items = $new_items;
    unset($new_items, $_is_oto_wash_menu);
}
unset($_tenant_category);

// NOT: can_access_page() fonksiyonu config.php'de tanımlı (çift tanımlama hatası önlendi)

// DARK MODE KONTROLÜ (Renk ayarlarından önce kontrol edilmeli)
$is_dark_mode = ($site_settings['dark_mode'] ?? 'off') === 'on';

// Renk Ayarları - Sayfa Bazlı
$current_page = basename($_SERVER['PHP_SELF']);

// Sayfa -> Renk Anahtarı Eşleştirmesi
$page_color_key_map = [
    'index.php' => 'dashboard',
    'daily_view.php' => 'daily_view',
    'servis_kabulleri.php' => 'daily_view',
    'service_orders.php'   => 'daily_view',
    'appointments.php' => 'appointments',
    'revenues.php' => 'revenues',
    'bekleyen_odemeler.php' => 'revenues',
    'expenses.php' => 'expenses',
    'customers.php' => 'customers',
    'reviews.php' => 'customers',
    'campaigns.php' => 'customers',
    'employees.php' => 'employees',
    'employee_salaries.php' => 'employees',
    'tasks.php' => 'tasks',
    'services.php' => 'services',
    'products.php' => 'services',
    'product_revenues.php' => 'services',
    'gallery.php' => 'gallery',
    'settings.php' => 'settings',
    'emergency.php' => 'settings'
];

// Varsayılan renkler
$default_colors = [
    'dashboard' => '#1e293b,#0f172a',
    'daily_view' => '#1e293b,#0f172a',
    'appointments' => '#1e293b,#0f172a',
    'revenues' => '#1e293b,#0f172a',
    'expenses' => '#1e293b,#0f172a',
    'customers' => '#1e293b,#0f172a',
    'employees' => '#1e293b,#0f172a',
    'tasks' => '#1e293b,#0f172a',
    'services' => '#1e293b,#0f172a',
    'gallery' => '#1e293b,#0f172a',
    'settings' => '#1e293b,#0f172a'
];

// Mevcut sayfa için renk anahtarını bul
$page_key = $page_color_key_map[$current_page] ?? 'dashboard';
$color_setting_key = 'sidebar_color_' . $page_key;

// Renk değerlerini al
$color_values = $site_settings[$color_setting_key] ?? $default_colors[$page_key] ?? '#1e293b,#0f172a';

// Global Renk Uygulama Ayarları
$apply_to_dark_mode = ($site_settings['dark_mode_apply_content'] ?? 'off') === 'on';
$apply_to_topnav = ($site_settings['dark_mode_apply_topnav'] ?? 'off') === 'on';
$apply_to_sidebar = ($site_settings['dark_mode_apply_sidebar'] ?? 'off') === 'on';

// Renkleri parse et
$colors = explode(',', $color_values);
$color1 = $colors[0] ?? '#1e293b';
$color2 = $colors[1] ?? '#0f172a';

// Varsayılan renkler
$dark_default1 = '#1e293b';
$dark_default2 = '#0f172a';
$light_sidebar_default1 = '#ffffff';
$light_sidebar_default2 = '#f8fafc';
$light_topnav_default1 = '#ffffff';
$light_topnav_default2 = '#f8fafc';

// Sidebar renkleri hesapla
if ($is_dark_mode) {
    // Dark mode açık
    if ($apply_to_dark_mode && $apply_to_sidebar) {
        // Karanlık mod renk açık VE sidebar açık → Sayfa rengi
        $sidebar_color1 = $color1;
        $sidebar_color2 = $color2;
    } else {
        // Varsayılan dark renkler
        $sidebar_color1 = $dark_default1;
        $sidebar_color2 = $dark_default2;
    }
} else {
    // Light mode
    if ($apply_to_sidebar) {
        // Sidebar açık → Sayfa rengi
        $sidebar_color1 = $color1;
        $sidebar_color2 = $color2;
    } else {
        // Varsayılan light renkler
        $sidebar_color1 = $light_sidebar_default1;
        $sidebar_color2 = $light_sidebar_default2;
    }
}

// Topnav renkleri hesapla
if ($is_dark_mode) {
    // Dark mode açık
    if ($apply_to_dark_mode && $apply_to_topnav) {
        // Karanlık mod renk açık VE topnav açık → Sayfa rengi
        $topnav_color1 = $color1;
        $topnav_color2 = $color2;
    } else {
        // Varsayılan dark renkler
        $topnav_color1 = $dark_default1;
        $topnav_color2 = $dark_default2;
    }
} else {
    // Light mode
    if ($apply_to_topnav) {
        // Topnav açık → Sayfa rengi
        $topnav_color1 = $color1;
        $topnav_color2 = $color2;
    } else {
        // Varsayılan light renkler
        $topnav_color1 = $light_topnav_default1;
        $topnav_color2 = $light_topnav_default2;
    }
}

$sidebar_gradient = "linear-gradient(180deg, {$sidebar_color1} 0%, {$sidebar_color2} 100%)";
$topnav_gradient = "linear-gradient(135deg, {$topnav_color1} 0%, {$topnav_color2} 100%)";
$current_gradient = $sidebar_gradient; // Geriye uyumluluk için
$header_title_color = $color2;

// Sidebar metin kontrastı (açık zemin = koyu yazı)
$sidebar_theme_class = 'sidebar-theme-dark';
try {
    $hex = ltrim((string)$sidebar_color1, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if (strlen($hex) === 6) {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luma = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        if ($luma > 0.62) {
            $sidebar_theme_class = 'sidebar-theme-light';
        }
    }
} catch (Exception $e) {}


// Dark mode toggle - AJAX POST istegi
if (!function_exists('getDarkModeToggleHtml')) {
    function getDarkModeToggleHtml() {
        return '
    <form id="dark_mode_toggle_form" method="POST" style="display: none;">
        <input type="hidden" name="toggle_dark_mode" value="1">
        <input type="hidden" name="csrf_token" value="' . csrf_token() . '">
    </form>
    <script>
    function toggleDarkMode() {
        document.getElementById("dark_mode_toggle_form").submit();
    }
    </script>';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - Yönetim Paneli' : 'Yönetim Paneli'; ?> - <?php echo htmlspecialchars($site_name_from_db); ?></title>
    
    <?php 
    $css_dir = __DIR__ . '/../assets/css/';
    $js_dir = __DIR__ . '/../assets/js/';
    $asset_version = max(
        filemtime($css_dir . 'main.css') ?: 0,
        filemtime($css_dir . 'common-components.css') ?: 0,
        filemtime($css_dir . 'components.css') ?: 0,
        filemtime($css_dir . 'variables.css') ?: 0,
        filemtime($css_dir . 'layout.css') ?: 0,
        filemtime($css_dir . 'profile-card.css') ?: 0,
        filemtime($css_dir . 'admin-notifications.css') ?: 0,
        filemtime($css_dir . 'appointments.css') ?: 0,
        filemtime($css_dir . 'daily_view.css') ?: 0,
        filemtime($css_dir . 'appointments-dark.css') ?: 0,
        filemtime($css_dir . 'daily_view-dark.css') ?: 0,
        filemtime($css_dir . 'bekleyen_odemeler.css') ?: 0
    ) ?: time();
    $profile_card_ver = filemtime($css_dir . 'profile-card.css') ?: $asset_version;
    $profile_card_dark_ver = filemtime($css_dir . 'profile-card-dark.css') ?: $asset_version;
    $admin_notif_ver = filemtime($css_dir . 'admin-notifications.css') ?: $asset_version;
    $admin_notif_dark_ver = filemtime($css_dir . 'admin-notifications-dark.css') ?: $asset_version;
    $notification_css_ver = filemtime($css_dir . 'notification.css') ?: $asset_version;
    $engage_css_ver = filemtime(dirname(__DIR__, 2) . '/assets/css/randu-engage.css') ?: 8;
    ?>

    <!-- Kritik: CSS gecikse bile paneller ekranı bozmasın -->
    <style id="randu-critical-ui">
      #notifDropdown:not(.open),
      .notif-dropdown:not(.open) { display: none !important; }
      .profile-card-panel:not(.open) {
        position: fixed !important;
        top: 0; right: 0;
        transform: translateX(110%) !important;
        visibility: hidden !important;
        pointer-events: none !important;
      }
      .profile-card-overlay:not(.open),
      .notif-overlay:not(.open),
      .rehber-submenu:not(.open) { display: none !important; }
      .re-push-row { max-width: 100%; box-sizing: border-box; }
      html { visibility: visible; }
      body { margin: 0; min-height: 100vh; background: #f1f5f9; }
      body.dark-mode { background: #0f172a; }
      .admin-wrapper { display: flex; min-height: 100vh; }
      .admin-main { flex: 1; min-width: 0; padding: 1rem; }
    </style>

    <link rel="preload" href="assets/css/variables.css?v=<?php echo $asset_version; ?>" as="style">
    <link rel="preload" href="assets/css/main.css?v=<?php echo $asset_version; ?>" as="style">
    <link rel="preload" href="assets/css/layout.css?v=<?php echo $asset_version; ?>" as="style">
    
    <!-- YENİ CSS YAPISI -->
    <link rel="stylesheet" href="assets/css/variables.css?v=<?php echo $asset_version; ?>">
    <link rel="stylesheet" href="assets/css/main.css?v=<?php echo $asset_version; ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?php echo $asset_version; ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?php echo $asset_version; ?>">
    <link rel="stylesheet" href="assets/css/theme-switch.css?v=<?php echo $asset_version; ?>">
    
    <!-- ORTAK COMPONENT CSS - TÜM SAYFALAR İÇİN -->
    <link rel="stylesheet" href="assets/css/common-components.css?v=<?php echo $asset_version; ?>">
    <link rel="stylesheet" href="assets/css/profile-card.css?v=<?php echo $profile_card_ver; ?>">
    <?php if ($is_dark_mode): ?>
    <link rel="stylesheet" href="assets/css/common-components-dark.css?v=<?php echo $asset_version; ?>">
    <link rel="stylesheet" href="assets/css/profile-card-dark.css?v=<?php echo $profile_card_dark_ver; ?>">
    <?php endif; ?>
    
    <!-- GLOBAL NOTIFICATION SYSTEM -->
    <?php if (!isset($current_page) || ($current_page !== 'daily_view.php' && $current_page !== 'servis_kabulleri.php')): ?>
    <link rel="stylesheet" href="assets/css/notification.css?v=<?php echo $notification_css_ver; ?>">
    <?php endif; ?>
    
    <!-- Admin Notification Bell -->
    <link rel="stylesheet" href="assets/css/admin-notifications.css?v=<?php echo $admin_notif_ver; ?>">
    <?php if ($is_dark_mode): ?>
    <link rel="stylesheet" href="assets/css/admin-notifications-dark.css?v=<?php echo $admin_notif_dark_ver; ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/randu-engage.css?v=<?php echo $engage_css_ver; ?>">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    

    <!-- Sayfa Bazlı Eski CSS'ler (Kademeli olarak temizlenecek) -->
<?php
$page = basename($_SERVER['PHP_SELF']);

switch ($page) {
    case 'services.php':
        echo '<link rel="stylesheet" href="assets/css/services.css?v=' . $asset_version . '">';
        if ($is_dark_mode) echo '<link rel="stylesheet" href="assets/css/services-dark.css?v=' . $asset_version . '">';
        break;

    case 'gallery.php':
        echo '<link rel="stylesheet" href="assets/css/gallery.css?v=' . $asset_version . '">';
        if ($is_dark_mode) echo '<link rel="stylesheet" href="assets/css/gallery-dark.css?v=' . $asset_version . '">';
        break;

    case 'revenues.php':
        echo '<link rel="stylesheet" href="assets/css/revenues.css?v=' . $asset_version . '">';
        if ($is_dark_mode) echo '<link rel="stylesheet" href="assets/css/revenues-dark.css?v=' . $asset_version . '">';
        break;

    case 'settings.php':
        $settings_ver = max(filemtime($css_dir . 'settings.css') ?: 0, filemtime($css_dir . 'settings-dark.css') ?: 0) ?: time();
        echo '<link rel="stylesheet" href="assets/css/settings.css?v=' . $settings_ver . '">';
        if ($is_dark_mode) echo '<link rel="stylesheet" href="assets/css/settings-dark.css?v=' . $settings_ver . '">';
        break;        

    case 'index.php':
        $index_ver = max(filemtime($css_dir . 'index.css') ?: 0, filemtime($css_dir . 'index-dark.css') ?: 0) ?: time();
        echo '<link rel="stylesheet" href="assets/css/index.css?v=' . $index_ver . '">';
        if ($is_dark_mode) echo '<link rel="stylesheet" href="assets/css/index-dark.css?v=' . $index_ver . '">';
        break;    

    case 'daily_view.php':
    case 'servis_kabulleri.php':
        echo '<link rel="stylesheet" href="assets/css/daily_view.css?v=' . $asset_version . '">';
        echo '<link rel="stylesheet" href="assets/css/filter-sidebar.css?v=' . $asset_version . '">';
        if ($is_dark_mode) {
            echo '<link rel="stylesheet" href="assets/css/daily_view-dark.css?v=' . $asset_version . '">';
            echo '<link rel="stylesheet" href="assets/css/filter-sidebar-dark.css?v=' . $asset_version . '">';
        }
        break;    

    case 'service_orders.php':
        echo '<link rel="stylesheet" href="assets/css/appointments.css?v=' . $asset_version . '">';
        echo '<link rel="stylesheet" href="assets/css/filter-sidebar.css?v=' . $asset_version . '">';
        if ($is_dark_mode) {
            echo '<link rel="stylesheet" href="assets/css/appointments-dark.css?v=' . $asset_version . '">';
            echo '<link rel="stylesheet" href="assets/css/filter-sidebar-dark.css?v=' . $asset_version . '">';
            echo '<link rel="stylesheet" href="assets/css/service_orders-dark.css?v=' . $asset_version . '">';
        }
        break;

    case 'customers.php':
    case 'vehicles.php':
        echo '<link rel="stylesheet" href="assets/css/customers.css?v=' . $asset_version . '">';
        echo '<link rel="stylesheet" href="assets/css/filter-sidebar.css?v=' . $asset_version . '">';
        if ($is_dark_mode) {
            echo '<link rel="stylesheet" href="assets/css/customer-dark.css?v=' . $asset_version . '">';
            echo '<link rel="stylesheet" href="assets/css/filter-sidebar-dark.css?v=' . $asset_version . '">';
        }
        break;

    case 'expenses.php':
        echo '<link rel="stylesheet" href="assets/css/expenses.css?v=' . $asset_version . '">';
        echo '<link rel="stylesheet" href="assets/css/filter-sidebar.css?v=' . $asset_version . '">';
        if ($is_dark_mode) {
            echo '<link rel="stylesheet" href="assets/css/expenses-dark.css?v=' . $asset_version . '">';
            echo '<link rel="stylesheet" href="assets/css/filter-sidebar-dark.css?v=' . $asset_version . '">';
        }
        break;

    case 'appointments.php':
        echo '<link rel="stylesheet" href="assets/css/appointments.css?v=' . $asset_version . '">';
        echo '<link rel="stylesheet" href="assets/css/filter-sidebar.css?v=' . $asset_version . '">';
        if ($is_dark_mode) {
            echo '<link rel="stylesheet" href="assets/css/appointments-dark.css?v=' . $asset_version . '">';
            echo '<link rel="stylesheet" href="assets/css/filter-sidebar-dark.css?v=' . $asset_version . '">';
        }
        break;

    case 'dashboard.php':
        echo '<link rel="stylesheet" href="assets/css/dashboard.css?v=' . $asset_version . '">';
        if ($is_dark_mode) echo '<link rel="stylesheet" href="assets/css/dashboard-dark.css?v=' . $asset_version . '">';
        break;

    case 'employees.php':
        echo '<link rel="stylesheet" href="assets/css/employees.css?v=' . $asset_version . '">';
        echo '<link rel="stylesheet" href="assets/css/filter-sidebar.css?v=' . $asset_version . '">';
        if ($is_dark_mode) {
            echo '<link rel="stylesheet" href="assets/css/employees-dark.css?v=' . $asset_version . '">';
            echo '<link rel="stylesheet" href="assets/css/filter-sidebar-dark.css?v=' . $asset_version . '">';
        }
        break;
    
    case 'employee_salaries.php':
        $es_ver = filemtime($css_dir . 'employee_salaries.css') ?: time();
        echo '<link rel="stylesheet" href="assets/css/employee_salaries.css?v=' . $es_ver . '">';
        if ($is_dark_mode) {
            echo '<link rel="stylesheet" href="assets/css/employee_salaries-dark.css?v=' . $es_ver . '">';
        }
        break;

    case 'tasks.php':
        echo '<link rel="stylesheet" href="assets/css/tasks.css?v=' . (filemtime($css_dir . 'tasks.css') ?: $asset_version) . '">';
        echo '<link rel="stylesheet" href="assets/css/filter-sidebar.css?v=' . $asset_version . '">';
        if ($is_dark_mode) {
            echo '<link rel="stylesheet" href="assets/css/tasks-dark.css?v=' . (filemtime($css_dir . 'tasks-dark.css') ?: $asset_version) . '">';
            echo '<link rel="stylesheet" href="assets/css/filter-sidebar-dark.css?v=' . $asset_version . '">';
        }
        break;

    case 'bekleyen_odemeler.php':
        $pp_ver = max(
            filemtime($css_dir . 'bekleyen_odemeler.css') ?: 0,
            filemtime($css_dir . 'bekleyen_odemeler-dark.css') ?: 0
        ) ?: $asset_version;
        echo '<link rel="stylesheet" href="assets/css/bekleyen_odemeler.css?v=' . $pp_ver . '">';
        if ($is_dark_mode) {
            echo '<link rel="stylesheet" href="assets/css/bekleyen_odemeler-dark.css?v=' . $pp_ver . '">';
        }
        break;
}
?>

    <!-- Sidebar Rengi İçin Inline Style (Dinamik olduğu için burada kalmalı) -->
    <style>
        :root {
            --sidebar-gradient: <?php echo $sidebar_gradient; ?>;
            --topnav-gradient: <?php echo $topnav_gradient; ?>;
            --page-header-color: <?php echo $color1; ?>;
            --page-header-color-dark: <?php echo $color2; ?>;
        }
        
        /* Kilitli Menü Öğeleri - Genel Stiller */
        .menu-item.locked {
            opacity: 0.65 !important;
            cursor: not-allowed !important;
        }
        .menu-item.locked:hover {
            background-color: rgba(220, 38, 38, 0.08) !important;
        }
        [data-theme="dark"] .menu-item.locked:hover {
            background-color: rgba(239, 68, 68, 0.12) !important;
        }
        
        <?php if (!$is_dark_mode && !$apply_to_sidebar): ?>
        /* Light mode + Sidebar renk kapalı = Beyaz sidebar, lacivert yazılar */
        .admin-sidebar .menu-item {
            color: #1e3a5f !important;
        }
        .admin-sidebar .menu-item i {
            color: #1e3a5f !important;
            opacity: 0.85;
        }
        .admin-sidebar .menu-item:hover {
            background-color: rgba(30, 58, 95, 0.1) !important;
            color: #1e3a5f !important;
        }
        .admin-sidebar .menu-item.active {
            background: rgba(30, 58, 95, 0.15) !important;
            color: #1e3a5f !important;
        }
        .admin-sidebar .menu-item.locked {
            opacity: 0.6;
            cursor: not-allowed !important;
        }
        .admin-sidebar .menu-item.locked:hover {
            background-color: rgba(220, 38, 38, 0.05) !important;
        }
        .admin-sidebar .menu-item::before {
            background: #1e3a5f !important;
        }
        .admin-sidebar .sidebar-menu hr {
            border-top-color: rgba(30, 58, 95, 0.2) !important;
        }
        .admin-sidebar .site-name {
            color: #1e3a5f !important;
        }
        .admin-sidebar .sidebar-toggle-btn {
            color: #1e3a5f !important;
            background: rgba(30, 58, 95, 0.1) !important;
        }
        .admin-sidebar .sidebar-toggle-btn:hover {
            background: rgba(30, 58, 95, 0.2) !important;
        }
        <?php endif; ?>
        
        <?php if (!$is_dark_mode && !$apply_to_topnav): ?>
        /* Light mode + Topnav renk kapalı = Beyaz header, lacivert yazılar */
        .admin-header h1 {
            color: #1e3a5f !important;
        }
        .admin-header .user-info span {
            color: #1e3a5f !important;
        }
        .admin-header .user-info i {
            color: #1e3a5f !important;
        }
        .admin-header .mobile-menu-toggle .hamburger-icon span {
            background-color: #1e3a5f !important;
        }
        .admin-header .theme-switch__container {
            border-color: rgba(30, 58, 95, 0.3) !important;
        }
        <?php endif; ?>
    </style>
    <script src="assets/js/csrf-interceptor.js?v=1"></script>
    <script>
    window.RANDU_ADMIN = Object.assign({}, window.RANDU_ADMIN || {}, {
        isVehicleCategory: <?php echo (function_exists('is_vehicle_service_category') && is_vehicle_service_category()) ? 'true' : 'false'; ?>,
        defterUrl: <?php echo json_encode(function_exists('admin_defter_url') ? admin_defter_url() : 'daily_view.php'); ?>,
        appointmentsUrl: <?php echo json_encode(function_exists('admin_appointments_url') ? admin_appointments_url() : 'appointments.php'); ?>,
        category: <?php echo json_encode(defined('TENANT_CATEGORY') ? (string) TENANT_CATEGORY : ''); ?>
    });
    </script>
    <script src="../assets/js/email-sanitizer.js?v=1" defer></script>

    <!-- PWA -->
    <link rel="manifest" href="/admin/manifest.json">
    <meta name="theme-color" content="#1e293b">
    <script>
    // PWA standalone modunda ekranı dikey kilitle
    (function(){
        try {
            var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            if (isStandalone && screen.orientation && typeof screen.orientation.lock === 'function') {
                screen.orientation.lock('portrait').catch(function(){});
            }
        } catch(e) {}
    })();
    </script>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RANDU İşletmem">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/uploads/pwa/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/assets/uploads/pwa/apple-touch-icon-152.png">
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/admin/sw.js?v=9', { scope: '/admin/' });
        });
    }
    </script>
</head>
<body class="<?php echo $is_dark_mode ? 'dark-mode' : ''; ?>">
    
    <?php
    // ============================================
    // PENDING PAYMENT WARNING BANNER
    // ============================================
    // Kullanıcı yarım bıraktığı ödemelerini göster
    // Ancak ödeme/billing sayfalarında gösterme — zaten ilgili sayfada
    $_payment_pages = ['checkout.php', 'billing.php', 'upgrade_package.php', 'payment.php', 'bekleyen_odemeler.php'];
    if (!isset($_SESSION['dismissed_payment_warning']) && !in_array($current_page, $_payment_pages, true)) {
        try {
            // UNION sonrası ORDER BY için alt sorgu — created_at ambiguous hatasını önler
            $pending_check = $db->prepare("
                SELECT * FROM (
                    SELECT 
                        'package' as type, 
                        p.id, 
                        p.package_id as checkout_id,
                        p.amount,
                        p.payment_method,
                        pkg.name as item_name,
                        p.created_at AS created_at
                    FROM payments p
                    LEFT JOIN packages pkg ON p.package_id = pkg.id
                    WHERE p.tenant_id = ? 
                      AND p.payment_status = 'pending'
                      AND p.created_at >= (NOW() - INTERVAL 30 DAY)
                    
                    UNION ALL
                    
                    SELECT 
                        'sms' as type, 
                        sp.id, 
                        sp.sms_package_id as checkout_id,
                        sp.amount,
                        sp.payment_method,
                        COALESCE(sms_pkg.name, CONCAT(sp.sms_count, ' SMS')) as item_name,
                        sp.created_at AS created_at
                    FROM sms_purchases sp
                    LEFT JOIN sms_packages sms_pkg ON sp.sms_package_id = sms_pkg.id
                    WHERE sp.tenant_id = ? 
                      AND sp.payment_status = 'pending'
                      AND sp.created_at >= (NOW() - INTERVAL 30 DAY)
                ) AS pending_items
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $pending_check->execute([TENANT_ID, TENANT_ID]);
            $pending_payment = $pending_check->fetch();
            
            if ($pending_payment):
                $item_display = $pending_payment['item_name'] ? htmlspecialchars($pending_payment['item_name']) : 'Ödeme';
                $amount_display = number_format($pending_payment['amount'], 2, ',', '.');
                $_pm = $pending_payment['payment_method'] ?? null;
                $_is_bank = ($_pm === 'bank_transfer');
                $_banner_label = $_is_bank ? 'Havale onayı bekleyen ödemeniz:' : 'Yarım kalan ödemeniz var:';
                $_btn_label    = $_is_bank ? '<i class="bi bi-eye"></i> Detayları Gör' : '<i class="bi bi-arrow-right-circle"></i> Devam Et';
                // Havale ise billing'e (onay durumu), değilse checkout'a yolla
                $_btn_action   = $_is_bank ? "goPendingDetails()" : "continuePayment(" . (int)($pending_payment['checkout_id'] ?? 0) . ", '" . $pending_payment['type'] . "')";
    ?>
    <div id="pendingPaymentBanner" class="pending-payment-warning">
        <div class="warning-content">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><strong><?php echo $_banner_label; ?></strong> <?php echo $item_display; ?> (<?php echo $amount_display; ?> ₺)</span>
        </div>
        <div class="warning-actions">
            <button type="button" onclick="<?php echo $_btn_action; ?>" class="btn-continue">
                <?php echo $_btn_label; ?>
            </button>
            <button type="button" onclick="dismissWarning()" class="btn-dismiss" title="Bu bildirimi kapat" aria-label="Kapat">×</button>
        </div>
    </div>
    
    <style>
        .pending-payment-warning {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 10000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            animation: slideDown 0.4s ease-out;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(0);
                opacity: 1;
            }
            to {
                transform: translateY(-100%);
                opacity: 0;
            }
        }
        
        .warning-content {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 15px;
        }
        
        .warning-content i {
            font-size: 20px;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .warning-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .btn-continue {
            background: white;
            color: #d97706;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-continue:hover {
            background: #fef3c7;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .btn-dismiss {
            background: rgba(255, 255, 255, 0.25);
            color: white;
            padding: 6px 14px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 6px;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            line-height: 1;
        }
        
        .btn-dismiss:hover {
            background: rgba(255, 255, 255, 0.4);
            transform: scale(1.1);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .pending-payment-warning {
                flex-direction: column;
                gap: 12px;
                padding: 12px 16px;
                text-align: center;
            }
            
            .warning-content {
                flex-direction: column;
                gap: 8px;
            }
            
            .warning-actions {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Dark mode uyumu */
        body.dark-mode .pending-payment-warning {
            background: linear-gradient(135deg, #ea580c 0%, #c2410c 100%);
        }
    </style>
    
    <script>
        function continuePayment(id, type) {
            if (!id || id < 1) {
                // checkout_id yoksa upgrade sayfasına gönder
                window.location.href = (type === 'sms') ? 'upgrade_package.php?tab=sms' : 'upgrade_package.php';
                return;
            }
            // Checkout sayfasına geri dön
            window.location.href = 'checkout.php?type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id);
        }

        function goPendingDetails() {
            // Havale onayı bekleyen ödemeler için billing sayfası
            window.location.href = 'billing.php';
        }

        function _hidePendingBanner() {
            const banner = document.getElementById('pendingPaymentBanner');
            if (!banner) return;
            banner.style.animation = 'slideUp 0.3s ease-out';
            setTimeout(() => { banner.style.display = 'none'; }, 300);
        }

        function dismissWarning() {
            // Önce UI'ı kapat (kullanıcı geri bildirimi anında olsun)
            _hidePendingBanner();
            // AJAX ile session'a dismiss flag'i ekle
            fetch('ajax/dismiss_payment_warning.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'csrf_token=' + encodeURIComponent('<?php echo $_SESSION['csrf_token'] ?? ''; ?>')
            }).catch(function(error) {
                console.error('Error dismissing warning:', error);
            });
        }
    </script>
    <?php 
            endif;
        } catch (Exception $e) {
            // Hata durumunda sessizce devam et
            error_log("Pending payment check error: " . $e->getMessage());
        }
    }
    ?>
    
    <div class="admin-wrapper">
        <div class="sidebar-overlay"></div>

        <nav class="admin-sidebar <?php echo htmlspecialchars($sidebar_theme_class ?? 'sidebar-theme-dark'); ?>" id="adminSidebar" style="background: <?php echo $sidebar_gradient; ?>;">
            <div class="sidebar-header">
                <?php
                    $sb_days = (int) ($sidebar_days_left ?? 0);
                    $sb_sms = (int) ($sidebar_sms_remaining ?? 0);
                    $sb_days_tone = $sb_days <= 7 ? 'is-warn' : ($sb_days <= 30 ? 'is-mid' : 'is-ok');
                    $sb_sms_tone = $sb_sms <= 10 ? 'is-warn' : ($sb_sms <= 50 ? 'is-mid' : 'is-ok');
                ?>
                <a href="billing.php" class="sidebar-meta" title="Paket ve SMS durumu">
                    <span class="sidebar-meta-pill <?php echo $sb_days_tone; ?>">
                        <span class="sidebar-meta-icon" aria-hidden="true"><i class="far fa-calendar-alt"></i></span>
                        <span class="sidebar-meta-copy">
                            <span class="sidebar-meta-label">Kalan süre</span>
                            <span class="sidebar-meta-value"><?php echo $sb_days; ?><small>gün</small></span>
                        </span>
                    </span>
                    <span class="sidebar-meta-divider" aria-hidden="true"></span>
                    <span class="sidebar-meta-pill <?php echo $sb_sms_tone; ?>">
                        <span class="sidebar-meta-icon" aria-hidden="true"><i class="far fa-comment-dots"></i></span>
                        <span class="sidebar-meta-copy">
                            <span class="sidebar-meta-label">SMS kredi</span>
                            <span class="sidebar-meta-value"><?php echo number_format($sb_sms, 0, ',', '.'); ?></span>
                        </span>
                    </span>
                </a>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar" data-tooltip="Sabitlemeyi Kaldır">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>

            <div class="sidebar-menu">
                <?php foreach ($sidebar_menu_items as $file => $item): ?>
                    <?php
                    // Kategori bazlı gizlenmiş öğeler
                    if (!empty($item['hidden'])) continue;
                    // Dropdown menü
                    if (isset($item['submenu'])): 
                        // Erişilebilir alt öğe var mı kontrol et (hidden olmayanlar arasında)
                        $has_accessible_sub = false;
                        foreach ($item['submenu'] as $sub_file => $sub_item) {
                            if (!empty($sub_item['hidden'])) continue;
                            if (can_access_page($sub_file)) { $has_accessible_sub = true; break; }
                        }
                        // Hiçbir alt öğeye erişim yoksa dropdown'ı tamamen gizle
                        if (!$has_accessible_sub) continue;
                        
                        $sub_active = false;
                        foreach ($item['submenu'] as $sub_file => $sub_item) {
                            if ($current_page == $sub_file) { $sub_active = true; break; }
                        }
                    ?>
                    <div class="menu-dropdown <?php echo $sub_active ? 'open' : ''; ?>">
                        <a href="javascript:void(0)" class="menu-item menu-dropdown-toggle <?php echo $sub_active ? 'active' : ''; ?>" data-menu-group="<?php echo $file; ?>">
                            <i class="fas <?php echo $item['icon']; ?>"></i> <span><?php echo htmlspecialchars($item['label']); ?></span>
                            <i class="fas fa-chevron-down menu-dropdown-arrow"></i>
                        </a>
                        <div class="menu-dropdown-items">
                            <?php foreach ($item['submenu'] as $sub_file => $sub_item): ?>
                                <?php if (!empty($sub_item['hidden'])) continue; ?>
                                <?php if (!can_access_page($sub_file)) continue; ?>
                                <a href="<?php echo $sub_file; ?>" class="menu-item menu-sub-item <?php echo $current_page == $sub_file ? 'active' : ''; ?>">
                                    <i class="fas <?php echo $sub_item['icon']; ?>"></i> <span><?php echo htmlspecialchars($sub_item['label']); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <?php 
                    // Erişim kontrolü - Erişemediği sayfaları gösterme
                    if (!can_access_page($file)) continue;
                    ?>
                    
                    <a href="<?php echo $file; ?>" class="menu-item <?php echo $current_page == $file ? 'active' : ''; ?>">
                        <i class="fas <?php echo $item['icon']; ?>"></i> <span><?php echo htmlspecialchars($item['label']); ?></span>
                    </a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <hr>

                <a href="../index.php" target="_blank" class="menu-item">
                    <i class="fas fa-external-link-alt"></i> <span>Siteyi Görüntüle</span>
                </a>

                <a href="logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i> <span>Çıkış Yap</span>
                </a>
            </div>
        </nav>

        <div class="admin-content" id="adminContent">
            <header class="admin-header" style="background: <?php echo $topnav_gradient; ?>;">
                <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle navigation menu">
                    <div class="hamburger-icon"><span></span><span></span><span></span></div>
                </button>

                <h1><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Dashboard'; ?></h1>

                <!-- SAĞ TARAF GRUBU -->
                <div class="header-right-actions">
                    <!-- Notification Bell -->
                    <div class="notif-bell-wrapper" id="notifBellWrapper" onmouseenter="AdminNotifications.hoverOpen()" onmouseleave="AdminNotifications.hoverClose()">
                        <button class="notif-bell-btn" id="notifBellBtn" aria-label="Bildirimler" onclick="AdminNotifications.toggle(event)">
                            <svg viewBox="0 0 24 24" fill="none" height="22" width="22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M12 5.365V3m0 2.365a5.338 5.338 0 0 1 5.133 5.368v1.8c0 2.386 1.867 2.982 1.867 4.175 0 .593 0 1.292-.538 1.292H5.538C5 18 5 17.301 5 16.708c0-1.193 1.867-1.789 1.867-4.175v-1.8A5.338 5.338 0 0 1 12 5.365ZM8.733 18c.094.852.306 1.54.944 2.112a3.48 3.48 0 0 0 4.646 0c.638-.572 1.236-1.26 1.33-2.112h-6.92Z" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" stroke="currentColor"></path>
                            </svg>
                            <?php if ($admin_notif_count > 0): ?>
                            <span class="notif-bell-badge" id="notifBellBadge"><?php echo $admin_notif_count > 99 ? '99+' : $admin_notif_count; ?></span>
                            <span class="notif-bell-pulse"></span>
                            <?php else: ?>
                            <span class="notif-bell-badge" id="notifBellBadge" style="display:none;">0</span>
                            <span class="notif-bell-pulse" style="display:none;"></span>
                            <?php endif; ?>
                        </button>

                        <!-- Dropdown Panel -->
                        <div class="notif-dropdown" id="notifDropdown">
                            <div class="notif-dropdown-header">
                                <h4>Bildirimler</h4>
                                <button class="notif-mark-all" onclick="AdminNotifications.markAllRead()" title="Tümünü okundu işaretle">
                                    <i class="fas fa-check-double"></i>
                                </button>
                            </div>
                            <div class="notif-tabs">
                                <button class="notif-tab active" data-tab="business" onclick="AdminNotifications.switchTab('business')">
                                    <i class="fas fa-store"></i> İşletme
                                    <span class="notif-tab-badge" id="notifTabBusiness">0</span>
                                </button>
                                <button class="notif-tab" data-tab="system" onclick="AdminNotifications.switchTab('system')">
                                    <i class="fas fa-server"></i> Sistem
                                    <span class="notif-tab-badge" id="notifTabSystem">0</span>
                                </button>
                            </div>
                            <div class="notif-list" id="notifList">
                                <div class="notif-loading">
                                    <div class="notif-spinner"></div>
                                </div>
                            </div>
                            <div class="notif-dropdown-footer">
                                <a href="bildirimler.php" class="notif-view-all-link">Tümünü Gör <i class="fas fa-arrow-right"></i></a>
                                <span class="notif-empty-text" id="notifEmptyText" style="display:none;">Bildirim yok</span>
                            </div>
                        </div>
                    </div>

                    <!-- Dark Mode Switch -->
                    <label class="theme-switch">
                                            <input type="checkbox" class="theme-switch__checkbox" <?php echo $is_dark_mode ? 'checked' : ''; ?> onchange="document.getElementById('dark_mode_toggle_form').submit()">
                      <div class="theme-switch__container">
                        <div class="theme-switch__clouds"></div>
                        <div class="theme-switch__stars-container">
                          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 144 55" fill="none">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M135.831 3.00688C135.055 3.85027 134.111 4.29946 133 4.35447C134.111 4.40947 135.055 4.85867 135.831 5.71123C136.607 6.55462 136.996 7.56303 136.996 8.72727C136.996 7.95722 137.172 7.25134 137.525 6.59129C137.886 5.93124 138.372 5.39954 138.98 5.00535C139.598 4.60199 140.268 4.39114 141 4.35447C139.88 4.2903 138.936 3.85027 138.16 3.00688C137.384 2.16348 136.996 1.16425 136.996 0C136.996 1.16425 136.607 2.16348 135.831 3.00688ZM31 23.3545C32.1114 23.2995 33.0551 22.8503 33.8313 22.0069C34.6075 21.1635 34.9956 20.1642 34.9956 19C34.9956 20.1642 35.3837 21.1635 36.1599 22.0069C36.9361 22.8503 37.8798 23.2903 39 23.3545C38.2679 23.3911 37.5976 23.602 36.9802 24.0053C36.3716 24.3995 35.8864 24.9312 35.5248 25.5913C35.172 26.2513 34.9956 26.9572 34.9956 27.7273C34.9956 26.563 34.6075 25.5546 33.8313 24.7112C33.0551 23.8587 32.1114 23.4095 31 23.3545ZM0 36.3545C1.11136 36.2995 2.05513 35.8503 2.83131 35.0069C3.6075 34.1635 3.99559 33.1642 3.99559 32C3.99559 33.1642 4.38368 34.1635 5.15987 35.0069C5.93605 35.8503 6.87982 36.2903 8 36.3545C7.26792 36.3911 6.59757 36.602 5.98015 37.0053C5.37155 37.3995 4.88644 37.9312 4.52481 38.5913C4.172 39.2513 3.99559 39.9572 3.99559 40.7273C3.99559 39.563 3.6075 38.5546 2.83131 37.7112C2.05513 36.8587 1.11136 36.4095 0 36.3545ZM56.8313 24.0069C56.0551 24.8503 55.1114 25.2995 54 25.3545C55.1114 25.4095 56.0551 25.8587 56.8313 26.7112C57.6075 27.5546 57.9956 28.563 57.9956 29.7273C57.9956 28.9572 58.172 28.2513 58.5248 27.5913C58.8864 26.9312 59.3716 26.3995 59.9802 26.0053C60.5976 25.602 61.2679 25.3911 62 25.3545C60.8798 25.2903 59.9361 24.8503 59.1599 24.0069C58.3837 23.1635 57.9956 22.1642 57.9956 21C57.9956 22.1642 57.6075 23.1635 56.8313 24.0069ZM81 25.3545C82.1114 25.2995 83.0551 24.8503 83.8313 24.0069C84.6075 23.1635 84.9956 22.1642 84.9956 21C84.9956 22.1642 85.3837 23.1635 86.1599 24.0069C86.9361 24.8503 87.8798 25.2903 89 25.3545C88.2679 25.3911 87.5976 25.602 86.9802 26.0053C86.3716 26.3995 85.8864 26.9312 85.5248 27.5913C85.172 28.2513 84.9956 28.9572 84.9956 29.7273C84.9956 28.563 84.6075 27.5546 83.8313 26.7112C83.0551 25.8587 82.1114 25.4095 81 25.3545ZM136 36.3545C137.111 36.2995 138.055 35.8503 138.831 35.0069C139.607 34.1635 139.996 33.1642 139.996 32C139.996 33.1642 140.384 34.1635 141.16 35.0069C141.936 35.8503 142.88 36.2903 144 36.3545C143.268 36.3911 142.598 36.602 141.98 37.0053C141.372 37.3995 140.886 37.9312 140.525 38.5913C140.172 39.2513 139.996 39.9572 139.996 40.7273C139.996 39.563 139.607 38.5546 138.831 37.7112C138.055 36.8587 137.111 36.4095 136 36.3545ZM101.831 49.0069C101.055 49.8503 100.111 50.2995 99 50.3545C100.111 50.4095 101.055 50.8587 101.831 51.7112C102.607 52.5546 102.996 53.563 102.996 54.7273C102.996 53.9572 103.172 53.2513 103.525 52.5913C103.886 51.9312 104.372 51.3995 104.98 51.0053C105.598 50.602 106.268 50.3911 107 50.3545C105.88 50.2903 104.936 49.8503 104.16 49.0069C103.384 48.1635 102.996 47.1642 102.996 46C102.996 47.1642 102.607 48.1635 101.831 49.0069Z" fill="currentColor"></path>
                          </svg>
                        </div>
                        <div class="theme-switch__circle-container">
                          <div class="theme-switch__sun-moon-container">
                            <div class="theme-switch__moon">
                              <div class="theme-switch__spot"></div>
                              <div class="theme-switch__spot"></div>
                              <div class="theme-switch__spot"></div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </label>

                    <!-- User Info - Profile Card Trigger -->
                    <div class="user-dropdown" onclick="toggleProfileCard(event)">
                        <div class="user-info">
                            <span>
                                <?php if ($profile_photo_url): ?>
                                <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" alt="" class="header-avatar-mini">
                                <?php else: ?>
                                <i class="fas fa-user-circle" style="font-size: 1.3rem;"></i>
                                <?php endif; ?>
                                <i class="fas fa-chevron-down" style="font-size: 0.75rem; opacity: 0.7; margin-left: 3px;"></i>
                            </span>
                        </div>
                    </div>

                    <!-- Profile Card Overlay -->
                    <div class="profile-card-overlay" id="profileCardOverlay" onclick="closeProfileCard()"></div>

                    <!-- Profile Card Panel -->
                    <div class="profile-card-panel" id="profileCardPanel">
                        <button class="profile-card-close" onclick="closeProfileCard()" aria-label="Kapat">
                            <i class="fas fa-times"></i>
                        </button>

                        <!-- Avatar & İsim -->
                        <div class="profile-card-header">
                            <div class="profile-card-avatar">
                                <?php if ($profile_photo_url): ?>
                                <img src="<?php echo htmlspecialchars($profile_photo_url); ?>" alt="<?php echo htmlspecialchars($profile_name); ?>">
                                <?php else: ?>
                                <div class="profile-card-avatar-placeholder">
                                    <i class="fas fa-user"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            <h3 class="profile-card-name"><?php echo htmlspecialchars($profile_name); ?></h3>
                            <span class="profile-card-role"><?php echo htmlspecialchars($profile_role_label); ?></span>
                        </div>

                        <!-- Kısayol Grid -->
                        <div class="profile-card-grid">
                            <a href="profile.php" class="profile-card-btn" data-color="blue">
                                <div class="profile-card-btn-icon"><i class="fas fa-user-edit"></i></div>
                                <span>Profilim</span>
                            </a>

                            <a href="messages.php" class="profile-card-btn" data-color="indigo">
                                <div class="profile-card-btn-icon"><i class="fas fa-paper-plane"></i></div>
                                <span class="profile-card-badge" id="profileMsgBadge" style="display:none;">0</span>
                                <span>Mesajlar</span>
                            </a>

                            <a href="tasks.php" class="profile-card-btn" data-color="amber">
                                <div class="profile-card-btn-icon"><i class="fas fa-clipboard-list"></i></div>
                                <span class="profile-card-badge" id="profileTaskBadge" style="display:none;">0</span>
                                <span>Görevler</span>
                            </a>

                            <?php if ($user_role !== 'owner'): ?>
                            <a href="employee_salaries.php" class="profile-card-btn" data-color="emerald">
                                <div class="profile-card-btn-icon"><i class="fas fa-coins"></i></div>
                                <span>Maaşım</span>
                            </a>
                            <?php endif; ?>

                            <?php if ($user_role === 'owner' || $user_role === 'manager'): ?>
                            <a href="settings.php" class="profile-card-btn" data-color="slate">
                                <div class="profile-card-btn-icon"><i class="fas fa-cog"></i></div>
                                <span>Ayarlar</span>
                            </a>
                            <?php endif; ?>

                            <a href="javascript:void(0)" class="profile-card-btn" data-color="purple" onclick="openHelpModal()">
                                <div class="profile-card-btn-icon"><i class="fas fa-life-ring"></i></div>
                                <span>Yardım</span>
                            </a>

                            <?php if ($user_role === 'owner'): ?>
                            <a href="javascript:void(0)" class="profile-card-btn" data-color="rose" onclick="openPasifModal()">
                                <div class="profile-card-btn-icon"><i class="fas fa-power-off"></i></div>
                                <span>İşletme Pasif</span>
                            </a>
                            <?php endif; ?>

                            <div class="rehber-menu-wrapper" style="position:relative;">
                                <a href="javascript:void(0)" class="profile-card-btn" data-color="purple" onclick="toggleRehberMenu(event)">
                                    <div class="profile-card-btn-icon"><i class="fas fa-route"></i></div>
                                    <span>Rehber</span>
                                </a>
                                <div class="rehber-submenu" id="rehberSubmenu">
                                    <button onclick="restartPageTour()" class="rehber-submenu-item">
                                        <i class="fas fa-redo"></i> Bu Sayfayı Baştan Başlat
                                    </button>
                                    <button onclick="enableAllTours()" class="rehber-submenu-item">
                                        <i class="fas fa-play-circle"></i> Tüm Turları Aç
                                    </button>
                                    <button onclick="disableAllTours()" class="rehber-submenu-item rehber-submenu-danger">
                                        <i class="fas fa-times-circle"></i> Tüm Turları Kapat
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Çıkış Yap -->
                        <a href="logout.php" class="profile-card-logout">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Çıkış Yap</span>
                        </a>
                    </div>
                </div>
            </header>

            <!-- Notification Detail Modal -->
            <div class="notif-detail-modal" id="notifDetailModal" onclick="closeNotifDetailModal(event)">
                <div class="notif-detail-modal-content" onclick="event.stopPropagation()">
                    <div class="notif-detail-modal-header">
                        <div class="notif-detail-modal-icon" id="notifDetailIcon"></div>
                        <div class="notif-detail-modal-title" id="notifDetailTitle"></div>
                        <button class="notif-detail-modal-close" onclick="closeNotifDetailModal()">&times;</button>
                    </div>
                    <div class="notif-detail-modal-body">
                        <div class="notif-detail-modal-message" id="notifDetailMessage"></div>
                        <div id="notifDetailLinkWrap" style="display:none; margin-top:16px; text-align:center;">
                            <a id="notifDetailLink" href="#" target="_blank" style="
                                display:inline-flex; align-items:center; gap:8px;
                                padding:12px 28px; border-radius:12px;
                                background:linear-gradient(135deg, #1a237e, #283593);
                                color:#fff; font-weight:700; font-size:0.9rem;
                                text-decoration:none; transition:all 0.2s;
                                box-shadow:0 4px 12px rgba(26,35,126,0.3);
                            " onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 20px rgba(26,35,126,0.4)'"
                               onmouseout="this.style.transform='';this.style.boxShadow='0 4px 12px rgba(26,35,126,0.3)'">
                                <i class="fas fa-external-link-alt"></i>
                                <span id="notifDetailLinkText">Detaylar</span>
                            </a>
                        </div>
                        <div class="notif-detail-modal-meta" id="notifDetailMeta"></div>
                    </div>
                </div>
            </div>

            <!-- Notification Confirm Modal -->
            <div class="notif-confirm-modal" id="notifConfirmModal" onclick="closeNotifConfirmModal(event)">
                <div class="notif-confirm-modal-content" onclick="event.stopPropagation()">
                    <div class="notif-confirm-modal-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="notif-confirm-modal-title" id="notifConfirmTitle">Emin misiniz?</div>
                    <div class="notif-confirm-modal-message" id="notifConfirmMessage"></div>
                    <div class="notif-confirm-modal-actions">
                        <button class="notif-confirm-btn cancel" onclick="closeNotifConfirmModal()">İptal</button>
                        <button class="notif-confirm-btn confirm" id="notifConfirmBtn">Evet</button>
                    </div>
                </div>
            </div>

            <!-- ===================== YARDIM / DESTEK MODAL ===================== -->
            <div class="help-modal" id="helpModal" onclick="closeHelpModal(event)">
                <div class="help-modal-content" onclick="event.stopPropagation()">
                    <div class="help-modal-header">
                        <div class="help-modal-header-icon"><i class="fas fa-life-ring"></i></div>
                        <div>
                            <div class="help-modal-title">Yardım & Destek</div>
                            <div class="help-modal-subtitle">Size nasıl yardımcı olabiliriz?</div>
                        </div>
                        <button class="help-modal-close" onclick="closeHelpModal()">&times;</button>
                    </div>
                    <div class="help-modal-body">
                        <form id="helpForm" onsubmit="submitHelpForm(event)">
                            <div class="help-form-group">
                                <label class="help-form-label"><i class="fas fa-tag"></i> Konu</label>
                                <select id="helpSubject" class="help-form-input" required>
                                    <option value="">Konu seçin...</option>
                                    <option value="Teknik Sorun">Teknik Sorun</option>
                                    <option value="Ödeme / Fatura">Ödeme / Fatura</option>
                                    <option value="Özellik Talebi">Özellik Talebi</option>
                                    <option value="SMS Sorunu">SMS Sorunu</option>
                                    <option value="Hesap / Giriş">Hesap / Giriş Sorunu</option>
                                    <option value="Diğer">Diğer</option>
                                </select>
                            </div>
                            <div class="help-form-row">
                                <div class="help-form-group">
                                    <label class="help-form-label"><i class="fas fa-user"></i> Ad Soyad</label>
                                    <input type="text" id="helpName" class="help-form-input" value="<?php echo htmlspecialchars($profile_name); ?>" required>
                                </div>
                                <div class="help-form-group">
                                    <label class="help-form-label"><i class="fas fa-phone"></i> Telefon</label>
                                    <input type="tel" id="helpPhone" class="help-form-input" placeholder="5XX XXX XX XX" required>
                                </div>
                            </div>
                            <div class="help-form-group">
                                <label class="help-form-label"><i class="fas fa-envelope"></i> E-posta <span style="color:#94a3b8;font-weight:400;">(opsiyonel)</span></label>
                                <input type="email" id="helpEmail" class="help-form-input" placeholder="ornek@email.com">
                            </div>
                            <div class="help-form-group">
                                <label class="help-form-label"><i class="fas fa-comment-dots"></i> Mesajınız</label>
                                <textarea id="helpMessage" class="help-form-input help-textarea" rows="4" placeholder="Sorununuzu veya talebinizi detaylı açıklayın..." required></textarea>
                                <div class="help-char-count"><span id="helpCharCount">0</span>/500</div>
                            </div>
                            <button type="submit" class="help-submit-btn" id="helpSubmitBtn">
                                <i class="fas fa-paper-plane"></i> Gönder
                            </button>
                        </form>
                        <div class="help-success-msg" id="helpSuccessMsg" style="display:none;">
                            <div class="help-success-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="help-success-title">Talebiniz Alındı!</div>
                            <div class="help-success-text">En kısa sürede sizinle iletişime geçeceğiz.</div>
                        </div>
                    </div>
                </div>
            </div>

            <style>
            /* ===================== YARDIM MODAL ===================== */
            .help-modal {
                display: none; position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(14, 22, 38, 0.55);
                z-index: 10000; align-items: center; justify-content: center;
                -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px);
                animation: helpFadeIn 0.2s ease;
            }
            .help-modal.open { display: flex; }
            @keyframes helpFadeIn { from { opacity:0; } to { opacity:1; } }
            @keyframes helpSlideUp { from { transform:translateY(20px); opacity:0; } to { transform:translateY(0); opacity:1; } }

            .help-modal-content {
                background: #fff; border-radius: 20px;
                box-shadow: 0 25px 60px rgba(0,0,0,0.15);
                max-width: 520px; width: 92%; max-height: 90vh; overflow-y: auto;
                animation: helpSlideUp 0.25s ease;
            }
            .help-modal-header {
                display: flex; align-items: center; gap: 14px;
                padding: 22px 24px 16px; border-bottom: 1px solid #f1f5f9;
                position: relative;
            }
            .help-modal-header-icon {
                width: 46px; height: 46px; border-radius: 14px;
                background: linear-gradient(135deg, #1a237e, #283593);
                display: flex; align-items: center; justify-content: center;
                font-size: 1.15rem; color: #fff; flex-shrink: 0;
            }
            .help-modal-title { font-size: 1.1rem; font-weight: 800; color: #1e293b; }
            .help-modal-subtitle { font-size: 0.8rem; color: #94a3b8; margin-top: 2px; }
            .help-modal-close {
                position: absolute; top: 16px; right: 16px;
                width: 34px; height: 34px; border-radius: 10px;
                border: none; background: #f1f5f9; color: #64748b;
                font-size: 1.3rem; cursor: pointer; display: flex;
                align-items: center; justify-content: center; transition: all 0.2s;
            }
            .help-modal-close:hover { background: #e2e8f0; color: #1e293b; }
            .help-modal-body { padding: 20px 24px 24px; }

            .help-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
            .help-form-group { margin-bottom: 14px; }
            .help-form-label {
                display: block; font-size: 0.8rem; font-weight: 600;
                color: #475569; margin-bottom: 6px;
            }
            .help-form-label i { color: #3b82f6; margin-right: 4px; font-size: 0.75rem; }
            .help-form-input {
                width: 100%; padding: 10px 14px; border-radius: 10px;
                border: 1.5px solid #e2e8f0; font-size: 0.88rem; color: #1e293b;
                background: #fff; transition: all 0.2s; outline: none;
                font-family: inherit; box-sizing: border-box;
            }
            .help-form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
            .help-form-input::placeholder { color: #cbd5e1; }
            .help-textarea { resize: vertical; min-height: 90px; max-height: 200px; }
            .help-char-count { text-align: right; font-size: 0.72rem; color: #94a3b8; margin-top: 4px; }

            .help-submit-btn {
                width: 100%; padding: 13px 24px; border: none; border-radius: 12px;
                background: linear-gradient(135deg, #1a237e, #283593);
                color: #fff; font-size: 0.95rem; font-weight: 700;
                cursor: pointer; display: flex; align-items: center;
                justify-content: center; gap: 8px; transition: all 0.2s;
                box-shadow: 0 4px 14px rgba(26,35,126,0.25);
            }
            .help-submit-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(26,35,126,0.35); }
            .help-submit-btn:active { transform: translateY(0); }
            .help-submit-btn:disabled {
                opacity: 0.6; cursor: not-allowed; transform: none !important;
                box-shadow: 0 4px 14px rgba(26,35,126,0.15) !important;
            }

            .help-success-msg { text-align: center; padding: 30px 10px; }
            .help-success-icon { font-size: 3rem; color: #10b981; margin-bottom: 14px; animation: helpBounce 0.5s ease; }
            @keyframes helpBounce { 0%{transform:scale(0)} 60%{transform:scale(1.15)} 100%{transform:scale(1)} }
            .help-success-title { font-size: 1.15rem; font-weight: 800; color: #1e293b; margin-bottom: 6px; }
            .help-success-text { font-size: 0.85rem; color: #64748b; }

            /* ===== DARK MODE ===== */
            body.dark-mode .help-modal-content { background: #1e293b; box-shadow: 0 25px 60px rgba(0,0,0,0.4); }
            body.dark-mode .help-modal-header { border-bottom-color: #334155; }
            body.dark-mode .help-modal-title { color: #f1f5f9; }
            body.dark-mode .help-modal-subtitle { color: #64748b; }
            body.dark-mode .help-modal-close { background: #334155; color: #94a3b8; }
            body.dark-mode .help-modal-close:hover { background: #475569; color: #f1f5f9; }
            body.dark-mode .help-form-label { color: #94a3b8; }
            body.dark-mode .help-form-label i { color: #60a5fa; }
            body.dark-mode .help-form-input {
                background: #0f172a; border-color: #334155; color: #f1f5f9;
            }
            body.dark-mode .help-form-input:focus { border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96,165,250,0.15); }
            body.dark-mode .help-form-input::placeholder { color: #475569; }
            body.dark-mode .help-char-count { color: #64748b; }
            body.dark-mode .help-success-title { color: #f1f5f9; }
            body.dark-mode .help-success-text { color: #94a3b8; }

            @media (max-width: 480px) {
                .help-form-row { grid-template-columns: 1fr; }
                .help-modal-content { width: 96%; border-radius: 16px; }
                .help-modal-body { padding: 16px 18px 20px; }
            }
            </style>

            <?php if ($user_role === 'owner'): ?>
            <!-- ===================== İŞLETME PASİF MODAL ===================== -->
            <div class="pasif-modal" id="pasifModal" onclick="closePasifModal(event)">
                <div class="pasif-modal-content" onclick="event.stopPropagation()">
                    <div class="pasif-modal-header">
                        <div class="pasif-modal-icon"><i class="fas fa-power-off"></i></div>
                        <div>
                            <div class="pasif-modal-title">İşletmeyi Pasif Yap</div>
                            <div class="pasif-modal-subtitle">Bu işlemi gerçekleştirmeden önce okuyun</div>
                        </div>
                        <button class="pasif-modal-close" onclick="closePasifModal()">&times;</button>
                    </div>
                    <div class="pasif-modal-body">
                        <div class="pasif-info-box">
                            <div class="pasif-info-title"><i class="fas fa-exclamation-triangle"></i> İşletme pasif yapıldığında:</div>
                            <ul class="pasif-info-list">
                                <li><i class="fas fa-eye-slash"></i> İşletmeniz dış dünyaya görünmez olur</li>
                                <li><i class="fas fa-lock"></i> Admin paneline kimse giriş yapamaz (siz dahil)</li>
                                <li><i class="fas fa-users-slash"></i> Personel ve işletme sahibi dahil tüm erişim kapanır</li>
                                <li><i class="fas fa-calendar-times"></i> Müşteriler randevu alamaz</li>
                            </ul>
                        </div>
                        <div class="pasif-warning-box">
                            <i class="fas fa-info-circle"></i>
                            Bu özelliği sadece işletmenizi artık kullanmıyorsanız veya geçici süre hizmet vermeyecekseniz kullanmanızı öneririz.
                        </div>
                        <div class="pasif-reactivate-box">
                            <i class="fas fa-redo"></i>
                            Tekrar aktif etmek için <strong>randu.tr/aktif</strong> adresinden telefon ve şifre doğrulaması yaparak işletmenizi aktif edebilirsiniz.
                        </div>
                        <div class="pasif-actions">
                            <button class="pasif-cancel-btn" onclick="closePasifModal()"><i class="fas fa-times"></i> Vazgeç</button>
                            <button class="pasif-confirm-btn" id="pasifConfirmBtn" onclick="confirmPasif()"><i class="fas fa-power-off"></i> İşletmeyi Pasif Yap</button>
                        </div>
                    </div>
                </div>
            </div>
            <style>
            .pasif-modal {
                display:none; position:fixed; top:0; left:0; right:0; bottom:0;
                background:rgba(14,22,38,0.55); z-index:10000;
                align-items:center; justify-content:center;
                -webkit-backdrop-filter:blur(4px); backdrop-filter:blur(4px);
                animation:helpFadeIn .2s ease;
            }
            .pasif-modal.open { display:flex; }
            .pasif-modal-content {
                background:#fff; border-radius:20px;
                box-shadow:0 25px 60px rgba(0,0,0,.15);
                max-width:480px; width:92%; max-height:90vh; overflow-y:auto;
                animation:helpSlideUp .25s ease;
            }
            .pasif-modal-header {
                display:flex; align-items:center; gap:14px;
                padding:22px 24px 16px; border-bottom:1px solid #f1f5f9;
                position:relative;
            }
            .pasif-modal-icon {
                width:46px; height:46px; border-radius:14px;
                background:linear-gradient(135deg,#f43f5e,#e11d48);
                display:flex; align-items:center; justify-content:center;
                font-size:1.15rem; color:#fff; flex-shrink:0;
            }
            .pasif-modal-title { font-size:1.1rem; font-weight:800; color:#1e293b; }
            .pasif-modal-subtitle { font-size:.8rem; color:#94a3b8; margin-top:2px; }
            .pasif-modal-close {
                position:absolute; top:16px; right:16px;
                width:34px; height:34px; border-radius:10px;
                border:none; background:#f1f5f9; color:#64748b;
                font-size:1.3rem; cursor:pointer; display:flex;
                align-items:center; justify-content:center; transition:all .2s;
            }
            .pasif-modal-close:hover { background:#e2e8f0; color:#1e293b; }
            .pasif-modal-body { padding:20px 24px 24px; }
            .pasif-info-box {
                background:#fef2f2; border:1px solid #fecaca; border-radius:12px;
                padding:1rem 1.25rem; margin-bottom:1rem;
            }
            .pasif-info-title {
                font-size:.85rem; font-weight:700; color:#dc2626; margin-bottom:.6rem;
                display:flex; align-items:center; gap:.4rem;
            }
            .pasif-info-list { list-style:none; padding:0; margin:0; }
            .pasif-info-list li {
                font-size:.82rem; color:#7f1d1d; padding:.35rem 0;
                display:flex; align-items:center; gap:.5rem;
            }
            .pasif-info-list li i { color:#ef4444; font-size:.75rem; width:16px; text-align:center; }
            .pasif-warning-box {
                background:#fffbeb; border:1px solid #fde68a; border-radius:10px;
                padding:.85rem 1rem; margin-bottom:.75rem;
                font-size:.8rem; color:#92400e; line-height:1.5;
                display:flex; align-items:flex-start; gap:.5rem;
            }
            .pasif-warning-box i { color:#f59e0b; margin-top:2px; flex-shrink:0; }
            .pasif-reactivate-box {
                background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;
                padding:.85rem 1rem; margin-bottom:1.25rem;
                font-size:.8rem; color:#166534; line-height:1.5;
                display:flex; align-items:flex-start; gap:.5rem;
            }
            .pasif-reactivate-box i { color:#22c55e; margin-top:2px; flex-shrink:0; }
            .pasif-reactivate-box strong { color:#15803d; }
            .pasif-actions { display:flex; gap:.75rem; justify-content:flex-end; }
            .pasif-cancel-btn {
                padding:.6rem 1.25rem; border:1px solid #e2e8f0; background:#fff;
                border-radius:10px; font-size:.85rem; font-weight:600;
                color:#64748b; cursor:pointer; transition:all .2s;
                display:flex; align-items:center; gap:.4rem;
            }
            .pasif-cancel-btn:hover { background:#f1f5f9; }
            .pasif-confirm-btn {
                padding:.6rem 1.25rem; border:none;
                background:linear-gradient(135deg,#ef4444,#dc2626);
                border-radius:10px; font-size:.85rem; font-weight:600;
                color:#fff; cursor:pointer; transition:all .2s;
                display:flex; align-items:center; gap:.4rem;
            }
            .pasif-confirm-btn:hover { opacity:.9; }
            .pasif-confirm-btn:disabled { opacity:.5; cursor:not-allowed; }
            /* Dark mode */
            body.dark-mode .pasif-modal-content { background:#1e293b; }
            body.dark-mode .pasif-modal-header { border-bottom-color:#334155; }
            body.dark-mode .pasif-modal-title { color:#f1f5f9; }
            body.dark-mode .pasif-modal-close { background:#334155; color:#94a3b8; }
            body.dark-mode .pasif-info-box { background:rgba(239,68,68,.1); border-color:rgba(239,68,68,.2); }
            body.dark-mode .pasif-info-title { color:#fca5a5; }
            body.dark-mode .pasif-info-list li { color:#fecaca; }
            body.dark-mode .pasif-warning-box { background:rgba(245,158,11,.08); border-color:rgba(245,158,11,.2); color:#fbbf24; }
            body.dark-mode .pasif-reactivate-box { background:rgba(34,197,94,.08); border-color:rgba(34,197,94,.2); color:#86efac; }
            body.dark-mode .pasif-reactivate-box strong { color:#4ade80; }
            body.dark-mode .pasif-cancel-btn { background:#334155; border-color:#475569; color:#94a3b8; }
            @media(max-width:480px) {
                .pasif-modal-content { width:96%; border-radius:16px; }
                .pasif-actions { flex-direction:column; }
                .pasif-actions button { width:100%; justify-content:center; }
            }
            </style>
            <?php endif; ?>

            <style>
            /* ===================== NOTIFICATION DETAIL MODAL ===================== */
            .notif-detail-modal, .notif-confirm-modal {
                display: none;
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(14, 22, 38, 0.55);
                z-index: 9999;
                align-items: center;
                justify-content: center;
                -webkit-backdrop-filter: blur(4px);
                backdrop-filter: blur(4px);
                animation: notifModalFadeIn 0.2s ease;
            }
            .notif-detail-modal.open, .notif-confirm-modal.open {
                display: flex;
            }
            @keyframes notifModalFadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }

            /* Detail Modal */
            .notif-detail-modal-content {
                background: #fff;
                border: 3px solid #323232;
                border-radius: 20px;
                box-shadow: 5px 5px #323232;
                max-width: 540px;
                width: 92%;
                max-height: 80vh;
                overflow-y: auto;
                animation: notifModalSlideUp 0.25s ease;
            }
            body.dark-mode .notif-detail-modal-content {
                background: #1e293b;
                border-color: #64748b;
                box-shadow: 5px 5px #475569;
            }
            @keyframes notifModalSlideUp {
                from { transform: translateY(20px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
            .notif-detail-modal-header {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 20px 24px 14px;
                border-bottom: 1px solid #f1f5f9;
            }
            body.dark-mode .notif-detail-modal-header {
                border-color: #334155;
            }
            .notif-detail-modal-icon {
                width: 44px; height: 44px; min-width: 44px;
                border-radius: 12px;
                display: flex; align-items: center; justify-content: center;
                font-size: 1rem; color: #fff;
            }
            .notif-detail-modal-title {
                flex: 1;
                font-size: 1.05rem;
                font-weight: 700;
                color: #1e293b;
                line-height: 1.3;
            }
            body.dark-mode .notif-detail-modal-title {
                color: #e2e8f0;
            }
            .notif-detail-modal-close {
                width: 36px; height: 36px;
                border: none; background: transparent;
                font-size: 1.5rem; color: #94a3b8;
                cursor: pointer; border-radius: 10px;
                display: flex; align-items: center; justify-content: center;
                transition: all 0.15s;
            }
            .notif-detail-modal-close:hover {
                background: #f1f5f9; color: #475569;
            }
            body.dark-mode .notif-detail-modal-close:hover {
                background: #334155; color: #e2e8f0;
            }
            .notif-detail-modal-body {
                padding: 18px 24px 24px;
            }
            .notif-detail-modal-message {
                font-size: 0.92rem;
                color: #475569;
                line-height: 1.7;
                white-space: pre-wrap;
                word-break: break-word;
            }
            body.dark-mode .notif-detail-modal-message {
                color: #cbd5e1;
            }
            .notif-detail-modal-meta {
                margin-top: 16px;
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 0.75rem;
                color: #94a3b8;
            }
            .notif-detail-modal-meta .notif-type-badge {
                padding: 2px 8px;
                border-radius: 6px;
                background: #f1f5f9;
                color: #475569;
                font-weight: 600;
                font-size: 0.68rem;
                text-transform: uppercase;
            }
            body.dark-mode .notif-detail-modal-meta .notif-type-badge {
                background: #334155; color: #94a3b8;
            }

            /* ===================== CONFIRM MODAL ===================== */
            .notif-confirm-modal-content {
                background: #fff;
                border: 3px solid #323232;
                border-radius: 20px;
                box-shadow: 5px 5px #323232;
                max-width: 400px;
                width: 88%;
                text-align: center;
                padding: 32px 28px 24px;
                animation: notifModalSlideUp 0.25s ease;
            }
            body.dark-mode .notif-confirm-modal-content {
                background: #1e293b;
                border-color: #64748b;
                box-shadow: 5px 5px #475569;
            }
            .notif-confirm-modal-icon {
                width: 56px; height: 56px;
                margin: 0 auto 14px;
                border-radius: 50%;
                background: #fff7ed;
                color: #f59e0b;
                display: flex; align-items: center; justify-content: center;
                font-size: 1.5rem;
            }
            body.dark-mode .notif-confirm-modal-icon {
                background: #451a03; color: #fbbf24;
            }
            .notif-confirm-modal-title {
                font-size: 1.1rem;
                font-weight: 700;
                color: #1e293b;
                margin-bottom: 8px;
            }
            body.dark-mode .notif-confirm-modal-title {
                color: #e2e8f0;
            }
            .notif-confirm-modal-message {
                font-size: 0.88rem;
                color: #64748b;
                line-height: 1.5;
                margin-bottom: 24px;
            }
            body.dark-mode .notif-confirm-modal-message {
                color: #94a3b8;
            }
            .notif-confirm-modal-actions {
                display: flex;
                gap: 10px;
                justify-content: center;
            }
            .notif-confirm-btn {
                padding: 10px 24px;
                border-radius: 12px;
                font-size: 0.88rem;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }
            .notif-confirm-btn.cancel {
                background: #fff;
                border: 2px solid #323232;
                color: #475569;
                box-shadow: 3px 3px #323232;
            }
            .notif-confirm-btn.cancel:hover {
                transform: translateY(-1px);
                box-shadow: 4px 4px #323232;
            }
            body.dark-mode .notif-confirm-btn.cancel {
                background: #1e293b;
                border-color: #64748b;
                color: #cbd5e1;
                box-shadow: 3px 3px #475569;
            }
            .notif-confirm-btn.confirm {
                background: #ef4444;
                border: 2px solid #323232;
                color: #fff;
                box-shadow: 3px 3px #323232;
            }
            .notif-confirm-btn.confirm:hover {
                transform: translateY(-1px);
                box-shadow: 4px 4px #323232;
                background: #dc2626;
            }
            body.dark-mode .notif-confirm-btn.confirm {
                background: #dc2626;
                border-color: #64748b;
                box-shadow: 3px 3px #475569;
            }
            body.dark-mode .notif-confirm-btn.confirm:hover {
                background: #b91c1c;
                box-shadow: 4px 4px #475569;
            }
            </style>

            <script>
            /* ===================== NOTIFICATION DETAIL MODAL ===================== */
            function openNotifDetailModal(n) {
                const modal = document.getElementById('notifDetailModal');
                const iconEl = document.getElementById('notifDetailIcon');
                const titleEl = document.getElementById('notifDetailTitle');
                const msgEl = document.getElementById('notifDetailMessage');
                const metaEl = document.getElementById('notifDetailMeta');
                const linkWrap = document.getElementById('notifDetailLinkWrap');
                const linkEl = document.getElementById('notifDetailLink');
                const linkTextEl = document.getElementById('notifDetailLinkText');

                const iconColor = n.icon_color || '#6366f1';
                const iconClass = n.icon || 'fa-bell';
                const catLabel = n.category === 'system' ? 'Sistem' : 'İşletme';

                iconEl.style.background = iconColor;
                iconEl.innerHTML = '<i class="fas ' + _escForModal(iconClass) + '"></i>';
                titleEl.textContent = n.title || 'Bildirim';
                msgEl.textContent = n.message || '';
                metaEl.innerHTML =
                    '<span class="notif-type-badge">' + catLabel + '</span>' +
                    '<span>' + _escForModal(n.time_ago || '') + '</span>';

                if (n.link) {
                    linkEl.href = n.link;
                    linkTextEl.textContent = n.link_text || 'Detaylar';
                    linkWrap.style.display = '';
                } else {
                    linkWrap.style.display = 'none';
                    linkEl.href = '#';
                }

                modal.classList.add('open');
                document.addEventListener('keydown', _notifModalEscHandler);
            }

            function closeNotifDetailModal(e) {
                if (e && e.target && !e.target.classList.contains('notif-detail-modal')) return;
                const modal = document.getElementById('notifDetailModal');
                modal.classList.remove('open');
                document.removeEventListener('keydown', _notifModalEscHandler);
            }

            /* ===================== CONFIRM MODAL ===================== */
            var _notifConfirmCallback = null;

            function openNotifConfirmModal(title, message, btnText, onConfirm) {
                document.getElementById('notifConfirmTitle').textContent = title;
                document.getElementById('notifConfirmMessage').textContent = message;
                const btn = document.getElementById('notifConfirmBtn');
                btn.textContent = btnText || 'Evet';
                _notifConfirmCallback = onConfirm;
                btn.onclick = function() {
                    var cb = _notifConfirmCallback;
                    closeNotifConfirmModal();
                    if (cb) cb();
                };
                document.getElementById('notifConfirmModal').classList.add('open');
                document.addEventListener('keydown', _notifConfirmEscHandler);
            }

            function closeNotifConfirmModal(e) {
                if (e && e.target && !e.target.classList.contains('notif-confirm-modal')) return;
                document.getElementById('notifConfirmModal').classList.remove('open');
                document.removeEventListener('keydown', _notifConfirmEscHandler);
                _notifConfirmCallback = null;
            }

            function _notifModalEscHandler(e) { if (e.key === 'Escape') closeNotifDetailModal(); }
            function _notifConfirmEscHandler(e) { if (e.key === 'Escape') closeNotifConfirmModal(); }

            function _escForModal(str) {
                if (!str) return '';
                var d = document.createElement('div');
                d.textContent = str;
                return d.innerHTML;
            }

            // Badge count'lar\u0131n\u0131 async y\u00fckle (sayfa render'dan sonra DB sorgusu at\u0131l\u0131r)
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    fetch('ajax/get_badge_counts.php', { credentials: 'same-origin' })
                        .then(function(r) { return r.ok ? r.json() : null; })
                        .then(function(data) {
                            if (!data) return;
                            // Bildirim zili
                            if (data.notifications > 0) {
                                var bellBadge = document.getElementById('notifBellBadge');
                                var bellPulse = document.querySelector('.notif-bell-pulse');
                                if (bellBadge) { bellBadge.textContent = data.notifications > 99 ? '99+' : data.notifications; bellBadge.style.display = ''; }
                                if (bellPulse) bellPulse.style.display = '';
                            }
                            // Profil kart\u0131 \u2014 mesajlar
                            if (data.messages > 0) {
                                var msgBadge = document.getElementById('profileMsgBadge');
                                if (msgBadge) { msgBadge.textContent = data.messages; msgBadge.style.display = ''; }
                            }
                            // Profil kart\u0131 \u2014 g\u00f6revler
                            if (data.tasks > 0) {
                                var taskBadge = document.getElementById('profileTaskBadge');
                                if (taskBadge) { taskBadge.textContent = data.tasks; taskBadge.style.display = ''; }
                            }
                        })
                        .catch(function() {}); // Hata durumunda sessizce ge\u00e7
                }, 800); // Sayfa render tamam olduktan sonra
            });
            </script>

            <main class="admin-main">
                <?php
                    if (isset($_SESSION['error_message'])) {
                        echo '<div class="alert alert-danger" style="margin-bottom: 1.5rem;"><i class="fas fa-exclamation-circle"></i> ' . $_SESSION['error_message'] . '</div>';
                        unset($_SESSION['error_message']);
                    }
                ?>

<script>
// SAYFA YÜKLENMEDEN ÖNCE SIDEBAR'I KAPAT (animasyon olmasın)
(function() {
    const sidebar = document.getElementById('adminSidebar');
    const adminContent = document.getElementById('adminContent');
    
    if (sidebar && adminContent && window.innerWidth > 1024) {
        const savedPinned = localStorage.getItem('sidebarPinned');
        if (savedPinned !== 'true') {
            sidebar.style.transition = 'none';
            adminContent.style.transition = 'none';
            sidebar.classList.add('collapsed');
            adminContent.classList.add('sidebar-collapsed');
            
            // Transition'ı kısa süre sonra geri ekle
            setTimeout(() => {
                sidebar.style.transition = '';
                adminContent.style.transition = '';
            }, 50);
        }
    }
})();

// ============================================
// PROFILE CARD PANEL
// ============================================
function toggleProfileCard(e) {
    e.stopPropagation();
    var panel = document.getElementById('profileCardPanel');
    var overlay = document.getElementById('profileCardOverlay');
    if (panel.classList.contains('open')) {
        closeProfileCard();
    } else {
        panel.classList.add('open');
        overlay.classList.add('open');
    }
}
function closeProfileCard() {
    var panel = document.getElementById('profileCardPanel');
    var overlay = document.getElementById('profileCardOverlay');
    if (panel) panel.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
}

function openHelpModal() {
    closeProfileCard();
    var modal = document.getElementById('helpModal');
    var form = document.getElementById('helpForm');
    var success = document.getElementById('helpSuccessMsg');
    form.style.display = ''; success.style.display = 'none';
    document.getElementById('helpSubmitBtn').disabled = false;
    document.getElementById('helpSubmitBtn').innerHTML = '<i class="fas fa-paper-plane"></i> Gönder';
    modal.classList.add('open');
}
function closeHelpModal(e) {
    if (e && e.target !== e.currentTarget) return;
    document.getElementById('helpModal').classList.remove('open');
}

// ═══════════════════════════════════════════
// İŞLETME PASİF MODAL
// ═══════════════════════════════════════════
function openPasifModal() {
    closeProfileCard();
    document.getElementById('pasifModal').classList.add('open');
    var btn = document.getElementById('pasifConfirmBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-power-off"></i> İşletmeyi Pasif Yap';
}
function closePasifModal(e) {
    if (e && e.target !== e.currentTarget) return;
    document.getElementById('pasifModal').classList.remove('open');
}
function confirmPasif() {
    var btn = document.getElementById('pasifConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> İşlem yapılıyor...';

    var fd = new FormData();
    fd.append('action', 'toggle');
    fd.append('status', '0');
    fd.append('csrf_token', '<?php echo Csrf::getToken(); ?>');

    fetch('ajax/toggle_business.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                closePasifModal();
                document.body.innerHTML = '<div style="position:fixed;inset:0;background:#0f172a;display:flex;align-items:center;justify-content:center;z-index:99999;padding:1.5rem"><div style="background:#1e293b;padding:2.5rem 2rem;border-radius:20px;max-width:440px;width:100%;text-align:center;box-shadow:0 25px 50px rgba(0,0,0,.3)"><div style="font-size:3.5rem;margin-bottom:1rem">🔒</div><h1 style="font-size:1.4rem;font-weight:800;color:#f1f5f9;margin-bottom:.5rem">İşletmeniz Pasif Edildi</h1><p style="font-size:.95rem;color:#94a3b8;margin-bottom:1.5rem;line-height:1.5">İşletmeniz askıya alınmıştır. Tekrar aktif etmek için:</p><p style="font-size:.9rem;color:#a5b4fc;font-weight:600;margin-bottom:1.5rem">randu.tr/aktif</p><a href="https://randu.tr" style="display:inline-block;padding:.75rem 2rem;background:#6366f1;color:#fff;text-decoration:none;border-radius:10px;font-weight:600;font-size:.9rem">Ana Sayfaya Dön</a></div></div>';
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-power-off"></i> İşletmeyi Pasif Yap';
                if (typeof showToast === 'function') {
                    showToast(d.error || 'Bir hata oluştu', 'error');
                } else {
                    alert(d.error || 'Bir hata oluştu');
                }
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-power-off"></i> İşletmeyi Pasif Yap';
            alert('Bağlantı hatası');
        });
}
(function() {
    var ta = document.getElementById('helpMessage');
    var cc = document.getElementById('helpCharCount');
    if (ta && cc) {
        ta.addEventListener('input', function() {
            if (this.value.length > 500) this.value = this.value.substring(0, 500);
            cc.textContent = this.value.length;
        });
    }
})();
function submitHelpForm(e) {
    e.preventDefault();
    var btn = document.getElementById('helpSubmitBtn');
    var name = document.getElementById('helpName').value.trim();
    var phone = document.getElementById('helpPhone').value.trim();
    var email = document.getElementById('helpEmail').value.trim();
    var subject = document.getElementById('helpSubject').value;
    var message = document.getElementById('helpMessage').value.trim();
    if (!name || !phone || !subject || !message) return;

    // Ad Soyad ayır
    var parts = name.split(' ');
    var firstName = parts[0] || '';
    var surname = parts.slice(1).join(' ') || '-';

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gönderiliyor...';

    fetch('/api/contact_request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            name: firstName,
            surname: surname,
            phone: phone,
            email: email || null,
            message: '[' + subject + '] ' + message,
            type: 'admin_form'
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            document.getElementById('helpForm').style.display = 'none';
            document.getElementById('helpSuccessMsg').style.display = '';
            setTimeout(function() { closeHelpModal(); }, 3000);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Gönder';
            alert('Hata: ' + (data.message || 'Bir sorun oluştu'));
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Gönder';
        alert('Bağlantı hatası. Lütfen tekrar deneyin.');
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Dropdown menü toggle
    document.querySelectorAll('.menu-dropdown-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            var dropdown = this.closest('.menu-dropdown');
            dropdown.classList.toggle('open');
        });
    });

    const mobileToggle = document.getElementById('mobileMenuToggle');
    const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const adminContent = document.getElementById('adminContent');

    let hoverTimeout;
    let isPinned = false;

    // LocalStorage'dan sabitleme durumunu al
    const savedPinnedState = localStorage.getItem('sidebarPinned');
    if (savedPinnedState === 'true' && window.innerWidth > 1024) {
        isPinned = true;
        sidebar.classList.remove('collapsed');
        adminContent.classList.remove('sidebar-collapsed');
    }

    function updateToggleButtonIcon() {
        if (!sidebarToggleBtn) return;
        const tooltipText = isPinned ? 'Sabitlemeyi Kaldır' : 'Sabitle';
        
        // Tooltip'i güncelle
        let tooltip = sidebarToggleBtn.querySelector('.custom-tooltip');
        if (tooltip) {
            tooltip.textContent = tooltipText;
        }
        
        const icon = sidebarToggleBtn.querySelector('i');
        if (icon) {
            icon.className = isPinned ? 'fas fa-thumbtack' : 'fas fa-thumbtack fa-rotate-90';
        }
    }

    // Özel tooltip oluştur
    function createTooltip() {
        if (!sidebarToggleBtn) return;
        
        // Tooltip element'i oluştur
        const tooltip = document.createElement('span');
        tooltip.className = 'custom-tooltip';
        tooltip.textContent = isPinned ? 'Sabitlemeyi Kaldır' : 'Sabitle';
        
        // Tooltip stillerini ekle
        Object.assign(tooltip.style, {
            position: 'absolute',
            right: '45px',
            top: '50%',
            transform: 'translateY(-50%)',
            backgroundColor: 'rgba(0, 0, 0, 0.9)',
            color: '#ffffff',
            padding: '6px 12px',
            borderRadius: '6px',
            fontSize: '13px',
            fontWeight: '500',
            whiteSpace: 'nowrap',
            pointerEvents: 'none',
            opacity: '0',
            transition: 'opacity 0.15s ease',
            zIndex: '10000',
            boxShadow: '0 2px 8px rgba(0,0,0,0.2)'
        });
        
        // Ok işareti ekle (sağda)
        const arrow = document.createElement('span');
        Object.assign(arrow.style, {
            position: 'absolute',
            right: '-4px',
            top: '50%',
            transform: 'translateY(-50%)',
            width: '0',
            height: '0',
            borderTop: '5px solid transparent',
            borderBottom: '5px solid transparent',
            borderLeft: '5px solid rgba(0, 0, 0, 0.9)'
        });
        
        tooltip.appendChild(arrow);
        sidebarToggleBtn.appendChild(tooltip);
        
        // Hover olayları
        sidebarToggleBtn.addEventListener('mouseenter', function() {
            tooltip.style.opacity = '1';
        });
        
        sidebarToggleBtn.addEventListener('mouseleave', function() {
            tooltip.style.opacity = '0';
        });
    }
    
    createTooltip();

    updateToggleButtonIcon();

    // Sabitleme butonu
    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            if (window.innerWidth > 1024) {
                isPinned = !isPinned;
                
                if (isPinned) {
                    sidebar.classList.remove('collapsed');
                    adminContent.classList.remove('sidebar-collapsed');
                } else {
                    sidebar.classList.add('collapsed');
                    adminContent.classList.add('sidebar-collapsed');
                }
                
                localStorage.setItem('sidebarPinned', isPinned);
                updateToggleButtonIcon();
            }
        });
    }

    // Hover olayları - Sadece pin edilmemişse çalışır
    if (sidebar) {
        sidebar.addEventListener('mouseenter', function() {
            if (window.innerWidth > 1024 && !isPinned) {
                clearTimeout(hoverTimeout);
                sidebar.classList.remove('collapsed');
                adminContent.classList.remove('sidebar-collapsed');
            }
        });

        sidebar.addEventListener('mouseleave', function() {
            if (window.innerWidth > 1024 && !isPinned) {
                clearTimeout(hoverTimeout);
                hoverTimeout = setTimeout(function() {
                    if (!isPinned) {
                        sidebar.classList.add('collapsed');
                        adminContent.classList.add('sidebar-collapsed');
                    }
                }, 50);
            }
        });
    }

    // Mobil menü toggle
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (window.innerWidth <= 1024) {
                const isOpen = sidebar.classList.contains('open');
                if (isOpen) {
                    closeSidebarMobile();
                } else {
                    openSidebarMobile();
                }
            }
        });
    }

    // Overlay tıklama
    if (overlay) {
        overlay.addEventListener('click', closeSidebarMobile);
    }

    // Menü öğelerine tıklama (mobil) - dropdown toggle'ları hariç
    const menuItems = document.querySelectorAll('.menu-item:not(.menu-dropdown-toggle)');
    menuItems.forEach(function(item) {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 1024) {
                setTimeout(closeSidebarMobile, 150);
            }
        });
    });

    // Ekran boyutu değişikliği
    window.addEventListener('resize', function() {
        if (window.innerWidth > 1024) {
            closeSidebarMobile();
            
            const savedPinnedState = localStorage.getItem('sidebarPinned');
            if (savedPinnedState === 'true') {
                isPinned = true;
                sidebar.classList.remove('collapsed');
                adminContent.classList.remove('sidebar-collapsed');
            } else {
                isPinned = false;
                sidebar.classList.add('collapsed');
                adminContent.classList.add('sidebar-collapsed');
            }
            updateToggleButtonIcon();
        } else {
            // Mobilde her zaman açık
            sidebar.classList.remove('collapsed');
            adminContent.classList.remove('sidebar-collapsed');
            isPinned = false;
        }
    });

    // ESC tuşu ile kapatma (mobil)
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && window.innerWidth <= 1024 && sidebar.classList.contains('open')) {
            closeSidebarMobile();
        }
    });

    // ==================== TELEFON NUMARASI FORMATTING ====================
    // Telefon input'larında otomatik formatting (546 578 46 95 şeklinde göster)
    function formatPhoneInput(value) {
        // Sadece rakamları al
        let numbers = value.replace(/\D/g, '');
        
        // Maksimum 10 hane (0 olmadan)
        if (numbers.length > 10) {
            numbers = numbers.substring(0, 10);
        }
        
        // 546 578 46 95 formatı
        if (numbers.length <= 3) {
            return numbers;
        } else if (numbers.length <= 6) {
            return numbers.substring(0, 3) + ' ' + numbers.substring(3);
        } else if (numbers.length <= 8) {
            return numbers.substring(0, 3) + ' ' + numbers.substring(3, 6) + ' ' + numbers.substring(6);
        } else {
            return numbers.substring(0, 3) + ' ' + numbers.substring(3, 6) + ' ' + numbers.substring(6, 8) + ' ' + numbers.substring(8);
        }
    }

    // Tüm telefon input'larına event listener ekle
    document.querySelectorAll('input[type="tel"], input[type="text"][name*="phone"], input[name="phone"], input[name="customer_phone"]').forEach(function(input) {
        // Input event'i - real-time formatting
        input.addEventListener('input', function(e) {
            // Maksimum 11 karaktere (0 dahil)
            let value = e.target.value;
            
            // Sadece rakamları ve boşlukları tut
            value = value.replace(/[^\d\s]/g, '');
            
            // 0 ile başlıyorsa ve 11 karakterden uzunsa kes
            if (value.startsWith('0') && value.replace(/\s/g, '').length > 11) {
                value = value.substring(0, value.replace(/\s/g, '').length - 1);
            }
            
            // Ön sıfırı kaldır formatting için
            if (value.startsWith('0')) {
                value = value.substring(1);
            }
            
            // Format uygula
            let formatted = formatPhoneInput(value);
            e.target.value = formatted;
        });

        // Blur event'i - son validasyon
        input.addEventListener('blur', function(e) {
            let value = e.target.value.replace(/\D/g, ''); // Sadece rakamlar
            
            // Eğer 0 ile başlamıyorsa başına ekle (ön sıfırı tamamla)
            if (value.length > 0 && !value.startsWith('0')) {
                value = '0' + value;
            }
            
            // Formatting
            if (value.startsWith('0')) {
                value = value.substring(1);
            }
            let formatted = formatPhoneInput(value);
            e.target.value = formatted;
        });
    });


    // Mobil fonksiyonlar
    let _sidebarScrollY = 0;
    let _sidebarTouchStartX = 0;
    let _sidebarTouchStartY = 0;

    function openSidebarMobile() {
        if (!sidebar) return;
        _sidebarScrollY = window.scrollY || window.pageYOffset || 0;
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('show');
        if (mobileToggle) mobileToggle.classList.add('active');
        document.body.classList.add('sidebar-open-lock');
        document.body.style.top = '-' + _sidebarScrollY + 'px';
        document.body.style.overflow = 'hidden';
    }

    function closeSidebarMobile() {
        if (!sidebar) return;
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('show');
        if (mobileToggle) mobileToggle.classList.remove('active');
        document.body.classList.remove('sidebar-open-lock');
        document.body.style.top = '';
        document.body.style.overflow = '';
        window.scrollTo(0, _sidebarScrollY || 0);
    }

    // iOS Safari: sidebar açıkken yatay sürüklemeyi engelle (menü sayfadan kaymasın)
    if (sidebar) {
        sidebar.addEventListener('touchstart', function(e) {
            if (!e.touches || !e.touches[0]) return;
            _sidebarTouchStartX = e.touches[0].clientX;
            _sidebarTouchStartY = e.touches[0].clientY;
        }, { passive: true });

        sidebar.addEventListener('touchmove', function(e) {
            if (!sidebar.classList.contains('open') || !e.touches || !e.touches[0]) return;
            const dx = e.touches[0].clientX - _sidebarTouchStartX;
            const dy = e.touches[0].clientY - _sidebarTouchStartY;
            if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) {
                e.preventDefault();
            }
        }, { passive: false });
    }

    // Sayfanın yatay lastik kaydırmasını kes (iOS)
    document.addEventListener('touchmove', function(e) {
        if (!document.body.classList.contains('sidebar-open-lock')) return;
        // Overlay üzerinde tamamen kilitle
        if (overlay && e.target === overlay) {
            e.preventDefault();
        }
    }, { passive: false });
});
</script>

<!-- GLOBAL NOTIFICATION SYSTEM -->
<?php if (!isset($current_page) || ($current_page !== 'daily_view.php' && $current_page !== 'servis_kabulleri.php')): ?>
<script src="assets/js/notification.js?v=<?php echo filemtime($js_dir . 'notification.js') ?: $asset_version; ?>"></script>
<?php endif; ?>

<!-- ONBOARDING TOUR SYSTEM -->
<script src="assets/js/tour.js?v=<?php echo filemtime($js_dir . 'tour.js') ?: $asset_version; ?>"></script>
<script src="assets/js/tour-definitions.js?v=<?php echo filemtime($js_dir . 'tour-definitions.js') ?: $asset_version; ?>"></script>
<script>
function toggleRehberMenu(e) {
    e.stopPropagation();
    e.preventDefault();
    const menu = document.getElementById('rehberSubmenu');
    menu.classList.toggle('open');
    // Dışa tıkla kapa
    if (menu.classList.contains('open')) {
        setTimeout(() => {
            document.addEventListener('click', closeRehberMenu);
        }, 10);
    }
}
function closeRehberMenu() {
    const menu = document.getElementById('rehberSubmenu');
    if (menu) menu.classList.remove('open');
    document.removeEventListener('click', closeRehberMenu);
}

function enableAllTours() {
    closeRehberMenu();
    closeProfileCard();
    localStorage.removeItem('tours_disabled');
    // Tüm turları sıfırla
    fetch('ajax/tour.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reset_all' })
    }).then(() => {
        if (typeof showSuccess === 'function') showSuccess('Turlar tekrar açıldı! Sayfa yenilenince başlayacak.', 4000);
        else if (typeof showToast === 'function') showToast('Turlar tekrar açıldı!', 'success');
        else alert('Turlar tekrar açıldı!');
    }).catch(() => {});
}

function restartPageTour() {
    closeRehberMenu();
    closeProfileCard();
    localStorage.removeItem('tours_disabled');
    const path = window.location.pathname;
    const page = path.split('/').pop().replace('.php', '');
    const tourMap = {
        'services': 'services', 'employees': 'employees', 'appointments': 'appointments',
        'customers': 'customers', 'daily_view': 'daily_view', 'settings': 'settings',
        'revenues': 'revenues', 'expenses': 'expenses', 'gallery': 'gallery',
        'bekleyen_odemeler': 'bekleyen_odemeler', 'employee_salaries': 'employee_salaries',
        'tasks': 'tasks', 'reviews': 'reviews',
        'index': 'sidebar'
    };
    const tourKey = tourMap[page];
    if (tourKey && TourDefinitions[tourKey]) {
        RanduTour.resetTour('tour_' + tourKey);
        if (page === 'settings') {
            ['genel','hakkimizda','iletisim','bankalar','randevu','tema','sistem'].forEach(t => {
                RanduTour.resetTour('tour_settings_' + t);
            });
        }
        ['modal_employee','modal_appointment','modal_service','modal_customer'].forEach(t => {
            RanduTour.resetTour('tour_' + t);
        });
        ['dropdown_revenues','dropdown_employees','dropdown_customers'].forEach(t => {
            RanduTour.resetTour('tour_' + t);
        });
        setTimeout(() => RanduTour.start('tour_' + tourKey, TourDefinitions[tourKey]), 500);
    }
}

function disableAllTours() {
    closeRehberMenu();
    closeProfileCard();
    // localStorage flag
    localStorage.setItem('tours_disabled', '1');
    // Tüm turları tamamlandı olarak işaretle (tek istekle)
    const allTours = [
        'tour_services','tour_employees','tour_appointments','tour_customers',
        'tour_daily_view','tour_settings','tour_revenues','tour_expenses',
        'tour_gallery','tour_bekleyen_odemeler','tour_employee_salaries',
        'tour_tasks','tour_reviews','tour_sidebar',
        'tour_settings_genel','tour_settings_hakkimizda','tour_settings_iletisim',
        'tour_settings_bankalar','tour_settings_randevu','tour_settings_tema','tour_settings_sistem',
        'tour_modal_employee','tour_modal_appointment','tour_modal_service','tour_modal_customer',
        'tour_dropdown_revenues','tour_dropdown_employees','tour_dropdown_customers'
    ];
    fetch('ajax/tour.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'complete_all', tour: 'bulk', tours: allTours })
    }).catch(() => {});
    // Aktif tur varsa kapat
    if (typeof RanduTour !== 'undefined' && RanduTour.endTour) {
        RanduTour.endTour();
    }
    // Bildirim göster
    if (typeof showToast === 'function') {
        showToast('Rehber turları kapatıldı', 'success');
    } else {
        alert('Rehber turları kapatıldı.');
    }
}
</script>

<script src="/assets/js/randu-engage.js?v=9"></script>
<script>
window.RANDU_SMS_GATE = <?php
    $__smsGate = function_exists('getTenantSmsCapability') ? getTenantSmsCapability() : [
        'can_send' => false,
        'send_on_create' => true,
        'warning' => 'SMS ayarları kontrol edilemedi.',
        'send_on_create_warning' => null,
        'settings_url' => '/admin/settings/randevu.php',
    ];
    echo json_encode([
        'can_send' => !empty($__smsGate['can_send']),
        'send_on_create' => !empty($__smsGate['send_on_create']),
        'warning' => $__smsGate['warning'] ?? null,
        'send_on_create_warning' => $__smsGate['send_on_create_warning'] ?? null,
        'settings_url' => $__smsGate['settings_url'] ?? '/admin/settings/randevu.php',
        'notifications_enabled' => !empty($__smsGate['notifications_enabled']),
    ], JSON_UNESCAPED_UNICODE);
?>;
window.notifySmsOutcome = function(result) {
    if (!result) return;
    var msg = result.sms_warning || (result.data && result.data.sms_warning) || null;
    if (!msg) return;
    if (typeof showToast === 'function') showToast(msg, 'warning');
    else if (typeof _notify === 'function') _notify(msg, 'warning', 7000);
    else if (typeof showAlert === 'function') showAlert('warning', msg, 7000);
    else if (typeof window.showNotification === 'function') window.showNotification(msg, 'warning');
    else if (typeof window.showWarning === 'function') window.showWarning(msg);
};
window.warnSmsIfUnavailable = function(checked) {
    if (!checked) return;
    var g = window.RANDU_SMS_GATE || {};
    var msg = null;
    if (!g.can_send) msg = g.warning || 'SMS bildirimleri kapalı. Ayarlar → Randevu ayarlarından SMS\'i aktif edin.';
    else if (g.send_on_create === false) msg = g.send_on_create_warning || 'Randevu oluşturma SMS\'i ayarlardan kapalı.';
    if (msg) window.notifySmsOutcome({ sms_warning: msg });
};
</script>
<script>
RanduEngage.init({
    panel: 'admin',
    vapidPublicKey: <?php echo json_encode(defined('VAPID_PUBLIC_KEY') ? VAPID_PUBLIC_KEY : ''); ?>,
    subscribeUrl: '/admin/api/push/subscribe.php',
    swUrl: '/admin/sw.js?v=9',
    swScope: '/admin/',
    appName: 'RANDU İşletmem',
    installTitle: 'İşletme uygulamasını yükle',
    installText: 'Ana ekranına ekle; randevu ve müşteri paneline tek dokunuşla gir.',
    pushTitle: 'Anlık bildirimleri aç',
    pushText: 'Yeni randevu ve kritik güncellemeleri kaçırma. İzni reddedersen zil menüsünden tekrar açabilirsin.',
    iconUrl: '/assets/uploads/pwa/icon-192.png',
    badgeUrl: '/assets/uploads/pwa/notification-badge.png',
    logoUrl: '/assets/uploads/logo.png',
    delayMs: 1400
});
</script>

<?php echo getDarkModeToggleHtml(); ?>
</body>
</html>