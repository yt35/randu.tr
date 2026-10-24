<?php
/**
 * Gallery API - Galeri Oluştur
 * POST /api/gallery/create.php
 *
 * Parametreler:
 * - title: Başlık (zorunlu)
 * - description: Açıklama
 * - category_id: Kategori ID (zorunlu)
 * - status: active/inactive
 * - images[]: Resim dosyaları (zorunlu, en az 1)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

try {
    require_once __DIR__ . '/../../../config/config.php';

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'unauthorized', 'message' => 'Oturum gerekli']);
        exit;
    }

    if (!defined('TENANT_ID') || TENANT_ID === null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'tenant_error', 'message' => 'İşletme kimliği doğrulanamadı']);
        exit;
    }

    require_once __DIR__ . '/../../../config/UploadManager.php';
    $uploadManager = new UploadManager($db, TENANT_ID);

    function handleGalleryImageUploads($files, $uploadManager, $db, $gallery_id = null) {
        $uploaded = ['filenames' => [], 'errors' => []];
        $total_files = count($files['name']);

        for ($i = 0; $i < $total_files; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $singleFile = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => UPLOAD_ERR_OK,
                'size' => $files['size'][$i]
            ];

            try {
                $userId = $_SESSION['user_id'] ?? null;
                $uploadResult = $uploadManager->uploadFile(
                    $singleFile,
                    'gallery',
                    $gallery_id,
                    'hair_gallery',
                    $userId
                );

                if ($uploadResult['success']) {
                    $uploaded['filenames'][] = $uploadResult['url'];
                } else {
                    $uploaded['errors'][] = $files['name'][$i] . ": " . $uploadResult['message'];
                }
            } catch (Exception $e) {
                $uploaded['errors'][] = $files['name'][$i] . ": " . safeErrorMessage($e);
            }
        }
        return $uploaded;
    }

    $title = sanitize($_POST['title'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $status = sanitize($_POST['status'] ?? 'active');

    if (empty($title)) {
        echo json_encode(['success' => false, 'error' => 'validation', 'message' => 'Başlık zorunludur']);
        exit;
    }

    if ($category_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'validation', 'message' => 'Kategori seçimi zorunludur']);
        exit;
    }

    if (empty($_FILES['images']['name'][0])) {
        echo json_encode(['success' => false, 'error' => 'validation', 'message' => 'En az bir resim yüklenmelidir']);
        exit;
    }

    $stmt = $db->prepare("SELECT id FROM gallery_categories WHERE id = ? AND tenant_id = ?");
    $stmt->execute([$category_id, TENANT_ID]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'validation', 'message' => 'Geçersiz kategori']);
        exit;
    }

    $db->beginTransaction();

    $stmt = $db->prepare("INSERT INTO hair_gallery (tenant_id, title, description, category_id, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([TENANT_ID, $title, $description, $category_id, $status]);
    $gallery_id = intval($db->lastInsertId());

    $uploadedFiles = handleGalleryImageUploads($_FILES['images'], $uploadManager, $db, $gallery_id);

    if (!empty($uploadedFiles['errors'])) {
        throw new Exception(implode(", ", $uploadedFiles['errors']));
    }

    $stmt_img = $db->prepare("INSERT INTO gallery_images (tenant_id, gallery_id, filename) VALUES (?, ?, ?)");
    foreach ($uploadedFiles['filenames'] as $filename) {
        $stmt_img->execute([TENANT_ID, $gallery_id, $filename]);
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Galeri öğesi başarıyla eklendi',
        'data' => [
            'id' => $gallery_id,
            'title' => $title,
            'category_id' => $category_id,
            'image_count' => count($uploadedFiles['filenames'])
        ]
    ]);

} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[GALLERY CREATE ERROR] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (ob_get_length()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => 'Galeri oluşturulurken hata oluştu'
    ]);
}
