<?php
// Oturum başlat
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Admin config dosyasını dahil et (veritabanı bağlantısı için)
require_once __DIR__ . '/../../config/config.php';

// Müşteri paneli için özel ayarlar
define('CUSTOMER_PANEL_PATH', __DIR__ . '/..');
define('CUSTOMER_PANEL_URL', '/customer');

// Müşteri oturum kontrol fonksiyonu
function isCustomerLoggedIn() {
    return isset($_SESSION['customer_id']) && isset($_SESSION['customer_logged_in']) && $_SESSION['customer_logged_in'] === true;
}

// Müşteri oturum timeout kontrolü (30 dakika)
function checkCustomerSessionTimeout() {
    if (!isCustomerLoggedIn()) {
        return false;
    }
    
    $timeout = 1800; // 30 dakika
    
    if (isset($_SESSION['customer_last_activity'])) {
        $elapsed = time() - $_SESSION['customer_last_activity'];
        
        if ($elapsed > $timeout) {
            customerLogout();
            return false;
        }
    }
    
    $_SESSION['customer_last_activity'] = time();
    return true;
}

// Müşteri çıkış fonksiyonu
function customerLogout() {
    global $db;
    
    if (isset($_SESSION['customer_session_token'])) {
        try {
            $stmt = $db->prepare("DELETE FROM customer_sessions WHERE session_token = ?");
            $stmt->execute([$_SESSION['customer_session_token']]);
        } catch (Exception $e) {
            // Hata sessizce yutulur
        }
    }
    
    unset($_SESSION['customer_id']);
    unset($_SESSION['customer_logged_in']);
    unset($_SESSION['customer_name']);
    unset($_SESSION['customer_email']);
    unset($_SESSION['customer_phone']);
    unset($_SESSION['customer_last_activity']);
    unset($_SESSION['customer_session_token']);
}

// Müşteri yönlendirme fonksiyonu
function customerRedirect($page) {
    header("Location: " . CUSTOMER_PANEL_URL . "/" . $page);
    exit;
}

