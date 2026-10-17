<?php
// Çıktı tamponlamayı başlat
ob_start();
// Hata raporlamayı aç (log dosyasına)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// config.php dosyasının varlığını ve yolunu kontrol et
$config_path = __DIR__ . '/../config/config.php';
if (!file_exists($config_path)) {
    die("Kritik Hata: Yapılandırma dosyası bulunamadı. `admin` klasörünün ana dizinde olduğundan emin olun.");
}
require_once $config_path;

// Gerekli fonksiyonların varlığını kontrol et
if (!function_exists('isLoggedIn') || !function_exists('checkSessionTimeout') || !function_exists('redirect')) {
    die("Kritik Hata: Gerekli oturum fonksiyonları (isLoggedIn, checkSessionTimeout, redirect) yüklenemedi. Lütfen config.php dosyanızı kontrol edin.");
}

if (!isLoggedIn()) {
    redirect('login.php');
}

// Araç kategorileri berber defteri yerine servis kabullerini kullanır
if (function_exists('is_vehicle_service_category') && is_vehicle_service_category()) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: servis_kabulleri.php' . ($qs !== '' ? ('?' . $qs) : ''), true, 302);
    exit;
}

// SAYFA ERİŞİM KONTROLÜ
if (!can_access_page('daily_view.php')) {
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

// Mola/boş saat için kullanılacak olan sistem müşterisinin ID'sini getiren fonksiyon (GÜNCELLENDİ)
function getBreakCustomerId() {
    global $db;
    $break_phone_number = '99999999999'; // Mola için ayrılmış özel numara
    $break_customer_name = 'Sistem Molası';

    // 1. Bu numaraya sahip global müşteri var mı?
    $stmt = $db->prepare("SELECT id FROM customers WHERE phone = ?");
    $stmt->execute([$break_phone_number]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($customer) {
        $customer_id = $customer['id'];
    } else {
        // Yoksa oluştur (Misafir olarak, tenant_id NULL)
        $stmt = $db->prepare("INSERT INTO customers (tenant_id, name, phone, is_guest) VALUES (NULL, ?, ?, 1)");
        $stmt->execute([$break_customer_name, $break_phone_number]);
        $customer_id = $db->lastInsertId();
    }

    // 2. Bu işletmeyle ilişkili mi? Değilse ilişkilendir.
    $stmtLink = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), deleted_at = NULL");
    $stmtLink->execute([$customer_id, TENANT_ID, $break_customer_name]);

    return $customer_id;
}

// Müşteri ID'sini ad ve telefondan oluşturan fonksiyon (GÜNCELLENDİ)
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
            // 3. Müşteri Yoksa: Global 'misafir' olarak oluştur
            $stmt = $db->prepare("INSERT INTO customers (tenant_id, name, phone, is_guest) VALUES (NULL, ?, ?, 1)");
            $stmt->execute([$name, $formatted_phone]);
            $new_id = $db->lastInsertId();
            
            // 4. İlişkiyi Kur
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

// Çalışma saatleri ayarları için fonksiyon - 24 saat formatında güncellendi
// VARSAYILAN çalışma saatlerini ayarlardan alır
function getDefaultWorkingHours() {
    global $db;
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'working_hours' AND tenant_id = ?");
    $stmt->execute([TENANT_ID]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $hours = json_decode($result['setting_value'], true);
        return [
            'start' => $hours['start'] ?? 9,
            'end' => $hours['end'] ?? 21
        ];
    }
    
    return ['start' => 9, 'end' => 21];
}

// GÜNLÜK AKIŞ çalışma saatlerini alır (önce daily_working_hours tablosu, yoksa varsayılan)
function getWorkingHours($date = null) {
    global $db;
    
    // daily_working_hours tablosunda bu tenant için kayıt var mı?
    $stmt = $db->prepare("SELECT start_hour, end_hour FROM daily_working_hours WHERE tenant_id = ?");
    $stmt->execute([TENANT_ID]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $start = (int)$result['start_hour'];
        $end = (int)$result['end_hour'];
        if ($start >= 0 && $start <= 23 && $end >= 1 && $end <= 24 && $start < $end) {
            return [
                'start' => $start,
                'end' => $end
            ];
        }
    }
    
    // Yoksa varsayılanı döndür
    return getDefaultWorkingHours();
}

// GÜNLÜK AKIŞ çalışma saatlerini kaydetme fonksiyonu (tenant başına tek kayıt)
function saveDailyWorkingHours($date, $startHour, $endHour) {
    global $db;
    
    // Önce var mı diye kontrol et
    $check = $db->prepare("SELECT id FROM daily_working_hours WHERE tenant_id = ?");
    $check->execute([TENANT_ID]);
    
    if ($check->fetch()) {
        // Varsa Güncelle
        $stmt = $db->prepare("UPDATE daily_working_hours SET start_hour = ?, end_hour = ? WHERE tenant_id = ?");
        $stmt->execute([$startHour, $endHour, TENANT_ID]);
    } else {
        // Yoksa Ekle
        $stmt = $db->prepare("INSERT INTO daily_working_hours (tenant_id, start_hour, end_hour) VALUES (?, ?, ?)");
        $stmt->execute([TENANT_ID, $startHour, $endHour]);
    }
}

// Eski saveWorkingHours fonksiyonu kaldırıldı - artık saveDailyWorkingHours kullanılıyor

// Saati görüntüleme formatına çeviren fonksiyon
function formatDisplayTime($hour) {
    return str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';
}

$page_title = 'Defter';
$success = '';
$error = '';

// URL'den gelen mesajları al
if (isset($_GET['success_msg'])) {
    $success = urldecode($_GET['success_msg']);
}
if (isset($_GET['error_msg'])) {
    $error = urldecode($_GET['error_msg']);
}

// Çalışma saatlerini al - selected_date henüz tanımlanmadı, geçici olarak bugünün tarihini veya GET'ten gelen tarihi kullan
$temp_date = $_GET['date'] ?? date('Y-m-d');
$working_hours = getWorkingHours($temp_date);

// --- AJAX İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (ob_get_length()) ob_clean();
    
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Bilinmeyen bir hata oluştu.'];
    
    try {
        $action = $_POST['action'];
        $appointment_date = sanitize($_POST['appointment_date'] ?? date('Y-m-d'));

        // Müşteri bilgilerini telefon numarasından getir (GÜNCELLENDİ)
        if ($action == 'get_customer_info') {
            $phone = sanitize($_POST['phone'] ?? '');
            if (!$phone) {
                throw new Exception("Telefon numarası gerekli.");
            }
            
            $formatted_phone = formatPhoneNumber($phone);
            
            // 1. Önce bu tenant'ın müşteri listesinde ara
            $stmt = $db->prepare("SELECT COALESCE(ct.name, c.name) as name, c.phone FROM customers c INNER JOIN customer_tenants ct ON c.id = ct.customer_id WHERE c.phone = ? AND ct.tenant_id = ? AND ct.deleted_at IS NULL");
            $stmt->execute([$formatted_phone, TENANT_ID]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($customer) {
                // Bu tenant'ta zaten kayıtlı — per-tenant ismiyle döndür
                $response['success'] = true;
                $response['customer'] = $customer;
                $response['show_confirmation'] = true;
            } else {
                // 2. Global'de var mı? (başka tenant'ta kayıtlı olabilir)
                $stmt2 = $db->prepare("SELECT name, phone FROM customers WHERE phone = ?");
                $stmt2->execute([$formatted_phone]);
                $globalCustomer = $stmt2->fetch(PDO::FETCH_ASSOC);
                
                if ($globalCustomer) {
                    // Global'de var ama bu tenant'ta yok — yeni isimle eklenecek
                    $response['success'] = false;
                    $response['message'] = 'Müşteri bulunamadı';
                } else {
                    $response['success'] = false;
                    $response['message'] = 'Müşteri bulunamadı';
                }
            }
        }
        elseif ($action == 'update_working_hours') {
            $start_hour = intval($_POST['start_hour'] ?? 0);
            $end_hour = intval($_POST['end_hour'] ?? 24);
            
            if ($start_hour < 0 || $start_hour > 23 || $end_hour < 1 || $end_hour > 24 || $start_hour >= $end_hour) {
                throw new Exception("Geçersiz çalışma saati aralığı. Başlangıç saati bitiş saatinden küçük olmalıdır.");
            }
            
            // Güne özel çalışma saatini kaydet (varsayılanı değiştirmez)
            saveDailyWorkingHours($appointment_date, $start_hour, $end_hour);
            
            $response['success'] = true;
            $response['message'] = 'Bu güne özel çalışma saatleri güncellendi!';
            $response['redirect'] = 'daily_view.php?date=' . urlencode($appointment_date);
        }
        elseif ($action == 'add' || $action == 'add_between') {
            $customer_name = sanitizeName($_POST['customer_name'] ?? '', 50);
            $customer_phone = sanitize($_POST['customer_phone'] ?? '');
            $employee_id = intval($_POST['employee_id'] ?? 0);
            $service_ids = isset($_POST['service_ids']) && is_array($_POST['service_ids']) ? $_POST['service_ids'] : [];
            $appointment_time = sanitize($_POST['appointment_time'] ?? '');
            $notes = sanitizeNotes($_POST['notes'] ?? '');
            $status = sanitize($_POST['status'] ?? 'confirmed');
            $is_between = ($action == 'add_between') ? 1 : 0;
            
            // Status validasyonu
            $allowed_statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'break'];
            if (!in_array($status, $allowed_statuses)) {
                $status = 'confirmed';
            }

            if (!$customer_name || !$customer_phone || !$employee_id || empty($service_ids) || !$appointment_date || !$appointment_time) {
                throw new Exception("Lütfen tüm zorunlu alanları doldurunuz.");
            }
            
            // Güncellenmiş fonksiyonu kullanıyoruz
            $customer_data = getOrCreateCustomer($customer_name, $customer_phone);
            $customer_id = $customer_data['id'];

            if (!$customer_data['is_new'] && $customer_data['name'] !== $customer_name && !isset($_POST['force_existing'])) {
                $response['existing_customer'] = true;
                $response['customer_name'] = $customer_data['name'];
                $response['customer_phone'] = $customer_phone;
                $response['message'] = "Bu numara " . htmlspecialchars($customer_data['name']) . " adına kayıtlı. Bu kişi ile devam edilsin mi?";
                echo json_encode($response);
                exit;
            }

            // Toplam fiyat ve süreyi hesapla
            $total_price = 0;
            $total_duration = 0;
            foreach ($service_ids as $service_id) {
                $stmt_price = $db->prepare("SELECT price, duration, pricing_type, price_min FROM services WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL AND status = 'active'");
                $stmt_price->execute([$service_id, TENANT_ID]);
                $service_data = $stmt_price->fetch(PDO::FETCH_ASSOC);
                if ($service_data) {
                    $svc_price = ($service_data['pricing_type'] === 'range' && $service_data['price_min'] !== null) ? (float)$service_data['price_min'] : (float)$service_data['price'];
                    $total_price += $svc_price;
                    $total_duration += $service_data['duration'];
                }
            }

            $db->beginTransaction();
            
            // Randevuyu ekle - tenant_id dahil, status parametresi ile
            $stmt_insert = $db->prepare("INSERT INTO appointments (tenant_id, customer_id, employee_id, appointment_date, appointment_time, status, total_price, total_duration, notes, is_between) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_insert->execute([TENANT_ID, $customer_id, $employee_id, $appointment_date, $appointment_time, $status, $total_price, $total_duration, $notes, $is_between]);
            $appointment_id = $db->lastInsertId();

            // Hizmetleri ekle
            $stmt_service = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, tenant_id) VALUES (?, ?, ?)");
            foreach ($service_ids as $service_id) {
                $stmt_service->execute([$appointment_id, intval($service_id), TENANT_ID]);
            }
            
            $db->commit();
            
            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla eklendi!';
            $response['redirect'] = 'daily_view.php?date=' . urlencode($appointment_date);
        }
        elseif ($action == 'add_all_time_slots') {
            $new_time_slot = sanitize($_POST['new_time_slot'] ?? '');
            
            if (!$new_time_slot || !$appointment_date) {
                throw new Exception("Lütfen tüm zorunlu alanları doldurunuz.");
            }
            
            // Çalışma saatlerini güncelle - yeni dilimi dahil et (SADECE O GÜN İÇİN)
            list($new_hour, $new_min) = explode(':', $new_time_slot);
            $new_hour = intval($new_hour);
            
            $current_working_hours = getWorkingHours($appointment_date);
            $new_end_hour = max($current_working_hours['end'], $new_hour + 1);
            saveDailyWorkingHours($appointment_date, $current_working_hours['start'], $new_end_hour);
            
            $response['success'] = true;
            $response['message'] = 'Bu güne yeni çalışma saati eklendi!';
            $response['redirect'] = 'daily_view.php?date=' . urlencode($appointment_date);
        }
        elseif ($action == 'complete') {
            $appointment_id = intval($_POST['appointment_id_complete'] ?? 0);
            $final_price = floatval($_POST['final_price'] ?? 0);
            $payment_method = sanitize($_POST['payment_method'] ?? 'cash');
            $service_ids = isset($_POST['service_ids']) && is_array($_POST['service_ids']) ? $_POST['service_ids'] : [];

            if (!in_array($payment_method, ['cash', 'bank_transfer', 'credit_card', 'not_paid'], true)) {
                $payment_method = 'cash';
            }

            if (!$appointment_id || empty($service_ids)) {
                throw new Exception("Randevu ID'si veya hizmet seçimi eksik.");
            }
            
            $db->beginTransaction();
            // Ana randevuyu güncelle - tenant_id kontrolü ile
            $stmt = $db->prepare("UPDATE appointments SET status = 'completed', payment_status = 'paid', total_price = ?, payment_method = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$final_price, $payment_method, $appointment_id, TENANT_ID]);
            
            // Mevcut hizmet detaylarını kaydet (varyant bilgilerini korumak için)
            $stmt_old = $db->prepare("SELECT service_id, variant_ids, final_price, final_duration, quantity FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
            $stmt_old->execute([$appointment_id, $appointment_id, TENANT_ID]);
            $old_details = [];
            foreach ($stmt_old->fetchAll(PDO::FETCH_ASSOC) as $od) {
                $old_details[$od['service_id']] = $od;
            }

            // Hizmetleri güncelle: önce sil, sonra ekle (tenant doğrulama ile)
            $stmt_delete = $db->prepare("DELETE FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
            $stmt_delete->execute([$appointment_id, $appointment_id, TENANT_ID]);
            
            $stmt_insert = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, tenant_id, variant_ids, final_price, final_duration, quantity) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($service_ids as $sid) {
                $sid = intval($sid);
                $old = $old_details[$sid] ?? null;
                $stmt_insert->execute([
                    $appointment_id, $sid, TENANT_ID,
                    $old['variant_ids'] ?? null,
                    $old['final_price'] ?? null,
                    $old['final_duration'] ?? null,
                    $old['quantity'] ?? 1
                ]);
            }
            $db->commit();

            // Stok düşümü (hizmete bağlı ürünler)
            deductStockForAppointment($db, $appointment_id, TENANT_ID, $_SESSION['user_id'] ?? null);

            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla tamamlandı!';
            $response['redirect'] = 'daily_view.php?date=' . urlencode($appointment_date);
        }
        elseif ($action == 'edit') {
            $appointment_id = intval($_POST['appointment_id_edit'] ?? 0);
            $final_price = floatval($_POST['final_price'] ?? 0);
            $payment_method = sanitize($_POST['payment_method'] ?? 'cash');
            $service_ids = isset($_POST['service_ids']) && is_array($_POST['service_ids']) ? $_POST['service_ids'] : [];
            $notes = sanitizeNotes($_POST['notes'] ?? '');

            if (!in_array($payment_method, ['cash', 'bank_transfer', 'credit_card', 'not_paid'], true)) {
                $payment_method = 'cash';
            }

            if (!$appointment_id || empty($service_ids)) {
                throw new Exception("Randevu ID'si veya hizmet seçimi eksik.");
            }
            
            $db->beginTransaction();
            // Ana randevuyu güncelle - tenant_id kontrolü ile
            $stmt = $db->prepare("UPDATE appointments SET total_price = ?, payment_method = ?, notes = ?, is_edited = 1 WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$final_price, $payment_method, $notes, $appointment_id, TENANT_ID]);
            
            // Hizmetleri güncelle: varyant bilgilerini koru
            $stmt_old_edit = $db->prepare("SELECT service_id, variant_ids, final_price, final_duration, quantity FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
            $stmt_old_edit->execute([$appointment_id, $appointment_id, TENANT_ID]);
            $old_edit_details = [];
            foreach ($stmt_old_edit->fetchAll(PDO::FETCH_ASSOC) as $od) {
                $old_edit_details[$od['service_id']] = $od;
            }

            $stmt_delete = $db->prepare("DELETE FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
            $stmt_delete->execute([$appointment_id, $appointment_id, TENANT_ID]);
            
            $stmt_insert = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, tenant_id, variant_ids, final_price, final_duration, quantity) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($service_ids as $sid) {
                $sid = intval($sid);
                $old = $old_edit_details[$sid] ?? null;
                $stmt_insert->execute([
                    $appointment_id, $sid, TENANT_ID,
                    $old['variant_ids'] ?? null,
                    $old['final_price'] ?? null,
                    $old['final_duration'] ?? null,
                    $old['quantity'] ?? 1
                ]);
            }
            $db->commit();

            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla güncellendi!';
            $response['redirect'] = 'daily_view.php?date=' . urlencode($appointment_date);
        }
        elseif ($action == 'delete') {
            $appointment_id = intval($_POST['appointment_id'] ?? 0);
            if (!$appointment_id) throw new Exception("Randevu ID'si eksik.");
            
            $db->beginTransaction();
            $db->prepare("UPDATE appointments SET deleted_at = NOW() WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL")->execute([$appointment_id, TENANT_ID]);
            $db->commit();

            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla silindi!';
            $response['redirect'] = 'daily_view.php?date=' . urlencode($appointment_date);
        }
        elseif ($action == 'update_drag') {
            $appointment_id = intval($_POST['appointmentId'] ?? 0);
            $new_employee_id = intval($_POST['newEmployeeId'] ?? 0);
            $new_time = sanitize($_POST['newTime'] ?? '');
            $appointment_date = sanitize($_POST['appointment_date'] ?? '');
            
            if (!$appointment_id || !$new_employee_id || !$new_time || !$appointment_date) {
                throw new Exception("Eksik veri gönderildi. (ID: $appointment_id, Employee: $new_employee_id, Time: $new_time, Date: $appointment_date)");
            }
            
            // Çakışma kontrolü - tenant_id ile
            $stmt_check = $db->prepare("SELECT COUNT(*) FROM appointments WHERE employee_id = ? AND appointment_date = ? AND appointment_time = ? AND id != ? AND tenant_id = ? AND deleted_at IS NULL");
            $stmt_check->execute([$new_employee_id, $appointment_date, $new_time, $appointment_id, TENANT_ID]);
            
            if ($stmt_check->fetchColumn() > 0) {
                throw new Exception("Hedef zaman dilimi başka bir randevu ile dolduruldu.");
            }
            
            // Güncelleme - tenant_id kontrolü ile
            $stmt_update = $db->prepare("UPDATE appointments SET employee_id = ?, appointment_time = ? WHERE id = ? AND tenant_id = ?");
            $stmt_update->execute([$new_employee_id, $new_time, $appointment_id, TENANT_ID]);
            
            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla taşındı!';
            $response['redirect'] = 'daily_view.php?date=' . urlencode($appointment_date);
        }
        elseif ($action == 'confirm_appointment') {
            $appointment_id = intval($_POST['appointment_id'] ?? 0);
            if (!$appointment_id) throw new Exception("Randevu ID'si eksik.");
            
            // Randevuyu onayla (pending -> confirmed)
            $stmt = $db->prepare("UPDATE appointments SET status = 'confirmed' WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$appointment_id, TENANT_ID]);
            
            $response['success'] = true;
            $response['message'] = 'Randevu başarıyla onaylandı!';
            $response['redirect'] = 'daily_view.php?date=' . urlencode($appointment_date);
        }
        elseif ($action == 'cancel_appointment') {
            $appointment_id = intval($_POST['appointment_id'] ?? 0);
            if (!$appointment_id) throw new Exception("Randevu ID'si eksik.");
            
            // Randevuyu iptal et (any status -> cancelled)
            $stmt = $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$appointment_id, TENANT_ID]);
            
            $response['success'] = true;
            $response['message'] = 'Randevu iptal edildi!';
            $response['redirect'] = 'daily_view.php?date=' . urlencode($appointment_date);
        }

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(400);
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// Ayarlardan randevu süresini al - tenant_id ile
$stmt_settings = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'appointment_duration' AND tenant_id = ?");
$stmt_settings->execute([TENANT_ID]);
$slot_duration = $stmt_settings->fetchColumn() ?: 30;
$selected_date = $_GET['date'] ?? date('Y-m-d');
$employees = [];
$schedule = [];
$grand_total_cash = 0;
$grand_total_bank = 0;
$grand_total_credit_card = 0;
$grand_total_not_paid = 0;
$employee_summary = [];

// Tarih navigasyonu için önceki ve sonraki günleri hesapla
$prev_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($selected_date . ' +1 day'));

try {
    // Çalışanları çek - tenant_id ile
    $stmtEmp = $db->prepare("SELECT id, name, commission_rate FROM employees WHERE status = 'active' AND tenant_id = ? AND deleted_at IS NULL ORDER BY name ASC");
    $stmtEmp->execute([TENANT_ID]);
    $employees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($employees as $employee) {
        $employee_summary[$employee['id']] = ['total_appointments' => 0, 'total_revenue' => 0, 'cash_total' => 0, 'bank_total' => 0, 'credit_card_total' => 0, 'not_paid_total' => 0, 'cash_count' => 0, 'bank_count' => 0, 'credit_card_count' => 0, 'not_paid_count' => 0];
    }
    
    // Randevuları çek - tenant_id ile
    $stmt = $db->prepare("
        SELECT a.*, COALESCE(ct.name, c.name) as customer_name, GROUP_CONCAT(s.id) as service_ids, GROUP_CONCAT(s.name SEPARATOR ', ') as service_name
        FROM appointments a
        LEFT JOIN customers c ON a.customer_id = c.id
        LEFT JOIN customer_tenants ct ON c.id = ct.customer_id AND ct.tenant_id = a.tenant_id
        LEFT JOIN appointment_services a_s ON a.id = a_s.appointment_id
        LEFT JOIN services s ON a_s.service_id = s.id
        WHERE a.appointment_date = ? AND a.employee_id IS NOT NULL AND a.tenant_id = ? AND a.deleted_at IS NULL
        GROUP BY a.id
        ORDER BY a.appointment_time ASC, a.is_between ASC
    ");
    $stmt->execute([$selected_date, TENANT_ID]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($appointments as $app) {
        $time_key = date('H:i', strtotime($app['appointment_time']));
        $employee_key = $app['employee_id'];
        
        if (!isset($schedule[$employee_key][$time_key])) {
            $schedule[$employee_key][$time_key] = [];
        }
        $schedule[$employee_key][$time_key][] = $app;
        
        if ($app['status'] == 'completed' && isset($employee_summary[$employee_key])) {
            $employee_summary[$employee_key]['total_appointments']++;
            $employee_summary[$employee_key]['total_revenue'] += $app['total_price'];

            // Kısmi ödeme kontrolü
            $paidAmt = isset($app['paid_amount']) && $app['paid_amount'] !== null ? (float)$app['paid_amount'] : null;
            $isPartial = ($paidAmt !== null && $paidAmt > 0 && $paidAmt < (float)$app['total_price']);

            if ($app['payment_method'] == 'not_paid') {
                $employee_summary[$employee_key]['not_paid_count']++;
                $employee_summary[$employee_key]['not_paid_total'] += $app['total_price'];
                $grand_total_not_paid += $app['total_price'];
            } else {
                // Alınan tutar (kısmi ödeme varsa paid_amount, yoksa total_price)
                $earnedAmt = $isPartial ? $paidAmt : (float)$app['total_price'];
                $remainingAmt = $isPartial ? ((float)$app['total_price'] - $paidAmt) : 0;

                if ($app['payment_method'] == 'cash') {
                    $employee_summary[$employee_key]['cash_count']++;
                    $employee_summary[$employee_key]['cash_total'] += $earnedAmt;
                    $grand_total_cash += $earnedAmt;
                } else if ($app['payment_method'] == 'bank_transfer') {
                    $employee_summary[$employee_key]['bank_count']++;
                    $employee_summary[$employee_key]['bank_total'] += $earnedAmt;
                    $grand_total_bank += $earnedAmt;
                } else if ($app['payment_method'] == 'credit_card') {
                    $employee_summary[$employee_key]['credit_card_count']++;
                    $employee_summary[$employee_key]['credit_card_total'] += $earnedAmt;
                    $grand_total_credit_card += $earnedAmt;
                }

                // Kalan tutar → alınmadı sepetine
                if ($remainingAmt > 0) {
                    $employee_summary[$employee_key]['not_paid_count']++;
                    $employee_summary[$employee_key]['not_paid_total'] += $remainingAmt;
                    $grand_total_not_paid += $remainingAmt;
                }
            }
        }
    }
} catch (Exception $e) {
    $error = "Veriler yüklenirken bir hata oluştu: " . $e->getMessage();
}

// Bugün tahsil edilen bekleyen ödemeler (farklı günlere ait, revenue_date = bugün)
$grand_total_pending_cash = 0;
$grand_total_pending_bank = 0;
$grand_total_pending_credit_card = 0;
$pending_revenue_today = [];
try {
    $col_check = $db->query("SHOW COLUMNS FROM appointments LIKE 'revenue_date'")->fetch();
    if ($col_check) {
        $stmt_pr = $db->prepare("
            SELECT a.id, a.appointment_date, a.total_price, a.payment_method, a.employee_id,
                   COALESCE(ct.name, c.name) as customer_name,
                   GROUP_CONCAT(s.name SEPARATOR ', ') as service_name
            FROM appointments a
            LEFT JOIN customers c ON a.customer_id = c.id
            LEFT JOIN customer_tenants ct ON c.id = ct.customer_id AND ct.tenant_id = a.tenant_id
            LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
            LEFT JOIN services s ON aps.service_id = s.id
            WHERE a.revenue_date = ?
                AND a.appointment_date != ?
                AND a.tenant_id = ?
                AND a.status = 'completed'
                AND a.payment_method IN ('cash', 'bank_transfer', 'credit_card')
                AND a.deleted_at IS NULL
            GROUP BY a.id
        ");
        $stmt_pr->execute([$selected_date, $selected_date, TENANT_ID]);
        $pending_revenue_today = $stmt_pr->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pending_revenue_today as $pr) {
            if ($pr['payment_method'] == 'cash') {
                $grand_total_cash += $pr['total_price'];
                $grand_total_pending_cash += $pr['total_price'];
            } elseif ($pr['payment_method'] == 'bank_transfer') {
                $grand_total_bank += $pr['total_price'];
                $grand_total_pending_bank += $pr['total_price'];
            } elseif ($pr['payment_method'] == 'credit_card') {
                $grand_total_credit_card += $pr['total_price'];
                $grand_total_pending_credit_card += $pr['total_price'];
            }
            if (isset($employee_summary[$pr['employee_id']])) {
                $employee_summary[$pr['employee_id']]['total_revenue'] += $pr['total_price'];
                $employee_summary[$pr['employee_id']]['total_appointments']++;
                if ($pr['payment_method'] == 'cash') {
                    $employee_summary[$pr['employee_id']]['cash_total'] += $pr['total_price'];
                    $employee_summary[$pr['employee_id']]['cash_count']++;
                } elseif ($pr['payment_method'] == 'bank_transfer') {
                    $employee_summary[$pr['employee_id']]['bank_total'] += $pr['total_price'];
                    $employee_summary[$pr['employee_id']]['bank_count']++;
                } elseif ($pr['payment_method'] == 'credit_card') {
                    $employee_summary[$pr['employee_id']]['credit_card_total'] += $pr['total_price'];
                    $employee_summary[$pr['employee_id']]['credit_card_count']++;
                }
            }
        }
    }
} catch (Exception $e) {
    $pending_revenue_today = [];
}

// Aralıklı fiyat hizmetlerinde price=0 olanları düzelt (eski kayıtlar)
try {
    $db->prepare("UPDATE services SET price = price_min WHERE tenant_id = ? AND pricing_type = 'range' AND price_min IS NOT NULL AND price_min > 0 AND (price = 0 OR price IS NULL)")
       ->execute([TENANT_ID]);
} catch (Exception $e) { /* sessizce geç */ }

// Hizmetleri çek - tenant_id ile
$stmtSrv = $db->prepare("SELECT s.*
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
$stmtSrv->execute([TENANT_ID, TENANT_ID]);
$services = $stmtSrv->fetchAll(PDO::FETCH_ASSOC);

// Varyant tipli hizmetler için varyantları çek
$service_variants_map = [];
foreach ($services as $svc) {
    if ($svc['pricing_type'] === 'variant') {
        $stmtVar = $db->prepare("SELECT id, name, extra_price, extra_duration, sort_order FROM service_variants WHERE service_id = ? AND tenant_id = ? AND status = 'active' ORDER BY sort_order ASC");
        $stmtVar->execute([$svc['id'], TENANT_ID]);
        $service_variants_map[$svc['id']] = $stmtVar->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Kullanılmış saat dilimlerini al (dinamik)
$used_time_slots = [];
foreach ($schedule as $emp_schedule) {
    foreach ($emp_schedule as $time_key => $appointments) {
        if (!in_array($time_key, $used_time_slots)) {
            $used_time_slots[] = $time_key;
        }
    }
}

// Dinamik saat dilimlerini oluştur
$all_time_slots = [];
$start_time = $working_hours['start'] * 60;
$end_time = $working_hours['end'] * 60;

// Standart grid slot'ları (off-grid tespiti için referans)
$standard_grid_slots = [];
for ($minutes = $start_time; $minutes < $end_time; $minutes += $slot_duration) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    $time_slot = sprintf('%02d:%02d', $hours, $mins);
    $all_time_slots[] = $time_slot;
    $standard_grid_slots[$time_slot] = true;
}

foreach ($used_time_slots as $time_slot) {
    if (!in_array($time_slot, $all_time_slots)) {
        $all_time_slots[] = $time_slot;
    }
}
sort($all_time_slots);

// Off-grid slot'ları işaretle (standart grid'e uymayan, manuel eklenen saatler)
// Örn: 30 dk slotlarda 09:00, 09:30 standart; 09:40 off-grid
$off_grid_slots = [];
foreach ($all_time_slots as $ts) {
    if (!isset($standard_grid_slots[$ts])) {
        $off_grid_slots[$ts] = true;
    }
}

// Çalışma saatleri bitişinden itibaren ilk boş slot'u bul
$next_slot = null;
for ($mins = $working_hours['end'] * 60; $mins < 24 * 60; $mins += $slot_duration) {
    $check_slot = sprintf('%02d:%02d', floor($mins / 60), $mins % 60);
    if (!in_array($check_slot, $all_time_slots)) {
        $next_slot = $check_slot;
        break;
    }
}

// Eğer çalışma saatleri sonrasında boş slot yoksa, son slot'tan sonrakini hesapla
if (!$next_slot && !empty($all_time_slots)) {
    $last_slot = end($all_time_slots);
    list($last_hour, $last_min) = explode(':', $last_slot);
    $last_minutes = ($last_hour * 60) + $last_min + $slot_duration;
    if ($last_minutes < 24 * 60) {
        $next_slot = sprintf('%02d:%02d', floor($last_minutes / 60), $last_minutes % 60);
    }
}

// Hiç slot yoksa çalışma saati bitişini kullan
if (!$next_slot) {
    $next_slot = sprintf('%02d:%02d', $working_hours['end'], 0);
}

$can_add_more = ($next_slot && strtotime($next_slot) < strtotime('23:59'));

include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/daily_view.css?v=<?php echo time(); ?>">

<div class="admin-main daily-view-container">
    
    <div class="working-hours-section">
        <form method="POST" class="working-hours-form" onsubmit="submitWorkingHours(event)">
            <input type="hidden" name="action" value="update_working_hours">
            <input type="hidden" name="appointment_date" value="<?php echo htmlspecialchars($selected_date); ?>">
            <label><i class="fas fa-clock"></i> Çalışma Saatleri:</label>
            <select name="start_hour" id="start_hour_select" required onchange="updateEndHourOptions()">
                <?php for($h = 0; $h <= 23; $h++): ?>
                    <option value="<?php echo $h; ?>" <?php echo ($h == $working_hours['start']) ? 'selected' : ''; ?>>
                        <?php echo str_pad($h, 2, '0', STR_PAD_LEFT) . ':00'; ?>
                    </option>
                <?php endfor; ?>
            </select>
            <span>-</span>
            <select name="end_hour" id="end_hour_select" required>
                <?php for($h = 1; $h <= 24; $h++): ?>
                    <option value="<?php echo $h; ?>" <?php echo ($h == $working_hours['end']) ? 'selected' : ''; ?>>
                        <?php echo ($h == 24) ? '24:00' : str_pad($h, 2, '0', STR_PAD_LEFT) . ':00'; ?>
                    </option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Kaydet
            </button>
        </form>
    </div>
    
    <div class="controls-header">
        <div class="date-navigation">
            <button class="date-nav-btn" onclick="navigateDate('<?php echo $prev_date; ?>')" title="Önceki Gün">
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="date-filter">
                <label for="dateSelect" class="font-weight-bold">Tarih Seç:</label>
                <input type="date" id="dateSelect" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="navigateDate(this.value)">
            </div>
            <button class="date-nav-btn" onclick="navigateDate('<?php echo $next_date; ?>')" title="Sonraki Gün">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <div class="controls-actions">
            <button class="btn btn-primary" onclick="showAddModal()">
                <i class="fas fa-plus"></i> Yeni Randevu
            </button>
            <button class="btn btn-info" onclick="openModal('waitingListModal')" id="viewWaitlistBtn">
                <i class="fas fa-list"></i> Bekleme Listesi <span class="list-count-badge" id="waitingListBadge">0</span>
            </button>
            <?php if (can_access_page('revenues.php')): ?>
            <button class="btn btn-success" onclick="openEndOfDayModal()">
                <i class="fas fa-calculator"></i> Gün Sonu Hesapla
            </button>
            <?php endif; ?>
            </div>
    </div>

    <!-- Bekleme Listesi Modal'a taşındı - daily_view.php'nin sonunda -->
    
    <div class="schedule-container">
        <div class="schedule-grid">
                <!-- Saat Sütunu -->
                <div class="time-column">
                    <div class="employee-header" style="background: #495057;">Saat</div>
                    <?php foreach ($all_time_slots as $time_key): 
                        $label_off_grid_class = isset($off_grid_slots[$time_key]) ? ' off-grid-slot' : '';
                    ?>
                        <div class="time-label<?php echo $label_off_grid_class; ?>" <?php if ($label_off_grid_class): ?>title="Standart slot dışında manuel eklenmiş saat"<?php endif; ?>><?php echo $time_key; ?></div>
                    <?php endforeach; ?>
                </div>

                <!-- Çalışan Kolonları -->
                <?php foreach ($employees as $employee): 
                    $employee_key = $employee['id'];
                ?>
                    <div class="employee-column">
                        <div class="employee-header">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($employee['name']); ?>
                        </div>
                        
                        <?php foreach ($all_time_slots as $time_key): 
                            $slot_class = 'available';
                            $slot_content = '<div class="slot-add-icon">+</div><div class="slot-add-text">Randevu Ekle</div>';
                            $data_attributes = "data-employee-id='{$employee_key}' data-time='{$time_key}' data-date='{$selected_date}'";
                            $onclick = "showAddModalForSlot(event, this)";
                            $is_off_grid = isset($off_grid_slots[$time_key]);
                            
                            if (isset($schedule[$employee_key][$time_key]) && !empty($schedule[$employee_key][$time_key])) {
                                $appointments_in_slot = $schedule[$employee_key][$time_key];
                                $slot_content = '';
                                
                                // Randevuları sırala: bekleyenler/onaylılar üstte, tamamlananlar altta
                                usort($appointments_in_slot, function($a, $b) {
                                    $order = ['pending' => 0, 'confirmed' => 1, 'break' => 2, 'completed' => 3, 'cancelled' => 4];
                                    $orderA = $order[$a['status']] ?? 5;
                                    $orderB = $order[$b['status']] ?? 5;
                                    return $orderA - $orderB;
                                });
                                
                                // Slot'un ana rengini belirleme - mixed durumu için özel class
                                $has_break = false;
                                $has_completed = false;
                                $has_pending = false;
                                $has_confirmed = false;
                                $has_cancelled = false;
                                
                                foreach ($appointments_in_slot as $appointment) {
                                    if ($appointment['status'] == 'break') {
                                        $has_break = true;
                                    } elseif ($appointment['status'] == 'completed') {
                                        $has_completed = true;
                                    } elseif ($appointment['status'] == 'cancelled') {
                                        $has_cancelled = true;
                                    } elseif ($appointment['status'] == 'confirmed') {
                                        $has_confirmed = true;
                                    } else {
                                        $has_pending = true; // pending
                                    }
                                }
                                
                                // Slot renk belirleme
                                // Birden fazla farklı durum varsa mixed
                                $status_count = ($has_pending ? 1 : 0) + ($has_completed ? 1 : 0) + ($has_cancelled ? 1 : 0) + ($has_break ? 1 : 0);
                                if ($status_count > 1) {
                                    $slot_class = 'mixed-status';
                                } elseif ($has_cancelled) {
                                    $slot_class = 'slot-cancelled';
                                } elseif ($has_break) {
                                    $slot_class = 'slot-break';
                                } elseif ($has_completed) {
                                    $slot_class = 'completed';
                                } elseif ($has_confirmed) {
                                    $slot_class = 'confirmed';
                                } else {
                                    $slot_class = 'booked'; // pending
                                }
                                
                                foreach ($appointments_in_slot as $index => $appointment) {
                                    $status = $appointment['status'];
                                    $pm = $appointment['payment_method'] ?? 'cash';
                                    $paidAmt = (float)($appointment['paid_amount'] ?? 0);
                                    if ($paidAmt > 0 && $pm !== 'not_paid') {
                                        $pmLabel = $pm == 'cash' ? 'Nakit' : ($pm == 'bank_transfer' ? 'Havale/EFT' : ($pm == 'credit_card' ? 'Kredi Kartı' : 'Ödeme'));
                                        $payment_method_text = 'Kısmi: ' . formatPrice($paidAmt) . ' ' . $pmLabel;
                                    } else {
                                        $payment_method_text = $pm == 'cash' ? 'Nakit' : ($pm == 'bank_transfer' ? 'Havale/EFT' : ($pm == 'credit_card' ? 'Kredi Kartı' : ($pm == 'not_paid' ? 'Alınmadı' : 'Havale/EFT')));
                                    }
                                    $customer_name = htmlspecialchars($appointment['customer_name'] ?? 'Müşteri');
                                    $is_between = $appointment['is_between'] == 1 ? 'true' : 'false';
                                    
                                    // Randevu detaylarını JSON olarak hazırla
                                    $appointment_json = htmlspecialchars(json_encode($appointment), ENT_QUOTES, 'UTF-8');
                                    
                                    // Her randevuya kendi status class'ını belirle
                                    // Bekleyen = Turuncu, Onaylı = Yeşil, Tamamlanan = Mavi, İptal = Kırmızı, Mola = Gri
                                    if ($status == 'completed') {
                                        $item_status_class = 'item-completed';
                                    } elseif ($status == 'cancelled') {
                                        $item_status_class = 'item-cancelled';
                                    } elseif ($status == 'break') {
                                        $item_status_class = 'item-break';
                                    } elseif ($status == 'confirmed') {
                                        $item_status_class = 'item-confirmed';
                                    } else {
                                        $item_status_class = 'item-pending'; // pending
                                    }
                                    
                                    // Her randevuyu ayrı appointment-item div'i içine al - kendi status class'ı ile
                                    $slot_content .= '<div class="appointment-item ' . $item_status_class . '" data-appointment-details=\'' . $appointment_json . '\' data-is-between="' . $is_between . '" onclick="handleAppointmentItemClick(event, this)">';
                                    
                                    if ($status == 'break') {
                                        $slot_content .= "<div class='slot-patient'>Açık Saat</div>";
                                    } else {
                                        // Düzenlenen randevular için D harfi göster - SOL ÜST köşede
                                        if (!empty($appointment['is_edited']) && $appointment['is_edited'] == 1 && $status == 'completed') {
                                            $slot_content .= '<div class="edited-indicator" title="Düzenlenmiş Randevu">D</div>';
                                        }
                                        
                                        // Birden fazla randevu varsa badge ekle
                                        if (count($appointments_in_slot) > 1) {
                                            if ($index === 0) {
                                                // İLK karta randevu sayısı badge'i
                                                $appointment_count = count($appointments_in_slot);
                                                $slot_content .= '<div class="appointment-count-badge"><i class="fas fa-layer-group"></i> ' . $appointment_count . ' randevu</div>';
                                            } else {
                                                // Diğer kartlara ARAYA badge'i
                                                $slot_content .= '<div class="between-label">ARAYA</div>';
                                            }
                                        }
                                        
                                        // Araya ekleme butonu - SAĞ ÜST köşede
                                        if ($status != 'break') {
                                            $slot_content .= '<button class="add-between-btn" onclick="showAddBetweenModal(event, ' . $employee_key . ', \'' . $time_key . '\', \'' . $selected_date . '\')" title="Araya Müşteri Ekle">+</button>';
                                        }
                                        
                                        $slot_content .= "<div class='slot-patient'>" . $customer_name . "</div>";
                                        $payment_class = getPaymentMethodCssClass($pm, $paidAmt);
                                    $slot_content .= "<div class='slot-details" . $payment_class . "'>" . formatPrice($appointment['total_price']) . " - " . $payment_method_text . "</div>";
                                        
                                        // Status text
                                        $status_text = 'Onaylandı'; // confirmed için
                                        if ($status == 'completed') $status_text = 'Tamamlandı';
                                        elseif ($status == 'cancelled') $status_text = 'İptal Edildi';
                                        elseif ($status == 'pending') $status_text = 'Bekliyor';
                                        $slot_content .= "<div class='slot-status'>" . $status_text . "</div>";
                                    }
                                    
                                    $slot_content .= '</div>'; // appointment-item kapat
                                }
                                
                                // Birden fazla randevu varsa slot'a özel class ekle (badge artık kart içinde)
                                if (count($appointments_in_slot) > 1) {
                                    $slot_class .= ' has-multiple';
                                }
                                
                                $onclick = "handleSlotClick(event, this)";
                            }
                        ?>
                            <div class="appointment-slot <?php echo $slot_class; ?><?php echo $is_off_grid ? ' off-grid-slot' : ''; ?>" 
                                 <?php echo $data_attributes; ?> 
                                 onclick="<?php echo $onclick; ?>"
                                 draggable="<?php echo $slot_class != 'available' ? 'true' : 'false'; ?>"
                                 <?php if ($is_off_grid): ?>title="Standart slot dışında - <?php echo htmlspecialchars($time_key); ?>"<?php endif; ?>>
                                <?php echo $slot_content; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Manuel Saat Slotu Ekleme -->
            <?php if ($can_add_more): ?>
                <div class="add-time-slot-container">
                    <div class="add-time-label"><?php echo $next_slot; ?></div>
                    <div class="add-slot-button" onclick="addNewTimeSlotForAll('<?php echo $next_slot; ?>')">+</div>
                </div>
            <?php endif; ?>

            <!-- Çalışan Özet Kartları -->
            <?php if (can_access_page('revenues.php')): ?>
            <div class="schedule-grid summary-grid">
                <div class="time-column summary-label-column">
                    <div class="summary-vertical-label">ÖZET</div>
                </div>

                <?php foreach ($employees as $employee): 
                    $employee_key = $employee['id'];
                    $summary = $employee_summary[$employee_key];
                ?>
                    <div class="employee-column">
                        <div class="employee-summary">
                            <div class="summary-name"><?php echo htmlspecialchars($employee['name']); ?></div>
                            <div class="summary-item summary-total">
                                <span class="summary-label">Toplam Randevu:</span>
                                <span class="summary-value"><?php echo $summary['total_appointments']; ?></span>
                            </div>
                            <div class="summary-item summary-total">
                                <span class="summary-label">Toplam Kazanç:</span>
                                <span class="summary-value"><?php echo formatPrice($summary['total_revenue']); ?></span>
                            </div>
                            <?php if ($summary['cash_count'] > 0): ?>
                            <div class="summary-item summary-payment summary-pm-cash">
                                <span class="summary-label">Nakit:</span>
                                <span class="summary-value"><?php echo $summary['cash_count']; ?> Adet (<?php echo formatPrice($summary['cash_total']); ?>)</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($summary['bank_count'] > 0): ?>
                            <div class="summary-item summary-payment summary-pm-bank">
                                <span class="summary-label">Havale/EFT:</span>
                                <span class="summary-value"><?php echo $summary['bank_count']; ?> Adet (<?php echo formatPrice($summary['bank_total']); ?>)</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($summary['credit_card_count'] > 0): ?>
                            <div class="summary-item summary-payment summary-pm-card">
                                <span class="summary-label">Kredi Kartı:</span>
                                <span class="summary-value"><?php echo $summary['credit_card_count']; ?> Adet (<?php echo formatPrice($summary['credit_card_total']); ?>)</span>
                            </div>
                            <?php endif; ?>
                            <?php if (($summary['not_paid_count'] ?? 0) > 0): ?>
                            <div class="summary-item summary-payment summary-not-paid">
                                <span class="summary-label">Alınmadı:</span>
                                <span class="summary-value"><?php echo $summary['not_paid_count']; ?> Adet (<?php echo formatPrice($summary['not_paid_total']); ?>)</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    
    <?php if (can_access_page('revenues.php')): ?>
    <div class="pending-revenue-section" id="pendingRevenueSection"<?php echo empty($pending_revenue_today) ? ' style="display:none;"' : ''; ?>>
        <div class="pending-revenue-header">
            <span>
                <i class="fas fa-clock"></i> Bugün Tahsil Edilen Bekleyen Ödemeler
            </span>
            <span class="pending-revenue-count"><?php echo count($pending_revenue_today); ?> adet — <?php echo formatPrice($grand_total_pending_cash + $grand_total_pending_bank + $grand_total_pending_credit_card); ?></span>
        </div>
        <div class="pending-revenue-list" id="pendingRevenueList">
            <?php foreach ($pending_revenue_today as $pr): 
                $pm_label = $pr['payment_method'] == 'cash' ? 'Nakit' : ($pr['payment_method'] == 'bank_transfer' ? 'Havale/EFT' : 'Kredi Kartı');
                $pm_class = $pr['payment_method'] == 'cash' ? 'pr-cash' : ($pr['payment_method'] == 'bank_transfer' ? 'pr-bank' : 'pr-card');
                $emp_name = '';
                foreach ($employees as $emp) {
                    if ($emp['id'] == $pr['employee_id']) { $emp_name = $emp['name']; break; }
                }
            ?>
            <div class="pending-revenue-item">
                <div class="pr-customer"><?php echo htmlspecialchars($pr['customer_name'] ?? '-'); ?></div>
                <div class="pr-date">Randevu: <?php echo date('d.m.Y', strtotime($pr['appointment_date'])); ?></div>
                <?php if ($emp_name): ?><div class="pr-employee"><i class="fas fa-user"></i> <?php echo htmlspecialchars($emp_name); ?></div><?php endif; ?>
                <?php if (!empty($pr['service_name'])): ?><div class="pr-service"><?php echo htmlspecialchars($pr['service_name']); ?></div><?php endif; ?>
                <div class="pr-amount"><?php echo formatPrice($pr['total_price']); ?></div>
                <div class="pr-method <?php echo $pm_class; ?>"><?php echo $pm_label; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (can_access_page('revenues.php')): ?>
    <div class="totals-footer" id="dailyTotalsFooter">
        <?php if ($grand_total_cash > 0): ?>
        <div class="total-item"><strong>GÜN SONU TOPLAM NAKİT:</strong> <?php echo formatPrice($grand_total_cash); ?><?php if ($grand_total_pending_cash > 0): ?> <small style="color:#64748b; font-weight:normal;">(<?php echo formatPrice($grand_total_pending_cash); ?> bekleyen tahsilat dahil)</small><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($grand_total_bank > 0): ?>
        <div class="total-item"><strong>GÜN SONU TOPLAM HAVALE/EFT:</strong> <?php echo formatPrice($grand_total_bank); ?><?php if ($grand_total_pending_bank > 0): ?> <small style="color:#64748b; font-weight:normal;">(<?php echo formatPrice($grand_total_pending_bank); ?> bekleyen tahsilat dahil)</small><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($grand_total_credit_card > 0): ?>
        <div class="total-item"><strong>GÜN SONU TOPLAM KREDİ KARTI:</strong> <?php echo formatPrice($grand_total_credit_card); ?><?php if ($grand_total_pending_credit_card > 0): ?> <small style="color:#64748b; font-weight:normal;">(<?php echo formatPrice($grand_total_pending_credit_card); ?> bekleyen tahsilat dahil)</small><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($grand_total_not_paid > 0): ?>
        <div class="total-item" style="color: #dc2626;"><strong>GÜN SONU TOPLAM ALINMADI:</strong> <?php echo formatPrice($grand_total_not_paid); ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div id="appointmentModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('appointmentModal')">&times;</span>
        <div class="admin-cart-toolbar">
            <h3 id="modalTitle" class="admin-cart-toolbar-title">Yeni Randevu</h3>
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
            <input type="hidden" name="action" value="add">
            <input type="hidden" id="form_action" name="form_action" value="add">
            <input type="hidden" id="appointment_id" name="appointment_id" value="">
            <input type="hidden" id="waitlist_id" name="waitlist_id" value="">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="customer_phone">Telefon *</label>
                    <input type="tel" id="customer_phone" name="customer_phone" class="form-control" required placeholder="5XX XXX XX XX" maxlength="15">
                    <div id="customer_phone_suggestions" class="customer-autocomplete-list" style="display:none;"></div>
                </div>
                <div class="form-group">
                    <label for="customer_name">Müşteri Adı Soyadı *</label>
                    <input type="text" id="customer_name" name="customer_name" class="form-control" required placeholder="Ad Soyad giriniz" maxlength="50">
                    <div id="customer_name_suggestions" class="customer-autocomplete-list" style="display:none;"></div>
                </div>
                <div class="form-group" id="employee_field">
                    <label for="employee_id">Çalışan *</label>
                    <select id="employee_id" name="employee_id" class="form-control" required>
                        <option value="">Çalışan Seçiniz</option>
                        <?php foreach($employees as $employee): ?>
                            <option value="<?php echo $employee['id']; ?>"><?php echo htmlspecialchars($employee['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="date_field">
                    <label for="appointment_date">Tarih *</label>
                    <input type="date" id="appointment_date" name="appointment_date" class="form-control" required>
                </div>
                <div class="form-group" id="time_field">
                    <label for="appointment_time">Saat *</label>
                    <input type="time" id="appointment_time" name="appointment_time" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="appointment_status">Durum</label>
                    <select id="appointment_status" name="status" class="form-control">
                        <option value="pending">Bekleyen</option>
                        <option value="confirmed" selected>Onaylı</option>
                        <option value="completed">Tamamlandı</option>
                        <option value="cancelled">İptal Edildi</option>
                    </select>
                </div>
                <div class="form-group sms-info-checkbox">
                    <input type="checkbox" id="send_customer_sms" name="send_customer_sms" value="1">
                    <label for="send_customer_sms">Müşteriye bilgilendirme SMS gönderilsin</label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Hizmetler *</label>
                <input type="text" class="form-control service-search-input" id="serviceSearchInput" placeholder="Hizmet ara..." autocomplete="off">
                <div class="services-selection" id="servicesSelection">
                    <?php foreach($services as $service): 
                        $display_price = ($service['pricing_type'] === 'range' && !empty($service['price_min'])) ? $service['price_min'] : $service['price'];
                        $is_range = ($service['pricing_type'] === 'range' && !empty($service['price_min']) && !empty($service['price_max']));
                        $is_variant = ($service['pricing_type'] === 'variant');
                        $variants = $is_variant ? ($service_variants_map[$service['id']] ?? []) : [];
                    ?>
                        <div class="service-item-wrapper">
                            <label>
                                <input type="checkbox" name="service_ids[]" value="<?php echo $service['id']; ?>" class="service-checkbox" data-price="<?php echo $display_price; ?>" data-is-range="<?php echo $is_range ? '1' : '0'; ?>" data-is-variant="<?php echo $is_variant ? '1' : '0'; ?>" data-is-multiple="<?php echo ($service['is_multiple'] ?? 0) ? '1' : '0'; ?>" data-min="<?php echo $service['price_min'] ?: $display_price; ?>" data-max="<?php echo $service['price_max'] ?: $display_price; ?>" data-service-id="<?php echo $service['id']; ?>" onchange="updateTotalPrice()">
                                <?php echo htmlspecialchars($service['name']); ?> - <?php 
                                    if ($is_range) {
                                        echo formatPrice($service['price_min']) . ' - ' . formatPrice($service['price_max']);
                                    } elseif ($is_variant) {
                                        echo formatPrice($display_price) . '+';
                                    } else {
                                        echo formatPrice($display_price);
                                    }
                                ?>
                            </label>
                            <?php if ($is_range): ?>
                            <div class="range-price-input" data-service-id="<?php echo $service['id']; ?>" style="display:none; margin-left:24px; margin-top:4px; align-items:center; gap:6px;">
                                <span style="font-size:0.8em; color:#6b7280;">Alınan tutar:</span>
                                <input type="number" class="range-actual-price form-control" data-service-id="<?php echo $service['id']; ?>" value="<?php echo intval($service['price_min']); ?>" min="<?php echo $service['price_min']; ?>" max="<?php echo $service['price_max']; ?>" step="any" style="width:100px; padding:4px 8px; font-size:0.85em; text-align:right;" onchange="updateTotalPrice()">
                                <span style="font-size:0.8em; color:#6b7280;">₺</span>
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
                <div class="service-price-display" id="totalPriceDisplay">Toplam: 0 ₺</div>
            </div>
            
            <div class="form-group">
                <label for="notes">Notlar</label>
                <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Randevu notları..."></textarea>
            </div>
            
            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('appointmentModal')"><i class="fas fa-times"></i> İptal</button>
                <button type="submit" id="normalSaveBtn" class="btn btn-primary"><span class="loading" style="display: none;"></span><span class="btn-text"><i class="fas fa-save"></i> Kaydet</span></button>
                <button type="button" id="addToCartBtn" class="btn btn-info" onclick="addToAdminCart()" style="display:none;"><i class="fas fa-cart-plus"></i> Sepete Ekle</button>
            </div>
        </form>

        <div id="adminCartPanel" class="admin-cart-panel" style="display:none;">
            <div class="admin-cart-panel-header">
                <h4 class="admin-cart-panel-title"><i class="fas fa-shopping-cart admin-cart-panel-icon"></i> Sepet (<span id="adminCartCount">0</span> randevu)</h4>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearAdminCart()"><i class="fas fa-trash"></i> Sepeti Temizle</button>
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

<div id="addBetweenModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addBetweenModal')">&times;</span>
        <h3>Araya Müşteri Al</h3>
        
        <!-- Müşteri Bulundu Bildirim Kartı -->
        <div id="betweenCustomerInfoAlert" class="customer-found-alert" style="display: none;">
            <button class="cfa-close" onclick="document.getElementById('betweenCustomerInfoAlert').style.display='none'">×</button>
            <div class="cfa-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"></path><circle cx="12" cy="12" r="10"></circle></svg>
            </div>
            <div class="cfa-text">
                <div class="cfa-title">Müşteri Bulundu</div>
                <div class="cfa-message">Bilgiler otomatik olarak dolduruldu</div>
            </div>
        </div>
        
        <form id="addBetweenForm">
            <input type="hidden" name="action" value="add_between">
            <input type="hidden" name="appointment_date" id="between_appointment_date">
            <input type="hidden" name="appointment_time" id="between_appointment_time">
            <input type="hidden" name="employee_id" id="between_employee_id">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="between_customer_phone">Telefon *</label>
                    <input type="tel" id="between_customer_phone" name="customer_phone" class="form-control" required placeholder="5XX XXX XX XX" maxlength="15">
                    <div id="between_customer_phone_suggestions" class="customer-autocomplete-list" style="display:none;"></div>
                </div>
                <div class="form-group">
                    <label for="between_customer_name">Müşteri Adı Soyadı *</label>
                    <input type="text" id="between_customer_name" name="customer_name" class="form-control" required placeholder="Ad Soyad giriniz" maxlength="50">
                    <div id="between_customer_name_suggestions" class="customer-autocomplete-list" style="display:none;"></div>
                </div>
                <div class="form-group">
                    <label for="between_status">Durum</label>
                    <select id="between_status" name="status" class="form-control">
                        <option value="pending">Bekleyen</option>
                        <option value="confirmed" selected>Onaylı</option>
                    </select>
                </div>
                <div class="form-group sms-info-checkbox">
                    <input type="checkbox" id="between_send_customer_sms" name="send_customer_sms" value="1">
                    <label for="between_send_customer_sms">Müşteriye bilgilendirme SMS gönderilsin</label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Hizmetler *</label>
                <input type="text" class="form-control service-search-input" id="betweenServiceSearchInput" placeholder="Hizmet ara..." autocomplete="off">
                <div class="services-selection" id="betweenServicesSelection">
                    <?php foreach($services as $service): 
                        $display_price = ($service['pricing_type'] === 'range' && !empty($service['price_min'])) ? $service['price_min'] : $service['price'];
                        $is_range = ($service['pricing_type'] === 'range' && !empty($service['price_min']) && !empty($service['price_max']));
                        $is_variant = ($service['pricing_type'] === 'variant');
                        $variants = $is_variant ? ($service_variants_map[$service['id']] ?? []) : [];
                    ?>
                        <div class="service-item-wrapper">
                            <label>
                                <input type="checkbox" name="service_ids[]" value="<?php echo $service['id']; ?>" class="between-service-checkbox" data-price="<?php echo $display_price; ?>" data-is-range="<?php echo $is_range ? '1' : '0'; ?>" data-is-variant="<?php echo $is_variant ? '1' : '0'; ?>" data-is-multiple="<?php echo ($service['is_multiple'] ?? 0) ? '1' : '0'; ?>" data-min="<?php echo $service['price_min'] ?: $display_price; ?>" data-max="<?php echo $service['price_max'] ?: $display_price; ?>" data-service-id="<?php echo $service['id']; ?>" onchange="updateBetweenTotalPrice()">
                                <?php echo htmlspecialchars($service['name']); ?> - <?php 
                                    if ($is_range) {
                                        echo formatPrice($service['price_min']) . ' - ' . formatPrice($service['price_max']);
                                    } elseif ($is_variant) {
                                        echo formatPrice($display_price) . '+';
                                    } else {
                                        echo formatPrice($display_price);
                                    }
                                ?>
                            </label>
                            <?php if ($is_range): ?>
                            <div class="range-price-input" data-service-id="<?php echo $service['id']; ?>" style="display:none; margin-left:24px; margin-top:4px; align-items:center; gap:6px;">
                                <span style="font-size:0.8em; color:#6b7280;">Alınan tutar:</span>
                                <input type="number" class="range-actual-price form-control" data-service-id="<?php echo $service['id']; ?>" value="<?php echo intval($service['price_min']); ?>" min="<?php echo $service['price_min']; ?>" max="<?php echo $service['price_max']; ?>" step="any" style="width:100px; padding:4px 8px; font-size:0.85em; text-align:right;" onchange="updateBetweenTotalPrice()">
                                <span style="font-size:0.8em; color:#6b7280;">₺</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($is_variant && !empty($variants)): ?>
                            <div class="variant-panel" data-service-id="<?php echo $service['id']; ?>" style="display:none; margin-left:24px; margin-top:6px; padding:8px 12px; background:rgba(139,92,246,0.06); border:1px solid rgba(139,92,246,0.2); border-radius:8px;">
                                <div style="font-size:0.8em; color:#8b5cf6; font-weight:600; margin-bottom:6px;">Varyant Seçimi:</div>
                                <?php foreach($variants as $variant): ?>
                                <label style="display:flex; align-items:center; gap:6px; padding:4px 0; cursor:pointer; font-size:0.85em;">
                                    <input type="<?php echo ($service['is_multiple'] ?? 0) ? 'checkbox' : 'radio'; ?>" name="between_variant_<?php echo $service['id']; ?>[]" value="<?php echo $variant['id']; ?>" class="variant-input" data-service-id="<?php echo $service['id']; ?>" data-extra-price="<?php echo $variant['extra_price']; ?>" data-extra-duration="<?php echo $variant['extra_duration']; ?>" onchange="updateBetweenTotalPrice()">
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
                <div class="service-price-display" id="betweenTotalPriceDisplay">Toplam: 0 ₺</div>
            </div>
            
            <div class="form-group">
                <label for="between_notes">Notlar</label>
                <textarea id="between_notes" name="notes" class="form-control" rows="3" placeholder="Randevu notları..."></textarea>
            </div>
            
            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addBetweenModal')"><i class="fas fa-times"></i> İptal</button>
                <button type="submit" class="btn btn-primary"><span class="loading" style="display: none;"></span><span class="btn-text"><i class="fas fa-plus"></i> Araya Ekle</span></button>
            </div>
        </form>
    </div>
</div>

<!-- Bekleme Listesi Modal -->
<div id="waitingListModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('waitingListModal')">&times;</span>
        <div class="waiting-list-header" style="margin-bottom: 1rem;">
            <h3 style="margin: 0;"><i class="fas fa-user-clock"></i> Bugunun Bekleme Listesi</h3>
            <span class="waiting-list-count" id="waitingListCountModal">0</span>
        </div>
        <div class="waiting-list-toolbar">
            <button type="button" class="btn btn-warning" onclick="toggleWaitlistInlineForm(true)">
                <i class="fas fa-plus"></i> Bekleme Listesine Ekle
            </button>
        </div>
        <div id="waitlistInlineFormContainer" class="waitlist-inline-form" style="display:none; margin-bottom: 1rem;">
            <form id="waitlistForm">
                <input type="hidden" id="waitlist_date" name="wait_date" value="<?php echo htmlspecialchars($selected_date); ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="waitlist_customer_phone">Telefon *</label>
                        <input type="tel" id="waitlist_customer_phone" name="customer_phone" class="form-control" required maxlength="15" placeholder="5XX XXX XX XX">
                    </div>
                    <div class="form-group">
                        <label for="waitlist_customer_name">Müşteri Adı Soyadı *</label>
                        <input type="text" id="waitlist_customer_name" name="customer_name" class="form-control" required maxlength="50" placeholder="Ad Soyad giriniz">
                    </div>
                    <div class="form-group">
                        <label for="waitlist_preferred_employee">Tercih Edilen Personel</label>
                        <select id="waitlist_preferred_employee" name="preferred_employee_id" class="form-control">
                            <option value="">Fark etmez</option>
                            <?php foreach($employees as $employee): ?>
                                <option value="<?php echo $employee['id']; ?>"><?php echo htmlspecialchars($employee['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="waitlist_preferred_time">Tercih Edilen Saat</label>
                        <input type="time" id="waitlist_preferred_time" name="preferred_time" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label for="waitlist_requested_services">Istenen Hizmet(ler)</label>
                    <input type="text" id="waitlist_requested_services" name="requested_services" class="form-control" maxlength="255" placeholder="Orn: Sac kesim + sakal">
                </div>

                <div class="form-group">
                    <label for="waitlist_note">Not</label>
                    <textarea id="waitlist_note" name="note" class="form-control" rows="2" placeholder="Musaitlik olursa once bunu ara vb."></textarea>
                </div>

                <div class="form-group" style="display:flex; align-items:center; gap:10px; background:#fff7ed; border:1px solid #fdba74; border-radius:10px; padding:10px 12px;">
                    <input type="checkbox" id="waitlist_call_when_available" name="call_when_available" value="1" checked style="width:18px; height:18px; cursor:pointer;">
                    <label for="waitlist_call_when_available" style="margin:0; font-weight:700; color:#7c2d12; cursor:pointer;">Bosluk olursa aranabilir</label>
                </div>

                <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-secondary" onclick="toggleWaitlistInlineForm(false)"><i class="fas fa-times"></i> Iptal</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-save"></i> Bekleme Listesine Ekle</button>
                </div>
            </form>
        </div>
        <div id="waitingListItemsModal" class="waiting-list-items">
            <div class="waiting-empty">Bekleme listesinde kayit yok.</div>
        </div>
    </div>
</div>

<div id="appointmentMenu" class="appointment-menu">
    <button id="menuCompleteBtn"><i class="fas fa-check"></i> Tamamla</button>
    <button id="menuNotesBtn" class="notes"><i class="fas fa-sticky-note"></i> Notları Gör</button>
    <button id="menuDeleteBtn" class="delete"><i class="fas fa-trash"></i> Sil</button>
</div>

<div id="completeModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('completeModal')">&times;</span>
        <h3 id="completeModalTitle">Randevu Tamamla</h3>
        <form method="POST" id="completeForm" action="daily_view.php" onsubmit="return false;">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="appointment_id_complete" id="completeAppointmentId">
            <input type="hidden" name="appointment_date" value="<?php echo htmlspecialchars($selected_date); ?>">
            <input type="hidden" name="payment_method" id="paymentMethodHidden" value="cash">
            
            <div class="form-group">
                <label>Verilen Hizmetler</label>
                <div id="serviceList" class="complete-modal-services"></div>
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

<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editModal')">&times;</span>
        <h3>Randevu Düzenle</h3>
        <form method="POST" id="editForm" action="daily_view.php" onsubmit="return false;">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="appointment_id_edit" id="editAppointmentId">
            <input type="hidden" name="appointment_date" value="<?php echo htmlspecialchars($selected_date); ?>">
            <input type="hidden" name="payment_method" id="editPaymentMethodHidden" value="cash">
            
            <div class="form-group">
                <label>Verilen Hizmetler</label>
                <div id="editServiceList" class="complete-modal-services"></div>
            </div>
            
            <div class="form-group">
                <label for="edit_notes">Notlar</label>
                <textarea id="edit_notes" name="notes" class="form-control" rows="3" placeholder="Randevu notları..."></textarea>
            </div>
            
            <div class="form-group complete-modal-total">
                <label for="edit_final_price">Toplam Tutar</label>
                <input type="number" step="0.01" name="final_price" id="edit_final_price" class="form-control">
            </div>
            
            <div class="form-group complete-modal-payment">
                <button type="button" class="btn btn-success" onclick="setEditPaymentMethodAndSubmit('cash')">
                    <i class="fas fa-money-bill-wave"></i> 
                    <span class="payment-btn-text-full">Nakit Güncelle</span>
                    <span class="payment-btn-text-short">Nakit</span>
                </button>
                <button type="button" class="btn btn-primary" onclick="setEditPaymentMethodAndSubmit('bank_transfer')">
                    <i class="fas fa-credit-card"></i> 
                    <span class="payment-btn-text-full">Havale/EFT Güncelle</span>
                    <span class="payment-btn-text-short">Havale/EFT</span>
                </button>
                <button type="button" class="btn btn-info" onclick="setEditPaymentMethodAndSubmit('credit_card')">
                    <i class="fas fa-credit-card"></i>
                    <span class="payment-btn-text-full">Kredi Kartı Güncelle</span>
                    <span class="payment-btn-text-short">Kredi Kartı</span>
                </button>
                <button type="button" class="btn btn-warning" onclick="showNotPaidOptions('edit')" style="background:#ef4444;border-color:#ef4444;color:#fff;">
                    <i class="fas fa-times-circle"></i> 
                    <span class="payment-btn-text-full">Alınmadı</span>
                    <span class="payment-btn-text-short">Alınmadı</span>
                </button>
            </div>
        </form>
    </div>
</div>

<div id="notesModal" class="modal">
    <div class="notes-modal-content modal-content">
        <span class="close" onclick="closeModal('notesModal')">&times;</span>
        <h3><i class="fas fa-sticky-note"></i> Randevu Notları</h3>
        
        <div class="notes-display" id="notesDisplay">
            <div class="notes-empty">Not bulunamadı</div>
        </div>
        
        <div style="text-align: center; margin-top: 1.5rem;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('notesModal')">
                <i class="fas fa-times"></i> Kapat
            </button>
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

<div id="confirmModal" class="confirm-modal-overlay">
    <div class="confirm-modal">
        <h4 id="confirmTitle"></h4>
        <p id="confirmMessage"></p>
        <div class="confirm-modal-actions">
            <button id="confirmCancelBtn" class="btn btn-secondary">İptal</button>
            <button id="confirmOkBtn" class="btn btn-danger">Onayla</button>
        </div>
    </div>
</div>

<!-- Randevu Detay Modalı -->
<div id="detailModal" class="modal">
    <div class="modal-content" style="max-width: 560px; max-height: 90vh; overflow-y: auto;">
        <span class="close" onclick="closeModal('detailModal')">&times;</span>
        <h3 style="margin-bottom: 0.5rem;"><i class="fas fa-info-circle"></i> Randevu Detayları</h3>
        <div id="detailModalBody" style="margin-top: 0.5rem;">
            <div style="text-align:center; padding:2rem; color:#94a3b8;">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p style="margin-top:0.5rem;">Yükleniyor...</p>
            </div>
        </div>
        <div style="text-align: center; margin-top: 1rem;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('detailModal')">
                <i class="fas fa-times"></i> Kapat
            </button>
        </div>
    </div>
</div>

<style>
.pending-revenue-section {
    margin: 0 0 0.5rem 0;
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-radius: 10px;
    padding: 12px 16px;
}
.pending-revenue-header {
    font-weight: 700;
    font-size: 0.9rem;
    color: #92400e;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.pending-revenue-count {
    margin-left: auto;
    font-size: 0.82rem;
    background: #fef3c7;
    color: #78350f;
    border: 1px solid #fcd34d;
    border-radius: 20px;
    padding: 2px 10px;
}
.pending-revenue-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.pending-revenue-item {
    background: #fff;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 0.8rem;
    min-width: 160px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.pr-customer { font-weight: 700; color: #1e293b; }
.pr-date { color: #64748b; font-size: 0.75rem; }
.pr-employee { color: #64748b; font-size: 0.75rem; }
.pr-service { color: #475569; font-size: 0.75rem; }
.pr-amount { font-weight: 700; color: #16a34a; margin-top: 2px; }
.pr-method { font-size: 0.72rem; font-weight: 600; padding: 1px 7px; border-radius: 10px; display: inline-block; width: fit-content; }
.pr-cash { background: #dcfce7; color: #166534; }
.pr-bank { background: #dbeafe; color: #1e40af; }
.pr-card { background: #ede9fe; color: #5b21b6; }
.detail-section { margin-bottom: 1.2rem; }
.detail-section-title {
    font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
    color: #64748b; margin-bottom: 0.6rem; display: flex; align-items: center; gap: 7px;
    border-bottom: 2px solid #e2e8f0; padding-bottom: 6px;
}
.detail-section-title i { font-size: 0.85rem; opacity: 0.7; }
.detail-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
}
.detail-item {
    display: flex; flex-direction: column; gap: 2px;
    padding: 6px 10px; background: rgba(248, 250, 252, 0.6); border-radius: 8px;
}
.detail-item .dl { font-size: 0.72rem; color: #94a3b8; font-weight: 500; text-transform: uppercase; letter-spacing: 0.03em; }
.detail-item .dv { font-size: 0.9rem; color: #1e293b; font-weight: 600; }
.detail-item.full { grid-column: 1 / -1; }
.detail-service-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 12px; background: #f1f5f9; border-radius: 10px; margin-bottom: 5px;
    border: 1px solid #e2e8f0; transition: background 0.15s;
}
.detail-service-row:hover { background: #e2e8f0; }
.detail-service-row .svc-name { font-weight: 600; font-size: 0.85rem; color: #334155; }
.detail-service-row .svc-variants { font-size: 0.75rem; color: #8b5cf6; margin-top: 2px; }
.detail-service-row .svc-price { font-weight: 700; font-size: 0.9rem; color: #059669; white-space: nowrap; }
.detail-status-badge {
    display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px;
    border-radius: 20px; font-size: 0.78rem; font-weight: 600;
}
.detail-status-badge.s-pending { background: #fef3c7; color: #92400e; }
.detail-status-badge.s-confirmed { background: #dbeafe; color: #1e40af; }
.detail-status-badge.s-completed { background: #d1fae5; color: #065f46; }
.detail-status-badge.s-cancelled { background: #fee2e2; color: #991b1b; }
.customer-stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.customer-stat-card {
    text-align: center; background: #f1f5f9; border-radius: 10px; padding: 10px 6px;
    border: 1px solid #e2e8f0; transition: transform 0.15s;
}
.customer-stat-card:hover { transform: translateY(-1px); }
.customer-stat-card .csv { font-size: 1.15rem; font-weight: 800; color: #1e293b; }
.customer-stat-card .csl { font-size: 0.7rem; color: #94a3b8; font-weight: 500; margin-top: 2px; }

.detail-customer-link {
    background: none; border: none; padding: 0; margin: 0; cursor: pointer;
    color: #2563eb; font-weight: 700; font-size: inherit; text-align: left;
    text-decoration: underline; text-underline-offset: 2px;
}
.detail-customer-link:hover { color: #1d4ed8; }
.btn-detail-history {
    border: 1px solid #93c5fd; background: #eff6ff; color: #1d4ed8;
    font-size: 0.72rem; font-weight: 700; padding: 0.35rem 0.65rem; border-radius: 8px;
    cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;
}
.btn-detail-history:hover { background: #dbeafe; }
.customer-stat-grid-clickable { cursor: pointer; }
.customer-stat-grid-clickable:hover .customer-stat-card { border-color: #93c5fd; background: #f8fbff; }
.detail-customer-history-panel {
    margin-top: 0.85rem; padding-top: 0.75rem; border-top: 1px dashed #cbd5e1;
}
.detail-history-toolbar {
    display: grid; grid-template-columns: 1.4fr 1fr; gap: 0.45rem; margin-bottom: 0.65rem;
}
.detail-history-toolbar input,
.detail-history-toolbar select {
    width: 100%; padding: 0.45rem 0.65rem; border: 1px solid #e2e8f0; border-radius: 8px;
    font-size: 0.8rem; background: #fff; color: #1e293b;
}
.detail-customer-history-list { display: flex; flex-direction: column; gap: 0.55rem; max-height: 320px; overflow: auto; }
.detail-history-meta { font-size: 0.72rem; color: #64748b; margin-bottom: 0.25rem; }
.detail-history-loading, .detail-history-empty {
    text-align: center; color: #94a3b8; padding: 1rem 0.5rem; font-size: 0.85rem;
}
.detail-history-item {
    background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #94a3b8;
    border-radius: 10px; padding: 0.65rem 0.75rem;
}
.dhi-top { display: flex; justify-content: space-between; gap: 0.5rem; align-items: flex-start; }
.dhi-date { font-weight: 700; color: #1e293b; font-size: 0.88rem; }
.dhi-emp { font-size: 0.72rem; color: #64748b; margin-top: 0.15rem; }
.dhi-badges { display: flex; flex-direction: column; gap: 0.25rem; align-items: flex-end; }
.dhi-status, .dhi-pay {
    font-size: 0.68rem; font-weight: 800; padding: 0.2rem 0.45rem; border-radius: 6px; white-space: nowrap;
}
.dhi-services { margin-top: 0.45rem; padding-top: 0.4rem; border-top: 1px dashed #e2e8f0; }
.dhi-svc { display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.8rem; color: #334155; }
.dhi-bottom { margin-top: 0.4rem; text-align: right; }
.dhi-total { font-weight: 900; font-size: 0.95rem; }
.dhi-notes { margin-top: 0.4rem; font-size: 0.75rem; color: #64748b; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 0.35rem 0.5rem; }

body.dark-mode .detail-customer-link { color: #60a5fa; }
body.dark-mode .btn-detail-history { background: #1e3a8a; border-color: #3b82f6; color: #dbeafe; }
body.dark-mode .detail-history-toolbar input,
body.dark-mode .detail-history-toolbar select { background: #0f172a; border-color: #334155; color: #f1f5f9; }
body.dark-mode .detail-history-item { background: #0f172a; border-color: #334155; }
body.dark-mode .dhi-date { color: #f1f5f9; }
body.dark-mode .dhi-svc { color: #cbd5e1; }
body.dark-mode .dhi-notes { background: #1e293b; border-color: #334155; color: #94a3b8; }
body.dark-mode .customer-stat-grid-clickable:hover .customer-stat-card { background: #1e293b; border-color: #3b82f6; }

/* Dark mode - Detail Modal */
body.dark-mode .detail-section-title { color: #94a3b8; border-bottom-color: #334155; }
body.dark-mode .detail-item { background: rgba(30, 41, 59, 0.6); }
body.dark-mode .detail-item .dl { color: #64748b; }
body.dark-mode .detail-item .dv { color: #f1f5f9; }
body.dark-mode .detail-service-row { background: #0f172a; border-color: #334155; }
body.dark-mode .detail-service-row:hover { background: #1e293b; }
body.dark-mode .detail-service-row .svc-name { color: #f1f5f9; }
body.dark-mode .detail-service-row .svc-price { color: #34d399; }
body.dark-mode .detail-status-badge.s-pending { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
body.dark-mode .detail-status-badge.s-confirmed { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
body.dark-mode .detail-status-badge.s-completed { background: rgba(16, 185, 129, 0.15); color: #34d399; }
body.dark-mode .detail-status-badge.s-cancelled { background: rgba(239, 68, 68, 0.15); color: #f87171; }
body.dark-mode .customer-stat-card { background: #0f172a; border-color: #334155; }
body.dark-mode .customer-stat-card .csv { color: #f1f5f9; }
body.dark-mode .customer-stat-card .csl { color: #64748b; }
body.dark-mode .pending-revenue-section { background: rgba(120, 53, 15, 0.15); border-color: #78350f; }
body.dark-mode .pending-revenue-header { color: #fcd34d; }
body.dark-mode .pending-revenue-count { background: rgba(120, 53, 15, 0.3); color: #fde68a; border-color: #78350f; }
body.dark-mode .pending-revenue-item { background: #1e293b; border-color: #334155; }
body.dark-mode .pr-customer { color: #f1f5f9; }
body.dark-mode .pr-date, body.dark-mode .pr-employee, body.dark-mode .pr-service { color: #94a3b8; }
body.dark-mode .pr-amount { color: #34d399; }
body.dark-mode .pr-cash { background: rgba(22, 163, 74, 0.15); color: #86efac; }
body.dark-mode .pr-bank { background: rgba(37, 99, 235, 0.15); color: #93c5fd; }
body.dark-mode .pr-card { background: rgba(109, 40, 217, 0.15); color: #c4b5fd; }

@media (max-width: 500px) {
    .detail-grid { grid-template-columns: 1fr; }
    .customer-stat-grid { grid-template-columns: 1fr 1fr 1fr; }
}
</style>

<div id="endOfDayModal" class="modal">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <span class="close" onclick="closeModal('endOfDayModal')">&times;</span>
        <h3><i class="fas fa-calculator"></i> Detaylı Gün Sonu Hesaplama</h3>
        
        <div id="employeePayoutList" class="eod-employee-list">
            </div>
        
        <div class="eod-summary">
            <h4><i class="fas fa-cash-register"></i> Kasa Özeti</h4>
            <div class="eod-summary-grid">
                <div>
                    <strong>Toplam Girdi (Nakit):</strong>
                    <span id="eod_total_cash">0 ₺</span>
                </div>
                <div>
                    <strong>Toplam Girdi (Havale/EFT):</strong>
                    <span id="eod_total_bank">0 ₺</span>
                </div>
                <div>
                    <strong>Toplam Girdi (Kredi Kartı):</strong>
                    <span id="eod_total_credit_card">0 ₺</span>
                </div>
                <div style="color:#ef4444">
                    <strong>Toplam Alınmadı:</strong>
                    <span id="eod_total_not_paid">0 ₺</span>
                </div>
                <div>
                    <strong>Ödenen (Nakit):</strong>
                    <span id="eod_paid_cash" class="text-danger">0 ₺</span>
                </div>
                <div>
                    <strong>Ödenen (Havale/EFT):</strong>
                    <span id="eod_paid_iban" class="text-danger">0 ₺</span>
                </div>
                <div class="highlight-total">
                    <strong>KASADA KALAN (Nakit):</strong>
                    <span id="eod_remaining_cash">0 ₺</span>
                </div>
                <div class="highlight-total">
                    <strong>KASADA KALAN (Havale/EFT):</strong>
                    <span id="eod_remaining_iban">0 ₺</span>
                </div>
            </div>
        </div>
        
        <div style="display: flex; gap: 1rem; justify-content: center; margin-top: 1.5rem; flex-wrap: wrap;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('endOfDayModal')">
                <i class="fas fa-times"></i> Kapat
            </button>
            <button type="button" class="btn btn-success" onclick="saveDayEndPayments()" id="saveDayEndBtn">
                <i class="fas fa-save"></i> Ödemeleri Kaydet
            </button>
        </div>
    </div>
</div>
<script>
    window.dailyViewData = {
        services: <?php echo json_encode($services); ?>,
        serviceVariants: <?php echo json_encode($service_variants_map); ?>,
        employeeSummary: <?php echo json_encode($employee_summary); ?>,
        employeeList: <?php echo json_encode($employees); ?>,
        grandTotalCash: <?php echo $grand_total_cash; ?>,
        grandTotalBank: <?php echo $grand_total_bank; ?>,
        grandTotalCreditCard: <?php echo $grand_total_credit_card; ?>,
        grandTotalNotPaid: <?php echo $grand_total_not_paid; ?>,
        grandTotalPendingCash: <?php echo $grand_total_pending_cash; ?>,
        grandTotalPendingBank: <?php echo $grand_total_pending_bank; ?>,
        grandTotalPendingCreditCard: <?php echo $grand_total_pending_credit_card; ?>,
        pendingRevenueToday: <?php echo json_encode($pending_revenue_today); ?>,
        canAccessRevenue: <?php echo can_access_page('revenues.php') ? 'true' : 'false'; ?>,
        selectedDate: "<?php echo htmlspecialchars($selected_date); ?>"
    };
</script>
<script src="assets/js/daily_view.js?v=<?php echo time(); ?>"></script>
<?php require_once __DIR__ . '/../config/service_qty.php'; ?>
<script>window.SVC_MAX_QTY = <?php echo json_encode((object)svcQtyMaxMap($db, (int)TENANT_ID)); ?>;</script>
<script src="assets/js/service_qty.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/service_qty.js'); ?>"></script>

<?php if(isset($success) && $success): ?>
<script>document.addEventListener('DOMContentLoaded', function() { _notify(<?php echo json_encode($success); ?>, 'success'); });</script>
<?php endif; ?>

<?php if(isset($error) && $error): ?>
<script>document.addEventListener('DOMContentLoaded', function() { _notify(<?php echo json_encode($error); ?>, 'error'); });</script>
<?php endif; ?>

<script>
// Hizmet arama filtresi
document.addEventListener('DOMContentLoaded', function() {
    function setupServiceSearch(inputId, containerId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('input', function() {
            var query = this.value.toLowerCase().replace(/İ/gi, 'i').replace(/I/g, 'ı');
            var container = document.getElementById(containerId);
            if (!container) return;
            container.querySelectorAll('.service-item-wrapper').forEach(function(item) {
                var label = item.querySelector('label');
                if (!label) return;
                var text = label.textContent.toLowerCase().replace(/İ/gi, 'i').replace(/I/g, 'ı');
                item.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }
    setupServiceSearch('serviceSearchInput', 'servicesSelection');
    setupServiceSearch('betweenServiceSearchInput', 'betweenServicesSelection');
});
</script>

<?php 
// Çıktı tamponlamayı bitir
ob_end_flush();
include 'includes/footer.php'; 
?>