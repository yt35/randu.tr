<?php
// ==========================================================================
// 🛠️ RANDU.TR - MERKEZI SMS YÖNETİM SİSTEMİ (CONFIG.PHP)
// ==========================================================================
// Tüm işletmeler TEK bir Master SMS API kullanır.
// Limitler tamamen admin tarafından yönetilir.
// ==========================================================================

// 0. ENVIRONMENT LOADER (En başta çalışmalı)
require_once __DIR__ . '/../includes/force_canonical_host.php';
require_once __DIR__ . '/EnvLoader.php';

// Debug modu .env'den kontrol — production'da kapalı olmalı
$_app_debug = EnvLoader::get('APP_DEBUG', 'false') === 'true';
if ($_app_debug) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
}

require_once __DIR__ . '/Logger.php';

require_once __DIR__ . '/Csrf.php';

// Mailer - E-posta gönderimi için
require_once __DIR__ . '/Mailer.php';

require_once __DIR__ . '/location_functions.php';

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/telegram.php';

require_once __DIR__ . '/RateLimiter.php';

// 1. OTURUM BAŞLATMA
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.cookie_domain', EnvLoader::get('SESSION_COOKIE_DOMAIN', '.randu.tr'));
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// 1.5. GÜVENLİK HEADER'LARI (tüm sayfalar için)
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (!$_app_debug) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// 2. VERİTABANI BAĞLANTISI
require_once 'database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db === null) {
        throw new Exception("Database connection returned null");
    }
    
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // SET NAMES database.php'de MYSQL_ATTR_INIT_COMMAND ile zaten ayarlanıyor
} catch (Exception $e) {
    error_log("DB CONNECTION FATAL [" . date('Y-m-d H:i:s') . "]: " . $e->getMessage());
    
    $is_ajax = (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ||
        (strpos($_SERVER['REQUEST_URI'], '/ajax/') !== false) ||
        (strpos($_SERVER['REQUEST_URI'], '/api/') !== false)
    );
    
    if ($is_ajax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'database_error',
            'message' => 'Veritabanı bağlantı hatası',
            'code' => 'DB_CONN_FAIL'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        http_response_code(500);
        $errorMsg = "<div style='font-family:sans-serif;text-align:center;padding:50px;background:#f5f5f5;min-height:100vh'>";
        $errorMsg .= "<div style='background:#fff;padding:40px;border-radius:10px;box-shadow:0 5px 20px rgba(0,0,0,.1);max-width:600px;margin:0 auto'>";
        $errorMsg .= "<h1 style='color:#d9534f'>⚠️ Veritabanı Bağlantı Hatası</h1>";
        $errorMsg .= "<p>Şu anda sisteme erişilemiyor.</p>";
        $errorMsg .= "<p><b>Kontrol edilecekler:</b></p>";
        $errorMsg .= "<ul style='text-align:left'>";
        $errorMsg .= "<li>.env dosyası var mı?</li>";
        $errorMsg .= "<li>DB_PASSWORD doğru mu?</li>";
        $errorMsg .= "<li>MySQL servisi çalışıyor mu?</li>";
        $errorMsg .= "</ul>";
        $errorMsg .= "</div></div>";
        die($errorMsg);
    }
}

// ==========================================================================
// 🔐 MASTER SMS API BİLGİLERİ (TEK HESAP - TÜM SİSTEM)
// ==========================================================================
// .env dosyasından credentials'ı oku
// Değerleri .env'de tanımla (production güvenliği için)

if (!defined('MASTER_SMS_API_ID')) define('MASTER_SMS_API_ID', EnvLoader::get('SMS_API_ID', ''));
if (!defined('MASTER_SMS_API_KEY')) define('MASTER_SMS_API_KEY', EnvLoader::get('SMS_API_KEY', ''));
if (!defined('MASTER_SMS_SENDER')) define('MASTER_SMS_SENDER', EnvLoader::get('SMS_SENDER_NAME', 'RANDUTR'));
if (!defined('SMS_SYSTEM_ENABLED')) define('SMS_SYSTEM_ENABLED', EnvLoader::get('SMS_SYSTEM_ENABLED', 'true') === 'true');

// ==========================================================================
// 🔔 WEB PUSH (VAPID) — native, OneSignal yok
// ==========================================================================
$_vapid_local = [];
$_vapid_file = __DIR__ . '/vapid.local.php';
if (is_readable($_vapid_file)) {
    $_vapid_local = require $_vapid_file;
    if (!is_array($_vapid_local)) $_vapid_local = [];
}
if (!defined('VAPID_PUBLIC_KEY')) define('VAPID_PUBLIC_KEY', EnvLoader::get('VAPID_PUBLIC_KEY', $_vapid_local['public'] ?? ''));
if (!defined('VAPID_PRIVATE_KEY')) define('VAPID_PRIVATE_KEY', EnvLoader::get('VAPID_PRIVATE_KEY', $_vapid_local['private'] ?? ''));
if (!defined('VAPID_SUBJECT')) define('VAPID_SUBJECT', EnvLoader::get('VAPID_SUBJECT', $_vapid_local['subject'] ?? 'mailto:destek@randu.tr'));
unset($_vapid_local, $_vapid_file);
require_once __DIR__ . '/WebPush.php';
if (!defined('OCR_SPACE_API_KEY')) define('OCR_SPACE_API_KEY', EnvLoader::get('OCR_SPACE_API_KEY', ''));
if (!defined('OCR_SPACE_APIKEY')) define('OCR_SPACE_APIKEY', EnvLoader::get('OCR_SPACE_APIKEY', EnvLoader::get('OCR_SPACE_API_KEY', '')));
if (!defined('BASE_URL')) define('BASE_URL', EnvLoader::get('BASE_URL', 'https://randu.tr'));
if (!defined('BADGE_ICON_PATH')) define('BADGE_ICON_PATH', EnvLoader::get('BADGE_ICON_PATH', '/assets/uploads/badge.png'));
// Yeni kayıtlarda ücretsiz deneme süresi (gün). Mevcut tenant package_expiry değerleri değiştirilmez.
if (!defined('RANDU_TRIAL_DAYS')) define('RANDU_TRIAL_DAYS', (int) EnvLoader::get('TRIAL_DAYS', 7));
// Deneme hesapları: paket fark etmeksizin SMS tavanı (kötüye kullanım sınırı)
if (!defined('RANDU_TRIAL_SMS_LIMIT')) define('RANDU_TRIAL_SMS_LIMIT', (int) EnvLoader::get('TRIAL_SMS_LIMIT', 100));

// ==========================================================================
// 🚀 3. SAAS (ÇOKLU İŞLETME) MANTIĞI - KİMLİK TESPİTİ
// ==========================================================================

$host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\\d+$/', '', $host);
$hostParts = explode('.', $host);
$subdomain = $hostParts[0];

// BURAYA 'app' EKLENDİ - ARTIK SİSTEM MOBİL GİRİŞİ TANIYACAK
$ana_domainler = ['www', 'randu', 'localhost', '127', 'randevu', 'panel', 'app']; 

