<?php
// Config yükle
$config_paths = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/../config/config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/config/config.php'
];
foreach ($config_paths as $path) {
    if (file_exists($path)) { require_once $path; break; }
}

// Zaten giriş yapmışsa panele yönlendir
if (isset($_SESSION['customer_id'])) {
    header("Location: /customer/index.php");
    exit;
}

$error = '';
$success = '';
$show_register = isset($_GET['register']);
$tenant_name = '';
$is_tenant_context = false;

// --- DOMAIN VE İŞLETME KONTROLÜ ---
$current_host = str_replace('www.', '', $_SERVER['HTTP_HOST']);
$main_domain = 'randu.tr';

if ($current_host === $main_domain) {
    // Ana domaindeyiz, işletme modunu kapat
    $is_tenant_context = false;
    $tenant_name = ''; // Randu.tr yazacak
} else {
    // Subdomaindeyiz, işletme adını çek
    if (defined('TENANT_ID') && TENANT_ID) {
        $is_tenant_context = true;
        $stmt = $db->prepare("SELECT firma_adi FROM tenants WHERE id = ?");
        $stmt->execute([TENANT_ID]);
        $tenant_name = $stmt->fetchColumn();
    }
}

// --- İLİŞKİLENDİRME FONKSİYONU ---
function linkCustomerToTenant($db, $customer_id) {
    global $is_tenant_context;
    
    if ($is_tenant_context && defined('TENANT_ID') && TENANT_ID) {
        $stmt = $db->prepare("SELECT id FROM customer_tenants WHERE customer_id = ? AND tenant_id = ?");
        $stmt->execute([$customer_id, TENANT_ID]);
        
        if (!$stmt->fetch()) {
            $stmtInsert = $db->prepare("INSERT INTO customer_tenants (customer_id, tenant_id) VALUES (?, ?)");
            $stmtInsert->execute([$customer_id, TENANT_ID]);
        }
    }
}

// --- TELEFON FORMATLAMA ---
function standardizePhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) == 11 && substr($phone, 0, 1) == '0') {
        $phone = substr($phone, 1);
    }
    return $phone;
}

// --- GİRİŞ İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $phone_raw = $_POST['phone'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($phone_raw) || empty($password)) {
        $error = 'Telefon ve şifre zorunludur.';
    } else {
        try {
            $phone = standardizePhone($phone_raw);
            $stmt = $db->prepare("SELECT * FROM customers WHERE phone = ? AND password IS NOT NULL");
            $stmt->execute([$phone]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($customer && password_verify($password, $customer['password'])) {
                $_SESSION['customer_id'] = $customer['id'];
                $_SESSION['customer_logged_in'] = true;
                $_SESSION['customer_name'] = $customer['name'];
                
                linkCustomerToTenant($db, $customer['id']);

                $session_token = bin2hex(random_bytes(32));
                $_SESSION['customer_session_token'] = $session_token;
                $stmt = $db->prepare("INSERT INTO customer_sessions (customer_id, session_token, ip_address, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))");
                $stmt->execute([$customer['id'], $session_token, $_SERVER['REMOTE_ADDR']]);
                
                header("Location: /customer/index.php");
                exit;
            } else {
                $error = 'Hatalı telefon numarası veya şifre.';
            }
        } catch (Exception $e) {
            $error = 'Sistem hatası oluştu.';
        }
    }
}

