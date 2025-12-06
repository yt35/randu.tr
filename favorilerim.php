<?php
require_once 'includes/header.php';

// Erişim Kontrolü
if ($is_tenant_context) {
    echo "<script>window.location.href='index.php';</script>"; 
    exit;
}

// FAVORİDEN ÇIKARMA İŞLEMLERİ
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['remove_tenant_id'])) {
        $tid = intval($_POST['remove_tenant_id']);
        $db->prepare("DELETE FROM customer_tenants WHERE customer_id = ? AND tenant_id = ?")->execute([$customer_id, $tid]);
        $success_msg = 'İşletme favorilerden çıkarıldı.';
    }
    if (isset($_POST['remove_employee_id'])) {
        $eid = intval($_POST['remove_employee_id']);
        $db->prepare("DELETE FROM customer_favorite_employees WHERE customer_id = ? AND employee_id = ?")->execute([$customer_id, $eid]);
        $success_msg = 'Çalışan favorilerden çıkarıldı.';
    }
    if (isset($_POST['remove_service_id'])) {
        $sid = intval($_POST['remove_service_id']);
        $db->prepare("DELETE FROM customer_favorite_services WHERE customer_id = ? AND service_id = ?")->execute([$customer_id, $sid]);
        $success_msg = 'Hizmet favorilerden çıkarıldı.';
    }
}

