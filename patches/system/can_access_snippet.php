function can_access_page($page_filename) {
    try {
        // Sidebar menü tanımı
        $sidebar_menu_items = [
            'index.php' => ['icon' => 'fa-tachometer-alt', 'label' => 'Dashboard'],
            'daily_view.php' => ['icon' => 'fa-calendar-check', 'label' => 'Günlük Takip'],
            'appointments.php' => ['icon' => 'fa-calendar-alt', 'label' => 'Randevular'],
            'revenues.php' => ['icon' => 'fa-chart-line', 'label' => 'Gelir Raporu'],
            'bekleyen_odemeler.php' => ['icon' => 'fa-exclamation-circle', 'label' => 'Bekleyen Ödemeler'],
            'expenses.php' => ['icon' => 'fa-wallet', 'label' => 'Giderler'],
            'customers.php' => ['icon' => 'fa-users', 'label' => 'Müşteriler'],
            'reviews.php' => ['icon' => 'fa-star', 'label' => 'Değerlendirmeler'],
            'campaigns.php' => ['icon' => 'fa-bullhorn', 'label' => 'Kampanyalar'],
            'employees.php' => ['icon' => 'fa-user-tie', 'label' => 'Çalışanlar', 'restricted_access' => true],
            'employee_salaries.php' => ['icon' => 'fa-money-bill-wave', 'label' => 'Maaş Yönetimi', 'restricted_access' => true],
            'tasks.php' => ['icon' => 'fa-clipboard-list', 'label' => 'Görevler', 'open_access' => true],
            'services.php' => ['icon' => 'fa-cut', 'label' => 'Hizmetler'],
            'products.php' => ['icon' => 'fa-box-open', 'label' => 'Ürün & Stok'],
            'product_revenues.php' => ['icon' => 'fa-chart-bar', 'label' => 'Satış Gelirleri'],
            'gallery.php' => ['icon' => 'fa-images', 'label' => 'Galeri Yönetimi'],
            'vehicles.php' => ['icon' => 'fa-motorcycle',     'label' => 'Araç Kayıtları'],
            'service_orders.php' => ['icon' => 'fa-clipboard-list', 'label' => 'İş Emirleri'],
            'servis_kabulleri.php' => ['icon' => 'fa-clipboard-list', 'label' => 'Servis Kabulleri'],
            'billing.php' => ['icon' => 'fa-credit-card', 'label' => 'Paket & Ödemeler', 'hidden_except_owner' => true],
            'upgrade_package.php' => ['icon' => 'fa-box-open', 'label' => 'Paket & SMS Satın Al', 'hidden_except_owner' => true],
            'settings.php' => ['icon' => 'fa-cog', 'label' => 'Site Ayarları', 'restricted_access' => true],
            'trash.php' => ['icon' => 'fa-trash-alt', 'label' => 'Çöp Kutusu'],
        ];

        if (!isset($sidebar_menu_items[$page_filename])) {
            Logger::info("can_access_page: Sayfa menüde yok", ['page' => $page_filename]);
            return false;
        }

        $user_role = $_SESSION['role'] ?? 'employee';
        $allowed_pages_string = $_SESSION['allowed_pages'] ?? '';
        $allowed_pages_array = [];
        
        if ($user_role === 'employee' || $user_role === 'manager') {
            if (!empty($allowed_pages_string) && $allowed_pages_string !== 'all') {
                $allowed_pages_array = explode(',', $allowed_pages_string);
                Logger::info("can_access_page: İzin listesi parse edildi", [
                    'page' => $page_filename,
                    'raw' => $allowed_pages_string,
                    'array' => $allowed_pages_array
                ]);
            }
        }

        // 1. OWNER (PATRON) - TAM YETKİ
        if ($user_role === 'owner' || $user_role === 'admin') {
            return true;
        }

        // 2. TAMAMEN GİZLİ SAYFALAR (Sadece Billing) - OWNER HARİÇ KİMSE ERİŞEMEZ
        if (isset($sidebar_menu_items[$page_filename]['hidden_except_owner']) && $sidebar_menu_items[$page_filename]['hidden_except_owner'] === true) {
            return false;
        }

        // 2.5. HERKESE AÇIK SAYFALAR - İZİN GEREKTİRMEZ
        if (isset($sidebar_menu_items[$page_filename]['open_access']) && $sidebar_menu_items[$page_filename]['open_access'] === true) {
            return true;
        }

        // 3. KISITLI ERİŞİM SAYFALARI (Employees, Settings) - İZİN VERİLİRSE ERİŞİLEBİLİR
        if (isset($sidebar_menu_items[$page_filename]['restricted_access']) && $sidebar_menu_items[$page_filename]['restricted_access'] === true) {
            $has_access = in_array($page_filename, $allowed_pages_array);
            Logger::info("can_access_page: Kısıtlı sayfa kontrolü", [
                'page' => $page_filename,
                'has_access' => $has_access
            ]);
            return $has_access;
        }

        // 4. MANAGER - KISITLI HARİÇ HER ŞEYE ERİŞİM
        if ($user_role === 'manager') {
            return true;
        }

        // 5. EMPLOYEE - KISITLI ERİŞİM (allowed_pages listesine göre)
        if ($user_role === 'employee') {
            $equivalents = function_exists('admin_page_access_equivalents')
                ? (admin_page_access_equivalents()[$page_filename] ?? [$page_filename])
                : [$page_filename];
            $has_access = false;
            foreach ($equivalents as $candidate) {
                if (in_array($candidate, $allowed_pages_array, true)) {
                    $has_access = true;
                    break;
                }
            }
            Logger::info("can_access_page: Employee kontrolü", [
                'page' => $page_filename,
                'has_access' => $has_access,
                'allowed_array' => $allowed_pages_array,
                'equivalents' => $equivalents
            ]);
            return $has_access;
        }
        
        return false;
    } catch (Exception $e) {
        Logger::error("can_access_page EXCEPTION", [
            'page' => $page_filename,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
