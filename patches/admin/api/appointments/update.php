<?php
/**
 * Appointments Update API Endpoint
 * 
 * POST /api/appointments/update.php
 * 
 * @package API/Appointments
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=UTF-8');

// Mevcut config sistemini kullan
$config_path = __DIR__ . '/../../../config/config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Config dosyası bulunamadı']);
    exit;
}
require_once $config_path;

// POST kabul et
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Sadece POST metodu kabul edilir']);
    exit;
}

// Oturum kontrolü
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor']);
    exit;
}

// CSRF kontrolü
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfToken) || $csrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF doğrulama hatası']);
    exit;
}

// Tenant ID
if (!defined('TENANT_ID')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tenant bilgisi bulunamadı']);
    exit;
}
$tenantId = TENANT_ID;

// Request verisini al
$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Helper
function formatPhoneNum($phone) {
    return preg_replace('/[^0-9]/', '', $phone);
}

// Validasyon
$errors = [];

$appointmentId = filter_var($data['appointment_id'] ?? $data['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$customerName = trim($data['customer_name'] ?? '');
$customerPhone = formatPhoneNum($data['customer_phone'] ?? '');
$employeeId = filter_var($data['employee_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$serviceIds = $data['service_ids'] ?? [];
$appointmentDate = trim($data['appointment_date'] ?? '');
$appointmentTime = trim($data['appointment_time'] ?? '');
$status = trim($data['status'] ?? 'confirmed');
$notes = function_exists('sanitizeNotes') ? sanitizeNotes($data['notes'] ?? '') : trim($data['notes'] ?? '');
$variantData = $data['variant_data'] ?? [];
$rangePrices = $data['range_prices'] ?? [];
$clientTotalPrice = isset($data['total_price']) && $data['total_price'] !== '' ? (float)$data['total_price'] : null;

if ($appointmentId <= 0) {
    $errors['appointment_id'] = 'Randevu ID gereklidir';
}

if (empty($customerName)) {
    $errors['customer_name'] = 'Müşteri adı gereklidir';
}

if (empty($customerPhone)) {
    $errors['customer_phone'] = 'Telefon numarası gereklidir';
}

if ($employeeId <= 0) {
    $errors['employee_id'] = 'Çalışan seçimi gereklidir';
}

if (empty($serviceIds) || !is_array($serviceIds)) {
    $errors['service_ids'] = 'En az bir hizmet seçilmelidir';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Doğrulama hatası', 'errors' => $errors]);
    exit;
}

try {
    // Randevunun varlığını kontrol et
    $checkStmt = $db->prepare("SELECT id, customer_id FROM appointments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL");
    $checkStmt->execute([$appointmentId, $tenantId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Randevu bulunamadı']);
        exit;
    }
    
    $db->beginTransaction();
    
    // Çalışanın var olduğunu kontrol et
    $empStmt = $db->prepare("SELECT id FROM employees WHERE id = ? AND tenant_id = ? AND status = 'active' AND deleted_at IS NULL");
    $empStmt->execute([$employeeId, $tenantId]);
    if (!$empStmt->fetch()) {
        throw new Exception('Geçersiz çalışan seçimi');
    }
    
    // Müşteri bilgilerini güncelleme — müşteri bilgileri yalnızca müşteri tarafından değiştirilebilir
    $customerId = $existing['customer_id'];
    
    // Hizmetleri getir ve toplamları hesapla
    $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $servicesStmt = $db->prepare("SELECT id, price, duration, pricing_type, price_min FROM services WHERE id IN ({$placeholders}) AND tenant_id = ? AND deleted_at IS NULL AND status = 'active'");
    $params = array_merge($serviceIds, [$tenantId]);
    $servicesStmt->execute($params);
    $services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    require_once __DIR__ . '/../../../config/service_qty.php';
    $svcQty = svcQtyResolve($db, (int)$tenantId, (array)$serviceIds, $data['service_quantities'] ?? null, svcQtyExisting($db, (int)$appointmentId));
    $totalPrice = 0;
    $totalDuration = 0;
    $servicePriceMap = [];
    $serviceDurationMap = [];
    $svcPricingType = [];
    $rangeSid = null;
    foreach ($services as $service) {
        $svcId = (int)$service['id'];
        $svcPricingType[$svcId] = $service['pricing_type'] ?? 'fixed';
        if (array_key_exists((string)$svcId, (array)$rangePrices) || array_key_exists($svcId, (array)$rangePrices)) {
            $basePrice = (float)($rangePrices[(string)$svcId] ?? $rangePrices[$svcId]);
        } elseif ($service['pricing_type'] === 'range' && $service['price_min'] !== null) {
            $basePrice = (float)$service['price_min'];
        } else {
            $basePrice = (float)$service['price'];
        }
        $baseDuration = (int)$service['duration'];
        $servicePriceMap[$svcId] = $basePrice;
        $serviceDurationMap[$svcId] = $baseDuration;
        if (($service['pricing_type'] ?? '') === 'range') {
            $rangeSid = $svcId;
        }
        
        // Varyant fiyat/süresini ekle
        $svcVariants = $variantData[(string)$svcId] ?? [];
        $variantExtraPrice = array_sum(array_column($svcVariants, 'extra_price'));
        $variantExtraDuration = array_sum(array_column($svcVariants, 'extra_duration'));
        
        $totalPrice += ($basePrice + (float)$variantExtraPrice) * ($svcQty[$svcId] ?? 1);
        $totalDuration += ($baseDuration + (int)$variantExtraDuration) * ($svcQty[$svcId] ?? 1);
    }
    if ($clientTotalPrice !== null && $clientTotalPrice >= 0) {
        $totalPrice = $clientTotalPrice;
    }
    
    // Randevuyu güncelle
    $sql = "UPDATE appointments SET 
        employee_id = ?, 
        appointment_date = ?, 
        appointment_time = ?,
        status = ?, 
        total_price = ?, 
        total_duration = ?, 
        notes = ?,
        is_edited = 1,
        updated_at = NOW()
        WHERE id = ? AND tenant_id = ?";
        // UPDATE appointments
        // This line is for context
        // It indicates the SQL command being executed
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $employeeId, $appointmentDate, $appointmentTime,
        $status, $totalPrice, $totalDuration, $notes,
        $appointmentId, $tenantId
    ]);
    
    // Mevcut varyant bilgilerini kaydet (fallback için — variant_data gelmezse eski kayıtları koru)
    $stmtOld = $db->prepare("SELECT service_id, variant_ids, final_price, final_duration, quantity FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
    $stmtOld->execute([$appointmentId, $appointmentId, $tenantId]);
    $oldDetails = [];
    foreach ($stmtOld->fetchAll(PDO::FETCH_ASSOC) as $od) {
        $oldDetails[(int)$od['service_id']] = $od;
    }

    // Eski hizmet ilişkilerini sil (tenant doğrulama ile)
    $deleteServices = $db->prepare("DELETE FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
    $deleteServices->execute([$appointmentId, $appointmentId, $tenantId]);
    
    // Yeni hizmet ilişkilerini ekle (varyant bilgileriyle)
    $serviceInsert = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, variant_ids, final_price, final_duration, quantity) VALUES (?, ?, ?, ?, ?, ?)");
    $serviceRows = [];
    $computedSum = 0;
    foreach ($serviceIds as $serviceId) {
        $sid = (int)$serviceId;
        $svcVariants = $variantData[(string)$sid] ?? [];
        $hasRangeOverride = array_key_exists((string)$sid, (array)$rangePrices) || array_key_exists($sid, (array)$rangePrices);
        if (!empty($svcVariants)) {
            // Yeni varyant seçimi gönderilmiş
            $variantIds = json_encode(array_values(array_column($svcVariants, 'id')));
            $variantExtraPrice = array_sum(array_column($svcVariants, 'extra_price'));
            $variantExtraDuration = array_sum(array_column($svcVariants, 'extra_duration'));
            $fp = (($servicePriceMap[$sid] ?? 0) + (float)$variantExtraPrice) * ($svcQty[$sid] ?? 1);
            $fd = (($serviceDurationMap[$sid] ?? 30) + (int)$variantExtraDuration) * ($svcQty[$sid] ?? 1);
        } elseif ($hasRangeOverride || ($svcPricingType[$sid] ?? '') === 'range') {
            // Aralıklı fiyat / range_prices öncelikli — eski final_price üzerine yazma
            $variantIds = isset($oldDetails[$sid]) ? $oldDetails[$sid]['variant_ids'] : null;
            $fp = isset($servicePriceMap[$sid]) ? $servicePriceMap[$sid] * ($svcQty[$sid] ?? 1) : null;
            $fd = isset($serviceDurationMap[$sid]) ? $serviceDurationMap[$sid] * ($svcQty[$sid] ?? 1) : null;
        } elseif (empty($variantData) && isset($oldDetails[$sid]) && (int)($oldDetails[$sid]['quantity'] ?? 1) === ($svcQty[$sid] ?? 1)) {
            // variant_data hiç gönderilmemişse eski kayıtları koru
            $variantIds = $oldDetails[$sid]['variant_ids'];
            $fp = $oldDetails[$sid]['final_price'];
            $fd = $oldDetails[$sid]['final_duration'];
        } else {
            // Varyant seçimi yok
            $variantIds = null;
            $fp = isset($servicePriceMap[$sid]) ? $servicePriceMap[$sid] * ($svcQty[$sid] ?? 1) : null;
            $fd = isset($serviceDurationMap[$sid]) ? $serviceDurationMap[$sid] * ($svcQty[$sid] ?? 1) : null;
        }
        $computedSum += (float)$fp;
        $serviceRows[] = [$appointmentId, $sid, $variantIds, $fp, $fd, $svcQty[$sid] ?? 1];
    }
    if ($rangeSid !== null && abs($computedSum - $totalPrice) > 0.009) {
        foreach ($serviceRows as &$row) {
            if ((int)$row[1] === $rangeSid) {
                $row[3] = max(0, (float)$row[3] + ($totalPrice - $computedSum));
            }
        }
        unset($row);
    }
    foreach ($serviceRows as $row) {
        $serviceInsert->execute($row);
    }
    
    $db->commit();
    
    // Çalışan adını al
    $empStmt = $db->prepare("SELECT name FROM employees WHERE id = ? AND tenant_id = ?");
    $empStmt->execute([$employeeId, TENANT_ID]);
    $employeeName = $empStmt->fetchColumn();
    
    // Hizmet adlarını al
    $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $serviceNamesStmt = $db->prepare("SELECT name FROM services WHERE id IN ({$placeholders}) AND tenant_id = ?");
    $serviceNamesStmt->execute(array_merge($serviceIds, [$tenantId]));
    $serviceNames = $serviceNamesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Güncellenen randevu verisi
    $appointmentData = [
        'id' => (int)$appointmentId,
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'employee_id' => (int)$employeeId,
        'employee_name' => $employeeName,
        'appointment_date' => $appointmentDate,
        'appointment_time' => $appointmentTime,
        'status' => $status,
        'total_price' => (float)$totalPrice,
        'service_name' => implode(', ', $serviceNames),
        'notes' => $notes
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Randevu başarıyla güncellendi',
        'appointment' => $appointmentData,
        'data' => ['id' => $appointmentId]
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => safeErrorMessage($e)]);
}
