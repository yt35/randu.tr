<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/header.php';

$message = '';

if (isset($_POST['cancel_appointment_id'])) {
    Csrf::verifyOrDie();
    $cancel_id = intval($_POST['cancel_appointment_id']);
    
    try {
        $checkSql = "
            SELECT a.id, a.appointment_date, a.appointment_time, a.tenant_id,
                   t.firma_adi AS tenant_name, t.telefon AS tenant_phone,
                   COALESCE(ct.name, c.name) AS customer_name
            FROM appointments a
            LEFT JOIN tenants t ON a.tenant_id = t.id
            LEFT JOIN customers c ON a.customer_id = c.id
            LEFT JOIN customer_tenants ct ON c.id = ct.customer_id AND ct.tenant_id = a.tenant_id
            WHERE a.id = ? AND a.customer_id = ? AND a.status IN ('pending', 'confirmed')
        ";
        $checkParams = [$cancel_id, $customer_id];

        if ($is_tenant_context && defined('TENANT_ID')) {
            $checkSql .= " AND a.tenant_id = ?";
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
                $stmtUpdate = $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = ? AND customer_id = ? AND tenant_id = ?");
                $stmtUpdate->execute([$cancel_id, $customer_id, (int)$app['tenant_id']]);

                if (!empty($app['tenant_phone'])) {
                    $dateF = date('d.m.Y', strtotime($app['appointment_date']));
                    $timeF = date('H:i', strtotime($app['appointment_time']));
                    $businessName = truncateForSms($app['tenant_name'] ?? 'İşletme', 50);
                    $customerNameSms = truncateForSms($app['customer_name'] ?? 'Müşteri', 40);
                    $smsMessage = upperForSms($businessName) . "\n\n";
                    $smsMessage .= "Müşteri randevu iptal etti.\n\n";
                    $smsMessage .= "Müşteri: {$customerNameSms}\n";
                    $smsMessage .= "Tarih: {$dateF}\n";
                    $smsMessage .= "Saat: {$timeF}\n";
                    $smsMessage .= "Durum: İptal\n\n";
                    $smsMessage .= "RANDU.TR";
                    $smsResult = sendSms($app['tenant_phone'], $smsMessage, null, (int)$app['tenant_id']);
                    if (empty($smsResult['status'])) {
                        error_log('Business cancel SMS failed (customer/index): appointment=' . $cancel_id . ', tenant=' . (int)$app['tenant_id'] . ', reason=' . ($smsResult['message'] ?? 'unknown'));
                    }
                }
                $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-2"></i>Randevunuz başarıyla iptal edildi.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        } else {
            $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>İşlem başarısız.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    } catch (Exception $e) {
        error_log('Customer index.php error: ' . $e->getMessage());
        $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>Beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

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
        e.name as employee_name,
        (SELECT setting_value FROM settings WHERE tenant_id = t.id AND setting_key = 'site_logo' LIMIT 1) as tenant_logo
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

// Asset URL'lerini düzelt
foreach ($future_appointments as &$fa) {
    resolveAssetUrls($fa, ['tenant_logo']);
}
unset($fa);

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
    $stmt = $db->prepare("SELECT s.name, aps.variant_ids FROM appointment_services aps JOIN services s ON aps.service_id = s.id WHERE aps.appointment_id = ?");
    $stmt->execute([$appointment_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $label = $row['name'];
        if (!empty($row['variant_ids'])) {
            $v_ids = json_decode($row['variant_ids'], true);
            if (!empty($v_ids)) {
                $v_ph = implode(',', array_fill(0, count($v_ids), '?'));
                $stmtV = $db->prepare("SELECT name FROM service_variants WHERE id IN ($v_ph) AND tenant_id = ?");
                $v_ids[] = TENANT_ID;
                $stmtV->execute($v_ids);
                $v_names = $stmtV->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($v_names)) {
                    $label .= ' (' . implode(', ', $v_names) . ')';
                }
            }
        }
        $result[] = $label;
    }
    return $result;
}
?>

<link rel="stylesheet" href="/customer/assets/css/index.css">

<div class="container">
    
    <?php echo $message; ?>

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
                <div class="stat-label">Toplam</div>
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
                        İşletme Bul <i class="bi bi-search ms-1" style="font-size: 0.875rem;"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">
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
                            <i class="bi bi-search me-1" style="font-size: 0.875rem;"></i> İşletme Bul
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
                                <div class="business-avatar" style="<?php echo !empty($app['tenant_logo']) ? 'background: #fff; padding: 8px;' : ''; ?>">
                                    <?php if (!empty($app['tenant_logo'])): ?>
                                        <img src="<?php echo htmlspecialchars($app['tenant_logo']); ?>" 
                                             alt="<?php echo htmlspecialchars($app['firma_adi']); ?>" 
                                             style="width: 100%; height: 100%; object-fit: contain; border-radius: 8px;">
                                    <?php else: ?>
                                        <?php echo mb_substr($app['firma_adi'], 0, 1, 'UTF-8'); ?>
                                    <?php endif; ?>
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
                                    <?php if(in_array($app['status'], ['pending', 'confirmed'])): ?>
                                    <div class="d-flex gap-2 flex-column">
                                        <?php if($app['status'] == 'pending'): ?>
                                        <button type="button" class="btn btn-primary btn-sm rounded-pill reschedule-btn" 
                                            data-appointment-id="<?php echo $app['id']; ?>"
                                            data-business-name="<?php echo htmlspecialchars($app['firma_adi'], ENT_QUOTES); ?>"
                                            data-tenant-id="<?php echo $app['tenant_id']; ?>"
                                            data-employee-id="<?php echo $app['employee_id'] ?: ''; ?>"
                                            data-current-date="<?php echo $app['appointment_date']; ?>"
                                            data-current-time="<?php echo date('H:i', strtotime($app['appointment_time'])); ?>"
                                            data-tenant-logo="<?php echo htmlspecialchars($app['tenant_logo'] ?? '', ENT_QUOTES); ?>">
                                            <i class="bi bi-calendar-check"></i> Tarih Değiştir
                                        </button>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('İptal etmek istediğinize emin misiniz?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="cancel_appointment_id" value="<?php echo $app['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill w-100">
                                                <i class="bi bi-x-circle"></i> İptal
                                            </button>
                                        </form>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

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
                                <?php 
                                $status_map = [
                                    'completed' => ['text' => 'Tamamlandı', 'class' => 'bg-success bg-opacity-10 text-success'],
                                    'cancelled' => ['text' => 'İptal', 'class' => 'bg-danger bg-opacity-10 text-danger'],
                                    'no_show' => ['text' => 'Gelmedi', 'class' => 'bg-warning bg-opacity-10 text-warning']
                                ];
                                $status_info = $status_map[$past['status']] ?? ['text' => $past['status'], 'class' => 'bg-secondary bg-opacity-10 text-secondary'];
                                ?>
                                <span class="badge <?php echo $status_info['class']; ?> rounded-pill small">
                                    <?php echo $status_info['text']; ?>
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

<!-- Tarih Değiştir Modal -->
<div class="modal fade" id="rescheduleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-calendar-check text-primary me-2"></i>
                    Randevu Tarihini Değiştir
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="rescheduleAlert"></div>
                
                <div class="business-info bg-light rounded-3 p-3 mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="business-avatar" id="modalBusinessAvatar" style="display: flex;"></div>
                        <div>
                            <div class="fw-bold" id="modalBusinessName"></div>
                            <small class="text-muted">Randevu #<span id="modalAppointmentId"></span></small>
                        </div>
                    </div>
                </div>

                <form id="rescheduleForm">
                    <input type="hidden" id="reschedule_appointment_id" name="appointment_id">
                    <input type="hidden" id="reschedule_tenant_id" name="tenant_id">
                    <input type="hidden" id="reschedule_employee_id" name="employee_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-600">
                            <i class="bi bi-calendar3 text-primary me-1"></i> Yeni Tarih
                        </label>
                        <input type="date" class="form-control form-control-lg rounded-3" 
                            id="new_date" name="new_date" required
                            min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-600">
                            <i class="bi bi-clock text-primary me-1"></i> Yeni Saat
                        </label>
                        <select class="form-select form-select-lg rounded-3" 
                            id="new_time" name="new_time" required disabled>
                            <option value="">Önce tarih seçiniz...</option>
                        </select>
                        <div id="timeLoadingSpinner" class="text-center mt-2" style="display: none;">
                            <div class="spinner-border spinner-border-sm text-primary" role="status">
                                <span class="visually-hidden">Yükleniyor...</span>
                            </div>
                            <small class="text-muted ms-2">Uygun saatler yükleniyor...</small>
                        </div>
                    </div>

                    <div class="alert alert-info bg-opacity-10 border-0 rounded-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <small>Yeni randevu saatiniz onaylandığında SMS ile bilgilendirileceksiniz.</small>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold" id="rescheduleSubmitBtn">
                        <i class="bi bi-check-lg me-1"></i> Randevuyu Güncelle
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.business-avatar {
    width: 50px;
    height: 50px;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 700;
    text-transform: uppercase;
}

#rescheduleModal .modal-content {
    overflow: hidden;
}

.business-info {
    border: 1px solid #e9ecef;
}

#timeLoadingSpinner {
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
</style>

<script>
let rescheduleModal;

document.addEventListener('DOMContentLoaded', function() {
    rescheduleModal = new bootstrap.Modal(document.getElementById('rescheduleModal'));
    
    // Tarih değiştiğinde saatleri yükle
    document.getElementById('new_date').addEventListener('change', function() {
        loadAvailableTimes();
    });
    
    // Form submit
    document.getElementById('rescheduleForm').addEventListener('submit', function(e) {
        e.preventDefault();
        submitReschedule();
    });
    
    // Reschedule butonlarına event listener ekle
    document.querySelectorAll('.reschedule-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const appointmentId = this.dataset.appointmentId;
            const businessName = this.dataset.businessName;
            const tenantId = this.dataset.tenantId;
            const employeeId = this.dataset.employeeId;
            const currentDate = this.dataset.currentDate;
            const currentTime = this.dataset.currentTime;
            const tenantLogo = this.dataset.tenantLogo;
            
            openRescheduleModal(appointmentId, businessName, tenantId, employeeId, currentDate, currentTime, tenantLogo);
        });
    });
});

