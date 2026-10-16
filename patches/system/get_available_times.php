<?php
/**
 * get_available_times.php
 * Ayarlardaki randevu süresine göre dinamik saat dilimleri oluşturur
 */

require_once 'config/config.php';
header('Content-Type: application/json');

// Log: varsayılan kapalı (disk/IO yükü). Açmak için env RANDU_DEBUG_TIMES=1
function writeLog($message) {
    $debug = (getenv('RANDU_DEBUG_TIMES') === '1')
        || (defined('RANDU_DEBUG_TIMES') && RANDU_DEBUG_TIMES);
    $isError = (stripos($message, 'ERROR') !== false || stripos($message, 'FATAL') !== false);
    if (!$debug && !$isError) {
        return;
    }
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/get_available_times.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Debug: Gelen parametreleri logla
writeLog("=== NEW REQUEST ===");
writeLog("GET params: " . json_encode($_GET));
writeLog("POST params: " . json_encode($_POST));

// --- TENANT KONTROLÜ (GÜVENLİK) ---
$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : (isset($_POST['tenant_id']) ? intval($_POST['tenant_id']) : 0);

writeLog("Tenant ID after parsing: " . $tenant_id);

if ($tenant_id <= 0) {
    writeLog("ERROR: Invalid tenant_id");
    echo json_encode(['success' => false, 'message' => 'Hata: İşletme kimliği doğrulanamadı.']);
    exit;
}

// TENANT_ID sabitini güvenli şekilde tanımla
if (!defined('TENANT_ID')) {
    define('TENANT_ID', $tenant_id);
} else {
    // ANA DOMAİN DÜZELTMESİ:
    // config.php TENANT_ID sabitini ana domaine (randu.tr) göre yanlış belirlemiş olabilir.
    // İstekten spesifik olarak tenant_id (ör. 27) geldiyse o değeri kullanmalıyız.
    // Bu yüzden $tenant_id değerini TENANT_ID sabiti ile EZMİYORUZ!
    writeLog("TENANT_ID defined as " . TENANT_ID . " but using explicit request tenant_id: " . $tenant_id);
}

$date = $_GET['date'] ?? $_POST['date'] ?? '';
$employee_id = $_GET['employee_id'] ?? $_POST['employee_id'] ?? 'any';
$total_duration = isset($_GET['duration']) ? intval($_GET['duration']) : (isset($_POST['duration']) && $_POST['duration'] > 0 ? intval($_POST['duration']) : 30);
// HATA ÇÖZÜMÜ: Loglarda hata vermemesi için service_ids_param değişkeni en üstte tanımlandı
$service_ids_param = $_GET['service_ids'] ?? $_POST['service_ids'] ?? '';

if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz tarih formatı.']);
    exit;
}

// Maksimum randevu tarihi kontrolü
$stmt_max = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'max_booking_days' AND tenant_id = ?");
$stmt_max->execute([$tenant_id]);
$max_booking_days = intval($stmt_max->fetchColumn() ?: 90);
$max_date = date('Y-m-d', strtotime("+{$max_booking_days} days"));
if ($date > $max_date) {
    echo json_encode(['success' => false, 'message' => 'Bu tarih için randevu alınamaz. Maksimum ' . $max_booking_days . ' gün sonrasına randevu alabilirsiniz.']);
    exit;
}

// Bugünün tarihi ve şu anki saat
$today = date('Y-m-d');
$current_time = time();
$is_today = ($date === $today);

