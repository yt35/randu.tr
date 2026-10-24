<?php
// Çıktı tamponlamayı başlat
ob_start();
// Hata raporlamayı aç (log dosyasına)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// UTF-8 encoding garantisi
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// CSP: frame-ancestors (X-Frame-Options zaten config.php'de merkezi olarak ekleniyor)
header('Content-Security-Policy: frame-ancestors \'self\'');

// config.php dosyasının varlığını ve yolunu kontrol et
$config_path = __DIR__ . '/../config/config.php';
if (!file_exists($config_path)) {
    die("Kritik Hata: Yapılandırma dosyası bulunamadı. Aranan yol: $config_path");
}
require_once $config_path;

// Gerekli fonksiyonların varlığını kontrol et
if (!function_exists('isLoggedIn') || !function_exists('checkSessionTimeout') || !function_exists('redirect')) {
    die("Kritik Hata: Gerekli oturum fonksiyonları yüklenemedi.");
}

if (!isLoggedIn()) {
    redirect('login.php');
}

// Araç kategorileri randevu listesi yerine iş emirlerini kullanır
if (function_exists('is_vehicle_service_category') && is_vehicle_service_category()) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: service_orders.php' . ($qs !== '' ? ('?' . $qs) : ''), true, 302);
    exit;
}

// SAYFA ERİŞİM KONTROLÜ
if (!can_access_page('appointments.php')) {
    $first_page = get_first_accessible_page();
    if ($first_page === null) {
        session_unset();
        session_destroy();
        header("Location: login.php?error=no_permissions");
    } else {
        header("Location: " . $first_page);
    }
    exit;
}

// --- FONKSYON: Platform Modeli (Müşteri oluştur veya bul) ---
function getOrCreateCustomer($name, $phone) {
    global $db;
    try {
        $formatted_phone = formatPhoneNumber($phone);
        
        // 1. Global Arama (Tenant kısıtlaması olmadan)
        $stmt = $db->prepare("SELECT id, name FROM customers WHERE phone = ?");
        $stmt->execute([$formatted_phone]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($customer) {
            $customer_id = $customer['id'];
            // 2. İlişkiyi garantiye al (varsa name güncelle)
            $stmtLink = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), deleted_at = NULL");
            $stmtLink->execute([$customer_id, TENANT_ID, $name]);
            
            return [
                'id' => $customer_id,
                'name' => $name,
                'is_new' => false
            ];
        } else {
            // 3. Müşteri Yoksa: Global olarak oluştur (tenant_id = NULL)
            $stmt = $db->prepare("INSERT INTO customers (tenant_id, name, phone, is_guest) VALUES (NULL, ?, ?, 1)");
            $stmt->execute([$name, $formatted_phone]);
            $new_id = $db->lastInsertId();
            
            // 4. lişkiyi Kur
            $stmtLink = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name) VALUES (?, ?, ?)");
            $stmtLink->execute([$new_id, TENANT_ID, $name]);
            
            return [
                'id' => $new_id,
                'name' => $name,
                'is_new' => true
            ];
        }
    } catch (Exception $e) {
        throw new Exception("Müşteri bilgileri işlenemedi: " . $e->getMessage());
    }
}

$page_title = 'Randevu Yönetimi';
$success = '';
$error = '';

// URL'den gelen mesajları al
if (isset($_GET['success_msg'])) {
    $success = urldecode($_GET['success_msg']);
}
if (isset($_GET['error_msg'])) {
    $error = urldecode($_GET['error_msg']);
}

// --- AJAX ÅLEMLER ---