// Müşteri bildirim gönderme fonksiyonu
function sendCustomerNotification($customer_id, $type, $title, $message, $appointment_id = null) {
    global $db;
    
    try {
        $stmt = $db->prepare("INSERT INTO customer_notifications (customer_id, type, title, message, appointment_id) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$customer_id, $type, $title, $message, $appointment_id]);
    } catch (Exception $e) {
        return false;
    }
}

// Okunmamış bildirim sayısı
function getUnreadNotificationCount($customer_id) {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM customer_notifications WHERE customer_id = ? AND is_read = 0");
        $stmt->execute([$customer_id]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// E-posta doğrulama kodu gönder
function sendVerificationEmail($email, $code) {
    // Basit e-posta gönderimi (production'da SMTP kullanılmalı)
    $to = $email;
    $subject = "E-posta Doğrulama Kodu";
    $message = "Doğrulama kodunuz: " . $code . "\n\nBu kod 15 dakika geçerlidir.";
    $headers = "From: noreply@demirerkekuaforu.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    return mail($to, $subject, $message, $headers);
}

// Şifre sıfırlama linki gönder
function sendPasswordResetEmail($email, $token) {
    $reset_link = "http://" . $_SERVER['HTTP_HOST'] . CUSTOMER_PANEL_URL . "/reset-password.php?token=" . $token;
    
    $to = $email;
    $subject = "Şifre Sıfırlama";
    $message = "Şifrenizi sıfırlamak için aşağıdaki linke tıklayın:\n\n" . $reset_link . "\n\nBu link 1 saat geçerlidir.";
    $headers = "From: noreply@demirerkekuaforu.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    return mail($to, $subject, $message, $headers);
}

// Randevu hatırlatıcı kontrolü ve gönderimi
function checkAndSendReminders() {
    global $db;
    
    try {
        // Ayarlardan hatırlatma saatini al
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'appointment_reminder_hours'");
        $stmt->execute();
        $reminder_hours = $stmt->fetchColumn() ?: 24;
        
        // Hatırlatma gönderilmemiş, yaklaşan randevuları bul
        $reminder_time = date('Y-m-d H:i:s', strtotime("+{$reminder_hours} hours"));
        
        $stmt = $db->prepare("
            SELECT a.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone,
                   e.name as employee_name
            FROM appointments a
            JOIN customers c ON a.customer_id = c.id
            LEFT JOIN employees e ON a.employee_id = e.id
            WHERE a.status IN ('confirmed', 'pending')
            AND a.reminder_sent = 0
            AND CONCAT(a.appointment_date, ' ', a.appointment_time) <= ?
            AND CONCAT(a.appointment_date, ' ', a.appointment_time) > NOW()
        ");
        $stmt->execute([$reminder_time]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($appointments as $appointment) {
            $date_formatted = date('d.m.Y', strtotime($appointment['appointment_date']));
            $time_formatted = date('H:i', strtotime($appointment['appointment_time']));
            
            $title = "Randevu Hatırlatması";
            $message = "{$date_formatted} tarihinde saat {$time_formatted}'de randevunuz bulunmaktadır.";
            
            if ($appointment['employee_name']) {
                $message .= " Berber: {$appointment['employee_name']}";
            }
            
            // Bildirim gönder
            sendCustomerNotification(
                $appointment['customer_id'],
                'appointment_reminder',
                $title,
                $message,
                $appointment['id']
            );
            
            // Reminder sent flag'i güncelle
            $update_stmt = $db->prepare("UPDATE appointments SET reminder_sent = 1, reminder_sent_at = NOW() WHERE id = ?");
            $update_stmt->execute([$appointment['id']]);
        }
        
        return count($appointments);
    } catch (Exception $e) {
        return 0;
    }
}

// Geçmiş randevuları otomatik tamamla
function autoCompleteExpiredAppointments() {
    global $db;
    
    try {
        $stmt = $db->prepare("
            UPDATE appointments 
            SET status = 'completed' 
            WHERE status IN ('confirmed', 'pending')
            AND CONCAT(appointment_date, ' ', TIME_FORMAT(appointment_time, '%H:%i:%s')) < NOW()
        ");
        return $stmt->execute();
    } catch (Exception $e) {
        return false;
    }
}

// Tarih formatı
function formatCustomerDate($date) {
    if (empty($date)) return '-';
    
    $timestamp = strtotime($date);
    if ($timestamp === false) return $date;
    
    $today = strtotime('today');
    $tomorrow = strtotime('tomorrow');
    
    if ($timestamp >= $today && $timestamp < $tomorrow) {
        return 'Bugün, ' . date('H:i', $timestamp);
    } elseif ($timestamp >= $tomorrow && $timestamp < strtotime('+2 days')) {
        return 'Yarın, ' . date('H:i', $timestamp);
    } else {
        return date('d.m.Y H:i', $timestamp);
    }
}

// Zaman farkını hesapla (kaç gün/saat/dakika kaldı)
function getTimeUntil($date, $time) {
    $appointment_datetime = strtotime($date . ' ' . $time);
    $now = time();
    $diff = $appointment_datetime - $now;
    
    if ($diff <= 0) {
        return 'Geçmiş';
    }
    
    $days = floor($diff / (60 * 60 * 24));
    $hours = floor(($diff % (60 * 60 * 24)) / (60 * 60));
    $minutes = floor(($diff % (60 * 60)) / 60);
    
    if ($days > 0) {
        return $days . ' gün ' . $hours . ' saat';
    } elseif ($hours > 0) {
        return $hours . ' saat ' . $minutes . ' dakika';
    } else {
        return $minutes . ' dakika';
    }
}

// Her sayfa yüklendiğinde arka plan görevlerini çalıştır (basit cron alternatifi)
if (mt_rand(1, 10) === 1) { // %10 ihtimalle çalıştır
    checkAndSendReminders();
    autoCompleteExpiredAppointments();
}