<?php
// Hata raporlamayı aç
ini_set('display_errors', 1);
error_reporting(E_ALL);

// config dosyasını çağır
$config_path = __DIR__ . '/config/config.php';
if (!file_exists($config_path)) {
    die("Kritik Hata: 'config/config.php' dosyası bulunamadı.");
}
require_once $config_path;

if (!function_exists('sanitize')) {
    die("Kritik Hata: 'sanitize()' fonksiyonu bulunamadı.");
}
if (!isset($db) || !$db instanceof PDO) {
    die("Kritik Hata: Veritabanı bağlantısı kurulamadı.");
}

// --- SUBDOMAIN KONTROLÜ - HEADER/FOOTER SEÇİMİ ---
$current_host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
$current_host = preg_replace('/:\\d+$/', '', $current_host);
$subdomain = explode('.', $current_host)[0];
$master_domains = ['www', 'randu', 'app', 'panel', 'localhost'];
$is_master_domain = in_array($subdomain, $master_domains);

// Header/Footer path belirleme
if ($is_master_domain) {
    $header_path = __DIR__ . '/landing/includes/header.php';
    $footer_path = __DIR__ . '/landing/includes/footer.php';
} else {
    $header_path = __DIR__ . '/includes/header.php';
    $footer_path = __DIR__ . '/includes/footer.php';
}

// --- TENANT KONTROLÜ ---
// URL'den tenant_id gelmişse kullan, yoksa subdomain'den çek
if (!$is_master_domain) {
    // Subdomain'de tenant_id override'a izin verme: güvenlik + SEO için subdomain tenant'ını zorunlu kıl
    try {
        $stmt_sub = $db->prepare("\n            SELECT id, domain, tenant_code, slug\n            FROM tenants\n            WHERE is_active = 1\n              AND (LOWER(domain) = ? OR LOWER(COALESCE(NULLIF(slug, ''), '')) = ? OR LOWER(COALESCE(NULLIF(tenant_code, ''), '')) = ?)\n            ORDER BY CASE\n                WHEN LOWER(domain) = ? THEN 1\n                WHEN LOWER(COALESCE(NULLIF(slug, ''), '')) = ? THEN 2\n                WHEN LOWER(COALESCE(NULLIF(tenant_code, ''), '')) = ? THEN 3\n                ELSE 99\n            END ASC, id ASC\n            LIMIT 1\n        ");
        $stmt_sub->execute([$subdomain, $subdomain, $subdomain, $subdomain, $subdomain, $subdomain]);
        $tenant_from_subdomain = $stmt_sub->fetch(PDO::FETCH_ASSOC);

        if (!$tenant_from_subdomain) {
            die("Hata: Bu subdomain'e ait aktif bir işletme bulunamadı.");
        }

        $subdomain_tenant_id = (int)$tenant_from_subdomain['id'];

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['tenant_id']) && intval($_GET['tenant_id']) > 0) {
            $requested_tenant_id = (int)$_GET['tenant_id'];

            // Subdomain tenant'ı ile URL tenant_id farklıysa doğru işletme domainine canonical 301 at
            if ($requested_tenant_id !== $subdomain_tenant_id) {
                $stmt_req = $db->prepare("SELECT id, domain, slug, tenant_code, is_active FROM tenants WHERE id = ? LIMIT 1");
                $stmt_req->execute([$requested_tenant_id]);
                $requested_tenant = $stmt_req->fetch(PDO::FETCH_ASSOC);

                if ($requested_tenant && (int)$requested_tenant['is_active'] === 1) {
                    $canonicalSub = trim((string)($requested_tenant['domain'] ?? ''));
                    if ($canonicalSub === '') {
                        $canonicalSub = trim((string)($requested_tenant['slug'] ?? ''));
                    }
                    if ($canonicalSub === '') {
                        $canonicalSub = trim((string)($requested_tenant['tenant_code'] ?? ''));
                    }
                    if ($canonicalSub !== '') {
                        $target_url = 'https://' . $canonicalSub . '.randu.tr/appointment';
                    } else {
                        $target_url = 'https://randu.tr/appointment?tenant_id=' . $requested_tenant_id;
                    }
                } else {
                    $target_url = 'https://randu.tr/appointment?tenant_id=' . $requested_tenant_id;
                }

                header('Location: ' . $target_url, true, 301);
                exit;
            }

            // Subdomain'de tenant_id parametresini temizleyip tek canonical URL'ye indir
            $query_params = $_GET;
            unset($query_params['tenant_id']);
            $canonical_subdomain_url = 'https://' . $subdomain . '.randu.tr/appointment';
            if (!empty($query_params)) {
                $canonical_subdomain_url .= '?' . http_build_query($query_params);
            }
            header('Location: ' . $canonical_subdomain_url, true, 301);
            exit;
        }

        $requested_tenant_id = $subdomain_tenant_id;
    } catch (Exception $e) {
        die("Hata: İşletme kimliği doğrulanamadı.");
    }
} else {
    if (isset($_GET['tenant_id']) && intval($_GET['tenant_id']) > 0) {
        $requested_tenant_id = intval($_GET['tenant_id']);
    } else {
        // Master domain'de tenant_id zorunlu
        die("Hata: İşletme kimliği belirtilmedi.");
    }
}

// Tenant'ı doğrula ve bilgilerini çek
try {
    $stmt_tenant = $db->prepare("SELECT id, firma_adi, domain, telefon, il, ilce FROM tenants WHERE id = ? AND is_active = 1");
    $stmt_tenant->execute([$requested_tenant_id]);
    $tenant_info = $stmt_tenant->fetch(PDO::FETCH_ASSOC);
    
    if (!$tenant_info) {
        die("Hata: İşletme bulunamadı veya aktif değil.");
    }
    
    // APPOINTMENT_TENANT_ID'yi tanımla
    define('APPOINTMENT_TENANT_ID', $tenant_info['id']);
    
    // Geriye dönük uyumluluk için TENANT_ID'yi de tanımla (sendSms() fonksiyonu için gerekli)
    if (!defined('TENANT_ID')) {
        define('TENANT_ID', $tenant_info['id']);
    }
} catch (Exception $e) {
    die("Hata: İşletme bilgileri alınamadı.");
}

// Oto yıkama / motosiklet: klasik randevu yerine talep formuna yönlendir
$appt_cat = defined('TENANT_CATEGORY') ? (string) TENANT_CATEGORY : '';
if ($appt_cat === '') {
    try {
        $stmt_cat = $db->prepare("SELECT kategori FROM tenants WHERE id = ? LIMIT 1");
        $stmt_cat->execute([APPOINTMENT_TENANT_ID]);
        $appt_cat = trim(explode(',', (string) $stmt_cat->fetchColumn())[0]);
    } catch (Exception $e) {
        $appt_cat = '';
    }
}
if (function_exists('is_vehicle_service_category') && is_vehicle_service_category($appt_cat)) {
    $talep_url = $is_master_domain
        ? ('on-bakim-talebi.php?tenant_id=' . (int) APPOINTMENT_TENANT_ID)
        : 'on-bakim-talebi';
    header('Location: ' . $talep_url, true, 302);
    exit;
}

$page_title = 'Randevu Al - ' . $tenant_info['firma_adi'];
$success = '';
$error = '';

// 1. AYARLARI ÇEK
try {
    $stmt_settings = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE tenant_id = ?");
    $stmt_settings->execute([APPOINTMENT_TENANT_ID]);
    $site_settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);
} catch(Exception $e) {
    $site_settings = [];
}