// --- HELPER FUNCTIONS ---
if (!function_exists('validateDate')) {
    function validateDate($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}

if (!function_exists('validateTime')) {
    function validateTime($time, $format = 'H:i') {
        $t = DateTime::createFromFormat($format, $time);
        return $t && $t->format($format) === $time;
    }
}
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (ob_get_length()) ob_clean();
    
    // Production: hatalar log dosyasına yazılır, browser'a gösterilmez
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    
    header('Content-Type: application/json; charset=UTF-8');
    $response = ['success' => false, 'message' => 'Bilinmeyen bir hata oluştu.'];
    
// --- WEB PUSH ---
    // sendWebPushToCustomer config/WebPush.php içinde

    try {
        $action = $_POST['action'];
        
        // Müşteri bilgilerini telefon numarasından getir
        if ($action == 'get_customer_info') {
            $phone = sanitize($_POST['phone'] ?? '');
            if (!$phone) throw new Exception("Telefon numarası gerekli.");
            
            $formatted_phone = formatPhoneNumber($phone);
            
            // 1. Önce bu tenant'ın müşteri listesinde ara
            $stmt = $db->prepare("SELECT COALESCE(ct.name, c.name) as name, c.phone FROM customers c INNER JOIN customer_tenants ct ON c.id = ct.customer_id WHERE c.phone = ? AND ct.tenant_id = ?");
            $stmt->execute([$formatted_phone, TENANT_ID]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($customer) {
                $response['success'] = true;
                $response['customer'] = $customer;
                $response['show_confirmation'] = true;
            } else {
                $response['success'] = false;
                $response['message'] = 'Müşteri bulunamadı';
            }
        }
        elseif ($action == 'add') {
            // --- YEN RANDEVU EKLEME ---
            $customer_name = sanitize($_POST['customer_name'] ?? '');
            $customer_phone = sanitize($_POST['customer_phone'] ?? '');
            $employee_id = intval($_POST['employee_id'] ?? 0);
            $service_ids = isset($_POST['service_ids']) && is_array($_POST['service_ids']) ? $_POST['service_ids'] : [];
            $appointment_time = sanitize($_POST['appointment_time'] ?? '');
            $status = sanitize($_POST['status'] ?? 'confirmed');
            $appointment_date = sanitize($_POST['appointment_date'] ?? '');
            $notes = sanitizeNotes($_POST['notes'] ?? '');
            
            if (!$customer_name || !$customer_phone || !$employee_id || empty($service_ids) || !$appointment_date || !$appointment_time) {
                throw new Exception("Lütfen tüm zorunlu alanları doldurunuz.");
            }
            
            // Tarih ve Saat Validasyonu
            if (!validateDate($appointment_date)) {
                throw new Exception("Geçersiz randevu tarihi. Lütfen Y-m-d formatında girin.");
            }
            if (!validateTime($appointment_time)) {
                throw new Exception("Geçersiz randevu saati. Lütfen H:i formatında girin (örn: 14:30).");
            }
            
            // Geçmiş tarih uyarısı (confirmation gerekir)
            if (strtotime($appointment_date) < strtotime(date('Y-m-d')) && !isset($_POST['confirm_past_date'])) {
                $response['past_date_warning'] = true;
                $response['message'] = 'Randevu eklemek istediğiniz tarih geçmiş bir tarih. Yine de eklemek istiyor musunuz?';
                $response['appointment_date'] = $appointment_date;
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Süre Hesapla
            $total_duration = 0;
            if (!empty($service_ids)) {
                $placeholders = str_repeat('?,', count($service_ids) - 1) . '?';
                $stmt_duration = $db->prepare("SELECT SUM(duration) FROM services WHERE id IN ($placeholders) AND tenant_id = ?");
                $params_duration = array_merge($service_ids, [TENANT_ID]);
                $stmt_duration->execute($params_duration);
                $total_duration = (int)$stmt_duration->fetchColumn();
            }
            if ($total_duration <= 0) throw new Exception("Hizmetlerin toplam süresi hesaplanamadı.");
            
            // Çakışma Kontrolü
            $stmt_check = $db->prepare("
                SELECT COUNT(*) FROM appointments 
                WHERE employee_id = ? AND appointment_date = ? AND status != 'cancelled' AND tenant_id = ?
                AND deleted_at IS NULL
                AND ((appointment_time < ADDTIME(?, SEC_TO_TIME(? * 60)) AND ADDTIME(appointment_time, SEC_TO_TIME(total_duration * 60)) > ?))
            ");
            $stmt_check->execute([$employee_id, $appointment_date, TENANT_ID, $appointment_time, $total_duration, $appointment_time]);
            if ($stmt_check->fetchColumn() > 0) throw new Exception("Seçilen zaman aralığında çakışma mevcut.");

            // Müşteri şlemleri
            $customer_data = getOrCreateCustomer($customer_name, $customer_phone);
            $customer_id = $customer_data['id'];
            
            if (!$customer_data['is_new'] && $customer_data['name'] !== $customer_name && !isset($_POST['force_existing'])) {
                $response['existing_customer'] = true;
                $response['customer_name'] = $customer_data['name'];
                $response['customer_phone'] = $customer_phone;
                $response['message'] = "Bu numara " . htmlspecialchars($customer_data['name']) . " adına kayıtlı.";
                echo json_encode($response);
                exit;
            }
            
            // Fiyat Hesapla (range pricing desteği)
            $total_price = 0;
            if (!empty($service_ids)) {
                $placeholders = str_repeat('?,', count($service_ids) - 1) . '?';
                $stmt_price = $db->prepare("SELECT id, price, pricing_type, price_min FROM services WHERE id IN ($placeholders) AND tenant_id = ?");
                $params_price = array_merge($service_ids, [TENANT_ID]);
                $stmt_price->execute($params_price);
                $price_rows = $stmt_price->fetchAll(PDO::FETCH_ASSOC);
                foreach ($price_rows as $pr) {
                    $total_price += ($pr['pricing_type'] === 'range' && $pr['price_min'] !== null) ? (float)$pr['price_min'] : (float)$pr['price'];
                }
            }

            // Kayıt
            $db->beginTransaction();
            $stmt_insert = $db->prepare("INSERT INTO appointments (tenant_id, customer_id, employee_id, appointment_date, appointment_time, status, total_price, total_duration, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_insert->execute([TENANT_ID, $customer_id, $employee_id, $appointment_date, $appointment_time, $status, $total_price, $total_duration, $notes]);
            $appointment_id = $db->lastInsertId();

            $stmt_service = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, tenant_id) VALUES (?, ?, ?)");
            foreach ($service_ids as $service_id) {
                $stmt_service->execute([$appointment_id, intval($service_id), TENANT_ID]);
            }
            $db->commit();
            
            // --- WEB PUSH (YENİ RANDEVU) ---
            try {
                $firma_adi = "Randu.tr";
                $logo_url = null;
                $base_url = defined('BASE_URL') ? BASE_URL : 'https://randu.tr';

                if (defined('TENANT_ID')) {
                    $stmtT = $db->prepare("SELECT firma_adi FROM tenants WHERE id = ?");
                    $stmtT->execute([TENANT_ID]);
                    $f = $stmtT->fetchColumn();
                    if ($f) $firma_adi = $f;

                    $stmtL = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'site_logo' AND tenant_id = ?");
                    $stmtL->execute([TENANT_ID]);
                    $l = $stmtL->fetchColumn();
                    if ($l) {
                        $logo_path = __DIR__ . '/../' . $l;
                        if (file_exists($logo_path)) {
                            $logo_url = $base_url . '/' . ltrim($l, '/');
                        }
                    }
                }

                $os_msg = ($status == 'confirmed') ? "Randevunuz oluşturuldu ve onaylandı." : "Yeni randevunuz oluşturuldu.";
                sendWebPushToCustomer((int)$customer_id, $firma_adi, $os_msg, $base_url . "/customer/index.php", $logo_url);
            } catch (Exception $e) {
                error_log("WebPush Exception in add action: " . $e->getMessage());
            }
            // ----------------------------------------
            
            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla eklendi!';
            $response['reload'] = true;
        }
        elseif ($action == 'update') {
            // --- RANDEVU GÜNCELLEME ---
            $appointment_id = intval($_POST['appointment_id'] ?? 0);
            $customer_name = sanitize($_POST['customer_name'] ?? '');
            $customer_phone = sanitize($_POST['customer_phone'] ?? '');
            $employee_id = intval($_POST['employee_id'] ?? 0);
            $service_ids = isset($_POST['service_ids']) && is_array($_POST['service_ids']) ? $_POST['service_ids'] : [];
            $appointment_time = sanitize($_POST['appointment_time'] ?? '');
            $status = sanitize($_POST['status'] ?? 'confirmed');
            $appointment_date = sanitize($_POST['appointment_date'] ?? '');
            $notes = sanitizeNotes($_POST['notes'] ?? '');
            
            if (!$appointment_id || !$customer_name || !$customer_phone || !$employee_id || empty($service_ids) || !$appointment_date || !$appointment_time) {
                throw new Exception("Lütfen tüm zorunlu alanları doldurunuz.");
            }
            
            // Tarih ve Saat Validasyonu
            if (!validateDate($appointment_date)) {
                throw new Exception("Geçersiz randevu tarihi. Lütfen Y-m-d formatında girin.");
            }
            if (!validateTime($appointment_time)) {
                throw new Exception("Geçersiz randevu saati. Lütfen H:i formatında girin (örn: 14:30).");
            }
            
            // Süre ve Fiyat
            $total_price = 0; $total_duration = 0;
            if (!empty($service_ids)) {
                $placeholders = str_repeat('?,', count($service_ids) - 1) . '?';
                $stmt_service_data = $db->prepare("SELECT SUM(price) as price, SUM(duration) as duration FROM services WHERE id IN ($placeholders) AND tenant_id = ?");
                $params_service = array_merge($service_ids, [TENANT_ID]);
                $stmt_service_data->execute($params_service);
                $service_totals = $stmt_service_data->fetch(PDO::FETCH_ASSOC);
                if($service_totals) {
                    $total_price = (float)$service_totals['price'];
                    $total_duration = (int)$service_totals['duration'];
                }
            }
            if ($total_duration <= 0) throw new Exception("Hizmetlerin toplam süresi hesaplanamadı.");

            // Çakışma
            $stmt_check = $db->prepare("
                SELECT COUNT(*) FROM appointments 
                WHERE employee_id = ? AND appointment_date = ? AND id != ? AND status != 'cancelled' AND tenant_id = ?
                AND deleted_at IS NULL
                AND ((appointment_time < ADDTIME(?, SEC_TO_TIME(? * 60)) AND ADDTIME(appointment_time, SEC_TO_TIME(total_duration * 60)) > ?))
            ");
            $stmt_check->execute([$employee_id, $appointment_date, $appointment_id, TENANT_ID, $appointment_time, $total_duration, $appointment_time]);
            if ($stmt_check->fetchColumn() > 0) throw new Exception("Seçilen zaman aralığında çakışma mevcut.");
            
            // Müşteri
            $customer_data = getOrCreateCustomer($customer_name, $customer_phone);
            $customer_id = $customer_data['id'];

            // Güncelleme
            $db->beginTransaction();
            $stmt_update = $db->prepare("UPDATE appointments SET customer_id = ?, employee_id = ?, appointment_date = ?, appointment_time = ?, status = ?, total_price = ?, total_duration = ?, notes = ? WHERE id = ? AND tenant_id = ?");
            $stmt_update->execute([$customer_id, $employee_id, $appointment_date, $appointment_time, $status, $total_price, $total_duration, $notes, $appointment_id, TENANT_ID]);
            
            // Mevcut varyant bilgilerini kaydet
            $stmt_old_svc = $db->prepare("SELECT service_id, variant_ids, final_price, final_duration FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
            $stmt_old_svc->execute([$appointment_id, $appointment_id, TENANT_ID]);
            $old_svc_details = [];
            foreach ($stmt_old_svc->fetchAll(PDO::FETCH_ASSOC) as $od) {
                $old_svc_details[$od['service_id']] = $od;
            }

            $stmt_delete_services = $db->prepare("DELETE FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
            $stmt_delete_services->execute([$appointment_id, $appointment_id, TENANT_ID]);

            $stmt_insert_service = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, tenant_id, variant_ids, final_price, final_duration) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($service_ids as $sid) {
                $sid = intval($sid);
                $old = $old_svc_details[$sid] ?? null;
                $stmt_insert_service->execute([
                    $appointment_id, $sid, TENANT_ID,
                    $old['variant_ids'] ?? null,
                    $old['final_price'] ?? null,
                    $old['final_duration'] ?? null
                ]);
            }
            $db->commit();

            // --- WEB PUSH (GÜNCELLEME) ---
            try {
                $firma_adi = "Randu.tr";
                $logo_url = null;
                $base_url = defined('BASE_URL') ? BASE_URL : 'https://randu.tr';

                if (defined('TENANT_ID')) {
                    $stmtT = $db->prepare("SELECT firma_adi FROM tenants WHERE id = ?");
                    $stmtT->execute([TENANT_ID]);
                    $f = $stmtT->fetchColumn();
                    if ($f) $firma_adi = $f;

                    $stmtL = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'site_logo' AND tenant_id = ?");
                    $stmtL->execute([TENANT_ID]);
                    $l = $stmtL->fetchColumn();
                    if ($l && file_exists(__DIR__ . '/../' . $l)) {
                        $logo_url = $base_url . '/' . ltrim($l, '/');
                    }
                }

                $msg = "";
                if ($status == 'confirmed') $msg = "Randevunuz onaylandı! Sizi bekliyoruz.";
                elseif ($status == 'cancelled') $msg = "Üzgünüz, randevunuz iptal edildi.";
                elseif ($status == 'completed') $msg = "Randevunuz tamamlandı.";
                elseif ($status == 'pending') $msg = "Randevu durumunuz güncellendi: Beklemede.";

                if ($msg) {
                    sendWebPushToCustomer((int)$customer_id, $firma_adi, $msg, $base_url . "/customer/index.php", $logo_url);
                }
            } catch (Exception $e) {
                error_log("WebPush Exception in update action: " . $e->getMessage());
            }
            // ---------------------------------------

            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla güncellendi!';
            $response['reload'] = true;
        }
        elseif ($action == 'complete') {
            // --- RANDEVU TAMAMLAMA ---
            $appointment_id = intval($_POST['appointment_id'] ?? 0);
            $final_price = floatval($_POST['final_price'] ?? 0);
            $payment_method = sanitize($_POST['payment_method'] ?? 'cash');
            
            if (!in_array($payment_method, ['cash', 'bank_transfer', 'credit_card', 'not_paid'])) {
                $payment_method = 'cash';
            }
            $payment_status = ($payment_method === 'not_paid') ? 'pending' : 'paid';
            
            if (!$appointment_id) throw new Exception("Randevu ID'si eksik.");
            
            $stmt = $db->prepare("UPDATE appointments SET status = 'completed', payment_status = ?, total_price = ?, payment_method = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$payment_status, $final_price, $payment_method, $appointment_id, TENANT_ID]);

            // Stok düşümü (hizmete bağlı ürünler)
            deductStockForAppointment($db, $appointment_id, TENANT_ID, $_SESSION['user_id'] ?? null);
            
            // --- WEB PUSH (TAMAMLAMA) ---
            try {
                $stmtCust = $db->prepare("SELECT a.customer_id FROM appointments a WHERE a.id = ? AND a.tenant_id = ?");
                $stmtCust->execute([$appointment_id, TENANT_ID]);
                $push_customer_id = (int)$stmtCust->fetchColumn();

                if ($push_customer_id > 0) {
                    $firma_adi = "Randu.tr";
                    $logo_url = null;
                    $base_url = defined('BASE_URL') ? BASE_URL : 'https://randu.tr';

                    $stmtT = $db->prepare("SELECT firma_adi FROM tenants WHERE id = ?");
                    $stmtT->execute([TENANT_ID]);
                    $f = $stmtT->fetchColumn();
                    if ($f) $firma_adi = $f;

                    $stmtL = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'site_logo' AND tenant_id = ?");
                    $stmtL->execute([TENANT_ID]);
                    $l = $stmtL->fetchColumn();
                    if ($l && file_exists(__DIR__ . '/../' . $l)) {
                        $logo_url = $base_url . '/' . ltrim($l, '/');
                    }

                    sendWebPushToCustomer(
                        $push_customer_id,
                        $firma_adi,
                        "Randevunuz tamamlandı. Bizi tercih ettiğiniz için teşekkürler.",
                        $base_url . "/customer/index.php",
                        $logo_url
                    );
                }
            } catch (Exception $e) {
                error_log("WebPush Exception in complete action: " . $e->getMessage());
            }
            // -------------------------------------
            
            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla tamamlandı!';
            $response['reload'] = true;
        }
        elseif ($action == 'delete') {
            $appointment_id = intval($_POST['appointment_id'] ?? 0);
            if (!$appointment_id) throw new Exception("Randevu ID'si eksik.");

            // Bekleyen ödeme kontrolü
            $chk = $db->prepare("SELECT status, payment_method FROM appointments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL");
            $chk->execute([$appointment_id, TENANT_ID]);
            $apt_chk = $chk->fetch(PDO::FETCH_ASSOC);
            if ($apt_chk && $apt_chk['status'] === 'completed' && $apt_chk['payment_method'] === 'not_paid') {
                throw new Exception('Bu randevuda bekleyen ödeme olduğu için silinemez. Lütfen önce ödemeyi tamamlayın.');
            }
            
            $db->beginTransaction();
            $stmt = $db->prepare("UPDATE appointments SET deleted_at = NOW() WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL");
            $stmt->execute([$appointment_id, TENANT_ID]);
            $db->commit();

            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla silindi!';
            $response['reload'] = true;
        }
        // bulk_delete ve bulk_status artık API endpoints'ler tarafından işleniyor
        // admin/api/appointments/bulk_delete.php ve bulk_status.php
        elseif ($action == 'confirm_appointment') {
            $appointment_id = intval($_POST['appointment_id'] ?? 0);
            if (!$appointment_id) throw new Exception("Randevu ID'si eksik.");
            
            $stmt = $db->prepare("UPDATE appointments SET status = 'confirmed' WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$appointment_id, TENANT_ID]);
            
            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla onaylandı!';
            $response['reload'] = true;
        }
        elseif ($action == 'cancel_appointment') {
            $appointment_id = intval($_POST['appointment_id'] ?? 0);
            if (!$appointment_id) throw new Exception("Randevu ID'si eksik.");
            
            $stmt = $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$appointment_id, TENANT_ID]);
            
            $response['success'] = true;
            $response['message'] = 'Randevu iptal edildi!';
            $response['reload'] = true;
        }
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        // Error log'a yaz
        error_log("Appointments Error: " . $e->getMessage() . " | Line: " . $e->getLine() . " | File: " . $e->getFile());
        
        $response['success'] = false;
        $response['message'] = $e->getMessage();
        $response['debug'] = [
            'line' => $e->getLine(),
            'file' => basename($e->getFile())
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Filtreleme parametreleri - Varsayılan: Sadece bugünün randevuları
// URL temiz kalacak, tüm filtreler AJAX ile yapılacak
$show_all = false;
$filter_date_from = date('Y-m-d'); // Bugün
$filter_date_to = date('Y-m-d');   // Bugün

// Varsayılan filtre değerleri - URL parametreleri kullanılmıyor
$filter_employee = 0;
$filter_status = '';
$filter_customer = '';
$per_page = 25;
$page = 1;

// Sıralama parametreleri - varsayılan
$sort_column = 'date';
$sort_order = 'asc';

// Geçerli sıralama kolonları
$valid_sorts = ['date', 'customer', 'employee', 'service', 'status', 'price'];
if (!in_array($sort_column, $valid_sorts)) {
    $sort_column = 'date';
}
$sort_order = strtolower($sort_order) === 'desc' ? 'DESC' : 'ASC';

// Verileri çek
$employees = [];
$services = [];
$appointments = [];
$total_records = 0;
$stats = ['total' => 0, 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];

try {
    // Çalışanları ve servisleri çek - TENANT_ID eklendi
    $emp_stmt = $db->prepare("SELECT id, name FROM employees WHERE status = 'active' AND tenant_id = ? AND deleted_at IS NULL ORDER BY name ASC");
    $emp_stmt->execute([TENANT_ID]);
    $employees = $emp_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $srv_stmt = $db->prepare("SELECT s.id, s.name, s.price, s.duration, s.pricing_type, s.price_min, s.price_max, s.is_multiple
    FROM services s
    LEFT JOIN (
        SELECT aps.service_id, COUNT(*) AS usage_cnt
        FROM appointment_services aps
        INNER JOIN appointments a ON a.id = aps.appointment_id
        WHERE a.tenant_id = ?
          AND a.deleted_at IS NULL
          AND a.status NOT IN ('cancelled', 'working_slot')
        GROUP BY aps.service_id
    ) u ON u.service_id = s.id
    WHERE s.status = 'active' AND s.tenant_id = ? AND s.deleted_at IS NULL
    ORDER BY CASE WHEN s.id = 912 THEN 0 ELSE 1 END, COALESCE(u.usage_cnt, 0) DESC, s.name ASC");
    $srv_stmt->execute([TENANT_ID, TENANT_ID]);
    $services = $srv_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Varyant tipli hizmetler için varyantları çek
    $service_variants_map = [];
    foreach ($services as $svc) {
        if ($svc['pricing_type'] === 'variant') {
            $stmtVar = $db->prepare("SELECT id, name, extra_price, extra_duration, sort_order FROM service_variants WHERE service_id = ? AND tenant_id = ? AND status = 'active' ORDER BY sort_order ASC");
            $stmtVar->execute([$svc['id'], TENANT_ID]);
            $service_variants_map[$svc['id']] = $stmtVar->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    // Filtreleme koşullarını hazırla
    $where_conditions = ['a.tenant_id = ?', 'a.deleted_at IS NULL'];
    $params = [TENANT_ID];
    
    if ($filter_date_from) {
        $where_conditions[] = 'a.appointment_date >= ?';
        $params[] = $filter_date_from;
    }
    
    if ($filter_date_to) {
        $where_conditions[] = 'a.appointment_date <= ?';
        $params[] = $filter_date_to;
    }
    
    if ($filter_employee) {
        $where_conditions[] = 'a.employee_id = ?';
        $params[] = $filter_employee;
    }
    
    if ($filter_status) {
        $where_conditions[] = 'a.status = ?';
        $params[] = $filter_status;
    }
    
    if ($filter_customer) {
        // Telefonla aramada formatı temizle
        $where_conditions[] = '(c.name LIKE ? OR c.phone LIKE ?)';
        $params[] = '%' . $filter_customer . '%';
        $params[] = '%' . formatPhoneNumber($filter_customer) . '%';
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Toplam kayıt sayısı
    $count_query = "
        SELECT COUNT(DISTINCT a.id) 
        FROM appointments a
        LEFT JOIN customers c ON a.customer_id = c.id
        WHERE $where_clause
    ";
    $stmt = $db->prepare($count_query);
    $stmt->execute($params);
    $total_records = $stmt->fetchColumn();
    
    // Sayfalama
    $total_pages = ceil($total_records / $per_page);
    $offset = ($page - 1) * $per_page;
    
    // Randevuları çek
    $query = "
        SELECT
            a.id, a.customer_id, a.employee_id, a.appointment_date, a.appointment_time,
            a.status, a.total_price, a.total_duration, a.payment_method, a.payment_status,
            a.notes, a.is_between, a.is_edited,
            COALESCE(ct.name, c.name) AS customer_name,
            c.phone AS customer_phone,
            e.name AS employee_name,
            GROUP_CONCAT(s.name SEPARATOR ', ') AS service_name,
            GROUP_CONCAT(s.id SEPARATOR ',') AS service_ids
        FROM
            appointments a
        LEFT JOIN
            customers c ON a.customer_id = c.id
        LEFT JOIN
            customer_tenants ct ON c.id = ct.customer_id AND ct.tenant_id = a.tenant_id
        LEFT JOIN
            employees e ON a.employee_id = e.id
        LEFT JOIN
            appointment_services a_s ON a.id = a_s.appointment_id
        LEFT JOIN
            services s ON a_s.service_id = s.id
        WHERE $where_clause
        GROUP BY
            a.id
        ORDER BY " . (function() use ($sort_column, $sort_order) {
                $sortMap = [
                    'date' => "a.appointment_date $sort_order, a.appointment_time $sort_order",
                    'customer' => "c.name $sort_order",
                    'employee' => "e.name $sort_order",
                    'service' => "GROUP_CONCAT(s.name) $sort_order",
                    'status' => "a.status $sort_order",
                    'price' => "total_price $sort_order"
                ];
                return $sortMap[$sort_column] ?? "a.appointment_date $sort_order, a.appointment_time $sort_order";
            })() . "
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // statistikler
    $stats['total'] = $total_records;
    $status_counts_query = "
        SELECT 
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM appointments a
        LEFT JOIN customers c ON a.customer_id = c.id
        WHERE $where_clause
    ";
    $stmt_statuses = $db->prepare($status_counts_query);
    $stmt_statuses->execute($params);
    $status_counts = $stmt_statuses->fetch(PDO::FETCH_ASSOC);
    if($status_counts) {
        $stats = array_merge($stats, $status_counts);
    }
    
} catch (Exception $e) {
    $error = "Veriler yüklenirken bir hata oluştu: " . $e->getMessage();
}

// Header include
if (file_exists('includes/header.php')) {
    include 'includes/header.php';
} else {
    echo "<!DOCTYPE html><html lang='tr'><head><meta charset='UTF-8'><title>Randevu Yönetimi</title></head><body>";
}
?>

<div class="page-container">
    <div class="stats-container">
        <div class="card">
            <div class="title">
                <span style="background-color: #6b21a8;">
                    <i class="fas fa-list"></i>
                </span>
                <p class="title-text">Toplam</p>
                <p class="percent">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 1792 1792" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1408 1216q0 26-19 45t-45 19h-896q-26 0-45-19t-19-45 19-45l448-448q19-19 45-19t45 19l448 448q19 19 19 45z"></path>
                    </svg>
                    100%
                </p>
            </div>
            <div class="data">
                <p><?php echo intval($stats['total'] ?? 0); ?></p>
                <div class="range">
                    <div class="fill" style="width: 100%; background-color: #6b21a8;"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="title">
                <span style="background-color: #f59e0b;">
                    <i class="fas fa-hourglass-half"></i>
                </span>
                <p class="title-text">Bekleyen</p>
                <p class="percent">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 1792 1792" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1408 1216q0 26-19 45t-45 19h-896q-26 0-45-19t-19-45 19-45l448-448q19-19 45-19t45 19l448 448q19 19 19 45z"></path>
                    </svg>
                    <?php echo intval($stats['total'] ?? 0) > 0 ? round((intval($stats['pending'] ?? 0) / intval($stats['total'] ?? 1)) * 100) : 0; ?>%
                </p>
            </div>
            <div class="data">
                <p><?php echo intval($stats['pending'] ?? 0); ?></p>
                <div class="range">
                    <div class="fill" style="width: <?php echo intval($stats['total'] ?? 0) > 0 ? round((intval($stats['pending'] ?? 0) / intval($stats['total'] ?? 1)) * 100) : 0; ?>%; background-color: #f59e0b;"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="title">
                <span style="background-color: #3b82f6;">
                    <i class="fas fa-check-circle"></i>
                </span>
                <p class="title-text">Onaylı</p>
                <p class="percent">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 1792 1792" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1408 1216q0 26-19 45t-45 19h-896q-26 0-45-19t-19-45 19-45l448-448q19-19 45-19t45 19l448 448q19 19 19 45z"></path>
                    </svg>
                    <?php echo intval($stats['total'] ?? 0) > 0 ? round((intval($stats['confirmed'] ?? 0) / intval($stats['total'] ?? 1)) * 100) : 0; ?>%
                </p>
            </div>
            <div class="data">
                <p><?php echo intval($stats['confirmed'] ?? 0); ?></p>
                <div class="range">
                    <div class="fill" style="width: <?php echo intval($stats['total'] ?? 0) > 0 ? round((intval($stats['confirmed'] ?? 0) / intval($stats['total'] ?? 1)) * 100) : 0; ?>%; background-color: #3b82f6;"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="title">
                <span style="background-color: #10b981;">
                    <i class="fas fa-check-double"></i>
                </span>
                <p class="title-text">Tamamlanan</p>
                <p class="percent">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 1792 1792" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1408 1216q0 26-19 45t-45 19h-896q-26 0-45-19t-19-45 19-45l448-448q19-19 45-19t45 19l448 448q19 19 19 45z"></path>
                    </svg>
                    <?php echo intval($stats['total'] ?? 0) > 0 ? round((intval($stats['completed'] ?? 0) / intval($stats['total'] ?? 1)) * 100) : 0; ?>%
                </p>
            </div>
            <div class="data">
                <p><?php echo intval($stats['completed'] ?? 0); ?></p>
                <div class="range">
                    <div class="fill" style="width: <?php echo intval($stats['total'] ?? 0) > 0 ? round((intval($stats['completed'] ?? 0) / intval($stats['total'] ?? 1)) * 100) : 0; ?>%; background-color: #10b981;"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="title">
                <span style="background-color: #ef4444;">
                    <i class="fas fa-times-circle"></i>
                </span>
                <p class="title-text">İptal</p>
                <p class="percent">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 1792 1792" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1408 1216q0 26-19 45t-45 19h-896q-26 0-45-19t-19-45 19-45l448-448q19-19 45-19t45 19l448 448q19 19 19 45z"></path>
                    </svg>
                    <?php echo intval($stats['total'] ?? 0) > 0 ? round((intval($stats['cancelled'] ?? 0) / intval($stats['total'] ?? 1)) * 100) : 0; ?>%
                </p>
            </div>
            <div class="data">
                <p><?php echo intval($stats['cancelled'] ?? 0); ?></p>
                <div class="range">
                    <div class="fill" style="width: <?php echo intval($stats['total'] ?? 0) > 0 ? round((intval($stats['cancelled'] ?? 0) / intval($stats['total'] ?? 1)) * 100) : 0; ?>%; background-color: #ef4444;"></div>
                </div>
            </div>
        </div>
    </div>

    </div>

    <!-- Modern Kaydırmalı Tarih Seçici -->
    <div class="date-picker">
        <div class="date-header">
            <div class="controls">
                <button class="ctrl-btn" onclick="changeDatePickerDay(-1)" aria-label="Önceki Gün">◀</button>
                <button class="ctrl-btn" onclick="goToday()" aria-label="Bugüne Dön">Bugün</button>
                <button class="ctrl-btn" onclick="changeDatePickerDay(1)" aria-label="Sonraki Gün">▶</button>
            </div>
            <strong id="datePickerTitle">
                <?php
                // Date picker için tarih: filter_date_from boşsa bugünü kullan
                $picker_date = !empty($filter_date_from) ? $filter_date_from : date('Y-m-d');
                $current_date = new DateTime($picker_date);
                $months_tr = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
                echo $current_date->format('j') . ' ' . $months_tr[(int)$current_date->format('n') - 1] . ' ' . $current_date->format('Y');
                ?>
            </strong>
        </div>
        
        <div class="days-wrapper">
            <div class="days-track" id="daysTrack">
                <?php
                $days_tr = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];
                
                // Mobil kontrolü: User-Agent ile basit kontrol
                $is_mobile = preg_match('/(android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini)/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
                
                // Mobilde 5 gün (ortada seçili, ±2), masaüstünde 11 gün (±5)
                $range = $is_mobile ? 2 : 5;
                
                for ($i = -$range; $i <= $range; $i++) {
                    $date = clone $current_date;
                    $date->modify("$i day");
                    $is_selected = ($i === 0);
                    $is_today = ($date->format('Y-m-d') === date('Y-m-d'));
                    
                    $day_name = $days_tr[(int)$date->format('w')];
                    $day_number = $date->format('j');
                    $month_name = $months_tr[(int)$date->format('n') - 1];
                ?>
                <div class="day<?php echo $is_selected ? ' selected' : ''; ?><?php echo $is_today ? ' is-today' : ''; ?>"
                     data-date="<?php echo $date->format('Y-m-d'); ?>"
                     data-offset="<?php echo $i; ?>"
                     onclick="slideToDate('<?php echo $date->format('Y-m-d'); ?>', <?php echo $i; ?>)">
                    <span><?php echo $day_name; ?></span>
                    <strong><?php echo $day_number; ?></strong>
                    <span><?php echo $month_name; ?></span>
                    <?php if ($is_today): ?>
                        <small class="today-badge">Bugün</small>
                    <?php endif; ?>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="card appointments-card">
        
        <div class="card-header">
            <div class="bulk-actions-wrapper">
                <!-- Sol: Tümünü Seç -->
                <div class="bulk-select-info">
                    <div class="select-all-container">
                        <input type="checkbox" id="selectAllMobile" onchange="toggleSelectAll()">
                        <label for="selectAllMobile">Tümünü Seç</label>
                    </div>
                    <div class="record-count">
                        <span>Randevular (<strong><?php echo $total_records; ?></strong> kayıt)</span>
                    </div>
                </div>
                
                <!-- Sağ: Filtre ve Ekle Butonları -->
                <div class="header-buttons" id="headerButtons">
                    <button class="btn btn-info btn-sm" onclick="showAllAppointments()"><i class="fas fa-list"></i> Tümünü Gör</button>
                    <button class="btn btn-secondary btn-sm" onclick="openFilterSidebar()"><i class="fas fa-filter"></i> Filtrele</button>
                    <button class="btn btn-success btn-sm" onclick="openAddAppointmentModal()"><i class="fas fa-plus"></i> Yeni Randevu</button>
                </div>
                
                <!-- Sağ: Bulk Action Butonları (Seçim yapılınca görünür) -->
                <div class="bulk-actions-controls" id="bulkActionsControls" style="display: none;">
                    <span class="selected-count" id="selectedCount">0 randevu seçildi</span>
                    <select id="bulkActionSelect" class="form-control">
                        <option value="">İşlem Seçin</option>
                        <option value="pending">Beklemeye Al</option>
                        <option value="confirmed">Onayla</option>
                        <option value="completed">Tamamla</option>
                        <option value="cancelled">İptal Et</option>
                        <option value="no_show">Gelmedi</option>
                    </select>
                    <button type="button" class="btn btn-danger btn-sm" onclick="executeBulkDelete()">
                        <i class="fas fa-trash-alt"></i> Kalıcı Sil
                    </button>
                    <button type="button" class="btn btn-warning btn-sm" onclick="executeBulkAction()">
                        <i class="fas fa-check"></i> Uygula
                    </button>
                </div>
            </div>
        </div>

        <!-- Tablo Container - AJAX ile doldurulacak -->
        <div class="appointments-table-container">
            <!-- Boş durum - AJAX sonrası gösterilecek -->
            <div class="empty-state" style="display: none;">
                <i class="fas fa-calendar-times"></i>
                <p>Filtreyle eşleşen randevu bulunamadı.</p>
                <p class="empty-state-subtitle">Filtreleri temizleyerek veya yeni bir randevu oluşturarak başlayabilirsiniz.</p>
            </div>
            
            <!-- Tablo wrapper - AJAX sonrası doldurulacak -->
            <div class="table-wrapper">
                <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 40px; color: #ffffff !important;"></th>
                        <th class="sortable" onclick="sortTable('date')" style="color: #ffffff !important;">
                            Tarih/Saat
                            <i class="fas fa-sort" style="opacity: 0.3;"></i>
                        </th>
                        <th class="sortable" onclick="sortTable('customer')" style="color: #ffffff !important;">
                            Müşteri
                            <i class="fas fa-sort" style="opacity: 0.3;"></i>
                        </th>
                        <th style="color: #ffffff !important;">Telefon</th>
                        <th class="sortable" onclick="sortTable('employee')" style="color: #ffffff !important;">
                            Çalışan
                            <i class="fas fa-sort" style="opacity: 0.3;"></i>
                        </th>
                        <th class="sortable" onclick="sortTable('service')" style="color: #ffffff !important;">
                            Hizmet
                            <i class="fas fa-sort" style="opacity: 0.3;"></i>
                        </th>
                        <th class="sortable" onclick="sortTable('status')" style="color: #ffffff !important;">
                            Durum
                            <i class="fas fa-sort" style="opacity: 0.3;"></i>
                        </th>
                        <th class="sortable" onclick="sortTable('price')" style="color: #ffffff !important;">
                            Ücret
                            <i class="fas fa-sort" style="opacity: 0.3;"></i>
                        </th>
                        <th style="color: #ffffff !important;">İşlemler</th>
                    </tr>
                </thead>
                <tbody id="appointmentsTableBody">
                    <!-- AJAX ile doldurulacak -->
                </tbody>
                </table>
            </div>
        </div>

    <!-- AJAX Pagination Wrapper -->
    <div class="pagination-wrapper">
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <div class="pagination-info">
                Toplam <?php echo $total_records; ?> kayıttan <?php echo (($page - 1) * $per_page) + 1; ?>-<?php echo min($page * $per_page, $total_records); ?> arası gösteriliyor
            </div>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                    <button class="page-btn" onclick="goToPage(1)">İlk</button>
                    <button class="page-btn" onclick="goToPage(<?php echo $page - 1; ?>)"><i class="fas fa-chevron-left"></i></button>
                <?php endif; ?>
                <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <button class="page-btn active"><?php echo $i; ?></button>
                    <?php else: ?>
                        <button class="page-btn" onclick="goToPage(<?php echo $i; ?>)"><?php echo $i; ?></button>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <button class="page-btn" onclick="goToPage(<?php echo $page + 1; ?>)"><i class="fas fa-chevron-right"></i></button>
                    <button class="page-btn" onclick="goToPage(<?php echo $total_pages; ?>)">Son</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    </div>
    </div>
</div>

<div id="appointmentModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeAppointmentModal()">&times;</span>
        <div class="admin-cart-toolbar">
            <h3 id="modalTitle" class="admin-cart-toolbar-title">Yeni Randevu</h3>
            <!-- Sepet Modu Toggle (sadece "add" modunda görünür) -->
            <button type="button" id="cartModeToggleBtn" class="btn btn-sm btn-outline-secondary" onclick="toggleAdminCartMode()" title="Birden fazla randevu eklemek için sepet modunu açın" style="display:none;">
                <i class="fas fa-shopping-cart"></i> <span id="cartModeToggleLbl">Sepet Modu</span>
            </button>
        </div>
        
        <!-- Müşteri Bulundu Bildirim Kartı -->
        <div id="customerInfoAlert" class="customer-found-alert" style="display: none;">
            <button class="cfa-close" onclick="document.getElementById('customerInfoAlert').style.display='none'">×</button>
            <div class="cfa-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"></path><circle cx="12" cy="12" r="10"></circle></svg>
            </div>
            <div class="cfa-text">
                <div class="cfa-title">Müşteri Bulundu</div>
                <div class="cfa-message">Bilgiler otomatik olarak dolduruldu</div>
            </div>
        </div>
        
        <form id="appointmentForm">
            <input type="hidden" id="appointment_id" name="appointment_id">
            <input type="hidden" id="form_action" name="action" value="add">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="customer_phone">Telefon *</label>
                    <input type="tel" id="customer_phone" name="customer_phone" class="form-control" required placeholder="5XX XXX XX XX" maxlength="15">
                </div>
                <div class="form-group">
                    <label for="customer_name">Müşteri Adı Soyadı *</label>
                    <input type="text" id="customer_name" name="customer_name" class="form-control" required placeholder="Ad Soyad giriniz" maxlength="50">
                </div>
                <div class="form-group">
                    <label for="employee_id">Çalışan *</label>
                    <select id="employee_id" name="employee_id" class="form-control" required>
                        <option value="">Çalışan Seçiniz</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?php echo $employee['id']; ?>"><?php echo htmlspecialchars($employee['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="appointment_date">Tarih *</label>
                    <input type="date" id="appointment_date" name="appointment_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label for="appointment_time">Saat *</label>
                    <input type="time" id="appointment_time" name="appointment_time" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="appointment_status">Durum</label>
                    <select id="appointment_status" name="status" class="form-control">
                        <option value="pending">Bekleyen</option>
                        <option value="confirmed" selected>Onaylı</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Hizmetler *</label>
                <input type="text" class="form-control service-search-input" id="serviceSearchInput" placeholder="Hizmet ara..." autocomplete="off">
                <div class="services-selection" id="servicesSelection">
                    <?php foreach($services as $service): 
                        $effective_price = ($service['pricing_type'] === 'range' && $service['price_min'] !== null && $service['price_min'] !== '') ? $service['price_min'] : $service['price'];
                        $is_variant = ($service['pricing_type'] === 'variant');
                        $is_range = ($service['pricing_type'] === 'range');
                        $variants = $is_variant ? ($service_variants_map[$service['id']] ?? []) : [];
                        if ($is_range) {
                            $price_label = formatPrice($service['price_min']) . ' - ' . formatPrice($service['price_max']);
                        } elseif ($is_variant) {
                            $price_label = formatPrice($service['price']) . '+';
                        } else {
                            $price_label = formatPrice($service['price']);
                        }
                    ?>
                        <div class="service-item-wrapper">
                            <label>
                                <input type="checkbox" name="service_ids[]" value="<?php echo $service['id']; ?>" class="service-checkbox" data-price="<?php echo $effective_price; ?>" data-pricing-type="<?php echo $service['pricing_type']; ?>" data-is-range="<?php echo $is_range ? '1' : '0'; ?>" data-is-variant="<?php echo $is_variant ? '1' : '0'; ?>" data-is-multiple="<?php echo ($service['is_multiple'] ?? 0) ? '1' : '0'; ?>" data-price-min="<?php echo $service['price_min']; ?>" data-price-max="<?php echo $service['price_max']; ?>" data-service-id="<?php echo $service['id']; ?>" onchange="updateTotalPrice()">
                                <?php echo htmlspecialchars($service['name']); ?> - <?php echo $price_label; ?>
                            </label>
                            <?php if ($is_range): ?>
                            <div class="edit-range-input" data-service-id="<?php echo $service['id']; ?>" style="margin-left: 24px; margin-top: 4px; display: none; align-items: center; gap: 6px;">
                                <span style="font-size:0.8em; color:#6b7280;">Alınan tutar:</span>
                                <input type="number" class="edit-range-actual-price" data-service-id="<?php echo $service['id']; ?>" value="<?php echo htmlspecialchars((string)$effective_price); ?>" min="<?php echo $service['price_min']; ?>" max="<?php echo $service['price_max']; ?>" step="any" style="width:90px; padding:4px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.85em; text-align:right;" oninput="updateTotalPrice()" onchange="updateTotalPrice()">
                                <span style="font-size:0.8em; color:#6b7280;">TL</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($is_variant && !empty($variants)): ?>
                            <div class="variant-panel" data-service-id="<?php echo $service['id']; ?>" style="display:none; margin-left:24px; margin-top:6px; padding:8px 12px; background:rgba(139,92,246,0.06); border:1px solid rgba(139,92,246,0.2); border-radius:8px;">
                                <div style="font-size:0.8em; color:#8b5cf6; font-weight:600; margin-bottom:6px;">Varyant Seçimi:</div>
                                <?php foreach($variants as $variant): ?>
                                <label style="display:flex; align-items:center; gap:6px; padding:4px 0; cursor:pointer; font-size:0.85em;">
                                    <input type="<?php echo ($service['is_multiple'] ?? 0) ? 'checkbox' : 'radio'; ?>" name="variant_<?php echo $service['id']; ?>[]" value="<?php echo $variant['id']; ?>" class="variant-input" data-service-id="<?php echo $service['id']; ?>" data-extra-price="<?php echo $variant['extra_price']; ?>" data-extra-duration="<?php echo $variant['extra_duration']; ?>" onchange="updateTotalPrice()">
                                    <span><?php echo htmlspecialchars($variant['name']); ?></span>
                                    <?php if ($variant['extra_price'] > 0): ?>
                                    <span style="color:#8b5cf6; font-weight:600;">+<?php echo formatPrice($variant['extra_price']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($variant['extra_duration'] > 0): ?>
                                    <span style="color:#6b7280; font-size:0.85em;">(+<?php echo $variant['extra_duration']; ?> dk)</span>
                                    <?php endif; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="service-price-display" id="totalPriceDisplay">Toplam: 0.00 ₺</div>
            </div>
            
            <div class="form-group">
                <label for="notes">Notlar</label>
                <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Randevu notları..."></textarea>
            </div>

            <!-- Tekrarlayan Randevu Bölümü -->
            <div class="recurring-section" id="recurringSection">
                <div class="recurring-toggle">
                    <label class="recurring-switch">
                        <input type="checkbox" id="is_recurring" name="is_recurring" onchange="toggleRecurringOptions()">
                        <span class="recurring-switch-slider"></span>
                    </label>
                    <span class="recurring-toggle-label">
                        <i class="fas fa-redo-alt"></i> Tekrarlayan Randevu
                    </span>
                </div>

                <div class="recurring-options" id="recurringOptions" style="display: none;">
                    <div class="recurring-options-grid">
                        <div class="form-group">
                            <label for="recurring_type">Tekrar Tipi *</label>
                            <select id="recurring_type" name="recurring_type" class="form-control" onchange="toggleCustomDays()">
                                <option value="weekly">Her Hafta</option>
                                <option value="biweekly">İki Haftada Bir</option>
                                <option value="monthly">Her Ay</option>
                                <option value="daily">Her Gün</option>
                                <option value="custom">Özel Günler</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="recurring_end_date">Bitiş Tarihi *</label>
                            <input type="date" id="recurring_end_date" name="recurring_end_date" class="form-control">
                        </div>
                    </div>

                    <!-- Özel Gün Seçimi -->
                    <div class="recurring-custom-days" id="recurringCustomDays" style="display: none;">
                        <label>Hangi Günler?</label>
                        <div class="day-chips">
                            <label class="day-chip"><input type="checkbox" name="recurring_days[]" value="1"><span>Pzt</span></label>
                            <label class="day-chip"><input type="checkbox" name="recurring_days[]" value="2"><span>Sal</span></label>
                            <label class="day-chip"><input type="checkbox" name="recurring_days[]" value="3"><span>Çar</span></label>
                            <label class="day-chip"><input type="checkbox" name="recurring_days[]" value="4"><span>Per</span></label>
                            <label class="day-chip"><input type="checkbox" name="recurring_days[]" value="5"><span>Cum</span></label>
                            <label class="day-chip"><input type="checkbox" name="recurring_days[]" value="6"><span>Cmt</span></label>
                            <label class="day-chip"><input type="checkbox" name="recurring_days[]" value="7"><span>Paz</span></label>
                        </div>
                    </div>

                    <!-- Önizleme -->
                    <div class="recurring-preview" id="recurringPreview" style="display: none;">
                        <div class="recurring-preview-header">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Oluşturulacak Randevular</span>
                            <span class="recurring-preview-count" id="recurringCount">0</span>
                        </div>
                        <div class="recurring-preview-dates" id="recurringDates"></div>
                        <div class="recurring-preview-conflicts" id="recurringConflicts" style="display: none;">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span id="conflictText"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:2rem;padding-top:1rem;border-top:1px solid var(--border-color,#e2e8f0);">
                <label class="sms-info-checkbox" style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.87rem;color:var(--text-secondary,#475569);font-weight:500;margin:0;">
                    <input type="checkbox" id="send_customer_sms" name="send_customer_sms" value="1">
                    <span>Müşteriye bilgilendirme SMS gönderilsin</span>
                </label>
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeAppointmentModal()"><i class="fas fa-times"></i> İptal</button>
                    <!-- Normal mod: kaydet -->
                    <button type="submit" id="normalSaveBtn" class="btn btn-primary"><span class="loading" style="display: none;"></span><span class="btn-text"><i class="fas fa-save"></i> Kaydet</span></button>
                    <!-- Sepet modu: sepete ekle -->
                    <button type="button" id="addToCartBtn" class="btn btn-info" onclick="addToAdminCart()" style="display:none;"><i class="fas fa-cart-plus"></i> Sepete Ekle</button>
                </div>
            </div>
        </form>

        <!-- ── Sepet Listesi (Sepet Modunda Görünür) ── -->
        <div id="adminCartPanel" class="admin-cart-panel" style="display:none;">
            <div class="admin-cart-panel-header">
                <h4 class="admin-cart-panel-title"><i class="fas fa-shopping-cart admin-cart-panel-icon"></i> Sepet (<span id="adminCartCount">0</span> randevu)</h4>
                <button type="button" class="btn btn-sm btn-secondary" onclick="clearAdminCart()"><i class="fas fa-trash"></i> Sepeti Temizle</button>
            </div>
            <div id="adminCartItems" class="admin-cart-items"></div>
            <div class="admin-cart-panel-footer">
                <span id="adminCartTotal" class="admin-cart-total"></span>
                <button type="button" id="submitCartBtn" class="btn btn-success" onclick="submitAdminCart()" style="display:none;">
                    <span class="loading" id="cartLoadingSpinner" style="display:none;"></span>
                    <i class="fas fa-check-double"></i> Tümünü Kaydet (<span id="cartBtnCount">0</span>)
                </button>
            </div>
        </div>
    </div>
</div>

<div id="customerConfirmationModal" class="customer-confirmation-modal">
    <div class="customer-confirmation-content">
        <div class="customer-confirmation-icon">
            <i class="fas fa-user-check"></i>
        </div>
        
        <h3 class="customer-confirmation-title">Mevcut Müşteri</h3>
        <p class="customer-confirmation-subtitle">Bu telefon numarası sistemde kayıtlı!</p>
        
        <div class="customer-info-display">
            <h4><i class="fas fa-address-card"></i> Kayıtlı Müşteri Bilgileri</h4>
            <div class="customer-detail">
                <i class="fas fa-user"></i>
                <strong>Ad:</strong>
                <span id="existing-customer-name"></span>
            </div>
            <div class="customer-detail">
                <i class="fas fa-phone"></i>
                <strong>Telefon:</strong>
                <span id="existing-customer-phone"></span>
            </div>
        </div>
        
        <div class="customer-confirmation-question">
            <i class="fas fa-question-circle"></i>
            Bu numara kayıtlı müşteriye ait. Bu kişi ile randevu oluşturulsun mu?
        </div>
        
        <div class="customer-confirmation-buttons">
            <button type="button" class="btn-customer-confirm" onclick="confirmExistingCustomer()">
                <i class="fas fa-check"></i>
                Evet, Devam Et
            </button>
            <button type="button" class="btn-customer-cancel" onclick="cancelExistingCustomer()">
                <i class="fas fa-times"></i>
                İptal
            </button>
        </div>
    </div>
</div>

<div id="errorModal" class="error-modal">
    <div class="error-content">
        <div class="error-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="error-title">Hata!</h3>
        <p id="error-message-text"></p>
        <button type="button" class="btn btn-danger" onclick="closeErrorModal()">Tamam</button>
    </div>
</div>

<div id="deleteConfirmationModal" class="delete-modal">
    <div class="delete-content">
        <div class="delete-icon"><i class="fas fa-trash-alt"></i></div>
        <h3 class="delete-title">Randevuyu Sil</h3>
        <p id="delete-message-text" style="margin-bottom: 2rem;"></p>
        <div class="delete-buttons">
            <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">İptal</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Evet, Sil</button>
        </div>
    </div>
</div>

<div id="generalConfirmationModal" class="confirmation-modal">
    <div class="confirmation-content">
        <div class="confirmation-icon"><i class="fas fa-question-circle"></i></div>
        <h3 class="confirmation-title">İşlemi Onayla</h3>
        <p id="general-confirmation-message-text" style="margin-bottom: 2rem;"></p>
        <div class="confirmation-buttons">
            <button type="button" class="btn btn-secondary" onclick="closeGeneralConfirmationModal()">İptal</button>
            <button type="button" class="btn btn-warning" id="confirmGeneralActionBtn">Evet, Onayla</button>
        </div>
    </div>
</div>

<div id="pastDateModal" class="confirmation-modal">
    <div class="confirmation-content">
        <div class="confirmation-icon" style="color: #f59e0b;"><i class="fas fa-calendar-times"></i></div>
        <h3 class="confirmation-title">Geçmiş Tarih Uyarısı</h3>
        <p id="past-date-message" style="margin-bottom: 1rem; font-size: 1.1rem; color: #f59e0b;">Randevu eklemek istediğiniz tarih geçmiş bir tarih. Yine de eklemek istiyor musunuz?</p>
        <p style="margin-bottom: 2rem; font-size: 0.9rem; color: #6b7280; font-style: italic;">
            <i class="fas fa-info-circle"></i> Bu özellik, unutulan randevu kayıtları için kullanılabilir.
        </p>
        <div class="confirmation-buttons">
            <button type="button" class="btn btn-secondary" onclick="closePastDateModal()">İptal</button>
            <button type="button" class="btn btn-warning" id="confirmPastDateBtn">Evet, Ekle</button>
        </div>
    </div>
</div>

<div id="completeModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeCompleteModal()">&times;</span>
        <h3>Randevuyu Tamamla</h3>
        <form id="completeForm">
            <input type="hidden" id="complete_appointment_id" name="appointment_id">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="payment_method" id="paymentMethodHidden" value="cash">
            
            <div class="form-group">
                <label>Verilen Hizmetler</label>
                <div id="completeServiceList" class="complete-modal-services"></div>
            </div>
            
            <div class="form-group">
                <label for="complete_notes">Notlar</label>
                <textarea id="complete_notes" name="notes" class="form-control" rows="3" placeholder="Randevu notları..."></textarea>
            </div>
            
            <!-- Ürün Tüketim Onay Bölümü -->
            <div class="form-group" id="productConsumptionSection" style="display:none;">
                <div class="product-consumption-header" onclick="toggleProductConsumption()">
                    <label style="cursor:pointer; margin:0; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-boxes" style="color:var(--primary-color);"></i>
                        Kullanılan Ürünler
                        <span id="productConsumptionCount" class="badge bg-primary" style="font-size:0.7rem;"></span>
                        <i class="fas fa-chevron-down" id="productConsumptionArrow" style="font-size:0.7rem; margin-left:auto; transition:transform 0.2s;"></i>
                    </label>
                </div>
                <div id="productConsumptionBody" style="display:none; margin-top:8px;">
                    <div id="productConsumptionList" style="max-height:200px; overflow-y:auto;"></div>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addProductToConsumption()" style="margin-top:6px; width:100%;">
                        <i class="fas fa-plus"></i> Ürün Ekle
                    </button>
                </div>
            </div>
            
            <div class="form-group complete-modal-total">
                <label for="final_price">Toplam Tutar</label>
                <input type="number" step="0.01" name="final_price" id="final_price" class="form-control">
            </div>
            
            <div class="form-group complete-modal-payment">
                <button type="button" class="btn btn-success" onclick="setPaymentMethodAndSubmit('cash')">
                    <i class="fas fa-money-bill-wave"></i> 
                    <span class="payment-btn-text-full">Nakit Kaydet</span>
                    <span class="payment-btn-text-short">Nakit</span>
                </button>
                <button type="button" class="btn btn-primary" onclick="setPaymentMethodAndSubmit('bank_transfer')">
                    <i class="fas fa-credit-card"></i> 
                    <span class="payment-btn-text-full">Havale/EFT Kaydet</span>
                    <span class="payment-btn-text-short">Havale/EFT</span>
                </button>
                <button type="button" class="btn btn-info" onclick="setPaymentMethodAndSubmit('credit_card')">
                    <i class="fas fa-credit-card"></i>
                    <span class="payment-btn-text-full">Kredi Kartı Kaydet</span>
                    <span class="payment-btn-text-short">Kredi Kartı</span>
                </button>
                <button type="button" class="btn btn-warning" onclick="showNotPaidOptions('complete')" style="background:#ef4444;border-color:#ef4444;color:#fff;">
                    <i class="fas fa-times-circle"></i> 
                    <span class="payment-btn-text-full">Alınmadı</span>
                    <span class="payment-btn-text-short">Alınmadı</span>
                </button>
            </div>

            <!-- Alınmadı Alt Seçenekler -->
            <div id="notPaidOptionsPanel" class="not-paid-options-panel" style="display:none">
                <div class="not-paid-option-row">
                    <label class="not-paid-radio-label">
                        <input type="radio" name="not_paid_type" value="full" checked onchange="onNotPaidTypeChange()"> Tamamı alınmadı
                    </label>
                    <label class="not-paid-radio-label">
                        <input type="radio" name="not_paid_type" value="partial" onchange="onNotPaidTypeChange()"> Belirli miktar alınmadı
                    </label>
                </div>
                <div id="partialPaymentFields" style="display:none">
                    <div class="partial-payment-row">
                        <div class="partial-payment-field">
                            <label>Alınan Tutar (₺)</label>
                            <input type="number" step="0.01" min="0" id="partialPaidAmount" class="form-control" placeholder="0.00" oninput="updateRemainingAmount()">
                        </div>
                        <div class="partial-payment-field">
                            <label>Ödeme Türü</label>
                            <select id="partialPaymentMethod" class="form-control">
                                <option value="cash">Nakit</option>
                                <option value="bank_transfer">Havale/EFT</option>
                                <option value="credit_card">Kredi Kartı</option>
                            </select>
                        </div>
                    </div>
                    <div class="partial-remaining-info">
                        Kalan (Alınmadı): <strong id="remainingAmountDisplay">0.00 ₺</strong>
                    </div>
                </div>
                <div class="not-paid-options-actions">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="cancelNotPaidOptions()"><i class="fas fa-times"></i> İptal</button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="confirmNotPaid()"><i class="fas fa-check"></i> Kaydet</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Filter Sidebar -->
<div class="filter-overlay" id="filterOverlay" onclick="closeFilterSidebar()"></div>
<div class="filter-sidebar" id="filterSidebar">
    <div class="sidebar-header">
        <h3>
            <span class="header-icon"><i class="fas fa-filter"></i></span>
            Filtrele & Sırala
        </h3>
        <button type="button" class="close-sidebar-btn" onclick="closeFilterSidebar()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <form id="filterForm" onsubmit="return false;">
        <div class="sidebar-content">
            <div class="filter-group">
                <label for="date_from">
                    <span class="label-icon icon-date"><i class="fas fa-calendar"></i></span>
                    Başlangıç Tarihi
                </label>
                <input type="date" id="date_from" name="date_from" class="form-control" value="<?php echo date('Y-m-d'); ?>" min="2000-01-01">
            </div>
            
            <div class="filter-group">
                <label for="date_to">
                    <span class="label-icon icon-date"><i class="fas fa-calendar-check"></i></span>
                    Bitiş Tarihi
                </label>
                <input type="date" id="date_to" name="date_to" class="form-control" value="<?php echo date('Y-m-d'); ?>" min="2000-01-01">
            </div>
            
            <div class="filter-group">
                <label for="employee_filter">
                    <span class="label-icon icon-user"><i class="fas fa-user"></i></span>
                    Çalışan
                </label>
                <select id="employee_filter" name="employee" class="form-control">
                    <option value="">Tüm Çalışanlar</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?php echo $employee['id']; ?>">
                            <?php echo htmlspecialchars($employee['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="status_filter">
                    <span class="label-icon icon-status"><i class="fas fa-toggle-on"></i></span>
                    Durum
                </label>
                <select id="status_filter" name="status" class="form-control">
                    <option value="">Tüm Durumlar</option>
                    <option value="pending">Bekleyen</option>
                    <option value="confirmed">Onaylı</option>
                    <option value="completed">Tamamlanan</option>
                    <option value="cancelled">İptal</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="customer_filter">
                    <span class="label-icon icon-search"><i class="fas fa-search"></i></span>
                    Müşteri Adı/Telefonu
                </label>
                <input type="text" id="customer_filter" name="customer" class="form-control" placeholder="Müşteri ara...">
            </div>
            
            <div class="filter-group">
                <label for="per_page_filter">
                    <span class="label-icon icon-sort"><i class="fas fa-list-ol"></i></span>
                    Sayfa Başı
                </label>
                <select id="per_page_filter" name="per_page" class="form-control">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
        <div class="sidebar-footer">
            <button type="button" class="btn btn-apply" onclick="applyFilters()">
                <i class="fas fa-check"></i> Uygula
            </button>
            <button type="button" class="btn btn-reset" onclick="clearFilters()">
                <i class="fas fa-undo"></i> Temizle
            </button>
        </div>
    </form>
</div>

<!-- Appointments JS - Inline script'ten önce yüklenmeli -->
<script src="assets/js/appointments.js?v=<?php echo time(); ?>"></script>
<?php require_once __DIR__ . '/../config/service_qty.php'; ?>
<script>window.SVC_MAX_QTY = <?php echo json_encode((object)svcQtyMaxMap($db, (int)TENANT_ID)); ?>;</script>
<script src="assets/js/service_qty.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/service_qty.js'); ?>"></script>

<script>
    // Bugünün tarihi
    const today = new Date().toISOString().split('T')[0];
    
    window.appointmentsData = {
        services: <?php echo json_encode($services); ?>,
        serviceVariants: <?php echo json_encode($service_variants_map); ?>,
        currentFilters: {
            date_from: today,
            date_to: today, // UI'da bugün seçili görünsün
            employee: '',
            status: '',
            customer: '',
            per_page: 25,
            page: 1,
            sort: 'date',
            order: 'asc',
            show_all: false
        }
    };
    
    // URL'i temizle (eski query parametrelerini kaldır)
    if (window.location.search) {
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    
    // Sayfa yüklendiğinde filtreleri ayarla ve randevuları AJAX ile yükle
    document.addEventListener('DOMContentLoaded', function() {
        if (window.appointmentsData && window.appointmentsData.currentFilters) {
            if (typeof currentFilters !== 'undefined') {
                Object.assign(currentFilters, window.appointmentsData.currentFilters);
            }
            
            // İlk yüklemede randevuları AJAX ile çek
            if (typeof loadAppointments === 'function') {
                loadAppointments(window.appointmentsData.currentFilters);
            }
        }
    });

    // Mobil Tümünü Seç İşlevi - Sayfa Yüklendiğinde
    document.addEventListener('DOMContentLoaded', function() {
        const selectAllMobile = document.getElementById('selectAllMobile');
        const selectAllDesktop = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.appointment-checkbox:not(#selectAll):not(#selectAllMobile)');

        // Mobil kutu değiştiğinde
        if (selectAllMobile) {
            selectAllMobile.addEventListener('change', function() {
                const isChecked = this.checked;
                
                // Tüm satırları seç/kaldır
                checkboxes.forEach(cb => {
                    cb.checked = isChecked;
                    // change eventini manuel tetikle ki toplu işlem menüsü güncellensin
                    const event = new Event('change');
                    cb.dispatchEvent(event);
                });

                // Masaüstü kutusunu da senkronize et
                if (selectAllDesktop) selectAllDesktop.checked = isChecked;
            });
        }

        // Masaüstü kutusu değiştiğinde mobili senkronize et
        if (selectAllDesktop) {
            selectAllDesktop.addEventListener('change', function() {
                if (selectAllMobile) selectAllMobile.checked = this.checked;
            });
        }
    });
</script>

<?php if ($success || $error): ?>
<script>
    // PHP mesajlarını JavaScript notification'a çevir
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($success): ?>
            if (typeof showSuccess === 'function') {
                showSuccess(<?php echo json_encode($success); ?>);
            }
        <?php endif; ?>
        
        <?php if ($error): ?>
            if (typeof showError === 'function') {
                showError(<?php echo json_encode($error); ?>);
            }
        <?php endif; ?>
    });
</script>
<?php endif; ?>

<script>
// Hizmet arama filtresi
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('serviceSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().replace(/İ/gi, 'i').replace(/I/g, 'ı');
            const container = document.getElementById('servicesSelection');
            if (!container) return;
            container.querySelectorAll('.service-item-wrapper').forEach(function(item) {
                const label = item.querySelector('label');
                if (!label) return;
                const text = label.textContent.toLowerCase().replace(/İ/gi, 'i').replace(/I/g, 'ı');
                item.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }
});
</script>

<?php
// Footer dosyasını include et
if (file_exists('includes/footer.php')) {
    include 'includes/footer.php';
} else {
    echo "</body></html>";
}

// Output buffer'ı temizle ve gönder
ob_end_flush();
?>
