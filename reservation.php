<?php
// AJAX/Normal istek ayrımı
$is_ajax = isset($_POST['ajax_action']) || isset($_POST['favorite_action']);

if ($is_ajax) {
    ini_set('display_errors', 0); 
    error_reporting(E_ALL);
    header('Content-Type: application/json');

    $config_loaded = false;
    $customer_config = __DIR__ . '/config/config.php';
    $root_config = $_SERVER['DOCUMENT_ROOT'] . '/config/config.php';
    $parent_config = dirname(dirname(__DIR__)) . '/config/config.php';

    if (file_exists($customer_config)) {
        require_once $customer_config;
        $config_loaded = true;
    } elseif (file_exists($root_config)) {
        require_once $root_config;
        $config_loaded = true;
    } elseif (file_exists($parent_config)) {
        require_once $parent_config;
        $config_loaded = true;
    }
    
    if (!$config_loaded || (!defined('DB_HOST') && !isset($db))) {
        echo json_encode(['status' => 'error', 'message' => 'Sistem hatası']);
        exit;
    }
    
    if (session_status() === PHP_SESSION_NONE) session_start();

} else {
    require_once 'includes/header.php';
}

$tenant_id = $is_ajax ? intval($_POST['tenant_id'] ?? 0) : intval($_GET['tenant_id'] ?? (defined('TENANT_ID') ? TENANT_ID : 0));

if ($tenant_id <= 0) {
    if ($is_ajax) { 
        echo json_encode(['status' => 'error', 'message' => 'Geçersiz İşletme']);
        exit;
    } else {
        echo "<script>window.location.href='isletmeler.php';</script>";
        exit;
    }
}

$customer_id = $_SESSION['customer_id'] ?? 0;

// YARDIMCI FONKSİYONLAR
if (!function_exists('getTenantSettings')) {
    function getTenantSettings($db, $tenant_id) {
        try {
            $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE tenant_id = ?");
            $stmt->execute([$tenant_id]);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) { return []; }
    }
}

if (!function_exists('sendTenantSms')) {
    function sendTenantSms($db, $tenant_id, $phone, $message) {
        try {
            $settings = getTenantSettings($db, $tenant_id);
            $isEnabled = ($settings['sms_notifications'] ?? 'off') === 'on';
            if (!$isEnabled) return false;
            return true;
        } catch(Exception $e) { return false; }
    }
}

