<?php
/**
 * Appointments Complete API Endpoint
 * 
 * POST /api/appointments/complete.php
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

// Sadece POST kabul et
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

// Validasyon
$appointmentId = filter_var($data['appointment_id'] ?? $data['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$paymentMethod = trim($data['payment_method'] ?? 'cash');
$variantData = $data['variant_data'] ?? [];
$rangePrices = $data['range_prices'] ?? [];
$paidAmount = isset($data['paid_amount']) && $data['paid_amount'] !== '' ? (float)$data['paid_amount'] : null;

if ($appointmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Randevu ID gereklidir']);
    exit;
}

// En az bir hizmet zorunlu (geçerli pozitif ID'ler, tekrarsız)
$validServiceIds = [];
if (isset($data['service_ids']) && is_array($data['service_ids'])) {
    foreach ($data['service_ids'] as $rawSid) {
        $rawSid = (int)$rawSid;
        if ($rawSid > 0) $validServiceIds[$rawSid] = $rawSid;
    }
}
if (empty($validServiceIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Lütfen en az bir hizmet seçin'], JSON_UNESCAPED_UNICODE);
    exit;
}

$validPaymentMethods = ['cash', 'bank_transfer'];
$validPaymentMethods = ['cash', 'bank_transfer', 'credit_card', 'not_paid', 'mixed'];
if (!in_array($paymentMethod, $validPaymentMethods, true)) {
    $paymentMethod = 'cash';
}

try {
    // Randevunun varlığını kontrol et
    $checkStmt = $db->prepare("SELECT id, status, total_price FROM appointments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL");
    $checkStmt->execute([$appointmentId, $tenantId]);
    $appointment = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Randevu bulunamadı']);
        exit;
    }
    
    if ($appointment['status'] === 'completed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Bu randevu zaten tamamlanmış']);
        exit;
    }
    
    if ($appointment['status'] === 'cancelled') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'İptal edilmiş randevu tamamlanamaz']);
        exit;
    }
    
    // Seçilen hizmetler bu işletmeye ait olmalı
    $svcCheckPh = implode(',', array_fill(0, count($validServiceIds), '?'));
    $svcCheck = $db->prepare("SELECT COUNT(*) FROM services WHERE id IN ($svcCheckPh) AND tenant_id = ?");
    $svcCheck->execute(array_merge(array_values($validServiceIds), [$tenantId]));
    if ((int)$svcCheck->fetchColumn() !== count($validServiceIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Seçilen hizmetlerden biri geçersiz. Lütfen sayfayı yenileyip tekrar deneyin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Fiyatı al (gönderilmişse kullan, yoksa mevcut fiyatı koru)
    // 0 geçerli bir tutardır (ücretsiz hizmet); yalnızca gönderilmemiş/boş ise mevcut fiyat korunur
    $totalPrice = (isset($data['total_price']) && $data['total_price'] !== '') ? (float)$data['total_price'] : (float)$appointment['total_price'];
    
    $db->beginTransaction();
    
    // Randevuyu tamamla
    $isPartial = ($paidAmount !== null && $paidAmount > 0 && $paidAmount < $totalPrice);
    if ($isPartial) {
        $paymentStatus = 'pending';
    } else {
        $paymentStatus = ($paymentMethod === 'not_paid') ? 'pending' : 'paid';
        $paidAmount = null;
    }
    $updateStmt = $db->prepare("
        UPDATE appointments 
        SET status = 'completed',
            payment_method = ?,
            payment_status = ?,
            total_price = ?,
            paid_amount = ?,
            updated_at = NOW()
        WHERE id = ? AND tenant_id = ?
    ");
    $updateStmt->execute([$paymentMethod, $paymentStatus, $totalPrice, $paidAmount, $appointmentId, $tenantId]);
    
    // Hizmetleri güncelle (doğrulanmış liste)
    $serviceIds = array_values($validServiceIds);
    if (!empty($serviceIds) && is_array($serviceIds)) {
        // Hizmet temel fiyat/süre bilgilerini çek
        $svcPh = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmtSvc = $db->prepare("SELECT id, price, duration, pricing_type, price_min FROM services WHERE id IN ($svcPh) AND tenant_id = ?");
        $stmtSvc->execute(array_merge(array_map('intval', $serviceIds), [$tenantId]));
        $svcMap = [];
        foreach ($stmtSvc->fetchAll(PDO::FETCH_ASSOC) as $sv) {
            $svcMap[(int)$sv['id']] = $sv;
        }
        
        require_once __DIR__ . '/../../../config/service_qty.php';
        $svcQty = svcQtyResolve($db, (int)$tenantId, $serviceIds, $data['service_quantities'] ?? null, svcQtyExisting($db, (int)$appointmentId));

        // Eski final_price'ları sakla (aralıklı fiyat kaybolmasın)
        $stmtOld = $db->prepare("SELECT service_id, final_price, final_duration, quantity, variant_ids FROM appointment_services WHERE appointment_id = ?");
        $stmtOld->execute([$appointmentId]);
        $oldSvc = [];
        foreach ($stmtOld->fetchAll(PDO::FETCH_ASSOC) as $od) {
            $oldSvc[(int)$od['service_id']] = $od;
        }

        // Önce mevcut hizmetleri sil
        $stmtDel = $db->prepare("DELETE FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
        $stmtDel->execute([$appointmentId, $appointmentId, $tenantId]);
        
        // Yeni hizmetleri ekle (range_prices / eski final_price koruması ile)
        $stmtIns = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, variant_ids, final_price, final_duration, quantity) VALUES (?, ?, ?, ?, ?, ?)");
        $computedSum = 0;
        $rangeSid = null;
        $serviceRows = [];
        foreach ($serviceIds as $serviceId) {
            $sid = (int)$serviceId;
            $svcVariants = $variantData[(string)$serviceId] ?? $variantData[(string)$sid] ?? [];
            $variantIds = !empty($svcVariants) ? json_encode(array_values(array_column($svcVariants, 'id'))) : ($oldSvc[$sid]['variant_ids'] ?? null);
            $variantExtraPrice = array_sum(array_column($svcVariants, 'extra_price'));
            $variantExtraDuration = array_sum(array_column($svcVariants, 'extra_duration'));
            $q = $svcQty[$sid] ?? 1;

            if (array_key_exists((string)$sid, (array)$rangePrices) || array_key_exists($sid, (array)$rangePrices)) {
                $basePrice = (float)($rangePrices[(string)$sid] ?? $rangePrices[$sid]);
            } elseif (($svcMap[$sid]['pricing_type'] ?? '') === 'range' && isset($oldSvc[$sid]['final_price']) && $oldSvc[$sid]['final_price'] !== null) {
                $basePrice = (float)$oldSvc[$sid]['final_price'] / max(1, (int)($oldSvc[$sid]['quantity'] ?? 1));
                if (!empty($svcVariants)) {
                    $basePrice = max(0, $basePrice - (float)$variantExtraPrice);
                }
            } elseif (($svcMap[$sid]['pricing_type'] ?? '') === 'range' && $svcMap[$sid]['price_min'] !== null) {
                $basePrice = (float)$svcMap[$sid]['price_min'];
            } else {
                $basePrice = (float)($svcMap[$sid]['price'] ?? 0);
            }

            $baseDuration = (int)($svcMap[$sid]['duration'] ?? 30);
            $fp = ($basePrice + (float)$variantExtraPrice) * $q;
            $fd = ($baseDuration + (int)$variantExtraDuration) * $q;
            if (($svcMap[$sid]['pricing_type'] ?? '') === 'range') {
                $rangeSid = $sid;
            }
            $computedSum += $fp;
            $serviceRows[] = [$appointmentId, $sid, $variantIds, $fp, $fd, $q];
        }

        // Tek aralıklı hizmet varsa toplam farkı ona yaz (kasa tutarı senkron)
        if ($rangeSid !== null && abs($computedSum - $totalPrice) > 0.009) {
            foreach ($serviceRows as &$row) {
                if ((int)$row[1] === $rangeSid) {
                    $row[3] = max(0, (float)$row[3] + ($totalPrice - $computedSum));
                }
            }
            unset($row);
        }

        foreach ($serviceRows as $row) {
            $stmtIns->execute($row);
        }
    }
    
    // Ürün tüketim verilerini kaydet (varsa)
    $productConsumption = $data['product_consumption'] ?? [];
    if (!empty($productConsumption) && is_array($productConsumption)) {
        saveAppointmentProducts($db, $appointmentId, $tenantId, $productConsumption, $_SESSION['user_id'] ?? null);
    }
    
    $db->commit();
    
    // Stok düşümü (hizmete bağlı ürünler)
    deductStockForAppointment($db, $appointmentId, $tenantId, $_SESSION['user_id'] ?? null);

    echo json_encode([
        'success' => true,
        'message' => 'Randevu tamamlandı',
        'data' => ['id' => $appointmentId]
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Randevu tamamlanırken bir hata oluştu']);
}