try {
    // YENI: Hizmet süresi kontrolü ayarını al (TENANT_ID filtreleme ile)
    $stmt_enforce = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'enforce_service_duration' AND tenant_id = ?");
    $stmt_enforce->execute([$tenant_id]);
    $enforce_service_duration = $stmt_enforce->fetchColumn();
    // Varsayılan olarak açık
    $enforce_service_duration = ($enforce_service_duration !== false) ? ($enforce_service_duration == 'on') : true;

    // Ayarlardan randevu süresini al (varsayılan 30 dakika) - TENANT_ID filtreleme ile
    $stmt_settings = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'appointment_duration' AND tenant_id = ?");
    $stmt_settings->execute([$tenant_id]);
    $appointment_duration = $stmt_settings->fetchColumn();
    $slot_duration = $appointment_duration ? intval($appointment_duration) : 30;
    
    $day_of_week = date('N', strtotime($date)); // ISO: 1=Pazartesi, 7=Pazar

    // İşletme çalışma takvimini al (gün gün açık/kapalı + saatler)
    $stmt_schedule = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'working_hours_schedule' AND tenant_id = ?");
    $stmt_schedule->execute([$tenant_id]);
    $schedule_json = $stmt_schedule->fetchColumn();
    $business_schedule = $schedule_json ? json_decode($schedule_json, true) : null;
    
    // Çalışanın özel saati (custom_hours exception) var mı? — işletme kapalı günlerde bypass için
    $fn_emp_has_custom_hours = function() use ($db, $tenant_id, $employee_id, $date) {
        try {
            if ($employee_id !== 'any') {
                $s = $db->prepare("SELECT COUNT(*) FROM schedule_exceptions WHERE tenant_id=? AND exception_date=? AND exception_type='custom_hours' AND (employee_id IS NULL OR employee_id=?)");
                $s->execute([$tenant_id, $date, $employee_id]);
            } else {
                $s = $db->prepare("SELECT COUNT(*) FROM schedule_exceptions WHERE tenant_id=? AND exception_date=? AND exception_type='custom_hours'");
                $s->execute([$tenant_id, $date]);
            }
            return (int)$s->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    // İşletme bu gün açık mı kontrol et
    if ($business_schedule && isset($business_schedule[$day_of_week])) {
        $day_schedule = $business_schedule[$day_of_week];
        if (empty($day_schedule['open'])) {
            // İşletme kapalı — ama çalışanın özel saati varsa yine de devam et
            if (!$fn_emp_has_custom_hours()) {
                writeLog("Business closed on day $day_of_week");
                echo json_encode(['success' => true, 'times' => [], 'message' => 'İşletme bu gün kapalıdır.']);
                exit;
            }
            writeLog("Business closed day $day_of_week but employee has custom_hours override");
            // İşletme kısıtını kaldır — çalışan saati tam günü kapsasın
            $business_start = '00:00';
            $business_end   = '23:59';
        } else {
            $business_start = $day_schedule['start'] ?? '09:00';
            $business_end   = $day_schedule['end']   ?? '18:00';
        }
    } else {
        // Schedule yoksa varsayılan: Pazar kapalı
        if ($day_of_week == 7 && !$fn_emp_has_custom_hours()) {
            echo json_encode(['success' => true, 'times' => [], 'message' => 'İşletme bu gün kapalıdır.']);
            exit;
        }
        // Fallback: Ayarlar yoksa 09:00-18:00 varsayılanını kullan
        $business_start = '09:00';
        $business_end   = '18:00';
    }
    $biz_start_ts = strtotime($date . ' ' . $business_start);
    $biz_end_ts = strtotime($date . ' ' . $business_end);
    if ($biz_end_ts <= $biz_start_ts) {
        // 00:00 gibi değerler gece yarısını geçen pencereyi temsil edebilir.
        $biz_end_ts = strtotime('+1 day', $biz_end_ts);
    }
    $biz_extra_blocks = [];
    $biz_custom_windows = [];

    // ── SCHEDULE EXCEPTIONS: İşletme seviyesi ──────────────────────────────
    // Tablo yoksa (yeni kurulum) exception'lar sessizce atlanır
    $biz_exceptions_for_date = [];
    try {
        $stmt_bex = $db->prepare("
            SELECT * FROM schedule_exceptions
            WHERE tenant_id = ? AND employee_id IS NULL AND exception_date = ?
        ");
        $stmt_bex->execute([$tenant_id, $date]);
        $biz_exceptions_for_date = $stmt_bex->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $excTableEx) {
        // schedule_exceptions tablosu henüz oluşturulmamış, devam et
    }

    $has_business_closed_exception = false;
    foreach ($biz_exceptions_for_date as $bex) {
        if ($bex['exception_type'] === 'business_closed') {
            $has_business_closed_exception = true;
            writeLog("Business exception flag: closed on $date");
        }
        if ($bex['exception_type'] === 'custom_hours' && $bex['custom_start'] && $bex['custom_end']) {
            // Aynı gün birden fazla özel saat penceresi olabilir (örn. 00:00-04:00 + 09:00-00:00).
            $exc_start = strtotime($date . ' ' . $bex['custom_start']);
            $exc_end   = strtotime($date . ' ' . $bex['custom_end']);
            if ($exc_end <= $exc_start) {
                $exc_end = strtotime('+1 day', $exc_end);
            }
            $biz_custom_windows[] = ['start' => $exc_start, 'end' => $exc_end];
            writeLog("Business exception: custom_hours {$bex['custom_start']}-{$bex['custom_end']} on $date");
        }
        if ($bex['exception_type'] === 'time_block' && $bex['block_start'] && $bex['block_end']) {
            $bs = strtotime($date . ' ' . $bex['block_start']);
            $be = strtotime($date . ' ' . $bex['block_end']);
            if ($be <= $bs) {
                $be = strtotime('+1 day', $be);
            }
            $biz_extra_blocks[] = ['start' => $bs, 'end' => $be];
        }
    }

    // Öncelik kuralı:
    // 1) Business custom_hours varsa gün açıktır (business_closed olsa bile)
    // 2) Business custom_hours yoksa business_closed günü kapatır
    if ($has_business_closed_exception && empty($biz_custom_windows)) {
        writeLog("Business exception effective: closed on $date");
        echo json_encode(['success' => true, 'times' => [], 'message' => 'İşletme bu gün kapalıdır.']);
        exit;
    }

    $business_windows = !empty($biz_custom_windows)
        ? $biz_custom_windows
        : [['start' => $biz_start_ts, 'end' => $biz_end_ts]];
    // ───────────────────────────────────────────────────────────────────────

    // Mevcut randevuları çek (TENANT_ID filtreleme ile) - pending_verification hariç!
    // Çöp kutusundaki (soft-deleted) randevular hariç tutulur ki silinen randevular slotu bloklamasın.
    $stmt_booked = $db->prepare("
        SELECT employee_id, appointment_time, total_duration 
        FROM appointments 
        WHERE appointment_date = ? AND tenant_id = ? 
        AND deleted_at IS NULL
        AND status IN ('pending', 'confirmed', 'pending_verification')
        AND NOT (status = 'pending_verification' AND verification_code_expires < NOW())
    ");
    $stmt_booked->execute([$date, $tenant_id]);
    $booked_slots_raw = $stmt_booked->fetchAll(PDO::FETCH_ASSOC);
    
    // Sepetteki (cart) öğeleri de meşgul slot olarak ekle
    $cart_items_json = $_POST['cart_items'] ?? '';
    $cart_items = [];
    if (!empty($cart_items_json)) {
        $cart_items = json_decode($cart_items_json, true);
        if (is_array($cart_items)) {
            foreach ($cart_items as $ci) {
                $ci_emp = intval($ci['employee_id'] ?? 0);
                $ci_time = $ci['time'] ?? '';
                $ci_dur = intval($ci['duration'] ?? $slot_duration);
                if (empty($ci_time)) continue;
                $virtual = [
                    'employee_id' => $ci_emp,
                    'appointment_time' => $ci_time,
                    'total_duration' => $ci_dur
                ];
                if ($ci_emp === 0) {
                    $virtual['_farketmez'] = true;
                }
                $booked_slots_raw[] = $virtual;
            }
        }
    }

    $booked_slots = [];
    $farketmez_blocks = [];
    foreach ($booked_slots_raw as $slot) {
        $start_time = strtotime($date . ' ' . $slot['appointment_time']);
        
        // YENI: Hizmet süresi kontrolü
        if ($enforce_service_duration) {
            $duration = !empty($slot['total_duration']) ? $slot['total_duration'] : $slot_duration;
        } else {
            $duration = $slot_duration;
        }
        
        $end_time = $start_time + ($duration * 60);

        if (!empty($slot['_farketmez'])) {
            $farketmez_blocks[] = ['start' => $start_time, 'end' => $end_time];
        } elseif (!empty($slot['employee_id'])) {
            $booked_slots[$slot['employee_id']][] = ['start' => $start_time, 'end' => $end_time];
        }
    }

    // Çalışanları al (TENANT_ID filtreleme ile)
    if ($employee_id !== 'any') {
        $stmt_employees = $db->prepare("SELECT * FROM employees WHERE id = ? AND tenant_id = ? AND status = 'active' AND deleted_at IS NULL AND hide_from_booking = 0");
        $stmt_employees->execute([$employee_id, $tenant_id]);
        $employees = $stmt_employees->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Farketmez: seçili hizmetleri verebilen çalışanlara filtrele
        
        // HATA ÇÖZÜMÜ: Buradaki atama yukarı alındığı için yorum satırı yapıldı
        // $service_ids_param = $_GET['service_ids'] ?? $_POST['service_ids'] ?? '';
        
        $service_ids_arr = array_filter(array_map('intval', explode(',', $service_ids_param)));

        if (!empty($service_ids_arr)) {
            // Seçili TÜM hizmetleri verebilen çalışanları bul
            $placeholders = implode(',', array_fill(0, count($service_ids_arr), '?'));
            $svc_count = count($service_ids_arr);
            $params = array_merge($service_ids_arr, [$tenant_id, $svc_count]);
            $stmt_employees = $db->prepare("
                SELECT e.* FROM employees e
                INNER JOIN employee_services es ON es.employee_id = e.id
                WHERE es.service_id IN ($placeholders)
                  AND e.tenant_id = ? AND e.status = 'active' AND e.deleted_at IS NULL AND e.hide_from_booking = 0
                GROUP BY e.id
                HAVING COUNT(DISTINCT es.service_id) = ?
            ");
            $stmt_employees->execute($params);
            $employees = $stmt_employees->fetchAll(PDO::FETCH_ASSOC);
            writeLog("Farketmez + service_ids=[" . implode(',', $service_ids_arr) . "] => " . count($employees) . " compatible employees");
        } else {
            $stmt_employees = $db->prepare("SELECT * FROM employees WHERE tenant_id = ? AND status = 'active' AND deleted_at IS NULL AND hide_from_booking = 0");
            $stmt_employees->execute([$tenant_id]);
            $employees = $stmt_employees->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (empty($employees)) {
        writeLog("ERROR: No active employees found for tenant_id=$tenant_id, employee_id=$employee_id");
        writeLog("Query was: SELECT * FROM employees WHERE... tenant_id=$tenant_id, status=active");
        echo json_encode(['success' => false, 'message' => 'Bu tarih için uygun çalışan bulunamadı.']);
        exit;
    }
    
    writeLog("Found " . count($employees) . " employees for date=$date, employee_id=$employee_id, service_ids=$service_ids_param");
    
    // Dinamik saat dilimlerini oluştur
    $all_available_slots = [];

    foreach ($employees as $employee) {
        $working_days = explode(',', $employee['working_days']);
        $is_regular_working_day = in_array($day_of_week, $working_days);

        // ── SCHEDULE EXCEPTIONS: Çalışan seviyesi ─────────────────────────
        $emp_exc_skip   = false;  // day_off
        $emp_custom_windows = [];
        $emp_extra_blocks    = []; // time_block'lar

        try {
            $stmt_eex = $db->prepare("
                SELECT * FROM schedule_exceptions
                WHERE tenant_id = ? AND employee_id = ? AND exception_date = ?
            ");
            $stmt_eex->execute([$tenant_id, $employee['id'], $date]);
            $emp_excs = $stmt_eex->fetchAll(PDO::FETCH_ASSOC);

            foreach ($emp_excs as $eex) {
                if ($eex['exception_type'] === 'day_off') {
                    $emp_exc_skip = true;
                    break;
                }
                if ($eex['exception_type'] === 'custom_hours' && $eex['custom_start'] && $eex['custom_end']) {
                    $ecs = strtotime($date . ' ' . $eex['custom_start']);
                    $ece = strtotime($date . ' ' . $eex['custom_end']);
                    if ($ece <= $ecs) {
                        $ece = strtotime('+1 day', $ece);
                    }
                    $emp_custom_windows[] = ['start' => $ecs, 'end' => $ece];
                }
                if ($eex['exception_type'] === 'time_block' && $eex['block_start'] && $eex['block_end']) {
                    $bs = strtotime($date . ' ' . $eex['block_start']);
                    $be = strtotime($date . ' ' . $eex['block_end']);
                    if ($be <= $bs) {
                        $be = strtotime('+1 day', $be);
                    }
                    $emp_extra_blocks[] = ['start' => $bs, 'end' => $be];
                }
            }
        } catch (Exception $excEx) {
            // Tablo yoksa devam et
        }

        if ($emp_exc_skip) {
            writeLog("Employee {$employee['id']} has day_off on $date, skipping");
            continue;
        }

        if (!$is_regular_working_day && empty($emp_custom_windows)) {
            continue;
        }
        // ──────────────────────────────────────────────────────────────────

        // Bu çalışan için çalışılabilir pencereleri hazırla (birden fazla aralık destekli)
        $employee_windows = [];
        if (!empty($emp_custom_windows)) {
            // Çalışan özel saatleri varsa doğrudan kullan
            $employee_windows = $emp_custom_windows;
        } else {
            // GÜVENLIK: NULL/boş saatlere karşı fallback
            $emp_start = !empty($employee['working_hours_start']) 
                         ? $employee['working_hours_start'] 
                         : '09:00';
            $emp_end = !empty($employee['working_hours_end']) 
                       ? $employee['working_hours_end'] 
                       : '18:00';
            
            $emp_start_ts = strtotime($date . ' ' . $emp_start);
            $emp_end_ts   = strtotime($date . ' ' . $emp_end);
            if ($emp_end_ts <= $emp_start_ts) {
                $emp_end_ts = strtotime('+1 day', $emp_end_ts);
            }

            foreach ($business_windows as $bw) {
                $ws = max($emp_start_ts, $bw['start']);
                $we = min($emp_end_ts, $bw['end']);
                if ($we > $ws) {
                    $employee_windows[] = ['start' => $ws, 'end' => $we];
                }
            }
        }

        if (empty($employee_windows)) {
            continue;
        }
        $employee_booked = array_merge($booked_slots[$employee['id']] ?? [], $emp_extra_blocks, $biz_extra_blocks);

        // Farketmez blokları tüm çalışanlar için engel oluşturur
        $employee_booked = array_merge($employee_booked, $farketmez_blocks);

        foreach ($employee_windows as $window) {
            $work_start_time = $window['start'];
            $work_end_time = $window['end'];

            // Çalışma saatleri içinde dinamik dilimler oluştur
            $current_slot = $work_start_time;

            while ($current_slot < $work_end_time) {
                $slot_end = $current_slot + ($slot_duration * 60);

                // Slot çalışma saati içinde mi?
                if ($slot_end > $work_end_time) {
                    break;
                }

                $is_available = true;

                // YENI: Hizmet süresi kontrolü
                if ($enforce_service_duration) {
                    // Hizmet süresi kontrol edilecekse
                    $required_duration = $total_duration * 60;

                    // Seçilen hizmetler için gerekli süre kadar yer var mı kontrol et
                    $slot_test_end = $current_slot + $required_duration;

                    // Slot çalışma saati içinde kalıyor mu?
                    if ($slot_test_end > $work_end_time) {
                        $is_available = false;
                    } else {
                        // Çakışma kontrolü
                        foreach ($employee_booked as $booked) {
                            if ($booked['start'] < $slot_test_end && $booked['end'] > $current_slot) {
                                $is_available = false;
                                break;
                            }
                        }
                    }
                } else {
                    // Hizmet süresi kontrol edilmeyecekse (eski davranış)
                    foreach ($employee_booked as $booked) {
                        if ($booked['start'] < $slot_end && $booked['end'] > $current_slot) {
                            $is_available = false;
                            break;
                        }
                    }
                }

                if ($is_available) {
                    // Bugünse ve saat geçmişse gösterme
                    if ($is_today && $current_slot <= $current_time) {
                        $current_slot += ($slot_duration * 60);
                        continue;
                    }

                    $time_key = date('H:i', $current_slot);
                    $all_available_slots[$time_key] = true;
                }

                // Bir sonraki dilime geç
                $current_slot += ($slot_duration * 60);
            }
        }
    }
    
    $final_slots = array_keys($all_available_slots);
    sort($final_slots);

    if (empty($final_slots)) {
         echo json_encode(['success' => true, 'times' => [], 'message' => 'Bu tarihte uygun saat bulunamadı.']);
    } else {
         echo json_encode(['success' => true, 'times' => $final_slots]);
    }

} catch (Exception $e) {
    writeLog("ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Uygun saatler getirilirken sunucu tarafı bir hata oluştu.']);
}
?>