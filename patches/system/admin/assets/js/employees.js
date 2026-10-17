// ==========================================
// EMPLOYEES.JS - AJAX API Version
// ==========================================

var allEmployees = [];
var employeeModalEl = null;
var deleteModalEl = null;
var deleteEmployeeId = null;

// Log fonksiyonu
function logDebug(msg) {
    if (window.RANDU_DEBUG || (typeof localStorage !== 'undefined' && localStorage.getItem('RANDU_DEBUG') === '1')) {
        console.log('[EMPLOYEES] ' + msg);
    }
}

// ==========================================
// API Helper
// ==========================================
async function apiRequest(endpoint, options = {}) {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.content : '';

    const defaults = {
        headers: {
            'X-CSRF-Token': csrfToken,
        }
    };

    // FormData değilse Content-Type ekle
    if (options.body && !(options.body instanceof FormData)) {
        defaults.headers['Content-Type'] = 'application/json';
        if (typeof options.body === 'object') {
            options.body = JSON.stringify(options.body);
        }
    }

    const config = { ...defaults, ...options };
    // Merge headers dikkatlice yap, CSRF kaybolmasın
    if (options.headers) {
        config.headers = { ...defaults.headers, ...options.headers };
    }
    if (options.body instanceof FormData) {
        delete config.headers['Content-Type'];
    }

    logDebug('API Request: ' + endpoint);

    try {
        const response = await fetch(endpoint, config);
        const text = await response.text();
        logDebug('API Response: ' + text.substring(0, 200));

        try {
            const data = JSON.parse(text);
            return data;
        } catch (parseError) {
            console.error('JSON Parse Error:', parseError, 'Response:', text);
            return { success: false, message: 'Sunucu yanıtı geçersiz: ' + text.substring(0, 100) };
        }
    } catch (error) {
        console.error('API Error:', error);
        return { success: false, message: 'Bağlantı hatası: ' + error.message };
    }
}

// ==========================================
// Employee API Functions
// ==========================================
async function loadEmployees(params = {}) {
    const queryString = new URLSearchParams(params).toString();
    const url = 'api/employees/list.php' + (queryString ? '?' + queryString : '');
    return await apiRequest(url);
}

async function getEmployeeApi(id) {
    return await apiRequest('api/employees/get.php?id=' + id);
}

async function createEmployee(formData) {
    return await apiRequest('api/employees/create.php', {
        method: 'POST',
        body: formData
    });
}

async function updateEmployee(formData) {
    return await apiRequest('api/employees/update.php', {
        method: 'POST',
        body: formData
    });
}

async function deleteEmployeeApi(id, keepData) {
    return await apiRequest('api/employees/delete.php', {
        method: 'POST',
        body: { id: id, keep_data: keepData !== false }
    });
}

async function bulkDeleteEmployees(ids, keepData) {
    return await apiRequest('api/employees/bulk_delete.php', {
        method: 'POST',
        body: { ids: ids, keep_data: keepData !== false }
    });
}

// ==========================================
// Liste Güncelleme Fonksiyonları
// ==========================================
function updateEmployeeInList(employeeId, newData) {
    logDebug('Updating employee in list: ' + employeeId);
    logDebug('New data received: ' + JSON.stringify(newData));

    // allEmployees array'ini güncelle
    for (var i = 0; i < allEmployees.length; i++) {
        if (allEmployees[i].id == employeeId) {
            allEmployees[i] = { ...allEmployees[i], ...newData };
            logDebug('Updated employee in array: ' + JSON.stringify(allEmployees[i]));
            break;
        }
    }

    // DOM'daki kartı bul
    var card = document.querySelector('.employee-card[data-id="' + employeeId + '"]');
    if (!card) {
        var checkbox = document.querySelector('.employee-checkbox[value="' + employeeId + '"]');
        if (checkbox) {
            card = checkbox.closest('.employee-card');
        }
    }

    if (card) {
        logDebug('Card found in DOM');
        // Yeni kart oluştur
        var updatedEmployee = null;
        for (var i = 0; i < allEmployees.length; i++) {
            if (allEmployees[i].id == employeeId) {
                updatedEmployee = allEmployees[i];
                break;
            }
        }

        if (updatedEmployee) {
            logDebug('Creating new card with employee data');
            logDebug('Photo: ' + updatedEmployee.photo);
            logDebug('Photo URL: ' + updatedEmployee.photo_url);

            var newCard = createEmployeeCard(updatedEmployee);

            // Eski kartı yenisiyle değiştir
            card.parentNode.replaceChild(newCard, card);

            // Fade-in animasyonu
            newCard.style.opacity = '0';
            setTimeout(function () {
                newCard.style.transition = 'opacity 0.3s ease';
                newCard.style.opacity = '1';
            }, 10);

            logDebug('Card replaced in DOM with fresh data');
        }
    } else {
        logDebug('Card not found, reloading page');
        setTimeout(() => window.location.reload(), 500);
    }
}

function removeEmployeeFromList(employeeId) {
    logDebug('Removing employee from list: ' + employeeId);

    // allEmployees array'inden kaldır
    allEmployees = allEmployees.filter(e => e.id != employeeId);

    // DOM'dan kaldır
    var card = document.querySelector('.employee-card[data-id="' + employeeId + '"]');
    if (!card) {
        var checkbox = document.querySelector('.employee-checkbox[value="' + employeeId + '"]');
        if (checkbox) {
            card = checkbox.closest('.employee-card');
        }
    }

    if (card) {
        card.style.transition = 'all 0.3s ease';
        card.style.opacity = '0';
        card.style.transform = 'translateX(20px)';
        setTimeout(() => card.remove(), 300);

        // Toplam sayıyı güncelle
        var countEl = document.querySelector('.employee-count, .total-count');
        if (countEl) {
            var currentCount = parseInt(countEl.textContent) || 0;
            countEl.textContent = Math.max(0, currentCount - 1);
        }
    }
}

function addEmployeeToList(employee) {
    logDebug('Adding employee to list: ' + employee.name);

    // allEmployees array'ine ekle
    allEmployees.unshift(employee);

    // DOM'a ekle
    var container = document.querySelector('.employees-grid');
    if (!container) {
        logDebug('Container not found, reloading page');
        setTimeout(() => window.location.reload(), 500);
        return;
    }

    var card = createEmployeeCard(employee);

    // İlk kartın önüne ekle
    var firstCard = container.querySelector('.employee-card');
    if (firstCard) {
        container.insertBefore(card, firstCard);
    } else {
        container.appendChild(card);
    }

    // Animasyon
    setTimeout(function () {
        card.style.transition = 'all 0.3s ease';
        card.style.opacity = '1';
        card.style.transform = 'translateY(0)';
    }, 50);

    // Toplam sayıyı güncelle
    var countEl = document.querySelector('.employee-count, .total-count');
    if (countEl) {
        var currentCount = parseInt(countEl.textContent) || 0;
        countEl.textContent = currentCount + 1;
    }

    logDebug('Employee card added to DOM');
}