function openRescheduleModal(appointmentId, businessName, tenantId, employeeId, currentDate, currentTime, tenantLogo) {
    document.getElementById('reschedule_appointment_id').value = appointmentId;
    document.getElementById('reschedule_tenant_id').value = tenantId;
    document.getElementById('reschedule_employee_id').value = employeeId || '';
    
    document.getElementById('modalAppointmentId').textContent = appointmentId;
    document.getElementById('modalBusinessName').textContent = businessName;
    
    // Avatar'ı güncelle - logo varsa göster
    const avatarEl = document.getElementById('modalBusinessAvatar');
    if (tenantLogo) {
        avatarEl.style.background = '#fff';
        avatarEl.style.padding = '8px';
        avatarEl.innerHTML = `<img src="${tenantLogo}" alt="${businessName}" style="width: 100%; height: 100%; object-fit: contain; border-radius: 8px;">`;
    } else {
        avatarEl.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        avatarEl.style.padding = '0';
        avatarEl.textContent = businessName.charAt(0).toUpperCase();
    }
    
    // Mevcut tarihi set et
    const dateInput = document.getElementById('new_date');
    dateInput.value = currentDate;
    
    // Saatleri yükle ve mevcut saati seçili yap
    loadAvailableTimes(currentTime);
    
    document.getElementById('rescheduleAlert').innerHTML = '';
    
    rescheduleModal.show();
}

