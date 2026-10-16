<?php
/**
 * RANDU.TR - Dinamik XML Sitemap
 * Multi-tenant yapıya uygun site haritası üreteci
 * Her tenant için subdomain bazlı sitemap oluşturur
 */

header('Content-Type: application/xml; charset=utf-8');

// Config yükle
require_once __DIR__ . '/config/config.php';

// Domain bilgisi
$host = $_SERVER['HTTP_HOST'];
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$base_url = $protocol . $host;

// Ana domain kontrolü (localhost kaldırıldı - production için)
$main_domains = ['randu.tr', 'www.randu.tr'];
$is_main_domain = in_array($host, $main_domains);

// Tenant kontrolü
$is_tenant = defined('TENANT_ID') && TENANT_ID !== null;

// Debug: URL sayacı (production'da silinebilir)
$url_count = 0;

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo '<!-- Generated: ' . date('Y-m-d H:i:s') . ' -->' . "\n";
echo '<!-- Domain: ' . htmlspecialchars($host) . ' -->' . "\n";
echo '<!-- Is Main Domain: ' . ($is_main_domain ? 'YES' : 'NO') . ' -->' . "\n";
echo '<!-- Is Tenant: ' . ($is_tenant ? 'YES (ID: ' . TENANT_ID . ')' : 'NO') . ' -->' . "\n";