function createEmployeeCard(employee) {
    var isDark = document.body.classList.contains('dark-mode');

    var card = document.createElement('div');
    card.className = 'employee-card';
    card.setAttribute('data-id', employee.id);
    card.style.opacity = '0';
    card.style.transform = 'translateY(-20px)';

    // Fotoğraf var mı kontrol et
    var hasPhoto = employee.photo_url || (employee.photo && employee.photo.trim() !== '');
    var photoSrc = hasPhoto
        ? (employee.photo_url || (employee.photo.indexOf('http') === 0 ? employee.photo : '../assets/uploads/employees/' + employee.photo))
        : null;

    // Baş harf avatar için renk paleti
    var avatarColors = [
        'linear-gradient(135deg, #1e3a5f 0%, #2c5282 100%)',
        'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
        'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)',
        'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)',
        'linear-gradient(135deg, #fa709a 0%, #fee140 100%)',
        'linear-gradient(135deg, #a8edea 0%, #fed6e3 100%)',
        'linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%)',
        'linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%)'
    ];
    var colorIndex = employee.id % avatarColors.length;
    var avatarBg = avatarColors[colorIndex];

    // İsmin baş harflerini al
    var initials = getInitials(employee.name);

    var statusClass = employee.status === 'active' ? 'status-active' : 'status-inactive';
    var statusText = employee.status === 'active' ? 'Aktif' : 'Pasif';
    var roleText = employee.role === 'manager' ? 'Yönetici' : 'Çalışan';
    var roleIcon = employee.role === 'manager' ? 'user-shield' : 'user';

    // Fotoğraf veya baş harf avatar - sabit boyut 60x60
    var photoHtml = hasPhoto
        ? '<img src="' + photoSrc + '" alt="' + escapeHtml(employee.name) + '" style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; margin-right: 1rem; border: 3px solid var(--primary-color);" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';">' +
        '<div class="avatar-initials" style="display:none; width: 60px; height: 60px; border-radius: 50%; background: ' + avatarBg + '; align-items: center; justify-content: center; margin-right: 1rem; color: white; font-size: 1.25rem; font-weight: 700; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">' + initials + '</div>'
        : '<div class="avatar-initials" style="width: 60px; height: 60px; border-radius: 50%; background: ' + avatarBg + '; display: flex; align-items: center; justify-content: center; margin-right: 1rem; color: white; font-size: 1.25rem; font-weight: 700; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">' + initials + '</div>';

    // Çalışma günleri
    var workingDays = employee.working_days || '1,2,3,4,5';
    var dayNames = { 1: 'Pzt', 2: 'Sal', 3: 'Çar', 4: 'Per', 5: 'Cum', 6: 'Cmt', 7: 'Paz' };
    var dayList = workingDays.split(',').map(function (d) { return dayNames[d.trim()] || ''; }).filter(function (d) { return d; }).join(', ');
    var startTime = employee.working_hours_start ? employee.working_hours_start.substring(0, 5) : '09:00';
    var endTime = employee.working_hours_end ? employee.working_hours_end.substring(0, 5) : '18:00';

    card.innerHTML = '<div class="card-content">' +
        '<div class="employee-details">' +
        '<input type="checkbox" class="employee-checkbox" value="' + employee.id + '" onchange="updateBulkActions(this)">' +
        photoHtml +
        '<div>' +
        '<div class="name">' + escapeHtml(employee.name) + '</div>' +
        (employee.expertise ? '<div class="contact" style="display: flex; align-items: center; gap: 0.5rem; color: var(--primary-color, #1e3a5f); font-weight: 500;"><i class="fas fa-id-badge"></i> <span>' + escapeHtml(employee.expertise) + '</span></div>' : '') +
        '<div class="contact" style="display: flex; align-items: center; gap: 0.5rem;"><i class="fas fa-phone"></i> <span>' + formatPhoneDisplay(employee.phone) + '</span></div>' +
        '<div class="contact" style="display: flex; align-items: center; gap: 0.5rem;"><i class="fas fa-envelope"></i> <span>' + escapeHtml(employee.email || 'N/A') + '</span></div>' +
        '</div>' +
        '</div>' +
        '<div class="card-working-hours d-none d-lg-block">' +
        startTime + ' - ' + endTime + '<br><small>' + dayList + '</small>' +
        '</div>' +
        '<div class="card-stats">' +
        '<div class="stat-item">' +
        '<div class="label">Komisyon</div>' +
        '<div class="value commission">%' + (employee.commission_rate || '50.00').toString().replace('.', ',') + '</div>' +
        '</div>' +
        '<div class="stat-item">' +
        '<div class="label">Randevu Sayısı</div>' +
        '<div class="value appointments">' + (employee.appointment_count || 0) + '</div>' +
        '</div>' +
        '<div class="stat-item">' +
        '<div class="label">Toplam Gelir</div>' +
        '<div class="value revenue">' + formatCurrency(employee.total_revenue || 0) + '</div>' +
        '</div>' +
        '</div>' +
        '<div class="card-actions">' +
        '<span class="status-badge ' + statusClass + '">' + statusText + '</span>' +
        '<span class="role-badge ' + employee.role + '"><i class="fas fa-' + roleIcon + '"></i> ' + roleText + '</span>' +
        (employee.hide_from_booking == 1 ? '<span style="display:inline-flex; align-items:center; gap:4px; padding:0.25rem 0.5rem; border-radius:6px; font-size:0.7rem; font-weight:600; background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.3);"><i class="fas fa-eye-slash"></i> Gizli</span>' : '') +
        '<button class="btn btn-sm btn-warning" onclick="editEmployee(' + employee.id + ')"><i class="fas fa-edit"></i></button>' +
        '<button class="btn btn-sm btn-danger" onclick="deleteEmployee(' + employee.id + ', \'' + escapeHtml(employee.name).replace(/'/g, "\\'") + '\', ' + (employee.future_appointment_count || 0) + ')"><i class="fas fa-trash"></i></button>' +
        '</div>' +
        '</div>';

    return card;
}

// Çalışan listesini yeniden render et
function renderEmployeeList(employees) {
    logDebug('Rendering employee list: ' + employees.length + ' employees');

    var container = document.querySelector('.employees-grid');
    if (!container) {
        logDebug('Container not found');
        return;
    }

    // Header'ları koru
    var bulkSelectHeader = container.querySelector('.bulk-select-header');
    var listHeader = container.querySelector('.employee-list-header');

    // Sadece employee-card'ları kaldır
    var cards = container.querySelectorAll('.employee-card');
    cards.forEach(function (card) {
        card.remove();
    });

    // Empty state varsa onu da kaldır
    var emptyState = container.querySelector('[style*="grid-column"]');
    if (emptyState) emptyState.remove();

    if (employees.length === 0) {
        var emptyDiv = document.createElement('div');
        emptyDiv.style.cssText = 'text-align: center; padding: 3rem; grid-column: 1 / -1;';
        emptyDiv.innerHTML = '<i class="fas fa-search" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 1rem; display: block;"></i>' +
            '<h3>Çalışan Bulunamadı</h3>' +
            '<p style="color: var(--dark-gray);">Arama kriterlerinize uygun çalışan bulunamadı.</p>';
        container.appendChild(emptyDiv);

        // Stat kartlarını güncelle
        updateStatCards(0, 0, 0, null);
        return;
    }

    employees.forEach(function (employee) {
        var card = createEmployeeCard(employee);
        card.style.opacity = '1';
        card.style.transform = 'none';
        container.appendChild(card);
    });

    // Toplam sayıyı güncelle
    var countEl = document.querySelector('.employee-count, .total-count');
    if (countEl) {
        countEl.textContent = employees.length;
    }

    // Stat kartlarını güncelle
    var totalCount = employees.length;
    var activeCount = employees.filter(function (e) { return e.status === 'active'; }).length;
    var passiveCount = employees.filter(function (e) { return e.status === 'inactive'; }).length;

    // En çok kazanan çalışanı bul
    var topEmployee = null;
    var maxRevenue = 0;
    employees.forEach(function (e) {
        var revenue = parseFloat(e.total_revenue) || 0;
        if (revenue > maxRevenue) {
            maxRevenue = revenue;
            topEmployee = e;
        }
    });

    updateStatCards(totalCount, activeCount, passiveCount, topEmployee);

    logDebug('Employee list rendered');
}

// Stat kartlarını güncelle
function updateStatCards(total, active, passive, topEmployee) {
    // Stat kartlarındaki sayıları bul ve güncelle
    var statCards = document.querySelectorAll('.stats-container .card, .stat-card');

    statCards.forEach(function (card) {
        var titleText = card.querySelector('.title-text, .stat-title');
        var dataEl = card.querySelector('.data p, .stat-value');

        if (titleText && dataEl) {
            var title = titleText.textContent.toLowerCase();

            if (title.includes('toplam')) {
                dataEl.textContent = total;
            } else if (title.includes('aktif')) {
                dataEl.textContent = active;
            } else if (title.includes('pasif')) {
                dataEl.textContent = passive;
            }
        }

        // Ayın lideri kartı
        if (card.querySelector('.employee-of-month-name') || card.textContent.toLowerCase().includes('ayın lideri')) {
            var nameEl = card.querySelector('.employee-of-month-name, .data p');
            if (nameEl && topEmployee) {
                nameEl.textContent = topEmployee.name;
            } else if (nameEl && !topEmployee) {
                nameEl.textContent = '-';
            }
        }
    });
}

// ==========================================
// Sayfa Yükleme
// ==========================================
document.addEventListener('DOMContentLoaded', function () {
    logDebug('DOMContentLoaded fired');

    allEmployees = window.employeeData ? window.employeeData.employees : [];
    logDebug('Employees loaded: ' + allEmployees.length);

    employeeModalEl = document.getElementById('employeeModal');
    deleteModalEl = document.getElementById('deleteModal');

    // Filtre form AJAX handler
    var filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            var searchInput = document.getElementById('search_input');
            var sortSelect = document.getElementById('sort_select');
            var statusSelect = document.getElementById('status_select');

            var search = searchInput ? searchInput.value.trim() : '';
            var sort = sortSelect ? sortSelect.value : 'total_revenue_DESC';
            var status = statusSelect ? statusSelect.value : '';

            // Sıralama değerini parçala
            var lastUnderscoreIndex = sort.lastIndexOf('_');
            var sortField = lastUnderscoreIndex > 0 ? sort.substring(0, lastUnderscoreIndex) : 'total_revenue';
            var sortOrder = lastUnderscoreIndex > 0 ? sort.substring(lastUnderscoreIndex + 1) : 'DESC';

            logDebug('Filtering: search=' + search + ', sort=' + sortField + ', order=' + sortOrder);

            // Loading göster
            var applyBtn = filterForm.querySelector('button[type="submit"]');
            var originalBtnText = applyBtn ? applyBtn.innerHTML : '';
            if (applyBtn) {
                applyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Yükleniyor...';
                applyBtn.disabled = true;
            }

            try {
                var params = {
                    search: search,
                    sort: sortField,
                    order: sortOrder
                };
                if (status) params.status = status;

                var result = await loadEmployees(params);

                if (result.success) {
                    allEmployees = result.employees || [];
                    renderEmployeeList(allEmployees);
                    closeFilterSidebar();

                    var newUrl = 'employees.php?search=' + encodeURIComponent(search) + '&sort=' + encodeURIComponent(sort);
                    if (status) newUrl += '&status=' + encodeURIComponent(status);
                    window.history.pushState({}, '', newUrl);

                    showNotification('Filtre uygulandı: ' + allEmployees.length + ' çalışan bulundu', 'success');
                } else {
                    showNotification(result.message || 'Filtreleme başarısız', 'error');
                }
            } catch (error) {
                showNotification('Filtreleme hatası: ' + error.message, 'error');
            } finally {
                if (applyBtn) {
                    applyBtn.innerHTML = originalBtnText;
                    applyBtn.disabled = false;
                }
            }
        });

        // Temizle butonu
        var resetBtn = filterForm.querySelector('.btn-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', async function (e) {
                e.preventDefault();

                var searchInput = document.getElementById('search_input');
                var sortSelect = document.getElementById('sort_select');
                var statusSelect = document.getElementById('status_select');
                if (searchInput) searchInput.value = '';
                if (sortSelect) sortSelect.value = 'total_revenue_DESC';
                if (statusSelect) statusSelect.value = '';

                try {
                    var result = await loadEmployees({});
                    if (result.success) {
                        allEmployees = result.employees || [];
                        renderEmployeeList(allEmployees);
                        closeFilterSidebar();
                        window.history.pushState({}, '', 'employees.php');
                        showNotification('Filtre temizlendi', 'success');
                    }
                } catch (error) {
                    showNotification('Hata: ' + error.message, 'error');
                }
            });
        }
    }
});