// VERİLERİ ÇEKME
try {
    $stmtT = $db->prepare("
        SELECT t.*, 
        (SELECT COUNT(*) FROM appointments a WHERE a.tenant_id = t.id AND a.customer_id = ?) as randevu_sayisi
        FROM tenants t
        JOIN customer_tenants ct ON t.id = ct.tenant_id
        WHERE ct.customer_id = ? AND t.is_active = 1
        ORDER BY ct.created_at DESC
    ");
    $stmtT->execute([$customer_id, $customer_id]);
    $fav_tenants = $stmtT->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $fav_tenants = []; }

try {
    $stmtE = $db->prepare("
        SELECT e.*, t.firma_adi, t.domain, t.id as tenant_id
        FROM employees e
        JOIN customer_favorite_employees cfe ON e.id = cfe.employee_id
        JOIN tenants t ON e.tenant_id = t.id
        WHERE cfe.customer_id = ? AND e.status = 'active'
    ");
    $stmtE->execute([$customer_id]);
    $fav_employees = $stmtE->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $fav_employees = []; }

try {
    $stmtS = $db->prepare("
        SELECT s.*, t.firma_adi, t.domain, t.id as tenant_id
        FROM services s
        JOIN customer_favorite_services cfs ON s.id = cfs.service_id
        JOIN tenants t ON s.tenant_id = t.id
        WHERE cfs.customer_id = ? AND s.status = 'active'
    ");
    $stmtS->execute([$customer_id]);
    $fav_services = $stmtS->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $fav_services = []; }
?>

<style>
    /* Page Header */
    .page-header {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        border-radius: 20px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        color: white;
    }

    .page-title {
        font-size: 1.5rem;
        font-weight: 800;
        margin-bottom: 0.25rem;
    }

    .page-subtitle {
        font-size: 0.9rem;
        opacity: 0.9;
    }

    /* Tabs */
    .custom-tabs {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        padding-bottom: 0.5rem;
    }

    .custom-tabs::-webkit-scrollbar {
        display: none;
    }

    .custom-tab {
        flex-shrink: 0;
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        background: white;
        border: 2px solid var(--border-color);
        font-weight: 600;
        color: var(--text-muted);
        transition: all 0.3s;
        cursor: pointer;
        white-space: nowrap;
    }

    .custom-tab.active {
        background: var(--accent-color);
        border-color: var(--accent-color);
        color: white;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }

    .custom-tab i {
        margin-right: 0.5rem;
    }

    /* Business Cards */
    .business-card-fav {
        background: white;
        border-radius: 16px;
        padding: 1.25rem;
        border: 1px solid var(--border-color);
        position: relative;
        transition: all 0.3s;
        height: 100%;
    }

    .business-card-fav:hover {
        box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        transform: translateY(-2px);
    }

    .business-avatar-fav {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        background: linear-gradient(135deg, #e0e7ff 0%, #dbeafe 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--accent-color);
        margin-bottom: 1rem;
    }

    .business-name {
        font-size: 1.125rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
        line-height: 1.3;
    }

    .business-location {
        font-size: 0.875rem;
        color: var(--text-muted);
        margin-bottom: 1rem;
    }

    .remove-btn {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: white;
        border: 2px solid #fee2e2;
        color: #ef4444;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .remove-btn:hover {
        background: #fee2e2;
        transform: scale(1.1);
    }

    /* Employee Cards */
    .employee-card-fav {
        background: white;
        border-radius: 16px;
        padding: 1.25rem;
        border: 1px solid var(--border-color);
        text-align: center;
        position: relative;
        transition: all 0.3s;
        height: 100%;
    }

    .employee-card-fav:hover {
        box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        transform: translateY(-2px);
    }

    .employee-img-fav {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        margin: 0 auto 1rem;
        border: 4px solid #f8fafc;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }

    .employee-name-fav {
        font-weight: 700;
        font-size: 1rem;
        margin-bottom: 0.25rem;
    }

    .employee-business {
        font-size: 0.8rem;
        color: var(--text-muted);
        margin-bottom: 1rem;
    }

    /* Service Cards */
    .service-card-fav {
        background: white;
        border-radius: 16px;
        padding: 1.25rem;
        border: 1px solid var(--border-color);
        position: relative;
        transition: all 0.3s;
        height: 100%;
    }

    .service-card-fav:hover {
        box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        transform: translateY(-2px);
    }

    .service-header {
        display: flex;
        justify-content: space-between;
        align-items: start;
        margin-bottom: 0.75rem;
    }

    .service-name-fav {
        font-weight: 700;
        font-size: 1rem;
        margin-bottom: 0.25rem;
    }

    .service-price-badge {
        background: #eff6ff;
        color: var(--accent-color);
        padding: 0.375rem 0.75rem;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.9rem;
    }

    .service-business {
        font-size: 0.875rem;
        color: var(--text-muted);
        margin-bottom: 1rem;
    }

    /* Empty State */
    .empty-state-fav {
        text-align: center;
        padding: 4rem 1rem;
        background: white;
        border-radius: 20px;
        border: 2px dashed var(--border-color);
    }

    .empty-state-fav i {
        font-size: 4rem;
        color: #e2e8f0;
        margin-bottom: 1.5rem;
    }

    .empty-state-fav h6 {
        color: var(--text-muted);
        margin-bottom: 0.5rem;
    }

    .empty-state-fav p {
        color: var(--text-muted);
        font-size: 0.9rem;
        margin-bottom: 1.5rem;
    }

    /* Action Button */
    .action-btn {
        width: 100%;
        padding: 0.75rem;
        border-radius: 12px;
        font-weight: 600;
        transition: all 0.3s;
    }

    /* Mobile Optimizations */
    @media (max-width: 767.98px) {
        .page-header {
            padding: 1.25rem;
            margin-bottom: 1rem;
        }

        .page-title {
            font-size: 1.25rem;
        }

        .custom-tabs {
            gap: 0.375rem;
            margin-bottom: 1rem;
        }

        .custom-tab {
            padding: 0.625rem 1rem;
            font-size: 0.875rem;
        }

        .business-card-fav,
        .employee-card-fav,
        .service-card-fav {
            padding: 1rem;
        }

        .business-avatar-fav {
            width: 48px;
            height: 48px;
            font-size: 1.25rem;
        }

        .employee-img-fav {
            width: 70px;
            height: 70px;
        }

        .remove-btn {
            width: 28px;
            height: 28px;
            font-size: 0.875rem;
        }

        .empty-state-fav {
            padding: 3rem 1rem;
        }

        .empty-state-fav i {
            font-size: 3rem;
        }
    }

    /* Success Toast */
    .toast-success {
        position: fixed;
        top: 80px;
        right: 1rem;
        background: #10b981;
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(16, 185, 129, 0.3);
        z-index: 9999;
        animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
</style>

<div class="container pb-4">
    
    <?php if(isset($success_msg)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h4 class="page-title">
                    <i class="bi bi-heart-fill me-2"></i>
                    Favorilerim
                </h4>
                <p class="page-subtitle mb-0">
                    Takip ettiğiniz işletmeler ve hizmetler
                </p>
            </div>
            <a href="isletmeler.php" class="btn btn-light btn-sm rounded-pill px-3">
                <i class="bi bi-search"></i>
            </a>
        </div>
    </div>

    <!-- Custom Tabs -->
    <div class="custom-tabs">
        <button class="custom-tab active" onclick="switchTab('isletme')">
            <i class="bi bi-shop"></i>
            İşletmeler (<?php echo count($fav_tenants); ?>)
        </button>
        <button class="custom-tab" onclick="switchTab('calisan')">
            <i class="bi bi-person-badge"></i>
            Çalışanlar (<?php echo count($fav_employees); ?>)
        </button>
        <button class="custom-tab" onclick="switchTab('hizmet')">
            <i class="bi bi-scissors"></i>
            Hizmetler (<?php echo count($fav_services); ?>)
        </button>
    </div>

    <!-- İŞLETMELER TAB -->
    <div class="tab-content-custom" id="tab-isletme">
        <?php if(empty($fav_tenants)): ?>
            <div class="empty-state-fav">
                <i class="bi bi-shop-window"></i>
                <h6>Favori işletmeniz yok</h6>
                <p>Beğendiğiniz işletmeleri favorilere ekleyin</p>
                <a href="isletmeler.php" class="btn btn-primary-custom btn-sm">
                    <i class="bi bi-search me-1"></i> İşletme Keşfet
                </a>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach($fav_tenants as $t): 
                    $initial = mb_substr($t['firma_adi'], 0, 1, 'UTF-8');
                    $link = "reservation.php?tenant_id=" . $t['id'];
                ?>
                <div class="col-sm-6 col-lg-4">
                    <div class="business-card-fav">
                        <form method="POST" class="d-inline" onsubmit="return confirm('Favorilerden çıkarmak istediğinize emin misiniz?');">
                            <input type="hidden" name="remove_tenant_id" value="<?php echo $t['id']; ?>">
                            <button type="submit" class="remove-btn">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </form>

                        <div class="business-avatar-fav">
                            <?php echo $initial; ?>
                        </div>
                        <h6 class="business-name"><?php echo htmlspecialchars($t['firma_adi']); ?></h6>
                        <div class="business-location">
                            <i class="bi bi-geo-alt-fill text-danger"></i>
                            <?php echo htmlspecialchars($t['il'] ?? 'Konum yok'); ?>
                        </div>
                        <a href="<?php echo $link; ?>" class="btn btn-primary-custom action-btn">
                            <i class="bi bi-calendar-check me-2"></i>
                            Randevu Al
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ÇALIŞANLAR TAB -->
    <div class="tab-content-custom" id="tab-calisan" style="display:none;">
        <?php if(empty($fav_employees)): ?>
            <div class="empty-state-fav">
                <i class="bi bi-person-x"></i>
                <h6>Favori çalışanınız yok</h6>
                <p>Beğendiğiniz çalışanları favorilere ekleyin</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach($fav_employees as $e): 
                    $emp_img = !empty($e['photo']) ? '../uploads/employees/'.$e['photo'] : 'https://placehold.co/80x80/e2e8f0/888?text='.mb_substr($e['name'],0,1);
                    $link = "reservation.php?tenant_id=" . $e['tenant_id'];
                ?>
                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                    <div class="employee-card-fav">
                        <form method="POST" class="d-inline" onsubmit="return confirm('Favorilerden çıkarmak istediğinize emin misiniz?');">
                            <input type="hidden" name="remove_employee_id" value="<?php echo $e['id']; ?>">
                            <button type="submit" class="remove-btn">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </form>

                        <img src="<?php echo $emp_img; ?>" class="employee-img-fav" alt="">
                        <h6 class="employee-name-fav"><?php echo htmlspecialchars($e['name']); ?></h6>
                        <p class="employee-business">
                            <i class="bi bi-shop"></i>
                            <?php echo htmlspecialchars($e['firma_adi']); ?>
                        </p>
                        <a href="<?php echo $link; ?>" class="btn btn-outline-primary action-btn btn-sm">
                            <i class="bi bi-calendar-check"></i>
                            Randevu
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- HİZMETLER TAB -->
    <div class="tab-content-custom" id="tab-hizmet" style="display:none;">
        <?php if(empty($fav_services)): ?>
            <div class="empty-state-fav">
                <i class="bi bi-scissors"></i>
                <h6>Favori hizmetiniz yok</h6>
                <p>Beğendiğiniz hizmetleri favorilere ekleyin</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach($fav_services as $s): 
                    $link = "reservation.php?tenant_id=" . $s['tenant_id'];
                ?>
                <div class="col-sm-6 col-lg-4">
                    <div class="service-card-fav">
                        <form method="POST" class="d-inline" onsubmit="return confirm('Favorilerden çıkarmak istediğinize emin misiniz?');">
                            <input type="hidden" name="remove_service_id" value="<?php echo $s['id']; ?>">
                            <button type="submit" class="remove-btn">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </form>

                        <div class="service-header">
                            <div class="flex-fill">
                                <h6 class="service-name-fav"><?php echo htmlspecialchars($s['name']); ?></h6>
                            </div>
                            <span class="service-price-badge"><?php echo $s['price']; ?> ₺</span>
                        </div>
                        <div class="service-business">
                            <i class="bi bi-shop"></i>
                            <?php echo htmlspecialchars($s['firma_adi']); ?>
                        </div>
                        <a href="<?php echo $link; ?>" class="btn btn-primary-custom action-btn">
                            <i class="bi bi-bag-check me-2"></i>
                            Bu Hizmeti Al
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function switchTab(tab) {
    // Hide all tabs
    document.querySelectorAll('.tab-content-custom').forEach(el => {
        el.style.display = 'none';
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('.custom-tab').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById('tab-' + tab).style.display = 'block';
    
    // Add active class to clicked button
    event.target.classList.add('active');
    
    // Smooth scroll to top
    window.scrollTo({top: 0, behavior: 'smooth'});
}
</script>

<?php include 'includes/footer.php'; ?>