// ==========================================
// CUSTOMERS.JS - AJAX API Version
// ==========================================

var allCustomers = [];
var customerModalEl = null;
var historyModalEl = null;
var deleteModalEl = null;

// Log fonksiyonu
function logDebug(msg) {
    if (window.RANDU_DEBUG || (typeof localStorage !== 'undefined' && localStorage.getItem('RANDU_DEBUG') === '1')) {
        console.log('[CUSTOMERS] ' + msg);
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
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
        }
    };

    // JSON body varsa stringify et
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
        options.body = JSON.stringify(options.body);
    }

    // FormData ise Content-Type header'ını kaldır (browser otomatik ekler)
    if (options.body instanceof FormData) {
        delete defaults.headers['Content-Type'];
    }

    const config = { ...defaults, ...options };
    // FormData ile gönderimde merge edilen options.headers CSRF'i ezebilir, koru
    if (options.headers) {
        config.headers = { ...defaults.headers, ...options.headers };
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
// Customer API Functions
// ==========================================
async function loadCustomers(params = {}) {
    const queryString = new URLSearchParams(params).toString();
    const url = 'api/customers/list.php' + (queryString ? '?' + queryString : '');
    return await apiRequest(url);
}

async function getCustomerApi(id) {
    return await apiRequest('api/customers/get.php?id=' + id);
}

async function createCustomer(data) {
    return await apiRequest('api/customers/create.php', {
        method: 'POST',
        body: data
    });
}

async function updateCustomer(data) {
    return await apiRequest('api/customers/update.php', {
        method: 'POST',
        body: data
    });
}

async function deleteCustomerApi(id) {
    return await apiRequest('api/customers/delete.php', {
        method: 'POST',
        body: { id: id }
    });
}

async function bulkDeleteCustomers(ids) {
    return await apiRequest('api/customers/bulk_delete.php', {
        method: 'POST',
        body: { ids: ids }
    });
}

async function getCustomerHistory(customerId, params = {}) {
    const queryString = new URLSearchParams({ customer_id: customerId, ...params }).toString();
    return await apiRequest('api/customers/history.php?' + queryString);
}

// ==========================================
// Liste Güncelleme Fonksiyonları (Sayfa yenilemeden)
// ==========================================
function updateCustomerInList(customerId, newData) {
    logDebug('Updating customer in list: ' + customerId);
    
    // allCustomers array'ini güncelle
    for (var i = 0; i < allCustomers.length; i++) {
        if (allCustomers[i].id == customerId) {
            allCustomers[i] = { ...allCustomers[i], ...newData };
            break;
        }
    }
    
    // DOM'daki kartı güncelle
    var card = document.querySelector('.customer-card[data-id="' + customerId + '"]');
    if (!card) {
        // data-id yoksa checkbox'tan bul
        var checkbox = document.querySelector('.customer-checkbox[value="' + customerId + '"]');
        if (checkbox) {
            card = checkbox.closest('.customer-card');
        }
    }
    
    if (card) {
        // İsim güncelle
        var nameEl = card.querySelector('.customer-name, h4, .fw-bold');
        if (nameEl) nameEl.textContent = newData.name || '';
        
        // Telefon güncelle
        var phoneEl = card.querySelector('.customer-phone, [data-field="phone"]');
        if (phoneEl) {
            var phoneText = phoneEl.querySelector('span') || phoneEl;
            phoneText.textContent = formatPhoneDisplay(newData.phone || '');
        }
        
        logDebug('Card updated in DOM');
    } else {
        logDebug('Card not found, reloading page');
        setTimeout(() => window.location.reload(), 500);
    }
}

function removeCustomerFromList(customerId) {
    logDebug('Removing customer from list: ' + customerId);
    
    // allCustomers array'inden kaldır
    allCustomers = allCustomers.filter(c => c.id != customerId);
    
    // DOM'dan kaldır
    var card = document.querySelector('.customer-card[data-id="' + customerId + '"]');
    if (!card) {
        var checkbox = document.querySelector('.customer-checkbox[value="' + customerId + '"]');
        if (checkbox) {
            card = checkbox.closest('.customer-card');
        }
    }
    
    if (card) {
        card.style.transition = 'all 0.3s ease';
        card.style.opacity = '0';
        card.style.transform = 'translateX(20px)';
        setTimeout(() => card.remove(), 300);
        
        // Toplam sayıyı güncelle
        var countEl = document.querySelector('.customer-count, .total-count');
        if (countEl) {
            var currentCount = parseInt(countEl.textContent) || 0;
            countEl.textContent = Math.max(0, currentCount - 1);
        }
    }
}

function addCustomerToList(customer) {
    logDebug('Adding customer to list: ' + customer.name);
    
    // allCustomers array'ine ekle
    allCustomers.unshift(customer);
    
    // DOM'a ekle
    var container = document.querySelector('.customers-grid');
    if (!container) {
        logDebug('Container not found, reloading page');
        setTimeout(() => window.location.reload(), 500);
        return;
    }
    
    var isDark = document.body.classList.contains('dark-mode');
    
    // Yeni kart oluştur
    var card = document.createElement('div');
    card.className = 'customer-card';
    card.setAttribute('data-id', customer.id);
    card.setAttribute('data-customer-id', customer.id);
    card.style.opacity = '0';
    card.style.transform = 'translateY(-20px)';
    
    var cardHTML = '<div class="card-content">' +
        '<div class="customer-info">' +
        '<input type="checkbox" class="customer-checkbox" value="' + customer.id + '" onchange="updateBulkActions(this)">' +
        '<div class="customer-details">' +
        '<h4 class="customer-name">' + escapeHtml(customer.name) + '</h4>' +
        '<div class="customer-contact customer-phone" data-field="phone">' +
        '<i class="fas fa-phone"></i><span>' + formatPhoneDisplay(customer.phone) + '</span>' +
        '</div>' +
        '<div class="customer-last-appointment">' +
        '<strong>Son Randevu:</strong> Yok' +
        '</div>' +
        '</div>' +
        '</div>' +
        '<div class="card-stats">' +
        '<div class="stat-item">' +
        '<div class="value total-spent">₺0,00</div>' +
        '<div class="label">Toplam Harcama</div>' +
        '</div>' +
        '<div class="stat-item">' +
        '<div class="value appointments">0</div>' +
        '<div class="label">Randevu Sayısı</div>' +
        '</div>' +
        '<div class="stat-item">' +
        '<div class="value register-date">' + formatDate(new Date().toISOString()) + '</div>' +
        '<div class="label">Kayıt Tarihi</div>' +
        '</div>' +
        '</div>' +
        '<div class="card-actions">' +
        '<button type="button" class="btn btn-sm btn-secondary" onclick="viewCustomerHistory(' + customer.id + ')">' +
        '<i class="fas fa-history"></i> Geçmiş' +
        '</button>' +
        '<button type="button" class="btn btn-sm btn-danger" onclick="deleteCustomer(' + customer.id + ', \'' + escapeHtml(customer.name).replace(/'/g, "\\'") + '\', 0)">' +
        '<i class="fas fa-trash"></i>' +
        '</button>' +
        '</div>' +
        '</div>';
    
    card.innerHTML = cardHTML;
    
    // Header'dan sonra ekle
    var header = container.querySelector('.customers-grid-header');
    if (header && header.nextSibling) {
        container.insertBefore(card, header.nextSibling);
    } else {
        container.appendChild(card);
    }
    
    // Animasyon
    setTimeout(function() {
        card.style.transition = 'all 0.3s ease';
        card.style.opacity = '1';
        card.style.transform = 'translateY(0)';
    }, 50);
    
    // Toplam sayıyı güncelle
    var countEl = document.querySelector('.customer-count, .total-count');
    if (countEl) {
        var currentCount = parseInt(countEl.textContent) || 0;
        countEl.textContent = currentCount + 1;
    }
    
    // "Henüz müşteri yok" mesajını kaldır
    var emptyMsg = container.parentElement.querySelector('[style*="text-align: center"]');
    if (emptyMsg && emptyMsg.textContent.includes('Henüz')) {
        emptyMsg.remove();
    }
    
    logDebug('Customer card added to DOM');
}

// Müşteri listesini yeniden render et
function renderCustomerList(customers) {
    logDebug('Rendering customer list: ' + customers.length + ' customers');
    
    var container = document.querySelector('.customers-grid');
    if (!container) {
        logDebug('Container not found');
        return;
    }
    
    // Header'ları koru, sadece müşteri kartlarını temizle
    var bulkSelectHeader = container.querySelector('.bulk-select-header');
    var listHeader = container.querySelector('.customer-list-header');
    
    // Sadece customer-card'ları kaldır
    var cards = container.querySelectorAll('.customer-card');
    cards.forEach(function(card) {
        card.remove();
    });
    
    // Empty state varsa onu da kaldır
    var emptyState = container.querySelector('[style*="grid-column"]');
    if (emptyState) emptyState.remove();
    
    if (customers.length === 0) {
        var emptyDiv = document.createElement('div');
        emptyDiv.style.cssText = 'text-align: center; padding: 3rem; grid-column: 1 / -1;';
        emptyDiv.innerHTML = '<i class="fas fa-search" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 1rem; display: block;"></i>' +
            '<h3>Müşteri Bulunamadı</h3>' +
            '<p style="color: var(--dark-gray);">Arama kriterlerinize uygun müşteri bulunamadı.</p>';
        container.appendChild(emptyDiv);
        return;
    }
    
    customers.forEach(function(customer) {
        var card = document.createElement('div');
        card.className = 'customer-card';
        card.setAttribute('data-id', customer.id);
        card.setAttribute('data-customer-id', customer.id);
        
        var loyalBadge = (customer.appointment_count || customer.total_visits || 0) >= 5 
            ? '<span class="badge badge-warning" title="Sadık Müşteri"><i class="fas fa-star"></i></span>' 
            : '';
        
        var lastAppointment = customer.last_appointment || customer.last_visit;
        var totalSpent = customer.total_spent || 0;
        var appointmentCount = customer.appointment_count || customer.total_visits || 0;
        var futureCount = customer.future_appointment_count || 0;
        
        card.innerHTML = '<div class="card-content">' +
            '<div class="customer-info">' +
            '<input type="checkbox" class="customer-checkbox" value="' + customer.id + '" onchange="updateBulkActions(this)">' +
            '<div class="customer-details">' +
            '<h4 class="customer-name">' + escapeHtml(customer.name) + ' ' + loyalBadge + '</h4>' +
            '<div class="customer-contact customer-phone" data-field="phone">' +
            '<i class="fas fa-phone"></i><span>' + formatPhoneDisplay(customer.phone) + '</span>' +
            '</div>' +
            '<div class="customer-last-appointment">' +
            '<strong>Son Randevu:</strong> ' + (lastAppointment ? formatDate(lastAppointment) : 'Yok') +
            '</div>' +
            '</div>' +
            '</div>' +
            '<div class="card-stats">' +
            '<div class="stat-item">' +
            '<div class="value total-spent">' + formatCurrency(totalSpent) + '</div>' +
            '<div class="label">Toplam Harcama</div>' +
            '</div>' +
            '<div class="stat-item">' +
            '<div class="value appointments">' + appointmentCount + '</div>' +
            '<div class="label">Randevu Sayısı</div>' +
            '</div>' +
            '<div class="stat-item">' +
            '<div class="value register-date">' + formatDate(customer.created_at) + '</div>' +
            '<div class="label">Kayıt Tarihi</div>' +
            '</div>' +
            '</div>' +
            '<div class="card-actions">' +
            (customer.is_guest == 1 ? '<button type="button" class="btn btn-sm btn-primary" onclick="editCustomer(' + customer.id + ')">' +
            '<i class="fas fa-edit"></i> Düzenle' +
            '</button>' : '') +
            '<button type="button" class="btn btn-sm btn-secondary" onclick="viewCustomerHistory(' + customer.id + ')">' +
            '<i class="fas fa-history"></i> Geçmiş' +
            '</button>' +
            '<button type="button" class="btn btn-sm btn-danger" onclick="deleteCustomer(' + customer.id + ', \'' + escapeHtml(customer.name).replace(/'/g, "\\'") + '\', ' + futureCount + ')">' +
            '<i class="fas fa-trash"></i>' +
            '</button>' +
            '</div>' +
            '</div>';
        
        container.appendChild(card);
    });
    
    // Toplam sayıyı güncelle
    var countEl = document.querySelector('.customer-count, .total-count');
    if (countEl) {
        countEl.textContent = customers.length;
    }
    
    logDebug('Customer list rendered');
}

// ==========================================
// Sayfa Yükleme
// ==========================================
document.addEventListener('DOMContentLoaded', function () {
    logDebug('DOMContentLoaded fired');

    allCustomers = window.customerData ? window.customerData.customers : [];
    logDebug('Customers loaded: ' + allCustomers.length);

    customerModalEl = document.getElementById('customerModal');
    historyModalEl = document.getElementById('historyModal');
    deleteModalEl = document.getElementById('deleteConfirmationModal');

    logDebug('customerModalEl: ' + (customerModalEl ? 'found' : 'NOT FOUND'));
    logDebug('historyModalEl: ' + (historyModalEl ? 'found' : 'NOT FOUND'));
    logDebug('deleteModalEl: ' + (deleteModalEl ? 'found' : 'NOT FOUND'));
    
    // Filtre form AJAX handler
    var filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            var searchInput = document.getElementById('search_input');
            var sortSelect = document.getElementById('sort_select');
            var genderSelect = document.getElementById('gender_select');
            var lastVisitInput = document.getElementById('last_visit_days');
            var birthdayInput = document.getElementById('birthday_days');
            
            var search = searchInput ? searchInput.value.trim() : '';
            var sort = sortSelect ? String(sortSelect.value || '').trim() : '';
            if (!sort) {
                sort = 'created_at_DESC';
                if (sortSelect) sortSelect.value = sort;
            }
            var gender = genderSelect ? genderSelect.value : '';
            var lastVisitDays = lastVisitInput ? lastVisitInput.value : '';
            var birthdayDays = birthdayInput ? birthdayInput.value : '';
            
            // Sıralama değerini parçala (appointment_count_ASC / created_at_DESC)
            var lastUnderscoreIndex = sort.lastIndexOf('_');
            var sortField = lastUnderscoreIndex > 0 ? sort.substring(0, lastUnderscoreIndex) : 'created_at';
            var sortOrder = lastUnderscoreIndex > 0 ? sort.substring(lastUnderscoreIndex + 1) : 'DESC';
            if (sortOrder !== 'ASC' && sortOrder !== 'DESC') {
                sortField = 'created_at';
                sortOrder = 'DESC';
            }
            
            logDebug('Filtering: search=' + search + ', sort=' + sortField + ', order=' + sortOrder);
            
            // Loading göster
            var applyBtn = filterForm.querySelector('button[type="submit"]');
            var originalBtnText = applyBtn ? applyBtn.innerHTML : '';
            if (applyBtn) {
                applyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Yükleniyor...';
                applyBtn.disabled = true;
            }
            
            try {
                var result = await loadCustomers({
                    search: search,
                    sort: sortField,
                    order: sortOrder,
                    gender: gender,
                    last_visit_days: lastVisitDays,
                    birthday_days: birthdayDays
                });
                
                if (result.success) {
                    // Listeyi güncelle
                    allCustomers = result.customers || [];
                    renderCustomerList(allCustomers);
                    closeFilterSidebar();
                    
                    // URL'i güncelle (sayfa yenilemeden)
                    var newUrl = 'customers.php?search=' + encodeURIComponent(search) + '&sort=' + encodeURIComponent(sort);
                    if (gender) newUrl += '&gender=' + encodeURIComponent(gender);
                    if (lastVisitDays) newUrl += '&last_visit_days=' + encodeURIComponent(lastVisitDays);
                    if (birthdayDays) newUrl += '&birthday_days=' + encodeURIComponent(birthdayDays);
                    window.history.pushState({}, '', newUrl);
                    
                    showNotification('Filtre uygulandı: ' + allCustomers.length + ' müşteri bulundu', 'success');
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
        
        // Temizle butonu AJAX
        var resetBtn = filterForm.querySelector('.btn-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', async function(e) {
                e.preventDefault();
                
                // Input'ları temizle
                var searchInput = document.getElementById('search_input');
                var sortSelect = document.getElementById('sort_select');
                if (searchInput) searchInput.value = '';
                if (sortSelect) sortSelect.value = 'name_ASC';
                
                // Tüm müşterileri yükle
                try {
                    var result = await loadCustomers({});
                    if (result.success) {
                        allCustomers = result.customers || [];
                        renderCustomerList(allCustomers);
                        closeFilterSidebar();
                        window.history.pushState({}, '', 'customers.php');
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
    // 0 ile başlıyorsa kaldır
    if (phone.length === 11 && phone[0] === '0') {
        phone = phone.substring(1);
    }
    if (phone.length === 10) {
        return phone.substr(0, 3) + ' ' + phone.substr(3, 3) + ' ' + phone.substr(6, 2) + ' ' + phone.substr(8, 2);
    }
    return phone;
}

// Telefonu input için formatla (0 olmadan)
function formatPhoneForInput(phone) {
    if (!phone) return '';
    phone = phone.replace(/\D/g, '');
    if (phone.length === 11 && phone[0] === '0') {
        phone = phone.substring(1);
    }
    if (phone.length === 10) {
        return phone.substr(0, 3) + ' ' + phone.substr(3, 3) + ' ' + phone.substr(6, 2) + ' ' + phone.substr(8, 2);
    }
    return phone;
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' });
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
// MODAL FONKSIYONLARI
// ==========================================
function showAddModal() {
    logDebug('showAddModal called');
    createCustomerModal(null);
}

function closeDynamicModal() {
    var modal = document.getElementById('dynamicModal');
    if (modal) modal.remove();
    document.body.style.overflow = '';
}

function createCustomerModal(customer) {
    // Eski modal varsa kaldir
    var oldModal = document.getElementById('dynamicModal');
    if (oldModal) oldModal.remove();

    var isEdit = customer !== null;
    var isDark = document.body.classList.contains('dark-mode');

    // Modal container
    var modal = document.createElement('div');
    modal.id = 'dynamicModal';
    modal.className = 'dynamic-modal-overlay';
    modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:999999; display:flex; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(8px);';

    // Dark mode renkleri
    var bgColor = isDark ? '#1e293b' : '#ffffff';
    var textColor = isDark ? '#f1f5f9' : '#1f2937';
    var borderColor = isDark ? '#475569' : '#323232';
    var inputBg = isDark ? '#334155' : '#ffffff';
    var inputBorder = isDark ? '#475569' : '#e5e7eb';
    var labelColor = isDark ? '#94a3b8' : '#374151';
    var headerGradient = isDark ? 'linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%)' : 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)';

    var modalContent = document.createElement('div');
    modalContent.className = 'dynamic-modal-content';
    modalContent.style.cssText = 'background:' + bgColor + '; padding:0; border-radius:20px; width:100%; max-width:520px; box-shadow:0 20px 60px rgba(0,0,0,0.3), 5px 5px 0 ' + borderColor + '; border:3px solid ' + borderColor + '; position:relative; max-height:90vh; overflow:hidden; display:flex; flex-direction:column;';

    // Header with gradient
    var header = '<div class="modal-header-gradient" style="padding:1.5rem; background:' + headerGradient + '; display:flex; justify-content:space-between; align-items:center; position:relative;">' +
        '<div style="display:flex; align-items:center; gap:0.75rem; position:relative; z-index:1;">' +
        '<div style="width:42px; height:42px; background:rgba(255,255,255,0.2); border-radius:10px; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(10px);">' +
        '<i class="fas fa-' + (isEdit ? 'edit' : 'user-plus') + ' icon-bounce" style="font-size:1.25rem; color:white;"></i></div>' +
        '<h3 style="margin:0; color:white; font-size:1.3rem; font-weight:700; text-shadow:0 2px 4px rgba(0,0,0,0.2);">' + (isEdit ? 'Müşteri Düzenle' : 'Yeni Müşteri') + '</h3>' +
        '</div>' +
        '<button type="button" onclick="closeDynamicModal()" class="modal-button-hover" style="background:rgba(255,255,255,0.2); border:none; font-size:1.25rem; cursor:pointer; color:white; padding:0.5rem; border-radius:8px; width:36px; height:36px; display:flex; align-items:center; justify-content:center; position:relative; z-index:1; backdrop-filter:blur(10px); transition:all 0.2s;" onmouseover="this.style.background=\'rgba(255,255,255,0.3)\'" onmouseout="this.style.background=\'rgba(255,255,255,0.2)\'">&times;</button>' +
        '</div>';

    // Form body with better spacing
    var formBody = '<div style="padding:2rem 1.5rem; overflow-y:auto; flex:1;">' +
        '<form id="dynamicCustomerForm">' +
        (isEdit ? '<input type="hidden" name="id" value="' + customer.id + '">' : '') +

        // Ad Soyad with icon
        '<div style="margin-bottom:1.5rem;">' +
        '<label class="form-label-icon" style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-user" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Ad Soyad <span style="color:#ef4444;">*</span></span></label>' +
        '<input type="text" name="name" class="form-control modal-input-focus" value="' + (isEdit ? escapeHtml(customer.name || '') : '') + '" required style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + '; transition:all 0.3s; box-shadow:0 2px 4px rgba(0,0,0,0.05);" placeholder="Müşteri adını girin">' +
        '</div>' +

        // Telefon with better styling
        '<div style="margin-bottom:1.5rem;">' +
        '<label class="form-label-icon" style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #10b981 0%, #34d399 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-phone" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Telefon <span style="color:#ef4444;">*</span></span></label>' +
        '<input type="tel" name="phone" id="dynPhone" class="form-control modal-input-focus" value="' + (isEdit ? formatPhoneForInput(customer.phone || '') : '') + '" required placeholder="5XX XXX XX XX" maxlength="15" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + '; transition:all 0.3s; box-shadow:0 2px 4px rgba(0,0,0,0.05);">' +
        '<small style="display:flex; align-items:center; gap:0.375rem; margin-top:0.5rem; color:' + labelColor + '; font-size:0.75rem; padding:0.5rem 0.75rem; background:' + (isDark ? '#1e293b' : '#f8fafc') + '; border-radius:8px;"><i class="fas fa-info-circle" style="color:#3b82f6;"></i> <span>Örnek: 512 345 67 89</span></small>' +
        '</div>' +

        // Doğum Tarihi + Cinsiyet (yan yana)
        '<div style="display:flex; gap:1rem; margin-bottom:1.5rem;">' +
        '<div style="flex:1;">' +
        '<label class="form-label-icon" style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #ec4899 0%, #f472b6 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-birthday-cake" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Doğum Tarihi</span></label>' +
        '<input type="date" name="birth_date" class="form-control modal-input-focus" value="' + (isEdit ? (customer.birth_date || '') : '') + '" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + '; transition:all 0.3s; box-shadow:0 2px 4px rgba(0,0,0,0.05);">' +
        '</div>' +
        '<div style="flex:1;">' +
        '<label class="form-label-icon" style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-venus-mars" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Cinsiyet</span></label>' +
        '<select name="gender" class="form-control modal-input-focus" style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + '; transition:all 0.3s; box-shadow:0 2px 4px rgba(0,0,0,0.05);">' +
        '<option value="">Belirtilmemiş</option>' +
        '<option value="male"' + (isEdit && customer.gender === 'male' ? ' selected' : '') + '>Erkek</option>' +
        '<option value="female"' + (isEdit && customer.gender === 'female' ? ' selected' : '') + '>Kadın</option>' +
        '<option value="other"' + (isEdit && customer.gender === 'other' ? ' selected' : '') + '>Diğer</option>' +
        '</select>' +
        '</div>' +
        '</div>' +

        // Notlar
        '<div style="margin-bottom:0;">' +
        '<label class="form-label-icon" style="display:flex; align-items:center; margin-bottom:0.625rem; font-weight:600; color:' + labelColor + '; font-size:0.875rem;">' +
        '<div style="width:28px; height:28px; background:linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%); border-radius:8px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-sticky-note" style="font-size:0.75rem; color:white;"></i></div>' +
        '<span>Notlar</span></label>' +
        '<textarea name="notes" class="form-control modal-input-focus" rows="3" placeholder="Müşteri hakkında notlar..." style="width:100%; padding:0.875rem 1rem; border:2px solid ' + inputBorder + '; border-radius:12px; font-size:1rem; background:' + inputBg + '; color:' + textColor + '; resize:vertical; min-height:100px; transition:all 0.3s; box-shadow:0 2px 4px rgba(0,0,0,0.05);">' + (isEdit ? escapeHtml(customer.notes || '') : '') + '</textarea>' +
        '</div>' +

        '</form></div>';

    // Footer with modern buttons
    var footer = '<div class="modal-footer-shadow" style="padding:1.25rem 1.5rem; background:' + (isDark ? '#1e293b' : '#f8fafc') + '; display:flex; gap:0.75rem; justify-content:flex-end; flex-wrap:wrap;">' +
        '<button type="button" onclick="closeDynamicModal()" class="modal-button-hover" style="padding:0.75rem 1.5rem; border:2px solid ' + inputBorder + '; border-radius:12px; background:' + bgColor + '; color:' + textColor + '; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.5rem; transition:all 0.2s; box-shadow:0 2px 4px rgba(0,0,0,0.05);" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 4px 8px rgba(0,0,0,0.1)\'" onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 2px 4px rgba(0,0,0,0.05)\'"><i class="fas fa-times"></i> <span>İptal</span></button>' +
        '<button type="submit" form="dynamicCustomerForm" class="modal-button-hover" style="padding:0.75rem 1.5rem; border:none; border-radius:12px; background:linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color:white; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.5rem; transition:all 0.2s; box-shadow:0 4px 8px rgba(59,130,246,0.3);" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 8px 16px rgba(59,130,246,0.4)\'" onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 4px 8px rgba(59,130,246,0.3)\'"><i class="fas fa-save"></i> <span>Kaydet</span></button>' +
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
            // 0 ile başlıyorsa kaldır
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
    document.getElementById('dynamicCustomerForm').onsubmit = async function (e) {
        e.preventDefault();
        
        var form = this;
        var submitBtn = document.querySelector('#dynamicModal button[type="submit"]');
        var originalText = submitBtn ? submitBtn.innerHTML : '';
        var isEditMode = form.querySelector('input[name="id"]') !== null;
        var customerId = isEditMode ? form.querySelector('input[name="id"]').value : null;
        
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Kaydediliyor...</span>';
            submitBtn.disabled = true;
        }

        var formData = new FormData(form);
        var data = {};
        formData.forEach((value, key) => data[key] = value);

        logDebug('Submitting form, isEdit: ' + isEditMode + ', data: ' + JSON.stringify(data));

        try {
            var result;
            if (isEditMode) {
                result = await updateCustomer(data);
            } else {
                result = await createCustomer(data);
            }

            logDebug('API Result: ' + JSON.stringify(result));

            if (result.success) {
                showNotification(result.message || 'Müşteri kaydedildi', 'success');
                closeDynamicModal();
                
                // Sayfa yenilemeden güncelle
                if (isEditMode && result.customer) {
                    updateCustomerInList(customerId, result.customer);
                } else if (result.customer) {
                    // Yeni müşteri eklendi - listeye ekle
                    addCustomerToList(result.customer);
                }
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

    // Modal disina tikla kapat
    modal.onclick = function (e) {
        if (e.target === modal) closeDynamicModal();
    };

    // ESC tusu ile kapat
    document.onkeydown = function (e) {
        if (e.key === 'Escape') closeDynamicModal();
    };

    logDebug('Customer modal created');
}

function editCustomer(id) {
    logDebug('editCustomer called with id: ' + id);
    var customer = null;
    for (var i = 0; i < allCustomers.length; i++) {
        if (allCustomers[i].id == id) {
            customer = allCustomers[i];
            break;
        }
    }
    logDebug('Customer found: ' + (customer ? customer.name : 'NOT FOUND'));
    if (!customer) return;

    // Kayıtlı müşterileri düzenlemeye izin verme
    if (customer.is_guest == 0) {
        showNotification('Bu müşteri platforma kayıtlı olduğu için bilgileri yalnızca kendisi tarafından değiştirilebilir.', 'error');
        return;
    }

    createCustomerModal(customer);
}

// ==========================================
// Müşteri Geçmişi Modal (filtre + ödeme renkleri)
// ==========================================
var _customerHistoryState = null;

function historyPaymentStyle(apt) {
    var method = apt.payment_method || '';
    var paidAmount = apt.paid_amount != null ? parseFloat(apt.paid_amount) : null;
    var total = parseFloat(apt.total_price || 0);
    var isPartial = paidAmount !== null && paidAmount > 0 && paidAmount < total;
    var isUnpaid = method === 'not_paid' || apt.payment_status === 'pending' || isPartial;

    if (isPartial) {
        return { key: 'partial', label: 'Kısmi', bg: '#fee2e2', color: '#b91c1c', border: '#fca5a5' };
    }
    if (method === 'not_paid' || (apt.status === 'completed' && apt.payment_status === 'pending' && !method)) {
        return { key: 'not_paid', label: 'Alınmadı', bg: '#dc2626', color: '#ffffff', border: '#991b1b' };
    }
    if (method === 'cash') {
        return { key: 'cash', label: 'Nakit', bg: '#fef08a', color: '#713f12', border: '#facc15' };
    }
    if (method === 'bank_transfer') {
        return { key: 'bank_transfer', label: 'Havale/EFT', bg: '#fbcfe8', color: '#831843', border: '#f9a8d4' };
    }
    if (method === 'credit_card') {
        return { key: 'credit_card', label: 'Kredi Kartı', bg: '#dbeafe', color: '#1e3a8a', border: '#93c5fd' };
    }
    if (method === 'mixed') {
        return { key: 'mixed', label: 'Karışık', bg: '#e0e7ff', color: '#3730a3', border: '#a5b4fc' };
    }
    return { key: method || 'other', label: apt.payment_method_label || '—', bg: '#f1f5f9', color: '#475569', border: '#cbd5e1' };
}

function historyPaymentFilterKey(apt) {
    var style = historyPaymentStyle(apt);
    if (style.key === 'partial' || style.key === 'not_paid') return 'not_paid';
    return style.key;
}

async function viewCustomerHistory(id) {
    logDebug('viewCustomerHistory called with id: ' + id);
    var customer = null;
    for (var i = 0; i < allCustomers.length; i++) {
        if (allCustomers[i].id == id) {
            customer = allCustomers[i];
            break;
        }
    }
    if (!customer) {
        showNotification('Müşteri bulunamadı', 'error');
        return;
    }

    var oldModal = document.getElementById('dynamicModal');
    if (oldModal) oldModal.remove();

    var isDark = document.body.classList.contains('dark-mode');
    var bgColor = isDark ? '#1e293b' : '#ffffff';
    var textColor = isDark ? '#f1f5f9' : '#1f2937';
    var borderColor = isDark ? '#475569' : '#323232';
    var cardBg = isDark ? '#334155' : '#f8fafc';
    var labelColor = isDark ? '#94a3b8' : '#6b7280';
    var headerGradient = isDark ? 'linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%)' : 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)';
    var rowBorder = isDark ? '#475569' : '#e5e7eb';
    var inputBg = isDark ? '#1e293b' : '#ffffff';

    var modal = document.createElement('div');
    modal.id = 'dynamicModal';
    modal.className = 'dynamic-modal-overlay';
    modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:999999; display:flex; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(8px);';

    modal.innerHTML = '<div class="dynamic-modal-content" style="background:' + bgColor + '; border-radius:16px; width:100%; max-width:820px; border:2px solid ' + borderColor + '; box-shadow:0 12px 40px rgba(0,0,0,0.25); overflow:hidden; max-height:92vh; display:flex; flex-direction:column;">' +
        '<div style="padding:0.875rem 1rem; display:flex; justify-content:space-between; align-items:center; background:' + headerGradient + '; flex-shrink:0;">' +
        '<div style="display:flex; align-items:center; gap:0.5rem;"><i class="fas fa-history" style="font-size:1rem; color:white;"></i>' +
        '<h3 style="margin:0; color:white; font-size:1.05rem; font-weight:700;">Müşteri Geçmişi</h3></div>' +
        '<button type="button" onclick="closeDynamicModal()" style="background:rgba(255,255,255,0.2); border:none; font-size:1.1rem; cursor:pointer; color:white; padding:0; border-radius:6px; width:30px; height:30px; display:flex; align-items:center; justify-content:center;">&times;</button></div>' +
        '<div id="customerHistoryBody" style="padding:1rem; overflow:auto; flex:1;"><div style="text-align:center; padding:2rem; color:' + labelColor + ';"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i><p style="margin-top:0.75rem;">Geçmiş yükleniyor...</p></div></div>' +
        '<div style="padding:0.6rem 0.75rem; border-top:1px solid ' + rowBorder + '; display:flex; justify-content:flex-end; flex-shrink:0;">' +
        '<button type="button" onclick="closeDynamicModal()" style="padding:0.45rem 1rem; border:1px solid ' + rowBorder + '; border-radius:8px; background:transparent; color:' + textColor + '; font-weight:600; cursor:pointer; font-size:0.8rem;"><i class="fas fa-times"></i> Kapat</button></div></div>';

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';
    modal.onclick = function (e) { if (e.target === modal) closeDynamicModal(); };
    document.onkeydown = function (e) { if (e.key === 'Escape') closeDynamicModal(); };

    try {
        var result = await getCustomerHistory(id, { limit: 100 });
        var body = document.getElementById('customerHistoryBody');
        if (!body) return;

        if (!result.success) {
            body.innerHTML = '<div style="text-align:center; padding:2rem; color:#ef4444;">' + escapeHtml(result.message || 'Geçmiş yüklenemedi') + '</div>';
            return;
        }

        _customerHistoryState = {
            customer: result.customer || customer,
            stats: result.stats || {},
            appointments: result.appointments || [],
            theme: { bgColor: bgColor, textColor: textColor, cardBg: cardBg, labelColor: labelColor, rowBorder: rowBorder, inputBg: inputBg, isDark: isDark },
            filters: { search: '', payment: '', sort: 'date_desc' }
        };

        renderCustomerHistoryContent();
    } catch (err) {
        var bodyErr = document.getElementById('customerHistoryBody');
        if (bodyErr) {
            bodyErr.innerHTML = '<div style="text-align:center; padding:2rem; color:#ef4444;">Geçmiş yüklenirken hata: ' + escapeHtml(err.message || 'bilinmeyen') + '</div>';
        }
    }
}

function renderCustomerHistoryContent() {
    var state = _customerHistoryState;
    if (!state) return;
    var body = document.getElementById('customerHistoryBody');
    if (!body) return;

    var t = state.theme;
    var stats = state.stats;
    var cust = state.customer;
    var filters = state.filters;

    var filtered = (state.appointments || []).slice();
    if (filters.search) {
        var q = filters.search.toLocaleLowerCase('tr-TR');
        filtered = filtered.filter(function (apt) {
            var hay = [
                apt.service_name || '',
                apt.employee_name || '',
                apt.notes || '',
                apt.payment_method_label || '',
                apt.status_label || '',
                (apt.services || []).map(function (s) { return s.name; }).join(' ')
            ].join(' ').toLocaleLowerCase('tr-TR');
            return hay.indexOf(q) !== -1;
        });
    }
    if (filters.payment) {
        filtered = filtered.filter(function (apt) {
            return historyPaymentFilterKey(apt) === filters.payment;
        });
    }
    if (filters.sort === 'spend_desc') {
        filtered.sort(function (a, b) { return parseFloat(b.total_price || 0) - parseFloat(a.total_price || 0); });
    } else if (filters.sort === 'spend_asc') {
        filtered.sort(function (a, b) { return parseFloat(a.total_price || 0) - parseFloat(b.total_price || 0); });
    } else if (filters.sort === 'date_asc') {
        filtered.sort(function (a, b) {
            var da = (a.appointment_date || '') + ' ' + (a.appointment_time || '');
            var db = (b.appointment_date || '') + ' ' + (b.appointment_time || '');
            return da.localeCompare(db);
        });
    } else {
        filtered.sort(function (a, b) {
            var da = (a.appointment_date || '') + ' ' + (a.appointment_time || '');
            var db = (b.appointment_date || '') + ' ' + (b.appointment_time || '');
            return db.localeCompare(da);
        });
    }

    var statusColor = {
        completed: '#10b981', confirmed: '#3b82f6', pending: '#f59e0b',
        cancelled: '#ef4444', 'no-show': '#94a3b8'
    };

    if (!document.getElementById('customerHistoryList')) {
        var shell = '';
        shell += '<div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">' +
            '<div style="width:48px; height:48px; min-width:48px; border-radius:50%; background:linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%); display:flex; align-items:center; justify-content:center; font-size:1.2rem; color:white; font-weight:700;">' +
            (cust.name ? cust.name.charAt(0).toUpperCase() : '?') + '</div>' +
            '<div style="flex:1; min-width:0;"><h4 style="margin:0 0 0.2rem 0; font-size:1.1rem; font-weight:700; color:' + t.textColor + ';">' + escapeHtml(cust.name || '') + '</h4>' +
            '<div style="display:flex; flex-wrap:wrap; gap:0.75rem; color:' + t.labelColor + '; font-size:0.8rem;">' +
            '<span><i class="fas fa-phone" style="color:#10b981;"></i> ' + formatPhoneDisplay(cust.phone || '') + '</span>' +
            (cust.email ? '<span><i class="fas fa-envelope" style="color:#f59e0b;"></i> ' + escapeHtml(cust.email) + '</span>' : '') +
            '</div></div></div>';

        shell += '<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:0.5rem; margin-bottom:1rem;">' +
            historyStatCard(t.cardBg, t.rowBorder, t.labelColor, '#3b82f6', 'fa-calendar-check', stats.total_appointments || state.appointments.length || 0, 'Randevu') +
            historyStatCard(t.cardBg, t.rowBorder, t.labelColor, '#10b981', 'fa-lira-sign', formatCurrency(stats.total_spent || 0).replace('₺', '').trim(), 'Toplam Hesap') +
            historyStatCard(t.cardBg, t.rowBorder, t.labelColor, '#059669', 'fa-check-circle', formatCurrency(stats.total_paid || 0).replace('₺', '').trim(), 'Ödenen') +
            historyStatCard(t.cardBg, t.rowBorder, t.labelColor, '#dc2626', 'fa-exclamation-circle', formatCurrency(stats.total_unpaid || 0).replace('₺', '').trim(), 'Alınmadı') +
            '</div>';

        shell += '<div style="display:grid; grid-template-columns:1.4fr 1fr 1fr; gap:0.5rem; margin-bottom:0.85rem;">' +
            '<input id="histFilterSearch" type="text" placeholder="Hizmet, personel, not ara..." value="' + escapeHtml(filters.search) + '" ' +
            'style="width:100%; padding:0.55rem 0.75rem; border:1px solid ' + t.rowBorder + '; border-radius:10px; background:' + t.inputBg + '; color:' + t.textColor + '; font-size:0.85rem;" ' +
            'oninput="onCustomerHistoryFilterChange()">' +
            '<select id="histFilterPayment" onchange="onCustomerHistoryFilterChange()" style="width:100%; padding:0.55rem 0.75rem; border:1px solid ' + t.rowBorder + '; border-radius:10px; background:' + t.inputBg + '; color:' + t.textColor + '; font-size:0.85rem;">' +
            '<option value=""' + (filters.payment === '' ? ' selected' : '') + '>Tüm ödemeler</option>' +
            '<option value="cash"' + (filters.payment === 'cash' ? ' selected' : '') + '>Nakit</option>' +
            '<option value="bank_transfer"' + (filters.payment === 'bank_transfer' ? ' selected' : '') + '>Havale/EFT</option>' +
            '<option value="credit_card"' + (filters.payment === 'credit_card' ? ' selected' : '') + '>Kredi Kartı</option>' +
            '<option value="not_paid"' + (filters.payment === 'not_paid' ? ' selected' : '') + '>Alınmadı / Kısmi</option>' +
            '<option value="mixed"' + (filters.payment === 'mixed' ? ' selected' : '') + '>Karışık</option>' +
            '</select>' +
            '<select id="histFilterSort" onchange="onCustomerHistoryFilterChange()" style="width:100%; padding:0.55rem 0.75rem; border:1px solid ' + t.rowBorder + '; border-radius:10px; background:' + t.inputBg + '; color:' + t.textColor + '; font-size:0.85rem;">' +
            '<option value="date_desc"' + (filters.sort === 'date_desc' ? ' selected' : '') + '>Tarih (Yeni → Eski)</option>' +
            '<option value="date_asc"' + (filters.sort === 'date_asc' ? ' selected' : '') + '>Tarih (Eski → Yeni)</option>' +
            '<option value="spend_desc"' + (filters.sort === 'spend_desc' ? ' selected' : '') + '>Harcama (Yüksek → Düşük)</option>' +
            '<option value="spend_asc"' + (filters.sort === 'spend_asc' ? ' selected' : '') + '>Harcama (Düşük → Yüksek)</option>' +
            '</select></div>';

        shell += '<div id="customerHistoryMeta"></div><div id="customerHistoryList"></div>';
        body.innerHTML = shell;
    }

    var meta = document.getElementById('customerHistoryMeta');
    var list = document.getElementById('customerHistoryList');
    if (!list) return;

    if (meta) {
        meta.innerHTML = '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.65rem; color:' + t.labelColor + '; font-size:0.78rem;">' +
            '<span><strong style="color:' + t.textColor + ';">' + filtered.length + '</strong> / ' + state.appointments.length + ' kayıt</span>' +
            '<span style="display:flex; gap:0.35rem; flex-wrap:wrap;">' +
            '<span style="background:#fef08a;color:#713f12;padding:2px 8px;border-radius:999px;font-weight:700;">Nakit</span>' +
            '<span style="background:#fbcfe8;color:#831843;padding:2px 8px;border-radius:999px;font-weight:700;">Havale</span>' +
            '<span style="background:#dc2626;color:#fff;padding:2px 8px;border-radius:999px;font-weight:700;">Alınmadı</span>' +
            '</span></div>';
    }

    var listHtml = '';
    if (!filtered.length) {
        listHtml = '<div style="text-align:center; padding:2rem; color:' + t.labelColor + ';"><i class="fas fa-inbox" style="font-size:2rem; margin-bottom:0.5rem; display:block;"></i>Bu filtrelere uygun kayıt yok</div>';
    } else {
        listHtml = '<div style="display:flex; flex-direction:column; gap:0.65rem;">';
        filtered.forEach(function (apt) {
            var sc = statusColor[apt.status] || '#64748b';
            var pay = historyPaymentStyle(apt);
            var servicesHtml = '';
            if (apt.services && apt.services.length) {
                servicesHtml = apt.services.map(function (s) {
                    var qty = s.quantity > 1 ? (' x' + s.quantity) : '';
                    return '<div style="display:flex; justify-content:space-between; gap:0.75rem; font-size:0.82rem; color:' + t.textColor + ';">' +
                        '<span><i class="fas fa-cut" style="color:#8b5cf6;font-size:0.7rem;margin-right:4px;"></i>' + escapeHtml(s.name) + qty + '</span>' +
                        '<span style="font-weight:700; white-space:nowrap;">' + formatCurrency(s.price || 0) + '</span></div>';
                }).join('');
            } else if (apt.service_name) {
                servicesHtml = '<div style="font-size:0.82rem; color:' + t.textColor + ';">' + escapeHtml(apt.service_name) + '</div>';
            }

            listHtml += '<div style="background:' + t.cardBg + '; border:1px solid ' + t.rowBorder + '; border-left:4px solid ' + pay.border + '; border-radius:12px; padding:0.9rem;">' +
                '<div style="display:flex; justify-content:space-between; gap:0.75rem; align-items:flex-start; margin-bottom:0.5rem;">' +
                '<div><div style="font-weight:700; color:' + t.textColor + '; font-size:0.98rem;">' +
                escapeHtml(apt.appointment_date_formatted || formatDate(apt.appointment_date)) +
                (apt.appointment_time_formatted ? (' · ' + escapeHtml(apt.appointment_time_formatted)) : '') +
                '</div>' +
                (apt.employee_name ? ('<div style="font-size:0.75rem; color:' + t.labelColor + '; margin-top:0.2rem;"><i class="fas fa-user-tie"></i> ' + escapeHtml(apt.employee_name) + '</div>') : '') +
                '</div>' +
                '<div style="display:flex; flex-direction:column; gap:0.35rem; align-items:flex-end;">' +
                '<span style="background:' + sc + '22; color:' + sc + '; font-size:0.7rem; font-weight:700; padding:0.25rem 0.55rem; border-radius:999px; white-space:nowrap;">' +
                escapeHtml(apt.status_label || apt.status) + '</span>' +
                '<span style="background:' + pay.bg + '; color:' + pay.color + '; font-size:0.7rem; font-weight:800; padding:0.25rem 0.55rem; border-radius:6px; white-space:nowrap; border:1px solid ' + pay.border + ';">' +
                escapeHtml(pay.label) + '</span>' +
                '</div></div>' +
                (servicesHtml ? ('<div style="margin:0.5rem 0; padding:0.5rem 0; border-top:1px dashed ' + t.rowBorder + '; border-bottom:1px dashed ' + t.rowBorder + ';">' + servicesHtml + '</div>') : '') +
                '<div style="display:flex; flex-wrap:wrap; gap:0.75rem; justify-content:space-between; align-items:center; margin-top:0.45rem;">' +
                '<div style="font-size:0.78rem; color:' + t.labelColor + ';">' +
                (apt.payment_status ? ((apt.payment_status === 'paid' ? 'Ödendi' : 'Bekliyor')) : '') +
                (apt.paid_amount != null ? (' · Alınan: ' + formatCurrency(apt.paid_amount)) : '') +
                (apt.total_duration ? (' · ' + apt.total_duration + ' dk') : '') +
                '</div>' +
                '<div style="font-weight:900; color:' + (pay.key === 'not_paid' || pay.key === 'partial' ? '#dc2626' : '#059669') + '; font-size:1.05rem;">' +
                (apt.total_price_formatted || formatCurrency(apt.total_price || 0)) + '</div>' +
                '</div>' +
                (apt.notes ? ('<div style="margin-top:0.55rem; font-size:0.78rem; color:' + t.labelColor + '; background:' + (t.isDark ? '#1e293b' : '#fff') + '; border:1px solid ' + t.rowBorder + '; border-radius:8px; padding:0.45rem 0.6rem;"><i class="fas fa-sticky-note"></i> ' + escapeHtml(apt.notes) + '</div>') : '') +
                '</div>';
        });
        listHtml += '</div>';
    }
    list.innerHTML = listHtml;
}

function onCustomerHistoryFilterChange() {
    if (!_customerHistoryState) return;
    var searchEl = document.getElementById('histFilterSearch');
    var payEl = document.getElementById('histFilterPayment');
    var sortEl = document.getElementById('histFilterSort');
    _customerHistoryState.filters.search = searchEl ? searchEl.value.trim() : '';
    _customerHistoryState.filters.payment = payEl ? payEl.value : '';
    _customerHistoryState.filters.sort = sortEl ? sortEl.value : 'date_desc';
    renderCustomerHistoryContent();
}

function historyStatCard(cardBg, rowBorder, labelColor, color, icon, value, label) {
    return '<div style="background:' + cardBg + '; padding:0.65rem; border-radius:10px; display:flex; align-items:center; gap:0.6rem; border:1px solid ' + rowBorder + ';">' +
        '<div style="width:36px; height:36px; min-width:36px; border-radius:8px; background:' + color + '22; display:flex; align-items:center; justify-content:center;"><i class="fas ' + icon + '" style="font-size:0.85rem; color:' + color + ';"></i></div>' +
        '<div><p style="margin:0; font-size:1rem; font-weight:700; color:' + color + '; line-height:1.2;">' + value + '</p>' +
        '<p style="margin:0; font-size:0.65rem; text-transform:uppercase; letter-spacing:0.3px; color:' + labelColor + '; font-weight:600;">' + label + '</p></div></div>';
}

window.onCustomerHistoryFilterChange = onCustomerHistoryFilterChange;

function closeModal() {
    closeDynamicModal();
}

function closeHistoryModal() {
    closeDynamicModal();
}

function closeDeleteModal() {
    logDebug('closeDeleteModal called');
    var deleteModal = document.getElementById('dynamicDeleteModal');
    if (deleteModal) deleteModal.remove();
    document.body.style.overflow = '';
}

// ==========================================
// Silme Fonksiyonları
// ==========================================
function deleteCustomer(id, name, futureAppointments) {
    logDebug('deleteCustomer called: ' + id + ', ' + name + ', appointments: ' + futureAppointments);

    if (futureAppointments > 0) {
        showWarningModal(
            'Müşteriyi Sil',
            '<strong>' + escapeHtml(name) + '</strong> adlı müşteri silinemez!<br><br>' +
            '<div style="background: #fffbeb; padding: 1rem; border-radius: 8px; border: 1px solid #fcd34d; text-align: left; color: #92400e;">' +
            '<i class="fas fa-exclamation-triangle" style="color: #f59e0b;"></i> Bu müşterinin <strong>' + futureAppointments + '</strong> adet GELECEK randevusu bulunmaktadır. ' +
            '<br><small>Sadece geçmiş randevusu olanlar silinebilir.</small></div>',
            function () { closeDynamicModal(); },
            'Tamam'
        );
    } else {
        showDeleteConfirmation('<strong>' + escapeHtml(name) + '</strong> müşterisi listenizden çıkarılsın mı?', async function () {
            try {
                var result = await deleteCustomerApi(id);
                if (result.success) {
                    showNotification(result.message || 'Müşteri silindi', 'success');
                    // Sayfa yenilemeden kartı kaldır
                    removeCustomerFromList(id);
                } else {
                    showNotification(result.message || 'Silme işlemi başarısız', 'error');
                }
            } catch (error) {
                showNotification('Bir hata oluştu: ' + error.message, 'error');
            }
        });
    }
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
    modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:999999; display:flex; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(8px);';

    modal.innerHTML = '<div class="dynamic-modal-content" style="background:' + bgColor + '; padding:2.5rem 2rem; border-radius:20px; width:100%; max-width:440px; border:3px solid ' + borderColor + '; box-shadow:0 20px 60px rgba(0,0,0,0.3), 5px 5px 0 ' + borderColor + '; text-align:center;">' +
        '<div style="width:80px; height:80px; border-radius:50%; background:linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(220, 38, 38, 0.15) 100%); display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; border:3px solid rgba(239, 68, 68, 0.2);"><i class="fas fa-trash-alt icon-bounce" style="font-size:2.25rem; color:#ef4444;"></i></div>' +
        '<h3 style="margin:0 0 1rem 0; font-size:1.4rem; font-weight:700; color:' + textColor + '; line-height:1.3;">Silme Onayı</h3>' +
        '<p style="margin:0 0 2rem 0; color:' + labelColor + '; font-size:1rem; line-height:1.6;">' + message + '</p>' +
        '<div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">' +
        '<button type="button" id="cancelDeleteBtn" class="modal-button-hover" style="padding:0.875rem 1.75rem; border:2px solid ' + (isDark ? '#475569' : '#e5e7eb') + '; border-radius:12px; background:' + bgColor + '; color:' + textColor + '; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.625rem; transition:all 0.2s; box-shadow:0 2px 4px rgba(0,0,0,0.05); font-size:0.95rem;"><i class="fas fa-times"></i> <span>İptal</span></button>' +
        '<button type="button" id="confirmDeleteBtn" class="modal-button-hover" style="padding:0.875rem 1.75rem; border:none; border-radius:12px; background:linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color:white; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.625rem; transition:all 0.2s; box-shadow:0 4px 8px rgba(239,68,68,0.3); font-size:0.95rem;"><i class="fas fa-trash"></i> <span>Evet, Sil</span></button>' +
        '</div></div>';

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    // Event listener'ları ekle
    var cancelBtn = document.getElementById('cancelDeleteBtn');
    var confirmBtn = document.getElementById('confirmDeleteBtn');

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            logDebug('Cancel button clicked');
            closeDeleteModal();
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            logDebug('Confirm button clicked - executing callback');
            closeDeleteModal();
            if (onConfirm && typeof onConfirm === 'function') {
                onConfirm();
            }
        });
    }

    modal.addEventListener('click', function (e) {
        if (e.target === modal) {
            logDebug('Modal overlay clicked');
            closeDeleteModal();
        }
    });
}

// ==========================================
// Toplu İşlemler
// ==========================================
function executeBulkAction() {
    logDebug('executeBulkAction called');
    var boxes = document.querySelectorAll('.customer-checkbox:checked:not(#selectAll)');
    if (boxes.length === 0) {
        showNotification('Lütfen en az bir müşteri seçin', 'error');
        return;
    }
    var ids = [];
    for (var i = 0; i < boxes.length; i++) {
        ids.push(parseInt(boxes[i].value));
    }

    showDeleteConfirmation('<strong>' + ids.length + '</strong> müşteri listenizden çıkarılsın mı?', async function () {
        try {
            var result = await bulkDeleteCustomers(ids);
            if (result.success) {
                showNotification(result.message || ids.length + ' müşteri silindi', 'success');
                // Silinen müşterileri listeden kaldır
                if (result.deleted_ids && result.deleted_ids.length > 0) {
                    result.deleted_ids.forEach(function(id) {
                        removeCustomerFromList(id);
                    });
                } else {
                    // deleted_ids yoksa tüm seçilenleri kaldır
                    ids.forEach(function(id) {
                        removeCustomerFromList(id);
                    });
                }
                // Checkbox'ları temizle
                document.getElementById('selectAll').checked = false;
                toggleHeaderButtons(false);
            } else {
                showNotification(result.message || 'Toplu silme başarısız', 'error');
            }
        } catch (error) {
            showNotification('Bir hata oluştu: ' + error.message, 'error');
        }
    });
}

function toggleSelectAll() {
    logDebug('toggleSelectAll called');
    var isChecked = document.getElementById('selectAll').checked;
    var boxes = document.querySelectorAll('.customer-checkbox:not(#selectAll)');
    for (var i = 0; i < boxes.length; i++) {
        boxes[i].checked = isChecked;
        // Add/remove row-selected class
        var card = boxes[i].closest('.customer-card');
        if (card) {
            if (isChecked) {
                card.classList.add('row-selected');
            } else {
                card.classList.remove('row-selected');
            }
        }
    }
    updateBulkActions();
    toggleHeaderButtons(isChecked);
}

function toggleHeaderButtons(hasSelection) {
    var filterBtn = document.querySelector('.bulk-select-header .btn-secondary');
    var addBtn = document.querySelector('.bulk-select-header .btn-success');
    var deleteBtn = document.getElementById('bulkDeleteBtn');
    var campaignBtn = document.getElementById('bulkCampaignBtn');

    if (hasSelection) {
        if (filterBtn) filterBtn.style.display = 'none';
        if (addBtn) addBtn.style.display = 'none';
        if (deleteBtn) deleteBtn.style.display = 'inline-flex';
        if (campaignBtn) campaignBtn.style.display = 'inline-flex';
    } else {
        if (filterBtn) filterBtn.style.display = 'inline-flex';
        if (addBtn) addBtn.style.display = 'inline-flex';
        if (deleteBtn) deleteBtn.style.display = 'none';
        if (campaignBtn) campaignBtn.style.display = 'none';
    }
}

function updateBulkActions(cb) {
    // Add/remove row-selected class
    if (cb && cb.classList && cb.classList.contains('customer-checkbox') && !cb.id) {
        var card = cb.closest('.customer-card');
        if (card) {
            if (cb.checked) {
                card.classList.add('row-selected');
            } else {
                card.classList.remove('row-selected');
            }
        }
    }

    var count = document.querySelectorAll('.customer-checkbox:checked:not(#selectAll)').length;
    var selectAllCheckbox = document.getElementById('selectAll');

    if (count > 0) {
        toggleHeaderButtons(true);
    } else {
        if (selectAllCheckbox) selectAllCheckbox.checked = false;
        toggleHeaderButtons(false);
    }
}

function openFilterSidebar() {
    logDebug('openFilterSidebar called');
    var sidebar = document.getElementById('filterSidebar');
    var overlay = document.getElementById('filterOverlay');
    if (sidebar) sidebar.classList.add('active');
    if (overlay) overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeFilterSidebar() {
    logDebug('closeFilterSidebar called');
    var sidebar = document.getElementById('filterSidebar');
    var overlay = document.getElementById('filterOverlay');
    if (sidebar) sidebar.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
}

function resetFilters() {
    logDebug('resetFilters called');
    var searchInput = document.getElementById('search_input');
    var sortSelect = document.getElementById('sort_select');
    if (searchInput) searchInput.value = '';
    if (sortSelect) sortSelect.value = 'name_asc';
    applyFilters();
    closeFilterSidebar();
}

// ==========================================
// Uyarı Modal
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
    modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:999999; display:flex; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(8px);';

    modal.innerHTML = '<div class="dynamic-modal-content" style="background:' + bgColor + '; padding:2.5rem 2rem; border-radius:20px; width:100%; max-width:500px; border:3px solid ' + borderColor + '; box-shadow:0 20px 60px rgba(0,0,0,0.3), 5px 5px 0 ' + borderColor + '; text-align:center;">' +
        '<div style="width:80px; height:80px; border-radius:50%; background:linear-gradient(135deg, rgba(245, 158, 11, 0.15) 0%, rgba(217, 119, 6, 0.15) 100%); display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; border:3px solid rgba(245, 158, 11, 0.2);"><i class="fas fa-exclamation-triangle icon-bounce" style="font-size:2.25rem; color:#f59e0b;"></i></div>' +
        '<h3 style="margin:0 0 1rem 0; font-size:1.4rem; font-weight:700; color:' + textColor + '; line-height:1.3;">' + title + '</h3>' +
        '<div style="margin:0 0 2rem 0; color:' + textColor + '; font-size:0.95rem; line-height:1.6;">' + message + '</div>' +
        '<div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">' +
        '<button type="button" id="closeWarningBtn" class="modal-button-hover" style="padding:0.875rem 1.75rem; border:2px solid ' + (isDark ? '#475569' : '#e5e7eb') + '; border-radius:12px; background:' + bgColor + '; color:' + textColor + '; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.625rem; transition:all 0.2s; box-shadow:0 2px 4px rgba(0,0,0,0.05); font-size:0.95rem;"><i class="fas fa-times"></i> <span>Kapat</span></button>' +
        '<button type="button" id="actionWarningBtn" class="modal-button-hover" style="padding:0.875rem 1.75rem; border:none; border-radius:12px; background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color:white; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:0.625rem; transition:all 0.2s; box-shadow:0 4px 8px rgba(245,158,11,0.3); font-size:0.95rem;"><i class="fas fa-edit"></i> <span>' + actionLabel + '</span></button>' +
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
// Legacy submitForm (backward compatibility)
// ==========================================
async function submitForm(formData) {
    logDebug('submitForm called (legacy)');
    closeDynamicModal();
    closeDeleteModal();
    
    var data = {};
    formData.forEach((value, key) => {
        if (key.endsWith('[]')) {
            var k = key.slice(0, -2);
            if (!data[k]) data[k] = [];
            data[k].push(value);
        } else {
            data[key] = value;
        }
    });
    
    var action = data.action;
    var result;
    
    try {
        if (action === 'add') {
            result = await createCustomer(data);
        } else if (action === 'edit') {
            data.id = data.customer_id;
            result = await updateCustomer(data);
        } else if (action === 'delete') {
            result = await deleteCustomerApi(data.customer_id);
        } else if (action === 'bulk_delete') {
            result = await bulkDeleteCustomers(data.customer_ids);
        } else {
            result = { success: false, message: 'Bilinmeyen işlem: ' + action };
        }
        
        if (result.success) {
            showNotification(result.message || 'İşlem başarılı', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showNotification(result.message || 'İşlem başarısız', 'error');
        }
    } catch (error) {
        logDebug('submitForm error: ' + error);
        showNotification('Bir hata oluştu. Lütfen tekrar deneyin.', 'error');
    }
}

// Modal dışına tıkla kapat
document.onclick = function (e) {
    if (e.target === customerModalEl) closeModal();
    if (e.target === historyModalEl) closeHistoryModal();
    if (e.target === deleteModalEl) closeDeleteModal();
};

logDebug('customers.js loaded (AJAX API version)');