// ==========================================
// Format Fonksiyonları
// ==========================================
function formatPhoneDisplay(phone) {
    if (!phone) return '-';
    phone = phone.replace(/\D/g, '');
    if (phone.length === 11 && phone[0] === '0') {
        phone = phone.substring(1);
    }
    if (phone.length === 10) {
        return phone.substr(0, 3) + ' ' + phone.substr(3, 3) + ' ' + phone.substr(6, 2) + ' ' + phone.substr(8, 2);
    }
    return phone;
}

// İsimden baş harfleri al
function getInitials(name) {
    if (!name) return '?';
    var parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
        return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    }
    return parts[0].charAt(0).toUpperCase();
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' }).format(amount || 0);
}

function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ==========================================
// FILTER SIDEBAR
// ==========================================
function openFilterSidebar() {
    logDebug('openFilterSidebar called');
    var sidebar = document.getElementById('filterSidebar');
    var overlay = document.getElementById('filterOverlay');
    if (sidebar) {
        sidebar.classList.add('active');
    }
    if (overlay) {
        overlay.classList.add('active');
    }
    document.body.style.overflow = 'hidden';
}

function closeFilterSidebar() {
    logDebug('closeFilterSidebar called');
    var sidebar = document.getElementById('filterSidebar');
    var overlay = document.getElementById('filterOverlay');
    if (sidebar) {
        sidebar.classList.remove('active');
    }
    if (overlay) {
        overlay.classList.remove('active');
    }
    document.body.style.overflow = '';
}

function resetFilters() {
    logDebug('resetFilters called');
    var searchInput = document.getElementById('search_input');
    var sortSelect = document.getElementById('sort_select');
    var statusSelect = document.getElementById('status_select');
    if (searchInput) searchInput.value = '';
    if (sortSelect) sortSelect.value = 'name_asc';
    if (statusSelect) statusSelect.value = '';
    applyFilters();
    closeFilterSidebar();
}

// ==========================================
// BULK SELECT FUNCTIONS
// ==========================================
function toggleSelectAll() {
    var isChecked = document.getElementById('selectAll').checked;
    var boxes = document.querySelectorAll('.employee-checkbox:not(#selectAll)');
    for (var i = 0; i < boxes.length; i++) {
        boxes[i].checked = isChecked;
        var card = boxes[i].closest('.employee-card');
        if (card) {
            if (isChecked) {
                card.classList.add('row-selected');
            } else {
                card.classList.remove('row-selected');
            }
        }
    }
    toggleHeaderButtons(isChecked);
}

function toggleHeaderButtons(hasSelection) {
    var filterBtn = document.querySelector('.bulk-select-header .btn-secondary');
    var addBtn = document.querySelector('.bulk-select-header .btn-success');
    var deleteBtn = document.getElementById('bulkDeleteBtn');

    if (hasSelection) {
        if (filterBtn) filterBtn.style.display = 'none';
        if (addBtn) addBtn.style.display = 'none';
        if (deleteBtn) deleteBtn.style.display = 'inline-flex';
    } else {
        if (filterBtn) filterBtn.style.display = 'inline-flex';
        if (addBtn) addBtn.style.display = 'inline-flex';
        if (deleteBtn) deleteBtn.style.display = 'none';
    }
}

function updateBulkActions(checkbox) {
    var card = checkbox.closest('.employee-card');
    if (card) {
        if (checkbox.checked) {
            card.classList.add('row-selected');
        } else {
            card.classList.remove('row-selected');
        }
    }

    var count = document.querySelectorAll('.employee-checkbox:checked:not(#selectAll)').length;
    var selectAllCheckbox = document.getElementById('selectAll');

    if (count > 0) {
        toggleHeaderButtons(true);
    } else {
        if (selectAllCheckbox) selectAllCheckbox.checked = false;
        toggleHeaderButtons(false);
    }
}

function executeBulkAction() {
    var boxes = document.querySelectorAll('.employee-checkbox:checked:not(#selectAll)');
    if (boxes.length === 0) {
        showNotification('Lütfen en az bir çalışan seçin', 'error');
        return;
    }
    var ids = [];
    for (var i = 0; i < boxes.length; i++) {
        ids.push(parseInt(boxes[i].value));
    }

    showEmployeeDataChoiceModal(
        '<strong>' + ids.length + '</strong> çalışan çöp kutusuna taşınacak.',
        false,
        async function (keepData) {
            try {
                var result = await bulkDeleteEmployees(ids, keepData);
                if (result.success) {
                    showNotification(result.message || ids.length + ' çalışan silindi', 'success');
                    if (result.deleted_ids && result.deleted_ids.length > 0) {
                        result.deleted_ids.forEach(function (id) {
                            removeEmployeeFromList(id);
                        });
                    }
                    document.getElementById('selectAll').checked = false;
                    toggleHeaderButtons(false);
                } else {
                    showNotification(result.message || 'Toplu silme başarısız', 'error');
                }
            } catch (error) {
                showNotification('Bir hata oluştu: ' + error.message, 'error');
            }
        }
    );
}

// ==========================================
// MODAL FUNCTIONS
// ==========================================
function showAddModal() {
    createEmployeeModal(null);
}