// AJAX İŞLEMLERİ
if ($is_ajax) {
    try {
        // Favori
        if (isset($_POST['favorite_action'])) {
            if (!$customer_id) throw new Exception("Oturum gerekli");
            $type = $_POST['type'];
            $id = intval($_POST['id']);
            $action = $_POST['act'];
            
            if ($type == 'employee') {
                $sql = ($action == 'add') ? "INSERT IGNORE INTO customer_favorite_employees (customer_id, employee_id) VALUES (?, ?)" : "DELETE FROM customer_favorite_employees WHERE customer_id = ? AND employee_id = ?";
                $db->prepare($sql)->execute([$customer_id, $id]);
            } elseif ($type == 'service') {
                $sql = ($action == 'add') ? "INSERT IGNORE INTO customer_favorite_services (customer_id, service_id) VALUES (?, ?)" : "DELETE FROM customer_favorite_services WHERE customer_id = ? AND service_id = ?";
                $db->prepare($sql)->execute([$customer_id, $id]);
            }
            echo json_encode(['status' => 'success']);
            exit;
        }

        // Saatleri Getir
        if ($_POST['ajax_action'] == 'get_time_slots') {
            $date = $_POST['date'];
            $emp_id = intval($_POST['employee_id']);
            $srv_ids = $_POST['service_ids'] ?? '';
            
            $settings = getTenantSettings($db, $tenant_id);
            $use_duration = ($settings['use_service_duration'] ?? 'on') === 'on';
            $shop_start = $settings['working_hours_start'] ?? '09:00';
            $shop_end = $settings['working_hours_end'] ?? '20:00';
            $off_day = isset($settings['off_day']) ? intval($settings['off_day']) : 0;

            $duration = 30;
            if($use_duration && !empty($srv_ids)) {
                $ids = array_map('intval', explode(',', $srv_ids));
                if(!empty($ids)) {
                    $ph = str_repeat('?,', count($ids)-1).'?';
                    $stmtS = $db->prepare("SELECT SUM(duration) as td FROM services WHERE id IN ($ph)");
                    $stmtS->execute($ids);
                    $row = $stmtS->fetch();
                    if($row && $row['td']) $duration = intval($row['td']);
                }
            }

            if (strtotime($date) < strtotime(date('Y-m-d'))) throw new Exception('Geçmiş tarih');

            $stmtE = $db->prepare("SELECT id, working_hours_start, working_hours_end, working_days FROM employees WHERE tenant_id = ? AND status = 'active'");
            $stmtE->execute([$tenant_id]);
            $all_emps = $stmtE->fetchAll(PDO::FETCH_ASSOC);

            $target_emps = [];
            if ($emp_id > 0) {
                foreach($all_emps as $ae) if($ae['id'] == $emp_id) $target_emps[] = $ae;
            } else {
                $target_emps = $all_emps;
            }

            if(empty($target_emps)) {
                echo json_encode(['status'=>'success','slots'=>[]]);
                exit;
            }

            $stmtA = $db->prepare("SELECT employee_id, appointment_time, total_duration FROM appointments WHERE tenant_id = ? AND appointment_date = ? AND status IN ('pending','confirmed')");
            $stmtA->execute([$tenant_id, $date]);
            $apps = $stmtA->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);

            $slots = [];
            $curr = strtotime("$date $shop_start");
            $end = strtotime("$date $shop_end");
            $day_idx = date('w', strtotime($date));

            if ($day_idx == $off_day) {
                echo json_encode(['status'=>'success','slots'=>[]]);
                exit;
            }

            while ($curr + ($duration * 60) <= $end) {
                $time_str = date('H:i', $curr);
                $s_start = $curr;
                $s_end = $curr + ($duration*60);
                $available = false;
                
                foreach($target_emps as $e) {
                    $days = !empty($e['working_days']) ? array_map('intval', explode(',', $e['working_days'])) : [0,1,2,3,4,5,6];
                    if(!in_array($day_idx, $days)) continue;
                    $e_start = strtotime("$date " . ($e['working_hours_start'] ?: '09:00'));
                    $e_end = strtotime("$date " . ($e['working_hours_end'] ?: '20:00'));
                    if($s_start < $e_start || $s_end > $e_end) continue;
                    
                    $busy = false;
                    if(isset($apps[$e['id']])) {
                        foreach($apps[$e['id']] as $ap) {
                            $ap_s = strtotime("$date ".$ap['appointment_time']);
                            $ap_d = $ap['total_duration'] ?: 30;
                            $ap_e = $ap_s + ($ap_d*60);
                            if($s_start < $ap_e && $s_end > $ap_s) {
                                $busy = true;
                                break;
                            }
                        }
                    }
                    if(!$busy) {
                        $available = true;
                        break;
                    }
                }
                
                if($date == date('Y-m-d') && $curr < time()) $available = false;
                $slots[] = ['time'=>$time_str, 'full'=>!$available];
                $curr = strtotime("+30 minutes", $curr);
            }
            echo json_encode(['status'=>'success', 'slots'=>$slots]);
            exit;

        // Randevu Oluştur
        } elseif ($_POST['ajax_action'] == 'create_appointment') {
            if (!$customer_id) throw new Exception("Oturum süresi dolmuş");
            
            $s_ids_str = $_POST['service_ids'] ?? '';
            $e_id_in = intval($_POST['employee_id']);
            $date = $_POST['date'];
            $time = $_POST['time'];

            if(empty($s_ids_str)) throw new Exception("Hizmet seçilmedi");

            $db->beginTransaction();
            $stmtRel = $db->prepare("INSERT IGNORE INTO customer_tenants (customer_id, tenant_id) VALUES (?, ?)");
            $stmtRel->execute([$customer_id, $tenant_id]);

            $s_ids = array_map('intval', explode(',', $s_ids_str));
            $ph = str_repeat('?,', count($s_ids)-1).'?';
            $stmtS = $db->prepare("SELECT id, price, duration FROM services WHERE id IN ($ph)");
            $stmtS->execute($s_ids);
            $sel_srvs = $stmtS->fetchAll(PDO::FETCH_ASSOC);

            $total_price = 0;
            $total_duration = 0;
            $settings = getTenantSettings($db, $tenant_id);
            $use_dur = ($settings['use_service_duration'] ?? 'on') === 'on';

            foreach($sel_srvs as $s) {
                $total_price += $s['price'];
                $total_duration += $s['duration'];
            }
            if(!$use_dur) $total_duration = 30;

            $final_emp_id = $e_id_in;
            if($final_emp_id == 0) {
                $stmtE = $db->prepare("SELECT id FROM employees WHERE tenant_id = ? AND status='active' ORDER BY RAND() LIMIT 1");
                $stmtE->execute([$tenant_id]);
                $final_emp_id = $stmtE->fetchColumn();
            }

            $stmtApp = $db->prepare("INSERT INTO appointments (tenant_id, customer_id, employee_id, appointment_date, appointment_time, status, total_price, total_duration, created_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, NOW())");
            $stmtApp->execute([$tenant_id, $customer_id, $final_emp_id, $date, $time.':00', $total_price, $total_duration]);
            $app_id = $db->lastInsertId();

            $stmtL = $db->prepare("INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)");
            foreach($s_ids as $sid) $stmtL->execute([$app_id, $sid]);

            $db->commit();
            
            echo json_encode(['status'=>'success', 'message'=>'Randevunuz oluşturuldu']);
            exit;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]);
        exit;
    }
}

