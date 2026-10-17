<?php
require_once '../config/config.php';

if (!isLoggedIn() || !checkSessionTimeout()) {
    redirect('login.php');
}

// SAYFA ERİŞİM KONTROLÜ
if (!can_access_page('customers.php')) {
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

$page_title = 'Müşteri Yönetimi';
$success = '';
$error = '';

function formatCurrency($amount) {
    $float_amount = floatval($amount);
    if ($float_amount == floor($float_amount)) {
        return number_format($float_amount, 0, ',', '.') . ' ₺';
    }
    return number_format($float_amount, 2, ',', '.') . ' ₺';
}

// Görünüm için telefon formatlama
function formatPhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);

    if (strlen($phone) == 10) {
        return '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . ' ' . substr($phone, 6, 2) . ' ' . substr($phone, 8, 2);
    }

    if (strlen($phone) == 11 && $phone[0] == '0') {
        return '(' . substr($phone, 0, 4) . ') ' . substr($phone, 4, 3) . ' ' . substr($phone, 7, 2) . ' ' . substr($phone, 9, 2);
    }

    return $phone;
}

// Veritabanı için telefon standartlaştırma (0'sız)
function standardizePhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 11 && substr($phone, 0, 1) == '0') {
        $phone = substr($phone, 1);
    }
    return $phone;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Bilinmeyen bir hata oluştu.'];

    try {
        $action = sanitize($_POST['action']);

        if ($action == 'add' || $action == 'edit') {
            $name = sanitizeName($_POST['name'] ?? '', 50);
            $phone_raw = sanitize($_POST['phone'] ?? '');
            $email_raw = sanitize($_POST['email'] ?? '');
            $email = !empty($email_raw) ? normalizeEmail($email_raw) : '';
            $notes = sanitizeNotes($_POST['notes'] ?? '');

            // Telefonu standartlaştır (5XX...)
            $phone = standardizePhone($phone_raw);

            if (empty($name) || empty($phone)) {
                throw new Exception('Ad soyad ve telefon numarası gereklidir.');
            }

            if (strlen($phone) < 10 || strlen($phone) > 11) {
                throw new Exception('Geçerli bir telefon numarası giriniz.');
            }

            if (!empty($email_raw) && $email === false) {
                throw new Exception('E-posta adresinde Türkçe karakter (ç, ğ, ı, ö, ş, ü) kullanılamaz.');
            }

            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Geçerli bir e-posta adresi giriniz.');
            }

            if ($action == 'add') {
                // 1. Telefon Global Kontrol
                $stmt = $db->prepare("SELECT id FROM customers WHERE phone = ?");
                $stmt->execute([$phone]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $customer_id = $existing['id'];
                    
                    // 2. İlişki Kontrolü (Zaten ekli mi?)
                    $stmtLink = $db->prepare("SELECT id FROM customer_tenants WHERE customer_id = ? AND tenant_id = ?");
                    $stmtLink->execute([$customer_id, TENANT_ID]);
                    
                    if ($stmtLink->fetch()) {
                        throw new Exception('Bu telefon numarası zaten müşteri listenizde kayıtlı.');
                    } else {
                        // Listede yoksa ilişkilendir (bu işletmenin verdiği isim ve notla)
                        $stmtInsert = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name, notes) VALUES (?, ?, ?, ?)");
                        $stmtInsert->execute([$customer_id, TENANT_ID, $name, $notes ?: null]);
                        
                        // İsteğe bağlı: İsmi güncellemek isterseniz
                        // $db->prepare("UPDATE customers SET name = ? WHERE id = ?")->execute([$name, $customer_id]);
                        
                        $response['message'] = 'Mevcut müşteri listenize eklendi.';
                    }
                } else {
                    // 3. Hiç yoksa: Yeni Global Müşteri Oluştur
                    $stmt = $db->prepare("INSERT INTO customers (tenant_id, name, phone, is_guest) VALUES (NULL, ?, ?, 1)");
                    $stmt->execute([$name, $phone]);
                    $new_customer_id = $db->lastInsertId();
                    
                    // 4. İlişki Kur (bu işletmenin verdiği isim ve notla)
                    $stmtLink = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id, name, notes) VALUES (?, ?, ?, ?)");
                    $stmtLink->execute([$new_customer_id, TENANT_ID, $name, $notes ?: null]);
                    
                    $response['message'] = 'Müşteri başarıyla oluşturuldu ve eklendi.';
                }

            } else {
                // DÜZENLEME İŞLEMİ
                // Müşterinin is_guest durumunu kontrol et
                $stmt_guest = $db->prepare("SELECT c.is_guest, c.phone FROM customers c INNER JOIN customer_tenants ct ON c.id = ct.customer_id WHERE c.id = ? AND ct.tenant_id = ? AND ct.deleted_at IS NULL");
                $stmt_guest->execute([$customer_id, TENANT_ID]);
                $guest_check = $stmt_guest->fetch(PDO::FETCH_ASSOC);
                
                if (!$guest_check) {
                    throw new Exception('Müşteri bulunamadı.');
                }
                if ($guest_check['is_guest'] == 0) {
                    throw new Exception('Bu müşteri platforma kayıtlı olduğu için bilgileri yalnızca kendisi tarafından değiştirilebilir.');
                }

                $current_phone = standardizePhone($guest_check['phone'] ?? '');
                if ($phone !== $current_phone) {
                    $stmtPhone = $db->prepare("SELECT id FROM customers WHERE phone = ? AND id != ? LIMIT 1");
                    $stmtPhone->execute([$phone, $customer_id]);
                    if ($stmtPhone->fetch(PDO::FETCH_ASSOC)) {
                        throw new Exception('Bu telefon numarası başka bir müşteriye ait. Farklı bir numara deneyin.');
                    }
                }
                
                // Per-tenant name ve notes güncelle
                $stmt_upd = $db->prepare("UPDATE customer_tenants SET name = ?, notes = ? WHERE customer_id = ? AND tenant_id = ?");
                $stmt_upd->execute([$name, $notes ?: null, $customer_id, TENANT_ID]);

                // Global phone (+ name) güncelle
                $stmt_phone = $db->prepare("UPDATE customers SET phone = ?, name = ? WHERE id = ? AND is_guest = 1");
                $stmt_phone->execute([$phone, $name, $customer_id]);

                $response['message'] = 'Müşteri bilgileri güncellendi.';
            }
            $response['success'] = true;
            $response['reload'] = true;

        } elseif ($action == 'delete') {
            $customer_id = intval($_POST['customer_id'] ?? 0);
            if (!$customer_id) throw new Exception("Müşteri ID'si eksik.");

            // Gelecek randevu kontrolü (SADECE BU TENANT İÇİN)
            $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE customer_id = ? AND appointment_date >= CURDATE() AND tenant_id = ? AND status IN ('pending', 'confirmed')");
            $stmt->execute([$customer_id, TENANT_ID]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('Bu müşterinin gelecek tarihli randevuları bulunduğu için listeden çıkarılamaz.');
            }

            // İlişkiyi Soft Delete (Müşteriyi dükkan listesinden çıkarıyoruz)
            $stmt = $db->prepare("UPDATE customer_tenants SET deleted_at = NOW() WHERE customer_id = ? AND tenant_id = ? AND deleted_at IS NULL");
            $stmt->execute([$customer_id, TENANT_ID]);
            
            $response['success'] = true;
            $response['message'] = 'Müşteri listenizden çıkarıldı.';
            $response['reload'] = true;

        } elseif ($action == 'bulk_delete') {
            $customer_ids = $_POST['customer_ids'] ?? [];
            if (empty($customer_ids) || !is_array($customer_ids)) {
                throw new Exception("Silinecek müşteri seçilmedi.");
            }

            $undeletable_customers = [];
            $deletable_ids = [];

            // Gelecek randevu kontrolü
            $stmt_check = $db->prepare("
                SELECT c.name 
                FROM customers c 
                WHERE c.id = ? 
                AND EXISTS (
                    SELECT 1 
                    FROM appointments a 
                    WHERE a.customer_id = c.id 
                    AND a.appointment_date >= CURDATE()
                    AND a.status IN ('pending', 'confirmed')
                    AND a.tenant_id = ?
                )
            ");
            
            foreach($customer_ids as $id){
                $id = intval($id);
                $stmt_check->execute([$id, TENANT_ID]);
                $customer_name = $stmt_check->fetchColumn();
                if($customer_name){
                    $undeletable_customers[] = $customer_name;
                } else {
                    $deletable_ids[] = $id;
                }
            }

            // Toplu ilişki silme
            if (!empty($deletable_ids)) {
                $placeholders = str_repeat('?,', count($deletable_ids) - 1) . '?';
                $stmt = $db->prepare("UPDATE customer_tenants SET deleted_at = NOW() WHERE customer_id IN ($placeholders) AND tenant_id = ? AND deleted_at IS NULL");
                $params_delete = array_merge($deletable_ids, [TENANT_ID]);
                $stmt->execute($params_delete);
            }

            $response['success'] = true;
            if (!empty($undeletable_customers)) {
                $response['message'] = count($deletable_ids) . " müşteri çıkarıldı. Ancak, şu müşterilerin randevusu olduğu için işlem yapılmadı: " . implode(', ', $undeletable_customers);
            } else {
                 $response['message'] = count($deletable_ids) . ' müşteri listenizden çıkarıldı!';
            }
            $response['reload'] = true;
        }

    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

$sort_combined_options = [
    'created_at_DESC' => 'Kayıt Tarihi (Yeni)',
    'created_at_ASC' => 'Kayıt Tarihi (Eski)',
    'total_spent_DESC' => 'Harcama (Azalan)',
    'total_spent_ASC' => 'Harcama (Artan)',
    'appointment_count_DESC' => 'Randevu Sayısı (Azalan)',
    'appointment_count_ASC' => 'Randevu Sayısı (Artan)',
    'last_appointment_DESC' => 'Son Randevu (Yeni)',
    'last_appointment_ASC' => 'Son Randevu (Eski)',
    'name_ASC' => 'Ad Soyad (A-Z)',
    'name_DESC' => 'Ad Soyad (Z-A)',
];

$filter_search = trim($_GET['search'] ?? '');
$filter_sort = $_GET['sort'] ?? 'created_at_DESC';
$filter_gender = $_GET['gender'] ?? '';
$filter_last_visit_days = intval($_GET['last_visit_days'] ?? 0);
$filter_birthday_days = intval($_GET['birthday_days'] ?? 0);

if (!array_key_exists($filter_sort, $sort_combined_options)) {
    $filter_sort = 'created_at_DESC';
}

$last_underscore_pos = strrpos($filter_sort, '_');
$filter_sort_by = substr($filter_sort, 0, $last_underscore_pos);
$filter_sort_order = substr($filter_sort, $last_underscore_pos + 1);

$sort_expr_map = [
    'created_at' => 'c.created_at',
    'name' => 'name',
    'appointment_count' => 'appointment_count',
    'total_spent' => 'total_spent',
    'last_appointment' => 'last_appointment',
];
$sort_expr = $sort_expr_map[$filter_sort_by] ?? 'c.created_at';
if (in_array($filter_sort_by, ['appointment_count', 'total_spent'], true)) {
    $order_by_clause = "ORDER BY {$sort_expr} {$filter_sort_order}, name ASC";
} elseif ($filter_sort_by === 'last_appointment') {
    $order_by_clause = "ORDER BY (last_appointment IS NULL) ASC, last_appointment {$filter_sort_order}, name ASC";
} else {
    $order_by_clause = "ORDER BY {$sort_expr} {$filter_sort_order}";
}

// Filtreleme koşulları (YENİ SİSTEM: customer_tenants üzerinden)
$where_conditions = ['ct.tenant_id = ?', 'ct.deleted_at IS NULL'];
$params = [TENANT_ID];

if (!empty($filter_search)) {
    $tokens = preg_split('/\s+/u', $filter_search, -1, PREG_SPLIT_NO_EMPTY) ?: [$filter_search];
    $nameParts = [];
    foreach ($tokens as $token) {
        $nameParts[] = "COALESCE(ct.name, c.name) LIKE ?";
        $params[] = '%' . $token . '%';
    }
    $searchSql = '(' . implode(' AND ', $nameParts) . ')';
    $phoneDigits = preg_replace('/[^0-9]/', '', $filter_search);
    if ($phoneDigits !== '' && strlen($phoneDigits) >= 3) {
        $searchSql = '(' . $searchSql . ' OR c.phone LIKE ?)';
        $params[] = '%' . $phoneDigits . '%';
    }
    $where_conditions[] = $searchSql;
}

if (!empty($filter_gender) && in_array($filter_gender, ['male', 'female', 'other'])) {
    $where_conditions[] = "c.gender = ?";
    $params[] = $filter_gender;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

try {
    // Müşteri listesi sorgusu - Platform Modeli JOIN Yapısı
    // future_appointment_count correlated subquery kaldırıldı (LEFT JOIN ile tek geçişte)
    $query = "
        SELECT
            c.id, COALESCE(ct.name, c.name) as name, c.phone, c.email, c.birth_date, c.gender, ct.notes, c.is_guest, c.created_at,
            ct.created_at as relation_date,
            COUNT(a.id) as appointment_count,
            COALESCE(SUM(CASE WHEN a.status = 'completed' THEN a.total_price ELSE 0 END), 0) as total_spent,
            MAX(CASE WHEN a.appointment_date <= CURDATE() AND a.status IN ('completed','confirmed','pending') THEN a.appointment_date END) as last_appointment,
            MIN(CASE WHEN a.appointment_date >= CURDATE() AND a.status IN ('pending','confirmed') THEN a.appointment_date END) as next_appointment,
            COALESCE(SUM(CASE WHEN a.appointment_date >= CURDATE() AND a.status IN ('pending','confirmed') THEN 1 ELSE 0 END), 0) as future_appointment_count
        FROM customers c
        INNER JOIN customer_tenants ct ON c.id = ct.customer_id
        LEFT JOIN appointments a ON c.id = a.customer_id AND a.tenant_id = ? AND a.deleted_at IS NULL
        $where_clause
        GROUP BY c.id
    ";
    
    // HAVING koşulları (aggregation sonrası filtreler)
    $having_conditions = [];
    $having_params = [];
    
    if ($filter_last_visit_days > 0) {
        $having_conditions[] = "(MAX(a.appointment_date) IS NULL OR MAX(a.appointment_date) <= DATE_SUB(CURDATE(), INTERVAL ? DAY))";
        $having_params[] = $filter_last_visit_days;
    }
    
    if ($filter_birthday_days > 0) {
        // Yıl sonu taşmasını da hesaba katan doğum günü filtresi
        $having_conditions[] = "c.birth_date IS NOT NULL AND (
            (DAYOFYEAR(DATE_FORMAT(c.birth_date, CONCAT(YEAR(CURDATE()), '-%m-%d'))) 
             BETWEEN DAYOFYEAR(CURDATE()) AND DAYOFYEAR(CURDATE()) + ?)
            OR (DAYOFYEAR(CURDATE()) + ? > 365 AND 
                DAYOFYEAR(DATE_FORMAT(c.birth_date, CONCAT(YEAR(CURDATE()), '-%m-%d'))) 
                <= (DAYOFYEAR(CURDATE()) + ? - 365))
        )";
        $having_params[] = $filter_birthday_days;
        $having_params[] = $filter_birthday_days;
        $having_params[] = $filter_birthday_days;
    }
    
    if (!empty($having_conditions)) {
        $query .= " HAVING " . implode(' AND ', $having_conditions);
    }
    
    $query .= " $order_by_clause LIMIT 500";
    
    // Parametreleri hazırla
    $query_params = array_merge([TENANT_ID], $params, $having_params);
    
    $stmt = $db->prepare($query);
    $stmt->execute($query_params);
    $customers = $stmt->fetchAll();
} catch(Exception $e) {
    $customers = [];
    $error = "Müşteri verileri yüklenirken bir hata oluştu: " . $e->getMessage();
}

// Aktif kampanyaları çek (kampanya gönderim modali için)
$active_campaigns = [];
try {
    $stmt_campaigns = $db->prepare("SELECT id, title, sms_message, campaign_type, discount_type, discount_value FROM tenant_campaigns WHERE tenant_id = ? AND is_active = 1 ORDER BY title");
    $stmt_campaigns->execute([TENANT_ID]);
    $active_campaigns = $stmt_campaigns->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tablo henüz yoksa sessizce geç
}

include 'includes/header.php';
?>

<link rel="stylesheet" href="assets/css/customers.css?v=<?php echo time(); ?>">
<?php if ($is_dark_mode): ?>
<link rel="stylesheet" href="assets/css/customer-dark.css?v=<?php echo time(); ?>">
<?php endif; ?>

<div class="admin-main">

    <div class="stats-container">
        <!-- Kart 1: Toplam Müşteri -->
        <div class="card">
            <div class="title">
                <span style="background-color: #3b82f6;">
                    <i class="fas fa-users"></i>
                </span>
                <p class="title-text">Toplam Müşteri</p>
                <p class="percent">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 1792 1792" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1408 1216q0 26-19 45t-45 19h-896q-26 0-45-19t-19-45 19-45l448-448q19-19 45-19t45 19l448 448q19 19 19 45z"></path>
                    </svg>
                    100%
                </p>
            </div>
            <div class="data">
                <p><?php echo count($customers); ?></p>
                <div class="range">
                    <div class="fill" style="width: 100%; background-color: #3b82f6;"></div>
                </div>
            </div>
        </div>

        <!-- Kart 2: Bu Ayki Yeni -->
        <div class="card">
            <div class="title">
                <span style="background-color: #10b981;">
                    <i class="fas fa-user-plus"></i>
                </span>
                <p class="title-text">Bu Ayki Yeni</p>
                <p class="percent">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 1792 1792" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1408 1216q0 26-19 45t-45 19h-896q-26 0-45-19t-19-45 19-45l448-448q19-19 45-19t45 19l448 448q19 19 19 45z"></path>
                    </svg>
                    <?php 
                    $new_customers = array_filter($customers, function($c) {
                        return strtotime($c['created_at']) > strtotime('-30 days');
                    });
                    echo count($customers) > 0 ? round((count($new_customers) / count($customers)) * 100) : 0;
                    ?>%
                </p>
            </div>
            <div class="data">
                <p><?php echo count($new_customers); ?></p>
                <div class="range">
                    <div class="fill" style="width: <?php echo count($customers) > 0 ? round((count($new_customers) / count($customers)) * 100) : 0; ?>%; background-color: #10b981;"></div>
                </div>
            </div>
        </div>

        <!-- Kart 3: Aktif Müşteri -->
        <div class="card">
            <div class="title">
                <span style="background-color: #3b82f6;">
                    <i class="fas fa-calendar-check"></i>
                </span>
                <p class="title-text">Aktif Müşteri</p>
                <p class="percent">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 1792 1792" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1408 1216q0 26-19 45t-45 19h-896q-26 0-45-19t-19-45 19-45l448-448q19-19 45-19t45 19l448 448q19 19 19 45z"></path>
                    </svg>
                    <?php
                    $active_customers = array_filter($customers, function($c) {
                        return $c['appointment_count'] > 0;
                    });
                    echo count($customers) > 0 ? round((count($active_customers) / count($customers)) * 100) : 0;
                    ?>%
                </p>
            </div>
            <div class="data">
                <p><?php echo count($active_customers); ?></p>
                <div class="range">
                    <div class="fill" style="width: <?php echo count($customers) > 0 ? round((count($active_customers) / count($customers)) * 100) : 0; ?>%; background-color: #3b82f6;"></div>
                </div>
            </div>
        </div>

        <!-- Kart 4: Sadık Müşteri -->
        <div class="card">
            <div class="title">
                <span style="background-color: #f59e0b;">
                    <i class="fas fa-star"></i>
                </span>
                <p class="title-text">Sadık Müşteri</p>
                <p class="percent">
                    <svg width="20" height="20" fill="currentColor" viewBox="0 0 1792 1792" xmlns="http://www.w3.org/2000/svg">
                        <path d="M1408 1216q0 26-19 45t-45 19h-896q-26 0-45-19t-19-45 19-45l448-448q19-19 45-19t45 19l448 448q19 19 19 45z"></path>
                    </svg>
                    <?php
                    $loyal_customers = array_filter($customers, function($c) {
                        return $c['appointment_count'] >= 5;
                    });
                    echo count($customers) > 0 ? round((count($loyal_customers) / count($customers)) * 100) : 0;
                    ?>%
                </p>
            </div>
            <div class="data">
                <p><?php echo count($loyal_customers); ?></p>
                <div class="range">
                    <div class="fill" style="width: <?php echo count($customers) > 0 ? round((count($loyal_customers) / count($customers)) * 100) : 0; ?>%; background-color: #f59e0b;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="data-table">
        <div class="table-header">
            <div class="table-header-top">
                <h3 class="table-title"><i class="fas fa-list"></i> Müşteri Listesi</h3>
            </div>
        </div>

        <?php if(!empty($customers)): ?>
        <div class="customers-grid">
            <div class="bulk-select-header">
                <div class="select-all-wrapper">
                    <input type="checkbox" id="selectAll" class="customer-checkbox" onclick="toggleSelectAll()">
                    <label for="selectAll">Tümünü Seç</label>
                </div>
                <div class="header-buttons">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="openFilterSidebar()"><i class="fas fa-filter"></i> Filtrele</button>
                    <button type="button" class="btn btn-success btn-sm" onclick="showAddModal()"><i class="fas fa-user-plus"></i> Ekle</button>
                    <button type="button" id="bulkDeleteBtn" class="btn btn-danger btn-sm" onclick="executeBulkAction()" style="display:none;"><i class="fas fa-trash-alt"></i> Seçilenleri Sil</button>
                    <button type="button" id="bulkCampaignBtn" class="btn btn-sm" onclick="openCampaignSendModal()" style="display:none; background:linear-gradient(135deg,#1e3a5f,#162d4a); color:#fff; border:none;"><i class="fas fa-paper-plane"></i> Kampanya Gönder</button>
                </div>
            </div>

            <div class="customer-list-header d-none d-lg-flex">
                <div class="header-info">Müşteri</div>
                <div class="header-stats">
                    <div>Toplam Harcama</div>
                    <div>Randevu Sayısı</div>
                    <div>Kayıt Tarihi</div>
                </div>
                <div class="header-actions">İşlemler</div>
            </div>

            <?php foreach($customers as $customer): ?>
            <div class="customer-card" data-id="<?php echo $customer['id']; ?>" data-customer-id="<?php echo $customer['id']; ?>">
                <div class="card-content">

                    <div class="customer-info">
                        <input type="checkbox" class="customer-checkbox" value="<?php echo $customer['id']; ?>" onchange="updateBulkActions(this)">
                        <div class="customer-details">
                            <h4 class="customer-name">
                                <?php echo htmlspecialchars($customer['name']); ?>
                                <?php if($customer['appointment_count'] >= 5): ?>
                                    <span class="badge badge-warning" title="Sadık Müşteri"><i class="fas fa-star"></i></span>
                                <?php endif; ?>
                            </h4>
                            <div class="customer-contact customer-phone" data-field="phone">
                                <i class="fas fa-phone"></i><span><?php echo formatPhone($customer['phone']); ?></span>
                            </div>
                            <div class="customer-last-appointment">
                                <strong>Son Randevu:</strong> <?php echo $customer['last_appointment'] ? formatDate($customer['last_appointment']) : 'Yok'; ?>
                            </div>
                            <?php if (!empty($customer['next_appointment'])): ?>
                            <div class="customer-next-appointment" style="color:#10b981; font-size:0.85rem; margin-top:0.25rem;">
                                <strong>Yaklaşan Randevu:</strong> <?php echo formatDate($customer['next_appointment']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-stats">
                        <div class="stat-item">
                            <div class="value total-spent"><?php echo formatCurrency($customer['total_spent']); ?></div>
                            <div class="label">Toplam Harcama</div>
                        </div>
                        <div class="stat-item">
                            <div class="value appointments"><?php echo $customer['appointment_count']; ?></div>
                            <div class="label">Randevu Sayısı</div>
                        </div>
                        <div class="stat-item">
                            <div class="value register-date"><?php echo formatDate($customer['created_at']); ?></div>
                            <div class="label">Kayıt Tarihi</div>
                        </div>
                    </div>

                    <div class="card-actions">
                        <?php if (!empty($customer['is_guest'])): ?>
                        <button type="button" class="btn btn-sm btn-primary" onclick="editCustomer(<?php echo $customer['id']; ?>)">
                            <i class="fas fa-edit"></i> Düzenle
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="viewCustomerHistory(<?php echo $customer['id']; ?>)">
                            <i class="fas fa-history"></i> Geçmiş
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars(addslashes($customer['name'])); ?>', <?php echo $customer['future_appointment_count']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>

        </div>
        <?php else: ?>
        <div class="customers-grid">
            <div class="bulk-select-header">
                <div class="select-all-wrapper">
                    <input type="checkbox" id="selectAll" class="customer-checkbox" onclick="toggleSelectAll()">
                    <label for="selectAll">Tümünü Seç</label>
                </div>
                <div class="header-buttons">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="openFilterSidebar()"><i class="fas fa-filter"></i> Filtrele</button>
                    <button type="button" class="btn btn-success btn-sm" onclick="showAddModal()"><i class="fas fa-user-plus"></i> Ekle</button>
                </div>
            </div>
            <div class="customers-empty-state" style="text-align: center; padding: 3rem;">
                <i class="fas fa-users" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 1rem; display: block;"></i>
                <h3>Henüz Müşteri Eklenmemiş</h3>
                <p style="color: var(--dark-gray); margin-bottom: 1.5rem;">İlk müşterinizi ekleyerek başlayın.</p>
                <button type="button" class="btn btn-success" onclick="showAddModal()"><i class="fas fa-user-plus"></i> Müşteri Ekle</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="customerModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3 id="modalTitle">Yeni Müşteri</h3>
        <form id="customerForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="customer_id" id="customerId">
            <div class="form-group">
                <label for="name">Ad Soyad <span style="color: red;">*</span></label>
                <input type="text" id="name" name="name" class="form-control" required maxlength="50">
            </div>
            <div class="form-group">
                <label for="phone">Telefon Numarası <span style="color: red;">*</span></label>
                <input type="tel" id="phone" name="phone" class="form-control" placeholder="5XX XXX XX XX" required maxlength="15">
                <small style="color: var(--dark-gray); display: block; margin-top: 0.5rem;">
                    <i class="fas fa-info-circle"></i> Örnek: 512 345 67 89
                </small>
            </div>
            <div class="form-group">
                <label for="email">E-posta</label>
                <input type="email" id="email" name="email" class="form-control">
            </div>
            <div class="form-row" style="display:flex; gap:1rem;">
                <div class="form-group" style="flex:1;">
                    <label for="birth_date">Doğum Tarihi</label>
                    <input type="date" id="birth_date" name="birth_date" class="form-control">
                </div>
                <div class="form-group" style="flex:1;">
                    <label for="gender">Cinsiyet</label>
                    <select id="gender" name="gender" class="form-control">
                        <option value="">Belirtilmemiş</option>
                        <option value="male">Erkek</option>
                        <option value="female">Kadın</option>
                        <option value="other">Diğer</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="notes">Notlar</label>
                <textarea id="notes" name="notes" class="form-control" rows="3"></textarea>
            </div>
            <div style="text-align: right; margin-top: 2rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">İptal</button>
                <button type="submit" class="btn btn-primary">Kaydet</button>
            </div>
        </form>
    </div>
</div>

<div id="historyModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.6); z-index:999999; align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; padding:2rem; border-radius:20px; width:90%; max-width:700px; border:3px solid #323232; box-shadow:5px 5px #323232; position:relative;">
        <span class="close" onclick="closeHistoryModal()" style="position:absolute; right:1rem; top:0.5rem; font-size:2rem; cursor:pointer;">&times;</span>
        <div id="historyContent"></div>
    </div>
</div>

<!-- Filter Sidebar -->
<div class="filter-overlay" id="filterOverlay" onclick="closeFilterSidebar()"></div>
<div class="filter-sidebar" id="filterSidebar">
    <div class="sidebar-header">
        <h3>
            <span class="header-icon"><i class="fas fa-filter"></i></span>
            Filtrele & Sırala
        </h3>
        <button type="button" class="close-sidebar-btn" onclick="closeFilterSidebar()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <form method="GET" id="filterForm">
        <div class="sidebar-content">
            <div class="filter-group">
                <label for="search_input">
                    <span class="label-icon icon-search"><i class="fas fa-search"></i></span>
                    Arama
                </label>
                <input type="text" id="search_input" name="search" class="form-control" placeholder="Müşteri ara..." value="<?php echo htmlspecialchars($filter_search); ?>">
            </div>
            
            <div class="filter-group">
                <label for="sort_select">
                    <span class="label-icon icon-sort"><i class="fas fa-sort"></i></span>
                    Sıralama
                </label>
                <select id="sort_select" name="sort" class="form-control">
                    <?php foreach ($sort_combined_options as $key => $value): ?>
                        <option value="<?php echo $key; ?>" <?php echo ($key == $filter_sort) ? 'selected' : ''; ?>>
                            <?php echo $value; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="gender_select">
                    <span class="label-icon" style="background:linear-gradient(135deg,#f59e0b,#fbbf24);"><i class="fas fa-venus-mars"></i></span>
                    Cinsiyet
                </label>
                <select id="gender_select" name="gender" class="form-control">
                    <option value="">Tümü</option>
                    <option value="male" <?php echo ($filter_gender === 'male') ? 'selected' : ''; ?>>Erkek</option>
                    <option value="female" <?php echo ($filter_gender === 'female') ? 'selected' : ''; ?>>Kadın</option>
                    <option value="other" <?php echo ($filter_gender === 'other') ? 'selected' : ''; ?>>Diğer</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="last_visit_days">
                    <span class="label-icon" style="background:linear-gradient(135deg,#ef4444,#f87171);"><i class="fas fa-user-slash"></i></span>
                    Son Ziyaret (gün önce)
                </label>
                <input type="number" id="last_visit_days" name="last_visit_days" class="form-control" min="0" max="365" placeholder="Ör: 30 (30+ gündür gelmeyen)" value="<?php echo $filter_last_visit_days ?: ''; ?>">
                <small style="color:var(--dark-gray); margin-top:0.25rem; display:block;">X gündür gelmeyen müşteriler</small>
            </div>

            <div class="filter-group">
                <label for="birthday_days">
                    <span class="label-icon" style="background:linear-gradient(135deg,#ec4899,#f472b6);"><i class="fas fa-birthday-cake"></i></span>
                    Yaklaşan Doğum Günü
                </label>
                <input type="number" id="birthday_days" name="birthday_days" class="form-control" min="0" max="60" placeholder="Ör: 7 (7 gün içinde)" value="<?php echo $filter_birthday_days ?: ''; ?>">
                <small style="color:var(--dark-gray); margin-top:0.25rem; display:block;">X gün içinde doğum günü olanlar</small>
            </div>
        </div>
        <div class="sidebar-footer">
            <button type="submit" class="btn btn-apply">
                <i class="fas fa-check"></i> Uygula
            </button>
            <button type="button" class="btn btn-reset" onclick="resetFilters()">
                <i class="fas fa-undo"></i> Temizle
            </button>
        </div>
    </form>
</div>

<script>
    window.customerData = {
        customers: <?php echo json_encode($customers); ?>
    };
    window.activeCampaigns = <?php echo json_encode($active_campaigns); ?>;
</script>

<!-- KAMPANYA GÖNDERİM MODAL -->
<div id="campaignSendModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeCampaignSendModal()">&times;</span>
        <h3><i class="fas fa-paper-plane" style="color:#1e3a5f;"></i> Kampanya SMS Gönder</h3>
        <p id="campaignSendInfo" style="color:var(--dark-gray); margin-bottom:1rem;"></p>
        
        <?php if (empty($active_campaigns)): ?>
        <div style="text-align:center; padding:2rem;">
            <i class="fas fa-exclamation-triangle" style="font-size:2rem; color:#f59e0b; margin-bottom:0.5rem; display:block;"></i>
            <p>Aktif kampanya bulunamadı.</p>
            <small style="color:var(--dark-gray);">Önce <a href="campaigns.php">Kampanya Yönetimi</a> sayfasından kampanya oluşturun.</small>
        </div>
        <?php else: ?>
        <div class="form-group">
            <label>Kampanya Seçin</label>
            <select id="campaignSelect" class="form-control" onchange="previewCampaignSms()">
                <option value="">-- Kampanya Seçin --</option>
                <?php foreach ($active_campaigns as $camp): ?>
                <option value="<?php echo $camp['id']; ?>" data-message="<?php echo htmlspecialchars($camp['sms_message']); ?>">
                    <?php echo htmlspecialchars($camp['title']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="campaignSmsPreview" style="display:none; margin:1rem 0; padding:0.75rem; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;">
            <small><strong>SMS Önizleme:</strong></small>
            <p id="campaignSmsPreviewText" style="margin:0.3rem 0; font-size:0.85rem; color:#166534;"></p>
        </div>
        <div style="text-align:right; margin-top:1.5rem;">
            <button type="button" class="btn btn-secondary" onclick="closeCampaignSendModal()">İptal</button>
            <button type="button" id="sendCampaignBtn" class="btn" style="background:linear-gradient(135deg,#1e3a5f,#162d4a); color:#fff; border:none;" onclick="sendCampaignToSelected()" disabled>
                <i class="fas fa-paper-plane"></i> Gönder
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Kampanya Gönderim Fonksiyonları
var selectedCampaignCustomerIds = [];

function openCampaignSendModal(singleCustomerId) {
    selectedCampaignCustomerIds = [];
    
    if (singleCustomerId) {
        selectedCampaignCustomerIds = [singleCustomerId];
    } else {
        document.querySelectorAll('.customer-checkbox:checked').forEach(function(cb) {
            if (cb.id !== 'selectAll' && cb.value) selectedCampaignCustomerIds.push(cb.value);
        });
    }
    
    if (selectedCampaignCustomerIds.length === 0) {
        showNotification('Lütfen en az bir müşteri seçin.', 'warning');
        return;
    }
    
    document.getElementById('campaignSendInfo').textContent = selectedCampaignCustomerIds.length + ' müşteriye kampanya SMS gönderilecek.';
    var select = document.getElementById('campaignSelect');
    if (select) { select.value = ''; }
    var preview = document.getElementById('campaignSmsPreview');
    if (preview) preview.style.display = 'none';
    var btn = document.getElementById('sendCampaignBtn');
    if (btn) btn.disabled = true;
    
    document.getElementById('campaignSendModal').classList.add('active');
}

function closeCampaignSendModal() {
    document.getElementById('campaignSendModal').classList.remove('active');
}

function previewCampaignSms() {
    var select = document.getElementById('campaignSelect');
    var preview = document.getElementById('campaignSmsPreview');
    var previewText = document.getElementById('campaignSmsPreviewText');
    var btn = document.getElementById('sendCampaignBtn');
    
    if (!select || !select.value) {
        if (preview) preview.style.display = 'none';
        if (btn) btn.disabled = true;
        return;
    }
    
    var opt = select.options[select.selectedIndex];
    var msg = opt.getAttribute('data-message') || '';
    previewText.textContent = msg.replace(/\{isim\}/g, 'Müşteri Adı').replace(/\{isletme\}/g, 'İşletme').replace(/\{indirim\}/g, '...');
    preview.style.display = 'block';
    btn.disabled = false;
}

function sendCampaignToSelected() {
    var select = document.getElementById('campaignSelect');
    if (!select || !select.value) {
        showNotification('Kampanya seçin.', 'warning');
        return;
    }
    
    var btn = document.getElementById('sendCampaignBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gönderiliyor...';
    
    var formData = new FormData();
    formData.append('action', 'send_campaign');
    formData.append('campaign_id', select.value);
    selectedCampaignCustomerIds.forEach(function(id) {
        formData.append('customer_ids[]', id);
    });
    
    fetch('campaigns.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showNotification(data.message, 'success');
            closeCampaignSendModal();
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(function() { showNotification('Gönderim sırasında hata oluştu.', 'error'); })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Gönder';
    });
}

// Modal dışı tıklama
document.addEventListener('click', function(e) {
    if (e.target.id === 'campaignSendModal') closeCampaignSendModal();
});
</script>

<script src="assets/js/customers.js?v=<?php echo time(); ?>"></script>

<?php include 'includes/footer.php'; ?>
