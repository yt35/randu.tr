<?php
require_once 'includes/header.php';

$message = '';

// PROFIL GÜNCELLEME
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $name = htmlspecialchars(trim($_POST['name'] ?? ''));
    $email = htmlspecialchars(trim($_POST['email'] ?? ''));
    
    if (empty($name)) {
        $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>Ad Soyad alanı zorunludur.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    } else {
        try {
            $stmt = $db->prepare("UPDATE customers SET name = ?, email = ? WHERE id = ?");
            if ($stmt->execute([$name, $email, $customer_id])) {
                $_SESSION['customer_name'] = $name;
                $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-2"></i>Bilgileriniz başarıyla güncellendi.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>Hata oluştu.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        } catch (Exception $e) {
            $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>Hata: ' . $e->getMessage() . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }
}

// ŞİFRE GÜNCELLEME
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_password'])) {
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    
    if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
        $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>Tüm alanları doldurun.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    } elseif ($new_pass !== $confirm_pass) {
        $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>Yeni şifreler eşleşmiyor.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    } elseif (strlen($new_pass) < 6) {
        $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>Şifre en az 6 karakter olmalıdır.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    } else {
        $stmt = $db->prepare("SELECT password FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($current_pass, $user['password'])) {
            $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmtUpdate = $db->prepare("UPDATE customers SET password = ? WHERE id = ?");
            if ($stmtUpdate->execute([$new_hash, $customer_id])) {
                $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-2"></i>Şifre güncellendi.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            } else {
                $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>Hata oluştu.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
            }
        } else {
            $message = '<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-x-circle-fill me-2"></i>Mevcut şifre hatalı.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }
}

// Güncel bilgileri çek
$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<style>
    .profile-avatar-large {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
        font-weight: 800;
        color: white;
        box-shadow: 0 8px 24px rgba(59, 130, 246, 0.3);
    }

    .profile-header-card {
        background: white;
        border-radius: 20px;
        padding: 1.5rem;
        box-shadow: 0 4px 16px rgba(0,0,0,0.04);
        border: 1px solid var(--border-color);
        margin-bottom: 1.5rem;
    }

    .form-section {
        background: white;
        border-radius: 16px;
        padding: 1.25rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        border: 1px solid var(--border-color);
        margin-bottom: 1rem;
    }

    .form-section-title {
        font-size: 1.125rem;
        font-weight: 700;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-main);
    }

    .form-label {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
    }

    .form-control,
    .form-select {
        border: 2px solid var(--border-color);
        border-radius: 12px;
        padding: 0.875rem 1rem;
        font-size: 0.95rem;
        transition: all 0.3s;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }

    .form-control:disabled,
    .form-control[readonly] {
        background-color: #f8fafc;
        cursor: not-allowed;
    }

    .btn-save {
        width: 100%;
        padding: 0.875rem;
        font-weight: 600;
        border-radius: 12px;
    }

    @media (max-width: 767.98px) {
        .profile-avatar-large {
            width: 70px;
            height: 70px;
            font-size: 2rem;
        }

        .profile-header-card {
            padding: 1.25rem;
        }

        .form-section {
            padding: 1rem;
        }
    }
</style>

<div class="container pb-4">
    
    <?php echo $message; ?>

    <!-- Profil Başlığı -->
    <div class="profile-header-card">
        <div class="d-flex align-items-center">
            <div class="profile-avatar-large me-3">
                <?php echo mb_substr($customer['name'] ?? '', 0, 1, 'UTF-8'); ?>
            </div>
            <div class="flex-fill">
                <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($customer['name'] ?? ''); ?></h4>
                <p class="text-muted mb-0">
                    <i class="bi bi-phone"></i> <?php echo htmlspecialchars($customer['phone'] ?? ''); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Kişisel Bilgiler -->
    <div class="form-section">
        <h6 class="form-section-title">
            <i class="bi bi-person-fill text-primary"></i>
            Kişisel Bilgiler
        </h6>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Ad Soyad *</label>
                <input type="text" name="name" class="form-control" 
                       value="<?php echo htmlspecialchars($customer['name'] ?? ''); ?>" 
                       placeholder="Adınız Soyadınız" required>
            </div>
            <div class="mb-3">
                <label class="form-label">E-posta</label>
                <input type="email" name="email" class="form-control" 
                       value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>" 
                       placeholder="ornek@email.com">
            </div>
            <div class="mb-3">
                <label class="form-label">Telefon</label>
                <input type="text" class="form-control" 
                       value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>" 
                       disabled>
                <small class="text-muted">Telefon numarası değiştirilemez</small>
            </div>
            <button type="submit" name="update_profile" class="btn btn-primary-custom btn-save">
                <i class="bi bi-check-circle me-2"></i>
                Bilgileri Kaydet
            </button>
        </form>
    </div>

    <!-- Şifre Değiştirme -->
    <div class="form-section">
        <h6 class="form-section-title">
            <i class="bi bi-lock-fill text-warning"></i>
            Şifre Değiştir
        </h6>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Mevcut Şifre *</label>
                <input type="password" name="current_password" class="form-control" 
                       placeholder="Mevcut şifreniz" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Yeni Şifre *</label>
                <input type="password" name="new_password" class="form-control" 
                       placeholder="En az 6 karakter" required minlength="6">
            </div>
            <div class="mb-3">
                <label class="form-label">Yeni Şifre (Tekrar) *</label>
                <input type="password" name="confirm_password" class="form-control" 
                       placeholder="Yeni şifrenizi tekrar girin" required minlength="6">
            </div>
            <button type="submit" name="update_password" class="btn btn-outline-primary btn-save">
                <i class="bi bi-shield-check me-2"></i>
                Şifreyi Güncelle
            </button>
        </form>
    </div>

    <!-- Hesap Bilgileri -->
    <div class="form-section">
        <h6 class="form-section-title">
            <i class="bi bi-info-circle-fill text-info"></i>
            Hesap Bilgileri
        </h6>
        <div class="d-flex justify-content-between py-2 border-bottom">
            <span class="text-muted">Üyelik Tarihi</span>
            <strong><?php echo date('d.m.Y', strtotime($customer['created_at'] ?? 'now')); ?></strong>
        </div>
        <div class="d-flex justify-content-between py-2">
            <span class="text-muted">Hesap Türü</span>
            <strong class="text-success">
                <?php echo $customer['is_guest'] == 0 ? 'Aktif Üye' : 'Misafir'; ?>
            </strong>
        </div>
    </div>

    <!-- Çıkış Butonu -->
    <div class="text-center mt-4">
        <a href="logout.php" class="btn btn-outline-danger rounded-pill px-5">
            <i class="bi bi-box-arrow-right me-2"></i>
            Oturumu Kapat
        </a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php include 'includes/footer.php'; ?>