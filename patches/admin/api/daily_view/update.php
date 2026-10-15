<?php
/**
 * Daily View Update Appointment API Endpoint
 * 
 * POST /api/daily_view/update.php
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
    
    $appointmentId = (int)($input['appointment_id'] ?? 0);
    $serviceIds = $input['service_ids'] ?? [];
    $rangePrices = $input['range_prices'] ?? [];
    $totalPrice = (float)($input['total_price'] ?? 0);
    $notes = trim($input['notes'] ?? '');
    if (function_exists('sanitizeNotes')) {
        $notes = sanitizeNotes($notes);
    }
    $paymentMethod = $input['payment_method'] ?? '';
    $variantData = $input['variant_data'] ?? [];
    $paidAmount = isset($input['paid_amount']) && $input['paid_amount'] !== '' ? (float)$input['paid_amount'] : null;
    $allowedPaymentMethods = ['cash', 'bank_transfer', 'credit_card', 'mixed', 'not_paid'];
    if ($paymentMethod !== '' && !in_array($paymentMethod, $allowedPaymentMethods, true)) {
        $paymentMethod = 'cash';
    }

    // Tam düzenleme için opsiyonel alanlar (randevular sayfasındaki gibi)
    $fullEdit = !empty($input['full_edit']);
    $customerName  = isset($input['customer_name'])  ? trim((string)$input['customer_name'])  : null;
    $customerPhone = isset($input['customer_phone']) ? trim((string)$input['customer_phone']) : null;
    $employeeId    = isset($input['employee_id'])    ? (int)$input['employee_id']             : null;
    $appointmentDate = isset($input['appointment_date']) ? trim((string)$input['appointment_date']) : null;
    $appointmentTime = isset($input['appointment_time']) ? trim((string)$input['appointment_time']) : null;
    $statusInput     = isset($input['status']) ? trim((string)$input['status']) : null;

    // Validasyon
    if ($appointmentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz randevu ID']);
        exit;
    }
    
    if (empty($serviceIds) || !is_array($serviceIds)) {
        echo json_encode(['success' => false, 'message' => 'En az bir hizmet seçilmeli']);
        exit;
    }
    
    // Randevunun var olduğunu kontrol et
    $stmt = $db->prepare("SELECT id, customer_id, employee_id, appointment_date, appointment_time, status FROM appointments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL");
    $stmt->execute([$appointmentId, $tenantId]);
    $existingApp = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingApp) {
        echo json_encode(['success' => false, 'message' => 'Randevu bulunamadı']);
        exit;
    }

    // Tam düzenleme modu için validasyon
    if ($fullEdit) {
        if ($customerName === '' || $customerPhone === '' || !$employeeId || !$appointmentDate || !$appointmentTime) {
            echo json_encode(['success' => false, 'message' => 'Lütfen tüm zorunlu alanları doldurun.']);
            exit;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointmentDate)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz tarih formatı.']);
            exit;
        }
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $appointmentTime)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz saat formatı.']);
            exit;
        }
        $allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'break'];
        if ($statusInput && !in_array($statusInput, $allowedStatuses, true)) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz durum.']);
            exit;
        }
    }
    
    // Hizmetlerin toplam fiyatını ve süresini hesapla
    $svcPlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $stmtSvcData = $db->prepare("SELECT id, price, duration, pricing_type, price_min FROM services WHERE id IN ($svcPlaceholders) AND tenant_id = ? AND deleted_at IS NULL");
    $stmtSvcData->execute(array_merge(array_map('intval', $serviceIds), [$tenantId]));
    $svcMap = [];
    foreach ($stmtSvcData->fetchAll(PDO::FETCH_ASSOC) as $sv) {
        $svcMap[(int)$sv['id']] = $sv;
    }

    // Eski final_price (aralık koruması)
    $stmtOld = $db->prepare("SELECT service_id, final_price, quantity, variant_ids FROM appointment_services WHERE appointment_id = ?");
    $stmtOld->execute([$appointmentId]);
    $oldSvc = [];
    foreach ($stmtOld->fetchAll(PDO::FETCH_ASSOC) as $od) {
        $oldSvc[(int)$od['service_id']] = $od;
    }
    
    require_once __DIR__ . '/../../../config/service_qty.php';
    $svcQty = svcQtyResolve($db, (int)$tenantId, (array)$serviceIds, $input['service_quantities'] ?? null, svcQtyExisting($db, $appointmentId));

    // Per-service final prices/durations (variant-aware + range_prices)
    $serviceFinalData = [];
    $totalDuration = 0;
    $calculatedTotal = 0;
    $rangeSid = null;
    foreach ($serviceIds as $sid) {
        $sid = (int)$sid;
        $svcVariants = $variantData[(string)$sid] ?? [];
        $variantIds = !empty($svcVariants) ? json_encode(array_values(array_column($svcVariants, 'id'))) : ($oldSvc[$sid]['variant_ids'] ?? null);
        $variantExtraPrice = array_sum(array_column($svcVariants, 'extra_price'));
        $variantExtraDuration = array_sum(array_column($svcVariants, 'extra_duration'));

        if (array_key_exists((string)$sid, (array)$rangePrices) || array_key_exists($sid, (array)$rangePrices)) {
            $basePrice = (float)($rangePrices[(string)$sid] ?? $rangePrices[$sid]);
        } elseif (($svcMap[$sid]['pricing_type'] ?? '') === 'range' && isset($oldSvc[$sid]['final_price']) && $oldSvc[$sid]['final_price'] !== null && empty($svcVariants)) {
            $basePrice = (float)$oldSvc[$sid]['final_price'] / max(1, (int)($oldSvc[$sid]['quantity'] ?? 1));
        } elseif (($svcMap[$sid]['pricing_type'] ?? '') === 'range' && $svcMap[$sid]['price_min'] !== null) {
            $basePrice = (float)$svcMap[$sid]['price_min'];
        } else {
            $basePrice = (float)($svcMap[$sid]['price'] ?? 0);
        }

        $baseDuration = (int)($svcMap[$sid]['duration'] ?? 30);
        $fp = $basePrice + (float)$variantExtraPrice;
        $fd = $baseDuration + (int)$variantExtraDuration;
        $q = $svcQty[$sid] ?? 1;
        $fp *= $q;
        $fd *= $q;
        if (($svcMap[$sid]['pricing_type'] ?? '') === 'range') {
            $rangeSid = $sid;
        }
        $serviceFinalData[$sid] = ['variant_ids' => $variantIds, 'fp' => $fp, 'fd' => $fd, 'q' => $q];
        $totalDuration += $fd;
        $calculatedTotal += $fp;
    }
    if ($totalDuration <= 0) $totalDuration = 30;
    
    if ($totalPrice <= 0) {
        $totalPrice = $calculatedTotal;
    }
    // Tek (veya son) aralıklı hizmete toplam farkı yaz — kasa / final_price senkron
    if ($rangeSid !== null && abs($calculatedTotal - $totalPrice) > 0.009 && isset($serviceFinalData[$rangeSid])) {
        $serviceFinalData[$rangeSid]['fp'] = max(0, (float)$serviceFinalData[$rangeSid]['fp'] + ($totalPrice - $calculatedTotal));
        $calculatedTotal = $totalPrice;
    }
    
    // Kısmi ödeme kontrol
    $isPartial = ($paidAmount !== null && $paidAmount > 0 && $totalPrice > 0 && $paidAmount < $totalPrice);
    if (!$isPartial) $paidAmount = null;

    $db->beginTransaction();

    // Tam düzenleme modu: müşteri, çalışan, tarih, saat, durum güncelle
    $newCustomerId = null;
    if ($fullEdit) {
        // Müşteriyi getir / oluştur (config.php içinde tanımlı global yardımcı varsa onu kullan)
        if (function_exists('getOrCreateCustomer')) {
            $custData = getOrCreateCustomer($customerName, $customerPhone);
            $newCustomerId = (int)($custData['id'] ?? 0);
        } else {
            $cleanPhone = function_exists('formatPhoneNumber') ? formatPhoneNumber($customerPhone) : preg_replace('/[^0-9]/', '', $customerPhone);
            $stmtCust = $db->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
            $stmtCust->execute([$cleanPhone]);
            $cust = $stmtCust->fetch(PDO::FETCH_ASSOC);
            if ($cust) {
                $newCustomerId = (int)$cust['id'];
            } else {
                $stmtIns = $db->prepare("INSERT INTO customers (tenant_id, name, phone, is_guest) VALUES (NULL, ?, ?, 1)");
                $stmtIns->execute([$customerName, $cleanPhone]);
                $newCustomerId = (int)$db->lastInsertId();
            }
            $stmtLink = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), deleted_at = NULL");
            $stmtLink->execute([$newCustomerId, $tenantId, $customerName]);
        }

        $newStatus = $statusInput ?: $existingApp['status'];

        if (!empty($paymentMethod)) {
            $stmt = $db->prepare("
                UPDATE appointments SET 
                    customer_id = ?, employee_id = ?, appointment_date = ?, appointment_time = ?, status = ?,
                    total_price = ?, total_duration = ?, notes = ?, payment_method = ?, paid_amount = ?, is_edited = 1
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$newCustomerId, $employeeId, $appointmentDate, $appointmentTime, $newStatus, $totalPrice, $totalDuration, $notes, $paymentMethod, $paidAmount, $appointmentId, $tenantId]);
        } else {
            $stmt = $db->prepare("
                UPDATE appointments SET 
                    customer_id = ?, employee_id = ?, appointment_date = ?, appointment_time = ?, status = ?,
                    total_price = ?, total_duration = ?, notes = ?, is_edited = 1
                WHERE id = ? AND tenant_id = ?
            ");
            $stmt->execute([$newCustomerId, $employeeId, $appointmentDate, $appointmentTime, $newStatus, $totalPrice, $totalDuration, $notes, $appointmentId, $tenantId]);
        }
    }
    // Sadece fiyat/hizmet/notlar güncelleme (eski davranış)
    elseif (!empty($paymentMethod)) {
        $stmt = $db->prepare("
            UPDATE appointments SET 
                total_price = ?, 
                total_duration = ?,
                notes = ?,
                payment_method = ?,
                paid_amount = ?,
                is_edited = 1
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$totalPrice, $totalDuration, $notes, $paymentMethod, $paidAmount, $appointmentId, $tenantId]);
    } else {
        $stmt = $db->prepare("
            UPDATE appointments SET 
                total_price = ?, 
                total_duration = ?,
                notes = ?,
                is_edited = 1
            WHERE id = ? AND tenant_id = ?
        ");
        $stmt->execute([$totalPrice, $totalDuration, $notes, $appointmentId, $tenantId]);
    }
    
    // Mevcut varyant bilgilerini kaydet
    // Eski hizmetleri sil
    $stmtDel = $db->prepare("DELETE FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
    $stmtDel->execute([$appointmentId, $appointmentId, $tenantId]);
    
    // Yeni hizmetleri ekle (variant_data ile)
    $stmtService = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, variant_ids, final_price, final_duration, quantity) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($serviceIds as $serviceId) {
        $sid = (int)$serviceId;
        $d = $serviceFinalData[$sid] ?? ['variant_ids' => null, 'fp' => 0, 'fd' => 30];
        $stmtService->execute([$appointmentId, $sid, $d['variant_ids'], $d['fp'], $d['fd'], $d['q'] ?? 1]);
    }
    
    $db->commit();
    
    // Güncellenen randevuyu getir
    $stmt = $db->prepare("
        SELECT 
            a.*,
            COALESCE(ct.name, c.name) as customer_name,
            c.phone as customer_phone,
            e.name as employee_name,
            GROUP_CONCAT(s.id) as service_ids,
            GROUP_CONCAT(s.name SEPARATOR ', ') as service_name
        FROM appointments a
        LEFT JOIN customers c ON a.customer_id = c.id
        LEFT JOIN customer_tenants ct ON c.id = ct.customer_id AND ct.tenant_id = a.tenant_id
        LEFT JOIN employees e ON a.employee_id = e.id
        LEFT JOIN appointment_services a_s ON a.id = a_s.appointment_id
        LEFT JOIN services s ON a_s.service_id = s.id
        WHERE a.id = ? AND a.tenant_id = ?
        GROUP BY a.id
    ");
    $stmt->execute([$appointmentId, $tenantId]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Format ekle
    $appointment['appointment_time_formatted'] = date('H:i', strtotime($appointment['appointment_time']));
    $appointment['total_price_formatted'] = number_format((float)$appointment['total_price'], 2, ',', '.') . ' ₺';

    echo json_encode([
        'success' => true,
        'message' => 'Randevu başarıyla güncellendi',
        'appointment' => $appointment
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Veritabanı hatası: ' . safeErrorMessage($e)
    ], JSON_UNESCAPED_UNICODE);
}
