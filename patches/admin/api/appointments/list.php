<?php
/**
 * Appointments List API Endpoint
 * 
 * GET /api/appointments/list.php
 * 
 * Query Parameters:
 * - date_from: Başlangıç tarihi (Y-m-d)
 * - date_to: Bitiş tarihi (Y-m-d)
 * - employee_id: Çalışan filtresi
 * - status: Durum filtresi (confirmed, completed, cancelled, pending)
 * - customer_id: Müşteri filtresi
 * - page: Sayfa numarası (default: 1)
 * - per_page: Sayfa başına kayıt (default: 20, max: 100)
 * 
 * @package API/Appointments
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=UTF-8');

// Mevcut config sistemini kullan
$config_path = __DIR__ . '/../../../config/config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Config dosyası bulunamadı: ' . $config_path]);
    exit;
}
require_once $config_path;

// Sadece GET kabul et
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Sadece GET metodu kabul edilir']);
    exit;
}

// Oturum kontrolü
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Oturum açmanız gerekiyor']);
    exit;
}

if (function_exists('checkSessionTimeout') && !checkSessionTimeout()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Oturum süresi dolmuş']);
    exit;
}

// Sayfa erişim kontrolü
if (function_exists('can_access_page') && !can_access_page('appointments.php')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Bu sayfaya erişim yetkiniz yok']);
    exit;
}

// Tenant ID
if (!defined('TENANT_ID')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tenant bilgisi bulunamadı']);
    exit;
}
$tenantId = TENANT_ID;

// Filtre parametreleri
$showAll = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$dateFrom = $showAll ? '' : ($_GET['date_from'] ?? date('Y-m-d'));
$dateTo = $showAll ? '' : ($_GET['date_to'] ?? date('Y-m-d'));
$employeeId = filter_var($_GET['employee_id'] ?? $_GET['employee'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$status = $_GET['status'] ?? '';
$customerId = filter_var($_GET['customer_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$customerSearch = trim($_GET['customer'] ?? ''); // Müşteri adı/telefon araması
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
// show_all modunda per_page limitini genişlet (Tümünü Gör için)
$perPageCap = $showAll ? 10000 : 100;
$perPage = min($perPageCap, max(1, filter_var($_GET['per_page'] ?? 25, FILTER_VALIDATE_INT) ?: 25));

// Sıralama
$sortColumn = $_GET['sort'] ?? 'date';
$sortOrder = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$sortMap = [
    'date' => 'a.appointment_date ' . $sortOrder . ', a.appointment_time ' . $sortOrder,
    'customer' => 'COALESCE(ct.name, c.name) ' . $sortOrder,
    'employee' => 'e.name ' . $sortOrder,
    'status' => 'a.status ' . $sortOrder,
    'price' => 'a.total_price ' . $sortOrder
];
$orderBy = $sortMap[$sortColumn] ?? $sortMap['date'];

try {
    // WHERE koşullarını oluştur
    $where = ['a.tenant_id = ?', 'a.deleted_at IS NULL'];
    $params = [$tenantId];
    
    // Tarih filtresi
    if (!empty($dateFrom)) {
        $where[] = 'a.appointment_date >= ?';
        $params[] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $where[] = 'a.appointment_date <= ?';
        $params[] = $dateTo;
    }
    
    // Çalışan filtresi
    if ($employeeId > 0) {
        $where[] = 'a.employee_id = ?';
        $params[] = $employeeId;
    }
    
    // Durum filtresi
    $validStatuses = ['confirmed', 'completed', 'cancelled', 'pending'];
    if (!empty($status) && in_array($status, $validStatuses)) {
        $where[] = 'a.status = ?';
        $params[] = $status;
    }
    
    // Müşteri filtresi (ID ile)
    if ($customerId > 0) {
        $where[] = 'a.customer_id = ?';
        $params[] = $customerId;
    }
    
    // Müşteri arama (ad veya telefon) — boş telefon deseni tüm kayıtları getirmesin
    if ($customerSearch !== '') {
        $tokens = preg_split('/\s+/u', $customerSearch, -1, PREG_SPLIT_NO_EMPTY) ?: [$customerSearch];
        $nameParts = [];
        foreach ($tokens as $token) {
            $nameParts[] = 'COALESCE(ct.name, c.name) LIKE ?';
            $params[] = '%' . $token . '%';
        }
        $nameSql = '(' . implode(' AND ', $nameParts) . ')';
        $phoneDigits = preg_replace('/[^0-9]/', '', $customerSearch);
        if ($phoneDigits !== '' && strlen($phoneDigits) >= 3) {
            $where[] = '(' . $nameSql . ' OR c.phone LIKE ?)';
            $params[] = '%' . $phoneDigits . '%';
        } else {
            $where[] = $nameSql;
        }
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Toplam kayıt sayısı
    $countSql = "SELECT COUNT(DISTINCT a.id) FROM appointments a
                 LEFT JOIN customers c ON a.customer_id = c.id
                 LEFT JOIN customer_tenants ct ON c.id = ct.customer_id AND ct.tenant_id = a.tenant_id
                 WHERE {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalRecords = (int) $countStmt->fetchColumn();
    
    // İstatistikler (durum bazlı)
    $statsSql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN a.status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
            SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM appointments a
        LEFT JOIN customers c ON a.customer_id = c.id
        LEFT JOIN customer_tenants ct ON c.id = ct.customer_id AND ct.tenant_id = a.tenant_id
        WHERE {$whereClause}
    ";
    $statsStmt = $db->prepare($statsSql);
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Offset hesapla
    $offset = ($page - 1) * $perPage;
    
    // Randevuları getir
    $sql = "
        SELECT
            a.id, a.customer_id, a.employee_id, a.appointment_date, a.appointment_time,
            a.status, a.total_price, a.total_duration, a.payment_method, a.payment_status,
            a.notes, a.is_between, a.is_edited,
            COALESCE(ct.name, c.name) AS customer_name,
            c.phone AS customer_phone,
            e.name AS employee_name,
            GROUP_CONCAT(s.name SEPARATOR ', ') AS service_names,
            GROUP_CONCAT(s.id SEPARATOR ',') AS service_ids
        FROM appointments a
        LEFT JOIN customers c ON a.customer_id = c.id
        LEFT JOIN customer_tenants ct ON c.id = ct.customer_id AND ct.tenant_id = a.tenant_id
        LEFT JOIN employees e ON a.employee_id = e.id
        LEFT JOIN appointment_services a_s ON a.id = a_s.appointment_id
        LEFT JOIN services s ON a_s.service_id = s.id
        WHERE {$whereClause}
        GROUP BY a.id
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Veriyi formatla
    foreach ($appointments as &$appointment) {
        // Tarih/saat formatla
        $appointment['appointment_date_formatted'] = date('d.m.Y', strtotime($appointment['appointment_date']));
        $appointment['appointment_time_formatted'] = date('H:i', strtotime($appointment['appointment_time']));
        
        // Fiyat formatla
        $appointment['total_price_formatted'] = number_format((float)$appointment['total_price'], 2, ',', '.') . ' ₺';
        
        // Durum etiketi
        $statusLabels = [
            'confirmed' => 'Onaylı',
            'completed' => 'Tamamlandı',
            'cancelled' => 'İptal',
            'pending' => 'Bekliyor'
        ];
        $appointment['status_label'] = $statusLabels[$appointment['status']] ?? $appointment['status'];
        
        // service_name alanı (JS uyumu için)
        $appointment['service_name'] = $appointment['service_names'] ?? '';

        if (function_exists('decodeNotesEntities') && isset($appointment['notes'])) {
            $appointment['notes'] = decodeNotesEntities($appointment['notes']);
        }
        
        // Hizmetleri dizi olarak da ver
        $appointment['services'] = [];
        if (!empty($appointment['service_ids'])) {
            $serviceIds = explode(',', $appointment['service_ids']);
            $serviceNames = explode(', ', $appointment['service_names']);
            for ($i = 0; $i < count($serviceIds); $i++) {
                $appointment['services'][] = [
                    'id' => (int)$serviceIds[$i],
                    'name' => $serviceNames[$i] ?? ''
                ];
            }
        }
    }
    
    // Pagination hesapla
    $totalPages = $totalRecords > 0 ? ceil($totalRecords / $perPage) : 0;
    
    echo json_encode([
        'success' => true,
        'message' => 'Randevular başarıyla getirildi',
        'data' => $appointments,
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
            'pending' => (int)($stats['pending'] ?? 0),
            'confirmed' => (int)($stats['confirmed'] ?? 0),
            'completed' => (int)($stats['completed'] ?? 0),
            'cancelled' => (int)($stats['cancelled'] ?? 0)
        ],
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages
        ],
        'filters' => [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'employee_id' => $employeeId,
            'status' => $status,
            'customer' => $customerSearch,
            'show_all' => $showAll
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası: ' . safeErrorMessage($e)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Randevular getirilirken bir hata oluştu: ' . safeErrorMessage($e)]);
}