async function loadAvailableTimes(preselectedTime = null) {
    const date = document.getElementById('new_date').value;
    const tenantId = document.getElementById('reschedule_tenant_id').value;
    const employeeId = document.getElementById('reschedule_employee_id').value;
    const timeSelect = document.getElementById('new_time');
    const spinner = document.getElementById('timeLoadingSpinner');
    
    if (!date) return;
    
    console.log('Loading times for:', { date, tenantId, employeeId });
    console.log('TenantId type:', typeof tenantId, 'Value:', tenantId);
    console.log('Preselected time:', preselectedTime);
    
    // Show loading
    timeSelect.disabled = true;
    timeSelect.innerHTML = '<option value="">Yükleniyor...</option>';
    spinner.style.display = 'block';
    
    try {
        const url = `/get_available_times.php?date=${date}&tenant_id=${tenantId}${employeeId ? '&employee_id=' + employeeId : ''}`;
        console.log('Fetching URL:', url);
        
        const response = await fetch(url);
        const text = await response.text();
        console.log('Raw response:', text);
        
        const data = JSON.parse(text);
        console.log('Parsed data:', JSON.stringify(data, null, 2));
        console.log('Success:', data.success);
        console.log('Times:', data.times);
        console.log('Message:', data.message);
        
        spinner.style.display = 'none';
        
        if (data.success && data.times && data.times.length > 0) {
            timeSelect.innerHTML = '<option value="">Saat seçiniz...</option>';
            
            // Mevcut saati varsa ve listede yoksa ekle
            let allTimes = [...data.times];
            if (preselectedTime && !allTimes.includes(preselectedTime)) {
                allTimes.push(preselectedTime);
                allTimes.sort();
            }
            
            allTimes.forEach(time => {
                const option = document.createElement('option');
                option.value = time;
                option.textContent = time;
                // Mevcut saat ise işaretle
                if (time === preselectedTime) {
                    option.textContent += ' (Mevcut)';
                }
                timeSelect.appendChild(option);
            });
            timeSelect.disabled = false;
            
            // Mevcut saati seç
            if (preselectedTime) {
                timeSelect.value = preselectedTime;
            }
        } else {
            timeSelect.innerHTML = '<option value="">Bu tarihte uygun saat yok</option>';
            showAlert('warning', data.message || 'Seçtiğiniz tarihte uygun saat bulunamadı. Lütfen başka bir tarih seçin.');
        }
    } catch (error) {
        console.error('Error loading times:', error);
        spinner.style.display = 'none';
        timeSelect.innerHTML = '<option value="">Hata oluştu</option>';
        showAlert('danger', 'Saatler yüklenirken hata oluştu. Lütfen tekrar deneyin.');
    }
}

async function submitReschedule() {
    const form = document.getElementById('rescheduleForm');
    const submitBtn = document.getElementById('rescheduleSubmitBtn');
    const originalBtnText = submitBtn.innerHTML;
    
    // Disable button
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Güncelleniyor...';
    
    try {
        const formData = new FormData(form);
        
        const response = await fetch('ajax/reschedule_appointment.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert('success', data.message || 'Randevunuz başarıyla güncellendi! Sayfa yenileniyor...');
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showAlert('danger', data.message || 'Randevu güncellenemedi. Lütfen tekrar deneyin.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    } catch (error) {
        showAlert('danger', 'Bir hata oluştu. Lütfen tekrar deneyin.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
    }
}

function showAlert(type, message) {
    const alertDiv = document.getElementById('rescheduleAlert');
    const iconMap = {
        'success': 'check-circle',
        'warning': 'exclamation-triangle',
        'danger': 'x-circle',
        'info': 'info-circle'
    };
    const icon = iconMap[type] || 'info-circle';
    
    alertDiv.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="bi bi-${icon}-fill me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
}
</script>

<?php include 'includes/footer.php'; ?>
