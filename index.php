<?php
require_once 'includes/header.php';

$message = '';

// İPTAL İŞLEMİ
if (isset($_POST['cancel_appointment_id'])) {
    $cancel_id = intval($_POST['cancel_appointment_id']);
    
    try {
        $checkSql = "SELECT id, appointment_date, appointment_time FROM appointments WHERE id = ? AND customer_id = ? AND status = 'pending'";
        $checkParams = [$cancel_id, $customer_id];

        if ($is_tenant_context && defined('TENANT_ID')) {
            $checkSql .= " AND tenant_id = ?";
            $checkParams[] = TENANT_ID;
        }

        $stmtCheck = $db->prepare($checkSql);
        $stmtCheck->execute($checkParams);
        $app = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($app) {
            $app_timestamp = strtotime($app['appointment_date'] . ' ' . $app['appointment_time']);
            if (time() > ($app_timestamp - 3600)) {
                $message = '<div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill me-2"></i>Randevu zamanına 1 saatten az kaldığı için iptal edilemez.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $stmtUpdate = $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ?");
                $stmtUpdate->execute([$cancel_id]);
                $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-2"></i>Randevunuz başarıyla iptal edildi.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        } else {
            $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>İşlem başarısız.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    } catch (Exception $e) {
        $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>Hata: ' . $e->getMessage() . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

// VERİLERİ ÇEKME
$filter_sql = "";
$filter_params = [$customer_id];

if ($is_tenant_context && defined('TENANT_ID')) {
    $filter_sql = " AND tenant_id = ? ";
    $filter_params[] = TENANT_ID;
}

$sqlTotal = "SELECT COUNT(*) FROM appointments WHERE customer_id = ?" . ($is_tenant_context ? " AND tenant_id = ?" : "");
$stmtTotal = $db->prepare($sqlTotal);
$stmtTotal->execute($filter_params);
$total_appointments = $stmtTotal->fetchColumn();

$sqlPending = "SELECT COUNT(*) FROM appointments WHERE customer_id = ? AND status IN ('pending', 'confirmed') AND CONCAT(appointment_date, ' ', appointment_time) > NOW()" . ($is_tenant_context ? " AND tenant_id = ?" : "");
$stmtPending = $db->prepare($sqlPending);
$stmtPending->execute($filter_params); 
$pending_count = $stmtPending->fetchColumn();

$sqlFuture = "
    SELECT 
        a.*, 
        t.firma_adi, t.domain, t.telefon as tenant_phone,
        e.name as employee_name
    FROM appointments a
    JOIN tenants t ON a.tenant_id = t.id
    LEFT JOIN employees e ON a.employee_id = e.id
    WHERE a.customer_id = ? 
    AND a.status IN ('pending', 'confirmed')
    AND CONCAT(a.appointment_date, ' ', a.appointment_time) > NOW()
";
if ($is_tenant_context) {
    $sqlFuture .= " AND a.tenant_id = ? ";
}
$sqlFuture .= " ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 5";

$stmtFuture = $db->prepare($sqlFuture);
$stmtFuture->execute($filter_params);
$future_appointments = $stmtFuture->fetchAll(PDO::FETCH_ASSOC);

$sqlPast = "
    SELECT 
        a.*, 
        t.firma_adi,
        e.name as employee_name
    FROM appointments a
    JOIN tenants t ON a.tenant_id = t.id
    LEFT JOIN employees e ON a.employee_id = e.id
    WHERE a.customer_id = ? 
    AND (
        a.status IN ('completed', 'cancelled', 'no_show') 
        OR CONCAT(a.appointment_date, ' ', a.appointment_time) <= NOW()
    )
";
if ($is_tenant_context) {
    $sqlPast .= " AND a.tenant_id = ? ";
}
$sqlPast .= " ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 5";

$stmtPast = $db->prepare($sqlPast);
$stmtPast->execute($filter_params);
$past_appointments = $stmtPast->fetchAll(PDO::FETCH_ASSOC);

function getAppointmentServices($db, $appointment_id) {
    $stmt = $db->prepare("SELECT s.name FROM appointment_services aps JOIN services s ON aps.service_id = s.id WHERE aps.appointment_id = ?");
    $stmt->execute([$appointment_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>

<style>
    /* Stat Cards */
    .stat-card {
        background: white;
        border-radius: 16px;
        padding: 1.25rem;
        border: 1px solid var(--border-color);
        transition: all 0.3s;
        height: 100%;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px rgba(0,0,0,0.08);
    }

    .stat-icon {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 0.25rem;
    }

    .stat-label {
        font-size: 0.875rem;
        color: var(--text-muted);
        font-weight: 500;
    }

    /* CTA Card */
    .cta-card {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        border-radius: 16px;
        padding: 1.5rem;
        color: white;
        text-align: center;
        border: none;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .cta-card h5 {
        margin-bottom: 1rem;
        font-weight: 700;
    }

    /* Appointment Cards */
    .appointment-card {
        background: white;
        border-radius: 14px;
        border: 1px solid var(--border-color);
        overflow: hidden;
        margin-bottom: 1rem;
        transition: all 0.3s;
    }

    .appointment-card:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.08);
    }

    .appointment-header {
        padding: 1rem;
        background: #f8fafc;
        border-bottom: 1px solid var(--border-color);
    }

    .appointment-body {
        padding: 1rem;
    }

    .business-avatar {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: #e0e7ff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        color: var(--accent-color);
        font-size: 1.25rem;
    }

    .status-badge {
        padding: 0.375rem 0.75rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        background: white;
        border-radius: 16px;
        border: 2px dashed var(--border-color);
    }

    .empty-state i {
        font-size: 4rem;
        color: #e2e8f0;
        margin-bottom: 1rem;
    }

    /* History List */
    .history-item {
        padding: 1rem;
        border-bottom: 1px solid var(--border-color);
        transition: background 0.2s;
    }

    .history-item:last-child {
        border-bottom: none;
    }

    .history-item:active {
        background: #f8fafc;
    }

    /* Section Headers */
    .section-header {
        display: flex;
        justify-content: between;
        align-items: center;
        margin-bottom: 1rem;
    }

    .section-title {
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--text-main);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    /* Mobile Optimizations */
    @media (max-width: 767.98px) {
        .stat-card {
            padding: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            font-size: 1.25rem;
        }

        .stat-value {
            font-size: 1.75rem;
        }

        .stat-label {
            font-size: 0.8rem;
        }

        .cta-card {
            padding: 1.25rem;
        }

        .appointment-header,
        .appointment-body {
            padding: 0.875rem;
        }

        .business-avatar {
            width: 40px;
            height: 40px;
            font-size: 1rem;
        }
    }

    /* Quick Actions */
    .quick-action-btn {
        width: 100%;
        padding: 0.875rem;
        border-radius: 12px;
        font-weight: 600;
        transition: all 0.3s;
    }
</style>

<div class="container">
    
    <?php echo $message; ?>

    <!-- İstatistik Kartları -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="d-flex align-items-start justify-content-between mb-3">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-calendar-event"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $pending_count; ?></div>
                <div class="stat-label">Yaklaşan</div>
            </div>
        </div>
        
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="d-flex align-items-start justify-content-between mb-3">
                    <div class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $total_appointments; ?></div>
                <div class="stat-label">Tamamlanan</div>
            </div>
        </div>
        
        <div class="col-12 col-md-4">
            <div class="cta-card">
                <h5 class="mb-2">Yeni Randevu</h5>
                <?php if($is_tenant_context): ?>
                    <a href="reservation.php" class="btn btn-light rounded-pill px-4 fw-bold text-primary">
                        Hemen Al <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                <?php else: ?>
                    <a href="isletmeler.php" class="btn btn-light rounded-pill px-4 fw-bold text-primary">
                        İşletme Bul <i class="bi bi-search ms-1"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Ana İçerik -->
    <div class="row g-4">
        <!-- Yaklaşan Randevular -->
        <div class="col-lg-7">
            <div class="section-header">
                <h5 class="section-title">
                    <i class="bi bi-hourglass-split text-primary"></i>
                    Yaklaşan Randevular
                </h5>
            </div>

            <?php if (empty($future_appointments)): ?>
                <div class="empty-state">
                    <i class="bi bi-calendar-x"></i>
                    <h6 class="text-muted mb-2">Henüz randevunuz yok</h6>
                    <p class="text-muted small mb-3">Yeni bir randevu oluşturarak başlayın</p>
                    <?php if(!$is_tenant_context): ?>
                        <a href="isletmeler.php" class="btn btn-primary-custom btn-sm">
                            <i class="bi bi-search me-1"></i> İşletme Bul
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($future_appointments as $app): 
                    $services = getAppointmentServices($db, $app['id']);
                    $status_class = ($app['status'] == 'confirmed') ? 'bg-success bg-opacity-10 text-success' : 'bg-warning bg-opacity-10 text-warning';
                    $status_text = ($app['status'] == 'confirmed') ? 'Onaylandı' : 'Bekliyor';
                ?>
                <div class="appointment-card">
                    <div class="appointment-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center gap-3">
                                <div class="business-avatar">
                                    <?php echo mb_substr($app['firma_adi'], 0, 1, 'UTF-8'); ?>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($app['firma_adi']); ?></h6>
                                    <small class="text-muted">
                                        <i class="bi bi-calendar3 me-1"></i>
                                        <?php echo date('d.m.Y', strtotime($app['appointment_date'])); ?> • 
                                        <i class="bi bi-clock me-1"></i>
                                        <?php echo date('H:i', strtotime($app['appointment_time'])); ?>
                                    </small>
                                </div>
                            </div>
                            <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                        </div>
                    </div>
                    <div class="appointment-body">
                        <div class="row align-items-center">
                            <div class="col-7 mb-2 mb-md-0">
                                <div class="small text-muted mb-1">Hizmetler</div>
                                <div class="fw-600 small"><?php echo implode(', ', $services); ?></div>
                                <?php if($app['employee_name']): ?>
                                    <small class="text-muted d-block mt-1">
                                        <i class="bi bi-person"></i> <?php echo htmlspecialchars($app['employee_name']); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <div class="col-5">
                                <div class="text-end">
                                    <div class="fw-bold fs-5 text-primary mb-2"><?php echo $app['total_price']; ?> ₺</div>
                                    <?php if($app['status'] == 'pending'): ?>
                                    <form method="POST" onsubmit="return confirm('İptal etmek istediğinize emin misiniz?');">
                                        <input type="hidden" name="cancel_appointment_id" value="<?php echo $app['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill w-100">
                                            <i class="bi bi-x-circle"></i> İptal
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Geçmiş -->
        <div class="col-lg-5">
            <div class="section-header">
                <h5 class="section-title">
                    <i class="bi bi-clock-history text-muted"></i>
                    Geçmiş
                </h5>
            </div>
            
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <?php if (empty($past_appointments)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-inbox fs-1 opacity-25"></i>
                        <p class="small mb-0 mt-2">Geçmiş kayıt bulunamadı</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($past_appointments as $past): ?>
                        <div class="history-item">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="fw-600 small"><?php echo htmlspecialchars($past['firma_adi']); ?></div>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill small">
                                    <?php 
                                    $status_map = ['completed' => 'Tamamlandı', 'cancelled' => 'İptal', 'no_show' => 'Gelmedi'];
                                    echo $status_map[$past['status']] ?? $past['status'];
                                    ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    <i class="bi bi-calendar3"></i>
                                    <?php echo date('d.m.Y', strtotime($past['appointment_date'])); ?>
                                </small>
                                <small class="fw-bold text-dark"><?php echo $past['total_price']; ?> ₺</small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php include 'includes/footer.php'; ?>