$show_service_duration = ($site_settings['show_service_duration'] ?? 'on') === 'on';
$max_booking_days = intval($site_settings['max_booking_days'] ?? 90);

$business_day_overrides = [];
try {
    $range_start = date('Y-m-d');
    $range_end = date('Y-m-d', strtotime('+' . $max_booking_days . ' day'));
    // Takvimde gunun secilebilirligini belirlerken:
    // - business_closed: sadece isletme seviyesi (employee_id IS NULL)
    // - custom_hours: isletme seviyesi VEYA calisan seviyesi varsa gun acik sayilmali
    $stmt_day_flags = $db->prepare("\n        SELECT exception_date, exception_type, employee_id\n        FROM schedule_exceptions\n        WHERE tenant_id = ?\n          AND exception_date BETWEEN ? AND ?\n          AND exception_type IN ('business_closed','custom_hours')\n    ");
    $stmt_day_flags->execute([APPOINTMENT_TENANT_ID, $range_start, $range_end]);
    foreach ($stmt_day_flags->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = $row['exception_date'] ?? '';
        if (!$d) continue;
        if (!isset($business_day_overrides[$d])) {
            $business_day_overrides[$d] = ['business_closed' => false, 'custom_hours' => false];
        }
        if ($row['exception_type'] === 'business_closed' && $row['employee_id'] === null) {
            $business_day_overrides[$d]['business_closed'] = true;
        }
        if ($row['exception_type'] === 'custom_hours') {
            $business_day_overrides[$d]['custom_hours'] = true;
        }
    }
} catch (Exception $e) {
    $business_day_overrides = [];
}

function _timeRangeForDate(string $date, string $start, string $end): array {
    $startTs = strtotime($date . ' ' . $start);
    $endTs = strtotime($date . ' ' . $end);
    if ($endTs <= $startTs) {
        $endTs = strtotime('+1 day', $endTs);
    }
    return ['start' => $startTs, 'end' => $endTs];
}

function _buildBusinessAvailabilityContext(PDO $db, int $tenantId, string $date, array $siteSettings): array {
    static $cache = [];
    $cacheKey = $tenantId . '|' . $date;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $dayOfWeek = (int)date('N', strtotime($date));
    $schedule = json_decode($siteSettings['working_hours_schedule'] ?? '{}', true);
    $daySchedule = is_array($schedule) && isset($schedule[$dayOfWeek]) ? $schedule[$dayOfWeek] : null;

    $isOpen = $daySchedule ? !empty($daySchedule['open']) : ($dayOfWeek !== 7);
    if (!$isOpen) {
        return $cache[$cacheKey] = ['closed' => true, 'windows' => [], 'blocks' => []];
    }

    $start = $daySchedule['start'] ?? '09:00';
    $end = $daySchedule['end'] ?? '18:00';
    $defaultWindow = _timeRangeForDate($date, $start, $end);

    $customWindows = [];
    $blocks = [];

    try {
        $stmt = $db->prepare("SELECT exception_type, custom_start, custom_end, block_start, block_end FROM schedule_exceptions WHERE tenant_id = ? AND exception_date = ? AND employee_id IS NULL");
        $stmt->execute([$tenantId, $date]);
        $exceptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($exceptions as $ex) {
            if ($ex['exception_type'] === 'business_closed') {
                return $cache[$cacheKey] = ['closed' => true, 'windows' => [], 'blocks' => []];
            }
            if ($ex['exception_type'] === 'custom_hours' && !empty($ex['custom_start']) && !empty($ex['custom_end'])) {
                $customWindows[] = _timeRangeForDate($date, $ex['custom_start'], $ex['custom_end']);
            }
            if ($ex['exception_type'] === 'time_block' && !empty($ex['block_start']) && !empty($ex['block_end'])) {
                $blocks[] = _timeRangeForDate($date, $ex['block_start'], $ex['block_end']);
            }
        }
    } catch (Exception $e) {
        // schedule_exceptions tablosu olmayan kurulumlarda sessizce devam et.
    }

    $windows = !empty($customWindows) ? $customWindows : [$defaultWindow];
    return $cache[$cacheKey] = ['closed' => false, 'windows' => $windows, 'blocks' => $blocks];
}

function _getEmployeeAvailabilityContext(PDO $db, int $tenantId, int $employeeId, string $date, array $businessCtx): ?array {
    static $employeeCache = [];
    $empKey = $tenantId . '|' . $employeeId;
    if (!isset($employeeCache[$empKey])) {
        $stmtEmp = $db->prepare("SELECT id, working_days, working_hours_start, working_hours_end FROM employees WHERE id = ? AND tenant_id = ? AND status = 'active' AND deleted_at IS NULL AND hide_from_booking = 0");
        $stmtEmp->execute([$employeeId, $tenantId]);
        $empData = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: null;
        // NULL saatleri fallback ile doldur
        if ($empData && empty($empData['working_hours_start'])) {
            $empData['working_hours_start'] = '09:00';
        }
        if ($empData && empty($empData['working_hours_end'])) {
            $empData['working_hours_end'] = '18:00';
        }
        $employeeCache[$empKey] = $empData;
    }

    $employee = $employeeCache[$empKey];
    if (!$employee || !empty($businessCtx['closed'])) {
        return null;
    }

    $dayOfWeek = (int)date('N', strtotime($date));

    $empCustomWindows = [];
    $empBlocks = [];
    try {
        $stmtEx = $db->prepare("SELECT exception_type, custom_start, custom_end, block_start, block_end FROM schedule_exceptions WHERE tenant_id = ? AND employee_id = ? AND exception_date = ?");
        $stmtEx->execute([$tenantId, $employeeId, $date]);
        $empExceptions = $stmtEx->fetchAll(PDO::FETCH_ASSOC);

        foreach ($empExceptions as $ex) {
            if ($ex['exception_type'] === 'day_off') {
                return null;
            }
            if ($ex['exception_type'] === 'custom_hours' && !empty($ex['custom_start']) && !empty($ex['custom_end'])) {
                $empCustomWindows[] = _timeRangeForDate($date, $ex['custom_start'], $ex['custom_end']);
            }
            if ($ex['exception_type'] === 'time_block' && !empty($ex['block_start']) && !empty($ex['block_end'])) {
                $empBlocks[] = _timeRangeForDate($date, $ex['block_start'], $ex['block_end']);
            }
        }
    } catch (Exception $e) {
        // schedule_exceptions tablosu olmayan kurulumlarda sessizce devam et.
    }

    $hasCustomException = !empty($empCustomWindows); // true = schedule_exceptions'dan geliyor

    if (empty($empCustomWindows)) {
        $workingDays = array_filter(array_map('intval', explode(',', (string)($employee['working_days'] ?? ''))));
        if (!in_array($dayOfWeek, $workingDays, true)) {
            return null;
        }

        // NULL/boş saatlere karşı kesin fallback
        $empStart = !empty($employee['working_hours_start']) ? $employee['working_hours_start'] : '09:00';
        $empEnd = !empty($employee['working_hours_end']) ? $employee['working_hours_end'] : '18:00';
        $empCustomWindows[] = _timeRangeForDate($date, $empStart, $empEnd);
    }

    $intersections = [];
    if ($hasCustomException) {
        // Çalışana özel saat istisnası tanımlanmışsa business saatleriyle kesişim yapma;
        // özel pencere işletme saatini geçersiz kılar (gece vardiyası, erken/geç mesai vb.)
        $intersections = $empCustomWindows;
    } else {
        foreach ($empCustomWindows as $ew) {
            foreach ($businessCtx['windows'] as $bw) {
                $s = max($ew['start'], $bw['start']);
                $e = min($ew['end'], $bw['end']);
                if ($e > $s) {
                    $intersections[] = ['start' => $s, 'end' => $e];
                }
            }
        }
    }

    if (empty($intersections)) {
        return null;
    }

    return [
        'windows' => $intersections,
        'blocks' => array_merge($businessCtx['blocks'], $empBlocks)
    ];
}

