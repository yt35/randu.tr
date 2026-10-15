<?php
/**
 * Appointments Create API Endpoint
 * 
 * POST /api/appointments/create.php
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

// Helper fonksiyonlar
function formatPhoneNum($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    // Başındaki 0'ı kaldır (0532 → 532) — formatPhoneNumber ile tutarlı format
    if (strlen($phone) == 11 && substr($phone, 0, 1) == '0') {
        $phone = substr($phone, 1);
    }
    if (strlen($phone) == 12 && substr($phone, 0, 2) == '90') {
        $phone = substr($phone, 2);
    }
    return $phone;
}

// Validasyon
$errors = [];

$customerName = sanitizeName($data['customer_name'] ?? '', 50);
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

if (empty($appointmentDate)) {
    $errors['appointment_date'] = 'Tarih gereklidir';
}

if (empty($appointmentTime)) {
    $errors['appointment_time'] = 'Saat gereklidir';
}

// Tekrarlayan randevu verileri
$isRecurring = intval($data['is_recurring'] ?? 0);
$recurringType = trim($data['recurring_type'] ?? '');
$recurringEndDate = trim($data['recurring_end_date'] ?? '');
$recurringDaysInput = $data['recurring_days'] ?? [];

if ($isRecurring) {
    $allowedTypes = ['daily', 'weekly', 'biweekly', 'monthly', 'custom'];
    if (!in_array($recurringType, $allowedTypes)) {
        $errors['recurring_type'] = 'Geçersiz tekrar tipi';
    }
    if (empty($recurringEndDate)) {
        $errors['recurring_end_date'] = 'Bitiş tarihi gereklidir';
    }
    if ($recurringType === 'custom') {
        if (!is_array($recurringDaysInput) || empty($recurringDaysInput)) {
            $errors['recurring_days'] = 'En az bir gün seçilmelidir';
        }
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Doğrulama hatası', 'errors' => $errors]);
    exit;
}

// Geçmiş tarih uyarısı (kullanıcı confirm_past_date=1 göndermediyse uyar)
$confirmPastDate = !empty($data['confirm_past_date']);
if (!$isRecurring && !$confirmPastDate
    && !empty($appointmentDate)
    && strtotime($appointmentDate) < strtotime(date('Y-m-d'))
) {
    echo json_encode([
        'success' => false,
        'past_date_warning' => true,
        'message' => 'Randevu eklemek istediğiniz tarih geçmiş bir tarih. Yine de eklemek istiyor musunuz?',
        'appointment_date' => $appointmentDate
    ]);
    exit;
}

try {
    $db->beginTransaction();
    
    // Çalışanın var olduğunu kontrol et
    $empStmt = $db->prepare("SELECT id, name FROM employees WHERE id = ? AND tenant_id = ? AND status = 'active' AND deleted_at IS NULL");
    $empStmt->execute([$employeeId, $tenantId]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$employee) {
        throw new Exception('Geçersiz çalışan seçimi');
    }
    
    // Müşteri bul veya oluştur (global arama — tenant kısıtlaması olmadan)
    $custStmt = $db->prepare("SELECT id FROM customers WHERE phone = ?");
    $custStmt->execute([$customerPhone]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($customer) {
        $customerId = $customer['id'];
        $linkStmt = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), deleted_at = NULL");
        $linkStmt->execute([$customerId, $tenantId, $customerName]);
    } else {
        $createCust = $db->prepare("INSERT INTO customers (tenant_id, name, phone, is_guest, created_at) VALUES (NULL, ?, ?, 1, NOW())");
        $createCust->execute([$customerName, $customerPhone]);
        $customerId = $db->lastInsertId();
        $linkStmt = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name) VALUES (?, ?, ?)");
        $linkStmt->execute([$customerId, $tenantId, $customerName]);
    }
    
    // Hizmetleri getir ve toplamları hesapla
    $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
    $servicesStmt = $db->prepare("SELECT id, price, duration, pricing_type, price_min FROM services WHERE id IN ({$placeholders}) AND tenant_id = ? AND deleted_at IS NULL AND status = 'active'");
    $params = array_merge($serviceIds, [$tenantId]);
    $servicesStmt->execute($params);
    $services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($services) !== count($serviceIds)) {
        throw new Exception('Bazı hizmetler bulunamadı');
    }
    
    require_once __DIR__ . '/../../../config/service_qty.php';
    $svcQty = svcQtyResolve($db, (int)$tenantId, (array)$serviceIds, $data['service_quantities'] ?? null);
    $totalPrice = 0;
    $totalDuration = 0;
    $servicePriceMap = [];
    $serviceDurationMap = [];
    foreach ($services as $service) {
        $svcId = (int)$service['id'];
        // Aralıklı hizmetlerde istemcinin girdiği fiyatı kullan
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
        
        // Varyant fiyat/süresini ekle
        $svcVariants = $variantData[(string)$svcId] ?? [];
        $variantExtraPrice = array_sum(array_column($svcVariants, 'extra_price'));
        $variantExtraDuration = array_sum(array_column($svcVariants, 'extra_duration'));
        
        $totalPrice += ($basePrice + (float)$variantExtraPrice) * ($svcQty[$svcId] ?? 1);
        $totalDuration += ($baseDuration + (int)$variantExtraDuration) * ($svcQty[$svcId] ?? 1);
    }
    // İstemci toplam gönderdiyse onu esas al (kasa / personel performansı ile senkron)
    if ($clientTotalPrice !== null && $clientTotalPrice >= 0) {
        $totalPrice = $clientTotalPrice;
    }
    
    // ── Tekrarlayan randevu tarihleri hesapla ──
    $appointmentDates = [$appointmentDate]; // Tekil ise sadece bu tarih
    $recurringGroupId = null;
    $recurringDaysStr = null;
    
    if ($isRecurring) {
        $recurringGroupId = bin2hex(random_bytes(16)); // UUID benzeri grup ID
        
        $start = new DateTime($appointmentDate);
        $end = new DateTime($recurringEndDate);
        
        if ($start > $end) {
            throw new Exception('Bitiş tarihi başlangıç tarihinden önce olamaz.');
        }
        
        $appointmentDates = [];
        
        if ($recurringType === 'daily') {
            $current = clone $start;
            while ($current <= $end) {
                $appointmentDates[] = $current->format('Y-m-d');
                $current->modify('+1 day');
            }
        } elseif ($recurringType === 'weekly') {
            $current = clone $start;
            while ($current <= $end) {
                $appointmentDates[] = $current->format('Y-m-d');
                $current->modify('+7 days');
            }
        } elseif ($recurringType === 'biweekly') {
            $current = clone $start;
            while ($current <= $end) {
                $appointmentDates[] = $current->format('Y-m-d');
                $current->modify('+14 days');
            }
        } elseif ($recurringType === 'monthly') {
            $current = clone $start;
            while ($current <= $end) {
                $appointmentDates[] = $current->format('Y-m-d');
                $current->modify('+1 month');
            }
        } elseif ($recurringType === 'custom') {
            $selectedDays = array_map('intval', $recurringDaysInput);
            $selectedDays = array_filter($selectedDays, function($d) { return $d >= 1 && $d <= 7; });
            sort($selectedDays);
            $recurringDaysStr = implode(',', $selectedDays);
            
            if (empty($selectedDays)) {
                throw new Exception('En az bir gün seçilmelidir.');
            }
            
            $current = clone $start;
            while ($current <= $end) {
                $isoDay = (int)$current->format('N'); // 1=Pzt, 7=Paz
                if (in_array($isoDay, $selectedDays)) {
                    $appointmentDates[] = $current->format('Y-m-d');
                }
                $current->modify('+1 day');
            }
        }
        
        if (empty($appointmentDates)) {
            throw new Exception('Seçilen kriterlere uygun tarih bulunamadı.');
        }
        if (count($appointmentDates) > 180) {
            throw new Exception('En fazla 180 tekrarlayan randevu oluşturulabilir.');
        }
    }
    
    // ── Çakışma kontrolü ──
    $conflictDates = [];
    $conflictDetails = []; // İlk çakışmanın ayrıntısı (kullanıcıya net mesaj için)
    $conflictCheckStmt = $db->prepare("
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

    foreach ($appointmentDates as $dateStr) {
        $conflictCheckStmt->execute([
            $employeeId, $dateStr, $tenantId,
            $appointmentTime, $totalDuration, $appointmentTime
        ]);
        $row = $conflictCheckStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $conflictDates[] = $dateStr;
            if (empty($conflictDetails)) {
                $conflictDetails = [
                    'date' => $dateStr,
                    'time' => substr($row['appointment_time'], 0, 5),
                    'end_time' => substr($row['end_time'], 0, 5),
                    'customer_name' => $row['customer_name'],
                    'employee_name' => $employee['name']
                ];
            }
        }
    }

    // Çakışan tarihleri çıkar (uyarı olarak bildir)
    $skippedCount = count($conflictDates);
    $forceBetween = !empty($input['confirm_between']) || !empty($input['is_between']) || !empty($input['allow_between']);
    $insertAsBetween = 0;

    if ($skippedCount > 0) {
        if (!$isRecurring && empty(array_values(array_diff($appointmentDates, $conflictDates)))) {
            $cd = $conflictDetails;
            $existingStart = substr((string)($cd['time'] ?? ''), 0, 5);
            $newStart = substr((string)$appointmentTime, 0, 5);

            // Aynı başlangıç: onay iste
            if ($existingStart !== '' && $existingStart === $newStart && !$forceBetween) {
                $db->rollBack();
                echo json_encode([
                    'success' => false,
                    'conflict' => true,
                    'needs_confirm' => true,
                    'conflict_type' => 'exact_start',
                    'message' => sprintf(
                        'Bu saatte bir randevu var: %s tarihinde %s–%s (%s). Araya eklensin mi?',
                        date('d.m.Y', strtotime($cd['date'])),
                        $cd['time'],
                        $cd['end_time'],
                        $cd['customer_name']
                    ),
                    'conflict_details' => $cd
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Ara saat veya onaylandı → araya ekle, tarihi geri koy
            $appointmentDates = [$conflictDates[0]];
            $insertAsBetween = 1;
            $skippedCount = 0;
        } else {
            $appointmentDates = array_values(array_diff($appointmentDates, $conflictDates));
        }
    }

    if (empty($appointmentDates)) {
        $db->rollBack();
        $cd = $conflictDetails;
        if ($isRecurring) {
            $msg = sprintf(
                '%s adlı çalışanın takviminde tüm tekrar tarihleri (%d tarih) için çakışma var. Örn: %s tarihinde %s–%s arası "%s" adlı müşterinin randevusu mevcut.',
                $cd['employee_name'] ?? '',
                $skippedCount,
                date('d.m.Y', strtotime($cd['date'])),
                $cd['time'],
                $cd['end_time'],
                $cd['customer_name']
            );
        } else {
            $msg = sprintf(
                '⛔ Bu saatte randevu mevcut: %s tarihinde %s ile %s arası %s adlı çalışanın "%s" adlı müşteri için randevusu var. Lütfen farklı bir saat seçin.',
                date('d.m.Y', strtotime($cd['date'])),
                $cd['time'],
                $cd['end_time'],
                $cd['employee_name'] ?? '',
                $cd['customer_name']
            );
        }
        echo json_encode([
            'success' => false,
            'conflict' => true,
            'message' => $msg,
            'conflict_details' => $cd
        ]);
        exit;
    }
    
    // ── Randevuları oluştur ──
    $insertSql = "INSERT INTO appointments (
        tenant_id, customer_id, employee_id, appointment_date, appointment_time,
        status, total_price, total_duration, notes, verified, is_between,
        is_recurring, recurring_type, recurring_days, recurring_end_date, recurring_group_id,
        created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, NOW())";
    
    $insertStmt = $db->prepare($insertSql);
    $serviceInsert = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id, tenant_id, variant_ids, final_price, final_duration, quantity) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    $createdIds = [];
    foreach ($appointmentDates as $dateStr) {
        $insertStmt->execute([
            $tenantId, $customerId, $employeeId, $dateStr, $appointmentTime,
            $status, $totalPrice, $totalDuration, $notes,
            $insertAsBetween,
            $isRecurring ? 1 : 0,
            $isRecurring ? $recurringType : null,
            $recurringDaysStr,
            $isRecurring ? $recurringEndDate : null,
            $recurringGroupId
        ]);
        $newId = $db->lastInsertId();
        $createdIds[] = $newId;
        
        foreach ($serviceIds as $serviceId) {
            $sid = (int)$serviceId;
            $svcVariants = $variantData[(string)$sid] ?? [];
            $variantIds = !empty($svcVariants) ? json_encode(array_values(array_column($svcVariants, 'id'))) : null;
            $variantExtraPrice = array_sum(array_column($svcVariants, 'extra_price'));
            $variantExtraDuration = array_sum(array_column($svcVariants, 'extra_duration'));
            $fp = ($servicePriceMap[$sid] ?? 0) + (float)$variantExtraPrice;
            $fd = ($serviceDurationMap[$sid] ?? 30) + (int)$variantExtraDuration;
            $q = $svcQty[$sid] ?? 1;
            $serviceInsert->execute([$newId, $serviceId, $tenantId, $variantIds, $fp * $q, $fd * $q, $q]);
        }
    }
    
    $db->commit();
    
    // Hizmet adlarını al
    $serviceNamesStmt = $db->prepare("SELECT name FROM services WHERE id IN ({$placeholders}) AND tenant_id = ?");
    $serviceNamesStmt->execute(array_merge($serviceIds, [$tenantId]));
    $serviceNames = $serviceNamesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Yanıt - ilk randevunun verisi
    $firstId = $createdIds[0];
    $appointmentData = [
        'id' => (int)$firstId,
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'employee_id' => (int)$employeeId,
        'employee_name' => $employee['name'],
        'appointment_date' => $appointmentDates[0],
        'appointment_time' => $appointmentTime,
        'status' => $status,
        'total_price' => (float)$totalPrice,
        'service_name' => implode(', ', $serviceNames),
        'notes' => $notes
    ];
    
    // Mesaj oluştur
    $totalCreated = count($createdIds);
    if ($isRecurring && $totalCreated > 1) {
        $message = $totalCreated . ' tekrarlayan randevu başarıyla oluşturuldu.';
        if ($skippedCount > 0) {
            $message .= ' (' . $skippedCount . ' tarih çakışma nedeniyle atlandı)';
        }
    } else {
        $message = 'Randevu başarıyla oluşturuldu';
    }

    // İşletme bildirimi (SMS/Telegram) sadece müşteri web'den randevu alınca gider.
    // Admin/randevular sayfasından eklerken bildirim gönderilmez.

    echo json_encode([
        'success' => true,
        'message' => $message,
        'appointment' => $appointmentData,
        'data' => ['id' => $firstId],
        'recurring_count' => $totalCreated,
        'skipped_count' => $skippedCount
    ]);
    
} catch (PDOException $e) {
    $db->rollBack();
    error_log('[appointments_create PDO] ' . $e->getMessage());
    http_response_code(500);
    // Veritabanı hatasının teknik detayını kullanıcıya gösterme
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası oluştu. Lütfen tekrar deneyin.']);
} catch (Exception $e) {
    $db->rollBack();
    // Kendi attığımız Exception'ların mesajları zaten kullanıcı dostu — direkt göster
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