// NORMAL SAYFA
$stmtT = $db->prepare("SELECT * FROM tenants WHERE id = ?");
$stmtT->execute([$tenant_id]);
$tenant = $stmtT->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    echo "<div class='container py-5'>İşletme bulunamadı.</div>";
    include 'includes/footer.php';
    exit;
}

$settings = getTenantSettings($db, $tenant_id);
$show_service_duration = ($settings['show_service_duration'] ?? 'on') === 'on';

$services = $db->prepare("SELECT * FROM services WHERE tenant_id = ? AND status='active' ORDER BY price ASC");
$services->execute([$tenant_id]);
$services = $services->fetchAll(PDO::FETCH_ASSOC);

$my_fav_services = $db->query("SELECT service_id FROM customer_favorite_services WHERE customer_id = $customer_id")->fetchAll(PDO::FETCH_COLUMN);

$employees = $db->prepare("SELECT * FROM employees WHERE tenant_id = ? AND status='active'");
$employees->execute([$tenant_id]);
$employees = $employees->fetchAll(PDO::FETCH_ASSOC);

$my_fav_employees = $db->query("SELECT employee_id FROM customer_favorite_employees WHERE customer_id = $customer_id")->fetchAll(PDO::FETCH_COLUMN);
?>

<style>
    /* Tenant Header */
    .tenant-header {
        background: linear-gradient(135deg, #e0e7ff 0%, #dbeafe 100%);
        padding: 1.5rem 1rem;
        border-radius: 20px;
        margin-bottom: 1.5rem;
    }

    .tenant-avatar {
        width: 64px;
        height: 64px;
        background: white;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: 700;
        color: var(--accent-color);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    /* Service Cards */
    .service-item {
        background: white;
        border: 2px solid var(--border-color);
        border-radius: 14px;
        padding: 1rem;
        margin-bottom: 0.75rem;
        transition: all 0.3s;
        position: relative;
    }

    .service-checkbox {
        display: none;
    }

    .service-checkbox:checked + .service-item {
        border-color: var(--accent-color);
        background: #eff6ff;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
    }

    .service-img {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        object-fit: cover;
        background: #f1f5f9;
    }

    .service-info {
        flex: 1;
        padding: 0 1rem;
    }

    .service-name {
        font-weight: 600;
        font-size: 0.95rem;
        margin-bottom: 0.25rem;
    }

    .service-duration {
        font-size: 0.8rem;
        color: var(--text-muted);
    }

    .service-price {
        font-weight: 700;
        color: var(--accent-color);
        font-size: 1.1rem;
    }

    .fav-btn {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: white;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #cbd5e1;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        z-index: 10;
    }

    .fav-btn.active {
        color: #ef4444;
    }

    /* Employee Grid */
    .employee-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: 0.75rem;
    }

    .employee-item {
        background: white;
        border: 2px solid var(--border-color);
        border-radius: 14px;
        padding: 1rem 0.5rem;
        text-align: center;
        transition: all 0.3s;
        position: relative;
    }

    .employee-radio {
        display: none;
    }

    .employee-radio:checked + .employee-item {
        border-color: var(--accent-color);
        background: #eff6ff;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
    }

    .employee-img {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        object-fit: cover;
        margin: 0 auto 0.5rem;
        border: 3px solid #f8fafc;
    }

    .employee-name {
        font-weight: 600;
        font-size: 0.85rem;
        margin-bottom: 0.25rem;
    }

    .employee-role {
        font-size: 0.7rem;
        color: var(--text-muted);
    }

    /* Time Slots */
    .time-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
        gap: 0.75rem;
    }

    .time-slot {
        padding: 1rem 0.5rem;
        text-align: center;
        border: 2px solid var(--border-color);
        border-radius: 12px;
        background: white;
        font-weight: 600;
        transition: all 0.2s;
        cursor: pointer;
    }

    .time-slot:not(.disabled):hover {
        border-color: var(--accent-color);
        background: #eff6ff;
    }

    .time-slot.selected {
        background: var(--accent-color);
        color: white;
        border-color: var(--accent-color);
    }

    .time-slot.disabled {
        background: #f1f5f9;
        color: #cbd5e1;
        cursor: not-allowed;
        text-decoration: line-through;
    }

    /* Bottom Bar */
    .bottom-bar {
        position: fixed;
        bottom: 70px;
        left: 0;
        right: 0;
        background: white;
        border-top: 1px solid var(--border-color);
        padding: 1rem;
        box-shadow: 0 -4px 16px rgba(0,0,0,0.08);
        z-index: 100;
    }

    .step-indicator {
        display: flex;
        justify-content: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }

    .step-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #e2e8f0;
        transition: all 0.3s;
    }

    .step-dot.active {
        width: 24px;
        border-radius: 4px;
        background: var(--accent-color);
    }

    /* Mobile Adjustments */
    @media (max-width: 767.98px) {
        .tenant-header {
            padding: 1.25rem 1rem;
        }

        .tenant-avatar {
            width: 56px;
            height: 56px;
            font-size: 1.75rem;
        }

        .service-img {
            width: 48px;
            height: 48px;
        }

        .employee-grid {
            grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
        }

        .time-grid {
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
        }
    }

    @media (min-width: 992px) {
        .bottom-bar {
            bottom: 0;
        }
    }

    /* Loading */
    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }

    .loading-overlay.show {
        display: flex;
    }

    .spinner {
        width: 48px;
        height: 48px;
        border: 4px solid white;
        border-top-color: transparent;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }
</style>

<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

<div class="container pb-5">
    <!-- Tenant Header -->
    <div class="tenant-header">
        <div class="d-flex align-items-center">
            <div class="tenant-avatar me-3">
                <?php echo mb_substr($tenant['firma_adi'] ?? '?', 0, 1, 'UTF-8'); ?>
            </div>
            <div>
                <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($tenant['firma_adi'] ?? ''); ?></h5>
                <p class="text-muted small mb-0">
                    <i class="bi bi-geo-alt-fill text-danger"></i> 
                    <?php echo htmlspecialchars(($tenant['il'] ?? '') . ', ' . ($tenant['ilce'] ?? '')); ?>
                </p>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-3 p-md-4">
            
            <!-- STEP 1: Hizmet Seçimi -->
            <div id="step1">
                <h6 class="fw-bold mb-3">
                    <i class="bi bi-scissors text-primary me-2"></i>
                    Hizmet Seçin
                </h6>
                <div id="servicesList">
                    <?php foreach($services as $s): 
                        $is_fav = in_array($s['id'], $my_fav_services);
                        $srv_img = !empty($s['image']) 
                            ? (str_starts_with($s['image'], 'assets/')
                            ? '/' . ltrim($s['image'], '/')
                            : "/assets/uploads/tenants/{$tenant_id}/services/" . $s['image'])
                            : 'https://placehold.co/56x56/e2e8f0/888?text=S';
                    ?>
                    <label class="d-block">
                        <input type="checkbox" class="service-checkbox" value="<?php echo $s['id']; ?>" 
                               data-price="<?php echo $s['price']; ?>" 
                               data-name="<?php echo htmlspecialchars($s['name']); ?>">
                        <div class="service-item d-flex align-items-center">
                            <button type="button" class="fav-btn <?php echo $is_fav ? 'active' : ''; ?>" 
                                    onclick="event.preventDefault(); toggleFavorite(event, 'service', <?php echo $s['id']; ?>, this)">
                                <i class="bi <?php echo $is_fav ? 'bi-heart-fill' : 'bi-heart'; ?>"></i>
                            </button>
                            <img src="<?php echo $srv_img; ?>" class="service-img" alt="">
                            <div class="service-info">
                                <div class="service-name"><?php echo htmlspecialchars($s['name']); ?></div>
                                <?php if($show_service_duration): ?>
                                    <div class="service-duration">
                                        <i class="bi bi-clock"></i> <?php echo $s['duration']; ?> dk
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="service-price"><?php echo $s['price']; ?> ₺</div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <h6 class="fw-bold mb-3 mt-4">
                    <i class="bi bi-person-badge text-primary me-2"></i>
                    Personel Tercihi
                </h6>
                <div class="employee-grid">
                    <label>
                        <input type="radio" name="employee_id" class="employee-radio" value="0" checked onchange="handleEmpChange()">
                        <div class="employee-item">
                            <img src="https://placehold.co/60x60/e2e8f0/888?text=?" class="employee-img" alt="">
                            <div class="employee-name">Farketmez</div>
                            <div class="employee-role">Otomatik</div>
                        </div>
                    </label>

                    <?php foreach($employees as $e): 
                        $emp_img = !empty($e['photo']) 
                            ? (str_starts_with($e['photo'], 'assets/')
                            ? '/' . ltrim($e['photo'], '/')
                            : "/assets/uploads/tenants/{$tenant_id}/employees/" . ltrim($e['photo'], '/'))
                            : 'https://placehold.co/60x60/e2e8f0/888?text='.mb_substr($e['name'],0,1);
                        $is_fav_emp = in_array($e['id'], $my_fav_employees);
                    ?>
                    <label class="position-relative">
                        <input type="radio" name="employee_id" class="employee-radio" value="<?php echo $e['id']; ?>" onchange="handleEmpChange()">
                        <div class="employee-item">
                            <button type="button" class="fav-btn <?php echo $is_fav_emp ? 'active' : ''; ?>" 
                                    onclick="event.preventDefault(); toggleFavorite(event, 'employee', <?php echo $e['id']; ?>, this)">
                                <i class="bi <?php echo $is_fav_emp ? 'bi-heart-fill' : 'bi-heart'; ?>"></i>
                            </button>
                            <img src="<?php echo $emp_img; ?>" class="employee-img" alt="">
                            <div class="employee-name"><?php echo htmlspecialchars($e['name']); ?></div>
                            <div class="employee-role">Uzman</div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" id="employeeSelect" value="0">
            </div>

            <!-- STEP 2: Tarih/Saat -->
            <div id="step2" style="display:none;">
                <button onclick="showStep(1)" class="btn btn-sm btn-light mb-3">
                    <i class="bi bi-arrow-left"></i> Geri
                </button>
                <h6 class="fw-bold mb-3">
                    <i class="bi bi-calendar3 text-primary me-2"></i>
                    Tarih ve Saat Seçin
                </h6>
                <input type="date" id="dateSelect" class="form-control mb-3" 
                       min="<?php echo date('Y-m-d'); ?>" 
                       value="<?php echo date('Y-m-d'); ?>">
                <div id="slots" class="time-grid"></div>
                <input type="hidden" id="selectedTime">
            </div>

            <!-- STEP 3: Onay -->
            <div id="step3" style="display:none;">
                <button onclick="showStep(2)" class="btn btn-sm btn-light mb-3">
                    <i class="bi bi-arrow-left"></i> Geri
                </button>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    Randevunuz <b><?php echo htmlspecialchars($_SESSION['customer_name'] ?? 'Müşteri'); ?></b> adına oluşturulacaktır.
                </div>
                <div class="bg-light p-3 rounded-3 mb-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Tarih:</span>
                        <strong id="confDate"></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Saat:</span>
                        <strong id="confTime"></strong>
                    </div>
                    <div class="d-flex justify-content-between pt-2 border-top">
                        <span class="text-muted">Toplam:</span>
                        <strong id="confPrice" class="text-primary fs-5"></strong>
                    </div>
                </div>
                <button onclick="createApp()" class="btn btn-success btn-lg w-100 fw-bold py-3">
                    <i class="bi bi-check-circle me-2"></i>
                    Randevuyu Onayla
                </button>
            </div>

        </div>
    </div>
