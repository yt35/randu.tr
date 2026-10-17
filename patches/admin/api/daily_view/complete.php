<?php
/**
 * Daily View Complete Appointment API Endpoint
 * 
 * POST /api/daily_view/complete.php
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
    $paymentMethod = $input['payment_method'] ?? 'cash';
    $bankId = !empty($input['bank_id']) ? (int)$input['bank_id'] : null;
    $paidAmount = isset($input['paid_amount']) && $input['paid_amount'] !== '' ? (float)$input['paid_amount'] : null;
    $rangePrices = $input['range_prices'] ?? [];
    
    if ($appointmentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz randevu ID']);
        exit;
    }
    // En az bir hizmet zorunlu (geçerli pozitif ID'ler, tekrarsız)
    $validServiceIds = [];
    if (isset($input['service_ids']) && is_array($input['service_ids'])) {
        foreach ($input['service_ids'] as $rawSid) {
            $rawSid = (int)$rawSid;
            if ($rawSid > 0) $validServiceIds[$rawSid] = $rawSid;
        }
    }
    if (empty($validServiceIds)) {
        echo json_encode(['success' => false, 'message' => 'Lütfen en az bir hizmet seçin'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Ödeme yöntemi validasyonu
    if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'credit_card', 'mixed', 'not_paid'])) {
        $paymentMethod = 'cash';
    }
    
    // Randevunun var olduğunu kontrol et
    $stmt = $db->prepare("SELECT id, total_price FROM appointments WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL");
    $stmt->execute([$appointmentId, $tenantId]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        echo json_encode(['success' => false, 'message' => 'Randevu bulunamadı']);
        exit;
    }
    
    // Seçilen hizmetler bu işletmeye ait olmalı
    $svcCheckPh = implode(',', array_fill(0, count($validServiceIds), '?'));
    $svcCheck = $db->prepare("SELECT COUNT(*) FROM services WHERE id IN ($svcCheckPh) AND tenant_id = ?");
    $svcCheck->execute(array_merge(array_values($validServiceIds), [$tenantId]));
    if ((int)$svcCheck->fetchColumn() !== count($validServiceIds)) {
        echo json_encode(['success' => false, 'message' => 'Seçilen hizmetlerden biri geçersiz. Lütfen sayfayı yenileyip tekrar deneyin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Fiyatı al (gönderilmişse kullan, yoksa mevcut fiyatı koru). 0 geçerli bir tutardır (ücretsiz hizmet).
    $totalPrice = (isset($input['total_price']) && $input['total_price'] !== '') ? (float)$input['total_price'] : (float)$appointment['total_price'];
    
    // Kısmi ödeme kontrolü: paid_amount gönderilmişse ve total_price'dan küçükse partial
    $isPartial = ($paidAmount !== null && $paidAmount > 0 && $paidAmount < $totalPrice);
    if ($isPartial) {
        // Kısmi ödeme: ödeme türü sağlanan method, durum pending (hâlâ borçlu)
        $paymentStatus = 'pending';
    } else {
        $paymentStatus = ($paymentMethod === 'not_paid') ? 'pending' : 'paid';
        $paidAmount = null; // tam ödeme/sıfır - paid_amount NULL
    }
    
    $db->beginTransaction();
    
    // Randevuyu tamamla
    $stmt = $db->prepare("
        UPDATE appointments SET 
            status = 'completed', 
            payment_status = ?,
            total_price = ?,
            payment_method = ?,
            paid_amount = ?
        WHERE id = ? AND tenant_id = ?
    ");
    $stmt->execute([$paymentStatus, $totalPrice, $paymentMethod, $paidAmount, $appointmentId, $tenantId]);
    
    // Hizmetleri güncelle (doğrulanmış liste)
    $serviceIds = array_values($validServiceIds);
    $variantData = $input['variant_data'] ?? [];
    if (!empty($serviceIds) && is_array($serviceIds)) {
        // Hizmet temel fiyat/süre bilgilerini çek
        $svcPlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmtSvc = $db->prepare("SELECT id, price, duration, pricing_type, price_min FROM services WHERE id IN ($svcPlaceholders) AND tenant_id = ?");
        $stmtSvc->execute(array_merge(array_map('intval', $serviceIds), [$tenantId]));
        $svcMap = [];
        foreach ($stmtSvc->fetchAll(PDO::FETCH_ASSOC) as $sv) {
            $svcMap[(int)$sv['id']] = $sv;
        }
        
        require_once __DIR__ . '/../../../config/service_qty.php';
        $svcQty = svcQtyResolve($db, (int)$tenantId, $serviceIds, $input['service_quantities'] ?? null, svcQtyExisting($db, (int)$appointmentId));

        $stmtOld = $db->prepare("SELECT service_id, final_price, quantity, variant_ids FROM appointment_services WHERE appointment_id = ?");
        $stmtOld->execute([$appointmentId]);
        $oldSvc = [];
        foreach ($stmtOld->fetchAll(PDO::FETCH_ASSOC) as $od) {
            $oldSvc[(int)$od['service_id']] = $od;
        }

        // Önce mevcut hizmetleri sil
        $stmtDel = $db->prepare("DELETE FROM appointment_services WHERE appointment_id = ? AND EXISTS (SELECT 1 FROM appointments WHERE id = ? AND tenant_id = ?)");
        $stmtDel->execute([$appointmentId, $appointmentId, $tenantId]);
        
        // Yeni hizmetleri ekle (range_prices / eski final_price koruması)
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
    $productConsumption = $input['product_consumption'] ?? [];
    if (!empty($productConsumption) && is_array($productConsumption)) {
        saveAppointmentProducts($db, $appointmentId, $tenantId, $productConsumption, $_SESSION['user_id'] ?? null);
    }
    
    $db->commit();
    
    // Stok düşümü (hizmete bağlı ürünler)
    deductStockForAppointment($db, $appointmentId, $tenantId, $_SESSION['user_id'] ?? null);
    
    // Güncel randevuyu getir
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
    $updatedAppointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Format ekle
    $updatedAppointment['appointment_time_formatted'] = date('H:i', strtotime($updatedAppointment['appointment_time']));
    $updatedAppointment['total_price_formatted'] = number_format((float)$updatedAppointment['total_price'], 2, ',', '.') . ' ₺';

    echo json_encode([
        'success' => true,
        'message' => 'Randevu tamamlandı',
        'appointment' => $updatedAppointment
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