function _isSlotInsideAvailability(array $availabilityCtx, int $startTs, int $endTs): bool {
    $fitsWindow = false;
    foreach ($availabilityCtx['windows'] as $w) {
        if ($startTs >= $w['start'] && $endTs <= $w['end']) {
            $fitsWindow = true;
            break;
        }
    }

    if (!$fitsWindow) {
        return false;
    }

    foreach ($availabilityCtx['blocks'] as $b) {
        if ($b['start'] < $endTs && $b['end'] > $startTs) {
            return false;
        }
    }

    return true;
}

// 2. VERİLERİ ÇEKME
try {
    // Kategorileri çek
    $stmt_categories = $db->prepare("SELECT id, name, sort_order FROM service_categories WHERE tenant_id = ? ORDER BY sort_order ASC, name ASC");
    $stmt_categories->execute([APPOINTMENT_TENANT_ID]);
    $categories = $stmt_categories->fetchAll(PDO::FETCH_ASSOC);

    // Hizmetler (varyant bilgisiyle birlikte)
    $stmt_services = $db->prepare("SELECT s.id, s.name, s.description, s.price, s.duration, s.pricing_type, s.price_min, s.price_max, s.is_multiple, s.category_id, s.image FROM services s WHERE s.tenant_id = ? AND s.status = 'active' AND s.deleted_at IS NULL AND EXISTS (SELECT 1 FROM employee_services es INNER JOIN employees e ON e.id = es.employee_id WHERE es.service_id = s.id AND e.tenant_id = s.tenant_id AND e.status = 'active' AND e.deleted_at IS NULL AND e.hide_from_booking = 0) ORDER BY s.name");
    $stmt_services->execute([APPOINTMENT_TENANT_ID]);
    $services = $stmt_services->fetchAll(PDO::FETCH_ASSOC);
    
    // Her hizmetin varyantlarını çek
    $stmt_variants = $db->prepare("SELECT id, service_id, name, extra_price, extra_duration FROM service_variants WHERE tenant_id = ? AND status = 'active' ORDER BY sort_order ASC");
    $stmt_variants->execute([APPOINTMENT_TENANT_ID]);
    $all_variants = $stmt_variants->fetchAll(PDO::FETCH_ASSOC);
    
    // Varyantları hizmetlere eşle
    $variants_by_service = [];
    foreach ($all_variants as $v) {
        $variants_by_service[$v['service_id']][] = [
            'id' => (int)$v['id'],
            'name' => $v['name'],
            'extra_price' => (float)$v['extra_price'],
            'extra_duration' => (int)$v['extra_duration']
        ];
    }
    foreach ($services as &$svc) {
        $svc['pricing_type'] = $svc['pricing_type'] ?? 'fixed';
        $svc['price_min'] = $svc['price_min'] !== null ? (float)$svc['price_min'] : null;
        $svc['price_max'] = $svc['price_max'] !== null ? (float)$svc['price_max'] : null;
        $svc['is_multiple'] = (int)($svc['is_multiple'] ?? 0);
        $svc['variants'] = $variants_by_service[$svc['id']] ?? [];
    }
    unset($svc);

    // Çalışanlar
    $stmt_employees = $db->prepare("SELECT id, name, photo, working_days FROM employees WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL AND hide_from_booking = 0 ORDER BY name");
    $stmt_employees->execute([APPOINTMENT_TENANT_ID]);
    $employees = $stmt_employees->fetchAll(PDO::FETCH_ASSOC);

    // Çalışan Resimlerini Düzenle - R2 CDN desteği
    foreach ($employees as &$employee) {
        if (!empty($employee['photo'])) {
            $imgSrc = $employee['photo'];
            if (strpos($imgSrc, 'http') === 0) {
                // Zaten CDN URL
                $employee['image'] = $imgSrc;
            } elseif (strpos($imgSrc, 'assets/') === 0) {
                $employee['image'] = $imgSrc;
            } else {
                $employee['image'] = 'assets/uploads/employees/' . $imgSrc;
            }
        } else {
            $initials = mb_substr($employee['name'], 0, 1) . mb_substr(explode(' ', $employee['name'])[1] ?? '', 0, 1);
            $employee['image'] = "https://placehold.co/100x100/EFEFEF/333?text=" . urlencode($initials);
        }
    }
    unset($employee);
} catch(Exception $e) {
    $services = [];
    $employees = [];
    $error = "Veriler yüklenirken hata oluştu.";
}