// --- KAYIT İŞLEMİ ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $name = htmlspecialchars(trim($_POST['name'] ?? ''));
    $email = htmlspecialchars(trim($_POST['email'] ?? ''));
    $phone_raw = $_POST['reg_phone'] ?? '';
    $password = $_POST['reg_password'] ?? '';
    
    // Validasyonlar
    if (empty($name) || empty($phone_raw) || empty($password) || empty($email)) {
        $error = 'Tüm alanları doldurun.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçerli bir e-posta adresi giriniz.';
    } else {
        try {
            $phone = standardizePhone($phone_raw);
            
            // Telefon format kontrolü (10 haneli olmalı)
            if (strlen($phone) !== 10) {
                throw new Exception('Telefon numarası geçerli formatta değil (5XX...).');
            }

            // Numara zaten var mı?
            $stmt = $db->prepare("SELECT id, password FROM customers WHERE phone = ?");
            $stmt->execute([$phone]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Varsa ama şifresi yoksa (Misafir) -> Üyeliğe Çevir
                if ($existing['password'] === null) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    // Email'i de güncelle
                    $stmt = $db->prepare("UPDATE customers SET name = ?, email = ?, password = ?, is_guest = 0 WHERE id = ?");
                    $stmt->execute([$name, $email, $hash, $existing['id']]);
                    
                    linkCustomerToTenant($db, $existing['id']);
                    
                    $success = 'Hesabınız aktifleştirildi, şimdi giriş yapabilirsiniz.';
                    $show_register = false;
                } else {
                    $error = 'Bu telefon numarası zaten sisteme kayıtlı.';
                }
            } else {
                // Yeni Kayıt (Email dahil)
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO customers (tenant_id, name, phone, email, password, is_guest, created_at) VALUES (NULL, ?, ?, ?, ?, 0, NOW())");
                $stmt->execute([$name, $phone, $email, $hash]);
                $new_id = $db->lastInsertId();
                
                linkCustomerToTenant($db, $new_id);
                
                $success = 'Kayıt başarılı! Lütfen giriş yapın.';
                $show_register = false;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8"><title>Giriş Yap - Randu.tr</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    body {
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        display: flex; align-items: center; justify-content: center; 
        min-height: 100vh; font-family: 'Segoe UI', sans-serif;
    } 
    .card {
        width: 100%; max-width: 420px; border: none; border-radius: 20px; 
        box-shadow: 0 20px 40px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .card-header-custom {
        background: #fff; padding: 2rem 2rem 1rem; text-align: center;
    }
    .brand-logo {
        font-size: 2rem; font-weight: 800; color: #6366f1; letter-spacing: -1px;
        margin-bottom: 0.5rem; display: block; text-decoration: none;
    }
    .tenant-badge {
        background: #e0e7ff; color: #4338ca; padding: 5px 15px; 
        border-radius: 50px; font-size: 0.85rem; font-weight: 600; display: inline-block;
        margin-bottom: 1rem;
    }
    .btn-primary { 
        background: #6366f1; border: none; padding: 12px; font-weight: 600; 
        border-radius: 12px; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); transition: all 0.3s;
    }
    .btn-primary:hover { background: #4f46e5; transform: translateY(-2px); }
    .btn-success { 
        background: #10b981; border: none; padding: 12px; font-weight: 600; 
        border-radius: 12px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); transition: all 0.3s;
    }
    .btn-success:hover { background: #059669; transform: translateY(-2px); }
    
    .form-control { 
        padding: 12px 15px; border-radius: 12px; border: 2px solid #f3f4f6; 
        font-size: 0.95rem; transition: all 0.3s;
    }
    .form-control:focus { 
        box-shadow: none; border-color: #6366f1; background: #fff; 
    }
    .input-group-text { 
        background: #f9fafb; border-color: #f3f4f6; border-width: 2px; 
        border-radius: 12px 0 0 12px; color: #6b7280; 
    }
    .phone-input-group .form-control { border-radius: 0 12px 12px 0; border-left: none; }
</style>
</head>
<body>
<div class="container p-3">
    <div class="card mx-auto">
        <div class="card-header-custom">
            <?php if($is_tenant_context && $tenant_name): ?>
                <a href="../index.php" class="brand-logo text-primary" style="font-size: 1.8rem;">
                    <?php echo htmlspecialchars($tenant_name); ?>
                </a>
                <p class="text-muted small mb-0">Müşteri Girişi</p>
            <?php else: ?>
                <a href="../index.php" class="brand-logo">Randu<span style="color: #1f2937;">.tr</span></a>
                <p class="text-muted small mb-0">Müşteri Paneli Girişi</p>
            <?php endif; ?>
        </div>

        <div class="card-body p-4 pt-0">
            <?php if($error): ?>
                <div class="alert alert-danger py-2 small rounded-3 border-0 bg-danger bg-opacity-10 text-danger">
                    <i class="fas fa-exclamation-circle me-1"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if($success): ?>
                <div class="alert alert-success py-2 small rounded-3 border-0 bg-success bg-opacity-10 text-success">
                    <i class="fas fa-check-circle me-1"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <?php if(!$show_register): ?>
            <!-- GİRİŞ FORMU -->
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary ms-1">Telefon Numarası</label>
                    <div class="input-group phone-input-group">
                        <span class="input-group-text"><i class="fas fa-phone"></i></span>
                        <input type="tel" name="phone" class="form-control" placeholder="5XX XXX XX XX" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-bold text-secondary ms-1">Şifre</label>
                    <div class="input-group phone-input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="******" required>
                    </div>
                </div>
                <button type="submit" name="login" class="btn btn-primary w-100 mb-3">Giriş Yap</button>
                <div class="text-center">
                    <p class="text-muted small mb-0">Hesabınız yok mu?</p>
                    <a href="?register=1" class="text-decoration-none fw-bold" style="color: #6366f1;">Hemen Kayıt Ol</a>
                </div>
            </form>
            
            <?php else: ?>
            <!-- KAYIT FORMU -->
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary ms-1">Ad Soyad</label>
                    <input type="text" name="name" class="form-control" placeholder="Adınız Soyadınız" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary ms-1">E-posta Adresi</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted border-end-0"><i class="fas fa-envelope"></i></span>
                        <input type="email" name="email" class="form-control border-start-0 ps-0" placeholder="ornek@email.com" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary ms-1">Telefon Numarası</label>
                    <div class="input-group phone-input-group">
                        <span class="input-group-text"><i class="fas fa-phone"></i></span>
                        <!-- JS Kontrolü: Sadece rakam, Max 11 hane (0 dahil) -->
                        <input type="tel" name="reg_phone" class="form-control" placeholder="5XX XXX XX XX" required 
                               maxlength="11" 
                               oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);">
                    </div>
                    <div class="form-text small text-muted ms-1">Başında 0 olmadan 10 hane veya 0 ile 11 hane.</div>
                </div>
                
                <div class="mb-4">
                    <label class="form-label small fw-bold text-secondary ms-1">Şifre Belirle</label>
                    <input type="password" name="reg_password" class="form-control" placeholder="En az 6 karakter" required minlength="6">
                </div>
                <button type="submit" name="register" class="btn btn-success w-100 mb-3">Kayıt Ol</button>
                <div class="text-center">
                    <p class="text-muted small mb-0">Zaten üye misiniz?</p>
                    <a href="login.php" class="text-decoration-none fw-bold" style="color: #6366f1;">Giriş Yap</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>