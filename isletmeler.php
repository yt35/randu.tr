<?php
require_once 'includes/header.php';

// Erişim Kontrolü
if ($is_tenant_context) {
    echo "<script>window.location.href='index.php';</script>"; 
    exit;
}

// FAVORİ İŞLEMİ
if (isset($_POST['toggle_fav_id'])) {
    $target_id = intval($_POST['toggle_fav_id']);
    
    $check = $db->prepare("SELECT id FROM customer_tenants WHERE customer_id = ? AND tenant_id = ?");
    $check->execute([$customer_id, $target_id]);
    
    if ($check->fetch()) {
        $del = $db->prepare("DELETE FROM customer_tenants WHERE customer_id = ? AND tenant_id = ?");
        $del->execute([$customer_id, $target_id]);
    } else {
        $add = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id) VALUES (?, ?)");
        $add->execute([$customer_id, $target_id]);
    }
    
    // AJAX isteği ise JSON dön
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// VERİLERİ ÇEKME
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$city_filter = isset($_GET['sehir']) ? trim($_GET['sehir']) : '';

$my_fav_ids = $db->query("SELECT tenant_id FROM customer_tenants WHERE customer_id = $customer_id")->fetchAll(PDO::FETCH_COLUMN);

try {
    $cities = $db->query("SELECT DISTINCT il FROM tenants WHERE is_active = 1 AND il IS NOT NULL AND il != '' ORDER BY il ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $cities = []; }

try {
    $sql = "SELECT * FROM tenants WHERE is_active = 1";
    $params = [];

    if ($search) {
        $sql .= " AND (firma_adi LIKE ? OR domain LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($city_filter) {
        $sql .= " AND il = ?";
        $params[] = $city_filter;
    }
    $sql .= " ORDER BY id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $tenants = []; }
?>

<style>
    /* Hero Section */
    .hero-section {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        padding: 2rem 1rem;
        border-radius: 20px;
        margin-bottom: 1.5rem;
        color: white;
        position: relative;
        overflow: hidden;
    }

    .hero-section::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 300px;
        height: 300px;
        background: rgba(255,255,255,0.1);
        border-radius: 50%;
    }

    .hero-content {
        position: relative;
        z-index: 2;
    }

    .hero-title {
        font-size: 1.75rem;
        font-weight: 800;
        margin-bottom: 0.5rem;
    }

    .hero-subtitle {
        font-size: 0.95rem;
        opacity: 0.9;
        margin-bottom: 1.5rem;
    }

    /* Search Bar */
    .search-bar {
        background: white;
        border-radius: 16px;
        padding: 1rem;
        box-shadow: 0 4px 16px rgba(0,0,0,0.08);
        border: 1px solid var(--border-color);
        margin-bottom: 1.5rem;
    }

    .search-input {
        border: 2px solid var(--border-color);
        border-radius: 12px;
        padding: 0.875rem 1rem;
        font-size: 0.95rem;
        transition: all 0.3s;
        width: 100%;
    }

    .search-input:focus {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        outline: none;
    }

    .search-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        pointer-events: none;
    }

    .filter-select {
        border: 2px solid var(--border-color);
        border-radius: 12px;
        padding: 0.875rem 1rem;
        font-size: 0.95rem;
        transition: all 0.3s;
        width: 100%;
    }

    .filter-select:focus {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        outline: none;
    }

    /* Business Cards */
    .business-card-main {
        background: white;
        border-radius: 16px;
        overflow: hidden;
        border: 1px solid var(--border-color);
        transition: all 0.3s;
        height: 100%;
        display: flex;
        flex-direction: column;
        position: relative;
    }

    .business-card-main:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 32px rgba(0,0,0,0.1);
        border-color: var(--accent-color);
    }

    .business-header-main {
        height: 120px;
        background: linear-gradient(135deg, #e0e7ff 0%, #dbeafe 100%);
        position: relative;
    }

    .business-logo-main {
        width: 70px;
        height: 70px;
        background: white;
        border-radius: 16px;
        position: absolute;
        bottom: -35px;
        left: 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        font-size: 2rem;
        font-weight: 700;
        color: var(--accent-color);
        border: 3px solid white;
    }

    .business-body-main {
        padding: 45px 1rem 1rem;
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .business-title-main {
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--text-main);
        margin-bottom: 0.25rem;
        line-height: 1.3;
    }

    .business-domain-main {
        color: var(--text-muted);
        font-size: 0.85rem;
        margin-bottom: 0.75rem;
    }

    .business-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .badge-item {
        background: #f1f5f9;
        color: #475569;
        font-size: 0.75rem;
        padding: 0.375rem 0.75rem;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }

    .badge-premium {
        background: #fffbeb;
        color: #b45309;
    }

    .fav-btn-main {
        position: absolute;
        top: 1rem;
        right: 1rem;
        z-index: 10;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: white;
        border: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #cbd5e1;
        transition: all 0.3s;
        cursor: pointer;
    }

    .fav-btn-main.active {
        color: #ef4444;
        background: #fee2e2;
    }

    .fav-btn-main:hover {
        transform: scale(1.1);
    }

    .action-btn-main {
        margin-top: auto;
        width: 100%;
        padding: 0.75rem;
        border-radius: 12px;
        font-weight: 600;
        transition: all 0.3s;
    }

    /* Empty State */
    .empty-state-main {
        text-align: center;
        padding: 4rem 1rem;
        background: white;
        border-radius: 20px;
        border: 2px dashed var(--border-color);
    }

    .empty-state-main i {
        font-size: 5rem;
        color: #e2e8f0;
        margin-bottom: 1.5rem;
    }

    .empty-state-main h5 {
        color: var(--text-main);
        margin-bottom: 0.5rem;
    }

    .empty-state-main p {
        color: var(--text-muted);
        margin-bottom: 1.5rem;
    }

    /* Stats */
    .stats-bar {
        background: #f8fafc;
        padding: 0.75rem 1rem;
        border-radius: 12px;
        margin-bottom: 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .stats-text {
        font-size: 0.875rem;
        color: var(--text-muted);
    }

    .stats-count {
        font-weight: 700;
        color: var(--accent-color);
    }

    /* Mobile Optimizations */
    @media (max-width: 767.98px) {
        .hero-section {
            padding: 1.5rem 1rem;
            margin-bottom: 1rem;
        }

        .hero-title {
            font-size: 1.5rem;
        }

        .hero-subtitle {
            font-size: 0.875rem;
        }

        .search-bar {
            padding: 0.875rem;
        }

        .business-header-main {
            height: 100px;
        }

        .business-logo-main {
            width: 60px;
            height: 60px;
            font-size: 1.75rem;
        }

        .business-body-main {
            padding: 40px 0.875rem 0.875rem;
        }

        .business-title-main {
            font-size: 1rem;
        }

        .fav-btn-main {
            width: 34px;
            height: 34px;
            top: 0.75rem;
            right: 0.75rem;
        }

        .empty-state-main {
            padding: 3rem 1rem;
        }

        .empty-state-main i {
            font-size: 3.5rem;
        }
    }

    /* Loading Skeleton */
    .skeleton {
        background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
    }

    @keyframes loading {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }
</style>

<div class="container pb-4">
    
    <!-- Hero Section -->
    <div class="hero-section">
        <div class="hero-content">
            <h1 class="hero-title">
                <i class="bi bi-search me-2"></i>
                İşletmeleri Keşfedin
            </h1>
            <p class="hero-subtitle mb-0">
                Size en yakın kuaför ve güzellik salonlarını bulun, hemen randevu alın
            </p>
        </div>
    </div>

    <!-- Search Bar -->
    <form method="GET" class="search-bar">
        <div class="row g-2">
            <div class="col-12 col-md-6">
                <div class="position-relative">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" name="q" class="search-input ps-5" 
                           placeholder="İşletme adı ara..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-8 col-md-4">
                <select name="sehir" class="filter-select">
                    <option value="">Tüm Şehirler</option>
                    <?php foreach($cities as $city): ?>
                        <option value="<?php echo htmlspecialchars($city); ?>" 
                                <?php echo $city_filter == $city ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($city); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-md-2">
                <button type="submit" class="btn btn-primary-custom w-100" style="padding: 0.875rem;">
                    <i class="bi bi-funnel-fill"></i>
                    <span class="d-none d-md-inline ms-1">Filtrele</span>
                </button>
            </div>
        </div>
    </form>

    <!-- Stats Bar -->
    <?php if($search || $city_filter): ?>
        <div class="stats-bar">
            <span class="stats-text">
                <span class="stats-count"><?php echo count($tenants); ?></span> sonuç bulundu
            </span>
            <a href="isletmeler.php" class="btn btn-sm btn-outline-secondary rounded-pill">
                <i class="bi bi-x-circle me-1"></i> Temizle
            </a>
        </div>
    <?php endif; ?>

    <!-- Business List -->
    <?php if(empty($tenants)): ?>
        <div class="empty-state-main">
            <i class="bi bi-shop-window"></i>
            <h5>Sonuç Bulunamadı</h5>
            <p>Arama kriterlerinize uygun işletme bulunamadı</p>
            <a href="isletmeler.php" class="btn btn-primary-custom">
                <i class="bi bi-arrow-clockwise me-2"></i>
                Filtreleri Temizle
            </a>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach($tenants as $t): 
                $detail_url = "reservation.php?tenant_id=" . $t['id'];
                $initial = mb_substr($t['firma_adi'], 0, 1, 'UTF-8');
                
                $location = 'Konum Belirtilmemiş';
                if (!empty($t['il'])) {
                    $location = htmlspecialchars($t['il']);
                    if (!empty($t['ilce'])) {
                        $location .= ', ' . htmlspecialchars($t['ilce']);
                    }
                } elseif (!empty($t['fatura_adresi'])) {
                    $location = mb_substr(htmlspecialchars($t['fatura_adresi']), 0, 30, 'UTF-8') . '...';
                }
                
                $is_fav = in_array($t['id'], $my_fav_ids);
            ?>
            <div class="col-sm-6 col-lg-4">
                <div class="business-card-main">
                    <!-- Favori Butonu -->
                    <button class="fav-btn-main <?php echo $is_fav ? 'active' : ''; ?>" 
                            onclick="toggleFavorite(<?php echo $t['id']; ?>, this)">
                        <i class="bi <?php echo $is_fav ? 'bi-heart-fill' : 'bi-heart'; ?>"></i>
                    </button>

                    <div class="business-header-main">
                        <div class="business-logo-main">
                            <?php echo $initial; ?>
                        </div>
                    </div>
                    
                    <div class="business-body-main">
                        <h6 class="business-title-main">
                            <?php echo htmlspecialchars($t['firma_adi']); ?>
                        </h6>
                        <div class="business-domain-main">
                            <i class="bi bi-globe2"></i>
                            <?php echo htmlspecialchars($t['domain']); ?>.randu.tr
                        </div>
                        
                        <div class="business-badges">
                            <span class="badge-item">
                                <i class="bi bi-geo-alt-fill text-danger"></i>
                                <?php echo $location; ?>
                            </span>
                            <?php if($t['paket_turu'] == 'gold'): ?>
                            <span class="badge-item badge-premium">
                                <i class="bi bi-star-fill"></i>
                                Premium
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <a href="<?php echo $detail_url; ?>" class="btn btn-primary-custom action-btn-main">
                            <i class="bi bi-calendar-check me-2"></i>
                            Randevu Al
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleFavorite(tenantId, btn) {
    const icon = btn.querySelector('i');
    const isAdding = !btn.classList.contains('active');
    
    // Optimistic UI Update
    btn.classList.toggle('active');
    if(isAdding) {
        icon.classList.replace('bi-heart', 'bi-heart-fill');
    } else {
        icon.classList.replace('bi-heart-fill', 'bi-heart');
    }
    
    // AJAX Request
    const fd = new FormData();
    fd.append('toggle_fav_id', tenantId);
    fd.append('ajax', '1');
    
    fetch('isletmeler.php', { 
        method: 'POST', 
        body: fd 
    })
    .then(r => r.json())
    .then(res => {
        if(res.status !== 'success') {
            // Revert on error
            btn.classList.toggle('active');
            if(isAdding) {
                icon.classList.replace('bi-heart-fill', 'bi-heart');
            } else {
                icon.classList.replace('bi-heart', 'bi-heart-fill');
            }
        } else {
            // Show feedback
            showToast(isAdding ? 'Favorilere eklendi' : 'Favorilerden çıkarıldı');
        }
    })
    .catch(() => {
        // Revert on error
        btn.classList.toggle('active');
        if(isAdding) {
            icon.classList.replace('bi-heart-fill', 'bi-heart');
        } else {
            icon.classList.replace('bi-heart', 'bi-heart-fill');
        }
    });
}

function showToast(message) {
    const toast = document.createElement('div');
    toast.className = 'toast-success';
    toast.innerHTML = `<i class="bi bi-check-circle me-2"></i>${message}`;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideIn 0.3s ease-out reverse';
        setTimeout(() => toast.remove(), 300);
    }, 2000);
}
</script>

<?php include 'includes/footer.php'; ?>