// 3. FORM İŞLEMLERİ (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['customer_name'])) {

    // Rate limiting: aynı IP'den 5 dakikada max 10 randevu denemesi
    if (class_exists('RateLimiter') && !RateLimiter::check('appointment_submit', 10, 300)) {
        $error = 'Çok fazla istek gönderildi. Lütfen birkaç dakika bekleyip tekrar deneyin.';
    } else {
    $customer_name = sanitizeName($_POST['customer_name'] ?? '', 50);
    
    $customer_phone_raw = $_POST['customer_phone'] ?? '';
    $customer_phone = preg_replace('/\D/', '', $customer_phone_raw);
    
    // Telefon formatını normalize et: Başındaki 0'ı kaldır (5551234567 formatında sakla)
    if (strlen($customer_phone) == 11 && substr($customer_phone, 0, 1) == '0') {
        $customer_phone = substr($customer_phone, 1);
    }
    
    $customer_email = normalizeEmail(sanitize($_POST['customer_email'] ?? ''));
    if ($customer_email === false) $customer_email = '';
    $notes = sanitizeNotes($_POST['notes'] ?? '');
    
    // --- SEPET VERİSİ PARSE ---
    $cart_data_raw = $_POST['cart_data'] ?? '';
    $cart_data = !empty($cart_data_raw) ? json_decode($cart_data_raw, true) : null;
    $is_cart_mode = is_array($cart_data) && !empty($cart_data['items']);
    
    // Sepet modunda: cart'tan items al; Yoksa eski tek randevu modunda oluştur
    $cart_items = [];
    if ($is_cart_mode) {
        // booking_group_id her zaman server'da üretilir — client değerine güvenilmez
        $booking_group_id = count($cart_data['items']) > 1 ? sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000, mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)) : null;
        foreach ($cart_data['items'] as $ci) {
            $cart_items[] = [
                'employee_id' => ($ci['employee_id'] === 'any' || $ci['employee_id'] === '') ? null : intval($ci['employee_id']),
                'service_ids' => !empty($ci['service_ids']) ? explode(',', $ci['service_ids']) : [],
                'variant_data' => is_array($ci['variant_data'] ?? null) ? $ci['variant_data'] : [],
                'appointment_date' => sanitize($ci['appointment_date'] ?? ''),
                'appointment_time' => sanitize($ci['appointment_time'] ?? ''),
            ];
        }
    } else {
        // Geriye uyumluluk: Eski tek randevu form alanlarından oluştur
        $variant_data_raw = $_POST['variant_data'] ?? '{}';
        $variant_data = json_decode($variant_data_raw, true);
        if (!is_array($variant_data)) $variant_data = [];
        
        $booking_group_id = null;
        $cart_items[] = [
            'employee_id' => ($_POST['employee_id'] === 'any' || $_POST['employee_id'] === '') ? null : intval($_POST['employee_id']),
            'service_ids' => isset($_POST['service_ids']) ? explode(',', $_POST['service_ids']) : [],
            'variant_data' => $variant_data,
            'appointment_date' => sanitize($_POST['appointment_date'] ?? ''),
            'appointment_time' => sanitize($_POST['appointment_time'] ?? ''),
        ];
    }

    // Validasyon: Her öğede zorunlu alanlar kontrol
    $validation_ok = !empty($customer_name) && !empty($customer_phone) && strlen($customer_phone) >= 10;
    if ($validation_ok) {
        foreach ($cart_items as $ci) {
            if (empty($ci['service_ids']) || empty($ci['appointment_date']) || empty($ci['appointment_time'])) {
                $validation_ok = false;
                break;
            }
        }
    }

    if (!$validation_ok) {
        $error = 'Lütfen tüm zorunlu alanları doldurunuz.';
    } else {
        try {
            $db->beginTransaction();

            // --- PLATFORM MODELİ MÜŞTERİ KONTROLÜ ---
            // 1. Telefon numarası sistemde (tüm işletmelerde) var mı?
            $stmt = $db->prepare("SELECT id FROM customers WHERE phone = ?");
            $stmt->execute([$customer_phone]);
            $customer = $stmt->fetch();
            
            if ($customer) {
                // A) Müşteri Zaten Var -> ID'sini al
                $customer_id = $customer['id'];
                
                // İsteğe bağlı: İsmi güncellemek istersen (Dikkat: Başka yerdeki ismini de değiştirir)
                // $stmt = $db->prepare("UPDATE customers SET name = ? WHERE id = ?"); ...
            } else {
                // B) Müşteri Yok -> Yeni "Misafir" Oluştur
                // tenant_id NULL, is_guest = 1
                $stmt = $db->prepare("INSERT INTO customers (tenant_id, name, phone, email, is_guest) VALUES (NULL, ?, ?, ?, 1)");
                $stmt->execute([$customer_name, $customer_phone, $customer_email]);
                $customer_id = $db->lastInsertId();
            }

            // 2. İLİŞKİ KONTROLÜ: Bu müşteri bu dükkanla (tenant) ilişkili mi?
            $stmt_link = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE customer_id = customer_id");
            $stmt_link->execute([$customer_id, APPOINTMENT_TENANT_ID, $customer_name]);
            // -----------------------------------------

            // --- SEPET ÖĞELERİNİ İŞLE ---
            // Hizmet süresi kontrol ayarı
            $enforce_service_duration = ($site_settings['enforce_service_duration'] ?? 'on') === 'on';
            $slot_duration = intval($site_settings['appointment_duration'] ?? 30);

            // booking_group_id: auto-migration (kolon yoksa ekle)
            try {
                $db->query("SELECT booking_group_id FROM appointments LIMIT 0");
            } catch (Exception $migEx) {
                $db->exec("ALTER TABLE appointments ADD COLUMN booking_group_id VARCHAR(36) NULL DEFAULT NULL AFTER tenant_id");
                $db->exec("ALTER TABLE appointments ADD INDEX idx_booking_group (booking_group_id)");
            }

            // Her sepet öğesi için: fiyat hesapla, çakışma kontrol et, çalışan ata
            $processed_items = [];
            foreach ($cart_items as $ci_index => $ci) {
                $item_employee_id = $ci['employee_id'];
                $item_service_ids = array_filter(array_map('intval', $ci['service_ids']));
                $item_variant_data = $ci['variant_data'];
                $item_date = $ci['appointment_date'];
                $item_time = $ci['appointment_time'];

                // Fiyat ve Süre Hesaplama
                $item_total_price = 0;
                $item_total_duration = 0;
                $item_service_details = [];
                if (!empty($item_service_ids)) {
                    $placeholders = implode(',', array_fill(0, count($item_service_ids), '?'));
                    $params = array_merge([APPOINTMENT_TENANT_ID], $item_service_ids);
                    $stmt_s = $db->prepare("SELECT id, price, duration, pricing_type, price_min FROM services WHERE tenant_id = ? AND id IN ($placeholders)");
                    $stmt_s->execute($params);
                    $s_data = $stmt_s->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($s_data as $s) {
                        $base_price = ($s['pricing_type'] === 'range' && $s['price_min'] !== null) ? (float)$s['price_min'] : (float)$s['price'];
                        $base_duration = (int)$s['duration'];
                        $extra_price = 0;
                        $extra_duration = 0;
                        $selected_variant_ids = [];
                        
                        if (isset($item_variant_data[$s['id']]) && is_array($item_variant_data[$s['id']]) && !empty($item_variant_data[$s['id']])) {
                            $selected_variant_ids = array_map('intval', $item_variant_data[$s['id']]);
                            $v_placeholders = implode(',', array_fill(0, count($selected_variant_ids), '?'));
                            $stmt_v = $db->prepare("SELECT id, extra_price, extra_duration FROM service_variants WHERE id IN ($v_placeholders) AND service_id = ? AND tenant_id = ? AND status = 'active'");
                            $v_params = array_merge($selected_variant_ids, [$s['id'], APPOINTMENT_TENANT_ID]);
                            $stmt_v->execute($v_params);
                            $valid_variants = $stmt_v->fetchAll(PDO::FETCH_ASSOC);
                            $selected_variant_ids = array_column($valid_variants, 'id');
                            foreach ($valid_variants as $vv) {
                                $extra_price += (float)$vv['extra_price'];
                                $extra_duration += (int)$vv['extra_duration'];
                            }
                        }
                        
                        $final_price = $base_price + $extra_price;
                        $final_duration = $base_duration + $extra_duration;
                        $item_total_price += $final_price;
                        $item_total_duration += $final_duration;
                        $item_service_details[$s['id']] = [
                            'variant_ids' => $selected_variant_ids,
                            'final_price' => $final_price,
                            'final_duration' => $final_duration
                        ];
                    }
                }

                // Çakışma kontrol verisi (her tarih için yeniden çek)
                // deleted_at IS NULL: get_available_times ile aynı — silinen randevu slotu bloklamasın
                $stmt_booked = $db->prepare("
                    SELECT employee_id, appointment_time, total_duration 
                    FROM appointments 
                    WHERE appointment_date = ? AND tenant_id = ? 
                    AND deleted_at IS NULL
                    AND status IN ('pending', 'confirmed', 'pending_verification')
                    AND NOT (status = 'pending_verification' AND verification_code_expires < NOW())
                ");
                $stmt_booked->execute([$item_date, APPOINTMENT_TENANT_ID]);
                $booked_slots_raw = $stmt_booked->fetchAll(PDO::FETCH_ASSOC);

                // Aynı sepetteki önceki öğeleri de çakışma verisine ekle
                foreach ($processed_items as $pi) {
                    if ($pi['appointment_date'] === $item_date) {
                        $booked_slots_raw[] = [
                            'employee_id' => $pi['employee_id'],
                            'appointment_time' => $pi['appointment_time'],
                            'total_duration' => $pi['total_duration']
                        ];
                    }
                }

                $booked_ranges = [];
                foreach ($booked_slots_raw as $slot) {
                    if (empty($slot['employee_id'])) continue;
                    $b_start = strtotime($item_date . ' ' . $slot['appointment_time']);
                    $b_dur = $enforce_service_duration && !empty($slot['total_duration']) 
                        ? $slot['total_duration'] 
                        : $slot_duration;
                    $b_end = $b_start + ($b_dur * 60);
                    $booked_ranges[$slot['employee_id']][] = ['start' => $b_start, 'end' => $b_end];
                }

                $new_start = strtotime($item_date . ' ' . $item_time);
                $new_dur = $enforce_service_duration && $item_total_duration > 0 
                    ? $item_total_duration 
                    : $slot_duration;
                $new_end = $new_start + ($new_dur * 60);

                $business_ctx = _buildBusinessAvailabilityContext($db, APPOINTMENT_TENANT_ID, $item_date, $site_settings);
                if (!empty($business_ctx['closed'])) {
                    throw new Exception('Sepet öğesi #' . ($ci_index + 1) . ': Bu tarih işletme çalışma takviminde kapalı görünüyor.');
                }

                // Çalışan Atama
                $assigned_employee_id = $item_employee_id;

                if ($assigned_employee_id === null) {
                    $day_of_week = date('N', strtotime($item_date));
                    $emp_query = "
                        SELECT DISTINCT e.id, e.working_days 
                        FROM employees e
                        WHERE e.tenant_id = ? AND e.status = 'active' AND e.deleted_at IS NULL AND e.hide_from_booking = 0
                        AND FIND_IN_SET(?, e.working_days) > 0
                    ";
                    $emp_params = [APPOINTMENT_TENANT_ID, $day_of_week];

                    if (!empty($item_service_ids)) {
                        $svc_placeholders = implode(',', array_fill(0, count($item_service_ids), '?'));
                        $svc_count = count($item_service_ids);
                        $emp_query .= " AND e.id IN (
                            SELECT es.employee_id FROM employee_services es 
                            WHERE es.tenant_id = ? AND es.service_id IN ($svc_placeholders)
                            GROUP BY es.employee_id
                            HAVING COUNT(DISTINCT es.service_id) = $svc_count
                        )";
                        $emp_params[] = APPOINTMENT_TENANT_ID;
                        $emp_params = array_merge($emp_params, $item_service_ids);
                    }
                    $emp_query .= " ORDER BY RAND()";

                    $stmt_all_emp = $db->prepare($emp_query);
                    $stmt_all_emp->execute($emp_params);
                    $all_employees = $stmt_all_emp->fetchAll(PDO::FETCH_COLUMN);

                    if (empty($all_employees)) {
                        throw new Exception('Sepet öğesi #' . ($ci_index + 1) . ': Seçilen hizmetleri verebilecek müsait bir çalışan bulunamadı.');
                    }

                    $assigned_employee_id = null;
                    foreach ($all_employees as $emp_id) {
                        $emp_availability = _getEmployeeAvailabilityContext($db, APPOINTMENT_TENANT_ID, (int)$emp_id, $item_date, $business_ctx);
                        if (!$emp_availability || !_isSlotInsideAvailability($emp_availability, $new_start, $new_end)) {
                            continue;
                        }

                        $emp_booked = $booked_ranges[$emp_id] ?? [];
                        $has_conflict = false;
                        foreach ($emp_booked as $range) {
                            if ($range['start'] < $new_end && $range['end'] > $new_start) {
                                $has_conflict = true;
                                break;
                            }
                        }
                        if (!$has_conflict) {
                            $assigned_employee_id = $emp_id;
                            break;
                        }
                    }

                    if ($assigned_employee_id === null) {
                        throw new Exception('Sepet öğesi #' . ($ci_index + 1) . ': Bu saatte tüm çalışanlarımız dolu. Lütfen farklı bir saat seçiniz.');
                    }
                } else {
                    $stmt_verify = $db->prepare("SELECT COUNT(*) FROM employees WHERE id = ? AND tenant_id = ? AND status = 'active' AND deleted_at IS NULL AND hide_from_booking = 0");
                    $stmt_verify->execute([$assigned_employee_id, APPOINTMENT_TENANT_ID]);
                    if ($stmt_verify->fetchColumn() == 0) throw new Exception('Geçersiz çalışan seçimi.');

                    $emp_availability = _getEmployeeAvailabilityContext($db, APPOINTMENT_TENANT_ID, (int)$assigned_employee_id, $item_date, $business_ctx);
                    if (!$emp_availability || !_isSlotInsideAvailability($emp_availability, $new_start, $new_end)) {
                        throw new Exception('Sepet öğesi #' . ($ci_index + 1) . ': Seçilen saat, çalışanın özel çalışma takvimine uygun değil. Lütfen farklı saat seçiniz.');
                    }

                    if (!empty($item_service_ids)) {
                        $svc_ph2 = implode(',', array_fill(0, count($item_service_ids), '?'));
                        $stmt_emp_svc = $db->prepare("SELECT service_id FROM employee_services WHERE tenant_id = ? AND employee_id = ? AND service_id IN ($svc_ph2)");
                        $stmt_emp_svc->execute(array_merge([APPOINTMENT_TENANT_ID, $assigned_employee_id], $item_service_ids));
                        $emp_provided_services = $stmt_emp_svc->fetchAll(PDO::FETCH_COLUMN);
                        $missing = array_diff($item_service_ids, $emp_provided_services);
                        if (!empty($missing)) {
                            throw new Exception('Seçilen çalışan bu hizmetlerden bazılarını veremiyor.');
                        }
                    }

                    $emp_booked = $booked_ranges[$assigned_employee_id] ?? [];
                    foreach ($emp_booked as $range) {
                        if ($range['start'] < $new_end && $range['end'] > $new_start) {
                            throw new Exception('Sepet öğesi #' . ($ci_index + 1) . ': Seçtiğiniz çalışan bu saatte dolu.');
                        }
                    }
                }

                $processed_items[] = [
                    'employee_id' => $assigned_employee_id,
                    'service_ids' => $item_service_ids,
                    'service_details' => $item_service_details,
                    'appointment_date' => $item_date,
                    'appointment_time' => $item_time,
                    'total_price' => $item_total_price,
                    'total_duration' => $item_total_duration,
                    'variant_data' => $item_variant_data,
                ];
            } // foreach cart_items

            // Müşterinin şifre durumunu kontrol et
            $stmt_pass = $db->prepare("SELECT id, name, phone, password FROM customers WHERE id = ?");
            $stmt_pass->execute([$customer_id]);
            $customer_full = $stmt_pass->fetch(PDO::FETCH_ASSOC);
            $has_password = !empty($customer_full['password']);
            
            $sms_verification_enabled = ($site_settings['sms_verification_enabled'] ?? 'on') === 'on';

            // === DOĞRULAMA KAPALI: TÜM RANDEVULARI DİREKT KAYDET ===
            if (!$sms_verification_enabled) {
                $first_appointment_id = null;
                foreach ($processed_items as $pi) {
                    $stmt_insert = $db->prepare("
                        INSERT INTO appointments 
                        (tenant_id, booking_group_id, customer_id, employee_id, appointment_date, appointment_time, status, 
                         verified, total_price, notes, total_duration) 
                        VALUES (?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?, ?)
                    ");
                    $stmt_insert->execute([
                        APPOINTMENT_TENANT_ID, $booking_group_id, $customer_id, $pi['employee_id'],
                        $pi['appointment_date'], $pi['appointment_time'],
                        $pi['total_price'], $notes, $pi['total_duration']
                    ]);
                    $appt_id = $db->lastInsertId();
                    if ($first_appointment_id === null) $first_appointment_id = $appt_id;
                    
                    if (!empty($pi['service_ids'])) {
                        $stmt_serv = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, tenant_id, variant_ids, final_price, final_duration) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach ($pi['service_ids'] as $sid) {
                            $detail = $pi['service_details'][$sid] ?? null;
                            $v_ids = ($detail && !empty($detail['variant_ids'])) ? json_encode($detail['variant_ids']) : null;
                            $f_price = $detail ? $detail['final_price'] : null;
                            $f_duration = $detail ? $detail['final_duration'] : null;
                            $stmt_serv->execute([$appt_id, $sid, APPOINTMENT_TENANT_ID, $v_ids, $f_price, $f_duration]);
                        }
                    }
                    
                    // Her randevu için ayrı SMS gönder
                    $send_confirmation_sms = ($site_settings['sms_send_on_create'] ?? 'on') === 'on';
                    if ($send_confirmation_sms) {
                        $business_name = truncateForSms($tenant_info['firma_adi'] ?? 'İşletme', 50);
                        $maps_link = $site_settings['shareable_maps_link'] ?? '';
                        $date_formatted = date('d.m.Y', strtotime($pi['appointment_date']));
                        $time_formatted = date('H:i', strtotime($pi['appointment_time']));
                        
                        $stmtEN = $db->prepare("SELECT name FROM employees WHERE id = ?");
                        $stmtEN->execute([$pi['employee_id']]);
                        $empNameSms = truncateForSms($stmtEN->fetchColumn() ?: '', 40);
                        
                        $sms_message = upperForSms($business_name) . "\n\n";
                        $sms_message .= "Randevunuz Oluşturuldu\n\n";
                        if ($empNameSms) $sms_message .= "Çalışan: {$empNameSms}\n";
                        $sms_message .= "Tarih: {$date_formatted}\n";
                        $sms_message .= "Saat: {$time_formatted}\n";
                        if ($maps_link) $sms_message .= "Konum: {$maps_link}\n";
                        $sms_message .= "\nRANDU.TR";
                        $sms_message = appendCustomerPendingPaymentNotice($db, (int)APPOINTMENT_TENANT_ID, (int)$customer_id, $sms_message);
                        
                        sendSms($customer_phone, $sms_message, null, (int)APPOINTMENT_TENANT_ID);
                    }

                    // E-posta gönder
                    try {
                        $stmtCustEmail = $db->prepare("SELECT name, email FROM customers WHERE id = ?");
                        $stmtCustEmail->execute([$customer_id]);
                        $customerData = $stmtCustEmail->fetch(PDO::FETCH_ASSOC);
                        
                        if (!empty($customerData['email']) && class_exists('Mailer')) {
                            $businessAddress = $site_settings['site_address'] ?? '';
                            if (empty($businessAddress) && !empty($tenant_info['il'])) {
                                $businessAddress = ($tenant_info['il'] ?? '') . ', ' . ($tenant_info['ilce'] ?? '');
                            }
                            
                            $stmtEmpN = $db->prepare("SELECT name FROM employees WHERE id = ?");
                            $stmtEmpN->execute([$pi['employee_id']]);
                            $emp_name = $stmtEmpN->fetchColumn() ?: 'Belirtilmemiş';
                            
                            $stmtSrvN = $db->prepare("SELECT name FROM services WHERE id IN (" . implode(',', array_map('intval', $pi['service_ids'])) . ")");
                            $stmtSrvN->execute();
                            $srv_names = $stmtSrvN->fetchAll(PDO::FETCH_COLUMN);
                            
                            Mailer::sendAppointmentConfirmation($customerData['email'], [
                                'customer_name' => $customerData['name'] ?? 'Değerli Müşterimiz',
                                'business_name' => $tenant_info['firma_adi'] ?? '',
                                'service_name' => implode(', ', $srv_names),
                                'employee_name' => $emp_name,
                                'date' => date('d.m.Y', strtotime($pi['appointment_date'])),
                                'time' => date('H:i', strtotime($pi['appointment_time'])),
                                'duration' => $pi['total_duration'],
                                'price' => number_format($pi['total_price'], 2, ',', '.') . ' ₺',
                                'address' => $businessAddress,
                                'phone' => $tenant_info['telefon'] ?? '',
                                'maps_link' => $site_settings['shareable_maps_link'] ?? '',
                                'pending_payment_amount' => getCustomerPendingPaymentTotal($db, (int)APPOINTMENT_TENANT_ID, (int)$customer_id)
                            ]);
                        }
                    } catch (Exception $emailEx) {
                        error_log("Randevu onay e-postası gönderilemedi: " . $emailEx->getMessage());
                    }

                    // Çalışanlara bildirim
                    try {
        notifyEmployeesOnNewAppointment($db, APPOINTMENT_TENANT_ID, $pi['employee_id'], $customer_name, $pi['appointment_date'], $pi['appointment_time']);
                    } catch (Exception $ex) {
                        error_log("İşletme bildirim SMS hatası: " . $ex->getMessage());
                    }
                } // foreach processed_items
                
                $db->commit();
                header("Location: appointment_success.php?phone=" . urlencode($customer_phone) . "&id=" . $first_appointment_id);
                exit;
            }

            // === DOĞRULAMA AÇIK: SESSION'A KAYDET ===
            $verification_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $verification_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $pending_token = bin2hex(random_bytes(16));

            $_SESSION['pending_appointment'] = [
                'is_cart' => true,
                'booking_group_id' => $booking_group_id,
                'items' => $processed_items,
                'tenant_id' => APPOINTMENT_TENANT_ID,
                'customer_id' => $customer_id,
                'notes' => $notes,
                'customer_phone' => $customer_phone,
                'customer_name' => $customer_name,
                'customer_email' => $customer_email,
                'verification_code' => $verification_code,
                'verification_expires' => $verification_expires,
                'pending_token' => $pending_token,
                'has_password' => $has_password,
                'created_at' => date('Y-m-d H:i:s')
            ];

            if (!isset($_SESSION['pending_appointments']) || !is_array($_SESSION['pending_appointments'])) {
                $_SESSION['pending_appointments'] = [];
            }
            $_SESSION['pending_appointments'][$pending_token] = $_SESSION['pending_appointment'];

            // ── DB'ye kaydet (Instagram in-app browser / yeni session sorunu için) ──
            // Önce commit et, sonra DDL+INSERT yap (DDL implicit commit yapar)
            $db->commit();

            try {
                $db->exec("CREATE TABLE IF NOT EXISTS `pending_appointments` (
                    `token` varchar(64) NOT NULL,
                    `tenant_id` int(11) NOT NULL,
                    `data` longtext NOT NULL,
                    `expires_at` datetime NOT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`token`),
                    KEY `expires_at` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $stmt_dbp = $db->prepare("INSERT INTO pending_appointments (token, tenant_id, data, expires_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE data=VALUES(data), expires_at=VALUES(expires_at)");
                $stmt_dbp->execute([$pending_token, APPOINTMENT_TENANT_ID, json_encode($_SESSION['pending_appointment']), $verification_expires]);
                // Süresi dolmuş eski kayıtları temizle (1/20 olasılıkla)
                if (rand(1, 20) === 1) {
                    $db->exec("DELETE FROM pending_appointments WHERE expires_at < NOW()");
                }
            } catch (Exception $e) { /* non-fatal */ }

            // Müşteri zaten giriş yapmışsa direkt onayla
            if (isset($_SESSION['customer_id']) && $_SESSION['customer_id'] == $customer_id) {
                $pend = $_SESSION['pending_appointment'];
                $first_appointment_id = null;
                
                foreach ($pend['items'] as $pi) {
                    $stmt_insert = $db->prepare("
                        INSERT INTO appointments 
                        (tenant_id, booking_group_id, customer_id, employee_id, appointment_date, appointment_time, status, 
                         verified, total_price, notes, total_duration) 
                        VALUES (?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?, ?)
                    ");
                    $stmt_insert->execute([
                        $pend['tenant_id'], $pend['booking_group_id'], $pend['customer_id'], $pi['employee_id'],
                        $pi['appointment_date'], $pi['appointment_time'],
                        $pi['total_price'], $pend['notes'], $pi['total_duration']
                    ]);
                    $appt_id = $db->lastInsertId();
                    if ($first_appointment_id === null) $first_appointment_id = $appt_id;
                    
                    if (!empty($pi['service_ids'])) {
                        $stmt_serv = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, tenant_id, variant_ids, final_price, final_duration) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach ($pi['service_ids'] as $sid) {
                            $detail = $pi['service_details'][$sid] ?? null;
                            $v_ids = ($detail && !empty($detail['variant_ids'])) ? json_encode($detail['variant_ids']) : null;
                            $f_price = $detail ? $detail['final_price'] : null;
                            $f_duration = $detail ? $detail['final_duration'] : null;
                            $stmt_serv->execute([$appt_id, $sid, $pend['tenant_id'], $v_ids, $f_price, $f_duration]);
                        }
                    }
                    
                    // Her randevu için ayrı SMS
                    $send_confirmation_sms = ($site_settings['sms_send_on_create'] ?? 'on') === 'on';
                    if ($send_confirmation_sms) {
                        $business_name = truncateForSms($tenant_info['firma_adi'] ?? 'İşletme', 50);
                        $maps_link = $site_settings['shareable_maps_link'] ?? '';
                        $date_formatted = date('d.m.Y', strtotime($pi['appointment_date']));
                        $time_formatted = date('H:i', strtotime($pi['appointment_time']));
                        
                        $stmtEN = $db->prepare("SELECT name FROM employees WHERE id = ?");
                        $stmtEN->execute([$pi['employee_id']]);
                        $empNameSms = truncateForSms($stmtEN->fetchColumn() ?: '', 40);
                        
                        $sms_message = upperForSms($business_name) . "\n\n";
                        $sms_message .= "Randevunuz Oluşturuldu\n\n";
                        if ($empNameSms) $sms_message .= "Çalışan: {$empNameSms}\n";
                        $sms_message .= "Tarih: {$date_formatted}\n";
                        $sms_message .= "Saat: {$time_formatted}\n";
                        if ($maps_link) $sms_message .= "Konum: {$maps_link}\n";
                        $sms_message .= "\nRANDU.TR";
                        $sms_message = appendCustomerPendingPaymentNotice($db, (int)APPOINTMENT_TENANT_ID, (int)$customer_id, $sms_message);
                        
                        sendSms($customer_phone, $sms_message, null, (int)APPOINTMENT_TENANT_ID);
                    }

                    // E-posta
                    try {
                        $stmtCustEmail2 = $db->prepare("SELECT name, email FROM customers WHERE id = ?");
                        $stmtCustEmail2->execute([$customer_id]);
                        $customerData2 = $stmtCustEmail2->fetch(PDO::FETCH_ASSOC);
                        
                        if (!empty($customerData2['email']) && class_exists('Mailer')) {
                            $businessAddress2 = $site_settings['site_address'] ?? '';
                            if (empty($businessAddress2) && !empty($tenant_info['il'])) {
                                $businessAddress2 = ($tenant_info['il'] ?? '') . ', ' . ($tenant_info['ilce'] ?? '');
                            }
                            
                            $stmtEmpN2 = $db->prepare("SELECT name FROM employees WHERE id = ?");
                            $stmtEmpN2->execute([$pi['employee_id']]);
                            $emp_name2 = $stmtEmpN2->fetchColumn() ?: 'Belirtilmemiş';
                            
                            $stmtSrvN2 = $db->prepare("SELECT name FROM services WHERE id IN (" . implode(',', array_map('intval', $pi['service_ids'])) . ")");
                            $stmtSrvN2->execute();
                            $srv_names2 = $stmtSrvN2->fetchAll(PDO::FETCH_COLUMN);
                            
                            Mailer::sendAppointmentConfirmation($customerData2['email'], [
                                'customer_name' => $customerData2['name'] ?? 'Değerli Müşterimiz',
                                'business_name' => $tenant_info['firma_adi'] ?? '',
                                'service_name' => implode(', ', $srv_names2),
                                'employee_name' => $emp_name2,
                                'date' => date('d.m.Y', strtotime($pi['appointment_date'])),
                                'time' => date('H:i', strtotime($pi['appointment_time'])),
                                'duration' => $pi['total_duration'],
                                'price' => number_format($pi['total_price'], 2, ',', '.') . ' ₺',
                                'address' => $businessAddress2,
                                'phone' => $tenant_info['telefon'] ?? '',
                                'maps_link' => $site_settings['shareable_maps_link'] ?? '',
                                'pending_payment_amount' => getCustomerPendingPaymentTotal($db, (int)$pend['tenant_id'], (int)$customer_id)
                            ]);
                        }
                    } catch (Exception $emailEx) {
                        error_log("Randevu onay e-postası gönderilemedi: " . $emailEx->getMessage());
                    }

                    // Çalışanlara bildirim
                    try {
                        notifyEmployeesOnNewAppointment($db, $pend['tenant_id'], $pi['employee_id'], $pend['customer_name'], $pi['appointment_date'], $pi['appointment_time']);
                    } catch (Exception $ex) {
                        error_log("İşletme bildirim SMS hatası: " . $ex->getMessage());
                    }
                } // foreach pend items
                
                $pend_token = $pend['pending_token'] ?? null;
                unset($_SESSION['pending_appointment']);
                if ($pend_token && isset($_SESSION['pending_appointments'][$pend_token])) {
                    unset($_SESSION['pending_appointments'][$pend_token]);
                }
                header("Location: appointment_success.php?phone=" . urlencode($customer_phone) . "&id=" . $first_appointment_id);
                exit;
            }

            // Şifre var mı? → şifre doğrulamaya yönlendir, yoksa SMS gönder
            if ($has_password) {
                header("Location: verify_appointment.php?phone=" . urlencode($customer_phone) . "&password_required=1&tenant_id=" . APPOINTMENT_TENANT_ID . "&pending_token=" . urlencode($pending_token));
                exit;
            } else {
                $first_item = $processed_items[0];
                $date_formatted = date('d.m.Y', strtotime($first_item['appointment_date']));
                $time_formatted = date('H:i', strtotime($first_item['appointment_time']));
                $sms_message = "Randevu onay kodunuz: {$verification_code}\n{$date_formatted} - {$time_formatted}\nKod 10 dk geçerlidir.";
                
                $sms_result = sendSms($customer_phone, $sms_message, null, (int)APPOINTMENT_TENANT_ID);

                // SMS gönderilemediyse hata göster, randevu oturumunu temizle ki kullanıcı tekrar denesin
                if (empty($sms_result['status'])) {
                    error_log("Randevu onay SMS gönderilemedi (tenant: " . APPOINTMENT_TENANT_ID . ", phone: {$customer_phone}): " . ($sms_result['message'] ?? 'bilinmeyen hata'));
                    unset($_SESSION['pending_appointment']);
                    if (isset($_SESSION['pending_appointments'][$pending_token])) {
                        unset($_SESSION['pending_appointments'][$pending_token]);
                    }
                    $error = 'Onay kodu gönderilemedi: ' . ($sms_result['message'] ?? 'Bilinmeyen hata') . ' Lütfen işletme ile iletişime geçin veya daha sonra tekrar deneyin.';
                } else {
                    header("Location: verify_appointment.php?phone=" . urlencode($customer_phone) . "&tenant_id=" . APPOINTMENT_TENANT_ID . "&pending_token=" . urlencode($pending_token));
                    exit;
                }
            }

        } catch(Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = 'Hata: ' . $e->getMessage();
        }
    } // end else (validation_ok)
    } // end else (rate limit)
}

