<?php
/**
 * Daily View Create Appointment API Endpoint
 * 
 * POST /api/daily_view/create.php
 * 
 * @package API/DailyView
 */

header('Content-Type: application/json; charset=UTF-8');

$config_path = __DIR__ . '/../../../config/config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Config dosyası bulunamadı']);
    exit;
}
require_once $config_path;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Sadece POST metodu kabul edilir']);
    exit;
}

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor']);
    exit;
}

// CSRF kontrolü - multiple sources
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfToken)) {
    $headers = getallheaders();
    $csrfToken = $headers['X-CSRF-Token'] ?? $headers['X-Csrf-Token'] ?? $headers['x-csrf-token'] ?? '';
}
if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Geçersiz güvenlik tokeni']);
    exit;
}

if (!defined('TENANT_ID')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tenant bilgisi bulunamadı']);
    exit;
}

$tenantId = TENANT_ID;

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $selectedDate = $input['appointment_date'] ?? date('Y-m-d');
    $appointmentTime = $input['appointment_time'] ?? '';
    $employeeId = $input['employee_id'] ?? '';
    $customerName = sanitizeName($input['customer_name'] ?? '', 50);
    $customerPhone = trim($input['customer_phone'] ?? '');
    $serviceIds = $input['service_ids'] ?? [];
    $rangePrices = $input['range_prices'] ?? [];
    $totalPrice = (float)($input['total_price'] ?? 0);
    $notes = trim($input['notes'] ?? '');
    if (function_exists('sanitizeNotes')) {
        $notes = sanitizeNotes($notes);
    }
    $isBetween = (int)($input['is_between'] ?? 0);
    $status = $input['status'] ?? 'confirmed';
    $sendCustomerSms = (int)($input['send_customer_sms'] ?? 1) === 1;
    $waitlistId = (int)($input['waitlist_id'] ?? 0);
    
    // Geçerli status kontrolü
        $variantData = $input['variant_data'] ?? [];
    
        // Geçerli status kontrolü
        $allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'break'];
    if (!in_array($status, $allowedStatuses)) {
        $status = 'confirmed';
    }
    
    // Validasyon
    if (empty($appointmentTime)) {
        echo json_encode(['success' => false, 'message' => 'Randevu saati gerekli']);
        exit;
    }
    
    if (empty($employeeId)) {
        echo json_encode(['success' => false, 'message' => 'Personel seçimi gerekli']);
        exit;
    }
    
    if (empty($customerName)) {
        echo json_encode(['success' => false, 'message' => 'Müşteri adı gerekli']);
        exit;
    }
    
    if (empty($serviceIds) || !is_array($serviceIds)) {
        echo json_encode(['success' => false, 'message' => 'En az bir hizmet seçilmeli']);
        exit;
    }
    
    // Müşteriyi al veya oluştur
    $customerId = getOrCreateCustomer($customerName, $customerPhone);
    
    // Hizmetlerin toplam fiyatını ve süresini hesapla
    $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $stmt = $db->prepare("SELECT id, price, pricing_type, price_min, duration FROM services WHERE id IN ($placeholders) AND tenant_id = ? AND deleted_at IS NULL AND status = 'active'");
    $params = array_merge($serviceIds, [$tenantId]);
    $stmt->execute($params);
    $servicesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    require_once __DIR__ . '/../../../config/service_qty.php';
    $svcQty = svcQtyResolve($db, (int)$tenantId, (array)$serviceIds, $input['service_quantities'] ?? null);
    $calculatedTotal = 0;
    $totalDuration = 0;
    $servicePriceMap = [];
    $serviceDurationMap = [];
    $clientTotalProvided = array_key_exists('total_price', (array)$input) && is_numeric($input['total_price']);
    foreach ($servicesData as $svc) {
        $svcId = (int)$svc['id'];
        $serviceDurationMap[$svcId] = (int)$svc['duration'];
        $totalDuration += (int)$svc['duration'] * ($svcQty[$svcId] ?? 1);
        // JSON anahtarları string gelir — hem string hem int dene
        if (array_key_exists((string)$svcId, (array)$rangePrices) || array_key_exists($svcId, (array)$rangePrices)) {
            $price = (float)($rangePrices[(string)$svcId] ?? $rangePrices[$svcId]);
        } elseif (($svc['pricing_type'] ?? '') === 'range' && $svc['price_min'] !== null && $svc['price_min'] !== '') {
            $price = (float)$svc['price_min'];
        } else {
            $price = (float)$svc['price'];
        }
        $servicePriceMap[$svcId] = $price;
        $calculatedTotal += $price * ($svcQty[$svcId] ?? 1);
    }
    
    // Varyant süre/fiyat ekleri (hesaplanan toplam + süre)
    foreach ($serviceIds as $serviceId) {
        $svcVariants = $variantData[(string)$serviceId] ?? $variantData[(string)(int)$serviceId] ?? [];
        if (!empty($svcVariants) && is_array($svcVariants)) {
            $totalDuration += (int) array_sum(array_column($svcVariants, 'extra_duration'));
            $calculatedTotal += (float) array_sum(array_column($svcVariants, 'extra_price'));
        }
    }

    // İstemci toplam gönderdiyse onu esas al (çift sayım yok); yoksa hesaplanan
    if ($clientTotalProvided && (float)$input['total_price'] >= 0) {
        $totalPrice = (float)$input['total_price'];
    } else {
        $totalPrice = $calculatedTotal;
    }
    if ($totalDuration <= 0) $totalDuration = 30;

    // Araya alma değilse çakışma kontrolü
    // - Aynı başlangıç saati: soft onay (needs_confirm) → koç/işletme "araya ekle" derse is_between=1
    // - Ara saat (örn. 10:00-12:30 içinde 10:30): otomatik araya al, hata verme
    if (!(int)$isBetween) {
        $timeNorm = $appointmentTime;
        if (strlen($timeNorm) === 5) {
            $timeNorm .= ':00';
        }
        $conflictStmt = $db->prepare("
            SELECT a.appointment_time,
                   TIME(ADDTIME(a.appointment_time, SEC_TO_TIME(a.total_duration * 60))) AS end_time,
                   COALESCE(ct.name, c.name, 'Diğer müşteri') AS customer_name
            FROM appointments a
            LEFT JOIN customers c ON c.id = a.customer_id
            LEFT JOIN customer_tenants ct ON ct.customer_id = a.customer_id AND ct.tenant_id = a.tenant_id
            WHERE a.employee_id = ? AND a.appointment_date = ? AND a.tenant_id = ?
              AND a.status != 'cancelled'
              AND a.deleted_at IS NULL
              AND a.appointment_time < ADDTIME(?, SEC_TO_TIME(? * 60))
              AND ADDTIME(a.appointment_time, SEC_TO_TIME(a.total_duration * 60)) > ?
            ORDER BY a.appointment_time ASC
            LIMIT 1
        ");
        $conflictStmt->execute([
            $employeeId, $selectedDate, $tenantId,
            $timeNorm, $totalDuration, $timeNorm
        ]);
        $conflictRow = $conflictStmt->fetch(PDO::FETCH_ASSOC);
        if ($conflictRow) {
            $existingStart = substr((string)$conflictRow['appointment_time'], 0, 5);
            $newStart = substr($timeNorm, 0, 5);
            $conflictLabel = $existingStart . '-'
                . substr((string)$conflictRow['end_time'], 0, 5)
                . ' (' . $conflictRow['customer_name'] . ')';

            if ($existingStart === $newStart) {
                echo json_encode([
                    'success' => false,
                    'conflict' => true,
                    'needs_confirm' => true,
                    'conflict_type' => 'exact_start',
                    'message' => 'Bu saatte bir randevu var: ' . $conflictLabel . '. Araya eklensin mi?',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Farklı başlangıç ama süre aralığı örtüşüyor → ara slot / araya ekleme
            $isBetween = 1;
        }
        $appointmentTime = $timeNorm;
    }
    
    $db->beginTransaction();
    
    // Randevuyu ekle
    $stmt = $db->prepare("
        INSERT INTO appointments 
        (tenant_id, customer_id, employee_id, appointment_date, appointment_time, status, total_price, total_duration, notes, is_between) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$tenantId, $customerId, $employeeId, $selectedDate, $appointmentTime, $status, $totalPrice, $totalDuration, $notes, $isBetween]);
    $appointmentId = $db->lastInsertId();
    
    // Hizmetleri ekle (variant_ids, final_price, final_duration ile)
    $stmtService = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, variant_ids, final_price, final_duration, quantity) VALUES (?, ?, ?, ?, ?, ?)");
    $serviceRows = [];
    $computedSum = 0;
    $rangeSid = null;
    foreach ($serviceIds as $serviceId) {
        $sid = (int)$serviceId;
        $svcVariants = $variantData[(string)$serviceId] ?? $variantData[(string)$sid] ?? [];
        $variantIds = !empty($svcVariants) ? json_encode(array_values(array_column($svcVariants, 'id'))) : null;
        $variantExtraPrice = array_sum(array_column($svcVariants, 'extra_price'));
        $variantExtraDuration = array_sum(array_column($svcVariants, 'extra_duration'));
        $fp = ($servicePriceMap[$sid] ?? 0) + (float)$variantExtraPrice;
        $fd = ($serviceDurationMap[$sid] ?? 30) + (int)$variantExtraDuration;
        $q = $svcQty[$sid] ?? 1;
        // pricing_type bilgisini servicesData'dan bul
        foreach ($servicesData as $svcRow) {
            if ((int)$svcRow['id'] === $sid && ($svcRow['pricing_type'] ?? '') === 'range') {
                $rangeSid = $sid;
                break;
            }
        }
        $computedSum += $fp * $q;
        $serviceRows[] = [$appointmentId, $serviceId, $variantIds, $fp * $q, $fd * $q, $q];
    }
    if ($rangeSid !== null && abs($computedSum - $totalPrice) > 0.009) {
        foreach ($serviceRows as &$row) {
            if ((int)$row[1] === (int)$rangeSid) {
                $row[3] = max(0, (float)$row[3] + ($totalPrice - $computedSum));
            }
        }
        unset($row);
    }
    foreach ($serviceRows as $row) {
        $stmtService->execute($row);
    }

    $db->commit();

    // Bekleme listesinden dönüştürüldüyse kaydı kapat (ana işlemden bağımsız)
    // Ana randevu kaydı başarılıysa burada oluşan hata kullanıcıyı bloklamasın.
    if ($waitlistId > 0) {
        try {
            ensureWaitingListTable();
            $stmtWait = $db->prepare("UPDATE waiting_list SET status = 'booked' WHERE id = ? AND tenant_id = ?");
            $stmtWait->execute([$waitlistId, $tenantId]);
        } catch (Exception $waitlistEx) {
            error_log('Daily view waitlist close warning: appointment=' . $appointmentId . ', waitlist=' . $waitlistId . ', tenant=' . $tenantId . ', reason=' . $waitlistEx->getMessage());
        }
    }
    
    // Eklenen randevuyu getir (hata olursa isteği düşürme)
    $appointment = [
        'id' => (int)$appointmentId,
        'appointment_date' => $selectedDate,
        'appointment_time' => $appointmentTime,
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'employee_name' => '',
        'service_ids' => implode(',', array_map('intval', $serviceIds)),
        'service_name' => ''
    ];

    try {
        $stmt = $db->prepare(" 
            SELECT 
                a.*,
                COALESCE(ct.name, c.name) as customer_name,
                c.phone as customer_phone,
                e.name as employee_name
            FROM appointments a
            LEFT JOIN customers c ON a.customer_id = c.id
            LEFT JOIN customer_tenants ct ON c.id = ct.customer_id AND ct.tenant_id = a.tenant_id
            LEFT JOIN employees e ON a.employee_id = e.id
            WHERE a.id = ? AND a.tenant_id = ?
            LIMIT 1
        ");
        $stmt->execute([$appointmentId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $appointment = array_merge($appointment, $row);
        }

        $stmtServices = $db->prepare(" 
            SELECT s.id, CONCAT(IF(a_s.quantity > 1, CONCAT(a_s.quantity, 'x '), ''), s.name) AS name
            FROM appointment_services a_s
            INNER JOIN services s ON s.id = a_s.service_id
            WHERE a_s.appointment_id = ?
            ORDER BY s.name ASC
        ");
        $stmtServices->execute([$appointmentId]);
        $serviceRows = $stmtServices->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($serviceRows)) {
            $appointment['service_ids'] = implode(',', array_map(function ($x) { return (int)$x['id']; }, $serviceRows));
            $appointment['service_name'] = implode(', ', array_map(function ($x) { return $x['name']; }, $serviceRows));
        }
    } catch (Exception $postReadEx) {
        error_log('Daily view create readback warning: appointment=' . $appointmentId . ', tenant=' . $tenantId . ', reason=' . $postReadEx->getMessage());
    }

    // Format ekle
    $appointment['appointment_time_formatted'] = date('H:i', strtotime($appointment['appointment_time'] ?? $appointmentTime));
    $appointment['total_price_formatted'] = number_format((float)($appointment['total_price'] ?? $totalPrice), 2, ',', '.') . ' ₺';

    // İsteğe bağlı: müşteriye bilgilendirme SMS'i (müşteri kendi aldığı randevu formatı)
    $smsMeta = function_exists('buildSmsAttemptMeta')
        ? buildSmsAttemptMeta(false)
        : ['sms_requested' => false, 'sms_sent' => false, 'sms_warning' => null];

    if ($sendCustomerSms) {
        if (empty($appointment['customer_phone'])) {
            $smsMeta = buildSmsAttemptMeta(true, null, 'no_phone');
        } else {
            try {
                $stmtSettings = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key IN ('sms_send_on_create','shareable_maps_link')");
                $stmtSettings->execute([$tenantId]);
                $siteSettings = $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR);

                $sendOnCreate = ($siteSettings['sms_send_on_create'] ?? 'on') === 'on';
                if (!$sendOnCreate) {
                    $smsMeta = buildSmsAttemptMeta(true, null, 'send_on_create_off');
                } else {
                    $stmtTenant = $db->prepare("SELECT firma_adi FROM tenants WHERE id = ?");
                    $stmtTenant->execute([$tenantId]);
                    $businessName = truncateForSms($stmtTenant->fetchColumn() ?: 'İşletme', 50);

                    $dateFormatted = date('d.m.Y', strtotime($selectedDate));
                    $timeFormatted = date('H:i', strtotime($appointmentTime));
                    $employeeNameSms = truncateForSms($appointment['employee_name'] ?? '', 40);
                    $mapsLink = $siteSettings['shareable_maps_link'] ?? '';

                    $smsMessage = upperForSms($businessName) . "\n\n";
                    $smsMessage .= "Randevunuz Oluşturuldu\n\n";
                    if (!empty($employeeNameSms)) {
                        $smsMessage .= "Çalışan: {$employeeNameSms}\n";
                    }
                    $smsMessage .= "Tarih: {$dateFormatted}\n";
                    $smsMessage .= "Saat: {$timeFormatted}\n";
                    if (!empty($mapsLink)) {
                        $smsMessage .= "Konum: {$mapsLink}\n";
                    }
                    $smsMessage .= "\nRANDU.TR";
                    if (!empty($appointment['customer_id'])) {
                        $smsMessage = appendCustomerPendingPaymentNotice($db, (int)$tenantId, (int)$appointment['customer_id'], $smsMessage);
                    }

                    $smsResult = sendSms($appointment['customer_phone'], $smsMessage, null, $tenantId);
                    $smsMeta = buildSmsAttemptMeta(true, $smsResult);
                    if (empty($smsResult['status'])) {
                        error_log('Daily view create SMS failed: appointment=' . $appointmentId . ', tenant=' . $tenantId . ', reason=' . ($smsResult['message'] ?? 'unknown'));
                    }
                }
            } catch (Exception $smsEx) {
                error_log('Daily view create SMS error: ' . $smsEx->getMessage());
                $smsMeta = buildSmsAttemptMeta(true, ['status' => false, 'message' => 'SMS gönderilirken hata oluştu.']);
            }
        }
    }

    // İşletme bildirimi (SMS/Telegram) sadece müşteri web'den randevu alınca gider.
    // Defterden işletmenin kendisi eklerken bildirim gönderilmez.

    echo json_encode(array_merge([
        'success' => true,
        'message' => 'Randevu başarıyla eklendi',
        'appointment' => $appointment
    ], $smsMeta), JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Veritabanı hatası: ' . safeErrorMessage($e)
    ], JSON_UNESCAPED_UNICODE);
}

// Helper function - müşteriyi al veya oluştur
function getOrCreateCustomer($name, $phone) {
    global $db;
    
    // Telefon numarasını temizle (formatPhoneNumber ile aynı format)
    $phone = formatPhoneNumber($phone);
    
    // 1. Global Arama (Tenant kısıtlaması olmadan)
    $stmt = $db->prepare("SELECT id FROM customers WHERE phone = ?");
    $stmt->execute([$phone]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($customer) {
        $customer_id = $customer['id'];
        
        // 2. İlişkiyi garantiye al (varsa name güncelle)
        $stmtLink = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), deleted_at = NULL");
        $stmtLink->execute([$customer_id, TENANT_ID, $name]);
        
        return $customer_id;
    } else {
        // 3. Müşteri Yoksa: Global 'misafir' olarak oluştur
        $stmt = $db->prepare("INSERT INTO customers (tenant_id, name, phone, is_guest) VALUES (NULL, ?, ?, 1)");
        $stmt->execute([$name, $phone]);
        $new_id = $db->lastInsertId();
        
        // 4. İlişkiyi Kur
        $stmtLink = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name) VALUES (?, ?, ?)");
        $stmtLink->execute([$new_id, TENANT_ID, $name]);
        
        return $new_id;
    }
}

function ensureWaitingListTable() {
    global $db;

    $db->exec("CREATE TABLE IF NOT EXISTS waiting_list (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        wait_date DATE NOT NULL,
        customer_name VARCHAR(80) NOT NULL,
        customer_phone VARCHAR(20) NOT NULL,
        preferred_employee_id INT NULL,
        preferred_time VARCHAR(5) NULL,
        requested_services VARCHAR(255) NULL,
        note TEXT NULL,
        call_when_available TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'waiting',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_waiting_list_tenant_date_status (tenant_id, wait_date, status),
        INDEX idx_waiting_list_tenant_phone (tenant_id, customer_phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