</div>

<!-- Bottom Bar -->
<div class="bottom-bar" id="bottomBar" style="display:none;">
    <div class="step-indicator">
        <div class="step-dot active" id="dot1"></div>
        <div class="step-dot" id="dot3"></div>
    </div>
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <small class="text-muted d-block">Toplam</small>
            <span class="fw-bold fs-5 text-primary" id="totalPriceBar">0 ₺</span>
        </div>
        <button class="btn btn-primary-custom px-4" onclick="nextStep(2)" id="btnContinue" disabled>
            Devam Et <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const tenantId = <?php echo $tenant_id; ?>;
let selectedServices = [];
let currentStep = 1;

function toggleFavorite(e, type, id, btn) {
    e.preventDefault();
    e.stopPropagation();

    const icon = btn.querySelector('i');
    const isAdding = !btn.classList.contains('active');
    const act = isAdding ? 'add' : 'remove';

    const fd = new FormData();
    fd.append('favorite_action', 1);
    fd.append('type', type);
    fd.append('id', id);
    fd.append('act', act);
    fd.append('tenant_id', tenantId);

    fetch('reservation.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res.status === 'success') {
            btn.classList.toggle('active');
            if(isAdding) {
                icon.classList.replace('bi-heart', 'bi-heart-fill');
            } else {
                icon.classList.replace('bi-heart-fill', 'bi-heart');
            }
        }
    });
}