// İletişim Bilgileri
$site_phone = $site_settings['site_phone'] ?? '';
$site_phone_formatted = $site_phone;
if (!empty($site_phone)) {
    $phone_digits = preg_replace('/\D/', '', $site_phone);
    if (strlen($phone_digits) === 10) $phone_digits = '0' . $phone_digits;
    if(strlen($phone_digits) >= 10) {
        $site_phone_formatted = '0 (' . substr($phone_digits, 1, 3) . ') ' . substr($phone_digits, 4, 3) . ' ' . substr($phone_digits, 7);
    }
}

// Müşteri hatası — Telegram bildirimi (TELEGRAM_CHAT_ID)
if (!empty($error)) {
    reportCustomerFacingError($error, [
        'phone' => $customer_phone ?? '',
        'customer_name' => $customer_name ?? '',
        'tenant_id' => defined('APPOINTMENT_TENANT_ID') ? (int) APPOINTMENT_TENANT_ID : null,
    ]);
}

// Dinamik Header Include
if (file_exists($header_path)) {
    include $header_path;
} else {
    die("Header dosyası bulunamadı: " . $header_path);
}
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link rel="stylesheet" href="appointment.css?v=<?php echo filemtime(__DIR__.'/appointment.css').'.'.mt_rand(1000,9999); ?>">
<link rel="stylesheet" href="notification.css">

