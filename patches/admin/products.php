<?php
/**
 * ÜRÜN & STOK YÖNETİMİ
 * Ürün listesi, ekleme/düzenleme, stok takibi, barkod okuma
 */
require_once '../config/config.php';
require_once '../config/UploadManager.php';

if (!isLoggedIn() || !checkSessionTimeout()) {
    redirect('login.php');
}

if (!can_access_page('products.php')) {
    $first_page = get_first_accessible_page();
    if ($first_page === null) {
        session_unset(); session_destroy();
        header("Location: login.php?error=no_permissions");
    } else {
        header("Location: " . $first_page);
    }
    exit;
}

if (!defined('TENANT_ID') || TENANT_ID === null) {
    die("Hata: İşletme kimliği doğrulanamadı.");
}

$page_title = 'Ürün & Stok Yönetimi';

// ============================================
// GET ile form gönderilmişse düzelt (cache sorunu)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Location: products.php');
    exit;
}

// ============================================
// AJAX İşlemleri
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Bilinmeyen hata.'];

    try {
        if (!Csrf::verify()) {
            throw new Exception('Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
        }
        $action = sanitize($_POST['action']);

        // ---- ÜRÜN EKLE ----
        if ($action === 'add') {
            $name = trim(strip_tags($_POST['name'] ?? ''));
            $barcode = trim(strip_tags($_POST['barcode'] ?? '')) ?: null;
            $price = floatval($_POST['price'] ?? 0);
            $purchase_price = floatval($_POST['purchase_price'] ?? 0);
            $discount_type = in_array($_POST['discount_type'] ?? '', ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
            $discount_amount = floatval($_POST['discount_amount'] ?? 0);
            $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
            $stock_tracking = intval($_POST['stock_tracking'] ?? 0);
            $min_stock = intval($_POST['min_stock'] ?? 5);
            $is_sale = intval($_POST['is_sale'] ?? 0);
            $is_consumable = intval($_POST['is_consumable'] ?? 0);
            if (!$is_sale && !$is_consumable) $is_sale = 1;
            if ($is_sale && $is_consumable) $product_type = 'both';
            elseif ($is_consumable) $product_type = 'consumable';
            else $product_type = 'sale';
            $description = trim(strip_tags($_POST['description'] ?? ''));

            if (empty($name)) throw new Exception('Ürün adı gereklidir.');
            if ($price < 0) throw new Exception('Fiyat negatif olamaz.');
            if ($purchase_price < 0) throw new Exception('Alış fiyatı negatif olamaz.');

            // Barkod benzersizlik kontrolü
            if ($barcode) {
                $check = $db->prepare("SELECT id FROM products WHERE tenant_id = ? AND barcode = ? AND deleted_at IS NULL");
                $check->execute([TENANT_ID, $barcode]);
                if ($check->fetch()) throw new Exception('Bu barkod zaten kullanılıyor.');
            }

            $stmt = $db->prepare("INSERT INTO products (tenant_id, name, barcode, description, price, purchase_price, discount_type, discount_amount, stock_quantity, stock_tracking, min_stock, product_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([TENANT_ID, $name, $barcode, $description ?: null, $price, $purchase_price, $discount_type, $discount_amount, $stock_quantity, $stock_tracking, $min_stock, $product_type]);

            $product_id = $db->lastInsertId();

            // Ürün görseli yükle
            if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadManager = new UploadManager($db, TENANT_ID);
                $uploadResult = $uploadManager->uploadFile($_FILES['image'], 'product', $product_id, 'products', $_SESSION['user_id']);
                if ($uploadResult['success']) {
                    $db->prepare("UPDATE products SET image = ? WHERE id = ? AND tenant_id = ?")->execute([$uploadResult['url'], $product_id, TENANT_ID]);
                }
            }

            // İlk stok girişi logla
            if ($stock_quantity > 0) {
                $db->prepare("INSERT INTO stock_movements (tenant_id, product_id, type, quantity, stock_before, stock_after, reason, reference_type, created_by) VALUES (?, ?, 'in', ?, 0, ?, 'İlk stok girişi', 'manual', ?)")
                   ->execute([TENANT_ID, $product_id, $stock_quantity, $stock_quantity, $_SESSION['user_id']]);
            }

            $response = ['success' => true, 'message' => 'Ürün eklendi.'];
        }

        // ---- ÜRÜN DÜZENLE ----
        elseif ($action === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            $name = trim(strip_tags($_POST['name'] ?? ''));
            $barcode = trim(strip_tags($_POST['barcode'] ?? '')) ?: null;
            $price = floatval($_POST['price'] ?? 0);
            $purchase_price = floatval($_POST['purchase_price'] ?? 0);
            $discount_type = in_array($_POST['discount_type'] ?? '', ['none','percent','fixed']) ? $_POST['discount_type'] : 'none';
            $discount_amount = floatval($_POST['discount_amount'] ?? 0);
            $stock_tracking = intval($_POST['stock_tracking'] ?? 0);
            $min_stock = intval($_POST['min_stock'] ?? 5);
            $is_sale = intval($_POST['is_sale'] ?? 0);
            $is_consumable = intval($_POST['is_consumable'] ?? 0);
            if (!$is_sale && !$is_consumable) $is_sale = 1;
            if ($is_sale && $is_consumable) $product_type = 'both';
            elseif ($is_consumable) $product_type = 'consumable';
            else $product_type = 'sale';
            $status = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
            $description = trim(strip_tags($_POST['description'] ?? ''));

            if (empty($name) || $id <= 0) throw new Exception('Geçersiz veri.');
            if ($purchase_price < 0) throw new Exception('Alış fiyatı negatif olamaz.');

            // Barkod benzersizlik (kendi ID'si hariç)
            if ($barcode) {
                $check = $db->prepare("SELECT id FROM products WHERE tenant_id = ? AND barcode = ? AND id != ? AND deleted_at IS NULL");
                $check->execute([TENANT_ID, $barcode, $id]);
                if ($check->fetch()) throw new Exception('Bu barkod başka bir üründe kullanılıyor.');
            }

            $stmt = $db->prepare("UPDATE products SET name=?, barcode=?, description=?, price=?, purchase_price=?, discount_type=?, discount_amount=?, stock_tracking=?, min_stock=?, product_type=?, status=? WHERE id=? AND tenant_id=?");
            $stmt->execute([$name, $barcode, $description ?: null, $price, $purchase_price, $discount_type, $discount_amount, $stock_tracking, $min_stock, $product_type, $status, $id, TENANT_ID]);

            // Ürün görseli yükle/güncelle
            if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadManager = new UploadManager($db, TENANT_ID);
                $uploadResult = $uploadManager->uploadFile($_FILES['image'], 'product', $id, 'products', $_SESSION['user_id']);
                if ($uploadResult['success']) {
                    $db->prepare("UPDATE products SET image = ? WHERE id = ? AND tenant_id = ?")->execute([$uploadResult['url'], $id, TENANT_ID]);
                }
            }
            // Görsel silme
            if (!empty($_POST['remove_image'])) {
                $db->prepare("UPDATE products SET image = NULL WHERE id = ? AND tenant_id = ?")->execute([$id, TENANT_ID]);
            }

            $response = ['success' => true, 'message' => 'Ürün güncellendi.'];
        }

        // ---- ÜRÜN SİL (soft-delete → çöp) ----
        elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Geçersiz ürün.');

            $db->prepare("UPDATE products SET deleted_at = NOW(), status = 'inactive' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL")
               ->execute([$id, TENANT_ID]);
            $response = ['success' => true, 'message' => 'Ürün çöp kutusuna taşındı.'];
        }

        // ---- STOK GÜNCELLE (giriş/çıkış) ----
        elseif ($action === 'stock_update') {
            $id = intval($_POST['product_id'] ?? 0);
            $type = in_array($_POST['movement_type'] ?? '', ['in','out','adjustment']) ? $_POST['movement_type'] : 'in';
            $qty = intval($_POST['quantity'] ?? 0);
            $new_quantity = isset($_POST['new_quantity']) ? intval($_POST['new_quantity']) : null;
            $reason = trim(strip_tags($_POST['reason'] ?? ''));

            if ($id <= 0 || ($qty <= 0 && $new_quantity === null)) throw new Exception('Geçersiz miktar.');

            $product = $db->prepare("SELECT id, stock_quantity FROM products WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL");
            $product->execute([$id, TENANT_ID]);
            $p = $product->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new Exception('Ürün bulunamadı.');

            $before = $p['stock_quantity'];
            if ($type === 'in') {
                $after = $before + $qty;
            } elseif ($type === 'out') {
                $after = $before - $qty;
                if ($after < 0) $after = 0;
            } else {
                // adjustment = direkt set (new_quantity varsa onu kullan)
                $after = ($new_quantity !== null) ? max(0, $new_quantity) : $qty;
            }

            $db->beginTransaction();
            $db->prepare("UPDATE products SET stock_quantity = ? WHERE id = ? AND tenant_id = ?")
               ->execute([$after, $id, TENANT_ID]);
            $movement_qty = ($type === 'adjustment') ? ($after - $before) : ($type === 'out' ? -$qty : $qty);
            $db->prepare("INSERT INTO stock_movements (tenant_id, product_id, type, quantity, stock_before, stock_after, reason, reference_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'manual', ?)")
               ->execute([TENANT_ID, $id, $type, $movement_qty, $before, $after, $reason ?: 'Manuel güncelleme', $_SESSION['user_id']]);
            $db->commit();

            $response = ['success' => true, 'message' => "Stok güncellendi: {$before} → {$after}", 'new_stock' => $after];
        }

        // ---- HIZLI FİYAT GÜNCELLE ----
        elseif ($action === 'quick_price') {
            $id = intval($_POST['id'] ?? 0);
            $price = floatval($_POST['price'] ?? 0);
            if ($id <= 0) throw new Exception('Geçersiz ürün.');

            $db->prepare("UPDATE products SET price = ? WHERE id = ? AND tenant_id = ?")
               ->execute([$price, $id, TENANT_ID]);
            $response = ['success' => true, 'message' => 'Fiyat güncellendi.'];
        }

        // ---- BARKOD İLE ÜRÜN BUL ----
        elseif ($action === 'find_barcode') {
            $barcode = trim(strip_tags($_POST['barcode'] ?? ''));
            if (empty($barcode)) throw new Exception('Barkod boş.');

            $stmt = $db->prepare("SELECT * FROM products WHERE tenant_id = ? AND barcode = ? AND deleted_at IS NULL");
            $stmt->execute([TENANT_ID, $barcode]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($found) {
                $response = ['success' => true, 'product' => $found];
            } else {
                $response = ['success' => false, 'message' => 'Bu barkodla ürün bulunamadı.'];
            }
        }

        // ---- OTOMATİK BARKOD ÜRET ----
        elseif ($action === 'generate_barcode') {
            $prefix = 'RND';
            $unique = $prefix . str_pad(TENANT_ID, 4, '0', STR_PAD_LEFT) . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            // Benzersizlik kontrolü
            $check = $db->prepare("SELECT id FROM products WHERE tenant_id = ? AND barcode = ? AND deleted_at IS NULL");
            $check->execute([TENANT_ID, $unique]);
            if ($check->fetch()) {
                $unique = $prefix . str_pad(TENANT_ID, 4, '0', STR_PAD_LEFT) . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            }
            $response = ['success' => true, 'barcode' => $unique];
        }

        // ---- STOK HAREKETLERİ ----
        elseif ($action === 'get_movements') {
            $id = intval($_POST['product_id'] ?? 0);
            $stmt = $db->prepare("SELECT sm.*, p.name as product_name FROM stock_movements sm JOIN products p ON p.id = sm.product_id WHERE sm.tenant_id = ? AND sm.product_id = ? ORDER BY sm.created_at DESC LIMIT 50");
            $stmt->execute([TENANT_ID, $id]);
            $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response = ['success' => true, 'movements' => $movements];
        }

        // ---- TEK ÜRÜN GETİR (AJAX refresh) ----
        elseif ($action === 'get_product') {
            $id = intval($_POST['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL");
            $stmt->execute([$id, TENANT_ID]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($p) $response = ['success' => true, 'product' => $p];
            else $response = ['success' => false, 'message' => 'Ürün bulunamadı.'];
        }

        // ---- İSTATİSTİKLER (AJAX refresh) ----
        elseif ($action === 'get_stats') {
            $st = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_count, SUM(CASE WHEN stock_tracking=1 AND stock_quantity <= min_stock AND stock_quantity > 0 THEN 1 ELSE 0 END) as low_stock, SUM(CASE WHEN stock_tracking=1 AND stock_quantity = 0 THEN 1 ELSE 0 END) as out_of_stock FROM products WHERE tenant_id = ? AND deleted_at IS NULL");
            $st->execute([TENANT_ID]);
            $response = ['success' => true, 'stats' => $st->fetch(PDO::FETCH_ASSOC)];
        }

    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit;
}

// ============================================
// VERİ YÜKLE
// ============================================
$search = trim($_GET['q'] ?? '');
$filter_type = $_GET['type'] ?? 'all';
$filter_stock = $_GET['stock'] ?? 'all';

$where = ["p.tenant_id = ?", "p.deleted_at IS NULL"];
$params = [TENANT_ID];

if (!empty($search)) {
    $where[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filter_type === 'sale') {
    $where[] = "p.product_type IN ('sale','both')";
} elseif ($filter_type === 'consumable') {
    $where[] = "p.product_type IN ('consumable','both')";
}
if ($filter_stock === 'low') {
    $where[] = "p.stock_tracking = 1 AND p.stock_quantity <= p.min_stock AND p.stock_quantity > 0";
} elseif ($filter_stock === 'out') {
    $where[] = "p.stock_tracking = 1 AND p.stock_quantity = 0";
}

$where_sql = implode(' AND ', $where);
$stmt = $db->prepare("SELECT p.* FROM products p WHERE {$where_sql} ORDER BY p.status ASC, p.name ASC");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// İstatistikler
$stats = $db->prepare("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN stock_tracking=1 AND stock_quantity <= min_stock AND stock_quantity > 0 THEN 1 ELSE 0 END) as low_stock,
    SUM(CASE WHEN stock_tracking=1 AND stock_quantity = 0 THEN 1 ELSE 0 END) as out_of_stock
    FROM products WHERE tenant_id = ? AND deleted_at IS NULL");
$stats->execute([TENANT_ID]);
$stat = $stats->fetch(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
/* Admin layout protection */
.admin-sidebar .menu-item { color: rgba(255,255,255,0.85); text-decoration: none; }
.admin-sidebar .menu-item:hover, .admin-sidebar .menu-item:focus { color: #fff; text-decoration: none; }
.admin-sidebar .menu-item.active { color: #fff; }
.admin-header { border-bottom: 1px solid var(--header-border); }

/* Bootstrap Modal Fix */
#productModal.modal, #stockModal.modal, #movementsModal.modal, #barcodeModal.modal, #fieldScanModal.modal, #printBarcodeModal.modal {
    visibility: visible !important; opacity: 1 !important; pointer-events: auto !important;
    background: transparent !important; backdrop-filter: none !important; transition: none !important;
}

/* ============================================ */
/* STATS CARDS - Neobrutalist KPI                */
/* ============================================ */
.stats-container {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}
.stats-container .card {
    border-radius: 20px;
    box-shadow: 5px 5px #323232;
    border: 3px solid #323232;
    padding: 1rem;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    background: white;
}
.stats-container .card:hover {
    transform: translateY(-2px);
    box-shadow: 6px 6px #323232;
}
.stats-container .card .title {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.stats-container .card .title span {
    width: 2rem;
    height: 2rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
    font-size: 0.85rem;
}
.stats-container .card .title-text {
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    margin: 0;
}
.stats-container .card .data p {
    font-size: 2.25rem;
    font-weight: 700;
    margin: 0.5rem 0 0.25rem;
    line-height: 1;
    color: #1f2937;
}
.stats-container .card .range {
    height: 0.5rem;
    background: #e5e7eb;
    border-radius: 9999px;
    overflow: hidden;
}
.stats-container .card .range .fill {
    height: 100%;
    border-radius: 9999px;
    transition: width 0.5s ease;
}

/* ============================================ */
/* DATA TABLE CONTAINER                          */
/* ============================================ */
.data-table {
    background: white;
    border-radius: 16px;
    box-shadow: 5px 5px 0 #323232;
    border: 3px solid #323232;
    overflow: hidden;
    width: 100%;
}
.table-header {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
}
.table-header-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.table-title {
    color: #2563eb;
    font-size: 1.3rem;
    font-weight: 700;
    margin: 0;
}
.table-title i { margin-right: 0.5rem; }

/* ============================================ */
/* BULK SELECT HEADER                            */
/* ============================================ */
.bulk-select-header {
    margin-bottom: 0.75rem;
    padding: 0.75rem 1rem;
    background: #f8fafc;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    border: 2px solid #e5e7eb;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    flex-wrap: wrap;
}
.search-box-inline {
    position: relative;
    flex: 1;
    min-width: 160px;
    max-width: 280px;
}
.search-box-inline input {
    width: 100%;
    padding: 0.5rem 0.75rem 0.5rem 2.2rem;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.85rem;
    background: white;
    color: #1f2937;
    outline: none;
    transition: border-color 0.2s;
}
.search-box-inline input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}
.search-box-inline i {
    position: absolute;
    left: 0.7rem;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 0.8rem;
}
.header-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.header-filters {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.header-filters select {
    padding: 0.5rem 0.75rem;
    border: 2px solid #323232;
    border-radius: 10px;
    font-size: 0.8rem;
    font-weight: 600;
    background: white;
    color: #1f2937;
    box-shadow: 3px 3px 0 #323232;
    cursor: pointer;
    outline: none;
}

/* ============================================ */
/* BUTTON STYLES (Neobrutalist)                  */
/* ============================================ */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    padding: 0.55rem 1rem;
    border: 2px solid #323232;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.85rem;
    box-shadow: 3px 3px 0 #323232;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    white-space: nowrap;
    color: white;
}
.btn:hover { transform: translateY(-2px); box-shadow: 5px 5px 0 #323232; }
.btn:active { transform: translateY(1px); box-shadow: 2px 2px 0 #323232; }
.btn-sm { padding: 0.45rem 0.85rem; font-size: 0.8rem; }

.btn-primary { background: linear-gradient(135deg, #3b82f6, #2563eb); }
.btn-success { background: linear-gradient(135deg, #10b981, #059669); }
.btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); }
.btn-warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
.btn-secondary { background: linear-gradient(135deg, #6b7280, #4b5563); }
.btn-info { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
.btn-light { background: white; color: #323232; }

/* Outline button overrides - modal/form buttons */
.btn-outline-primary { background: transparent; color: #2563eb; border-color: #2563eb; box-shadow: none; }
.btn-outline-primary:hover { background: #2563eb; color: white; box-shadow: none; }
.btn-outline-secondary { background: transparent; color: #6b7280; border-color: #6b7280; box-shadow: none; }
.btn-outline-secondary:hover { background: #6b7280; color: white; box-shadow: none; }
.btn-outline-danger { background: transparent; color: #dc2626; border-color: #dc2626; box-shadow: none; }
.btn-outline-danger:hover { background: #dc2626; color: white; box-shadow: none; }
.btn-outline-info { background: transparent; color: #0891b2; border-color: #0891b2; box-shadow: none; }
.btn-outline-info:hover { background: #0891b2; color: white; box-shadow: none; }

/* ============================================ */
/* PRODUCT LIST HEADER (Desktop)                 */
/* ============================================ */
.product-list-header {
    display: none;
    align-items: center;
    padding: 0 1.25rem;
    margin-bottom: 0.5rem;
    height: 48px;
    background: #3b82f6;
    color: white;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.header-info { flex: 3; }
.header-stats {
    flex: 4;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    text-align: center;
}
.header-actions { flex: 2; text-align: right; }

/* ============================================ */
/* PRODUCT CARD                                  */
/* ============================================ */
.product-card {
    background: white;
    border-radius: 16px;
    border: 3px solid #323232;
    margin-bottom: 0.75rem;
    box-shadow: 5px 5px 0 #323232;
    transition: all 0.3s ease;
    overflow: hidden;
    cursor: pointer;
}
.product-card:hover {
    transform: translateY(-3px);
    box-shadow: 7px 7px 0 #323232;
}
.product-card.inactive-card {
    opacity: 0.6;
}
.product-card .card-actions { cursor: default; }
.edit-barcode-preview {
    display: none;
    text-align: center;
    margin-top: 10px;
    padding: 12px 10px 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
}
.edit-barcode-preview.show { display: block; }
.edit-barcode-preview svg { max-width: 100%; height: auto; }
body.dark-mode .edit-barcode-preview {
    background: rgba(255,255,255,.04);
    border-color: rgba(255,255,255,.1);
}
.card-content {
    display: flex;
    flex-direction: column;
    padding: 1rem;
}
.product-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.product-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: white;
    font-size: 1.1rem;
}
.product-img-wrap {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    overflow: hidden;
    flex-shrink: 0;
}
.product-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.product-icon.type-consumable-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
.product-icon.type-both-icon { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }

.product-details { flex: 1; min-width: 0; }
.product-details h4 {
    font-size: 1rem;
    font-weight: 700;
    margin: 0 0 2px;
    color: #1f2937;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.product-details .product-barcode {
    font-size: 0.78rem;
    color: #94a3b8;
    font-family: 'Courier New', monospace;
}
.product-details .product-barcode i { margin-right: 3px; }

/* Badges */
.badge-type {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.badge-sale { background: rgba(59,130,246,0.1); color: #2563eb; }
.badge-consumable { background: rgba(245,158,11,0.1); color: #d97706; }
.badge-both { background: rgba(139,92,246,0.1); color: #7c3aed; }
.badge-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 600;
}
.badge-active { background: rgba(16,185,129,0.1); color: #059669; }
.badge-inactive { background: rgba(148,163,184,0.15); color: #64748b; }

/* Card Stats */
.card-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
    padding: 0.75rem 0;
    border-top: 1px solid #f3f4f6;
    border-bottom: 1px solid #f3f4f6;
    margin-top: 0.75rem;
}
.stat-item { text-align: center; }
.stat-item .value {
    font-size: 1.15rem;
    font-weight: 700;
    margin-bottom: 0.15rem;
}
.stat-item .label {
    font-size: 0.72rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}
.stat-item .value.price-val { color: #10b981; }
.stat-item .value.stock-val { color: #3b82f6; }
.stat-item .value.stock-low { color: #d97706; }
.stat-item .value.stock-out { color: #dc2626; }
.stat-item .value.stock-off { color: #94a3b8; }

.discount-tag {
    font-size: 0.7rem;
    font-weight: 700;
    background: rgba(239,68,68,0.1);
    color: #dc2626;
    padding: 1px 6px;
    border-radius: 4px;
    margin-left: 4px;
}
.price-original {
    text-decoration: line-through;
    opacity: 0.5;
    font-size: 0.8rem;
}

/* Stock inline control */
.stock-control {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.stock-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.82rem;
    font-weight: 600;
}
.stock-ok { background: rgba(16,185,129,0.1); color: #059669; }
.stock-low { background: rgba(245,158,11,0.1); color: #d97706; }
.stock-out { background: rgba(239,68,68,0.1); color: #dc2626; }
.stock-off { background: rgba(148,163,184,0.1); color: #64748b; }

.qs-btn {
    width: 28px; height: 28px; border-radius: 8px; border: 2px solid #323232;
    cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.85rem; font-weight: 700; transition: all 0.15s; box-shadow: 2px 2px 0 #323232;
}
.qs-plus { background: rgba(16,185,129,0.15); color: #059669; }
.qs-plus:hover { background: rgba(16,185,129,0.3); transform: translateY(-1px); box-shadow: 3px 3px 0 #323232; }
.qs-minus { background: rgba(239,68,68,0.15); color: #dc2626; }
.qs-minus:hover { background: rgba(239,68,68,0.3); transform: translateY(-1px); box-shadow: 3px 3px 0 #323232; }

/* Card Actions */
.card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.75rem;
}
.card-actions .btn {
    flex-grow: 1;
}

/* Empty State */
.prod-empty { text-align: center; padding: 60px 20px; color: #94a3b8; }
.prod-empty i { font-size: 3rem; opacity: 0.4; margin-bottom: 12px; }

/* Modal styles */
.modal-content { border-radius: 20px !important; border: 3px solid #323232 !important; box-shadow: 5px 5px #323232 !important; }
.modal-header { border-bottom: 1px solid #e5e7eb; padding: 16px 20px; }
.modal-body { padding: 20px; }
.modal-footer { border-top: 1px solid #e5e7eb; padding: 12px 20px; }
.form-control, .form-select {
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 0.6rem 0.75rem;
}
.form-control:focus, .form-select:focus {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1) !important;
}

/* Movements table */
.mv-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.mv-table th { padding: 8px 10px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b; background: #f8fafc; }
.mv-table td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; }
.mv-in { color: #059669; font-weight: 600; }
.mv-out { color: #dc2626; font-weight: 600; }
.mv-adj { color: #d97706; font-weight: 600; }

/* Barcode scanner area */
#barcode-reader { width: 100%; max-width: 400px; margin: 0 auto; }
#barcode-reader video { border-radius: 12px; }

/* ============================================ */
/* DARK MODE                                     */
/* ============================================ */
body.dark-mode .stats-container .card {
    background: #1e293b !important;
    border-color: #475569 !important;
    box-shadow: 5px 5px #475569 !important;
}
body.dark-mode .stats-container .card:hover { box-shadow: 6px 6px #475569 !important; }
body.dark-mode .stats-container .card .data p { color: #f1f5f9 !important; }
body.dark-mode .stats-container .card .title-text { color: #94a3b8 !important; }
body.dark-mode .stats-container .card .range { background: #334155 !important; }

body.dark-mode .data-table {
    background: #1e293b !important;
    border-color: #475569 !important;
    box-shadow: 5px 5px 0 #475569 !important;
}
body.dark-mode .table-title { color: #60a5fa !important; }

body.dark-mode .bulk-select-header {
    background: #0f172a !important;
    border-color: #334155 !important;
}
body.dark-mode .search-box-inline input {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f1f5f9 !important;
}
body.dark-mode .header-filters select {
    background: #1e293b !important;
    border-color: #475569 !important;
    color: #f1f5f9 !important;
    box-shadow: 3px 3px 0 #475569 !important;
}

body.dark-mode .product-list-header {
    background: #1e40af !important;
}
body.dark-mode .product-card {
    background: #1e293b !important;
    border-color: #475569 !important;
    box-shadow: 5px 5px 0 #475569 !important;
}
body.dark-mode .product-card:hover { box-shadow: 7px 7px 0 #475569 !important; }
body.dark-mode .product-details h4 { color: #f1f5f9 !important; }
body.dark-mode .product-details .product-barcode { color: #64748b !important; }
body.dark-mode .card-stats { border-color: #334155 !important; }
body.dark-mode .stat-item .label { color: #94a3b8 !important; }
body.dark-mode .card-actions .btn { border-color: #475569 !important; box-shadow: 3px 3px 0 #475569 !important; }
body.dark-mode .card-actions .btn:hover { box-shadow: 5px 5px 0 #475569 !important; }

body.dark-mode .qs-btn { border-color: #475569 !important; box-shadow: 2px 2px 0 #475569 !important; }
body.dark-mode .qs-btn:hover { box-shadow: 3px 3px 0 #475569 !important; }
body.dark-mode .inline-stock-input { background: #0f172a !important; color: #f1f5f9 !important; border-color: #60a5fa !important; }

body.dark-mode .modal-content { background: #1e293b !important; color: #f1f5f9 !important; border-color: #475569 !important; box-shadow: 5px 5px #475569 !important; }
body.dark-mode .modal-header { border-color: #334155 !important; }
body.dark-mode .modal-footer { border-color: #334155 !important; }
body.dark-mode .form-control, body.dark-mode .form-select { background: #0f172a !important; border-color: #334155 !important; color: #f1f5f9 !important; }
body.dark-mode .btn-outline-primary { color: #60a5fa; border-color: #60a5fa; }
body.dark-mode .btn-outline-primary:hover { background: #2563eb; color: white; }
body.dark-mode .btn-outline-secondary { color: #94a3b8; border-color: #64748b; }
body.dark-mode .btn-outline-secondary:hover { background: #64748b; color: white; }
body.dark-mode .btn-outline-info { color: #22d3ee; border-color: #22d3ee; }
body.dark-mode .btn-outline-info:hover { background: #0891b2; color: white; }

body.dark-mode .mv-table th { background: #0f172a !important; color: #94a3b8 !important; }
body.dark-mode .mv-table td { border-color: #334155 !important; color: #f1f5f9 !important; }

body.dark-mode .prod-empty { color: #64748b !important; }

/* Quick Actions Popup Dark Mode */
body.dark-mode .quick-action-popup { background: #1e293b !important; color: #f1f5f9 !important; border: 2px solid #475569 !important; }
body.dark-mode .quick-action-popup .qa-name { color: #f1f5f9 !important; }
body.dark-mode .quick-action-popup .qa-price-label,
body.dark-mode .quick-action-popup .qa-stock-label,
body.dark-mode .quick-action-popup .qa-section-title { color: #94a3b8 !important; }
body.dark-mode .quick-action-popup .qa-price-val { color: #f1f5f9 !important; }
body.dark-mode .quick-action-popup .swal2-close { color: #94a3b8 !important; }
body.dark-mode .quick-action-popup .swal2-close:hover { color: #f1f5f9 !important; }
body.dark-mode .quick-action-popup div[style*="border-bottom"] { border-color: #334155 !important; }
/* SweetAlert2 dark mode overrides for all modals */
body.dark-mode .swal2-popup { background: #1e293b !important; color: #f1f5f9 !important; }
body.dark-mode .swal2-title { color: #f1f5f9 !important; }
body.dark-mode .swal2-html-container { color: #cbd5e1 !important; }
body.dark-mode .swal2-input { background: #0f172a !important; border-color: #475569 !important; color: #f1f5f9 !important; }
body.dark-mode .swal2-validation-message { background: #312e81 !important; color: #c7d2fe !important; }

/* ============================================ */
/* RESPONSIVE                                    */
/* ============================================ */
@media (min-width: 1024px) {
    .product-list-header { display: flex; }
    .card-content {
        flex-direction: row;
        align-items: center;
        gap: 1.5rem;
    }
    .product-info { flex: 3; }
    .card-stats {
        flex: 4;
        border: none;
        margin: 0;
        padding: 0;
    }
    .card-actions {
        flex: 2;
        margin: 0;
        justify-content: flex-end;
    }
    .card-actions .btn { flex-grow: 0; }
}

@media (max-width: 1024px) {
    .stats-container {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .stats-container .card {
        padding: 0.75rem;
        box-shadow: 4px 4px #323232;
        border: 2px solid #323232;
    }
    .header-buttons .btn,
    .bulk-select-header .btn {
        padding: 0.45rem 0.7rem !important;
        font-size: 0.75rem !important;
    }
}

@media (max-width: 768px) {
    .admin-main { padding-top: 0.5rem !important; padding-left: 0.5rem !important; padding-right: 0.5rem !important; }
    .bulk-select-header { flex-direction: column; align-items: stretch; }
    .search-box-inline { max-width: 100%; }
    .header-buttons { justify-content: center; }
    .header-filters { justify-content: center; }
    .card-stats { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 480px) {
    .stats-container { gap: 0.5rem; }
    .stats-container .card { padding: 0.5rem; }
    .stats-container .card .title-text { font-size: 0.75rem; }
    .stats-container .card .data p { font-size: 1.5rem; }
    .product-details h4 { font-size: 0.9rem; }
    .stat-item .value { font-size: 0.95rem; }
    .stat-item .label { font-size: 0.65rem; }
    .card-actions .btn {
        padding: 0.5rem 0.75rem !important;
        font-size: 0.75rem !important;
    }
}

</style>

<div class="admin-main">
    <!-- İstatistikler -->
    <div class="stats-container">
        <div class="card">
            <div class="title">
                <span style="background-color: #3b82f6;"><i class="bi bi-box-seam"></i></span>
                <p class="title-text">Toplam Ürün</p>
            </div>
            <div class="data">
                <p><?= $stat['total'] ?? 0 ?></p>
                <div class="range"><div class="fill" style="width: 100%; background-color: #3b82f6;"></div></div>
            </div>
        </div>
        <div class="card">
            <div class="title">
                <span style="background-color: #10b981;"><i class="bi bi-check-circle"></i></span>
                <p class="title-text">Aktif Ürün</p>
            </div>
            <div class="data">
                <p><?= $stat['active_count'] ?? 0 ?></p>
                <div class="range"><div class="fill" style="width: <?= ($stat['total'] ?? 0) > 0 ? round((($stat['active_count'] ?? 0) / $stat['total']) * 100) : 0 ?>%; background-color: #10b981;"></div></div>
            </div>
        </div>
        <div class="card">
            <div class="title">
                <span style="background-color: #f59e0b;"><i class="bi bi-exclamation-triangle"></i></span>
                <p class="title-text">Düşük Stok</p>
            </div>
            <div class="data">
                <p><?= $stat['low_stock'] ?? 0 ?></p>
                <div class="range"><div class="fill" style="width: <?= ($stat['total'] ?? 0) > 0 ? round((($stat['low_stock'] ?? 0) / $stat['total']) * 100) : 0 ?>%; background-color: #f59e0b;"></div></div>
            </div>
        </div>
        <div class="card">
            <div class="title">
                <span style="background-color: #ef4444;"><i class="bi bi-x-circle"></i></span>
                <p class="title-text">Tükenen</p>
            </div>
            <div class="data">
                <p><?= $stat['out_of_stock'] ?? 0 ?></p>
                <div class="range"><div class="fill" style="width: <?= ($stat['total'] ?? 0) > 0 ? round((($stat['out_of_stock'] ?? 0) / $stat['total']) * 100) : 0 ?>%; background-color: #ef4444;"></div></div>
            </div>
        </div>
    </div>

    <!-- Ürün Listesi -->
    <div class="data-table">
        <div class="table-header">
            <div class="table-header-top">
                <h3 class="table-title"><i class="bi bi-box-seam"></i> Ürün Listesi</h3>
            </div>
        </div>

        <?php if (!empty($products)): ?>
        <div style="padding: 0 0.75rem 0.75rem;">
            <div class="bulk-select-header">
                <div class="search-box-inline">
                    <i class="bi bi-search"></i>
                    <input type="text" id="searchInput" placeholder="Ürün veya barkod ara..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="header-filters">
                    <select id="filterType" onchange="applyFilters()">
                        <option value="all" <?= $filter_type==='all'?'selected':'' ?>>Tüm Tipler</option>
                        <option value="sale" <?= $filter_type==='sale'?'selected':'' ?>>Satılık</option>
                        <option value="consumable" <?= $filter_type==='consumable'?'selected':'' ?>>Sarf</option>
                    </select>
                    <select id="filterStock" onchange="applyFilters()">
                        <option value="all" <?= $filter_stock==='all'?'selected':'' ?>>Tüm Stok</option>
                        <option value="low" <?= $filter_stock==='low'?'selected':'' ?>>Düşük</option>
                        <option value="out" <?= $filter_stock==='out'?'selected':'' ?>>Tükenen</option>
                    </select>
                </div>
                <div class="header-buttons">
                    <button class="btn btn-info btn-sm" onclick="openBarcodeScanner()"><i class="bi bi-upc-scan"></i> <span class="d-none d-sm-inline">Barkod</span></button>
                    <button class="btn btn-success btn-sm" onclick="openAddModal()"><i class="bi bi-plus-lg"></i> <span class="d-none d-sm-inline">Yeni Ürün</span></button>
                </div>
            </div>

            <!-- Desktop Header -->
            <div class="product-list-header d-none d-lg-flex">
                <div class="header-info">Ürün</div>
                <div class="header-stats"><div>Fiyat</div><div>Stok</div><div>Tip</div></div>
                <div class="header-actions">İşlemler</div>
            </div>

            <!-- Product Cards -->
            <?php foreach ($products as $p):
                $has_discount = $p['discount_type'] !== 'none' && $p['discount_amount'] > 0;
                $final_price = $p['price'];
                if ($has_discount) {
                    if ($p['discount_type'] === 'percent') {
                        $final_price = $p['price'] * (1 - $p['discount_amount'] / 100);
                    } else {
                        $final_price = $p['price'] - $p['discount_amount'];
                    }
                    if ($final_price < 0) $final_price = 0;
                }
                $stock_class = 'stock-ok'; $stock_val_class = 'stock-val';
                if (!$p['stock_tracking']) { $stock_class = 'stock-off'; $stock_val_class = 'stock-off'; }
                elseif ($p['stock_quantity'] <= 0) { $stock_class = 'stock-out'; $stock_val_class = 'stock-out'; }
                elseif ($p['stock_quantity'] <= $p['min_stock']) { $stock_class = 'stock-low'; $stock_val_class = 'stock-low'; }

                $icon_class = 'product-icon';
                if ($p['product_type'] === 'consumable') $icon_class .= ' type-consumable-icon';
                elseif ($p['product_type'] === 'both') $icon_class .= ' type-both-icon';
            ?>
            <div class="product-card <?= $p['status'] !== 'active' ? 'inactive-card' : '' ?>" data-id="<?= $p['id'] ?>" onclick="showQuickActions(allProducts.find(x=>x.id==<?= $p['id'] ?>))" title="Hızlı işlemler / barkod">
                <div class="card-content">
                    <!-- Ürün Bilgisi -->
                    <div class="product-info">
                        <?php if (!empty($p['image'])): ?>
                        <div class="product-img-wrap">
                            <img src="<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" class="product-img" onerror="this.parentElement.outerHTML='<div class=<?= $icon_class ?>><i class=\'bi <?= $p['product_type'] === 'consumable' ? 'bi-droplet-half' : ($p['product_type'] === 'both' ? 'bi-box-seam' : 'bi-cart') ?>\'></i></div>'">
                        </div>
                        <?php else: ?>
                        <div class="<?= $icon_class ?>">
                            <i class="bi <?= $p['product_type'] === 'consumable' ? 'bi-droplet-half' : ($p['product_type'] === 'both' ? 'bi-box-seam' : 'bi-cart') ?>"></i>
                        </div>
                        <?php endif; ?>
                        <div class="product-details">
                            <h4>
                                <?= htmlspecialchars($p['name']) ?>
                                <?php if ($p['status'] !== 'active'): ?>
                                    <span class="badge-status badge-inactive"><i class="bi bi-pause-circle"></i> Pasif</span>
                                <?php endif; ?>
                            </h4>
                            <?php if ($p['barcode']): ?>
                                <div class="product-barcode"><i class="bi bi-upc"></i><?= htmlspecialchars($p['barcode']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- İstatistikler -->
                    <div class="card-stats">
                        <div class="stat-item">
                            <div class="value price-val">
                                <?php if ($has_discount): ?>
                                    <span class="price-original"><?= number_format($p['price'], 2, ',', '.') ?></span>
                                    <?= number_format($final_price, 2, ',', '.') ?> ₺
                                    <span class="discount-tag"><?= $p['discount_type']==='percent' ? '%'.$p['discount_amount'] : $p['discount_amount'].'₺' ?></span>
                                <?php else: ?>
                                    <?= number_format($p['price'], 2, ',', '.') ?> ₺
                                <?php endif; ?>
                            </div>
                            <div class="label">Fiyat</div>
                        </div>
                        <?php if ($p['purchase_price'] > 0): ?>
                        <div class="stat-item">
                            <div class="value" style="color: #8b5cf6; font-size: 0.85rem;"><?= number_format($final_price - $p['purchase_price'], 2, ',', '.') ?> ₺</div>
                            <div class="label">Kâr</div>
                        </div>
                        <?php endif; ?>
                        <div class="stat-item">
                            <div class="value <?= $stock_val_class ?>">
                                <?php if ($p['stock_tracking']): ?>
                                    <div class="stock-control">
                                        <button class="qs-btn qs-minus" onclick="event.stopPropagation();quickStock(<?= $p['id'] ?>, 'out')" title="Stok düş"><i class="bi bi-dash"></i></button>
                                        <span class="stock-badge <?= $stock_class ?>" onclick="event.stopPropagation();inlineStockEdit(this, <?= $p['id'] ?>, <?= $p['stock_quantity'] ?>)" title="Tıkla ve değiştir" style="cursor:pointer;"><?= $p['stock_quantity'] ?></span>
                                        <button class="qs-btn qs-plus" onclick="event.stopPropagation();quickStock(<?= $p['id'] ?>, 'in')" title="Stok ekle"><i class="bi bi-plus"></i></button>
                                    </div>
                                <?php else: ?>
                                    <span class="stock-badge stock-off"><i class="bi bi-dash-circle"></i> Kapalı</span>
                                <?php endif; ?>
                            </div>
                            <div class="label">Stok</div>
                        </div>
                        <div class="stat-item">
                            <div class="value">
                                <?php if ($p['product_type'] === 'both'): ?>
                                    <span class="badge-type badge-both">Satılık + Sarf</span>
                                <?php elseif ($p['product_type'] === 'sale'): ?>
                                    <span class="badge-type badge-sale">Satılık</span>
                                <?php else: ?>
                                    <span class="badge-type badge-consumable">Sarf</span>
                                <?php endif; ?>
                            </div>
                            <div class="label">Tip</div>
                        </div>
                    </div>

                    <!-- İşlem Butonları -->
                    <div class="card-actions" onclick="event.stopPropagation()">
                        <button class="btn btn-warning btn-sm" onclick="openEditModal(<?= $p['id'] ?>)"><i class="bi bi-pencil"></i> <span class="d-lg-none">Düzenle</span></button>
                        <button class="btn btn-secondary btn-sm" onclick="openStockModal(<?= $p['id'] ?>)"><i class="bi bi-box-arrow-in-down"></i> <span class="d-lg-none">Stok</span></button>
                        <button class="btn btn-info btn-sm" onclick="showMovements(<?= $p['id'] ?>)"><i class="bi bi-clock-history"></i> <span class="d-lg-none">Hareketler</span></button>
                        <?php if ($p['barcode']): ?>
                        <button class="btn btn-primary btn-sm" onclick="printBarcode(<?= $p['id'] ?>)"><i class="bi bi-printer"></i> <span class="d-lg-none">Barkod</span></button>
                        <?php endif; ?>
                        <button class="btn btn-outline-info btn-sm" onclick="showQuickActions(allProducts.find(x=>x.id==<?= $p['id'] ?>))" title="Hızlı İşlemler"><i class="bi bi-lightning"></i> <span class="d-lg-none">Hızlı</span></button>
                        <button class="btn btn-danger btn-sm" onclick="deleteProduct(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')"><i class="bi bi-trash3"></i> <span class="d-lg-none">Sil</span></button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="prod-empty">
            <i class="bi bi-box-seam d-block"></i>
            <p>Henüz ürün eklenmemiş.</p>
            <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; margin-top: 1rem;">
                <button class="btn btn-info" onclick="openBarcodeScanner()"><i class="bi bi-upc-scan"></i> Barkod Oku</button>
                <button class="btn btn-success" onclick="openAddModal()"><i class="bi bi-plus-lg"></i> Yeni Ürün Ekle</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Ürün Ekle/Düzenle Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="productModalTitle"><i class="bi bi-plus-circle me-2"></i>Yeni Ürün</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="productForm" method="post" onsubmit="return false;" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="formId" value="">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Ürün Adı *</label>
                            <input type="text" name="name" id="fName" class="form-control" required placeholder="Örn: Saç Bakım Kremi">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Ürün Tipi</label>
                            <div class="d-flex flex-column gap-1 mt-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="fIsSale" name="is_sale" value="1" checked>
                                    <label class="form-check-label" for="fIsSale" style="font-size:0.88rem;"><i class="bi bi-cart me-1" style="color:#2563eb;"></i>Satılık</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="fIsConsumable" name="is_consumable" value="1">
                                    <label class="form-check-label" for="fIsConsumable" style="font-size:0.88rem;"><i class="bi bi-droplet me-1" style="color:#d97706;"></i>Sarf Malzeme</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Barkod</label>
                            <div class="input-group">
                                <input type="text" name="barcode" id="fBarcode" class="form-control" placeholder="Barkod numarası" oninput="updateEditBarcodePreview(this.value)">
                                <button type="button" class="btn btn-outline-primary" onclick="scanBarcodeToField()" title="Kameradan barkod oku"><i class="bi bi-camera"></i></button>
                                <button type="button" class="btn btn-outline-secondary" onclick="generateBarcode()" title="Otomatik barkod üret"><i class="bi bi-magic"></i></button>
                            </div>
                            <div id="editBarcodePreview" class="edit-barcode-preview">
                                <svg id="editBarcodeSvg"></svg>
                                <div id="editBarcodeValue" style="font-family:'Courier New',monospace;font-size:.78rem;color:#64748b;margin-top:4px;"></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Satış Fiyatı (₺) *</label>
                            <input type="number" name="price" id="fPrice" class="form-control" step="0.01" min="0" required placeholder="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Alış Fiyatı (₺)</label>
                            <input type="number" name="purchase_price" id="fPurchasePrice" class="form-control" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">İndirim Tipi</label>
                            <select name="discount_type" id="fDiscountType" class="form-select" onchange="toggleDiscount()">
                                <option value="none">İndirim Yok</option>
                                <option value="percent">Yüzde (%)</option>
                                <option value="fixed">Sabit Tutar (₺)</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="discountAmountWrap" style="display: none;">
                            <label class="form-label fw-semibold">İndirim Miktarı</label>
                            <input type="number" name="discount_amount" id="fDiscountAmount" class="form-control" step="0.01" min="0" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Durum</label>
                            <select name="status" id="fStatus" class="form-select">
                                <option value="active">Aktif</option>
                                <option value="inactive">Pasif</option>
                            </select>
                        </div>
                        <div class="col-12"><hr class="my-1"></div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="stock_tracking" id="fStockTracking" checked>
                                <label class="form-check-label fw-semibold" for="fStockTracking">Stok Takibi</label>
                            </div>
                        </div>
                        <div class="col-md-4" id="stockQtyWrap">
                            <label class="form-label fw-semibold">Başlangıç Stok</label>
                            <input type="number" name="stock_quantity" id="fStockQty" class="form-control" min="0" value="0">
                        </div>
                        <div class="col-md-4" id="minStockWrap">
                            <label class="form-label fw-semibold">Minimum Stok</label>
                            <input type="number" name="min_stock" id="fMinStock" class="form-control" min="0" value="5">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Ürün Görseli</label>
                            <div id="imagePreviewWrap" style="display:none; margin-bottom:0.5rem; position:relative; width:fit-content;">
                                <img id="imagePreview" src="" alt="Önizleme" style="max-height:120px; border-radius:10px; border:2px solid #e2e8f0;">
                                <button type="button" onclick="removeProductImage()" class="btn btn-sm btn-danger" style="position:absolute; top:-8px; right:-8px; border-radius:50%; width:24px; height:24px; padding:0; font-size:0.7rem; line-height:1;"><i class="bi bi-x"></i></button>
                            </div>
                            <input type="file" name="image" id="fImage" class="form-control" accept="image/jpeg,image/png,image/webp" onchange="previewProductImage(this)">
                            <input type="hidden" name="remove_image" id="fRemoveImage" value="">
                            <div class="form-text">JPG, PNG veya WebP. Maks 5 MB.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Açıklama</label>
                            <textarea name="description" id="fDesc" class="form-control" rows="2" placeholder="Opsiyonel açıklama..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary fw-bold" id="formSubmitBtn"><i class="bi bi-check-lg me-1"></i>Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Stok İşlemi Modal -->
<div class="modal fade" id="stockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-box-arrow-in-down me-2"></i>Stok İşlemi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="stockForm" method="post" onsubmit="return false;">
                <div class="modal-body">
                    <input type="hidden" name="action" value="stock_update">
                    <input type="hidden" name="product_id" id="sProductId">
                    <p class="fw-bold mb-3" id="sProductName"></p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">İşlem Tipi</label>
                        <select name="movement_type" id="sType" class="form-select">
                            <option value="in">📥 Stok Giriş</option>
                            <option value="out">📤 Stok Çıkış</option>
                            <option value="adjustment">🔧 Stok Düzeltme (Yeni miktar)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Miktar</label>
                        <input type="number" name="quantity" id="sQty" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Açıklama</label>
                        <input type="text" name="reason" id="sReason" class="form-control" placeholder="Örn: Tedarikçiden alım, Müşteriye satış...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-check-lg me-1"></i>Uygula</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Stok Hareketleri Modal -->
<div class="modal fade" id="movementsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-clock-history me-2"></i>Stok Hareketleri</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="movementsBody">
                <p class="text-center text-muted">Yükleniyor...</p>
            </div>
        </div>
    </div>
</div>

<!-- Barkod Okuyucu Modal -->
<div class="modal fade" id="barcodeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-upc-scan me-2"></i>Barkod Tara</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="stopBarcodeScanner()"></button>
            </div>
            <div class="modal-body text-center">
                <div id="barcode-reader"></div>
                <div class="mt-3">
                    <div class="input-group">
                        <input type="text" id="manualBarcode" class="form-control" placeholder="veya barkodu elle girin...">
                        <button class="btn btn-primary" onclick="searchBarcode(document.getElementById('manualBarcode').value)">Ara</button>
                    </div>
                </div>
                <div id="barcodeResult" class="mt-3"></div>
            </div>
        </div>
    </div>
</div>

<!-- Barkod Yazdır Modal -->
<div class="modal fade" id="printBarcodeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-printer me-2"></i>Barkod Etiketi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="barcodeLabelPreview" style="border: 2px dashed var(--border-color, #e2e8f0); border-radius: 12px; padding: 20px; margin-bottom: 16px;">
                    <p class="fw-bold mb-2" id="labelProductName" style="font-size: 1rem;"></p>
                    <svg id="labelBarcodeSvg"></svg>
                    <p class="mt-1 mb-0" id="labelPrice" style="font-size: 0.85rem; color: var(--text-muted, #64748b);"></p>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold" style="font-size:0.82rem;">Etiket Sayısı</label>
                        <input type="number" id="printCount" class="form-control" min="1" max="100" value="1">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold" style="font-size:0.82rem;">Etiket Boyutu</label>
                        <select id="printSize" class="form-select" onchange="updateBarcodePreview()">
                            <option value="small">Küçük (50x25mm)</option>
                            <option value="medium" selected>Orta (60x30mm)</option>
                            <option value="large">Büyük (80x40mm)</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary fw-bold" onclick="doPrintBarcode()"><i class="bi bi-printer me-1"></i>Yazdır</button>
            </div>
        </div>
    </div>
</div>

<!-- Barkod Okuyucu (Alan İçi) Modal -->
<div class="modal fade" id="fieldScanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-camera me-2"></i>Barkod Oku</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="stopFieldScanner()"></button>
            </div>
            <div class="modal-body text-center">
                <p class="text-muted mb-2" style="font-size:0.85rem;">Ürün barkodunu kameraya gösterin</p>
                <div id="field-barcode-reader"></div>
                <div id="fieldScanResult" class="mt-3"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>

<script>
const allProducts = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' ?>;
let barcodeScanner = null;

// ============================================
// AJAX HELPERS (sayfa yenilemeden güncelleme)
// ============================================
function _refreshProduct(productId) {
    const fd = new FormData();
    fd.append('action', 'get_product');
    fd.append('id', productId);
    return fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.product) {
                const p = data.product;
                // allProducts dizisini güncelle
                const idx = allProducts.findIndex(x => x.id == productId);
                if (idx >= 0) allProducts[idx] = p;
                else allProducts.push(p);
                // Kartı güncelle
                _updateProductCard(p);
            }
            return data;
        });
}

function _refreshStats() {
    const fd = new FormData();
    fd.append('action', 'get_stats');
    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.stats) {
                const s = data.stats;
                const cards = document.querySelectorAll('.stats-container .card');
                if (cards[0]) cards[0].querySelector('.data p').textContent = s.total ?? 0;
                if (cards[1]) cards[1].querySelector('.data p').textContent = s.active_count ?? 0;
                if (cards[2]) cards[2].querySelector('.data p').textContent = s.low_stock ?? 0;
                if (cards[3]) cards[3].querySelector('.data p').textContent = s.out_of_stock ?? 0;
                // Progress bar güncelle
                const total = parseInt(s.total) || 1;
                if (cards[1]) cards[1].querySelector('.fill').style.width = Math.round((parseInt(s.active_count)||0)/total*100)+'%';
                if (cards[2]) cards[2].querySelector('.fill').style.width = Math.round((parseInt(s.low_stock)||0)/total*100)+'%';
                if (cards[3]) cards[3].querySelector('.fill').style.width = Math.round((parseInt(s.out_of_stock)||0)/total*100)+'%';
            }
        });
}

function _updateProductCard(p) {
    const card = document.querySelector(`.product-card[data-id="${p.id}"]`);
    if (!card) return;

    // Görsel güncelle
    const infoDiv = card.querySelector('.product-info');
    const oldIcon = infoDiv?.querySelector('.product-icon, .product-img-wrap');
    if (oldIcon && p.image) {
        const wrap = document.createElement('div');
        wrap.className = 'product-img-wrap';
        wrap.innerHTML = `<img src="${_escHtml(p.image)}" alt="" class="product-img">`;
        oldIcon.replaceWith(wrap);
    } else if (oldIcon && !p.image) {
        const iconType = p.product_type === 'consumable' ? 'bi-droplet-half' : (p.product_type === 'both' ? 'bi-box-seam' : 'bi-cart');
        const iconClass = 'product-icon' + (p.product_type === 'consumable' ? ' type-consumable-icon' : p.product_type === 'both' ? ' type-both-icon' : '');
        const div = document.createElement('div');
        div.className = iconClass;
        div.innerHTML = `<i class="bi ${iconType}"></i>`;
        oldIcon.replaceWith(div);
    }

    // Fiyat hesapla
    const price = parseFloat(p.price);
    let finalPrice = price;
    let hasDiscount = p.discount_type !== 'none' && p.discount_amount > 0;
    if (hasDiscount) {
        finalPrice = p.discount_type === 'percent' ? price * (1 - p.discount_amount / 100) : price - p.discount_amount;
        if (finalPrice < 0) finalPrice = 0;
    }

    // Fiyat güncelle
    const priceEl = card.querySelector('.price-val');
    if (priceEl) {
        if (hasDiscount) {
            priceEl.innerHTML = `<span class="price-original">${_fmt(price)}</span> ${_fmt(finalPrice)} ₺ <span class="discount-tag">${p.discount_type === 'percent' ? '%'+p.discount_amount : p.discount_amount+'₺'}</span>`;
        } else {
            priceEl.innerHTML = `${_fmt(price)} ₺`;
        }
    }

    // Kâr güncelle
    const purchasePrice = parseFloat(p.purchase_price) || 0;
    const profitItems = card.querySelectorAll('.stat-item');
    let profitEl = null;
    profitItems.forEach(si => { if (si.querySelector('.label')?.textContent === 'Kâr') profitEl = si; });
    if (purchasePrice > 0) {
        const profit = finalPrice - purchasePrice;
        if (profitEl) {
            profitEl.querySelector('.value').textContent = _fmt(profit) + ' ₺';
        } else {
            // Fiyat stat-item'ın hemen sonrasına ekle
            const priceStatItem = priceEl?.closest('.stat-item');
            if (priceStatItem) {
                const newStat = document.createElement('div');
                newStat.className = 'stat-item';
                newStat.innerHTML = `<div class="value" style="color: #8b5cf6; font-size: 0.85rem;">${_fmt(profit)} ₺</div><div class="label">Kâr</div>`;
                priceStatItem.after(newStat);
            }
        }
    } else if (profitEl) {
        profitEl.remove();
    }

    // Stok güncelle
    const stockBadge = card.querySelector('.stock-badge');
    if (stockBadge && p.stock_tracking == 1) {
        stockBadge.textContent = p.stock_quantity;
        stockBadge.className = 'stock-badge';
        stockBadge.setAttribute('onclick', `event.stopPropagation();inlineStockEdit(this, ${p.id}, ${p.stock_quantity})`);
        if (p.stock_quantity <= 0) stockBadge.classList.add('stock-out');
        else if (p.stock_quantity <= p.min_stock) stockBadge.classList.add('stock-low');
        else stockBadge.classList.add('stock-ok');
    }

    // İsim güncelle
    const nameEl = card.querySelector('.product-details h4');
    if (nameEl) {
        let nameHtml = _escHtml(p.name);
        if (p.status !== 'active') nameHtml += ' <span class="badge-status badge-inactive"><i class="bi bi-pause-circle"></i> Pasif</span>';
        nameEl.innerHTML = nameHtml;
    }

    // Barkod güncelle
    const bcEl = card.querySelector('.product-barcode');
    if (bcEl && p.barcode) {
        bcEl.innerHTML = '<i class="bi bi-upc"></i>' + _escHtml(p.barcode);
    }

    // Tip güncelle
    const typeBadges = card.querySelectorAll('.badge-type');
    typeBadges.forEach(b => {
        if (p.product_type === 'both') { b.className = 'badge-type badge-both'; b.textContent = 'Satılık + Sarf'; }
        else if (p.product_type === 'sale') { b.className = 'badge-type badge-sale'; b.textContent = 'Satılık'; }
        else { b.className = 'badge-type badge-consumable'; b.textContent = 'Sarf'; }
    });

    // Inactive card class
    if (p.status !== 'active') card.classList.add('inactive-card');
    else card.classList.remove('inactive-card');
}

function _removeProductCard(productId) {
    const card = document.querySelector(`.product-card[data-id="${productId}"]`);
    if (card) {
        card.style.transition = 'opacity 0.3s, transform 0.3s';
        card.style.opacity = '0';
        card.style.transform = 'scale(0.95)';
        setTimeout(() => card.remove(), 300);
    }
    const idx = allProducts.findIndex(x => x.id == productId);
    if (idx >= 0) allProducts.splice(idx, 1);
}

function _fmt(n) { return parseFloat(n).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function _escHtml(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

function _notify(msg, type = 'success') {
    const toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 2000, timerProgressBar: true });
    toast.fire({ icon: type, title: msg });
}

// ============================================
// FİLTRE & ARAMA
// ============================================
let searchTimeout;
document.getElementById('searchInput')?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 400);
});

function applyFilters() {
    const q = document.getElementById('searchInput').value;
    const type = document.getElementById('filterType').value;
    const stock = document.getElementById('filterStock').value;
    window.location.href = `products.php?q=${encodeURIComponent(q)}&type=${type}&stock=${stock}`;
}

// ============================================
// ÜRÜN EKLE/DÜZENLE
// ============================================
function openAddModal() {
    document.getElementById('productModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Yeni Ürün';
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('productForm').reset();
    document.getElementById('fStockTracking').checked = true;
    document.getElementById('fIsSale').checked = true;
    document.getElementById('fIsConsumable').checked = false;
    document.getElementById('fStatus').closest('.col-md-4').style.display = 'none';
    document.getElementById('stockQtyWrap').style.display = '';
    document.getElementById('imagePreviewWrap').style.display = 'none';
    document.getElementById('fRemoveImage').value = '';
    toggleDiscount();
    updateEditBarcodePreview('');
    new bootstrap.Modal(document.getElementById('productModal')).show();
}

function openEditModal(id) {
    const p = allProducts.find(x => x.id == id);
    if (!p) return;
    document.getElementById('productModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Ürün Düzenle';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = p.id;
    document.getElementById('fName').value = p.name;
    document.getElementById('fBarcode').value = p.barcode || '';
    document.getElementById('fPrice').value = p.price;
    document.getElementById('fPurchasePrice').value = p.purchase_price || '0';
    document.getElementById('fIsSale').checked = (p.product_type === 'sale' || p.product_type === 'both');
    document.getElementById('fIsConsumable').checked = (p.product_type === 'consumable' || p.product_type === 'both');
    document.getElementById('fDiscountType').value = p.discount_type;
    document.getElementById('fDiscountAmount').value = p.discount_amount;
    document.getElementById('fStockTracking').checked = p.stock_tracking == 1;
    document.getElementById('fMinStock').value = p.min_stock;
    document.getElementById('fDesc').value = p.description || '';
    document.getElementById('fStatus').value = p.status;
    document.getElementById('fStatus').closest('.col-md-4').style.display = '';
    document.getElementById('stockQtyWrap').style.display = 'none'; // Düzenlemede başlangıç stok gösterme
    // Görsel önizleme
    document.getElementById('fImage').value = '';
    document.getElementById('fRemoveImage').value = '';
    if (p.image) {
        document.getElementById('imagePreview').src = p.image;
        document.getElementById('imagePreviewWrap').style.display = '';
    } else {
        document.getElementById('imagePreviewWrap').style.display = 'none';
    }
    toggleDiscount();
    updateEditBarcodePreview(p.barcode || '');
    new bootstrap.Modal(document.getElementById('productModal')).show();
}

function updateEditBarcodePreview(code) {
    const wrap = document.getElementById('editBarcodePreview');
    const valEl = document.getElementById('editBarcodeValue');
    const svg = document.getElementById('editBarcodeSvg');
    if (!wrap || !svg) return;
    const barcode = String(code || '').trim();
    if (!barcode) {
        wrap.classList.remove('show');
        svg.innerHTML = '';
        if (valEl) valEl.textContent = '';
        return;
    }
    try {
        JsBarcode(svg, barcode, {
            format: 'CODE128',
            width: 1.6,
            height: 48,
            fontSize: 12,
            margin: 4,
            displayValue: false,
            background: 'transparent'
        });
        if (valEl) valEl.textContent = barcode;
        wrap.classList.add('show');
    } catch (e) {
        wrap.classList.remove('show');
        svg.innerHTML = '';
        if (valEl) valEl.textContent = '';
    }
}

function toggleDiscount() {
    const type = document.getElementById('fDiscountType').value;
    document.getElementById('discountAmountWrap').style.display = type === 'none' ? 'none' : '';
}

function previewProductImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('imagePreview').src = e.target.result;
            document.getElementById('imagePreviewWrap').style.display = '';
        };
        reader.readAsDataURL(input.files[0]);
        document.getElementById('fRemoveImage').value = '';
    }
}

function removeProductImage() {
    document.getElementById('imagePreview').src = '';
    document.getElementById('imagePreviewWrap').style.display = 'none';
    document.getElementById('fImage').value = '';
    document.getElementById('fRemoveImage').value = '1';
}

document.getElementById('productForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    if (!fd.get('stock_tracking')) fd.set('stock_tracking', '0');
    else fd.set('stock_tracking', '1');

    // Checkbox'ları açıkça gönder (unchecked olunca FormData'da yer almaz)
    fd.set('is_sale', document.getElementById('fIsSale').checked ? '1' : '0');
    fd.set('is_consumable', document.getElementById('fIsConsumable').checked ? '1' : '0');

    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('productModal'))?.hide();
                _notify(data.message);
                const action = fd.get('action');
                if (action === 'add') {
                    // Yeni eklenen ürün için sayfayı yenile (yeni kart oluşturmak lazım)
                    location.reload();
                } else {
                    const editId = fd.get('id');
                    if (editId) { _refreshProduct(editId); _refreshStats(); }
                }
            } else {
                Swal.fire('Hata', data.message, 'error');
            }
        });
});

// ============================================
// ÜRÜN SİL
// ============================================
function deleteProduct(id, name) {
    Swal.fire({
        title: 'Ürünü Sil',
        html: `<strong>${name}</strong> silinecek. Emin misiniz?`,
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Evet, Sil', cancelButtonText: 'İptal', confirmButtonColor: '#dc2626'
    }).then(r => {
        if (r.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            fetch('products.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        _removeProductCard(id);
                        _refreshStats();
                        _notify('Ürün silindi');
                    } else Swal.fire('Hata', data.message, 'error');
                });
        }
    });
}

// ============================================
// STOK İŞLEMLERİ
// ============================================
function openStockModal(id) {
    const p = allProducts.find(x => x.id == id);
    if (!p) return;
    document.getElementById('sProductId').value = id;
    document.getElementById('sProductName').textContent = p.name + ' (Mevcut: ' + p.stock_quantity + ')';
    document.getElementById('stockForm').reset();
    document.getElementById('sProductId').value = id;
    new bootstrap.Modal(document.getElementById('stockModal')).show();
}

document.getElementById('stockForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const pid = fd.get('product_id');
    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('stockModal'))?.hide();
                _notify(data.message);
                _refreshProduct(pid);
                _refreshStats();
            } else {
                Swal.fire('Hata', data.message, 'error');
            }
        });
});

function quickStock(id, type) {
    const fd = new FormData();
    fd.append('action', 'stock_update');
    fd.append('product_id', id);
    fd.append('movement_type', type);
    fd.append('quantity', 1);
    fd.append('reason', type === 'in' ? 'Hızlı stok giriş (+1)' : 'Hızlı stok çıkış (-1)');
    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                _notify(data.message);
                _refreshProduct(id);
                _refreshStats();
            } else Swal.fire('Hata', data.message, 'error');
        });
}

function inlineStockEdit(badge, productId, currentQty) {
    // Zaten düzenleme modundaysa çık
    if (badge.querySelector('input')) return;
    
    const originalText = badge.textContent.trim();
    const originalClasses = badge.className;
    
    badge.innerHTML = '';
    badge.style.padding = '0';
    
    const input = document.createElement('input');
    input.type = 'number';
    input.value = currentQty;
    input.min = '0';
    input.step = '1';
    input.className = 'inline-stock-input';
    input.style.cssText = 'width:60px;padding:2px 4px;border:2px solid #3b82f6;border-radius:6px;font-size:0.82rem;font-weight:600;text-align:center;outline:none;background:white;color:#1f2937;';
    
    badge.appendChild(input);
    input.focus();
    input.select();
    
    function saveStock() {
        const newQty = parseInt(input.value);
        if (isNaN(newQty) || newQty < 0) {
            badge.className = originalClasses;
            badge.style.padding = '';
            badge.textContent = originalText;
            return;
        }
        if (newQty === currentQty) {
            badge.className = originalClasses;
            badge.style.padding = '';
            badge.textContent = originalText;
            return;
        }
        
        const diff = newQty - currentQty;
        const fd = new FormData();
        fd.append('action', 'stock_update');
        fd.append('product_id', productId);
        fd.append('movement_type', 'adjustment');
        fd.append('quantity', Math.abs(diff));
        fd.append('new_quantity', newQty);
        fd.append('reason', 'Hızlı stok düzeltme (' + currentQty + ' → ' + newQty + ')');
        fetch('products.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    _notify(data.message);
                    _refreshProduct(productId);
                    _refreshStats();
                } else {
                    Swal.fire('Hata', data.message, 'error');
                    badge.className = originalClasses;
                    badge.style.padding = '';
                    badge.textContent = originalText;
                }
            })
            .catch(() => {
                badge.className = originalClasses;
                badge.style.padding = '';
                badge.textContent = originalText;
            });
    }
    
    input.addEventListener('blur', saveStock);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') {
            input.removeEventListener('blur', saveStock);
            badge.className = originalClasses;
            badge.style.padding = '';
            badge.textContent = originalText;
        }
    });
}

// ============================================
// STOK HAREKETLERİ
// ============================================
function showMovements(id) {
    const modal = new bootstrap.Modal(document.getElementById('movementsModal'));
    document.getElementById('movementsBody').innerHTML = '<p class="text-center text-muted">Yükleniyor...</p>';
    modal.show();

    const fd = new FormData();
    fd.append('action', 'get_movements');
    fd.append('product_id', id);
    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.movements.length > 0) {
                let html = '<table class="mv-table"><thead><tr><th>Tarih</th><th>Tip</th><th>Miktar</th><th>Önceki</th><th>Sonra</th><th>Açıklama</th></tr></thead><tbody>';
                data.movements.forEach(m => {
                    const typeMap = { in: ['📥 Giriş', 'mv-in'], out: ['📤 Çıkış', 'mv-out'], adjustment: ['🔧 Düzeltme', 'mv-adj'] };
                    const [label, cls] = typeMap[m.type] || ['?', ''];
                    const date = new Date(m.created_at).toLocaleDateString('tr-TR') + ' ' + new Date(m.created_at).toLocaleTimeString('tr-TR', {hour:'2-digit',minute:'2-digit'});
                    html += `<tr><td>${date}</td><td class="${cls}">${label}</td><td class="${cls}">${m.quantity > 0 ? '+' : ''}${m.quantity}</td><td>${m.stock_before}</td><td>${m.stock_after}</td><td>${m.reason || '-'}</td></tr>`;
                });
                html += '</tbody></table>';
                document.getElementById('movementsBody').innerHTML = html;
            } else {
                document.getElementById('movementsBody').innerHTML = '<p class="text-center text-muted">Henüz stok hareketi yok.</p>';
            }
        });
}

// ============================================
// BARKOD
// ============================================
function generateBarcode() {
    const fd = new FormData();
    fd.append('action', 'generate_barcode');
    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('fBarcode').value = data.barcode;
                updateEditBarcodePreview(data.barcode);
            }
        });
}

function openBarcodeScanner() {
    const modal = new bootstrap.Modal(document.getElementById('barcodeModal'));
    document.getElementById('barcodeResult').innerHTML = '';
    modal.show();

    if (typeof Html5Qrcode !== 'undefined') {
        barcodeScanner = new Html5Qrcode("barcode-reader");
        barcodeScanner.start(
            { facingMode: "environment" },
            { fps: 10, qrbox: { width: 250, height: 100 } },
            (decodedText) => {
                searchBarcode(decodedText);
            },
            () => {}
        ).catch(err => {
            document.getElementById('barcodeResult').innerHTML = '<p class="text-warning"><i class="bi bi-camera-video-off me-1"></i>Kamera erişimi sağlanamadı. Barkodu elle girebilirsiniz.</p>';
        });
    }
}

function stopBarcodeScanner() {
    if (barcodeScanner) {
        const scanner = barcodeScanner;
        barcodeScanner = null;
        scanner.stop().then(() => {
            scanner.clear();
        }).catch(() => {
            try { scanner.clear(); } catch(e) {}
        });
    }
}

function searchBarcode(barcode) {
    if (!barcode) return;
    const wasRunning = !!barcodeScanner;
    if (wasRunning) {
        const scanner = barcodeScanner;
        barcodeScanner = null;
        const doSearch = () => {
            try { scanner.clear(); } catch(e) {}
            _doSearchBarcode(barcode);
        };
        scanner.stop().then(doSearch).catch(doSearch);
    } else {
        _doSearchBarcode(barcode);
    }
}

function _doSearchBarcode(barcode) {
    const fd = new FormData();
    fd.append('action', 'find_barcode');
    fd.append('barcode', barcode.trim());
    fetch('products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const p = data.product;
                bootstrap.Modal.getInstance(document.getElementById('barcodeModal'))?.hide();
                showQuickActions(p);
            } else {
                document.getElementById('barcodeResult').innerHTML = `<p class="text-danger"><i class="bi bi-x-circle me-1"></i>${data.message}</p><button class="btn btn-sm btn-primary mt-2" onclick="bootstrap.Modal.getInstance(document.getElementById('barcodeModal'))?.hide(); openAddModal(); document.getElementById('fBarcode').value='${barcode}'">Bu barkodla yeni ürün ekle</button>`;
            }
        });
}

function showQuickActions(p) {
    const isDark = document.body.classList.contains('dark-mode');
    const txtMain = isDark ? '#f1f5f9' : '#1f2937';
    const txtSub = isDark ? '#94a3b8' : '#64748b';
    const borderCol = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.08)';

    const price = parseFloat(p.price);
    let finalPrice = price;
    let discountHtml = '';
    if (p.discount_type === 'percent' && p.discount_amount > 0) {
        finalPrice = price * (1 - p.discount_amount / 100);
        discountHtml = `<span style="text-decoration:line-through;opacity:.5;font-size:.85rem;margin-right:4px;">${price.toFixed(2)} ₺</span><span style="background:rgba(239,68,68,.1);color:#dc2626;padding:1px 6px;border-radius:4px;font-size:.72rem;font-weight:700;">%${p.discount_amount}</span>`;
    } else if (p.discount_type === 'fixed' && p.discount_amount > 0) {
        finalPrice = price - p.discount_amount;
        discountHtml = `<span style="text-decoration:line-through;opacity:.5;font-size:.85rem;margin-right:4px;">${price.toFixed(2)} ₺</span><span style="background:rgba(239,68,68,.1);color:#dc2626;padding:1px 6px;border-radius:4px;font-size:.72rem;font-weight:700;">${p.discount_amount}₺</span>`;
    }
    if (finalPrice < 0) finalPrice = 0;

    const stockNum = parseInt(p.stock_quantity) || 0;
    const minStock = parseInt(p.min_stock) || 0;
    const trackingOn = p.stock_tracking == 1;
    let stockColor = '#059669', stockBg = 'rgba(16,185,129,.1)', stockLabel = 'Yeterli';
    if (!trackingOn) { stockColor = '#64748b'; stockBg = 'rgba(148,163,184,.1)'; stockLabel = 'Kapalı'; }
    else if (stockNum <= 0) { stockColor = '#dc2626'; stockBg = 'rgba(239,68,68,.1)'; stockLabel = 'Tükendi'; }
    else if (stockNum <= minStock) { stockColor = '#d97706'; stockBg = 'rgba(245,158,11,.1)'; stockLabel = 'Düşük'; }

    const typeBadge = p.product_type === 'both'
        ? '<span style="background:rgba(139,92,246,.1);color:#7c3aed;padding:2px 8px;border-radius:6px;font-size:.72rem;font-weight:600;">Satılık + Sarf</span>'
        : p.product_type === 'sale'
        ? '<span style="background:rgba(59,130,246,.1);color:#2563eb;padding:2px 8px;border-radius:6px;font-size:.72rem;font-weight:600;">Satılık</span>'
        : '<span style="background:rgba(245,158,11,.1);color:#d97706;padding:2px 8px;border-radius:6px;font-size:.72rem;font-weight:600;">Sarf</span>';

    const barcodeVisual = p.barcode ? `
        <div style="background:${isDark ? 'rgba(255,255,255,.04)' : '#f8fafc'};border:1px solid ${borderCol};border-radius:12px;padding:14px 12px 10px;margin-bottom:16px;text-align:center;">
            <div style="font-size:.72rem;color:${txtSub};font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;"><i class="bi bi-upc-scan" style="margin-right:4px;"></i>Barkod</div>
            <svg id="qaBarcodeSvg" style="max-width:100%;height:auto;"></svg>
            <div style="font-family:'Courier New',monospace;font-size:.82rem;font-weight:600;color:${txtMain};margin-top:6px;letter-spacing:.5px;">${_escHtml(String(p.barcode))}</div>
        </div>` : '';

    const html = `
    <div style="text-align:left;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid ${borderCol};">
            <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-box-seam" style="color:#fff;font-size:1.2rem;"></i>
            </div>
            <div style="flex:1;min-width:0;">
                <div class="qa-name" style="font-weight:700;font-size:1.05rem;color:${txtMain};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${p.name}</div>
                <div style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap;">
                    ${typeBadge}
                </div>
            </div>
        </div>
        ${barcodeVisual}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
            <div style="background:rgba(59,130,246,.06);border-radius:10px;padding:12px;text-align:center;">
                <div class="qa-price-label" style="font-size:.72rem;color:${txtSub};font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Fiyat</div>
                <div class="qa-price-val" style="font-size:1.3rem;font-weight:800;color:${txtMain};margin-top:2px;">${finalPrice.toFixed(2)} ₺</div>
                ${discountHtml ? '<div style="margin-top:2px;">' + discountHtml + '</div>' : ''}
            </div>
            <div style="background:${stockBg};border-radius:10px;padding:12px;text-align:center;">
                <div class="qa-stock-label" style="font-size:.72rem;color:${txtSub};font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Stok</div>
                <div style="font-size:1.3rem;font-weight:800;color:${stockColor};margin-top:2px;">${trackingOn ? stockNum : '—'}</div>
                <div style="font-size:.72rem;color:${stockColor};font-weight:600;">${stockLabel}</div>
            </div>
        </div>
        <div class="qa-section-title" style="font-size:.75rem;color:${txtSub};font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Hızlı İşlemler</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <button onclick="Swal.close();barcodeQuickSell(${p.id})" style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid rgba(16,185,129,.2);background:rgba(16,185,129,.06);border-radius:10px;cursor:pointer;font-size:.85rem;font-weight:600;color:#059669;transition:all .15s;" onmouseover="this.style.background='rgba(16,185,129,.15)'" onmouseout="this.style.background='rgba(16,185,129,.06)'">
                <i class="bi bi-cart-check" style="font-size:1.1rem;"></i> Satış Yap
            </button>
            <button onclick="Swal.close();barcodeQuickStockOut(${p.id})" style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid rgba(239,68,68,.2);background:rgba(239,68,68,.06);border-radius:10px;cursor:pointer;font-size:.85rem;font-weight:600;color:#dc2626;transition:all .15s;" onmouseover="this.style.background='rgba(239,68,68,.15)'" onmouseout="this.style.background='rgba(239,68,68,.06)'">
                <i class="bi bi-dash-circle" style="font-size:1.1rem;"></i> Stok Düş
            </button>
            <button onclick="Swal.close();barcodeQuickStockIn(${p.id})" style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid rgba(59,130,246,.2);background:rgba(59,130,246,.06);border-radius:10px;cursor:pointer;font-size:.85rem;font-weight:600;color:#2563eb;transition:all .15s;" onmouseover="this.style.background='rgba(59,130,246,.15)'" onmouseout="this.style.background='rgba(59,130,246,.06)'">
                <i class="bi bi-plus-circle" style="font-size:1.1rem;"></i> Stok Ekle
            </button>
            <button onclick="Swal.close();openStockModal(${p.id})" style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid rgba(245,158,11,.2);background:rgba(245,158,11,.06);border-radius:10px;cursor:pointer;font-size:.85rem;font-weight:600;color:#d97706;transition:all .15s;" onmouseover="this.style.background='rgba(245,158,11,.15)'" onmouseout="this.style.background='rgba(245,158,11,.06)'">
                <i class="bi bi-box-arrow-in-down" style="font-size:1.1rem;"></i> Stok İşlemi
            </button>
            <button onclick="Swal.close();openEditModal(${p.id})" style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid rgba(139,92,246,.2);background:rgba(139,92,246,.06);border-radius:10px;cursor:pointer;font-size:.85rem;font-weight:600;color:#7c3aed;transition:all .15s;" onmouseover="this.style.background='rgba(139,92,246,.15)'" onmouseout="this.style.background='rgba(139,92,246,.06)'">
                <i class="bi bi-pencil-square" style="font-size:1.1rem;"></i> Düzenle
            </button>
            <button onclick="Swal.close();showMovements(${p.id})" style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid rgba(148,163,184,.2);background:rgba(148,163,184,.06);border-radius:10px;cursor:pointer;font-size:.85rem;font-weight:600;color:#64748b;transition:all .15s;" onmouseover="this.style.background='rgba(148,163,184,.15)'" onmouseout="this.style.background='rgba(148,163,184,.06)'">
                <i class="bi bi-clock-history" style="font-size:1.1rem;"></i> Hareketler
            </button>
            ${p.barcode ? `<button onclick="Swal.close();printBarcode(${p.id})" style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid rgba(148,163,184,.2);background:rgba(148,163,184,.06);border-radius:10px;cursor:pointer;font-size:.85rem;font-weight:600;color:#64748b;transition:all .15s;grid-column:span 2;" onmouseover="this.style.background='rgba(148,163,184,.15)'" onmouseout="this.style.background='rgba(148,163,184,.06)'">
                <i class="bi bi-printer" style="font-size:1.1rem;"></i> Barkod Yazdır
            </button>` : ''}
        </div>
    </div>`;

    Swal.fire({
        html: html,
        showConfirmButton: false,
        showCloseButton: true,
        width: 420,
        padding: '20px',
        customClass: { popup: 'quick-action-popup' },
        didOpen: () => {
            if (!p.barcode) return;
            try {
                JsBarcode('#qaBarcodeSvg', String(p.barcode), {
                    format: 'CODE128',
                    width: 1.7,
                    height: 52,
                    fontSize: 12,
                    margin: 4,
                    displayValue: false,
                    background: 'transparent'
                });
            } catch (e) {
                const svg = document.getElementById('qaBarcodeSvg');
                if (svg) svg.outerHTML = '<div style="color:#94a3b8;font-size:.85rem;">Barkod görseli oluşturulamadı</div>';
            }
        }
    });
}

function barcodeQuickSell(id) {
    Swal.fire({
        title: 'Satış Adedi',
        input: 'number',
        inputValue: 1,
        inputAttributes: { min: 1, step: 1 },
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-cart-check me-1"></i> Satış Yap',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#059669',
        inputValidator: (v) => { if (!v || v < 1) return 'En az 1 adet giriniz.'; }
    }).then(r => {
        if (r.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'stock_update');
            fd.append('product_id', id);
            fd.append('movement_type', 'out');
            fd.append('quantity', r.value);
            fd.append('reason', 'Müşteriye satış (' + r.value + ' adet)');
            fetch('products.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
                if (data.success) { _notify('Satış kaydedildi!'); _refreshProduct(id); _refreshStats(); }
                else Swal.fire('Hata', data.message, 'error');
            });
        }
    });
}

function barcodeQuickStockOut(id) {
    Swal.fire({
        title: 'Stok Düş',
        text: 'Kaç adet düşülsün?',
        input: 'number',
        inputValue: 1,
        inputAttributes: { min: 1, step: 1 },
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-dash-circle me-1"></i> Düş',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#dc2626',
        inputValidator: (v) => { if (!v || v < 1) return 'En az 1 adet giriniz.'; }
    }).then(r => {
        if (r.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'stock_update');
            fd.append('product_id', id);
            fd.append('movement_type', 'out');
            fd.append('quantity', r.value);
            fd.append('reason', 'Barkod ile hızlı stok düşme (-' + r.value + ')');
            fetch('products.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
                if (data.success) { _notify('Stok düşüldü'); _refreshProduct(id); _refreshStats(); }
                else Swal.fire('Hata', data.message, 'error');
            });
        }
    });
}

function barcodeQuickStockIn(id) {
    Swal.fire({
        title: 'Stok Ekle',
        text: 'Kaç adet eklensin?',
        input: 'number',
        inputValue: 1,
        inputAttributes: { min: 1, step: 1 },
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-plus-circle me-1"></i> Ekle',
        cancelButtonText: 'İptal',
        confirmButtonColor: '#2563eb',
        inputValidator: (v) => { if (!v || v < 1) return 'En az 1 adet giriniz.'; }
    }).then(r => {
        if (r.isConfirmed) {
            const fd = new FormData();
            fd.append('action', 'stock_update');
            fd.append('product_id', id);
            fd.append('movement_type', 'in');
            fd.append('quantity', r.value);
            fd.append('reason', 'Barkod ile hızlı stok ekleme (+' + r.value + ')');
            fetch('products.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
                if (data.success) { _notify('Stok eklendi'); _refreshProduct(id); _refreshStats(); }
                else Swal.fire('Hata', data.message, 'error');
            });
        }
    });
}

// ESC ile barkod tarayıcıyı kapat
document.getElementById('barcodeModal')?.addEventListener('hidden.bs.modal', stopBarcodeScanner);
document.getElementById('fieldScanModal')?.addEventListener('hidden.bs.modal', stopFieldScanner);

// ============================================
// KAMERADAN BARKOD OKU → ALANA YAZ
// ============================================
let fieldScanner = null;

function scanBarcodeToField() {
    // productModal'ı geçici gizle (iki modal üst üste sorun yaratmasın)
    const productModal = bootstrap.Modal.getInstance(document.getElementById('productModal'));
    
    const scanModal = new bootstrap.Modal(document.getElementById('fieldScanModal'));
    document.getElementById('fieldScanResult').innerHTML = '';
    scanModal.show();

    if (typeof Html5Qrcode !== 'undefined') {
        fieldScanner = new Html5Qrcode("field-barcode-reader");
        fieldScanner.start(
            { facingMode: "environment" },
            { fps: 10, qrbox: { width: 260, height: 120 }, aspectRatio: 1.5 },
            (decodedText) => {
                // Barkod okundu!
                stopFieldScanner();
                document.getElementById('fBarcode').value = decodedText;
                updateEditBarcodePreview(decodedText);
                bootstrap.Modal.getInstance(document.getElementById('fieldScanModal'))?.hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Barkod okundu!',
                    text: decodedText,
                    timer: 1200,
                    showConfirmButton: false
                });
            },
            () => {} // hata callback - sessiz
        ).catch(err => {
            document.getElementById('fieldScanResult').innerHTML = '<p class="text-warning"><i class="bi bi-camera-video-off me-1"></i>Kamera erişimi sağlanamadı.</p>';
        });
    } else {
        document.getElementById('fieldScanResult').innerHTML = '<p class="text-danger">Barkod okuyucu yüklenemedi.</p>';
    }
}

function stopFieldScanner() {
    if (fieldScanner) {
        const scanner = fieldScanner;
        fieldScanner = null;
        scanner.stop().then(() => {
            scanner.clear();
        }).catch(() => {
            try { scanner.clear(); } catch(e) {}
        });
    }
}

// ============================================
// BARKOD YAZDIR
// ============================================
let currentPrintProduct = null;

function printBarcode(id) {
    const p = allProducts.find(x => x.id == id);
    if (!p || !p.barcode) return Swal.fire('Hata', 'Bu ürünün barkodu yok.', 'warning');
    currentPrintProduct = p;

    document.getElementById('labelProductName').textContent = p.name;
    const finalPrice = calcFinalPrice(p);
    document.getElementById('labelPrice').textContent = parseFloat(finalPrice).toFixed(2) + ' ₺';

    updateBarcodePreview();
    new bootstrap.Modal(document.getElementById('printBarcodeModal')).show();
}

function calcFinalPrice(p) {
    let price = parseFloat(p.price);
    if (p.discount_type === 'percent' && p.discount_amount > 0) {
        price = price * (1 - p.discount_amount / 100);
    } else if (p.discount_type === 'fixed' && p.discount_amount > 0) {
        price = price - p.discount_amount;
    }
    return Math.max(price, 0);
}

function updateBarcodePreview() {
    if (!currentPrintProduct) return;
    const size = document.getElementById('printSize').value;
    const dims = { small: { width: 1.2, height: 40, fontSize: 12 }, medium: { width: 1.8, height: 50, fontSize: 14 }, large: { width: 2.2, height: 60, fontSize: 16 } };
    const d = dims[size];
    try {
        JsBarcode('#labelBarcodeSvg', currentPrintProduct.barcode, {
            format: 'CODE128',
            width: d.width,
            height: d.height,
            fontSize: d.fontSize,
            margin: 5,
            displayValue: true
        });
    } catch(e) {
        document.getElementById('labelBarcodeSvg').innerHTML = '';
        Swal.fire('Hata', 'Barkod görseli oluşturulamadı.', 'error');
    }
}

function doPrintBarcode() {
    if (!currentPrintProduct) return;
    const count = Math.max(1, Math.min(100, parseInt(document.getElementById('printCount').value) || 1));
    const size = document.getElementById('printSize').value;
    const dims = { small: { w: '50mm', h: '25mm', bw: 1, bh: 30, fs: 10 }, medium: { w: '60mm', h: '30mm', bw: 1.5, bh: 40, fs: 12 }, large: { w: '80mm', h: '40mm', bw: 2, bh: 50, fs: 14 } };
    const d = dims[size];

    // Geçici SVG üret
    const tempSvg = document.createElement('svg');
    tempSvg.id = 'tempPrintBarcode';
    document.body.appendChild(tempSvg);
    JsBarcode(tempSvg, currentPrintProduct.barcode, {
        format: 'CODE128', width: d.bw, height: d.bh, fontSize: d.fs, margin: 2, displayValue: true
    });
    const svgMarkup = tempSvg.outerHTML;
    document.body.removeChild(tempSvg);

    const finalPrice = calcFinalPrice(currentPrintProduct).toFixed(2) + ' ₺';
    let labelsHtml = '';
    for (let i = 0; i < count; i++) {
        labelsHtml += `<div class="label" style="width:${d.w};height:${d.h};display:inline-flex;flex-direction:column;align-items:center;justify-content:center;border:1px dashed #ccc;margin:2mm;padding:1mm;page-break-inside:avoid;box-sizing:border-box;">
            <div style="font-weight:700;font-size:${d.fs - 2}px;margin-bottom:1mm;text-align:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:100%;">${currentPrintProduct.name}</div>
            ${svgMarkup}
            <div style="font-size:${d.fs - 3}px;margin-top:1mm;color:#555;">${finalPrice}</div>
        </div>`;
    }

    const printWin = window.open('', '_blank', 'width=800,height=600');
    printWin.document.write(`<!DOCTYPE html><html><head><title>Barkod Yazdır</title>
        <style>@page{margin:5mm;}body{font-family:Arial,sans-serif;display:flex;flex-wrap:wrap;justify-content:center;align-items:flex-start;padding:2mm;}svg{max-width:100%;}</style>
        </head><body>${labelsHtml}</body></html>`);
    printWin.document.close();
    printWin.onload = function() { printWin.print(); };

    bootstrap.Modal.getInstance(document.getElementById('printBarcodeModal'))?.hide();
}

// ============================================
// ENTER TUŞ DESTEĞİ & USB BARKOD OKUYUCU
// ============================================
// Manuel barkod girişinde Enter tuşu
document.getElementById('manualBarcode')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        searchBarcode(this.value);
    }
});

// USB barkod okuyucu desteği (hızlı karakter dizisi + Enter)
(function() {
    let barcodeBuf = '';
    let barcodeTimer = null;
    document.addEventListener('keydown', function(e) {
        // Aktif bir input/textarea varsa devreye girme
        const tag = document.activeElement?.tagName?.toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
        // Swal açıksa devreye girme
        if (document.querySelector('.swal2-container')) return;
        // Modal açıksa devreye girme
        if (document.querySelector('.modal.show')) return;

        if (e.key === 'Enter' && barcodeBuf.length >= 4) {
            e.preventDefault();
            const code = barcodeBuf;
            barcodeBuf = '';
            clearTimeout(barcodeTimer);
            searchBarcode(code);
        } else if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            barcodeBuf += e.key;
            clearTimeout(barcodeTimer);
            barcodeTimer = setTimeout(() => { barcodeBuf = ''; }, 100);
        }
    });
})();

// URL'de scan=1 varsa otomatik barkod tarayıcı aç
if (new URLSearchParams(window.location.search).has('scan')) {
    window.addEventListener('DOMContentLoaded', () => openBarcodeScanner());
    // URL'den parametreyi temizle
    history.replaceState(null, '', 'products.php' + (window.location.search.replace(/[?&]scan=1/, '').replace(/^\?$/, '') || ''));
}

</script>

<?php include 'includes/footer.php'; ?>
