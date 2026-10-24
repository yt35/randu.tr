<?php
/**
 * Customers List API
 * GET /api/customers/list.php
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Geçersiz istek metodu']);
    exit;
}

try {
    // Filtre parametreleri
    $search = trim(sanitize($_GET['search'] ?? ''));
    $sort = trim(sanitize($_GET['sort'] ?? 'created_at'));
    if ($sort === '') {
        $sort = 'created_at';
    }
    $order = strtoupper(trim(sanitize($_GET['order'] ?? 'DESC')));
    $limit = intval($_GET['limit'] ?? 0);
    $offset = intval($_GET['offset'] ?? 0);
    $gender = sanitize($_GET['gender'] ?? '');
    $lastVisitDays = intval($_GET['last_visit_days'] ?? 0);
    $birthdayDays = intval($_GET['birthday_days'] ?? 0);

    // Sadece ASC veya DESC kabul et
    if (!in_array($order, ['ASC', 'DESC'])) {
        $order = 'ASC';
    }

    // UI alan adlarını SQL alanlarına eşle
    $sortAliases = [
        'appointment_count' => 'total_visits',
        'last_appointment' => 'last_visit',
        'total_visits' => 'total_visits',
        'last_visit' => 'last_visit',
        'total_spent' => 'total_spent',
        'created_at' => 'created_at',
        'name' => 'name',
        'phone' => 'phone',
        'email' => 'email',
    ];
    if (isset($sortAliases[$sort])) {
        $sort = $sortAliases[$sort];
    }

    // İzin verilen sıralama alanları
    $allowed_sorts = ['name', 'phone', 'email', 'created_at', 'last_visit', 'total_visits', 'total_spent'];
    if (!in_array($sort, $allowed_sorts)) {
        $sort = 'name';
    }

    // Tenant'a bağlı müşterileri al — per-tenant isim ve notlar customer_tenants'tan
    $sql = "SELECT c.id, COALESCE(ct.name, c.name) as name, c.phone, c.birth_date, c.gender, ct.notes, c.is_guest, c.created_at,
            COALESCE(agg.total_visits, 0) as total_visits,
            COALESCE(agg.total_visits, 0) as appointment_count,
            agg.last_visit,
            agg.last_visit as last_appointment,
            agg.next_visit,
            agg.next_visit as next_appointment,
            COALESCE(agg.total_spent, 0) as total_spent
            FROM customers c
            INNER JOIN customer_tenants ct ON c.id = ct.customer_id
            LEFT JOIN (
                SELECT customer_id,
                       COUNT(*) as total_visits,
                       MAX(CASE WHEN appointment_date <= CURDATE() THEN appointment_date END) as last_visit,
                       MIN(CASE WHEN appointment_date >= CURDATE() AND status IN ('pending','confirmed') THEN appointment_date END) as next_visit,
                       SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END) as total_spent
                FROM appointments
                WHERE tenant_id = ? AND deleted_at IS NULL
                GROUP BY customer_id
            ) agg ON c.id = agg.customer_id
            WHERE ct.tenant_id = ? AND ct.deleted_at IS NULL";
    
    $params = [TENANT_ID, TENANT_ID];

    // Arama filtresi — tüm kelimeler AND; telefon yalnızca rakam varsa
    if ($search !== '') {
        $tokens = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($tokens)) {
            $tokens = [$search];
        }
        $nameParts = [];
        foreach ($tokens as $token) {
            $nameParts[] = "COALESCE(ct.name, c.name) LIKE ?";
            $params[] = '%' . $token . '%';
        }
        $searchSql = '(' . implode(' AND ', $nameParts) . ')';
        $phoneDigits = preg_replace('/[^0-9]/', '', $search);
        if ($phoneDigits !== '' && strlen($phoneDigits) >= 3) {
            $searchSql = '(' . $searchSql . ' OR c.phone LIKE ?)';
            $params[] = '%' . $phoneDigits . '%';
        }
        $sql .= ' AND ' . $searchSql;
    }

    if (!empty($gender) && in_array($gender, ['male', 'female', 'other'], true)) {
        $sql .= " AND c.gender = ?";
        $params[] = $gender;
    }

    // HAVING için alt sorgu yerine dış WHERE (alias üzerinden)
    $having = [];
    if ($lastVisitDays > 0) {
        $having[] = "(agg.last_visit IS NULL OR agg.last_visit <= DATE_SUB(CURDATE(), INTERVAL ? DAY))";
        $params[] = $lastVisitDays;
    }
    if ($birthdayDays > 0) {
        $having[] = "c.birth_date IS NOT NULL AND (
            (DAYOFYEAR(DATE_FORMAT(c.birth_date, CONCAT(YEAR(CURDATE()), '-%m-%d')))
             BETWEEN DAYOFYEAR(CURDATE()) AND DAYOFYEAR(CURDATE()) + ?)
            OR (DAYOFYEAR(CURDATE()) + ? > 365 AND
                DAYOFYEAR(DATE_FORMAT(c.birth_date, CONCAT(YEAR(CURDATE()), '-%m-%d')))
                <= (DAYOFYEAR(CURDATE()) + ? - 365))
        )";
        $params[] = $birthdayDays;
        $params[] = $birthdayDays;
        $params[] = $birthdayDays;
    }
    if (!empty($having)) {
        $sql .= ' AND ' . implode(' AND ', $having);
    }

    // Sıralama — JOIN'de ambiguous kolon olmasın (c.created_at vs ct.created_at)
    $sortExprMap = [
        'name' => 'COALESCE(ct.name, c.name)',
        'phone' => 'c.phone',
        'email' => 'c.email',
        'created_at' => 'c.created_at',
        'last_visit' => 'agg.last_visit',
        'total_visits' => 'COALESCE(agg.total_visits, 0)',
        'total_spent' => 'COALESCE(agg.total_spent, 0)',
    ];
    $sortExpr = $sortExprMap[$sort] ?? 'COALESCE(ct.name, c.name)';
    if (in_array($sort, ['total_visits', 'total_spent'], true)) {
        $sql .= " ORDER BY {$sortExpr} {$order}, COALESCE(ct.name, c.name) ASC";
    } elseif (in_array($sort, ['last_visit', 'created_at'], true)) {
        $sql .= " ORDER BY ({$sortExpr} IS NULL) ASC, {$sortExpr} {$order}, COALESCE(ct.name, c.name) ASC";
    } else {
        $sql .= " ORDER BY {$sortExpr} {$order}";
    }

    // Limit
    if ($limit > 0) {
        $sql .= " LIMIT " . $limit;
        if ($offset > 0) {
            $sql .= " OFFSET " . $offset;
        }
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Toplam sayı (aynı filtrelerle, agg olmadan basit sayım için yeniden)
    $count_sql = "SELECT COUNT(*) FROM customers c 
                  INNER JOIN customer_tenants ct ON c.id = ct.customer_id 
                  LEFT JOIN (
                      SELECT customer_id,
                             MAX(CASE WHEN appointment_date <= CURDATE() THEN appointment_date END) as last_visit
                      FROM appointments
                      WHERE tenant_id = ? AND deleted_at IS NULL
                      GROUP BY customer_id
                  ) agg ON c.id = agg.customer_id
                  WHERE ct.tenant_id = ? AND ct.deleted_at IS NULL";
    $count_params = [TENANT_ID, TENANT_ID];
    
    if ($search !== '') {
        $tokens = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [$search];
        $nameParts = [];
        foreach ($tokens as $token) {
            $nameParts[] = "COALESCE(ct.name, c.name) LIKE ?";
            $count_params[] = '%' . $token . '%';
        }
        $searchSql = '(' . implode(' AND ', $nameParts) . ')';
        $phoneDigits = preg_replace('/[^0-9]/', '', $search);
        if ($phoneDigits !== '' && strlen($phoneDigits) >= 3) {
            $searchSql = '(' . $searchSql . ' OR c.phone LIKE ?)';
            $count_params[] = '%' . $phoneDigits . '%';
        }
        $count_sql .= ' AND ' . $searchSql;
    }
    if (!empty($gender) && in_array($gender, ['male', 'female', 'other'], true)) {
        $count_sql .= " AND c.gender = ?";
        $count_params[] = $gender;
    }
    if ($lastVisitDays > 0) {
        $count_sql .= " AND (agg.last_visit IS NULL OR agg.last_visit <= DATE_SUB(CURDATE(), INTERVAL ? DAY))";
        $count_params[] = $lastVisitDays;
    }
    if ($birthdayDays > 0) {
        $count_sql .= " AND c.birth_date IS NOT NULL AND (
            (DAYOFYEAR(DATE_FORMAT(c.birth_date, CONCAT(YEAR(CURDATE()), '-%m-%d')))
             BETWEEN DAYOFYEAR(CURDATE()) AND DAYOFYEAR(CURDATE()) + ?)
            OR (DAYOFYEAR(CURDATE()) + ? > 365 AND
                DAYOFYEAR(DATE_FORMAT(c.birth_date, CONCAT(YEAR(CURDATE()), '-%m-%d')))
                <= (DAYOFYEAR(CURDATE()) + ? - 365))
        )";
        $count_params[] = $birthdayDays;
        $count_params[] = $birthdayDays;
        $count_params[] = $birthdayDays;
    }
    
    $stmt_count = $db->prepare($count_sql);
    $stmt_count->execute($count_params);
    $total_count = $stmt_count->fetchColumn();

    // İstatistikler
    $stats_sql = "SELECT 
                    COUNT(DISTINCT c.id) as total_customers,
                    COUNT(DISTINCT CASE WHEN c.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN c.id END) as new_this_month
                  FROM customers c
                  INNER JOIN customer_tenants ct ON c.id = ct.customer_id
                  WHERE ct.tenant_id = ? AND ct.deleted_at IS NULL";
    $stmt_stats = $db->prepare($stats_sql);
    $stmt_stats->execute([TENANT_ID]);
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'customers' => $customers,
        'total' => intval($total_count),
        'stats' => $stats
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Müşteriler yüklenirken hata oluştu: ' . safeErrorMessage($e)
    ]);
}