<!-- Animated Background -->
<div class="animated-bg">
    <div class="floating-shape shape1"></div>
    <div class="floating-shape shape2"></div>
    <div class="floating-shape shape3"></div>
</div>

<div class="appointment-container">
    <div class="appointment-wrapper">
        <div class="appointment-sidebar">
            <h2 class="sidebar-title">Randevu Al</h2>
            <p class="sidebar-subtitle">Adımları takip ederek randevunuzu oluşturun.</p>
            <div id="steps-container" class="steps-wrapper"></div>
            <div class="sidebar-footer">
                <p class="font-semibold">Bir sorunuz mu var?</p>
                <p><?php echo htmlspecialchars($site_phone_formatted); ?></p>
            </div>
        </div>
        <div class="appointment-content">
            <?php $appt_base_url = 'appointment.php' . ($is_master_domain ? '?tenant_id=' . APPOINTMENT_TENANT_ID : ''); ?>
            <?php if($success): ?>
                <div class="form-success-message">
                    <i class="fas fa-check-circle"></i>
                    <h3>Randevunuz Başarıyla Alındı!</h3>
                    <p><?php echo $success; ?></p>
                    <a href="<?php echo $appt_base_url; ?>" class="new-appointment-btn">Yeni Randevu Al</a>
                </div>
            <?php elseif($error): ?>
                 <div class="form-error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <h3>Bir Hata Oluştu!</h3>
                    <p><?php echo $error; ?></p>
                     <a href="<?php echo $appt_base_url; ?>" class="new-appointment-btn">Tekrar Dene</a>
                </div>
            <?php else: ?>
                <form id="appointmentForm" method="POST" action="appointment.php?tenant_id=<?php echo APPOINTMENT_TENANT_ID; ?>">
                    <div id="form-steps-container"></div>
                    <input type="hidden" name="employee_id" id="hidden_employee_id">
                    <input type="hidden" name="service_ids" id="hidden_service_ids">
                    <input type="hidden" name="variant_data" id="hidden_variant_data">
                    <input type="hidden" name="appointment_date" id="hidden_appointment_date">
                    <input type="hidden" name="appointment_time" id="hidden_appointment_time">
                    <input type="hidden" name="customer_name" id="hidden_customer_name">
                    <input type="hidden" name="customer_phone" id="hidden_customer_phone">
                    <input type="hidden" name="customer_email" id="hidden_customer_email">
                    <input type="hidden" name="notes" id="hidden_notes">
                    <input type="hidden" name="cart_data" id="hidden_cart_data">
                </form>
                <div id="navigation-buttons" class="navigation-wrapper">
                    <button type="button" id="prevBtn" class="nav-btn prev-btn">Geri</button>
                    <button type="button" id="nextBtn" class="nav-btn next-btn">İleri</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    const employeesData = <?php echo json_encode($employees); ?>;
    const servicesData = <?php echo json_encode($services); ?>;
    const categoriesData = <?php echo json_encode($categories); ?>;
    const showServiceDuration = <?php echo $show_service_duration ? 'true' : 'false'; ?>;
    const maxBookingDays = <?php echo $max_booking_days; ?>;
    const appointmentSuccess = <?php echo $success ? 'true' : 'false'; ?>;
    const appointmentError = <?php echo $error ? 'true' : 'false'; ?>;
    const tenantId = <?php echo APPOINTMENT_TENANT_ID; ?>;

    // Çalışan-Hizmet mapping: { employee_id: [service_id, ...], ... }
    <?php
    $stmt_esm = $db->prepare("SELECT employee_id, service_id FROM employee_services WHERE tenant_id = ?");
    $stmt_esm->execute([APPOINTMENT_TENANT_ID]);
    $es_rows = $stmt_esm->fetchAll(PDO::FETCH_ASSOC);
    $emp_svc_map = [];
    foreach ($es_rows as $row) {
        $emp_svc_map[$row['employee_id']][] = (int)$row['service_id'];
    }
    ?>
    const employeeServiceMap = <?php echo json_encode((object)$emp_svc_map); ?>;

    // İşletme çalışma takvimi (gün gün) - keys: 1-7 ISO (1=Pzt, 7=Paz)
    const businessSchedule = <?php echo json_encode(json_decode($site_settings['working_hours_schedule'] ?? '{}', true) ?: new stdClass()); ?>;
    // İşletme düzeyi özel gün bayrakları (employee_id IS NULL): { 'YYYY-MM-DD': { business_closed, custom_hours } }
    const businessDayOverrides = <?php echo json_encode($business_day_overrides, JSON_UNESCAPED_UNICODE); ?>;

</script>
<script src="notification.js"></script>
<script src="appointment.js?v=<?php echo filemtime(__DIR__.'/appointment.js').'.'.mt_rand(1000,9999); ?>"></script>
<script>
    if (appointmentSuccess) {
        setTimeout(() => {
            if(typeof notificationManager !== 'undefined') notificationManager.show('Randevunuz alındı!', 'success');
        }, 500);
    } else if (appointmentError) {
        setTimeout(() => {
            if(typeof notificationManager !== 'undefined') notificationManager.show('Hata oluştu.', 'error');
        }, 500);
    }
</script>

<?php 
// Dinamik Footer Include
if (file_exists($footer_path)) {
    include $footer_path;
} else {
    die("Footer dosyası bulunamadı: " . $footer_path);
}
?>