function handleEmpChange() {
    const val = document.querySelector('input[name="employee_id"]:checked').value;
    document.getElementById('employeeSelect').value = val;
    if(document.getElementById('step2').style.display !== 'none') fetchSlots();
}

document.querySelectorAll('.service-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
        const s = { id: this.value, name: this.dataset.name, price: parseFloat(this.dataset.price) };
        if(this.checked) {
            selectedServices.push(s);
        } else {
            selectedServices = selectedServices.filter(i => i.id !== s.id);
        }
        
        let total = 0;
        selectedServices.forEach(s => total += s.price);
        document.getElementById('totalPriceBar').innerText = total + ' ₺';
        const btn = document.getElementById('btnContinue');
        btn.disabled = selectedServices.length === 0;
        
        // Bottom bar göster/gizle
        document.getElementById('bottomBar').style.display = 
            selectedServices.length > 0 ? 'block' : 'none';
    });
});

function showStep(n) {
    currentStep = n;
    document.getElementById('step1').style.display = n==1?'block':'none';
    document.getElementById('step2').style.display = n==2?'block':'none';
    document.getElementById('step3').style.display = n==3?'block':'none';
    
    // Step indicators
    ['dot1','dot2','dot3'].forEach((id, i) => {
        document.getElementById(id).classList.toggle('active', i+1 === n);
    });
    
    // Bottom bar visibility
    const bar = document.getElementById('bottomBar');
    if(n === 1 && selectedServices.length > 0) {
        bar.style.display = 'block';
    } else {
        bar.style.display = 'none';
    }
    
    // Scroll to top
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function nextStep(step) {
    if(step === 2) fetchSlots();
    if(step === 3) {
        let total = 0;
        selectedServices.forEach(s => total += s.price);
        document.getElementById('confDate').innerText = document.getElementById('dateSelect').value;
        document.getElementById('confTime').innerText = document.getElementById('selectedTime').value;
        document.getElementById('confPrice').innerText = total + ' ₺';
    }
    showStep(step);
}

document.getElementById('dateSelect').addEventListener('change', fetchSlots);

function fetchSlots() {
    let d = document.getElementById('dateSelect').value;
    let e = document.getElementById('employeeSelect').value;
    let sIds = selectedServices.map(s => s.id).join(',');
    
    let box = document.getElementById('slots');
    box.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary"></div></div>';

    let fd = new FormData();
    fd.append('ajax_action', 'get_time_slots');
    fd.append('tenant_id', tenantId);
    fd.append('date', d);
    fd.append('employee_id', e);
    fd.append('service_ids', sIds);

    fetch('reservation.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        box.innerHTML = '';
        if(res.status==='success') {
            if(res.slots.length===0) {
                box.innerHTML = '<div class="alert alert-warning">Bu tarih için uygun saat bulunmamaktadır.</div>';
            } else {
                res.slots.forEach(s => {
                    let btn = document.createElement('button');
                    btn.className = 'time-slot';
                    btn.innerText = s.time;
                    if(s.full) {
                        btn.classList.add('disabled');
                        btn.disabled = true;
                    } else {
                        btn.onclick = () => {
                            document.querySelectorAll('.time-slot').forEach(b => b.classList.remove('selected'));
                            btn.classList.add('selected');
                            document.getElementById('selectedTime').value = s.time;
                            setTimeout(() => nextStep(3), 300);
                        };
                    }
                    box.appendChild(btn);
                });
            }
        } else {
            box.innerHTML = '<div class="alert alert-danger">Hata: ' + res.message + '</div>';
        }
    })
    .catch(() => box.innerHTML = '<div class="alert alert-danger">Bağlantı hatası.</div>');
}

function createApp() {
    const overlay = document.getElementById('loadingOverlay');
    overlay.classList.add('show');

    let fd = new FormData();
    fd.append('ajax_action', 'create_appointment');
    fd.append('tenant_id', tenantId);
    fd.append('date', document.getElementById('dateSelect').value);
    fd.append('time', document.getElementById('selectedTime').value);
    fd.append('employee_id', document.getElementById('employeeSelect').value);
    fd.append('service_ids', selectedServices.map(s => s.id).join(','));

    fetch('reservation.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        overlay.classList.remove('show');
        if(data.status==='success') {
            alert('✓ Randevunuz başarıyla oluşturuldu!');
            window.location.href = 'index.php';
        } else {
            alert('✗ ' + data.message);
        }
    })
    .catch(() => {
        overlay.classList.remove('show');
        alert('✗ Bir hata oluştu.');
    });
}
</script>

<?php include 'includes/footer.php'; ?>dot2"></div>
        <div class="step-dot" id="