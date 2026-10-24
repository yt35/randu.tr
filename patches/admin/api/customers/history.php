<?php
/**
 * Customer Appointment History API
 * GET /api/customers/history.php?customer_id=123&page=1&limit=20
 */

require_once __DIR__ . '/../../../config/config.php';

header('Content-Type: application/json; charset=utf-8');

// Auth kontrolü
if (!isLoggedIn() || !checkSessionTimeout()) {
    echo json_encode(['success' => false, 'message' => 'Oturum geçersiz']);
    exit;
}

if (!defined('TENANT_ID') || TENANT_ID === null) {
    echo json_encode(['success' => false, 'message' => 'İşletme kimliği doğrulanamadı']);
    exit;
}

try {
    $customer_id = intval($_GET['customer_id'] ?? $_GET['id'] ?? 0);
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 50)));
    $status = $_GET['status'] ?? '';
    $year = intval($_GET['year'] ?? 0);
    $month = intval($_GET['month'] ?? 0);

    if ($customer_id <= 0) {
        throw new Exception('Müşteri ID geçersiz.');
    }

    // Müşterinin bu tenant'a ait olduğunu doğrula + tenant adı
    $stmt = $db->prepare("
        SELECT c.id, COALESCE(ct.name, c.name) as name, c.phone, c.email, c.birth_date, c.gender, ct.notes, c.created_at
        FROM customers c
        INNER JOIN customer_tenants ct ON c.id = ct.customer_id
        WHERE c.id = ? AND ct.tenant_id = ? AND ct.deleted_at IS NULL
    ");
    $stmt->execute([$customer_id, TENANT_ID]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new Exception('Müşteri bulunamadı.');
    }

    // WHERE koşullarını oluştur
    $where = "a.customer_id = ? AND a.tenant_id = ? AND a.deleted_at IS NULL";
    $params = [$customer_id, TENANT_ID];

    if (!empty($status)) {
        $where .= " AND a.status = ?";
        $params[] = $status;
    }

    if ($year > 0) {
        $where .= " AND YEAR(a.appointment_date) = ?";
        $params[] = $year;
    }

    if ($month > 0) {
        $where .= " AND MONTH(a.appointment_date) = ?";
        $params[] = $month;
    }

    // Toplam kayıt sayısı
    $stmt = $db->prepare("SELECT COUNT(*) FROM appointments a WHERE $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    // Pagination
    $offset = ($page - 1) * $limit;
    $total_pages = $total > 0 ? (int)ceil($total / $limit) : 0;

    // Randevuları al (çoklu hizmet + ödeme)
    $stmt = $db->prepare("
        SELECT a.id, a.appointment_date, a.appointment_time, a.status,
               a.total_price, a.paid_amount, a.payment_method, a.payment_status,
               a.notes, a.total_duration, a.created_at,
               e.name as employee_name,
               GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as service_names,
               GROUP_CONCAT(
                   DISTINCT CONCAT(
                       s.name, '::',
                       COALESCE(aps.final_price, s.price, 0), '::',
                       COALESCE(aps.quantity, 1)
                   )
                   ORDER BY s.name SEPARATOR '||'
               ) as services_detail
        FROM appointments a
        LEFT JOIN employees e ON a.employee_id = e.id
        LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
        LEFT JOIN services s ON aps.service_id = s.id
        WHERE $where
        GROUP BY a.id
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $paymentLabels = [
        'cash' => 'Nakit',
        'bank_transfer' => 'Havale/EFT',
        'credit_card' => 'Kredi Kartı',
        'mixed' => 'Karışık',
        'not_paid' => 'Ödenmedi',
    ];
    $statusLabels = [
        'pending' => 'Bekliyor',
        'confirmed' => 'Onaylı',
        'completed' => 'Tamamlandı',
        'cancelled' => 'İptal',
        'no-show' => 'Gelmedi',
    ];

    foreach ($appointments as &$apt) {
        $services = [];
        if (!empty($apt['services_detail'])) {
            foreach (explode('||', $apt['services_detail']) as $chunk) {
                $parts = explode('::', $chunk);
                if (count($parts) >= 2) {
                    $services[] = [
                        'name' => $parts[0],
                        'price' => (float)$parts[1],
                        'quantity' => isset($parts[2]) ? (int)$parts[2] : 1,
                    ];
                }
            }
        }
        $apt['services'] = $services;
        $apt['service_name'] = $apt['service_names'] ?? '';
        $apt['total_price'] = (float)($apt['total_price'] ?? 0);
        $apt['paid_amount'] = $apt['paid_amount'] !== null ? (float)$apt['paid_amount'] : null;
        $apt['total_price_formatted'] = number_format($apt['total_price'], 2, ',', '.') . ' ₺';
        $apt['payment_method_label'] = $paymentLabels[$apt['payment_method'] ?? ''] ?? ($apt['payment_method'] ?: '-');
        $apt['status_label'] = $statusLabels[$apt['status'] ?? ''] ?? ($apt['status'] ?? '');
        $apt['appointment_date_formatted'] = $apt['appointment_date'] ? date('d.m.Y', strtotime($apt['appointment_date'])) : '';
        $apt['appointment_time_formatted'] = $apt['appointment_time'] ? date('H:i', strtotime($apt['appointment_time'])) : '';
        unset($apt['services_detail']);
    }
    unset($apt);

    // İstatistikler
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_appointments,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status = 'no-show' THEN 1 ELSE 0 END) as no_show,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END), 0) as total_spent,
            COALESCE(SUM(CASE WHEN status = 'completed' AND payment_status = 'paid' THEN total_price ELSE 0 END), 0) as total_paid,
            COALESCE(SUM(CASE WHEN status = 'completed' AND (payment_status = 'pending' OR payment_method = 'not_paid') THEN total_price ELSE 0 END), 0) as total_unpaid,
            MIN(appointment_date) as first_appointment,
            MAX(appointment_date) as last_appointment
        FROM appointments a
        WHERE a.customer_id = ? AND a.tenant_id = ? AND a.deleted_at IS NULL
    ");
    $stmt->execute([$customer_id, TENANT_ID]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Yıllara göre grupla (filtre dropdown için)
    $stmt = $db->prepare("
        SELECT DISTINCT YEAR(appointment_date) as year
        FROM appointments
        WHERE customer_id = ? AND tenant_id = ? AND deleted_at IS NULL
        ORDER BY year DESC
    ");
    $stmt->execute([$customer_id, TENANT_ID]);
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'customer' => $customer,
        'appointments' => $appointments,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_records' => (int)$total,
            'limit' => $limit
        ],
        'stats' => $stats,
        'available_years' => $years
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => safeErrorMessage($e)
    ]);
}