// SMS doğrulamalı transfer modal
function showTransferVerification(apiResult, originalFormData, form, isEditMode) {
    var isDark = document.body.classList.contains('dark-mode');
    var bgColor = isDark ? '#1e293b' : '#ffffff';
    var textColor = isDark ? '#f1f5f9' : '#1f2937';
    var mutedColor = isDark ? '#94a3b8' : '#6b7280';
    var inputBg = isDark ? '#334155' : '#ffffff';
    var inputBorder = isDark ? '#475569' : '#e5e7eb';
    var borderColor = isDark ? '#475569' : '#323232';
    var warningBg = isDark ? 'rgba(245,158,11,0.15)' : '#fef3c7';
    var warningText = isDark ? '#fcd34d' : '#92400e';

    // Mevcut modal'ı kapat
    closeDynamicModal();

    var overlay = document.createElement('div');
    overlay.id = 'dynamicModal';
    overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:999999; display:flex; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(8px);';

    overlay.innerHTML = `
        <div style="background:${bgColor}; border-radius:16px; border:3px solid ${borderColor}; box-shadow:8px 8px 0 ${borderColor}; max-width:440px; width:100%; overflow:hidden;">
            <div style="background:linear-gradient(135deg,#f59e0b,#d97706); padding:1.25rem 1.5rem; display:flex; align-items:center; gap:0.75rem;">
                <i class="fas fa-exchange-alt" style="font-size:1.5rem; color:white;"></i>
                <h3 style="margin:0; color:white; font-size:1.1rem;">Çalışan Transferi</h3>
            </div>
            <div style="padding:1.5rem;">
                <div style="background:${warningBg}; border-radius:10px; padding:1rem; margin-bottom:1.25rem;">
                    <p style="margin:0; font-size:0.9rem; color:${warningText}; line-height:1.5;">
                        <i class="fas fa-exclamation-triangle" style="margin-right:0.25rem;"></i>
                        <strong>${apiResult.old_employee_name}</strong> adlı çalışan 
                        "<strong>${apiResult.old_business}</strong>" işletmesinde kayıtlı. 
                        Transfer için çalışanın telefonuna (${apiResult.masked_phone}) doğrulama kodu gönderildi.
                    </p>
                </div>
                <div style="margin-bottom:1rem;">
                    <label style="display:block; margin-bottom:0.5rem; font-weight:600; color:${textColor}; font-size:0.9rem;">
                        <i class="fas fa-sms" style="margin-right:0.25rem; color:#f59e0b;"></i> Doğrulama Kodu
                    </label>
                    <input type="text" id="transferVerifyCode" maxlength="6" placeholder="6 haneli kodu girin" 
                        style="width:100%; padding:0.75rem 1rem; border:2px solid ${inputBorder}; border-radius:10px; 
                        font-size:1.25rem; text-align:center; letter-spacing:0.5rem; font-weight:700; 
                        background:${inputBg}; color:${textColor}; box-sizing:border-box;"
                        oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    <p style="margin:0.5rem 0 0; font-size:0.8rem; color:${mutedColor};">
                        <i class="fas fa-clock"></i> Kod 5 dakika geçerlidir
                    </p>
                </div>
                <div style="display:flex; gap:0.75rem;">
                    <button type="button" onclick="closeDynamicModal()" 
                        style="flex:1; padding:0.75rem; border:2px solid ${borderColor}; border-radius:10px; 
                        background:transparent; color:${textColor}; font-weight:600; cursor:pointer; font-size:0.9rem;">
                        İptal
                    </button>
                    <button type="button" id="transferConfirmBtn"
                        style="flex:2; padding:0.75rem; border:none; border-radius:10px; 
                        background:linear-gradient(135deg,#f59e0b,#d97706); color:white; font-weight:600; 
                        cursor:pointer; font-size:0.9rem; display:flex; align-items:center; justify-content:center; gap:0.5rem;">
                        <i class="fas fa-check-circle"></i> Doğrula ve Transfer Et
                    </button>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';

    // Focus
    setTimeout(function() {
        var codeInput = document.getElementById('transferVerifyCode');
        if (codeInput) codeInput.focus();
    }, 100);

    // Enter ile gönder
    document.getElementById('transferVerifyCode').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('transferConfirmBtn').click();
        }
    });

    // Doğrula butonu
    document.getElementById('transferConfirmBtn').addEventListener('click', async function() {
        var code = document.getElementById('transferVerifyCode').value.trim();
        if (code.length !== 6) {
            showNotification('Lütfen 6 haneli doğrulama kodunu girin', 'error');
            return;
        }

        var btn = this;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Doğrulanıyor...';
        btn.disabled = true;

        // Yeni FormData oluştur (orijinalin kopyası + transfer bilgileri)
        var newFormData = new FormData(form);
        newFormData.append('transfer_action', 'verify');
        newFormData.append('transfer_code', code);

        try {
            var result = isEditMode ? await updateEmployee(newFormData) : await createEmployee(newFormData);
            logDebug('Transfer Verify Result: ' + JSON.stringify(result));

            if (result.success) {
                showNotification(result.message || 'Çalışan başarıyla transfer edildi', 'success');
                closeDynamicModal();
                setTimeout(function() { window.location.reload(); }, 300);
            } else {
                showNotification(result.message || 'Doğrulama başarısız', 'error');
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Doğrula ve Transfer Et';
                btn.disabled = false;
                document.getElementById('transferVerifyCode').value = '';
                document.getElementById('transferVerifyCode').focus();
            }
        } catch (error) {
            showNotification('Bir hata oluştu: ' + error.message, 'error');
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Doğrula ve Transfer Et';
            btn.disabled = false;
        }
    });

    // Overlay tıklama ile kapat
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeDynamicModal();
    });
}

function closeDynamicModal() {
    var modal = document.getElementById('dynamicModal');
    if (modal) modal.remove();
    document.body.style.overflow = '';
}

function createEmployeeModal(employee) {
    var oldModal = document.getElementById('dynamicModal');
    if (oldModal) oldModal.remove();

    var isEdit = employee !== null;
    var isDark = document.body.classList.contains('dark-mode');

    var modal = document.createElement('div');
    modal.id = 'dynamicModal';
    modal.className = 'dynamic-modal-overlay';
    modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:999999; display:flex; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(8px);';

    var bgColor = isDark ? '#1e293b' : '#ffffff';
    var textColor = isDark ? '#f1f5f9' : '#1f2937';
    var borderColor = isDark ? '#475569' : '#323232';
    var inputBg = isDark ? '#334155' : '#ffffff';
    var inputBorder = isDark ? '#475569' : '#e5e7eb';
    var labelColor = isDark ? '#94a3b8' : '#374151';
    var headerGradient = isDark ? 'linear-gradient(135deg, #0f2847 0%, #0a1e3a 100%)' : 'linear-gradient(135deg, #1e3a5f 0%, #0f2847 100%)';

    var modalContent = document.createElement('div');
    modalContent.className = 'dynamic-modal-content';
    modalContent.style.cssText = 'background:' + bgColor + '; padding:0; border-radius:20px; width:100%; max-width:700px; box-shadow:0 20px 60px rgba(0,0,0,0.3), 5px 5px 0 ' + borderColor + '; border:3px solid ' + borderColor + '; position:relative; max-height:90vh; overflow:hidden; display:flex; flex-direction:column;';

    // Header
    var header = '<div class="modal-header-gradient" style="padding:1.5rem; background:' + headerGradient + '; display:flex; justify-content:space-between; align-items:center; position:relative;">' +
        '<div style="display:flex; align-items:center; gap:0.75rem; position:relative; z-index:1;">' +
        '<div style="width:42px; height:42px; background:rgba(255,255,255,0.2); border-radius:10px; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(10px);">' +
        '<i class="fas fa-' + (isEdit ? 'user-edit' : 'user-plus') + ' icon-bounce" style="font-size:1.25rem; color:white;"></i></div>' +
        '<h3 style="margin:0; color:white; font-size:1.3rem; font-weight:700; text-shadow:0 2px 4px rgba(0,0,0,0.2);">' + (isEdit ? 'Çalışan Düzenle' : 'Yeni Çalışan') + '</h3>' +
        '</div>' +
        '<button type="button" onclick="closeDynamicModal()" class="modal-button-hover" style="background:rgba(255,255,255,0.2); border:none; font-size:1.25rem; cursor:pointer; color:white; padding:0.5rem; border-radius:8px; width:36px; height:36px; display:flex; align-items:center; justify-content:center; position:relative; z-index:1; backdrop-filter:blur(10px); transition:all 0.2s;">&times;</button>' +
        '</div>';

    // Days
    var days = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];
    var defaultDays = isEdit && employee.working_days ? employee.working_days.split(',') : ['1', '2', '3', '4', '5'];
    var dayMap = { '1': 'Pzt', '2': 'Sal', '3': 'Çar', '4': 'Per', '5': 'Cum', '6': 'Cmt', '7': 'Paz' };
    var activeDayNames = defaultDays.map(function (num) { return dayMap[num.trim()]; }).filter(function (d) { return d; });

    var daysCheckboxes = '';
    days.forEach(function (day) {
        var checked = activeDayNames.indexOf(day) !== -1 ? 'checked' : '';
        daysCheckboxes += '<label style="display:inline-flex; align-items:center; gap:0.5rem; padding:0.75rem; border:2px solid ' + inputBorder + '; border-radius:12px; cursor:pointer; transition:all 0.2s; background:' + inputBg + ';">' +
            '<input type="checkbox" name="days[]" value="' + day + '" ' + checked + ' style="margin:0;">' +
            '<span style="color:' + textColor + ';">' + day + '</span></label>';
    });

    // Existing photo for edit mode
    var existingPhotoHTML = '';
    var previewDisplay = 'none';
    var placeholderDisplay = 'block';

    if (isEdit && employee.photo) {
        var photoUrl = employee.photo.indexOf('http') === 0 ? employee.photo : '../assets/uploads/employees/' + employee.photo;
        existingPhotoHTML = '<img src="' + photoUrl + '" alt="Preview" style="max-width: 100px; max-height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid ' + (isDark ? '#60a5fa' : '#1e3a5f') + ';">';
        previewDisplay = 'block';
        placeholderDisplay = 'none';
    }

    // Form body
    var formBody = '<div style="padding:2rem 1.5rem; overflow-y:auto; flex:1;">' +
        '<form id="dynamicEmployeeForm" enctype="multipart/form-data">' +
        (isEdit ? '<input type="hidden" name="id" value="' + employee.id + '">' : '') +

        // Photo - New Upload Style
        '<div style="margin-bottom:1.5rem;">' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #1e3a5f 0%, #2c5282 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-image" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Profil Fotoğrafı</span></label>' +
        '<div id="employeePhotoUploadArea" style="border: 2px dashed ' + inputBorder + '; border-radius: 12px; padding: 1.5rem; text-align: center; cursor: pointer; transition: all 0.3s; background: ' + inputBg + ';" onclick="openEmployeePhotoUpload()">' +
        '<input type="file" id="employeePhotoInput" name="photo" accept="image/jpeg,image/jpg,image/png,image/gif" style="display: none;">' +
        '<div id="employeePhotoPreview" style="display: ' + previewDisplay + '; margin-bottom: 0.75rem;">' + existingPhotoHTML + '</div>' +
        '<div id="employeePhotoPlaceholder" style="display: ' + placeholderDisplay + ';">' +
        '<i class="fas fa-cloud-upload-alt" style="font-size: 2.5rem; color: ' + (isDark ? '#60a5fa' : '#1e3a5f') + '; margin-bottom: 0.5rem; display: block;"></i>' +
        '<p style="margin: 0; color: ' + labelColor + '; font-size: 0.9rem;">Fotoğraf yüklemek için tıklayın</p>' +
        '<small style="color: ' + (isDark ? '#6b7280' : '#9ca3af') + ';">JPG, PNG, GIF - Max 5MB</small>' +
        '</div>' +
        '</div>' +
        '</div>' +

        // Grid
        '<div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:1.25rem;">' +

        // Ad Soyad
        '<div>' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-user" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Ad Soyad <span style="color:#ef4444;">*</span></span></label>' +
        '<input type="text" name="name" value="' + (isEdit ? escapeHtml(employee.name) : '') + '" required maxlength="50" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + ';" placeholder="Çalışan adı">' +
        '</div>' +

        // Ünvan
        '<div>' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #14b8a6 0%, #2dd4bf 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-id-badge" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Ünvan</span></label>' +
        '<input type="text" name="expertise" value="' + (isEdit ? escapeHtml(employee.expertise || '') : '') + '" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + ';" placeholder="Örn: Uzman Berber, Saç Tasarımcısı">' +
        '</div>' +

        '</div>' +

        // Özgeçmiş
        '<div style="margin-top:1.25rem;">' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #1e3a5f 0%, #2c5282 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-file-alt" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Özgeçmiş</span></label>' +
        '<textarea name="bio" rows="3" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + '; resize:vertical; font-family:inherit;" placeholder="Çalışan hakkında kısa bilgi...">' + (isEdit ? escapeHtml(employee.bio || '') : '') + '</textarea>' +
        '<small style="display:block; margin-top:0.375rem; color:' + (isDark ? '#94a3b8' : '#6b7280') + '; font-size:0.75rem;">Hakkımızda sayfasında görünecek özgeçmiş bilgisi</small>' +
        '</div>' +

        '<div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:1.25rem; margin-top:1.25rem;">' +

        // Telefon
        '<div>' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #10b981 0%, #34d399 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-phone" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Telefon <span style="color:#ef4444;">*</span></span></label>' +
        '<input type="tel" name="phone" id="dynPhone" value="' + (isEdit ? formatPhoneDisplay(employee.phone) : '') + '" required placeholder="5XX XXX XX XX" maxlength="15" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + ';">' +
        '</div>' +

        // Email
        '<div>' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-envelope" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>E-posta <span style="color:#ef4444;">*</span></span></label>' +
        '<input type="email" name="email" value="' + (isEdit ? escapeHtml(employee.email || '') : '') + '" required placeholder="ornek@email.com" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + ';">' +
        '</div>' +

        // Password
        '<div>' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #ef4444 0%, #f87171 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-lock" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Şifre' + (isEdit ? '' : ' <span style="color:#ef4444;">*</span>') + '</span></label>' +
        '<input type="password" name="password" ' + (isEdit ? '' : 'required') + ' autocomplete="new-password" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + ';" placeholder="' + (isEdit ? 'Değiştirmek için girin' : 'Şifre') + '">' +
        (isEdit ? '<small style="display:block; margin-top:0.5rem; color:' + labelColor + '; font-size:0.75rem;">Boş bırakırsanız şifre değişmez</small>' : '') +
        '</div>' +

        // Commission
        '<div>' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #1e3a5f 0%, #2c5282 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-percentage" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Komisyon (%)</span></label>' +
        '<input type="number" name="commission_rate" value="' + (isEdit ? employee.commission_rate : '50') + '" step="0.01" min="0" max="100" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + ';">' +
        '</div>' +

        // Start Time
        '<div>' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #06b6d4 0%, #22d3ee 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-clock" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Başlangıç Saati</span></label>' +
        '<input type="time" name="start_time" value="' + (isEdit ? employee.working_hours_start : '09:00') + '" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + ';">' +
        '</div>' +

        // End Time
        '<div>' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #ec4899 0%, #f472b6 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-clock" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Bitiş Saati</span></label>' +
        '<input type="time" name="end_time" value="' + (isEdit ? employee.working_hours_end : '18:00') + '" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + ';">' +
        '</div>' +

        // Status
        '<div>' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #84cc16 0%, #a3e635 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-toggle-on" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Durum</span></label>' +
        '<select name="status" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + ';">' +
        '<option value="active" ' + (isEdit && employee.status === 'active' ? 'selected' : '') + '>Aktif</option>' +
        '<option value="inactive" ' + (isEdit && employee.status === 'inactive' ? 'selected' : '') + '>Pasif</option>' +
        '</select>' +
        '</div>' +

        // Role
        '<div>' +
        '<label style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #1e3a5f 0%, #2c5282 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-user-shield" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Yetki Seviyesi</span></label>' +
        '<select name="role" id="dynRole" onchange="togglePermissionsSection()" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + ';">' +
        '<option value="employee" ' + (isEdit && employee.role === 'employee' ? 'selected' : '') + '>Çalışan</option>' +
        '<option value="manager" ' + (isEdit && employee.role === 'manager' ? 'selected' : '') + '>Yönetici</option>' +
        '</select>' +
        '</div>' +

        '</div>' +

        // Hide from booking toggle
        '<div style="margin:1.5rem 0; padding:1rem 1.25rem; background:' + (isDark ? 'rgba(30,41,59,0.6)' : 'rgba(248,250,252,0.8)') + '; border:2px solid ' + (isDark ? '#475569' : '#e5e7eb') + '; border-radius:12px;">' +
        '<label style="display:flex; align-items:center; justify-content:space-between; cursor:pointer; gap:1rem;">' +
        '<div style="display:flex; align-items:center; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-eye-slash" style="font-size:0.75rem; color:white;"></i></div>' +
        '<div>' +
        '<span style="font-weight:600; color:' + labelColor + '; font-size:0.875rem;">Randevu ekranında gizle</span>' +
        '<small style="display:block; color:' + (isDark ? '#94a3b8' : '#6b7280') + '; font-size:0.75rem; margin-top:2px;">Aktif olursa müşteriler bu çalışanı randevu alırken göremez</small>' +
        '</div></div>' +
        '<input type="checkbox" name="hide_from_booking" value="1" ' + (isEdit && employee.hide_from_booking == 1 ? 'checked' : '') + ' style="width:20px; height:20px; accent-color:#f59e0b; cursor:pointer;">' +
        '</label>' +
        '</div>' +

        // Working Days
        '<div style="margin:1.5rem 0;">' +
        '<label style="display:flex; align-items:center; margin-bottom:0.75rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #f97316 0%, #fb923c 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-calendar-days" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Çalışma Günleri</span></label>' +
        '<div style="display:flex; flex-wrap:wrap; gap:0.75rem;">' + daysCheckboxes + '</div>' +
        '</div>' +

        // Services (Verilen Hizmetler)
        buildServiceCheckboxes(isEdit ? employee : null, isDark, labelColor, inputBg, inputBorder, textColor) +

        // Permissions
        '<div id="dynPermissionsSection" style="margin:1.5rem 0; padding:1.25rem; background:' + (isDark ? 'rgba(30,41,59,0.6)' : 'rgba(248,250,252,0.8)') + '; border:2px solid ' + (isDark ? '#475569' : '#e5e7eb') + '; border-radius:12px; display:' + (isEdit ? (employee.role === 'employee' ? 'block' : 'none') : 'block') + ';">' +
        '<h4 style="margin:0 0 1rem 0; color:' + labelColor + '; font-size:1rem; display:flex; align-items:center; gap:0.5rem;"><i class="fas fa-lock"></i> Erişilebilir Sayfalar</h4>' +
        '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:0.75rem;">' +
        buildPermissionCheckboxes(isEdit ? employee : null, inputBg, inputBorder, textColor) +
        '</div>' +
        '<small style="display:block; margin-top:0.75rem; color:' + (isDark ? '#94a3b8' : '#6b7280') + '; font-size:0.875rem;">Yöneticiler tüm sayfalara erişir.</small>' +
        '</div>' +

        '</form></div>';

    // Footer
    var footer = '<div class="modal-footer-shadow" style="padding:1.25rem 1.5rem; background:' + (isDark ? '#1e293b' : '#f8fafc') + '; display:flex; gap:0.75rem; justify-content:flex-end; flex-wrap:wrap; border-top:2px solid ' + (isDark ? '#475569' : '#e5e7eb') + ';">' +
        '<button type="button" onclick="closeDynamicModal()" class="modal-button-hover" style="padding:0.75rem 1.5rem; border:2px solid ' + inputBorder + '; border-radius:12px; background:' + bgColor + '; color:' + textColor + '; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.5rem; transition:all 0.2s;"><i class="fas fa-times"></i> <span>İptal</span></button>' +
        '<button type="submit" form="dynamicEmployeeForm" class="modal-button-hover" style="padding:0.75rem 1.5rem; border:none; border-radius:12px; background:linear-gradient(135deg, #1e3a5f 0%, #0f2847 100%); color:white; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.5rem; transition:all 0.2s;"><i class="fas fa-save"></i> <span>Kaydet</span></button>' +
        '</div>';

    modalContent.innerHTML = header + formBody + footer;
    modal.appendChild(modalContent);
    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    // Phone format
    var phoneInput = document.getElementById('dynPhone');
    if (phoneInput) {
        phoneInput.addEventListener('input', function (e) {
            var x = e.target.value.replace(/\D/g, '');
            while (x.length > 0 && x[0] === '0') {
                x = x.substring(1);
            }
            x = x.substring(0, 10);
            var formatted = '';
            if (x.length > 0) formatted = x.substring(0, 3);
            if (x.length > 3) formatted += ' ' + x.substring(3, 6);
            if (x.length > 6) formatted += ' ' + x.substring(6, 8);
            if (x.length > 8) formatted += ' ' + x.substring(8, 10);
            e.target.value = formatted;
        });
    }

    // Form submit - AJAX
    document.getElementById('dynamicEmployeeForm').onsubmit = async function (e) {
        e.preventDefault();

        var form = this;
        var submitBtn = document.querySelector('#dynamicModal button[type="submit"]');
        var originalText = submitBtn ? submitBtn.innerHTML : '';
        var isEditMode = form.querySelector('input[name="id"]') !== null;
        var employeeId = isEditMode ? form.querySelector('input[name="id"]').value : null;

        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Kaydediliyor...</span>';
            submitBtn.disabled = true;
        }

        var formData = new FormData(form);

        logDebug('Submitting form, isEdit: ' + isEditMode);

        try {
            var result;
            if (isEditMode) {
                result = await updateEmployee(formData);
            } else {
                result = await createEmployee(formData);
            }

            logDebug('API Result: ' + JSON.stringify(result));

            // Başka işletmede kayıtlı çalışan - SMS doğrulamalı transfer
            if (!result.success && result.requires_transfer_confirm) {
                if (submitBtn) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
                showTransferVerification(result, formData, form, isEditMode);
                return;
            }

            if (result.success) {
                showNotification(result.message || 'Çalışan kaydedildi', 'success');
                closeDynamicModal();

                // Sayfayı yenile - kartların güncel haliyle görünsün
                setTimeout(function () {
                    window.location.reload();
                }, 300);
            } else {
                showNotification(result.message || 'İşlem başarısız', 'error');
                if (submitBtn) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            }
        } catch (error) {
            logDebug('Submit error: ' + error.message);
            showNotification('Bir hata oluştu: ' + error.message, 'error');
            if (submitBtn) {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        }
    };

    modal.onclick = function (e) {
        if (e.target === modal) closeDynamicModal();
    };

    document.onkeydown = function (e) {
        if (e.key === 'Escape') closeDynamicModal();
    };

    // Setup photo upload event listeners
    setTimeout(function () {
        setupEmployeePhotoUpload();
    }, 100);

    logDebug('Employee modal created');
}

function buildServiceCheckboxes(employee, isDark, labelColor, inputBg, inputBorder, textColor) {
    var services = (window.employeeData && window.employeeData.allServices) ? window.employeeData.allServices : [];
    if (services.length === 0) return '';

    var assignedIds = (employee && employee.service_ids) ? employee.service_ids.map(Number) : [];

    var checkboxes = '';
    services.forEach(function (svc) {
        var checked = assignedIds.indexOf(Number(svc.id)) !== -1 ? 'checked' : '';
        checkboxes += '<label style="display:flex; align-items:center; gap:0.5rem; padding:0.625rem; background:' + inputBg + '; border:1px solid ' + inputBorder + '; border-radius:8px; cursor:pointer; transition:all 0.2s;">' +
            '<input type="checkbox" name="services[]" value="' + svc.id + '" ' + checked + ' style="width:18px; height:18px; cursor:pointer; accent-color:#10b981;"> ' +
            '<span style="color:' + textColor + ';">' + escapeHtml(svc.name) + '</span></label>';
    });

    return '<div style="margin:1.5rem 0; padding:1.25rem; background:' + (isDark ? 'rgba(30,41,59,0.6)' : 'rgba(248,250,252,0.8)') + '; border:2px solid ' + (isDark ? '#475569' : '#e5e7eb') + '; border-radius:12px;">' +
        '<h4 style="margin:0 0 1rem 0; color:' + labelColor + '; font-size:1rem; display:flex; align-items:center; gap:0.5rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #10b981 0%, #34d399 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-cut" style="font-size:0.75rem; color:white;"></i></div>' +
        ' Verilen Hizmetler</h4>' +
        '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:0.75rem;">' + checkboxes + '</div>' +
        '<small style="display:block; margin-top:0.75rem; color:' + (isDark ? '#94a3b8' : '#6b7280') + '; font-size:0.875rem;">Seçim yapılmazsa çalışan tüm hizmetleri verebilir.</small>' +
        '</div>';
}

function buildPermissionCheckboxes(employee, inputBg, inputBorder, textColor) {
    var pages = (window.employeeData && Array.isArray(window.employeeData.permissionPages) && window.employeeData.permissionPages.length)
        ? window.employeeData.permissionPages
        : [
            { value: 'index.php', label: 'Dashboard' },
            { value: 'daily_view.php', label: 'Günlük Takip' },
            { value: 'appointments.php', label: 'Randevular' },
            { value: 'revenues.php', label: 'Gelir Raporu' },
            { value: 'bekleyen_odemeler.php', label: 'Bekleyen Ödemeler' },
            { value: 'expenses.php', label: 'Giderler' },
            { value: 'customers.php', label: 'Müşteriler' },
            { value: 'reviews.php', label: 'Değerlendirmeler' },
            { value: 'campaigns.php', label: 'Kampanyalar' },
            { value: 'employees.php', label: 'Çalışanlar' },
            { value: 'employee_salaries.php', label: 'Maaş Yönetimi' },
            { value: 'services.php', label: 'Hizmetler' },
            { value: 'gallery.php', label: 'Galeri Yönetimi' },
            { value: 'trash.php', label: 'Çöp Kutusu' },
            { value: 'settings.php', label: 'Site Ayarları' }
        ];

    var allowedPages = employee && employee.allowed_pages ? employee.allowed_pages.split(',') : [];
    // Eski izinler (daily_view / appointments) araç sayfalarında da işaretli görünsün
    var aliasMap = {
        'servis_kabulleri.php': ['servis_kabulleri.php', 'daily_view.php'],
        'service_orders.php': ['service_orders.php', 'appointments.php'],
        'vehicles.php': ['vehicles.php', 'customers.php']
    };
    var html = '';

    pages.forEach(function (page) {
        var keys = aliasMap[page.value] || [page.value];
        var checked = keys.some(function (k) { return allowedPages.includes(k); }) ? 'checked' : '';
        html += '<label style="display:flex; align-items:center; gap:0.5rem; padding:0.625rem; background:' + inputBg + '; border:1px solid ' + inputBorder + '; border-radius:8px; cursor:pointer; transition:all 0.2s;">' +
            '<input type="checkbox" name="allowed_pages[]" value="' + page.value + '" ' + checked + ' style="width:18px; height:18px; cursor:pointer; accent-color:#1e3a5f;"> ' +
            '<span style="color:' + textColor + ';">' + page.label + '</span></label>';
    });

    return html;
}

function togglePermissionsSection() {
    var dynRoleSelect = document.getElementById('dynRole');
    var dynPermissionsSection = document.getElementById('dynPermissionsSection');

    if (dynRoleSelect && dynPermissionsSection) {
        if (dynRoleSelect.value === 'employee') {
            dynPermissionsSection.style.display = 'block';
        } else {
            dynPermissionsSection.style.display = 'none';
        }
    }
}

function editEmployee(id) {
    logDebug('editEmployee called with id: ' + id);
    var employee = null;
    for (var i = 0; i < allEmployees.length; i++) {
        if (allEmployees[i].id == id) {
            employee = allEmployees[i];
            break;
        }
    }

    if (!employee && window.employeeData && window.employeeData.employees) {
        employee = window.employeeData.employees.find(e => e.id == id);
    }

    logDebug('Employee found: ' + (employee ? employee.name : 'NOT FOUND'));
    if (!employee) return;

    createEmployeeModal(employee);
}

// IMAGE PREVIEW
function previewImage(event) {
    var file = event.target.files[0];
    if (file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            var container = document.getElementById('imagePreviewContainer');
            var img = document.getElementById('imagePreview');
            if (container && img) {
                img.src = e.target.result;
                container.style.display = 'block';
            }
        };
        reader.readAsDataURL(file);
    }
}

// ==========================================
// DELETE FUNCTIONS
// ==========================================
function deleteEmployee(id, name, futureAppointments) {
    logDebug('deleteEmployee called: ' + id + ', ' + name + ', appointments: ' + futureAppointments);
    deleteEmployeeId = id;

    showEmployeeDataChoiceModal(
        '<strong>' + escapeHtml(name) + '</strong> çalışanı çöp kutusuna taşınacak.',
        futureAppointments > 0,
        async function (keepData) {
            try {
                var result = await deleteEmployeeApi(id, keepData);
                if (result.success) {
                    showNotification(result.message || 'Çalışan silindi', 'success');
                    removeEmployeeFromList(id);
                } else {
                    showNotification(result.message || 'Silme işlemi başarısız', 'error');
                }
            } catch (error) {
                showNotification('Bir hata oluştu: ' + error.message, 'error');
            }
        },
        futureAppointments > 0 ? function () { editEmployee(id); } : null
    );
}

/**
 * keepData callback: true = verileri tut, false = birlikte sil
 * hasFutureAppointments: true ise "verileri tut" seçeneği engellenir / uyarı gösterilir
 */
function showEmployeeDataChoiceModal(messageHtml, hasFutureAppointments, onChoice, onEditPassive) {
    var oldModal = document.getElementById('dynamicDeleteModal');
    if (oldModal) oldModal.remove();

    var isDark = document.body.classList.contains('dark-mode');
    var bgColor = isDark ? '#1e293b' : '#ffffff';
    var textColor = isDark ? '#f1f5f9' : '#1f2937';
    var borderColor = isDark ? '#475569' : '#323232';
    var labelColor = isDark ? '#94a3b8' : '#6b7280';
    var cardBg = isDark ? '#334155' : '#f8fafc';
    var keepBorder = isDark ? '#3b82f6' : '#2563eb';
    var purgeBorder = isDark ? '#f87171' : '#dc2626';

    var futureWarning = hasFutureAppointments
        ? '<div style="background:#fffbeb;padding:0.75rem 1rem;border-radius:10px;border:1px solid #fcd34d;color:#92400e;text-align:left;margin:0 0 1rem;font-size:0.875rem;">' +
          '<i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i> Aktif gelecek randevu var. <strong>Verileri tut</strong> seçilemez; önce randevuları taşıyın/iptal edin, çalışanı Pasif yapın veya <strong>birlikte sil</strong> seçin.' +
          '</div>'
        : '';

    var modal = document.createElement('div');
    modal.id = 'dynamicDeleteModal';
    modal.className = 'dynamic-modal-overlay';
    modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:9999999; display:flex; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(8px);';

    modal.innerHTML =
        '<div class="dynamic-modal-content" style="background:' + bgColor + '; padding:2rem 1.5rem; border-radius:20px; width:100%; max-width:480px; border:3px solid ' + borderColor + '; box-shadow:0 20px 60px rgba(0,0,0,0.3), 5px 5px 0 ' + borderColor + '; text-align:center;">' +
        '<div style="width:72px; height:72px; border-radius:50%; background:linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(220, 38, 38, 0.15) 100%); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem; border:3px solid rgba(239, 68, 68, 0.2);"><i class="fas fa-trash-alt" style="font-size:2rem; color:#ef4444;"></i></div>' +
        '<h3 style="margin:0 0 0.75rem 0; font-size:1.3rem; font-weight:700; color:' + textColor + ';">Çalışanı Sil</h3>' +
        '<p style="margin:0 0 1rem 0; color:' + labelColor + '; font-size:0.95rem; line-height:1.55;">' + messageHtml + '</p>' +
        futureWarning +
        '<p style="margin:0 0 1rem 0; color:' + textColor + '; font-size:0.9rem; font-weight:600; text-align:left;">Randevu ve gelir verileri ne olsun?</p>' +
        '<div style="display:flex; flex-direction:column; gap:0.75rem; margin-bottom:1.25rem;">' +
        '<button type="button" id="keepDataBtn" ' + (hasFutureAppointments ? 'disabled' : '') + ' style="text-align:left; padding:1rem; border:2px solid ' + keepBorder + '; border-radius:12px; background:' + (hasFutureAppointments ? (isDark ? '#1e293b' : '#f1f5f9') : cardBg) + '; color:' + textColor + '; cursor:' + (hasFutureAppointments ? 'not-allowed' : 'pointer') + '; opacity:' + (hasFutureAppointments ? '0.55' : '1') + ';">' +
        '<div style="font-weight:700; margin-bottom:0.25rem;"><i class="fas fa-shield-alt" style="color:#2563eb; margin-right:0.35rem;"></i>Verileri tut <span style="font-weight:500;font-size:0.8rem;color:' + labelColor + ';">(önerilen)</span></div>' +
        '<div style="font-size:0.8rem; color:' + labelColor + '; line-height:1.4;">Randevu ve gelirler kalır. Çalışan çöpe gider; 14 gün içinde geri yüklenebilir.</div>' +
        '</button>' +
        '<button type="button" id="purgeDataBtn" style="text-align:left; padding:1rem; border:2px solid ' + purgeBorder + '; border-radius:12px; background:' + cardBg + '; color:' + textColor + '; cursor:pointer;">' +
        '<div style="font-weight:700; margin-bottom:0.25rem;"><i class="fas fa-bomb" style="color:#dc2626; margin-right:0.35rem;"></i>Birlikte sil</div>' +
        '<div style="font-size:0.8rem; color:' + labelColor + '; line-height:1.4;">Randevular çöpe alınır, <strong>gelir kayıtları kalıcı silinir</strong> (geri gelmez).</div>' +
        '</button>' +
        '</div>' +
        '<div style="display:flex; gap:0.75rem; justify-content:center; flex-wrap:wrap;">' +
        (onEditPassive ? '<button type="button" id="editPassiveBtn" style="padding:0.75rem 1.25rem; border:2px solid ' + (isDark ? '#475569' : '#e5e7eb') + '; border-radius:12px; background:' + bgColor + '; color:' + textColor + '; font-weight:600; cursor:pointer;"><i class="fas fa-user-slash"></i> Pasif Yap</button>' : '') +
        '<button type="button" id="cancelDeleteBtn" style="padding:0.75rem 1.25rem; border:2px solid ' + (isDark ? '#475569' : '#e5e7eb') + '; border-radius:12px; background:' + bgColor + '; color:' + textColor + '; font-weight:600; cursor:pointer;"><i class="fas fa-times"></i> İptal</button>' +
        '</div></div>';

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    function finish(keepData) {
        closeDeleteModal();
        if (onChoice) onChoice(keepData);
    }

    document.getElementById('cancelDeleteBtn').addEventListener('click', closeDeleteModal);
    var keepBtn = document.getElementById('keepDataBtn');
    if (keepBtn && !hasFutureAppointments) {
        keepBtn.addEventListener('click', function () { finish(true); });
    }
    document.getElementById('purgeDataBtn').addEventListener('click', function () { finish(false); });
    var editBtn = document.getElementById('editPassiveBtn');
    if (editBtn && onEditPassive) {
        editBtn.addEventListener('click', function () {
            closeDeleteModal();
            onEditPassive();
        });
    }

    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeDeleteModal();
    });
}

function showDeleteConfirmation(message, onConfirm) {
    var oldModal = document.getElementById('dynamicDeleteModal');
    if (oldModal) oldModal.remove();

    var isDark = document.body.classList.contains('dark-mode');
    var bgColor = isDark ? '#1e293b' : '#ffffff';
    var textColor = isDark ? '#f1f5f9' : '#1f2937';
    var borderColor = isDark ? '#475569' : '#323232';
    var labelColor = isDark ? '#94a3b8' : '#6b7280';

    var modal = document.createElement('div');
    modal.id = 'dynamicDeleteModal';
    modal.className = 'dynamic-modal-overlay';
    modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:9999999; display:flex; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(8px);';

    modal.innerHTML = '<div class="dynamic-modal-content" style="background:' + bgColor + '; padding:2.5rem 2rem; border-radius:20px; width:100%; max-width:440px; border:3px solid ' + borderColor + '; box-shadow:0 20px 60px rgba(0,0,0,0.3), 5px 5px 0 ' + borderColor + '; text-align:center;">' +
        '<div style="width:80px; height:80px; border-radius:50%; background:linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(220, 38, 38, 0.15) 100%); display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; border:3px solid rgba(239, 68, 68, 0.2);"><i class="fas fa-trash-alt icon-bounce" style="font-size:2.25rem; color:#ef4444;"></i></div>' +
        '<h3 style="margin:0 0 1rem 0; font-size:1.4rem; font-weight:700; color:' + textColor + '; line-height:1.3;">Silme Onayı</h3>' +
        '<p style="margin:0 0 2rem 0; color:' + labelColor + '; font-size:1rem; line-height:1.6;">' + message + '</p>' +
        '<div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">' +
        '<button type="button" id="cancelDeleteBtn" style="padding:0.875rem 1.75rem; border:2px solid ' + (isDark ? '#475569' : '#e5e7eb') + '; border-radius:12px; background:' + bgColor + '; color:' + textColor + '; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.625rem;"><i class="fas fa-times"></i> <span>İptal</span></button>' +
        '<button type="button" id="confirmDeleteBtn" style="padding:0.875rem 1.75rem; border:none; border-radius:12px; background:linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color:white; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.625rem;"><i class="fas fa-trash"></i> <span>Evet, Sil</span></button>' +
        '</div></div>';

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    document.getElementById('cancelDeleteBtn').addEventListener('click', function () {
        closeDeleteModal();
    });

    document.getElementById('confirmDeleteBtn').addEventListener('click', function () {
        closeDeleteModal();
        if (onConfirm && typeof onConfirm === 'function') {
            onConfirm();
        }
    });

    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeDeleteModal();
    });
}

function closeDeleteModal() {
    var modal = document.getElementById('dynamicDeleteModal');
    if (modal) modal.remove();
    document.body.style.overflow = '';
}

function closeModal() {
    closeDynamicModal();
}

// ==========================================
// WARNING MODAL
// ==========================================
function showWarningModal(title, message, onAction, actionLabel) {
    var oldModal = document.getElementById('dynamicWarningModal');
    if (oldModal) oldModal.remove();

    var isDark = document.body.classList.contains('dark-mode');
    var bgColor = isDark ? '#1e293b' : '#ffffff';
    var textColor = isDark ? '#f1f5f9' : '#1f2937';
    var borderColor = isDark ? '#475569' : '#323232';

    var modal = document.createElement('div');
    modal.id = 'dynamicWarningModal';
    modal.className = 'dynamic-modal-overlay';
    modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:9999999; display:flex; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(8px);';

    modal.innerHTML = '<div class="dynamic-modal-content" style="background:' + bgColor + '; padding:2.5rem 2rem; border-radius:20px; width:100%; max-width:500px; border:3px solid ' + borderColor + '; box-shadow:0 20px 60px rgba(0,0,0,0.3), 5px 5px 0 ' + borderColor + '; text-align:center;">' +
        '<div style="width:80px; height:80px; border-radius:50%; background:linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(217, 119, 6, 0.15) 100%); display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; border:3px solid rgba(245, 158, 11, 0.2);"><i class="fas fa-exclamation-triangle icon-bounce" style="font-size:2.25rem; color:#f59e0b;"></i></div>' +
        '<h3 style="margin:0 0 1rem 0; font-size:1.4rem; font-weight:700; color:' + textColor + '; line-height:1.3;">' + title + '</h3>' +
        '<div style="margin:0 0 2rem 0; color:' + textColor + '; font-size:0.95rem; line-height:1.6;">' + message + '</div>' +
        '<div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">' +
        '<button type="button" id="closeWarningBtn" style="padding:0.875rem 1.75rem; border:2px solid ' + (isDark ? '#475569' : '#e5e7eb') + '; border-radius:12px; background:' + bgColor + '; color:' + textColor + '; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.625rem;"><i class="fas fa-times"></i> <span>Kapat</span></button>' +
        '<button type="button" id="actionWarningBtn" style="padding:0.875rem 1.75rem; border:none; border-radius:12px; background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color:white; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.625rem;"><i class="fas fa-edit"></i> <span>' + actionLabel + '</span></button>' +
        '</div></div>';

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    document.getElementById('closeWarningBtn').addEventListener('click', function () {
        modal.remove();
        document.body.style.overflow = '';
    });

    document.getElementById('actionWarningBtn').addEventListener('click', function () {
        modal.remove();
        document.body.style.overflow = '';
        if (onAction && typeof onAction === 'function') {
            setTimeout(function () {
                onAction();
            }, 100);
        }
    });

    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            modal.remove();
            document.body.style.overflow = '';
        }
    });
}

// ==========================================
// LIMIT MODAL
// ==========================================
function closeLimitModal() {
    var limitModal = document.getElementById('limitModal');
    if (limitModal) limitModal.classList.remove('active');
}

function goToUpgrade() {
    window.location.href = 'settings.php?tab=subscription';
}

// ==========================================
// EMPLOYEE PHOTO UPLOAD HELPERS
// ==========================================
function openEmployeePhotoUpload() {
    var input = document.getElementById('employeePhotoInput');
    if (input) input.click();
}

function setupEmployeePhotoUpload() {
    var input = document.getElementById('employeePhotoInput');
    var area = document.getElementById('employeePhotoUploadArea');
    var preview = document.getElementById('employeePhotoPreview');
    var placeholder = document.getElementById('employeePhotoPlaceholder');

    if (!input || !area) return;

    // File input change
    input.addEventListener('change', function (e) {
        var file = e.target.files[0];
        if (file) {
            // Validation
            if (file.size > 5 * 1024 * 1024) {
                showNotification('Dosya boyutu 5MB\'dan küçük olmalı', 'error');
                return;
            }

            var validTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (validTypes.indexOf(file.type) === -1) {
                showNotification('Sadece JPG, PNG, GIF formatları desteklenir', 'error');
                return;
            }

            // Show preview
            var reader = new FileReader();
            reader.onload = function (e) {
                var isDark = document.body.classList.contains('dark-mode');
                if (preview) {
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview" style="max-width: 100px; max-height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid ' + (isDark ? '#60a5fa' : '#1e3a5f') + ';">';
                    preview.style.display = 'block';
                }
                if (placeholder) {
                    placeholder.style.display = 'none';
                }
            };
            reader.readAsDataURL(file);
        }
    });

    // Drag & Drop
    area.addEventListener('dragover', function (e) {
        e.preventDefault();
        area.style.borderColor = '#1e3a5f';
        var isDark = document.body.classList.contains('dark-mode');
        area.style.background = isDark ? '#1e293b' : '#f3f4f6';
    });

    area.addEventListener('dragleave', function (e) {
        e.preventDefault();
        area.style.borderColor = '';
        area.style.background = '';
    });

    area.addEventListener('drop', function (e) {
        e.preventDefault();
        area.style.borderColor = '';
        area.style.background = '';

        var file = e.dataTransfer.files[0];
        if (file) {
            // Transfer file to input
            var dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            input.files = dataTransfer.files;

            // Trigger change event
            var event = new Event('change');
            input.dispatchEvent(event);
        }
    });
}

// ESC KEY
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeDynamicModal();
        closeDeleteModal();
        closeFilterSidebar();
    }
});

logDebug('employees.js loaded (AJAX API version)');