// =============================================================================
// ANA DOMAIN (randu.tr) - Landing Sayfaları
// =============================================================================
if ($is_main_domain) {
    
    // Ana sayfa
    addUrl($base_url . '/', '1.0', 'daily', 'now');
    
    // Statik sayfalar
    $static_pages = [
        '/hakkimizda' => ['priority' => '0.8', 'changefreq' => 'monthly'],
        '/iletisim' => ['priority' => '0.8', 'changefreq' => 'monthly'],
        '/sss' => ['priority' => '0.7', 'changefreq' => 'monthly'],
        '/kampanyalar' => ['priority' => '0.9', 'changefreq' => 'weekly'],
        '/isletmeler' => ['priority' => '0.9', 'changefreq' => 'daily'],
        '/kayit' => ['priority' => '0.8', 'changefreq' => 'monthly'],
    ];
    
    foreach ($static_pages as $page => $settings) {
        addUrl($base_url . $page, $settings['priority'], $settings['changefreq']);
    }
    
    // Yasal sayfalar
    $legal_pages = [
        '/legal/gizlilik-politikasi',
        '/legal/kullanim-kosullari',
        '/legal/kvkk',
        '/legal/cerez-politikasi',
    ];
    
    foreach ($legal_pages as $page) {
        addUrl($base_url . $page, '0.5', 'yearly');
    }
    
    // Dinamik işletmeler (veritabanından çek)
    try {
        echo '<!-- Fetching tenants from database... -->' . "\n";
        
        // Tenant sorgusu - 'domain' kolonu kullanılıyor (örn: 'ahmet', 'salon' vs.)
        $stmt = $db->prepare("
            SELECT 
                id,
                firma_adi,
                domain,
                slug,
                il,
                kategori,
                created_at
            FROM tenants 
            WHERE domain IS NOT NULL 
            AND domain != ''
            AND is_active = 1
            AND LOWER(firma_adi) NOT LIKE '%test%'
            AND LOWER(domain) NOT LIKE 'test%'
            ORDER BY created_at DESC
            LIMIT 1000
        ");
        $stmt->execute();
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $tenant_count = count($tenants);
        echo '<!-- Found ' . $tenant_count . ' tenants -->' . "\n";
        
        if ($tenant_count === 0) {
            echo '<!-- WARNING: No tenants found in database! Check tenants table and is_active status. -->' . "\n";
        }
        
        $cities = [];
        
        foreach ($tenants as $tenant) {
            $tenant_url = $protocol . $tenant['domain'] . '.randu.tr';
            $lastmod = $tenant['created_at'] ?? null;
            addUrl($tenant_url, '0.8', 'weekly', $lastmod);
            
            // İşletme detay sayfası (SEO-friendly slug URL)
            if (!empty($tenant['slug'])) {
                addUrl($base_url . '/' . $tenant['slug'], '0.7', 'weekly', $lastmod);
            } else {
                addUrl($base_url . '/isletme_detay.php?subdomain=' . urlencode($tenant['domain']), '0.7', 'weekly', $lastmod);
            }
            
            $kat = strtolower(str_replace(['_', ' '], '-', trim(explode(',', (string)($tenant['kategori'] ?? ''))[0])));
            $is_vehicle = in_array($kat, ['oto-yikama', 'motosiklet-servisi', 'moto-servis'], true);
            $book_path = $is_vehicle ? '/on-bakim-talebi' : '/appointment.php';

            // Tenant sayfaları (subdomain üzerinden)
            $tenant_pages_list = [
                $book_path => '1.0',
                '/services.php' => '0.9',
                '/gallery.php' => '0.8',
                '/hakkimizda.php' => '0.7',
                '/iletisim.php' => '0.7'
            ];
            
            foreach ($tenant_pages_list as $page => $priority) {
                addUrl($tenant_url . $page, $priority, 'weekly', $lastmod);
            }
            
            // Şehir toplama (il kolonu kullanılıyor)
            if (!empty($tenant['il'])) {
                $cities[$tenant['il']] = true;
            }
        }
        
        // Şehir bazlı filtreleme sayfaları
        foreach (array_keys($cities) as $city) {
            addUrl($base_url . '/isletmeler?sehir=' . urlencode($city), '0.8', 'daily');
        }
        
    } catch (Exception $e) {
        // Hata durumunda sessizce devam et
        echo '<!-- ERROR fetching tenants: ' . htmlspecialchars($e->getMessage()) . ' -->' . "\n";
        // sessiz
    }
    
    // Blog sayfaları (PHP dosya tabanlı blog verisi)
    try {
        $blog_data_file = __DIR__ . '/includes/blog-data.php';
        if (file_exists($blog_data_file)) {
            require_once $blog_data_file;
            $all_blogs = getAllBlogs();
            if (!empty($all_blogs)) {
                // Blog ana sayfa
                addUrl($base_url . '/blog', '0.8', 'weekly', 'now');
                
                // Her blog yazısı
                foreach ($all_blogs as $bp) {
                    $post_url = $base_url . '/blog-detay?yazi=' . urlencode($bp['slug']);
                    $lastmod = $bp['date'] ?? null;
                    addUrl($post_url, '0.7', 'monthly', $lastmod);
                }
                echo '<!-- Blog posts added: ' . count($all_blogs) . ' -->' . "\n";
            }
        }
    } catch (Exception $e) {
        // sessiz
    }
    
    // Not: campaigns tablosu henüz oluşturulmadı, bu yüzden dinamik kampanya URL'leri yok
    echo '<!-- Note: campaigns table not implemented yet -->' . "\n";
}

// =============================================================================
// TENANT SUBDOMAINS (ahmet.randu.tr) - Randevu Sistemi Sayfaları
// =============================================================================
if ($is_tenant) {
    
    // Ana sayfa (randevu sistemi)
    addUrl($base_url, '1.0', 'daily', 'now');
    
    // Public sayfalar
    $tenant_book = (function_exists('is_vehicle_service_category') && is_vehicle_service_category())
        ? '/on-bakim-talebi'
        : '/appointment.php';
    $tenant_pages = [
        $tenant_book => ['priority' => '1.0', 'changefreq' => 'hourly'],
        '/services.php' => ['priority' => '0.9', 'changefreq' => 'weekly'],
        '/gallery.php' => ['priority' => '0.8', 'changefreq' => 'weekly'],
        '/hakkimizda.php' => ['priority' => '0.7', 'changefreq' => 'monthly'],
        '/iletisim.php' => ['priority' => '0.7', 'changefreq' => 'monthly'],
    ];
    
    foreach ($tenant_pages as $page => $settings) {
        $exists = ($page === '/on-bakim-talebi')
            ? file_exists(__DIR__ . '/on-bakim-talebi.php')
            : file_exists(__DIR__ . $page);
        if ($exists) {
            addUrl($base_url . $page, $settings['priority'], $settings['changefreq']);
        }
    }
    
    // Opsiyonel şema parçaları — tablo/kolon yoksa sessizce atla (log spam yok)
    $sitemapTableExists = static function (PDO $db, string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $db->query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 0');
            return $cache[$table] = true;
        } catch (Throwable $e) {
            return $cache[$table] = false;
        }
    };

    // Hizmetler (dinamik) — slug kolonu olmayan şemalarla uyumlu
    try {
        $stmt = $db->prepare("
            SELECT s.id, s.name, s.category_id, s.updated_at,
                   sc.name as category_name
            FROM services s
            LEFT JOIN service_categories sc ON s.category_id = sc.id
            WHERE s.tenant_id = ?
            AND s.status = 'active'
            ORDER BY s.display_order ASC, s.name ASC
        ");
        $stmt->execute([TENANT_ID]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $service_categories = [];

        foreach ($services as $service) {
            $service_url = $base_url . '/services.php#service-' . $service['id'];
            $lastmod = $service['updated_at'] ?? null;
            addUrl($service_url, '0.8', 'weekly', $lastmod);

            if (!empty($service['category_name'])) {
                $service_categories[$service['category_name']] = true;
            }
        }

        foreach (array_keys($service_categories) as $cat_name) {
            addUrl($base_url . '/services.php?kategori=' . urlencode($cat_name), '0.7', 'weekly');
        }
    } catch (Throwable $e) {
        // sessiz — sitemap temel URL'lerle devam eder
    }

    // Çalışanlar
    try {
        $stmt = $db->prepare("
            SELECT id, name, updated_at
            FROM employees
            WHERE tenant_id = ?
            AND status = 'active'
            ORDER BY name ASC
        ");
        $stmt->execute([TENANT_ID]);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($employees as $employee) {
            $employee_url = $base_url . '/personel/' . $employee['id'];
            $lastmod = $employee['updated_at'] ?? null;
            addUrl($employee_url, '0.6', 'monthly', $lastmod);

            if (function_exists('is_vehicle_service_category') && is_vehicle_service_category()) {
                addUrl($base_url . '/on-bakim-talebi', '0.9', 'daily', $lastmod);
            } else {
                addUrl($base_url . '/appointment.php?calisan=' . $employee['id'], '0.9', 'daily', $lastmod);
            }
        }
    } catch (Throwable $e) {
        // sessiz
    }

    // Galeri (tablo varsa)
    if ($sitemapTableExists($db, 'gallery')) {
        try {
            $stmt = $db->prepare("
                SELECT id, title, category, created_at
                FROM gallery
                WHERE tenant_id = ?
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $stmt->execute([TENANT_ID]);
            $gallery_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $gallery_categories = [];

            foreach ($gallery_items as $item) {
                $gallery_url = $base_url . '/gallery.php#photo-' . $item['id'];
                $lastmod = $item['created_at'] ?? null;
                addUrl($gallery_url, '0.6', 'monthly', $lastmod);
                if (!empty($item['category'])) {
                    $gallery_categories[$item['category']] = true;
                }
            }
            foreach (array_keys($gallery_categories) as $category) {
                addUrl($base_url . '/gallery.php?kategori=' . urlencode($category), '0.7', 'weekly');
            }
        } catch (Throwable $e) {
            // sessiz
        }
    }

    // Yorumlar
    try {
        $review_count = $db->prepare("
            SELECT COUNT(*)
            FROM reviews
            WHERE tenant_id = ?
            AND status = 'approved'
            AND rating >= 4
        ");
        $review_count->execute([TENANT_ID]);
        if ((int)$review_count->fetchColumn() > 0) {
            addUrl($base_url . '/yorumlar.php', '0.7', 'weekly');
        }
    } catch (Throwable $e) {
        // sessiz
    }

    // Kampanyalar (tablo varsa)
    if ($sitemapTableExists($db, 'campaigns')) {
        try {
            $campaign_check = $db->prepare("
                SELECT COUNT(*)
                FROM campaigns
                WHERE tenant_id = ?
                AND status = 'active'
                AND end_date >= CURDATE()
            ");
            $campaign_check->execute([TENANT_ID]);
            if ((int)$campaign_check->fetchColumn() > 0) {
                addUrl($base_url . '/kampanyalar.php', '0.9', 'daily');
            }
        } catch (Throwable $e) {
            // sessiz
        }
    }

    // Blog (tablo varsa)
    if ($sitemapTableExists($db, 'blog_posts')) {
        try {
            $stmt = $db->prepare("
                SELECT id, title, slug, created_at
                FROM blog_posts
                WHERE tenant_id = ?
                AND status = 'published'
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $stmt->execute([TENANT_ID]);
            $blog_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($blog_posts) > 0) {
                addUrl($base_url . '/blog', '0.8', 'weekly');
                foreach ($blog_posts as $post) {
                    $post_url = $base_url . '/blog/' . ($post['slug'] ?? $post['id']);
                    $lastmod = $post['created_at'] ?? null;
                    addUrl($post_url, '0.7', 'monthly', $lastmod);
                }
            }
        } catch (Throwable $e) {
            // sessiz
        }
    }

    // Yasal sayfalar (tenant özelinde)
    $legal_pages = [
        '/legal/gizlilik-politikasi.php',
        '/legal/kullanim-kosullari.php',
        '/legal/kvkk.php',
    ];
    
    foreach ($legal_pages as $page) {
        if (file_exists(__DIR__ . $page)) {
            addUrl($base_url . $page, '0.4', 'yearly');
        }
    }
}

echo '<!-- Total URLs: ' . $url_count . ' -->' . "\n";
echo '</urlset>';

/**
 * URL ekle helper fonksiyonu
 * 
 * @param string $url URL adresi
 * @param string $priority Öncelik (0.0 - 1.0)
 * @param string $changefreq Güncelleme sıklığı
 * @param string|null $lastmod Son değişiklik tarihi
 */
function addUrl($url, $priority = '0.5', $changefreq = 'weekly', $lastmod = null) {
    global $url_count;
    $url_count++;
    
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($url, ENT_XML1, 'UTF-8') . "</loc>\n";
    
    // Son değişiklik tarihi
    if ($lastmod === 'now') {
        echo "    <lastmod>" . date('Y-m-d\TH:i:s+00:00') . "</lastmod>\n";
    } elseif ($lastmod && strtotime($lastmod)) {
        echo "    <lastmod>" . date('Y-m-d\TH:i:s+00:00', strtotime($lastmod)) . "</lastmod>\n";
    } else {
        echo "    <lastmod>" . date('Y-m-d\TH:i:s+00:00', filemtime(__FILE__)) . "</lastmod>\n";
    }
    
    echo "    <changefreq>" . htmlspecialchars($changefreq, ENT_XML1, 'UTF-8') . "</changefreq>\n";
    echo "    <priority>" . htmlspecialchars($priority, ENT_XML1, 'UTF-8') . "</priority>\n";
    echo "  </url>\n";
}