if (in_array($subdomain, $ana_domainler)) {
    // ANA YÖNETİM PANELİ VEYA APP GİRİŞİ
    if (isset($_SESSION['tenant_id']) && !empty($_SESSION['tenant_id'])) {
        if (!defined('TENANT_ID')) define('TENANT_ID', $_SESSION['tenant_id']);
        
        $stmt = $db->prepare("
            SELECT t.*, p.name as paket_adi, p.limit_employees, p.limit_services, p.limit_sms, p.feature_whatsapp 
            FROM tenants t
            LEFT JOIN packages p ON t.package_id = p.id
            WHERE t.id = ?
        ");
        $stmt->execute([$_SESSION['tenant_id']]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tenant) {
            // Pasif tenant kontrolü (session üzerinden giriş yapılmış olabilir)
            // SADECE /admin/ yollarını engelle — ana domain sayfalarını (landing, aktif, vs.) engelleme
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $is_admin_path = (strpos($request_uri, '/admin') === 0 || strpos($request_uri, '/admin/') !== false);
            
            if ((int)$tenant['is_active'] === 0 && $is_admin_path) {
                $is_toggle_ajax = (strpos($request_uri, 'toggle_business.php') !== false);
                $is_logout = (strpos($request_uri, 'logout.php') !== false);
                
                if ($is_toggle_ajax || $is_logout) {
                    // Toggle endpoint ve logout'a izin ver
                    if (!defined('TENANT_DATA')) define('TENANT_DATA', $tenant);
                    if (!defined('TENANT_CATEGORY')) define('TENANT_CATEGORY', trim(explode(',', $tenant['kategori'] ?? '')[0]));
                    if (!defined('LIMIT_EMPLOYEES')) define('LIMIT_EMPLOYEES', intval($tenant['limit_employees']));
                    if (!defined('LIMIT_SERVICES')) define('LIMIT_SERVICES', intval($tenant['limit_services']));
                    if (!defined('LIMIT_SMS')) define('LIMIT_SMS', intval($tenant['limit_sms']));
                    if (!defined('FEATURE_WHATSAPP')) define('FEATURE_WHATSAPP', intval($tenant['feature_whatsapp']));
                    $site_name_default = $tenant['firma_adi'];
                } else {
                    // Admin sayfaları — işletme kapalı mesajı
                    $is_ajax = (
                        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ||
                        (strpos($request_uri, '/ajax/') !== false) ||
                        (strpos($request_uri, '/api/') !== false)
                    );
                    
                    if ($is_ajax) {
                        http_response_code(503);
                        header('Content-Type: application/json; charset=utf-8');
                        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                        header('X-LiteSpeed-Cache-Control: no-cache');
                        echo json_encode([
                            'success' => false,
                            'error' => 'business_inactive',
                            'message' => 'Bu işletme şu anda hizmet vermemektedir.',
                            'code' => 'TENANT_INACTIVE'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    $firma = htmlspecialchars($tenant['firma_adi']);
                    http_response_code(503);
                    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                    header('Pragma: no-cache');
                    header('Expires: 0');
                    header('X-LiteSpeed-Cache-Control: no-cache');
                    echo "<!DOCTYPE html>
<html lang='tr'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>{$firma} - Hizmet Dışı</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
        .card{background:#1e293b;padding:2.5rem 2rem;border-radius:20px;max-width:440px;width:100%;text-align:center;box-shadow:0 25px 50px rgba(0,0,0,.3)}
        .icon{font-size:3.5rem;margin-bottom:1rem}
        h1{font-size:1.4rem;font-weight:800;color:#f1f5f9;margin-bottom:0.5rem}
        .subtitle{font-size:0.95rem;color:#94a3b8;margin-bottom:1.5rem;line-height:1.5}
        .reasons{text-align:left;background:rgba(255,255,255,.03);border-radius:12px;padding:1.25rem;margin-bottom:1.5rem;border:1px solid rgba(255,255,255,.05)}
        .reasons p{font-size:.8rem;color:#64748b;margin-bottom:.5rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
        .reasons ul{list-style:none;padding:0}
        .reasons li{font-size:.85rem;color:#94a3b8;padding:.4rem 0;display:flex;align-items:center;gap:.5rem}
        .reasons li::before{content:'•';color:#475569}
        .contact{font-size:.8rem;color:#475569;line-height:1.5}
        .contact a{color:#6366f1;text-decoration:none}
        .home-btn{display:inline-block;margin-top:1.25rem;padding:.75rem 2rem;background:#6366f1;color:#fff;text-decoration:none;border-radius:10px;font-weight:600;font-size:.9rem;transition:background .2s}
        .home-btn:hover{background:#4f46e5}
        .owner-link{display:inline-block;margin-top:.75rem;padding:.6rem 1.5rem;background:transparent;color:#94a3b8;text-decoration:none;border-radius:10px;font-weight:600;font-size:.8rem;border:1px solid rgba(255,255,255,.1);transition:all .2s}
        .owner-link:hover{background:rgba(255,255,255,.05);color:#e2e8f0}
    </style>
</head>
<body>
    <div class='card'>
        <div class='icon'>🔒</div>
        <h1>{$firma}</h1>
        <div class='subtitle'>Bu işletme şu anda hizmet vermemektedir.</div>
        <div class='reasons'>
            <p>Olası nedenler</p>
            <ul>
                <li>Abonelik süresi dolmuş olabilir</li>
                <li>İşletme geçici olarak kapatılmış olabilir</li>
                <li>İşletme kapatma talebinde bulunmuş olabilir</li>
            </ul>
        </div>
        <div class='contact'>Detaylı bilgi için <a href='mailto:destek@randu.tr'>destek@randu.tr</a></div>
        <a href='https://randu.tr' class='home-btn'>Ana Sayfaya Dön</a>
    </div>
</body>
</html>";
                    exit;
                }
            } else {
                // Aktif tenant VEYA admin dışı sayfa — normal akış
                if (!defined('TENANT_DATA')) define('TENANT_DATA', $tenant);
                if (!defined('TENANT_CATEGORY')) define('TENANT_CATEGORY', trim(explode(',', $tenant['kategori'] ?? '')[0]));
                if (!defined('LIMIT_EMPLOYEES')) define('LIMIT_EMPLOYEES', intval($tenant['limit_employees']));
                if (!defined('LIMIT_SERVICES')) define('LIMIT_SERVICES', intval($tenant['limit_services']));
                if (!defined('LIMIT_SMS')) define('LIMIT_SMS', intval($tenant['limit_sms']));
                if (!defined('FEATURE_WHATSAPP')) define('FEATURE_WHATSAPP', intval($tenant['feature_whatsapp']));
                $site_name_default = $tenant['firma_adi'];
            }
        } else {
            if (!defined('TENANT_ID')) define('TENANT_ID', null);
            $site_name_default = 'Ana Yönetim Paneli';
        }
    } else {
        if (!defined('TENANT_ID')) define('TENANT_ID', null);
        if (!defined('TENANT_CODE')) define('TENANT_CODE', null);
        $site_name_default = 'Ana Yönetim Paneli';
    }

} else {
    // MÜŞTERİ SUBDOMAINİ (BERBER/KUAFÖR)
    // Önce is_active filtresi olmadan çek (pasif mi yoksa hiç yok mu ayırt et)
    $stmt = $db->prepare("
        SELECT t.*, p.name as paket_adi, p.limit_employees, p.limit_services, p.limit_sms, p.feature_whatsapp,
               CASE
                   WHEN LOWER(t.domain) = ? THEN 1
                   WHEN LOWER(COALESCE(NULLIF(t.slug, ''), '')) = ? THEN 2
                   WHEN LOWER(COALESCE(NULLIF(t.tenant_code, ''), '')) = ? THEN 3
                   ELSE 99
               END AS _match_rank
        FROM tenants t
        LEFT JOIN packages p ON t.package_id = p.id
        WHERE LOWER(t.domain) = ?
           OR LOWER(COALESCE(NULLIF(t.slug, ''), '')) = ?
           OR LOWER(COALESCE(NULLIF(t.tenant_code, ''), '')) = ?
        ORDER BY _match_rank ASC, t.id ASC
        LIMIT 1
    ");
    $stmt->execute([$subdomain, $subdomain, $subdomain, $subdomain, $subdomain, $subdomain]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    // Domain bos tenant kaydini ilk isabette otomatik tamamla
    if ($tenant && empty($tenant['domain'])) {
        try {
            $checkDomain = $db->prepare("SELECT id FROM tenants WHERE LOWER(domain) = ? LIMIT 1");
            $checkDomain->execute([$subdomain]);
            if (!$checkDomain->fetch(PDO::FETCH_ASSOC)) {
                $fixDomain = $db->prepare("UPDATE tenants SET domain = ? WHERE id = ? AND (domain IS NULL OR domain = '')");
                $fixDomain->execute([$subdomain, $tenant['id']]);
                $tenant['domain'] = $subdomain;
            }
        } catch (Exception $e) {
            error_log('Tenant domain auto-fix error: ' . $e->getMessage());
        }
    }

    if ($tenant && (int)$tenant['is_active'] === 1) {
        // Aktif tenant — normal akış
        if (!defined('TENANT_ID')) define('TENANT_ID', $tenant['id']);
        if (!defined('TENANT_CODE')) define('TENANT_CODE', $tenant['tenant_code']);
        if (!defined('TENANT_DATA')) define('TENANT_DATA', $tenant); 
        if (!defined('TENANT_CATEGORY')) define('TENANT_CATEGORY', trim(explode(',', $tenant['kategori'] ?? '')[0]));
        if (!defined('LIMIT_EMPLOYEES')) define('LIMIT_EMPLOYEES', intval($tenant['limit_employees']));
        if (!defined('LIMIT_SERVICES')) define('LIMIT_SERVICES', intval($tenant['limit_services']));
        if (!defined('LIMIT_SMS')) define('LIMIT_SMS', intval($tenant['limit_sms']));
        if (!defined('FEATURE_WHATSAPP')) define('FEATURE_WHATSAPP', intval($tenant['feature_whatsapp']));
        $site_name_default = $tenant['firma_adi'];
    } elseif ($tenant && (int)$tenant['is_active'] === 0) {
        // Pasif tenant — işletme kapalı sayfası göster
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $is_toggle_ajax = (strpos($request_uri, 'toggle_business.php') !== false);
        $is_logout = (strpos($request_uri, 'logout.php') !== false);
        
        if ($is_toggle_ajax || $is_logout) {
            // Toggle endpoint ve logout'a izin ver — tenant bilgilerini tanımla
            if (!defined('TENANT_ID')) define('TENANT_ID', $tenant['id']);
            if (!defined('TENANT_CODE')) define('TENANT_CODE', $tenant['tenant_code']);
            if (!defined('TENANT_DATA')) define('TENANT_DATA', $tenant);
            if (!defined('TENANT_CATEGORY')) define('TENANT_CATEGORY', trim(explode(',', $tenant['kategori'] ?? '')[0]));
            if (!defined('LIMIT_EMPLOYEES')) define('LIMIT_EMPLOYEES', intval($tenant['limit_employees']));
            if (!defined('LIMIT_SERVICES')) define('LIMIT_SERVICES', intval($tenant['limit_services']));
            if (!defined('LIMIT_SMS')) define('LIMIT_SMS', intval($tenant['limit_sms']));
            if (!defined('FEATURE_WHATSAPP')) define('FEATURE_WHATSAPP', intval($tenant['feature_whatsapp']));
            $site_name_default = $tenant['firma_adi'];
        } else {
            // Diğer tüm sayfalar (admin panel dahil) — işletme kapalı mesajı
            $is_ajax = (
                (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ||
                (strpos($request_uri, '/ajax/') !== false) ||
                (strpos($request_uri, '/api/') !== false)
            );
            
            if ($is_ajax) {
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('X-LiteSpeed-Cache-Control: no-cache');
                echo json_encode([
                    'success' => false,
                    'error' => 'business_inactive',
                    'message' => 'Bu işletme şu anda hizmet vermemektedir.',
                    'code' => 'TENANT_INACTIVE'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $firma = htmlspecialchars($tenant['firma_adi']);
            http_response_code(503);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-LiteSpeed-Cache-Control: no-cache');
            echo "<!DOCTYPE html>
<html lang='tr'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>{$firma} - Hizmet Dışı</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
        .card{background:#1e293b;padding:2.5rem 2rem;border-radius:20px;max-width:440px;width:100%;text-align:center;box-shadow:0 25px 50px rgba(0,0,0,.3)}
        .icon{font-size:3.5rem;margin-bottom:1rem}
        h1{font-size:1.4rem;font-weight:800;color:#f1f5f9;margin-bottom:0.5rem}
        .subtitle{font-size:0.95rem;color:#94a3b8;margin-bottom:1.5rem;line-height:1.5}
        .reasons{text-align:left;background:rgba(255,255,255,.03);border-radius:12px;padding:1.25rem;margin-bottom:1.5rem;border:1px solid rgba(255,255,255,.05)}
        .reasons p{font-size:.8rem;color:#64748b;margin-bottom:.5rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
        .reasons ul{list-style:none;padding:0}
        .reasons li{font-size:.85rem;color:#94a3b8;padding:.4rem 0;display:flex;align-items:center;gap:.5rem}
        .reasons li::before{content:'•';color:#475569}
        .contact{font-size:.8rem;color:#475569;line-height:1.5}
        .contact a{color:#6366f1;text-decoration:none}
        .home-btn{display:inline-block;margin-top:1.25rem;padding:.75rem 2rem;background:#6366f1;color:#fff;text-decoration:none;border-radius:10px;font-weight:600;font-size:.9rem;transition:background .2s}
        .home-btn:hover{background:#4f46e5}
        .owner-link{display:inline-block;margin-top:.75rem;padding:.6rem 1.5rem;background:transparent;color:#94a3b8;text-decoration:none;border-radius:10px;font-weight:600;font-size:.8rem;border:1px solid rgba(255,255,255,.1);transition:all .2s}
        .owner-link:hover{background:rgba(255,255,255,.05);color:#e2e8f0}
    </style>
</head>
<body>
    <div class='card'>
        <div class='icon'>🔒</div>
        <h1>{$firma}</h1>
        <div class='subtitle'>Bu işletme şu anda hizmet vermemektedir.</div>
        <div class='reasons'>
            <p>Olası nedenler</p>
            <ul>
                <li>Abonelik süresi dolmuş olabilir</li>
                <li>İşletme geçici olarak kapatılmış olabilir</li>
                <li>İşletme kapatma talebinde bulunmuş olabilir</li>
            </ul>
        </div>
        <div class='contact'>Detaylı bilgi için <a href='mailto:destek@randu.tr'>destek@randu.tr</a></div>
        <a href='https://randu.tr' class='home-btn'>Ana Sayfaya Dön</a>
    </div>
</body>
</html>";
            exit;
        }
    } else {
        // Tenant hiç bulunamadı
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $is_ajax = (
            (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ||
            (strpos($request_uri, '/ajax/') !== false) ||
            (strpos($request_uri, '/api/') !== false)
        );
        
        if ($is_ajax) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'tenant_not_found',
                'message' => "İşletme bulunamadı: {$subdomain}",
                'code' => 'TENANT_404'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            http_response_code(404);
            echo "<!DOCTYPE html>
<html lang='tr'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>İşletme Bulunamadı</title>
    <style>
        body{font-family:sans-serif;text-align:center;padding:50px 20px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center}
        .c{background:#fff;color:#333;padding:40px;border-radius:15px;box-shadow:0 10px 40px rgba(0,0,0,.2);max-width:500px}
        h1{color:#d9534f;font-size:2em;margin-bottom:20px}
        p{line-height:1.6;margin-bottom:15px}
        .s{font-weight:700;color:#667eea}
        a{display:inline-block;margin-top:20px;padding:12px 30px;background:#667eea;color:#fff;text-decoration:none;border-radius:5px;font-weight:600}
    </style>
</head>
<body>
    <div class='c'>
        <h1>⚠️ İşletme Bulunamadı</h1>
        <p>Girdiğiniz adrese (<span class='s'>{$subdomain}</span>) ait aktif işletme bulunamadı.</p>
        <p>Lütfen adresi kontrol edin.</p>
        <a href='https://randu.tr'>Ana Sayfa</a>
    </div>
</body>
</html>";
            exit;
        }
    }
}

// ==========================================================================
// ⚙️ 4. GENEL AYARLAR VE SABİTLER
// ==========================================================================

if (!defined('SITE_NAME')) define('SITE_NAME', isset($site_name_default) ? $site_name_default : 'Randevu Sistemi');
if (!defined('SITE_URL')) define('SITE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]"); 
if (!defined('ADMIN_URL')) define('ADMIN_URL', SITE_URL . '/admin');
if (!defined('SESSION_TIMEOUT')) define('SESSION_TIMEOUT', EnvLoader::get('SESSION_TIMEOUT', 3600));
if (!defined('UPLOAD_DIR_BASE')) define('UPLOAD_DIR_BASE', __DIR__ . '/../assets/uploads/'); 
if (!defined('MAX_FILE_SIZE')) define('MAX_FILE_SIZE', EnvLoader::get('UPLOAD_MAX_FILE_SIZE', 5242880));

// Environment Variables
if (!defined('APP_ENV')) define('APP_ENV', EnvLoader::get('APP_ENV', 'production'));
// Debug Mode (already configured at top of file)
if (!defined('APP_DEBUG')) define('APP_DEBUG', $_app_debug);
if (!defined('CSRF_SECRET')) {
    $csrf_secret = EnvLoader::get('CSRF_SECRET', '');
    if (empty($csrf_secret)) {
        error_log('GÜVENLİK UYARISI: CSRF_SECRET .env dosyasında tanımlanmamış!');
        $csrf_secret = hash('sha256', __DIR__ . php_uname());
    }
    define('CSRF_SECRET', $csrf_secret);
}

// Timezone
date_default_timezone_set(EnvLoader::get('APP_TIMEZONE', 'Europe/Istanbul'));

// Debug mode already configured at file top based on APP_DEBUG

// ==========================================================================
// 📁 5. AYARLARI VERİTABANINDAN ÇEKME
// ==========================================================================

try {
    if (defined('TENANT_ID') && TENANT_ID !== null) {
        $stmt_settings = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE tenant_id = ?");
        $stmt_settings->execute([TENANT_ID]);
        $site_settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);
    } else {
        $site_settings = []; 
    }
} catch (Exception $e) {
    $site_settings = [];
}

// Geçmişte encode edilmiş adları runtime'da normalize et
if (!empty($site_settings['site_name'])) {
    $site_settings['site_name'] = trim(html_entity_decode((string)$site_settings['site_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

if (!empty($site_name_default)) {
    $site_name_default = trim(html_entity_decode((string)$site_name_default, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

if (!empty($site_settings['site_name']) && !defined('SITE_NAME_FROM_DB')) {
     define('SITE_NAME_FROM_DB', $site_settings['site_name']);
} else if (!defined('SITE_NAME_FROM_DB')) {
     define('SITE_NAME_FROM_DB', isset($site_name_default) ? $site_name_default : 'Randevu Paneli');
}

// ==========================================================================
// 📱 6. MERKEZİ SMS API FONKSİYONU (MASTER HESAP - LİMİT KONTROLÜ)
// ==========================================================================

/**
 * VatanSMS NtoN isteği (geçici 502/503 için kısa retry).
 * @return array{response:mixed,curl_error:string,http_code:int,raw:string,attempts:int}
 */
function vatanSmsRequest(array $params, $maxAttempts = 3) {
    $apiUrl = 'https://api.toplusms.app/api/v1/NtoN';
    $payload = json_encode($params);
    $response = false;
    $curlError = '';
    $httpCode = 0;
    $attempts = 0;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $attempts = $attempt;
        $response = false;
        $curlError = '';
        $httpCode = 0;

        if (function_exists('curl_init')) {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json']
            ]);
            $response = curl_exec($curl);
            $curlError = curl_error($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            $response = @file_get_contents($apiUrl, false, $context);
            if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
                $httpCode = (int) $m[1];
            }
            if ($response === false) {
                $curlError = 'HTTP request failed (cURL unavailable)';
            }
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        $transient = ($curlError !== '')
            || in_array($httpCode, [502, 503, 504], true)
            || ($httpCode === 0)
            || ($response === false)
            || ($response === '' || $response === null)
            || (!is_array($decoded) && $httpCode !== 200);

        if (!$transient || $attempt >= $maxAttempts) {
            break;
        }

        Logger::warning('SMS API geçici hata, yeniden deneniyor', [
            'attempt' => $attempt,
            'http_code' => $httpCode,
            'curl_error' => $curlError ?: null,
        ]);
        usleep(400000 * $attempt); // 0.4s, 0.8s, ...
    }

    return [
        'response' => is_string($response) ? json_decode($response, true) : null,
        'curl_error' => $curlError,
        'http_code' => $httpCode,
        'raw' => is_string($response) ? $response : '',
        'attempts' => $attempts,
    ];
}

/**
 * Kullanıcıya gösterilecek VatanSMS hata metni.
 */
function formatVatanSmsError($httpCode, $result, $raw = '') {
    if (is_array($result)) {
        $apiMsg = $result['message'] ?? $result['description'] ?? null;
        if (is_string($apiMsg) && $apiMsg !== '') {
            return $apiMsg;
        }
    }
    if (in_array((int) $httpCode, [502, 503, 504], true) || $result === null) {
        return 'SMS sağlayıcısı geçici olarak yanıt vermiyor (HTTP ' . (int) $httpCode . '). Lütfen birkaç saniye sonra tekrar deneyin.';
    }
    if ((int) $httpCode === 0) {
        return 'SMS sağlayıcısına bağlanılamadı. Lütfen tekrar deneyin.';
    }
    if (is_string($raw) && $raw !== '' && strlen($raw) < 200 && strpos($raw, '<') === false) {
        return $raw;
    }
    return 'SMS sağlayıcısından beklenmeyen yanıt (HTTP ' . (int) $httpCode . ').';
}

/**
 * SMS gönderimi için işletme kimliğini çözümler.
 * Override > APPOINTMENT_TENANT_ID > TENANT_ID > oturum tenant_id
 */
function resolveSmsTenantId($tenantIdOverride = null): ?int {
    if ($tenantIdOverride !== null && (int)$tenantIdOverride > 0) {
        return (int)$tenantIdOverride;
    }
    if (defined('APPOINTMENT_TENANT_ID') && APPOINTMENT_TENANT_ID !== null && (int)APPOINTMENT_TENANT_ID > 0) {
        return (int)APPOINTMENT_TENANT_ID;
    }
    if (defined('TENANT_ID') && TENANT_ID !== null && (int)TENANT_ID > 0) {
        return (int)TENANT_ID;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['tenant_id'])) {
        return (int)$_SESSION['tenant_id'];
    }
    return null;
}

/**
 * Merkezi SMS Gönderim Fonksiyonu
 * 
 * ÖNEMLİ: Artık her işletmenin kendi API bilgisi YOK!
 * Tüm sistem TEK Master API kullanıyor.
 * Limitler tamamen veritabanından kontrol ediliyor.
 */
function sendSms($phone, $message, $sendTime = null, $tenantIdOverride = null) {
    global $db;

    // 1. İşletme Kontrolü
    $current_tenant_id = resolveSmsTenantId($tenantIdOverride);
    if ($current_tenant_id === null) {
        Logger::error("SMS HATA: Hiçbir TENANT_ID tanımlı değil");
        return [
            'status' => false, 
            'message' => 'Sistem hatası: İşletme kimliği bulunamadı.'
        ];
    }

    // 0. Rate Limiting: tenant + telefon bazlı (NAT/ortak IP kaynaklı false-positive'leri azaltır)
    $normalizedPhone = formatPhoneNumber((string)$phone);
    if (strlen($normalizedPhone) !== 10) {
        $normalizedPhone = preg_replace('/\D/', '', (string)$phone);
    }

    $isCli = (php_sapi_name() === 'cli');
    $isSystemCall = $isCli || ($tenantIdOverride !== null);
    $rateLimitId = ($isSystemCall ? 'system' : 'web') . '_' . $current_tenant_id . '_' . $normalizedPhone;
    $rateLimitMax = $isSystemCall ? 60 : 6;
    if (!RateLimiter::check('sms_send', $rateLimitMax, 60, $rateLimitId)) {
        Logger::warning("SMS Rate Limit aşıldı", ['tenant_id' => $current_tenant_id, 'phone' => $normalizedPhone]);
        return [
            'status' => false,
            'message' => 'Çok fazla SMS isteği. Lütfen biraz bekleyin.'
        ];
    }

    // 2. SMS Sistemi Kapalı mı?
    if (!SMS_SYSTEM_ENABLED) {
        Logger::info("SMS gönderimi engellendi: Sistem kapalı", ['phone' => $phone]);
        return [
            'status' => false, 
            'message' => 'SMS sistemi şu anda bakımdadır.'
        ];
    }

    // 3. Master API Bilgileri Kontrol
    if (empty(MASTER_SMS_API_KEY) || empty(MASTER_SMS_SENDER)) {
        Logger::error("SMS Konfigürasyon Hatası: API bilgileri eksik");
        return [
            'status' => false, 
            'message' => 'Sistem hatası: Master SMS API yapılandırması eksik.'
        ];
    }

    try {
        // 4. İşletmenin SMS Ayarlarını ve LİMİTLERİNİ Çek
        $stmt = $db->prepare("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE tenant_id = ? 
            AND setting_key IN ('sms_notifications', 'sms_total_limit', 'sms_current_count')
        ");
        $stmt->execute([$current_tenant_id]);
        $smsSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // 5. Değişkenleri Hazırla
        $isEnabled = ($smsSettings['sms_notifications'] ?? 'off') === 'on';
        $totalLimit = intval($smsSettings['sms_total_limit'] ?? 0); 
        $currentCount = intval($smsSettings['sms_current_count'] ?? 0);

        // 6. KRİTİK KONTROLLER

        // A) İşletme SMS özelliğini açmış mı?
        if (!$isEnabled) {
            return [
                'status' => false, 
                'message' => 'SMS bildirimleri kapalı. Lütfen ayarlardan aktif ediniz.'
            ];
        }

        // B) 🔴 LİMİT KONTROLÜ (EN ÖNEMLİ KISIM!)
        // Eğer limit 0'dan büyükse VE kullanım limite ulaştıysa DURDUR!
        if ($totalLimit > 0 && $currentCount >= $totalLimit) {
            Logger::warning("SMS Limiti Aşıldı", ['tenant_id' => $current_tenant_id, 'limit' => $totalLimit]);
            return [
                'status' => false, 
                'message' => '⚠️ SMS paketiniz tükenmiştir. Lütfen yöneticinizle iletişime geçin.',
                'limit_reached' => true,
                'current' => $currentCount,
                'limit' => $totalLimit
            ];
        }

        // 7. Telefon Numarası Temizliği
        $cleanPhone = preg_replace('/\D/', '', $phone);
        if (substr($cleanPhone, 0, 1) === '0') {
            $cleanPhone = substr($cleanPhone, 1);
        }
        if (substr($cleanPhone, 0, 2) === '90' && strlen($cleanPhone) > 10) {
            $cleanPhone = substr($cleanPhone, 2);
        }
        
        if (strlen($cleanPhone) !== 10) {
            return [
                'status' => false, 
                'message' => 'Geçersiz telefon numarası formatı.'
            ];
        }

        // Türkçe karakterleri ASCII'ye çevir (SMS bozulmasın)
        $message = turkishToAsciiForSms($message);

        // 8. VatanSMS API İsteği (MASTER BİLGİLERİ KULLANILIYOR!)
        $params = [
            'api_id' => MASTER_SMS_API_ID,
            'api_key' => MASTER_SMS_API_KEY,
            'sender' => MASTER_SMS_SENDER,
            'message_type' => 'normal',
            'message_content_type' => 'bilgi',
            'phones' => [
                [
                    'phone' => $cleanPhone,
                    'message' => $message
                ]
            ]
        ];

        if ($sendTime !== null) {
            $params['send_time'] = $sendTime;
        }

        // 9. HTTP isteği ile gönderim (geçici 502 için retry)
        $smsHttp = vatanSmsRequest($params);
        $curlError = $smsHttp['curl_error'];
        $httpCode = (int) $smsHttp['http_code'];
        $result = $smsHttp['response'];

        if ($curlError) {
            Logger::error("SMS cURL Hatası", [
                'error' => $curlError,
                'phone' => $cleanPhone,
                'attempts' => $smsHttp['attempts'],
            ]);
            return [
                'status' => false, 
                'message' => 'Sunucu bağlantı hatası: ' . $curlError
            ];
        }

        // 10. Yanıtı Analiz Et ve SAYACI ARTIR
        if ($httpCode == 200 && isset($result['status']) && ($result['status'] === 'success' || $result['status'] === true)) {
            
            // ✅ BAŞARILI: Veritabanındaki kullanım sayacını +1 artır
            $stmtUpd = $db->prepare("
                UPDATE settings 
                SET setting_value = setting_value + 1 
                WHERE tenant_id = ? AND setting_key = 'sms_current_count'
            ");
            $stmtUpd->execute([$current_tenant_id]);
            
            // Güncellenen satır sayısını kontrol et
            $updated_rows = $stmtUpd->rowCount();
            
            Logger::info("SMS Gönderildi", [
                'tenant_id' => $current_tenant_id, 
                'phone' => $cleanPhone,
                'updated_rows' => $updated_rows,
                'old_count' => $currentCount,
                'api_response' => $result
            ]);
            
            // Eğer güncelleme yapılmadıysa, kayıt yoktur - oluştur
            if ($updated_rows === 0) {
                Logger::warning("SMS sayacı bulunamadı, oluşturuluyor", ['tenant_id' => $current_tenant_id]);
                $stmtIns = $db->prepare("
                    INSERT INTO settings (tenant_id, setting_key, setting_value) 
                    VALUES (?, 'sms_current_count', 1)
                    ON DUPLICATE KEY UPDATE setting_value = setting_value + 1
                ");
                $stmtIns->execute([$current_tenant_id]);
            }

            // Yeni değerleri al
            $newCount = $currentCount + 1;
            $remaining = $totalLimit - $newCount;
            
            return [
                'status' => true, 
                'message' => 'SMS başarıyla gönderildi.',
                'used' => $newCount,
                'remaining' => $remaining,
                'limit' => $totalLimit
            ];
        } else {
            // ❌ BAŞARISIZ
            $apiMsg = formatVatanSmsError($httpCode, $result, $smsHttp['raw'] ?? '');
            Logger::error("SMS API Hatası", [
                'response' => $result, 
                'phone' => $cleanPhone,
                'http_code' => $httpCode,
                'attempts' => $smsHttp['attempts'] ?? 1,
                'status_check' => [
                    'status_value' => $result['status'] ?? 'yok',
                    'status_type' => gettype($result['status'] ?? null)
                ]
            ]);
            return [
                'status' => false, 
                'message' => 'SMS Servis Hatası: ' . $apiMsg
            ];
        }

    } catch (Exception $e) {
        Logger::error("SMS İşlem Exception", ['error' => $e->getMessage()]);
        return [
            'status' => false, 
            'message' => 'İşlem hatası: ' . $e->getMessage()
        ];
    }
}

/**
 * GLOBAL SMS GÖNDERİMİ
 * Şifre sıfırlama gibi sistem mesajları için kullanılır
 * İşletme bakiyesinden düşmez, tenant kontrolü yapmaz
 * Direkt Master API üzerinden gönderir
 */
function sendGlobalSms($phone, $message) {
    // SMS Sistemi Kapalı mı?
    if (!SMS_SYSTEM_ENABLED) {
        Logger::info("Global SMS engellendi: Sistem kapalı", ['phone' => $phone]);
        return [
            'status' => false, 
            'message' => 'SMS sistemi şu anda bakımdadır.'
        ];
    }

    // Master API Bilgileri Kontrol
    if (empty(MASTER_SMS_API_KEY) || empty(MASTER_SMS_SENDER)) {
        Logger::error("Global SMS Konfigürasyon Hatası: API bilgileri eksik");
        return [
            'status' => false, 
            'message' => 'Sistem hatası: SMS API yapılandırması eksik.'
        ];
    }

    try {
        // Telefon Numarası Temizliği
        $cleanPhone = preg_replace('/\D/', '', $phone);
        if (substr($cleanPhone, 0, 1) === '0') {
            $cleanPhone = substr($cleanPhone, 1);
        }
        if (substr($cleanPhone, 0, 2) === '90' && strlen($cleanPhone) > 10) {
            $cleanPhone = substr($cleanPhone, 2);
        }
        
        if (strlen($cleanPhone) !== 10) {
            return [
                'status' => false, 
                'message' => 'Geçersiz telefon numarası formatı.'
            ];
        }

        $message = turkishToAsciiForSms($message);

        // VatanSMS API İsteği
        $params = [
            'api_id' => MASTER_SMS_API_ID,
            'api_key' => MASTER_SMS_API_KEY,
            'sender' => MASTER_SMS_SENDER,
            'message_type' => 'normal',
            'message_content_type' => 'bilgi',
            'phones' => [
                [
                    'phone' => $cleanPhone,
                    'message' => $message
                ]
            ]
        ];

        $smsHttp = vatanSmsRequest($params);
        $curlError = $smsHttp['curl_error'];
        $httpCode = (int) $smsHttp['http_code'];
        $result = $smsHttp['response'];

        if ($curlError) {
            Logger::error("Global SMS cURL Hatası", [
                'error' => $curlError,
                'phone' => $cleanPhone,
                'attempts' => $smsHttp['attempts'],
            ]);
            return [
                'status' => false, 
                'message' => 'Sunucu bağlantı hatası: ' . $curlError
            ];
        }

        // Yanıtı Analiz Et (sayaç artırmıyoruz - global SMS)
        if ($httpCode == 200 && isset($result['status']) && ($result['status'] === 'success' || $result['status'] === true)) {
            Logger::info("Global SMS Gönderildi", [
                'phone' => $cleanPhone,
                'type' => 'system_message',
                'api_response' => $result
            ]);
            
            return [
                'status' => true, 
                'message' => 'SMS başarıyla gönderildi.'
            ];
        } else {
            $apiMsg = formatVatanSmsError($httpCode, $result, $smsHttp['raw'] ?? '');
            Logger::error("Global SMS API Hatası", [
                'response' => $result, 
                'phone' => $cleanPhone,
                'http_code' => $httpCode,
                'attempts' => $smsHttp['attempts'],
            ]);
            return [
                'status' => false, 
                'message' => 'SMS Servis Hatası: ' . $apiMsg
            ];
        }

    } catch (Exception $e) {
        Logger::error("Global SMS Exception", ['error' => $e->getMessage()]);
        return [
            'status' => false, 
            'message' => 'İşlem hatası: ' . $e->getMessage()
        ];
    }
}

// ==========================================================================
// 🛠️ 7. YARDIMCI FONKSİYONLAR
// ==========================================================================

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

/**
 * Randevu/müşteri notları için güvenli temizlik.
 * htmlspecialchars KULLANMAZ — aksi halde DB'de &amp; &quot; birikir ve notlar bozulur.
 * Eski kayıtlardaki entity encoding'i de çözer.
 */
function sanitizeNotes($notes, $maxLength = 2000) {
    $notes = decodeNotesEntities((string)$notes);
    $notes = strip_tags($notes);
    $notes = trim($notes);
    // Kontrol karakterlerini temizle (null byte vb.), satır sonlarını koru
    $notes = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $notes);
    if ($maxLength > 0 && mb_strlen($notes, 'UTF-8') > $maxLength) {
        $notes = mb_substr($notes, 0, $maxLength, 'UTF-8');
    }
    return $notes;
}

/**
 * DB'de birikmiş HTML entity'leri çöz (&amp; → &, &quot; → " vb.).
 * Moto/servis JSON notlarına dokunmaz.
 */
function decodeNotesEntities($notes) {
    if ($notes === null || $notes === '') {
        return '';
    }
    $notes = (string)$notes;
    $trim = ltrim($notes);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        return $notes;
    }
    $guard = 0;
    while ($guard < 5 && strpos($notes, '&') !== false) {
        $decoded = html_entity_decode($notes, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded === $notes) {
            break;
        }
        $notes = $decoded;
        $guard++;
    }
    return $notes;
}

/**
 * İsim/unvan alanlarını temizle ve karakter sınırı uygula.
 * SMS sömürme saldırısını önler (uzun isim = fazla SMS kredisi).
 * @param string $name
 * @param int $maxLength Varsayılan 50 karakter
 * @return string
 */
function sanitizeName($name, $maxLength = 50) {
    $name = html_entity_decode((string)$name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name = strip_tags(trim($name));
    $name = preg_replace('/\s+/u', ' ', $name);
    if (mb_strlen($name, 'UTF-8') > $maxLength) {
        $name = mb_substr($name, 0, $maxLength, 'UTF-8');
    }
    return $name;
}

/**
 * SMS mesajında kullanılacak isimleri güvenli uzunlukta keser.
 * @param string $name
 * @param int $maxLength Varsayılan 40 karakter
 * @return string
 */
function truncateForSms($name, $maxLength = 40) {
    $name = strip_tags(trim($name));
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($name, 'UTF-8') > $maxLength) {
            $name = mb_substr($name, 0, $maxLength, 'UTF-8');
        }
    } elseif (strlen($name) > $maxLength) {
        $name = substr($name, 0, $maxLength);
    }
    return $name;
}

/**
 * SMS sağlayıcıları Türkçe karakterleri bozabiliyor (ı→ , ş→  vb.).
 * Gönderim öncesi ASCII karşılıklarına çevirir.
 */
function turkishToAsciiForSms($text) {
    if ($text === null || $text === '') {
        return '';
    }
    $map = [
        'ç' => 'c', 'Ç' => 'C',
        'ğ' => 'g', 'Ğ' => 'G',
        'ı' => 'i', 'İ' => 'I',
        'ö' => 'o', 'Ö' => 'O',
        'ş' => 's', 'Ş' => 'S',
        'ü' => 'u', 'Ü' => 'U',
        'â' => 'a', 'Â' => 'A',
        'ê' => 'e', 'Ê' => 'E',
        'î' => 'i', 'Î' => 'I',
        'ô' => 'o', 'Ô' => 'O',
        'û' => 'u', 'Û' => 'U',
        '₺' => 'TL',
    ];
    return strtr((string)$text, $map);
}

/**
 * SMS başlığı için güvenli büyük harf.
 * PHP strtoupper Türkçe ö/ü/ş... karakterlerini büyütmez; sonra ASCII
 * dönüşümü "KUAFoRu" gibi bozuk sonuç üretir. Önce ASCII, sonra upper.
 */
function upperForSms($text) {
    return strtoupper(turkishToAsciiForSms($text));
}

/**
 * Telefon numarasını standart formata dönüştür (10 haneli, 0'sız)
 * Desteklenen formatlar: 0532xxx, 532xxx, 90532xxx, +90532xxx
 * @param string $phone
 * @return string 10 haneli format (0'sız)
 */
function formatPhoneNumber($phone) {
    // Sadece rakamları al
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Eğer 11 haneli ve 0 ile başlıyorsa, 0'ı sil (0532... -> 532...)
    if (strlen($phone) == 11 && substr($phone, 0, 1) == '0') {
        $phone = substr($phone, 1);
    }
    
    // Eğer 12 haneli ve 90 ile başlıyorsa, 90'ı sil (90532... -> 532...)
    if (strlen($phone) == 12 && substr($phone, 0, 2) == '90') {
        $phone = substr($phone, 2);
    }
    
    return $phone;
}

/**
 * E-posta adresini normalize et
 * - Türkçe İ/I karakterlerini düzeltir (punycode sorununu önler)
 * - Tamamını küçük harfe çevirir
 * - Boşlukları temizler
 * @param string $email
 * @return string normalize edilmiş e-posta
 */
function normalizeEmail($email) {
    $email = trim($email);
    if (empty($email)) return '';
    
    // Küçük harfe çevir (sadece ASCII için)
    $email = mb_strtolower($email, 'UTF-8');
    
    // Punycode domain varsa çöz (xn-- prefix)
    $parts = explode('@', $email, 2);
    if (count($parts) === 2 && function_exists('idn_to_utf8') && strpos($parts[1], 'xn--') !== false) {
        $decoded = idn_to_utf8($parts[1], 0, INTL_IDNA_VARIANT_UTS46);
        if ($decoded !== false) {
            $email = $parts[0] . '@' . $decoded;
        }
    }
    
    // Türkçe veya ASCII-dışı karakter varsa reddet
    if (preg_match('/[^\x20-\x7E]/', $email)) {
        return false;
    }
    
    return $email;
}

/**
 * Login Brute Force Protection
 * IP bazlı denemeler takip edilir
 * 5 başarısız deneyden sonra 15 dakika lockout
 * 
 * Neden IP-only?
 * - Şifre reset edildiğinde blok otomatik açılsın diye
 * - Başarılı login sonrası blok temizlenir
 * - Aynı IP'den farklı user'lar denerse de sayılır (kabul edilebilir risk)
 */
function checkLoginAttempts() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $lockout_file = __DIR__ . '/../logs/login_attempts_' . md5($ip) . '.json';
    $max_attempts = 5;
    $lockout_duration = 900; // 15 dakika
    
    // Lockout dosyası varsa kontrol et
    if (file_exists($lockout_file)) {
        $data = json_decode(file_get_contents($lockout_file), true);
        if ($data && isset($data['locked_until'])) {
            if (time() < $data['locked_until']) {
                return [
                    'blocked' => true,
                    'message' => 'Çok fazla başarısız deneme. Lütfen 15 dakika sonra tekrar deneyin.',
                    'remaining' => $data['locked_until'] - time()
                ];
            } else {
                // Lockout süresi geçti, dosyayı sil
                @unlink($lockout_file);
            }
        }
    }
    
    return ['blocked' => false, 'message' => ''];
}

/**
 * Login başarısız olduğunda attempt sayısını artır
 */
function recordFailedLoginAttempt() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $lockout_file = __DIR__ . '/../logs/login_attempts_' . md5($ip) . '.json';
    $max_attempts = 5;
    $lockout_duration = 900; // 15 dakika
    
    // Logs klasörü yoksa oluştur
    if (!file_exists(__DIR__ . '/../logs')) {
        mkdir(__DIR__ . '/../logs', 0755, true);
    }
    
    $data = [];
    if (file_exists($lockout_file)) {
        $data = json_decode(file_get_contents($lockout_file), true) ?? [];
    }
    
    $data['attempts'] = ($data['attempts'] ?? 0) + 1;
    $data['first_attempt'] = $data['first_attempt'] ?? time();
    $data['ip'] = $ip;
    
    // 5 başarısız denemeden sonra lock yap
    if ($data['attempts'] >= $max_attempts) {
        $data['locked_until'] = time() + $lockout_duration;
        Logger::warning("Login Brute Force Protection Activated", [
            'ip' => $ip,
            'attempts' => $data['attempts'],
            'locked_until' => date('Y-m-d H:i:s', $data['locked_until'])
        ]);
    }
    
    file_put_contents($lockout_file, json_encode($data));
}

/**
 * Başarılı login sonrası attempt dosyasını temizle
 */
function clearLoginAttempts() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $lockout_file = __DIR__ . '/../logs/login_attempts_' . md5($ip) . '.json';
    @unlink($lockout_file);
}

/**
 * Saati valide et (H:i formatında)
 * @param string $time
 * @return bool
 */
function validateTime($time) {
    if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
        return false;
    }
    [$hours, $minutes] = explode(':', $time);
    return true;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function checkSessionTimeout() {
    if (isLoggedIn()) { 
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
            session_unset();
            session_destroy();
            return false;
        }
        $_SESSION['last_activity'] = time();
    }
    return true; 
}

function redirect($url) {
    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
        if ($url === 'login.php' || strpos($url, 'admin/') === 0) {
             $admin_path = rtrim(ADMIN_URL, '/');
             $url = $admin_path . '/' . ltrim($url, '/');
        } else {
             $site_path = rtrim(SITE_URL, '/');
             $url = $site_path . '/' . ltrim($url, '/');
        }
    }
    if (!headers_sent()) {
        header("Location: " . $url);
    } else {
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    }
    exit();
}

function formatPrice($price) {
    $val = floatval($price);
    if ($val == floor($val)) {
        return number_format($val, 0, ',', '.') . ' ₺';
    }
    return number_format($val, 2, ',', '.') . ' ₺';
}

function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    return date('d.m.Y', strtotime($date));
}

function formatDateTime($datetime) {
     if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '';
    return date('d.m.Y H:i', strtotime($datetime));
}

function getTurkishDays() {
    return [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];
}

function getMonthName($month) {
    $months = [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];
    return $months[intval($month)] ?? '';
}

// ==========================================================================
// 🛡️ 8. GÜVENLİK VE DOSYA YÖNETİMİ
// ==========================================================================

if (isset($_SESSION['user_id']) && isset($_SESSION['tenant_id'])) {
    if (defined('TENANT_ID') && TENANT_ID !== null && TENANT_ID != $_SESSION['tenant_id']) {
        session_unset();
        session_destroy();
        header("Location: /admin/login.php?error=invalid_tenant");
        exit;
    }
}

function uploadTenantFile($file, $subfolder = 'general', $old_file = null) {
    if (!defined('TENANT_ID') || TENANT_ID === null) {
        return ['success' => false, 'message' => 'Oturum hatası: İşletme kimliği yok.'];
    }
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Dosya yüklenemedi.'];
    }
    
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed)) return ['success' => false, 'message' => 'Geçersiz dosya formatı.'];
    if ($file['size'] > 5242880) return ['success' => false, 'message' => 'Dosya boyutu 5MB sınırını aşıyor.'];

    // R2 Storage kullanımı
    require_once __DIR__ . '/R2Storage.php';
    $r2 = new R2Storage();
    
    if ($r2->isEnabled()) {
        // R2'ye yükle
        $new_filename = uniqid() . '_' . time() . '.' . $ext;
        $r2_path = 'tenants/' . TENANT_ID . '/' . $subfolder . '/' . $new_filename;
        
        $result = $r2->upload($file, $r2_path);
        
        if ($result['success']) {
            // Eski dosyayı sil (R2'den)
            if ($old_file && !empty($old_file)) {
                // URL'den path çıkar
                if (strpos($old_file, 'http') === 0) {
                    $parsed = parse_url($old_file);
                    $old_path = ltrim($parsed['path'], '/');
                    $r2->delete($old_path);
                } else {
                    // assets/uploads/ yerine direkt path
                    $old_path = str_replace('assets/uploads/', '', $old_file);
                    $r2->delete($old_path);
                }
            }
            return [
                'success' => true,
                'path' => $result['url'], // Tam URL döndür
                'message' => 'Dosya R2\'ye yüklendi.'
            ];
        } else {
            // R2 başarısız, local'e fallback
            error_log('R2 upload failed, falling back to local: ' . $result['message']);
        }
    }
    
    // Local storage (fallback veya R2 disabled)
    $base_folder = 'assets/uploads/tenants/' . TENANT_ID . '/' . $subfolder . '/';
    $target_dir = __DIR__ . '/../' . $base_folder; 

    if (!file_exists($target_dir)) mkdir($target_dir, 0755, true);

    $new_filename = uniqid() . '_' . time() . '.' . $ext;
    $target_file = $target_dir . $new_filename;
    $db_path = $base_folder . $new_filename; 

    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        if ($old_file && !empty($old_file)) {
            deleteAsset($old_file, __DIR__ . '/../');
        }
        return ['success' => true, 'path' => $db_path, 'message' => 'Dosya yüklendi.'];
    } else {
        return ['success' => false, 'message' => 'Dosya taşınırken hata oluştu.'];
    }
}

function checkLimit($table, $limit) {
    global $db;
    
    if ($limit == -1) return true;

    // Çöptekiler (soft-delete) limit sayılmaz
    $sql = "SELECT COUNT(*) FROM `$table` WHERE tenant_id = ? AND status = 'active'";
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM `$table` LIKE 'deleted_at'")->fetch(PDO::FETCH_ASSOC);
        if ($colCheck) {
            $sql .= " AND deleted_at IS NULL";
        }
    } catch (Throwable $e) {
        // kolon yoksa status ile devam
    }

    $stmt = $db->prepare($sql);
    $stmt->execute([TENANT_ID]);
    $current_count = $stmt->fetchColumn();
    
    if ($current_count >= $limit) {
        return "Paket limitiniz doldu! (Limit: $limit, Mevcut: $current_count). Yükseltmek için yöneticiye başvurun.";
    }
    return true;
}

/**
 * İşletmenin SMS Durumunu Getir
 */
function getSmsStatus() {
    global $db;
    
    if (!defined('TENANT_ID') || TENANT_ID === null) {
        return ['total' => 0, 'used' => 0, 'remaining' => 0, 'percentage' => 0];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE tenant_id = ? 
            AND setting_key IN ('sms_total_limit', 'sms_current_count')
        ");
        $stmt->execute([TENANT_ID]);
        $data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $total = intval($data['sms_total_limit'] ?? 0);
        $used = intval($data['sms_current_count'] ?? 0);
        $remaining = max(0, $total - $used);
        $percentage = $total > 0 ? round(($used / $total) * 100, 1) : 0;
        
        return [
            'total' => $total,
            'used' => $used,
            'remaining' => $remaining,
            'percentage' => $percentage
        ];
    } catch (Exception $e) {
        return ['total' => 0, 'used' => 0, 'remaining' => 0, 'percentage' => 0];
    }
}

/**
 * Tenant SMS gönderim kapasitesi (sistem + ayarlar + paket).
 * Frontend uyarıları ve API sms_warning üretimi için kullanılır.
 */
function getTenantSmsCapability($tenantId = null) {
    global $db;

    if ($tenantId === null) {
        if (defined('TENANT_ID') && TENANT_ID !== null) {
            $tenantId = TENANT_ID;
        } else {
            return [
                'system_enabled' => false,
                'notifications_enabled' => false,
                'send_on_create' => false,
                'total' => 0,
                'used' => 0,
                'remaining' => 0,
                'can_send' => false,
                'warning' => 'SMS sistemi kullanılamıyor.',
                'send_on_create_warning' => null,
                'settings_url' => '/admin/settings/randevu.php',
            ];
        }
    }

    $systemEnabled = defined('SMS_SYSTEM_ENABLED') ? (bool) SMS_SYSTEM_ENABLED : true;
    $notificationsEnabled = false;
    $sendOnCreate = true;
    $total = 0;
    $used = 0;

    try {
        $stmt = $db->prepare("
            SELECT setting_key, setting_value
            FROM settings
            WHERE tenant_id = ?
              AND setting_key IN ('sms_notifications', 'sms_send_on_create', 'sms_total_limit', 'sms_current_count')
        ");
        $stmt->execute([(int) $tenantId]);
        $data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $notificationsEnabled = ($data['sms_notifications'] ?? 'off') === 'on';
        $sendOnCreate = ($data['sms_send_on_create'] ?? 'on') === 'on';
        $total = (int) ($data['sms_total_limit'] ?? 0);
        $used = (int) ($data['sms_current_count'] ?? 0);
    } catch (Exception $e) {
        // Ayarlar okunamazsa güvenli tarafta kal
    }

    $remaining = $total > 0 ? max(0, $total - $used) : null; // null = limitsiz (total 0)
    $limitOk = !($total > 0 && $used >= $total);
    $canSend = $systemEnabled && $notificationsEnabled && $limitOk;

    $warning = null;
    if (!$systemEnabled) {
        $warning = 'SMS sistemi şu anda bakımdadır. SMS gönderilemez.';
    } elseif (!$notificationsEnabled) {
        $warning = 'SMS bildirimleri kapalı. Ayarlar → Randevu ayarlarından SMS\'i aktif edin.';
    } elseif (!$limitOk) {
        $warning = 'SMS paketiniz tükenmiş. Yeni SMS gönderilemez; paket yenileyin veya yöneticinizle iletişime geçin.';
    }

    $sendOnCreateWarning = null;
    if ($canSend && !$sendOnCreate) {
        $sendOnCreateWarning = 'Randevu oluşturma SMS\'i ayarlardan kapalı. Ayarlar → Randevu ayarlarından "Randevu oluşturulunca SMS gönder" seçeneğini açın.';
    }

    return [
        'system_enabled' => $systemEnabled,
        'notifications_enabled' => $notificationsEnabled,
        'send_on_create' => $sendOnCreate,
        'total' => $total,
        'used' => $used,
        'remaining' => $remaining === null ? 0 : $remaining,
        'limitless' => $total <= 0,
        'can_send' => $canSend,
        'warning' => $warning,
        'send_on_create_warning' => $sendOnCreateWarning,
        'settings_url' => '/admin/settings/randevu.php',
    ];
}

/**
 * Kullanıcı SMS istediğinde API yanıtına eklenecek meta.
 *
 * @param bool $userWanted Kullanıcı SMS seçeneğini açtı mı
 * @param array|null $smsResult sendSms() sonucu (gönderildiyse)
 * @param string|null $skipReason 'no_phone' | 'send_on_create_off' | 'not_attempted' | null
 */
function buildSmsAttemptMeta($userWanted, $smsResult = null, $skipReason = null) {
    $meta = [
        'sms_requested' => (bool) $userWanted,
        'sms_sent' => false,
        'sms_warning' => null,
    ];

    if (!$userWanted) {
        return $meta;
    }

    if ($skipReason === 'no_phone') {
        $meta['sms_warning'] = 'SMS gönderilemedi: müşteri telefon numarası yok.';
        return $meta;
    }

    if ($skipReason === 'send_on_create_off') {
        $meta['sms_warning'] = 'SMS gönderilmedi: Ayarlar → Randevu ayarlarından "Randevu oluşturulunca SMS gönder" seçeneğini açın.';
        return $meta;
    }

    if (is_array($smsResult) && !empty($smsResult['status'])) {
        $meta['sms_sent'] = true;
        return $meta;
    }

    $raw = is_array($smsResult) ? trim((string) ($smsResult['message'] ?? '')) : '';
    if ($raw === '') {
        $cap = getTenantSmsCapability();
        $meta['sms_warning'] = $cap['warning']
            ?: ($cap['send_on_create_warning']
            ?: 'SMS gönderilemedi. Ayarlar → Randevu ayarlarından SMS ayarlarını kontrol edin.');
        return $meta;
    }

    // Bilinen mesajları daha net aksiyon cümlesine çevir
    if (stripos($raw, 'kapalı') !== false || stripos($raw, 'aktif ediniz') !== false) {
        $meta['sms_warning'] = 'SMS gönderilmedi: SMS bildirimleri kapalı. Ayarlar → Randevu ayarlarından SMS\'i aktif edin.';
    } elseif (!empty($smsResult['limit_reached']) || stripos($raw, 'tüken') !== false) {
        $meta['sms_warning'] = 'SMS gönderilmedi: SMS paketiniz tükenmiş. Paket yenileyin veya yöneticinizle iletişime geçin.';
    } elseif (stripos($raw, 'bakım') !== false) {
        $meta['sms_warning'] = 'SMS gönderilmedi: SMS sistemi şu anda bakımdadır.';
    } else {
        $meta['sms_warning'] = 'SMS gönderilmedi: ' . $raw;
    }

    return $meta;
}

// ==========================================================================
// 🔐 9. SAYFA ERİŞİM KONTROLÜ (Admin Panel için)
// ==========================================================================

/**
 * Kullanıcının belirli bir sayfaya erişim yetkisi olup olmadığını kontrol eder
 * NOT: Bu fonksiyon header.php'den ÖNCE çağrıldığı için config.php'de tanımlı olmalı
 */
function can_access_page($page_filename) {
    try {
        // Sidebar menü tanımı
        $sidebar_menu_items = [
            'index.php' => ['icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
            'daily_view.php' => ['icon' => 'fa-calendar-check', 'label' => 'Günlük Takip'],
            'appointments.php' => ['icon' => 'fa-calendar-alt', 'label' => 'Randevular'],
            'revenues.php' => ['icon' => 'fa-chart-line', 'label' => 'Gelir Raporu'],
            'bekleyen_odemeler.php' => ['icon' => 'fa-exclamation-circle', 'label' => 'Bekleyen Ödemeler'],
            'expenses.php' => ['icon' => 'fa-wallet', 'label' => 'Giderler'],
            'customers.php' => ['icon' => 'fa-users', 'label' => 'Müşteriler'],
            'reviews.php' => ['icon' => 'fa-star', 'label' => 'Değerlendirmeler'],
            'campaigns.php' => ['icon' => 'fa-bullhorn', 'label' => 'Kampanyalar'],
            'employees.php' => ['icon' => 'fa-user-tie', 'label' => 'Çalışanlar', 'restricted_access' => true],
            'employee_salaries.php' => ['icon' => 'fa-money-bill-wave', 'label' => 'Maaş Yönetimi', 'restricted_access' => true],
            'tasks.php' => ['icon' => 'fa-clipboard-list', 'label' => 'Görevler', 'open_access' => true],
            'services.php' => ['icon' => 'fa-cut', 'label' => 'Hizmetler'],
            'products.php' => ['icon' => 'fa-box-open', 'label' => 'Ürün & Stok'],
            'product_revenues.php' => ['icon' => 'fa-chart-bar', 'label' => 'Satış Gelirleri'],
            'gallery.php' => ['icon' => 'fa-images', 'label' => 'Galeri Yönetimi'],
            'vehicles.php' => ['icon' => 'fa-motorcycle',     'label' => 'Araç Kayıtları'],
            'service_orders.php' => ['icon' => 'fa-clipboard-list', 'label' => 'İş Emirleri'],
            'servis_kabulleri.php' => ['icon' => 'fa-clipboard-list', 'label' => 'Servis Kabulleri'],
            'billing.php' => ['icon' => 'fa-credit-card', 'label' => 'Paket & Ödemeler', 'hidden_except_owner' => true],
            'upgrade_package.php' => ['icon' => 'fa-box-open', 'label' => 'Paket & SMS Satın Al', 'hidden_except_owner' => true],
            'settings.php' => ['icon' => 'fa-cog', 'label' => 'Site Ayarları', 'restricted_access' => true],
            'trash.php' => ['icon' => 'fa-trash-alt', 'label' => 'Çöp Kutusu'],
        ];

        if (!isset($sidebar_menu_items[$page_filename])) {
            // Bilinen ama menüde olmayan sayfalar (emergency, profile vb.) — INFO spam üretme
            return false;
        }

        $user_role = $_SESSION['role'] ?? 'employee';
        $allowed_pages_string = $_SESSION['allowed_pages'] ?? '';
        $allowed_pages_array = [];
        
        if ($user_role === 'employee' || $user_role === 'manager') {
            if (!empty($allowed_pages_string) && $allowed_pages_string !== 'all') {
                $allowed_pages_array = explode(',', $allowed_pages_string);

            }
        }

        // 1. OWNER (PATRON) - TAM YETKİ
        if ($user_role === 'owner' || $user_role === 'admin') {
            return true;
        }

        // 2. TAMAMEN GİZLİ SAYFALAR (Sadece Billing) - OWNER HARİÇ KİMSE ERİŞEMEZ
        if (isset($sidebar_menu_items[$page_filename]['hidden_except_owner']) && $sidebar_menu_items[$page_filename]['hidden_except_owner'] === true) {
            return false;
        }

        // 2.5. HERKESE AÇIK SAYFALAR - İZİN GEREKTİRMEZ
        if (isset($sidebar_menu_items[$page_filename]['open_access']) && $sidebar_menu_items[$page_filename]['open_access'] === true) {
            return true;
        }

        // 3. KISITLI ERİŞİM SAYFALARI (Employees, Settings) - İZİN VERİLİRSE ERİŞİLEBİLİR
        if (isset($sidebar_menu_items[$page_filename]['restricted_access']) && $sidebar_menu_items[$page_filename]['restricted_access'] === true) {
            $has_access = in_array($page_filename, $allowed_pages_array);
            Logger::info("can_access_page: Kısıtlı sayfa kontrolü", [
                'page' => $page_filename,
                'has_access' => $has_access
            ]);
            return $has_access;
        }

        // 4. MANAGER - KISITLI HARİÇ HER ŞEYE ERİŞİM
        if ($user_role === 'manager') {
            return true;
        }

        // 5. EMPLOYEE - KISITLI ERİŞİM (allowed_pages listesine göre)
        if ($user_role === 'employee') {
            $equivalents = function_exists('admin_page_access_equivalents')
                ? (admin_page_access_equivalents()[$page_filename] ?? [$page_filename])
                : [$page_filename];
            $has_access = false;
            foreach ($equivalents as $candidate) {
                if (in_array($candidate, $allowed_pages_array, true)) {
                    $has_access = true;
                    break;
                }
            }
            Logger::info("can_access_page: Employee kontrolü", [
                'page' => $page_filename,
                'has_access' => $has_access,
                'allowed_array' => $allowed_pages_array,
                'equivalents' => $equivalents
            ]);
            return $has_access;
        }
        
        return false;
    } catch (Exception $e) {
        Logger::error("can_access_page EXCEPTION", [
            'page' => $page_filename,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return false;
    }
}

/**
 * Kullanıcının erişebildiği ilk sayfayı bul (login sonrası yönlendirme için)
 * NOT: Session zaten set edilmiş olmalı (login.php'de kullanılıyor)
 */
function get_first_accessible_page() {
    try {
        // Session kontrolü
        if (!isset($_SESSION['role'])) {
            Logger::warning("get_first_accessible_page: Role bulunamadı");
            return 'index.php'; // Fallback
        }
        
        // Owner için direkt index.php dön (optimizasyon)
        if ($_SESSION['role'] === 'owner' || $_SESSION['role'] === 'admin') {
            return 'index.php';
        }
        
        Logger::info("get_first_accessible_page başladı", [
            'role' => $_SESSION['role'],
            'allowed_pages' => $_SESSION['allowed_pages'] ?? 'YOK'
        ]);
        
        // Menü sırası (öncelik sırasına göre)
        $menu_order = [
            'index.php',
            'daily_view.php',
            'appointments.php',
            'revenues.php',
            'expenses.php',
            'customers.php',
            'tasks.php',
            'services.php',
            'gallery.php',
            'employees.php',
            'settings.php',
            'billing.php'
        ];
        if (function_exists('is_vehicle_service_category') && is_vehicle_service_category()) {
            $menu_order = [
                'index.php',
                'servis_kabulleri.php',
                'service_orders.php',
                'vehicles.php',
                'revenues.php',
                'expenses.php',
                'tasks.php',
                'services.php',
                'employees.php',
                'settings.php',
                'billing.php'
            ];
        }
        
        foreach ($menu_order as $page) {
            try {
                $has_access = can_access_page($page);
                Logger::info("Sayfa kontrolü", ['page' => $page, 'access' => $has_access]);
                if ($has_access) {
                    // Eski izinlerle gelen çalışanlarda canonical URL'ye yönlendir
                    if (function_exists('is_vehicle_service_category') && is_vehicle_service_category()) {
                        if ($page === 'daily_view.php') {
                            $page = 'servis_kabulleri.php';
                        } elseif ($page === 'appointments.php') {
                            $page = 'service_orders.php';
                        } elseif ($page === 'customers.php') {
                            $page = 'vehicles.php';
                        }
                    }
                    Logger::info("İlk erişilebilir sayfa bulundu", ['page' => $page]);
                    return $page;
                }
            } catch (Exception $e) {
                Logger::error("can_access_page hatası", ['page' => $page, 'error' => $e->getMessage()]);
            }
        }
        
        // Hiçbir sayfaya erişim yoksa
        Logger::warning("Hiçbir erişilebilir sayfa bulunamadı");
        
        return null;
    } catch (Exception $e) {
        Logger::error("get_first_accessible_page FATAL", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return 'index.php'; // Fallback
    }
}

// ── PAKET SÜRESİ OTOMATİK KONTROLÜ (admin/ajax ve admin/api endpoint'leri) ──
$_request_path = $_SERVER['REQUEST_URI'] ?? '';
if (
    defined('TENANT_ID') &&
    isset($db) &&
    (strpos($_request_path, '/admin/ajax/') !== false || strpos($_request_path, '/admin/api/') !== false)
) {
    enforcePackageExpiry($db);
    
    // ── MERKEZİ CSRF KORUMASI (POST/PUT/DELETE istekleri) ──
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
        // Login endpoint'i CSRF'ten muaf (henüz session yok)
        $csrf_exempt = ['/admin/api/auth/login'];
        $is_exempt = false;
        foreach ($csrf_exempt as $path) {
            if (strpos($_request_path, $path) !== false) {
                $is_exempt = true;
                break;
            }
        }
        if (!$is_exempt) {
            Csrf::verifyAjaxOrDie();
        }
    }
}
?>