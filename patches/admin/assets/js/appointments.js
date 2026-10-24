// Global değişkenler
let currentAppointment = null;
let pendingFormData = null;
let currentFilters = {
    date_from: '',
    date_to: '',
    employee: '',
    status: '',
    customer: '',
    per_page: 25,
    page: 1,
    sort: 'date',
    order: 'desc',
    show_all: false
};
let isLoading = false;

// Durum etiketleri ve renkleri
const STATUS_CONFIG = {
    'pending': { label: 'Bekleyen', class: 'badge-pending' },
    'confirmed': { label: 'Onaylı', class: 'badge-confirmed' },
    'completed': { label: 'Tamamlanan', class: 'badge-completed' },
    'cancelled': { label: 'İptal', class: 'badge-cancelled' },
    'no_show': { label: 'Gelmedi', class: 'badge-no_show' }
};

// CSRF Token'ı meta tag'den al
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

// API istek helper
async function apiRequest(endpoint, method = 'GET', data = null) {
    const options = {
        method: method,
        headers: {
            'X-CSRF-Token': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    };
    
    if (data && method !== 'GET') {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data);
    }
    
    const response = await fetch(`api/appointments/${endpoint}`, options);
    return response.json();
}

// ---- AJAX FİLTRELEME SİSTEMİ ----

// Filtreleri uygula ve tabloyu yenile
async function loadAppointments(filters = {}) {
    if (isLoading) return;
    isLoading = true;
    
    // Filtreleri güncelle
    Object.assign(currentFilters, filters);
    
    // Loading göster
    showTableLoading();
    
    // Query string oluştur
    const params = new URLSearchParams();
    if (currentFilters.date_from) params.set('date_from', currentFilters.date_from);
    if (currentFilters.date_to) params.set('date_to', currentFilters.date_to);
    if (currentFilters.employee) params.set('employee', currentFilters.employee);
    if (currentFilters.status) params.set('status', currentFilters.status);
    if (currentFilters.customer) params.set('customer', currentFilters.customer);
    if (currentFilters.per_page) params.set('per_page', currentFilters.per_page);
    if (currentFilters.page) params.set('page', currentFilters.page);
    if (currentFilters.sort) params.set('sort', currentFilters.sort);
    if (currentFilters.order) params.set('order', currentFilters.order);
    if (currentFilters.show_all) params.set('show_all', '1');
    
    try {
        const result = await apiRequest(`list.php?${params.toString()}`);
        
        if (result.success) {
            renderAppointmentsTable(result.data);
            updateStats(result.stats);
            updatePagination(result.pagination);
            updateRecordCount(result.pagination.total_records);
            
            // URL temiz kalacak - güncelleme yok
        } else {
            showAlert('error', result.message || 'Randevular yüklenemedi');
        }
    } catch (error) {
        console.error('Load appointments error:', error);
        showAlert('error', 'Randevular yüklenirken bir hata oluştu');
    } finally {
        isLoading = false;
        hideTableLoading();
    }
}

// Loading göster
function showTableLoading() {
    const container = document.querySelector('.appointments-table-container') || document.querySelector('.table-wrapper');
    if (container) {
        container.style.opacity = '0.5';
        container.style.pointerEvents = 'none';
    }
}

// Loading gizle
function hideTableLoading() {
    const container = document.querySelector('.appointments-table-container') || document.querySelector('.table-wrapper');
    if (container) {
        container.style.opacity = '1';
        container.style.pointerEvents = '';
    }
}

// Tabloyu render et
function renderAppointmentsTable(appointments) {
    const tbody = document.getElementById('appointmentsTableBody');
    const appointmentsCard = document.querySelector('.appointments-card');
    const tableContainer = appointmentsCard?.querySelector('.appointments-table-container');
    const tableWrapper = tableContainer?.querySelector('.table-wrapper');
    
    if (appointments.length === 0) {
        // Boş durum göster
        if (tbody) tbody.innerHTML = '';
        if (tableWrapper) tableWrapper.style.display = 'none';
        
        // Mevcut empty state'i bul veya oluştur
        let emptyContainer = tableContainer?.querySelector('.empty-state');
        if (!emptyContainer && tableContainer) {
            emptyContainer = document.createElement('div');
            emptyContainer.className = 'empty-state';
            emptyContainer.innerHTML = `
                <i class="fas fa-calendar-times"></i>
                <p>Filtreyle eşleşen randevu bulunamadı.</p>
                <p class="empty-state-subtitle">Filtreleri temizleyerek veya yeni bir randevu oluşturarak başlayabilirsiniz.</p>
            `;
            tableContainer.appendChild(emptyContainer);
        }
        if (emptyContainer) emptyContainer.style.display = 'block';
        return;
    }
    
    // Tablo göster, boş durumu gizle
    if (tableWrapper) tableWrapper.style.display = '';
    const emptyState = tableContainer?.querySelector('.empty-state');
    if (emptyState) emptyState.style.display = 'none';
    
    if (!tbody) return;
    
    // Satırları oluştur
    tbody.innerHTML = appointments.map(apt => createAppointmentRowHTML(apt)).join('');
    
    // Mobil event listener'ları ekle
    if (window.innerWidth <= 768) {
        tbody.querySelectorAll('tr').forEach(row => {
            row.addEventListener('click', toggleMobileCard);
            row.setAttribute('data-click-listener', 'true');
        });
    }
}

// İstatistikleri güncelle
function updateStats(stats) {
    // Total
    const totalEl = document.querySelector('.stat-cards .card:nth-child(1) .data p');
    if (totalEl) totalEl.textContent = stats.total || 0;
    
    // Pending
    const pendingEl = document.querySelector('.stat-cards .card:nth-child(2) .data p');
    if (pendingEl) pendingEl.textContent = stats.pending || 0;
    
    // Confirmed
    const confirmedEl = document.querySelector('.stat-cards .card:nth-child(3) .data p');
    if (confirmedEl) confirmedEl.textContent = stats.confirmed || 0;
    
    // Completed
    const completedEl = document.querySelector('.stat-cards .card:nth-child(4) .data p');
    if (completedEl) completedEl.textContent = stats.completed || 0;
    
    // Cancelled
    const cancelledEl = document.querySelector('.stat-cards .card:nth-child(5) .data p');
    if (cancelledEl) cancelledEl.textContent = stats.cancelled || 0;
    
    // Progress bar'ları güncelle
    updateStatProgressBars(stats);
}

// Progress bar'ları güncelle
function updateStatProgressBars(stats) {
    const total = stats.total || 1;
    const cards = document.querySelectorAll('.stat-cards .card');
    
    const values = [stats.total, stats.pending, stats.confirmed, stats.completed, stats.cancelled];
    
    cards.forEach((card, index) => {
        const fill = card.querySelector('.fill');
        const percent = card.querySelector('.percent');
        
        if (fill && index > 0) {
            const pct = Math.round((values[index] / total) * 100);
            fill.style.width = pct + '%';
        }
        
        if (percent && index > 0) {
            const pct = Math.round((values[index] / total) * 100);
            percent.innerHTML = `<svg width="20" height="20" fill="currentColor" viewBox="0 0 1792 1792" xmlns="http://www.w3.org/2000/svg">
                <path d="M1408 1216q0 26-19 45t-45 19h-896q-26 0-45-19t-19-45 19-45l448-448q19-19 45-19t45 19l448 448q19 19 19 45z"></path>
            </svg> ${pct}%`;
        }
    });
}

// Sayfalama güncelle
function updatePagination(pagination) {
    const paginationEl = document.querySelector('.pagination-wrapper');
    if (!paginationEl) return;

    // show_all modunda pagination gizli kalsın
    if (currentFilters.show_all) {
        paginationEl.innerHTML = '';
        return;
    }
    
    const { page, total_pages, has_prev, has_next } = pagination;
    
    let html = '<div class="pagination">';
    
    // Önceki
    html += `<button class="page-btn" onclick="goToPage(${page - 1})" ${!has_prev ? 'disabled' : ''}>
        <i class="fas fa-chevron-left"></i>
    </button>`;
    
    // Sayfa numaraları
    const startPage = Math.max(1, page - 2);
    const endPage = Math.min(total_pages, page + 2);
    
    if (startPage > 1) {
        html += `<button class="page-btn" onclick="goToPage(1)">1</button>`;
        if (startPage > 2) html += '<span class="page-dots">...</span>';
    }
    
    for (let i = startPage; i <= endPage; i++) {
        html += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
    }
    
    if (endPage < total_pages) {
        if (endPage < total_pages - 1) html += '<span class="page-dots">...</span>';
        html += `<button class="page-btn" onclick="goToPage(${total_pages})">${total_pages}</button>`;
    }
    
    // Sonraki
    html += `<button class="page-btn" onclick="goToPage(${page + 1})" ${!has_next ? 'disabled' : ''}>
        <i class="fas fa-chevron-right"></i>
    </button>`;
    
    html += '</div>';
    
    paginationEl.innerHTML = html;
}

// Kayıt sayısını güncelle
function updateRecordCount(total) {
    const countEl = document.querySelector('.record-count strong');
    if (countEl) countEl.textContent = total;
}

// URL'i güncelleme - DEVRE DIŞI (temiz URL için)
// function updateURL(params) {
//     const newURL = `${window.location.pathname}?${params.toString()}`;
//     window.history.replaceState({}, '', newURL);
// }

// Sayfaya git
function goToPage(page) {
    loadAppointments({ page: page });
}
window.goToPage = goToPage;

// Sıralama
function sortTable(column) {
    const newOrder = (currentFilters.sort === column && currentFilters.order === 'asc') ? 'desc' : 'asc';
    loadAppointments({ sort: column, order: newOrder, page: 1 });
    
    // Sıralama ikonlarını güncelle
    updateSortIcons(column, newOrder);
}
window.sortTable = sortTable;

// Sıralama ikonlarını güncelle
function updateSortIcons(column, order) {
    document.querySelectorAll('.sortable i').forEach(icon => {
        icon.className = 'fas fa-sort';
        icon.style.opacity = '0.3';
    });
    
    const columnMap = {
        'date': 0, 'customer': 1, 'employee': 3, 'service': 4, 'status': 5, 'price': 6
    };
    
    const headers = document.querySelectorAll('.sortable');
    const header = headers[columnMap[column]];
    if (header) {
        const icon = header.querySelector('i');
        if (icon) {
            icon.className = `fas fa-sort-${order === 'asc' ? 'up' : 'down'}`;
            icon.style.opacity = '1';
        }
    }
}

// ---- TARİH SEÇİCİ AJAX FONKSİYONLARI ----

// Tarihe git (AJAX) - Seçilen tarih ortaya gelir
function slideToDate(dateStr, offset = 0) {
    // Date picker'ı yeniden oluştur (seçilen tarih ortada olacak)
    regenerateDatePicker(dateStr);
    
    // Filtreleri güncelle ve yükle
    loadAppointments({
        date_from: dateStr,
        date_to: dateStr,
        show_all: false,
        page: 1
    });
}
window.slideToDate = slideToDate;

// Date picker UI güncelle
function updateDatePickerUI(selectedDate) {
    const daysTrack = document.getElementById('daysTrack');
    if (!daysTrack) return;
    
    const days = daysTrack.querySelectorAll('.day');
    days.forEach(day => {
        day.classList.remove('selected');
        if (day.dataset.date === selectedDate) {
            day.classList.add('selected');
        }
    });
    
    // Başlık güncelle
    const titleEl = document.getElementById('datePickerTitle');
    if (titleEl) {
        const date = new Date(selectedDate);
        const months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
        titleEl.textContent = `${date.getDate()} ${months[date.getMonth()]} ${date.getFullYear()}`;
    }
}

// Bugüne git
function goToday() {
    const today = new Date().toISOString().split('T')[0];
    slideToDate(today, 0);
    
    // Date picker'ı bugüne döndür
    regenerateDatePicker(today);
}
window.goToday = goToday;

// Date picker'ı yeniden oluştur
function regenerateDatePicker(centerDate) {
    const daysTrack = document.getElementById('daysTrack');
    if (!daysTrack) return;
    
    const center = new Date(centerDate);
    const today = new Date().toISOString().split('T')[0];
    const daysNames = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];
    const months = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    
    const isMobile = window.innerWidth <= 768;
    const range = isMobile ? 2 : 5;
    
    let html = '';
    for (let i = -range; i <= range; i++) {
        const date = new Date(center);
        date.setDate(date.getDate() + i);
        const dateStr = date.toISOString().split('T')[0];
        const isSelected = i === 0;
        const isToday = dateStr === today;
        
        html += `
            <div class="day${isSelected ? ' selected' : ''}${isToday ? ' is-today' : ''}"
                 data-date="${dateStr}"
                 data-offset="${i}"
                 onclick="slideToDate('${dateStr}', ${i})">
                <span>${daysNames[date.getDay()]}</span>
                <strong>${date.getDate()}</strong>
                <span>${months[date.getMonth()]}</span>
                ${isToday ? '<small class="today-badge">Bugün</small>' : ''}
            </div>
        `;
    }
    
    daysTrack.innerHTML = html;
    
    // Başlık güncelle
    const titleEl = document.getElementById('datePickerTitle');
    if (titleEl) {
        titleEl.textContent = `${center.getDate()} ${months[center.getMonth()]} ${center.getFullYear()}`;
    }
}

// Gün değiştir
function changeDatePickerDay(offset) {
    const selected = document.querySelector('.day.selected');
    if (!selected) return;
    
    const currentDate = new Date(selected.dataset.date);
    currentDate.setDate(currentDate.getDate() + offset);
    const newDateStr = currentDate.toISOString().split('T')[0];
    
    // Date picker'ı yeniden oluştur ve filtreleri uygula
    regenerateDatePicker(newDateStr);
    loadAppointments({
        date_from: newDateStr,
        date_to: newDateStr,
        show_all: false,
        page: 1
    });
}
window.changeDatePickerDay = changeDatePickerDay;

// ---- FİLTRE SIDEBAR AJAX FONKSİYONLARI ----

// Filtreleri uygula (AJAX)
function applyFilters() {
    const dateFrom = document.getElementById('date_from')?.value || '';
    const dateTo = document.getElementById('date_to')?.value || '';
    const employee = document.getElementById('employee_filter')?.value || '';
    const status = document.getElementById('status_filter')?.value || '';
    const customer = document.getElementById('customer_filter')?.value || '';
    const perPage = document.getElementById('per_page_filter')?.value || 25;
    
    closeFilterSidebar();
    
    loadAppointments({
        date_from: dateFrom,
        date_to: dateTo,
        employee: employee,
        status: status,
        customer: customer,
        per_page: parseInt(perPage),
        show_all: !dateFrom && !dateTo,
        page: 1
    });
    
    // Date picker'ı güncelle
    if (dateFrom) {
        regenerateDatePicker(dateFrom);
    }
}
window.applyFilters = applyFilters;

// Filtreleri temizle (AJAX)
function clearFilters() {
    // Form alanlarını temizle
    const form = document.getElementById('filterForm');
    if (form) form.reset();
    
    // Bugünün tarihini ayarla
    const today = new Date().toISOString().split('T')[0];
    const dateFrom = document.getElementById('date_from');
    const dateTo = document.getElementById('date_to');
    if (dateFrom) dateFrom.value = today;
    if (dateTo) dateTo.value = today;
    
    closeFilterSidebar();
    
    // Bugüne dön
    loadAppointments({
        date_from: today,
        date_to: today,
        employee: '',
        status: '',
        customer: '',
        per_page: 25,
        show_all: false,
        page: 1
    });
    
    regenerateDatePicker(today);
}
window.clearFilters = clearFilters;

// Tümünü gör (AJAX) - uyarı sonrası tüm randevuları yükler
function showAllAppointments() {
    const confirmMsg = '⚠️ TÜM randevularınız yüklenecek.\n\nÇok fazla veri varsa tarayıcınızda yavaşlama veya çökme yaşanabilir.\n\nDevam etmek istiyor musunuz?';
    if (!confirm(confirmMsg)) return;

    // Pagination'ı gizle (tümü tek sayfada)
    const paginationEl = document.querySelector('.pagination-wrapper');
    if (paginationEl) paginationEl.innerHTML = '';

    loadAppointments({
        date_from: '',
        date_to: '',
        show_all: true,
        per_page: 10000,
        page: 1
    });
    
    // Date picker'daki seçimi kaldır
    document.querySelectorAll('.day').forEach(d => d.classList.remove('selected'));
}
window.showAllAppointments = showAllAppointments;

// ---- DOM GÜNCELLEME FONKSİYONLARI ----

// Satır bul
function getAppointmentRow(appointmentId) {
    return document.querySelector(`tr[data-id="${appointmentId}"]`);
}

// Satırı sil (animasyonlu)
function removeAppointmentRow(appointmentId) {
    const row = getAppointmentRow(appointmentId);
    if (row) {
        row.style.transition = 'opacity 0.3s, transform 0.3s';
        row.style.opacity = '0';
        row.style.transform = 'translateX(-20px)';
        setTimeout(() => row.remove(), 300);
        
        // Tablo boş mu kontrol et
        setTimeout(() => {
            const tbody = document.getElementById('appointmentsTableBody');
            if (tbody && tbody.children.length === 0) {
                showEmptyState();
            }
        }, 350);
    }
}

// Satır durumunu güncelle
function updateRowStatus(appointmentId, newStatus) {
    const row = getAppointmentRow(appointmentId);
    if (!row) return;
    
    // data-status attribute güncelle
    row.setAttribute('data-status', newStatus);
    
    // Badge'i bul ve güncelle
    const badge = row.querySelector('.badge');
    if (badge) {
        // Tüm badge class'larını kaldır
        badge.className = 'badge';
        const config = STATUS_CONFIG[newStatus];
        if (config) {
            badge.classList.add(config.class);
            badge.textContent = config.label;
        }
    }
    
    // Aksiyon butonlarını güncelle
    updateRowActions(row, newStatus, appointmentId);
    
    // Satırı vurgula
    highlightRow(row);
}

// Aksiyon butonlarını duruma göre güncelle
function updateRowActions(row, status, appointmentId) {
    const actionsCell = row.querySelector('.action-buttons');
    if (!actionsCell) return;
    
    // Mevcut fiyatı al (tamamlama için gerekli)
    const priceCell = row.querySelector('td[data-label="Ücret"] strong');
    const price = priceCell ? parseFloat(priceCell.textContent.replace(/[^0-9.,]/g, '').replace(',', '.')) : 0;
    
    let buttonsHTML = '';
    
    if (status === 'pending') {
        buttonsHTML = `
            <button class="btn btn-primary btn-sm" onclick="confirmAppointmentAction(${appointmentId})" title="Onayla">
                <i class="fas fa-check-circle"></i>
            </button>
            <button class="btn btn-danger btn-sm" onclick="cancelAppointmentAction(${appointmentId})" title="İptal Et">
                <i class="fas fa-times-circle"></i>
            </button>
        `;
    } else if (status === 'confirmed') {
        buttonsHTML = `
            <button class="btn btn-success btn-sm" onclick="completeAppointment(${appointmentId}, ${price})" title="Tamamla">
                <i class="fas fa-check"></i>
            </button>
            <button class="btn btn-danger btn-sm" onclick="cancelAppointmentAction(${appointmentId})" title="İptal Et">
                <i class="fas fa-times-circle"></i>
            </button>
            <button class="btn btn-warning btn-sm" onclick="editAppointmentById(${appointmentId})" title="Düzenle">
                <i class="fas fa-edit"></i>
            </button>
        `;
    } else if (status === 'completed') {
        buttonsHTML = `
            <button class="btn btn-warning btn-sm" onclick="editAppointmentById(${appointmentId})" title="Düzenle">
                <i class="fas fa-edit"></i>
            </button>
            <button class="btn btn-danger btn-sm" onclick="deleteAppointment(${appointmentId})" title="Sil">
                <i class="fas fa-trash"></i>
            </button>
        `;
    } else if (status === 'cancelled') {
        buttonsHTML = `
            <button class="btn btn-danger btn-sm" onclick="deleteAppointment(${appointmentId})" title="Kalıcı Sil">
                <i class="fas fa-trash"></i>
            </button>
        `;
    }
    
    actionsCell.innerHTML = buttonsHTML;
}

// Satırı vurgula (flash effect)
function highlightRow(row) {
    row.style.transition = 'background-color 0.3s';
    row.style.backgroundColor = 'rgba(59, 130, 246, 0.2)';
    setTimeout(() => {
        row.style.backgroundColor = '';
    }, 1000);
}

// Boş durum göster
function showEmptyState() {
    const tbody = document.getElementById('appointmentsTableBody');
    if (tbody) {
        tbody.innerHTML = `
            <tr class="appointments-empty-row">
                <td colspan="12" style="text-align: center; padding: 40px;">
                    <i class="fas fa-calendar-times" style="font-size: 48px; color: #9ca3af; margin-bottom: 16px; display:block;"></i>
                    <p style="color: #6b7280; margin:0;">Bu kriterlere uygun randevu bulunamadı.</p>
                </td>
            </tr>
        `;
        return;
    }
    const container = document.querySelector('.appointments-table-container');
    if (container) {
        container.innerHTML = `
            <div class="empty-state" style="text-align: center; padding: 40px;">
                <i class="fas fa-calendar-times" style="font-size: 48px; color: #9ca3af; margin-bottom: 16px;"></i>
                <p style="color: #6b7280;">Bu kriterlere uygun randevu bulunamadı.</p>
            </div>
        `;
    }
}

// ID ile randevu düzenle (API'den veri çekerek)
async function editAppointmentById(appointmentId) {
    try {
        const result = await apiRequest(`get.php?id=${appointmentId}`);
        if (result.success && result.appointment) {
            editAppointment(result.appointment);
        } else {
            showErrorModal('Randevu bilgileri alınamadı');
        }
    } catch (error) {
        console.error('Error:', error);
        showErrorModal('Randevu bilgileri yüklenirken hata oluştu');
    }
}
window.editAppointmentById = editAppointmentById;

// Sayfa yüklendiğinde olay dinleyicilerini ayarla
document.addEventListener('DOMContentLoaded', function () {
    setupFormHandlers();
    setupModalCloseListeners();
});

function setupFormHandlers() {
    // Ana randevu formu
    const appointmentForm = document.getElementById('appointmentForm');
    if (appointmentForm) {
        appointmentForm.addEventListener('submit', function (e) {
            e.preventDefault();
            submitAppointmentForm();
        });
    }
    // Tamamlama formu - submit engelle (ödeme butonları yönetir)
    const completeForm = document.getElementById('completeForm');
    if (completeForm) {
        completeForm.addEventListener('submit', function (e) {
            e.preventDefault();
        });
    }
    // Telefon inputuna debounced müşteri arama
    const customerPhoneInput = document.getElementById('customer_phone');
    if (customerPhoneInput) {
        customerPhoneInput.addEventListener('input', function() {
            clearTimeout(_aptCustomerPhoneDebounce);
            _aptCustomerPhoneDebounce = setTimeout(() => fillCustomerInfo(), 500);
        });
    }

    const smsChk = document.getElementById('send_customer_sms');
    if (smsChk) {
        const prefKey = 'appointments_send_customer_sms';
        if (localStorage.getItem(prefKey) === '1') smsChk.checked = true;
        smsChk.addEventListener('change', function () {
            localStorage.setItem(prefKey, smsChk.checked ? '1' : '0');
            if (typeof window.warnSmsIfUnavailable === 'function') {
                window.warnSmsIfUnavailable(smsChk.checked);
            }
        });
    }
}

// Modal dışına tıklayınca kapat
function setupModalCloseListeners() {
    const modals = ['appointmentModal', 'completeModal', 'errorModal', 'customerConfirmationModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        }
    });
}

// ---- MODAL KONTROLLERİ ----
function openAddAppointmentModal() {
    document.getElementById('modalTitle').textContent = 'Yeni Randevu';
    document.getElementById('form_action').value = 'add';
    document.getElementById('appointmentForm').reset();
    document.getElementById('appointment_date').value = new Date().toISOString().split('T')[0];
    document.getElementById('appointment_status').value = 'confirmed';
    
    const nameInput = document.getElementById('customer_name');
    if (nameInput) nameInput.readOnly = false;
    
    const infoAlert = document.getElementById('customerInfoAlert');
    if (infoAlert) infoAlert.style.display = 'none';
    
    // Aralıklı hizmet input'larını gizle
    document.querySelectorAll('.edit-range-input').forEach(el => el.style.display = 'none');
    
    // Varyant panellerini gizle ve seçimleri temizle
    document.querySelectorAll('.variant-panel').forEach(el => {
        el.style.display = 'none';
        el.querySelectorAll('.variant-input').forEach(v => v.checked = false);
    });
    
    // Tekrarlayan bölümünü göster (editAppointment gizlemiş olabilir)
    var recurringSection = document.getElementById('recurringSection');
    if (recurringSection) recurringSection.style.display = '';

    // Sepet toggle butonunu göster (sadece "ekle" modunda)
    var cartToggleBtn = document.getElementById('cartModeToggleBtn');
    if (cartToggleBtn) cartToggleBtn.style.display = '';
    // Sepet modunu sıfırla
    if (adminCartMode) {
        adminCartMode = false;
        var cartLbl = document.getElementById('cartModeToggleLbl');
        if (cartToggleBtn) cartToggleBtn.classList.replace('btn-warning', 'btn-outline-secondary');
        if (cartLbl) cartLbl.textContent = 'Sepet Modu';
        var cartPanel = document.getElementById('adminCartPanel');
        if (cartPanel) cartPanel.style.display = 'none';
        var normalSave = document.getElementById('normalSaveBtn');
        if (normalSave) normalSave.style.display = '';
        var addToCartBtn2 = document.getElementById('addToCartBtn');
        if (addToCartBtn2) addToCartBtn2.style.display = 'none';
        if (recurringSection) recurringSection.style.display = '';
        adminCart = [];
        renderAdminCart();
    }

    document.getElementById('appointmentModal').classList.add('active');
}
// Global scope'a ekle
window.openAddAppointmentModal = openAddAppointmentModal;
function closeAppointmentModal() {
    document.getElementById('appointmentModal').classList.remove('active');
    currentAppointment = null;
    // Tekrarlayan alanları sıfırla
    var recurringCb = document.getElementById('is_recurring');
    if (recurringCb) recurringCb.checked = false;
    var recurringOpts = document.getElementById('recurringOptions');
    if (recurringOpts) recurringOpts.style.display = 'none';
    var recurringPreview = document.getElementById('recurringPreview');
    if (recurringPreview) recurringPreview.style.display = 'none';
    var recurringCustom = document.getElementById('recurringCustomDays');
    if (recurringCustom) recurringCustom.style.display = 'none';
    var recurringEnd = document.getElementById('recurring_end_date');
    if (recurringEnd) recurringEnd.value = '';
    // Sepet modunu kapat ve sepeti temizle
    if (adminCartMode) {
        adminCartMode = false;
        var cartBtn = document.getElementById('cartModeToggleBtn');
        var cartLbl = document.getElementById('cartModeToggleLbl');
        if (cartBtn) cartBtn.classList.replace('btn-warning', 'btn-outline-secondary');
        if (cartLbl) cartLbl.textContent = 'Sepet Modu';
        var cartPanel = document.getElementById('adminCartPanel');
        if (cartPanel) cartPanel.style.display = 'none';
        var normalSave = document.getElementById('normalSaveBtn');
        if (normalSave) normalSave.style.display = '';
        var addToCartBtn3 = document.getElementById('addToCartBtn');
        if (addToCartBtn3) addToCartBtn3.style.display = 'none';
    }
    adminCart = [];
}

function editAppointment(appointment) {
    currentAppointment = appointment;

    // Element kontrolü ile güvenli set
    const setValueSafe = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.value = value || '';
        else console.warn('Element not found:', id);
    };

    const setTextSafe = (id, text) => {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
        else console.warn('Element not found:', id);
    };

    // Saat formatını HH:MM:SS'den HH:MM'ye çevir
    const formatTimeForInput = (time) => {
        if (!time) return '';
        // Eğer HH:MM:SS formatındaysa HH:MM'ye çevir
        return time.substring(0, 5);
    };

    setTextSafe('modalTitle', 'Randevu Düzenle');
    setValueSafe('form_action', 'update');
    setValueSafe('appointment_id', appointment.id);
    setValueSafe('customer_name', appointment.customer_name);
    setValueSafe('customer_phone', appointment.customer_phone);
    setValueSafe('employee_id', appointment.employee_id);
    setValueSafe('appointment_date', appointment.appointment_date);
    setValueSafe('appointment_time', formatTimeForInput(appointment.appointment_time));
    setValueSafe('appointment_status', appointment.status);
    setValueSafe('notes', appointment.notes);

    // Hizmet seçimi için checkbox'ları işaretle
    if (appointment.service_ids) {
        let serviceIds = appointment.service_ids;
        // String ise array'e çevir
        if (typeof serviceIds === 'string') {
            serviceIds = serviceIds.split(',').map(s => s.trim());
        }
        // Array değilse array yap
        if (!Array.isArray(serviceIds)) {
            serviceIds = [serviceIds];
        }
        // String'e çevir (checkbox value'lar string)
        const serviceIdStrings = serviceIds.map(id => String(id));
        
        // Mevcut varyant bilgilerini hazırla (service_details'den)
        const existingVariants = {};
        if (appointment.service_details) {
            appointment.service_details.forEach(detail => {
                if (detail.variant_ids) {
                    try {
                        existingVariants[String(detail.service_id)] = JSON.parse(detail.variant_ids);
                    } catch(e) { /* ignore */ }
                }
            });
        }
        
        document.querySelectorAll('.service-checkbox').forEach(cb => {
            cb.checked = serviceIdStrings.includes(cb.value);
            // Aralıklı hizmetlerin input'larını göster/gizle
            const wrapper = cb.closest('.service-item-wrapper');
            const rangeDiv = wrapper?.querySelector('.edit-range-input');
            if (rangeDiv) {
                rangeDiv.style.display = cb.checked ? 'flex' : 'none';
            }
            // Varyant panellerini göster/gizle ve mevcut seçimleri işaretle
            const variantPanel = wrapper?.querySelector('.variant-panel');
            if (variantPanel) {
                variantPanel.style.display = cb.checked ? 'block' : 'none';
                // Önce tüm seçimleri temizle
                variantPanel.querySelectorAll('.variant-input').forEach(v => v.checked = false);
                // Mevcut varyantları işaretle
                const variantIds = existingVariants[cb.value] || [];
                variantIds.forEach(vid => {
                    const input = variantPanel.querySelector(`.variant-input[value="${vid}"]`);
                    if (input) input.checked = true;
                });
            }
        });
        if (typeof updateTotalPrice === 'function') updateTotalPrice();
    }

    const modal = document.getElementById('appointmentModal');
    if (modal) modal.classList.add('active');
    else console.error('appointmentModal not found');

    // Sepet toggle butonunu düzenleme modunda gizle
    var cartToggleBtn2 = document.getElementById('cartModeToggleBtn');
    if (cartToggleBtn2) cartToggleBtn2.style.display = 'none';

    // Tekrarlayan bölümünü düzenlemede gizle
    var recurringSection = document.getElementById('recurringSection');
    if (recurringSection) recurringSection.style.display = 'none';
}

function completeAppointment(appointmentId, currentPrice) {
    document.getElementById('complete_appointment_id').value = appointmentId;
    document.getElementById('final_price').value = currentPrice;
    
    // Hizmet listesini oluştur
    const serviceListContainer = document.getElementById('completeServiceList');
    serviceListContainer.innerHTML = '';
    
    const services = window.appointmentsData?.services || [];
    
    // Randevu verilerini API'den çek (service_ids ve varyant bilgileri için)
    apiRequest(`get.php?id=${appointmentId}`)
        .then(result => {
            let selectedServiceIds = [];
            let serviceDetails = [];
            if (result.success && result.appointment) {
                const apt = result.appointment;
                selectedServiceIds = apt.service_ids ? apt.service_ids.split(',') : [];
                serviceDetails = apt.service_details || [];
                // Notları doldur
                document.getElementById('complete_notes').value = apt.notes || '';
            }
            
            buildCompleteServiceList(serviceListContainer, services, selectedServiceIds, serviceDetails);
            
            updateCompletePrice();
            
            serviceListContainer.querySelectorAll('.complete-service-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const rangeDiv = serviceListContainer.querySelector(`.complete-range-price-input[data-service-id="${this.dataset.id}"]`);
                    const variantPanel = serviceListContainer.querySelector(`.complete-variant-panel[data-service-id="${this.dataset.id}"]`);
                    if (rangeDiv) rangeDiv.style.display = this.checked ? 'flex' : 'none';
                    if (variantPanel) {
                        variantPanel.style.display = this.checked ? 'block' : 'none';
                        if (!this.checked) variantPanel.querySelectorAll('.complete-variant-input').forEach(v => v.checked = false);
                    }
                    updateCompletePrice();
                    // Hizmet değişince ürün listesini güncelle
                    loadProductConsumption(appointmentId);
                });
            });
            
            document.getElementById('completeModal').classList.add('active');
            
            // Ürün tüketim verilerini yükle
            loadProductConsumption(appointmentId);
        })
        .catch(error => {
            console.error('Error fetching appointment:', error);
            // Fallback: hizmet listesini service_ids olmadan göster
            buildCompleteServiceList(serviceListContainer, services, [], []);
            
            serviceListContainer.querySelectorAll('.complete-service-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const rangeDiv = serviceListContainer.querySelector(`.complete-range-price-input[data-service-id="${this.dataset.id}"]`);
                    const variantPanel = serviceListContainer.querySelector(`.complete-variant-panel[data-service-id="${this.dataset.id}"]`);
                    if (rangeDiv) rangeDiv.style.display = this.checked ? 'flex' : 'none';
                    if (variantPanel) {
                        variantPanel.style.display = this.checked ? 'block' : 'none';
                        if (!this.checked) variantPanel.querySelectorAll('.complete-variant-input').forEach(v => v.checked = false);
                    }
                    updateCompletePrice();
                    loadProductConsumption(appointmentId);
                });
            });
            
            document.getElementById('completeModal').classList.add('active');
            
            // Ürün tüketim verilerini yükle (fallback)
            loadProductConsumption(appointmentId);
        });
}

function buildCompleteServiceList(container, services, selectedServiceIds, serviceDetails) {
    serviceDetails = serviceDetails || [];
    const serviceVariants = window.appointmentsData?.serviceVariants || {};
    // service_id => detail map
    const detailMap = {};
    serviceDetails.forEach(sd => { detailMap[sd.service_id] = sd; });

    services.forEach(service => {
        const isChecked = selectedServiceIds.includes(service.id.toString());
        const isRange = service.pricing_type === 'range' && parseFloat(service.price_min) >= 0 && parseFloat(service.price_max) > 0;
        const isVariant = service.pricing_type === 'variant';
        const detail = detailMap[service.id];
        const variants = serviceVariants[service.id] || [];
        
        // Mevcut seçili varyant ID'leri
        let selectedVariantIds = [];
        if (isVariant && detail && detail.variant_ids) {
            try { selectedVariantIds = JSON.parse(detail.variant_ids); } catch(e) { selectedVariantIds = []; }
        }
        
        // Fiyat etiketi
        let priceLabel;
        if (isVariant) {
            priceLabel = variants.length > 0 ? '(' + formatPrice(service.price).replace(' ₺','') + '+' + ' TL)' : '(' + formatPrice(service.price).replace(' ₺','') + ' TL)';
        } else if (isRange) {
            priceLabel = '<span style="color:#8b5cf6;font-size:0.85em;">(Aralık: ' + formatPrice(service.price_min).replace(' ₺','') + ' - ' + formatPrice(service.price_max).replace(' ₺','') + ' TL)</span>';
        } else {
            priceLabel = '(' + formatPrice(service.price).replace(' ₺','') + ' TL)';
        }
        
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'margin-bottom: 6px;';
        
        const label = document.createElement('label');
        label.style.cssText = 'display: flex; align-items: center; gap: 6px;';
        label.innerHTML = `<input type="checkbox" class="complete-service-checkbox" value="${service.price}" data-id="${service.id}" data-base-price="${service.price}" data-is-range="${isRange ? '1' : '0'}" data-is-variant="${isVariant ? '1' : '0'}" data-min="${service.price_min || service.price}" data-max="${service.price_max || service.price}" ${isChecked ? 'checked' : ''}> ${service.name} ${priceLabel}`;
        wrapper.appendChild(label);
        
        if (isRange) {
            const effectivePrice = (detail && detail.final_price != null && detail.final_price !== '')
                ? parseFloat(detail.final_price)
                : parseFloat(service.price_min);
            const rangeInput = document.createElement('div');
            rangeInput.className = 'complete-range-price-input';
            rangeInput.dataset.serviceId = service.id;
            rangeInput.style.cssText = 'margin-left: 24px; margin-top: 4px; display: ' + (isChecked ? 'flex' : 'none') + '; align-items: center; gap: 6px;';
            rangeInput.innerHTML = `<span style="font-size:0.8em; color:#6b7280;">Alınan tutar:</span><input type="number" class="complete-range-actual-price" data-service-id="${service.id}" value="${effectivePrice}" min="${service.price_min}" max="${service.price_max}" step="any" style="width:90px; padding:4px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.85em; text-align:right;" oninput="updateCompletePrice()" onchange="updateCompletePrice()"><span style="font-size:0.8em; color:#6b7280;">TL</span>`;
            wrapper.appendChild(rangeInput);
        }
        
        if (isVariant && variants.length > 0) {
            const isMultiple = parseInt(service.is_multiple || 0);
            const variantPanel = document.createElement('div');
            variantPanel.className = 'variant-panel complete-variant-panel';
            variantPanel.dataset.serviceId = service.id;
            variantPanel.style.cssText = 'display:' + (isChecked ? 'block' : 'none') + '; margin-left:24px; margin-top:6px; padding:8px 12px; background:rgba(139,92,246,0.06); border:1px solid rgba(139,92,246,0.2); border-radius:8px;';
            const headerDiv = document.createElement('div');
            headerDiv.style.cssText = 'font-size:0.8em; color:#8b5cf6; font-weight:600; margin-bottom:6px;';
            headerDiv.textContent = 'Varyant Seçimi:';
            variantPanel.appendChild(headerDiv);
            
            variants.forEach(v => {
                const vLabel = document.createElement('label');
                vLabel.style.cssText = 'display:flex; align-items:center; gap:6px; padding:4px 0; cursor:pointer; font-size:0.85em;';
                const inputType = isMultiple ? 'checkbox' : 'radio';
                const isVarChecked = selectedVariantIds.includes(parseInt(v.id)) || selectedVariantIds.includes(v.id.toString());
                let vHtml = `<input type="${inputType}" class="complete-variant-input" value="${v.id}" data-service-id="${service.id}" data-extra-price="${v.extra_price}" data-extra-duration="${v.extra_duration}" ${isVarChecked ? 'checked' : ''} onchange="updateCompletePrice()"> <span>${v.name}</span>`;
                if (parseFloat(v.extra_price) > 0) vHtml += `<span style="color:#8b5cf6;font-weight:600;">+${parseFloat(v.extra_price).toFixed(0)} TL</span>`;
                if (parseInt(v.extra_duration) > 0) vHtml += `<span style="color:#6b7280;font-size:0.85em;">(+${v.extra_duration} dk)</span>`;
                vLabel.innerHTML = vHtml;
                variantPanel.appendChild(vLabel);
            });
            wrapper.appendChild(variantPanel);
        }
        
        container.appendChild(wrapper);
    });
}

// Global scope'a ekle
window.completeAppointment = completeAppointment;
window.editAppointment = editAppointment;

function closeAppointmentModal() {
    document.getElementById('appointmentModal').classList.remove('active');
    currentAppointment = null;
    // Tekrarlayan alanları sıfırla
    var recurringCb = document.getElementById('is_recurring');
    if (recurringCb) recurringCb.checked = false;
    var recurringOpts = document.getElementById('recurringOptions');
    if (recurringOpts) recurringOpts.style.display = 'none';
    var recurringPreview = document.getElementById('recurringPreview');
    if (recurringPreview) recurringPreview.style.display = 'none';
    var recurringCustom = document.getElementById('recurringCustomDays');
    if (recurringCustom) recurringCustom.style.display = 'none';
    var recurringEnd = document.getElementById('recurring_end_date');
    if (recurringEnd) recurringEnd.value = '';
}

function closeCompleteModal() {
    document.getElementById('completeModal').classList.remove('active');
}

function updateCompletePrice() {
    let total = 0;
    const serviceList = document.getElementById('completeServiceList');
    if (!serviceList) return;
    serviceList.querySelectorAll('.complete-service-checkbox:checked').forEach(checkbox => {
        if (checkbox.dataset.isRange === '1') {
            const rangeInput = serviceList.querySelector(`.complete-range-actual-price[data-service-id="${checkbox.dataset.id}"]`);
            total += rangeInput ? parseFloat(rangeInput.value) || 0 : parseFloat(checkbox.dataset.basePrice || checkbox.value);
        } else if (checkbox.dataset.isVariant === '1') {
            total += parseFloat(checkbox.dataset.basePrice || checkbox.value);
            serviceList.querySelectorAll(`.complete-variant-panel[data-service-id="${checkbox.dataset.id}"] .complete-variant-input:checked`).forEach(v => {
                total += parseFloat(v.dataset.extraPrice || 0);
            });
        } else {
            total += parseFloat(checkbox.value);
        }
    });
    const finalEl = document.getElementById('final_price');
    if (finalEl) finalEl.value = total.toFixed(2);
}

// ============================================================
// ALINMADI ALT SEÇENEKLER
// ============================================================
let _notPaidContext = 'complete';

function showNotPaidOptions(context) {
    _notPaidContext = context || 'complete';
    const panel = document.getElementById('notPaidOptionsPanel');
    if (!panel) { setPaymentMethodAndSubmit('not_paid'); return; }
    const fullRadio = panel.querySelector('[name="not_paid_type"][value="full"]');
    if (fullRadio) fullRadio.checked = true;
    const partialFields = document.getElementById('partialPaymentFields');
    if (partialFields) partialFields.style.display = 'none';
    const paidInput = document.getElementById('partialPaidAmount');
    if (paidInput) paidInput.value = '';
    updateRemainingAmount();
    panel.style.display = '';
    setTimeout(() => panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
}

function cancelNotPaidOptions() {
    const panel = document.getElementById('notPaidOptionsPanel');
    if (panel) panel.style.display = 'none';
}

function onNotPaidTypeChange() {
    const val = document.querySelector('[name="not_paid_type"]:checked')?.value;
    const partialFields = document.getElementById('partialPaymentFields');
    if (partialFields) partialFields.style.display = val === 'partial' ? '' : 'none';
    if (val === 'partial') {
        updateRemainingAmount();
        setTimeout(() => partialFields.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
    }
}

function updateRemainingAmount() {
    const total = parseFloat(document.getElementById('final_price')?.value) || 0;
    const paid  = parseFloat(document.getElementById('partialPaidAmount')?.value) || 0;
    const remaining = Math.max(0, total - paid);
    const el = document.getElementById('remainingAmountDisplay');
    if (el) el.textContent = remaining.toFixed(2) + ' ₺';
}

function confirmNotPaid() {
    const type = document.querySelector('[name="not_paid_type"]:checked')?.value;
    const panel = document.getElementById('notPaidOptionsPanel');
    if (type === 'partial') {
        const total  = parseFloat(document.getElementById('final_price')?.value) || 0;
        const paid   = parseFloat(document.getElementById('partialPaidAmount')?.value) || 0;
        const method = document.getElementById('partialPaymentMethod')?.value || 'cash';
        if (paid <= 0) { showErrorModal('Alınan tutar 0’dan büyük olmalıdır'); return; }
        if (paid >= total && total > 0) { showErrorModal('Alınan tutar toplam tutardan küçük olmalıdır'); return; }
        if (panel) panel.style.display = 'none';
        setPaymentMethodAndSubmit(method, paid);
    } else {
        if (panel) panel.style.display = 'none';
        setPaymentMethodAndSubmit('not_paid');
    }
}

async function setPaymentMethodAndSubmit(paymentMethod, paidAmount = null) {
    const appointmentId = document.getElementById('complete_appointment_id').value;
    const serviceListContainer = document.getElementById('completeServiceList');
    const serviceIds = Array.from(serviceListContainer.querySelectorAll('.complete-service-checkbox:checked')).map(cb => cb.dataset.id);
    
    if (serviceIds.length === 0) {
        showErrorModal('Lütfen en az bir hizmet seçin');
        return;
    }
    
    // Varyant validasyonu
    let aptVarErr = false;
    serviceListContainer.querySelectorAll('.complete-service-checkbox:checked').forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const panel = serviceListContainer.querySelector(`.complete-variant-panel[data-service-id="${cb.dataset.id}"]`);
            if (panel && panel.querySelectorAll('.complete-variant-input:checked').length === 0) {
                aptVarErr = true;
            }
        }
    });
    if (aptVarErr) {
        showErrorModal('Lütfen varyantlı hizmetler için varyant seçimi yapın');
        return;
    }
    
    // Varyant verilerini topla
    const aptCompleteVariantData = {};
    serviceListContainer.querySelectorAll('.complete-service-checkbox:checked').forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const panel = serviceListContainer.querySelector(`.complete-variant-panel[data-service-id="${cb.dataset.id}"]`);
            if (panel) {
                const selected = Array.from(panel.querySelectorAll('.complete-variant-input:checked')).map(v => ({
                    id: parseInt(v.value),
                    extra_price: parseFloat(v.dataset.extraPrice || 0),
                    extra_duration: parseInt(v.dataset.extraDuration || 0)
                }));
                if (selected.length > 0) aptCompleteVariantData[cb.dataset.id] = selected;
            }
        }
    });
    
    // 0 TL onayı: kaydetmeden önce kullanıcıdan onay al (İptal => forma döner)
    if (parseFloat(document.getElementById('final_price').value) === 0 &&
        !confirm('Bu randevuyu 0 TL olarak kaydediyorsunuz. Onaylıyor musunuz?')) {
        return;
    }
    
    // Butonları devre dışı bırak
    const paymentButtons = document.querySelectorAll('.complete-modal-payment button');
    paymentButtons.forEach(btn => btn.disabled = true);
    
    const aptCompleteRangePrices = {};
    const aptRangeChecked = [];
    serviceListContainer.querySelectorAll('.complete-service-checkbox:checked').forEach(cb => {
        if (cb.dataset.isRange === '1') {
            const rangeInput = serviceListContainer.querySelector(`.complete-range-actual-price[data-service-id="${cb.dataset.id}"]`);
            if (rangeInput) {
                aptCompleteRangePrices[cb.dataset.id] = parseFloat(rangeInput.value) || 0;
                aptRangeChecked.push(cb.dataset.id);
            }
        }
    });
    const aptCompleteTotal = parseFloat(document.getElementById('final_price').value);
    if (aptRangeChecked.length === 1 && Number.isFinite(aptCompleteTotal)) {
        let fixedSum = 0;
        serviceListContainer.querySelectorAll('.complete-service-checkbox:checked').forEach(cb => {
            if (cb.dataset.isRange === '1') return;
            fixedSum += parseFloat(cb.dataset.basePrice || cb.value || 0);
        });
        const onlySid = aptRangeChecked[0];
        aptCompleteRangePrices[onlySid] = Math.max(0, aptCompleteTotal - fixedSum);
        const syncInput = serviceListContainer.querySelector(`.complete-range-actual-price[data-service-id="${onlySid}"]`);
        if (syncInput) syncInput.value = aptCompleteRangePrices[onlySid];
    }

    try {
        const result = await apiRequest('complete.php', 'POST', {
            appointment_id: parseInt(appointmentId),
            payment_method: paymentMethod,
            service_ids: serviceIds,
            range_prices: aptCompleteRangePrices,
            variant_data: aptCompleteVariantData,
            // 0 geçerli tutar; boş/geçersiz alan => null (sunucu mevcut fiyatı korur)
            total_price: Number.isFinite(parseFloat(document.getElementById('final_price').value)) ? parseFloat(document.getElementById('final_price').value) : null,
            paid_amount: paidAmount,
            product_consumption: collectProductConsumption()
        });
        
        if (result.success) {
            showAlert('success', result.message);
            closeCompleteModal();
            updateRowStatus(parseInt(appointmentId), 'completed');
            
            // Fiyatı da güncelle
            const row = getAppointmentRow(parseInt(appointmentId));
            if (row) {
                const priceCell = row.querySelector('td[data-label="Ücret"] strong');
                if (priceCell) {
                    const totalPrice = parseFloat(document.getElementById('final_price').value) || 0;
                    priceCell.textContent = formatPrice(totalPrice);
                }
            }
        } else {
            showErrorModal(result.message || 'Bir hata oluştu');
        }
    } catch (error) {
        console.error('Complete Error:', error);
        showErrorModal('Randevu tamamlanırken bir hata oluştu.');
    } finally {
        paymentButtons.forEach(btn => btn.disabled = false);
    }
}

window.setPaymentMethodAndSubmit = setPaymentMethodAndSubmit;
window.updateCompletePrice = updateCompletePrice;

function closeErrorModal() {
    document.getElementById('errorModal').classList.remove('active');
}

function cancelExistingCustomer() {
    document.getElementById('customerConfirmationModal').classList.remove('active');
    pendingFormData = null;
    toggleSubmitButton(document.getElementById('appointmentForm'), false);
}

// Müşteri telefon numarasından otomatik bilgi çekme (debounce ile)
let _aptCustomerPhoneDebounce = null;

function fillCustomerInfo() {
    const phoneInput = document.getElementById('customer_phone');
    const nameInput = document.getElementById('customer_name');
    const infoAlert = document.getElementById('customerInfoAlert');

    if (!phoneInput || !nameInput) return;

    const phone = phoneInput.value.trim();
    if (!phone || phone.length < 10) {
        // Numara silinirse alert'i gizle
        if (infoAlert) infoAlert.style.display = 'none';
        return;
    }

    // Customers API ile müşteri bilgilerini çek
    fetch(`api/customers/get_by_phone.php?phone=${encodeURIComponent(phone)}`, {
        method: 'GET',
        headers: { 
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.customer) {
                nameInput.value = data.customer.name;
                nameInput.readOnly = true;

                // Bilgi kartını göster
                if (infoAlert) {
                    infoAlert.style.display = 'flex';
                    // 5 saniye sonra otomatik gizle
                    setTimeout(() => {
                        if (infoAlert.style.display === 'flex') {
                            infoAlert.style.display = 'none';
                        }
                    }, 5000);
                }
            } else {
                nameInput.readOnly = false;
                if (infoAlert) infoAlert.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Müşteri bilgisi alınamadı:', error);
            nameInput.readOnly = false;
            if (infoAlert) infoAlert.style.display = 'none';
        });
}

// Global scope'a ekle (önce fonksiyonları tanımla, sonra window'a ata)
window.openAddAppointmentModal = openAddAppointmentModal;
window.closeAppointmentModal = closeAppointmentModal;
window.closeCompleteModal = closeCompleteModal;
window.closeErrorModal = closeErrorModal;
window.cancelExistingCustomer = cancelExistingCustomer;
window.fillCustomerInfo = fillCustomerInfo;
window.updateTotalPrice = updateTotalPrice;

// Hizmet seçimlerinden toplam fiyat hesapla
function updateTotalPrice() {
    let total = 0;
    document.querySelectorAll('.service-checkbox').forEach(cb => {
        const wrapper = cb.closest('.service-item-wrapper');
        const rangeDiv = wrapper?.querySelector('.edit-range-input');
        const variantPanel = wrapper?.querySelector('.variant-panel');
        if (rangeDiv) {
            rangeDiv.style.display = cb.checked ? 'flex' : 'none';
        }
        if (variantPanel) {
            variantPanel.style.display = cb.checked ? 'block' : 'none';
            if (!cb.checked) {
                variantPanel.querySelectorAll('.variant-input').forEach(v => v.checked = false);
            }
        }
        if (cb.checked) {
            if (cb.dataset.pricingType === 'range') {
                const rangeInput = wrapper?.querySelector('.edit-range-actual-price');
                total += rangeInput ? (parseFloat(rangeInput.value) || 0) : parseFloat(cb.dataset.price || 0);
            } else if (cb.dataset.isVariant === '1') {
                total += parseFloat(cb.dataset.price || 0);
                if (variantPanel) {
                    variantPanel.querySelectorAll('.variant-input:checked').forEach(v => {
                        total += parseFloat(v.dataset.extraPrice || 0);
                    });
                }
            } else {
                total += parseFloat(cb.dataset.price || 0);
            }
        }
    });
    const totalDisplay = document.getElementById('totalPriceDisplay');
    if (totalDisplay) {
        totalDisplay.textContent = 'Toplam: ' + formatPrice(total);
    }
}

// ---- FORM GÖNDERME İŞLEMLERİ ----
function submitAppointmentForm() {
    const form = document.getElementById('appointmentForm');
    const formData = new FormData(form);
    const action = formData.get('action');
    toggleSubmitButton(form, true, 'Kaydediliyor...');
    
    // Varyantlı hizmet seçimi validasyonu
    const checkedBoxes = form.querySelectorAll('input[name="service_ids[]"]:checked');
    let variantError = false;
    checkedBoxes.forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const wrapper = cb.closest('.service-item-wrapper');
            const checkedVariants = wrapper?.querySelectorAll('.variant-input:checked');
            if (!checkedVariants || checkedVariants.length === 0) {
                variantError = true;
            }
        }
    });
    if (variantError) {
        showErrorModal('Lütfen varyantlı hizmetler için varyant seçimi yapın');
        toggleSubmitButton(form, false, 'Kaydet');
        return;
    }
    
    // Varyant seçimleri
    const variantData = {};
    checkedBoxes.forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const wrapper = cb.closest('.service-item-wrapper');
            const selectedVariants = Array.from(wrapper?.querySelectorAll('.variant-input:checked') || []).map(v => ({
                id: parseInt(v.value),
                extra_price: parseFloat(v.dataset.extraPrice || 0),
                extra_duration: parseInt(v.dataset.extraDuration || 0)
            }));
            if (selectedVariants.length > 0) {
                variantData[cb.value] = selectedVariants;
            }
        }
    });

    // Aralıklı fiyatlar + toplam
    const rangePrices = {};
    let computedTotal = 0;
    checkedBoxes.forEach(cb => {
        const wrapper = cb.closest('.service-item-wrapper');
        const sid = cb.dataset.serviceId || cb.value;
        if (cb.dataset.pricingType === 'range' || cb.dataset.isRange === '1') {
            const rangeInput = wrapper?.querySelector('.edit-range-actual-price, .range-actual-price');
            const rp = rangeInput ? (parseFloat(rangeInput.value) || 0) : parseFloat(cb.dataset.price || 0);
            rangePrices[sid] = rp;
            computedTotal += rp;
        } else if (cb.dataset.isVariant === '1') {
            let p = parseFloat(cb.dataset.price || 0);
            wrapper?.querySelectorAll('.variant-input:checked').forEach(v => {
                p += parseFloat(v.dataset.extraPrice || 0);
            });
            computedTotal += p;
        } else {
            computedTotal += parseFloat(cb.dataset.price || 0);
        }
    });
    
    // Form verilerini JSON'a çevir
    const data = {
        customer_name: formData.get('customer_name'),
        customer_phone: formData.get('customer_phone'),
        employee_id: parseInt(formData.get('employee_id')),
        service_ids: formData.getAll('service_ids[]').map(Number),
        appointment_date: formData.get('appointment_date'),
        appointment_time: formData.get('appointment_time'),
        status: formData.get('status'),
        notes: formData.get('notes') || '',
        variant_data: variantData,
        range_prices: rangePrices,
        total_price: computedTotal
    };
    
    // Tekrarlayan randevu verileri
    const isRecurring = document.getElementById('is_recurring');
    if (isRecurring && isRecurring.checked && action !== 'update') {
        data.is_recurring = 1;
        data.recurring_type = document.getElementById('recurring_type').value;
        data.recurring_end_date = document.getElementById('recurring_end_date').value;
        if (data.recurring_type === 'custom') {
            data.recurring_days = formData.getAll('recurring_days[]').map(Number);
        }
    }
    
    // Güncelleme ise ID ekle
    if (action === 'update') {
        data.appointment_id = parseInt(formData.get('appointment_id'));
    }
    
    const endpoint = action === 'update' ? 'update.php' : 'create.php';
    
    apiRequest(endpoint, 'POST', data)
        .then(result => {
            if (result.success) {
                showAlert('success', result.message);
                closeAppointmentModal();
                
                // Tekrarlayan randevu oluşturulduysa sayfayı yenile
                if (result.recurring_count && result.recurring_count > 1) {
                    setTimeout(() => location.reload(), 800);
                } else if (action === 'update' && result.appointment) {
                    // Mevcut satırı güncelle
                    updateAppointmentRowData(result.appointment);
                } else if (result.appointment) {
                    // Yeni satır ekle
                    addAppointmentRow(result.appointment);
                } else {
                    // Veri yoksa sayfayı yenile (fallback)
                    location.reload();
                }
            } else if (result.existing_customer) {
                showExistingCustomerModal(result);
            } else if (result.past_date_warning) {
                // Geçmiş tarih onayı iste, kullanıcı onaylarsa aynı veriyi confirm_past_date=1 ile tekrar gönder
                showPastDateConfirmModal(data, action);
            } else if (result.conflict && result.needs_confirm) {
                showConflictBetweenConfirmModal(data, action, result.message);
            } else {
                showErrorModal(result.message || 'Bir hata oluştu');
                if (result.errors) {
                    console.error('Validation errors:', result.errors);
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showErrorModal('Bir ağ hatası oluştu. Lütfen tekrar deneyin.');
        })
        .finally(() => {
            const customerModal = document.getElementById('customerConfirmationModal');
            if (!customerModal || !customerModal.classList.contains('active')) {
                toggleSubmitButton(form, false, 'Kaydet');
            }
        });
}

function confirmExistingCustomer() {
    document.getElementById('customerConfirmationModal').style.display = 'none';
    const existingName = document.getElementById('confirmation-message-text').dataset.name;
    document.getElementById('customer_name').value = existingName;
    submitAppointmentForm();
}

// ---- TEKLİ VE TOPLU AKSİYONLAR ----
function deleteAppointment(appointmentId) {
    // Modal ile silme onayı al (tarayıcı confirm yerine)
    showDeleteModal(appointmentId);
}

function showDeleteModal(appointmentId) {
    const modal = document.getElementById('deleteConfirmationModal');
    const messageText = document.getElementById('delete-message-text');
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    
    messageText.textContent = 'Bu randevuyu silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.';
    
    // Eski event listener'ı temizle
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    
    // Yeni event listener ekle
    newConfirmBtn.addEventListener('click', function() {
        closeDeleteModal();
        executeDelete(appointmentId);
    });
    
    modal.classList.add('active');
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteConfirmationModal');
    modal.classList.remove('active');
}

function executeDelete(appointmentId) {
    apiRequest('delete.php', 'POST', { appointment_id: appointmentId, hard_delete: true })
        .then(result => {
            if (result.success) {
                showAlert('success', result.message);
                // Sayfa yenilemeden satırı kaldır
                removeAppointmentRow(appointmentId);
            } else {
                showErrorModal(result.message);
            }
        })
        .catch(error => {
            console.error('Delete Error:', error);
            showErrorModal('Randevu silinirken bir hata oluştu.');
        });
}

// Global scope'a ekle
window.deleteAppointment = deleteAppointment;
window.closeDeleteModal = closeDeleteModal;

function executeBulkAction() {
    const action = document.getElementById('bulkActionSelect').value;
    const checkedBoxes = document.querySelectorAll('.appointment-checkbox:checked:not(#selectAllMobile)');

    if (!action) {
        showAlert('warning', 'Lütfen bir işlem seçin.');
        return;
    }

    if (checkedBoxes.length === 0) {
        showAlert('warning', 'Lütfen en az bir randevu seçin.');
        return;
    }

    const appointmentIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value));
    
    // API ile bulk status güncelleme
    apiRequest('bulk_status.php', 'POST', {
        appointment_ids: appointmentIds,
        new_status: action
    })
        .then(result => {
            if (result.success) {
                showAlert('success', result.message);
                // Sayfa yenilemeden tüm satırları güncelle
                appointmentIds.forEach(id => updateRowStatus(id, action));
                clearSelection();
                closeBulkActionModal();
            } else {
                showErrorModal(result.message);
            }
        })
        .catch(error => {
            console.error('Bulk Action Error:', error);
            showErrorModal('Toplu işlem sırasında bir hata oluştu.');
        });
}

function executeBulkDelete() {
    const checkedBoxes = document.querySelectorAll('.appointment-checkbox:checked:not(#selectAllMobile)');

    if (checkedBoxes.length === 0) {
        showAlert('warning', 'Silinecek randevu seçilmedi.');
        return;
    }

    const count = checkedBoxes.length;
    if (!confirm(`${count} randevu silinecek. Onaylıyor musunuz?`)) {
        return;
    }

    const appointmentIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value));
    
    // API ile bulk delete
    apiRequest('bulk_delete.php', 'POST', {
        appointment_ids: appointmentIds,
        permanent: false // Soft delete - iptal et
    })
        .then(result => {
            if (result.success) {
                showAlert('success', result.message);
                // Sayfa yenilemeden tüm satırları kaldır
                appointmentIds.forEach(id => removeAppointmentRow(id));
                clearSelection();
            } else {
                showErrorModal(result.message);
            }
        })
        .catch(error => {
            console.error('Bulk Delete Error:', error);
            showErrorModal('Toplu silme sırasında bir hata oluştu.');
        });
}

// Global scope'a ekle
window.executeBulkAction = executeBulkAction;
window.executeBulkDelete = executeBulkDelete;

// ---- YARDIMCI FONKSİYONLAR ----
function toggleSubmitButton(form, isLoading, loadingText = '...') {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (!submitBtn) return;
    const loading = submitBtn.querySelector('.loading');
    const btnText = submitBtn.querySelector('.btn-text');

    submitBtn.disabled = isLoading;
    if (isLoading) {
        loading.style.display = 'inline-block';
        btnText.textContent = loadingText;
    } else {
        loading.style.display = 'none';
        btnText.textContent = loadingText;
    }
}

function showAlert(type, message, duration = 5000) {
    // Yeni notification sistemini kullan
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, type, duration);
    } else {
        // Fallback: console log
        console.log(`[${type.toUpperCase()}]: ${message}`);
    }
}

function showExistingCustomerModal(data) {
    document.getElementById('confirmation-message-text').textContent = `Bu numara ${data.customer_name} adına kayıtlı. Bu kişi ile devam edilsin mi?`;
    document.getElementById('confirmation-message-text').dataset.name = data.customer_name;
    document.getElementById('customerConfirmationModal').classList.add('active');
}

function showErrorModal(message) {
    document.getElementById('error-message-text').textContent = message;
    document.getElementById('errorModal').classList.add('active');
}

// ---- TABLO SEÇİM İŞLEMLERİ ----
function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll') || document.getElementById('selectAllMobile');
    const isChecked = selectAll ? selectAll.checked : false;
    document.querySelectorAll('.appointment-checkbox:not(#selectAllMobile)').forEach(cb => cb.checked = isChecked);
    updateBulkActions();
}

function updateBulkActions() {
    const checkedBoxes = document.querySelectorAll('.appointment-checkbox:checked:not(#selectAllMobile)');
    const count = checkedBoxes.length;

    // Update row-selected class for all checkboxes
    document.querySelectorAll('.appointment-checkbox:not(#selectAllMobile)').forEach(cb => {
        const row = cb.closest('tr');
        if (row) {
            if (cb.checked) {
                row.classList.add('row-selected');
            } else {
                row.classList.remove('row-selected');
            }
        }
    });

    // Get button containers
    const headerButtons = document.getElementById('headerButtons');
    const bulkActionsControls = document.getElementById('bulkActionsControls');
    const selectedCount = document.getElementById('selectedCount');

    if (count > 0) {
        // Hide header buttons, show bulk actions
        if (headerButtons) headerButtons.style.display = 'none';
        if (bulkActionsControls) bulkActionsControls.style.display = 'flex';
        if (selectedCount) selectedCount.textContent = `${count} randevu seçildi`;
    } else {
        // Show header buttons, hide bulk actions
        if (headerButtons) headerButtons.style.display = 'flex';
        if (bulkActionsControls) bulkActionsControls.style.display = 'none';
    }

    // Update select all checkbox state
    const selectAll = document.getElementById('selectAllMobile');
    const totalCheckboxes = document.querySelectorAll('.appointment-checkbox:not(#selectAllMobile)').length;
    if (selectAll && totalCheckboxes > 0) {
        selectAll.checked = count === totalCheckboxes;
        selectAll.indeterminate = count > 0 && count < totalCheckboxes;
    }
}

// Bulk Action Modal fonksiyonları
function showBulkActionModal() {
    const checkedBoxes = document.querySelectorAll('.appointment-checkbox:checked:not(#selectAllMobile)');
    const count = checkedBoxes.length;

    if (count === 0) {
        if (typeof showNotification === 'function') {
            showNotification('Lütfen en az bir randevu seçin.', 'warning');
        } else {
            alert('Lütfen en az bir randevu seçin.');
        }
        return;
    }

    document.getElementById('bulkActionCount').textContent = `${count} randevu seçildi`;
    document.getElementById('bulkActionSelect').value = '';
    document.getElementById('bulkActionModal').style.display = 'flex';
}

function closeBulkActionModal() {
    const modal = document.getElementById('bulkActionModal');
    if (modal) modal.style.display = 'none';
}

function clearSelection() {
    document.querySelectorAll('.appointment-checkbox:checked').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAll');
    const selectAllMobile = document.getElementById('selectAllMobile');
    if (selectAll) selectAll.checked = false;
    if (selectAllMobile) selectAllMobile.checked = false;
    updateBulkActions();
}

// Global scope'a ekle
window.toggleSelectAll = toggleSelectAll;
window.updateBulkActions = updateBulkActions;
window.clearSelection = clearSelection;
window.showBulkActionModal = showBulkActionModal;
window.closeBulkActionModal = closeBulkActionModal;

// --- SIDEBAR FILTER CONTROLS ---
window.openFilterSidebar = function () {
    document.getElementById('filterSidebar').classList.add('active');
    document.getElementById('filterOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
};

window.closeFilterSidebar = function () {
    document.getElementById('filterSidebar').classList.remove('active');
    document.getElementById('filterOverlay').classList.remove('active');
    document.body.style.overflow = '';
};

// --- GEÇMIŞ TARİH MODAL ---
function showPastDateModal(originalFormData) {
    const modal = document.getElementById('pastDateModal');
    const confirmBtn = document.getElementById('confirmPastDateBtn');

    modal.classList.add('active');

    // Önceki event listener'ı kaldır
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

    // Yeni event listener ekle
    newConfirmBtn.addEventListener('click', function () {
        // confirm_past_date parametresini ekle
        originalFormData.append('confirm_past_date', '1');

        // Formu tekrar gönder
        const form = document.getElementById('appointmentForm');
        toggleSubmitButton(form, true, 'Kaydediliyor...');

        fetch('appointments.php', { method: 'POST', body: originalFormData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    closeAppointmentModal();
                    closePastDateModal();
                    if (data.reload) setTimeout(() => location.reload(), 1500);
                } else {
                    showErrorModal(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showErrorModal('Bir ağ hatası oluştu. Lütfen tekrar deneyin.');
            })
            .finally(() => {
                toggleSubmitButton(form, false, 'Kaydet');
            });
    });
}

function closePastDateModal() {
    const modal = document.getElementById('pastDateModal');
    modal.classList.remove('active');

    // Form butonunu tekrar aktif et
    const form = document.getElementById('appointmentForm');
    toggleSubmitButton(form, false, 'Kaydet');
}

// Aynı başlangıç saatinde çakışma → araya ekleme onayı
function showConflictBetweenConfirmModal(data, action, message) {
    const form = document.getElementById('appointmentForm');
    const msg = message || 'Bu saatte bir randevu var. Araya eklensin mi?';
    const modal = document.getElementById('generalConfirmationModal');
    const msgEl = document.getElementById('general-confirmation-message-text');
    const confirmBtn = document.getElementById('confirmGeneralActionBtn');

    const retry = () => {
        data.confirm_between = 1;
        data.is_between = 1;
        toggleSubmitButton(form, true, 'Kaydediliyor...');
        const endpoint = action === 'update' ? 'update.php' : 'create.php';
        apiRequest(endpoint, 'POST', data)
            .then(result => {
                if (result.success) {
                    showAlert('success', result.message);
                    closeAppointmentModal();
                    if (typeof closeGeneralConfirmationModal === 'function') {
                        closeGeneralConfirmationModal();
                    } else if (modal) {
                        modal.classList.remove('active');
                        modal.style.display = 'none';
                    }
                    if (result.recurring_count && result.recurring_count > 1) {
                        setTimeout(() => location.reload(), 800);
                    } else if (result.appointment) {
                        addAppointmentRow(result.appointment);
                    } else {
                        location.reload();
                    }
                } else {
                    showErrorModal(result.message || 'Bir hata oluştu');
                }
            })
            .catch(() => showErrorModal('Bir ağ hatası oluştu. Lütfen tekrar deneyin.'))
            .finally(() => toggleSubmitButton(form, false, 'Kaydet'));
    };

    if (!modal || !confirmBtn) {
        if (confirm(msg.replace(/<[^>]+>/g, ' '))) {
            retry();
        } else {
            toggleSubmitButton(form, false, 'Kaydet');
        }
        return;
    }

    if (msgEl) msgEl.textContent = msg.replace(/<[^>]+>/g, ' ');
    modal.classList.add('active');
    modal.style.display = 'flex';
    toggleSubmitButton(form, false, 'Kaydet');

    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    newConfirmBtn.addEventListener('click', retry);
}

window.showConflictBetweenConfirmModal = showConflictBetweenConfirmModal;

function closeGeneralConfirmationModal() {
    const modal = document.getElementById('generalConfirmationModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.style.display = 'none';
}
window.closeGeneralConfirmationModal = closeGeneralConfirmationModal;

// Yeni API için: aynı veriyi confirm_past_date=1 ile JSON olarak tekrar gönder
function showPastDateConfirmModal(data, action) {
    const modal = document.getElementById('pastDateModal');
    const confirmBtn = document.getElementById('confirmPastDateBtn');
    const form = document.getElementById('appointmentForm');

    if (!modal || !confirmBtn) {
        // Modal yoksa fallback: doğrudan confirm dialog
        if (confirm('Geçmiş tarihe randevu eklemek istediğinize emin misiniz?')) {
            data.confirm_past_date = 1;
            const endpoint = action === 'update' ? 'update.php' : 'create.php';
            apiRequest(endpoint, 'POST', data).then(result => {
                if (result.success) {
                    showAlert('success', result.message);
                    closeAppointmentModal();
                    location.reload();
                } else {
                    showErrorModal(result.message || 'Bir hata oluştu');
                }
            });
        }
        toggleSubmitButton(form, false, 'Kaydet');
        return;
    }

    modal.classList.add('active');
    toggleSubmitButton(form, false, 'Kaydet');

    // Eski listener'ı temizle
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

    newConfirmBtn.addEventListener('click', function () {
        data.confirm_past_date = 1;
        toggleSubmitButton(form, true, 'Kaydediliyor...');
        const endpoint = action === 'update' ? 'update.php' : 'create.php';

        apiRequest(endpoint, 'POST', data)
            .then(result => {
                if (result.success) {
                    showAlert('success', result.message);
                    closeAppointmentModal();
                    closePastDateModal();
                    if (result.recurring_count && result.recurring_count > 1) {
                        setTimeout(() => location.reload(), 800);
                    } else if (result.appointment) {
                        addAppointmentRow(result.appointment);
                    } else {
                        location.reload();
                    }
                } else {
                    showErrorModal(result.message || 'Bir hata oluştu');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showErrorModal('Bir ağ hatası oluştu. Lütfen tekrar deneyin.');
            })
            .finally(() => {
                toggleSubmitButton(form, false, 'Kaydet');
            });
    });
}

window.showPastDateModal = showPastDateModal;
window.closePastDateModal = closePastDateModal;
window.showPastDateConfirmModal = showPastDateConfirmModal;

// ============================================
// KAYDIRMALI TARİH SEÇİCİ - GERÇEK SLİDE ANİMASYONU
// Mobil uyumlu, performanslı navigasyon
// ============================================
// TARİH SEÇİCİ (Date Picker) - AJAX TABANLI
// ============================================

// Türkçe ay ve gün isimleri
const dayNamesTR = ["Paz", "Pzt", "Sal", "Çar", "Per", "Cum", "Cmt"];
const monthNamesTR = ["Ocak", "Şubat", "Mart", "Nisan", "Mayıs", "Haziran", "Temmuz", "Ağustos", "Eylül", "Ekim", "Kasım", "Aralık"];

// Global seçili tarih
let currentSelectedDate = null;

/**
 * Sayfa yüklendiğinde seçili tarihi belirle
 */
document.addEventListener('DOMContentLoaded', function () {
    const selectedDay = document.querySelector('.day.selected');
    if (selectedDay) {
        currentSelectedDate = new Date(selectedDay.dataset.date);
    }
});

/**
 * Klavye navigasyonu (ok tuşları)
 */
document.addEventListener('keydown', function (e) {
    // Sadece date picker sayfasındayken çalış
    if (!document.getElementById('daysTrack')) return;

    // Input alanındayken çalışmasın
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    if (e.key === 'ArrowLeft') {
        e.preventDefault();
        changeDatePickerDay(-1);
    } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        changeDatePickerDay(1);
    }
});

// Randevu Onayla (pending -> confirmed)
function confirmAppointmentAction(appointmentId) {
    if (!confirm('Bu randevuyu onaylamak istediğinizden emin misiniz?')) return;

    apiRequest('status.php', 'POST', { 
        appointment_id: appointmentId, 
        status: 'confirmed' 
    })
        .then(result => {
            if (result.success) {
                showAlert('success', result.message);
                // Sayfa yenilemeden durumu güncelle
                updateRowStatus(appointmentId, 'confirmed');
            } else {
                showErrorModal(result.message);
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showErrorModal('Bir hata oluştu');
        });
}



// Randevu İptal Et (pending/confirmed -> cancelled)
function cancelAppointmentAction(appointmentId) {
    if (!confirm('Bu randevuyu iptal etmek istediğinizden emin misiniz?')) return;

    apiRequest('delete.php', 'POST', { 
        appointment_id: appointmentId,
        cancellation_reason: 'Admin tarafından iptal edildi'
    })
        .then(result => {
            if (result.success) {
                showAlert('success', result.message);
                // Sayfa yenilemeden satırı kaldır veya durumu güncelle
                if (result.deleted) {
                    removeAppointmentRow(appointmentId);
                } else {
                    updateRowStatus(appointmentId, 'cancelled');
                }
            } else {
                showErrorModal(result.message);
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showErrorModal('Bir hata oluştu');
        });
}

// Mobil kart expand/collapse
function toggleMobileCard(event) {
    // Checkbox veya butonlara tıklanmışsa expand yapma
    if (event.target.closest('input[type="checkbox"]') ||
        event.target.closest('.action-buttons') ||
        event.target.closest('button') ||
        event.target.closest('a')) {
        return;
    }

    const row = event.target.closest('tr');
    if (row && window.innerWidth <= 768) {
        row.classList.toggle('expanded');
    }
}

// Sayfa yüklendiğinde mobil kartlara tıklama event'i ekle
document.addEventListener('DOMContentLoaded', function () {
    if (window.innerWidth <= 768) {
        document.querySelectorAll('.data-table tbody tr').forEach(row => {
            row.addEventListener('click', toggleMobileCard);
        });
    }

    // Pencere yeniden boyutlandırıldığında event'leri güncelle
    let resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            const rows = document.querySelectorAll('.data-table tbody tr');
            if (window.innerWidth <= 768) {
                rows.forEach(row => {
                    if (!row.hasAttribute('data-click-listener')) {
                        row.addEventListener('click', toggleMobileCard);
                        row.setAttribute('data-click-listener', 'true');
                    }
                });
            } else {
                rows.forEach(row => {
                    row.classList.remove('expanded');
                });
            }
        }, 250);
    });
});

// Global scope'a export et
window.slideToDate = slideToDate;
window.changeDatePickerDay = changeDatePickerDay;
window.goToday = goToday;
window.confirmAppointmentAction = confirmAppointmentAction;
window.cancelAppointmentAction = cancelAppointmentAction;
window.toggleMobileCard = toggleMobileCard;

// ---- YENİ SATIR EKLEME VE GÜNCELLEME FONKSİYONLARI ----

// Tarih formatla (dd.mm.yyyy)
function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// Saat formatla (HH:mm)
function formatTime(timeStr) {
    if (!timeStr) return '';
    return timeStr.substring(0, 5);
}

// Para formatla
function formatPrice(price) {
    const n = parseFloat(price || 0);
    if (n === Math.floor(n)) return n.toLocaleString('tr-TR') + ' ₺';
    return n.toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ₺';
}

// Yeni randevu satırı oluştur
function createAppointmentRowHTML(apt) {
    const status = apt.status || 'confirmed';
    const statusConfig = STATUS_CONFIG[status] || STATUS_CONFIG['confirmed'];
    const price = apt.total_price || apt.price || 0;
    
    // Aksiyon butonlarını duruma göre oluştur
    let actionsHTML = '';
    if (status === 'pending') {
        actionsHTML = `
            <button class="btn btn-primary btn-sm" onclick="confirmAppointmentAction(${apt.id})" title="Onayla">
                <i class="fas fa-check-circle"></i>
            </button>
            <button class="btn btn-danger btn-sm" onclick="cancelAppointmentAction(${apt.id})" title="İptal Et">
                <i class="fas fa-times-circle"></i>
            </button>
        `;
    } else if (status === 'confirmed') {
        actionsHTML = `
            <button class="btn btn-success btn-sm" onclick="completeAppointment(${apt.id}, ${price})" title="Tamamla">
                <i class="fas fa-check"></i>
            </button>
            <button class="btn btn-danger btn-sm" onclick="cancelAppointmentAction(${apt.id})" title="İptal Et">
                <i class="fas fa-times-circle"></i>
            </button>
            <button class="btn btn-warning btn-sm" onclick="editAppointmentById(${apt.id})" title="Düzenle">
                <i class="fas fa-edit"></i>
            </button>
        `;
    } else if (status === 'completed') {
        actionsHTML = `
            <button class="btn btn-warning btn-sm" onclick="editAppointmentById(${apt.id})" title="Düzenle">
                <i class="fas fa-edit"></i>
            </button>
            <button class="btn btn-danger btn-sm" onclick="deleteAppointment(${apt.id})" title="Sil">
                <i class="fas fa-trash"></i>
            </button>
        `;
    } else {
        actionsHTML = `
            <button class="btn btn-danger btn-sm" onclick="deleteAppointment(${apt.id})" title="Sil">
                <i class="fas fa-trash"></i>
            </button>
        `;
    }
    
    // Not kısmı
    const notesHTML = apt.notes ? `
        <br><small style="color: #6b7280;" title="${escapeHtml(apt.notes)}">
            <i class="fas fa-sticky-note"></i> 
            ${escapeHtml(apt.notes.substring(0, 30))}${apt.notes.length > 30 ? '...' : ''}
        </small>
    ` : '';
    
    const dialPhone = normalizePhoneForDial(apt.customer_phone);

    return `
        <tr data-id="${apt.id}" data-status="${status}">
            <td data-label="Seç"><input type="checkbox" class="appointment-checkbox" value="${apt.id}" onchange="updateBulkActions(this)"></td>
            <td data-label="Tarih/Saat">
                <div style="display:flex; flex-direction:column; align-items:flex-end; width: 100%;">
                    <strong style="color: var(--primary-color, #1e40af);">${formatDate(apt.appointment_date)}</strong>
                    <small style="color: var(--warning-color, #f59e0b); font-weight: 600;">${formatTime(apt.appointment_time)}</small>
                </div>
            </td>
            <td data-label="Müşteri">
                <strong style="color: var(--text-primary, #1f2937);">${escapeHtml(apt.customer_name)}</strong>
                ${notesHTML}
            </td>
            <td data-label="Telefon">
                <a href="tel:${escapeHtml(dialPhone)}" style="color: #8b5cf6; text-decoration: none; font-weight: 600;">
                    <i class="fas fa-phone"></i> ${escapeHtml(apt.customer_phone)}
                </a>
            </td>
            <td data-label="Çalışan"><strong style="color: var(--info-color, #3b82f6);">${escapeHtml(apt.employee_name || '')}</strong></td>
            <td data-label="Hizmet"><strong style="color: var(--kpi-2, #ec4899);">${escapeHtml(apt.service_name || apt.services || '')}</strong></td>
            <td data-label="Durum">
                <span class="badge ${statusConfig.class}">${statusConfig.label}</span>
            </td>
            <td data-label="Ücret"><strong style="color: var(--success-color, #10b981);">${formatPrice(price)}</strong></td>
            <td data-label="İşlemler">
                <div class="action-buttons">
                    ${actionsHTML}
                </div>
            </td>
        </tr>
    `;
}

// Arama için telefon numarasını normalize et (TR: başına 0 ekle)
function normalizePhoneForDial(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    if (!digits) return '';
    if (digits.length === 10) return '0' + digits;
    if (digits.length === 12 && digits.startsWith('90')) return '0' + digits.substring(2);
    return digits;
}

// HTML escape fonksiyonu
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Tabloya yeni satır ekle
function addAppointmentRow(appointment) {
    const tbody = document.getElementById('appointmentsTableBody');
    if (!tbody) {
        // Tablo yoksa sayfayı yenile
        location.reload();
        return;
    }
    
    // Boş durum mesajı varsa kaldır
    const emptyState = document.querySelector('.empty-state');
    if (emptyState) {
        emptyState.closest('.appointments-table-container').innerHTML = `
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                            <th>Tarih/Saat</th>
                            <th>Müşteri</th>
                            <th>Telefon</th>
                            <th>Çalışan</th>
                            <th>Hizmet</th>
                            <th>Durum</th>
                            <th>Ücret</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody id="appointmentsTableBody"></tbody>
                </table>
            </div>
        `;
    }
    
    const newTbody = document.getElementById('appointmentsTableBody');
    const rowHTML = createAppointmentRowHTML(appointment);
    
    // Satırı tablonun başına ekle
    newTbody.insertAdjacentHTML('afterbegin', rowHTML);
    
    // Yeni satırı vurgula
    const newRow = newTbody.querySelector(`tr[data-id="${appointment.id}"]`);
    if (newRow) {
        highlightRow(newRow);
        
        // Mobil event listener ekle
        if (window.innerWidth <= 768) {
            newRow.addEventListener('click', toggleMobileCard);
            newRow.setAttribute('data-click-listener', 'true');
        }
    }
}

// Mevcut satırı güncelle (düzenleme sonrası)
function updateAppointmentRowData(appointment) {
    const row = getAppointmentRow(appointment.id);
    if (!row) {
        // Satır bulunamadı, yeni ekle
        addAppointmentRow(appointment);
        return;
    }
    
    // Satırı yeni verilerle değiştir
    const newRowHTML = createAppointmentRowHTML(appointment);
    row.outerHTML = newRowHTML;
    
    // Yeni satırı bul ve vurgula
    const newRow = getAppointmentRow(appointment.id);
    if (newRow) {
        highlightRow(newRow);
        
        // Mobil event listener ekle
        if (window.innerWidth <= 768) {
            newRow.addEventListener('click', toggleMobileCard);
            newRow.setAttribute('data-click-listener', 'true');
        }
    }
}

// Global scope'a ekle
window.addAppointmentRow = addAppointmentRow;
window.updateAppointmentRowData = updateAppointmentRowData;
window.removeAppointmentRow = removeAppointmentRow;
window.updateRowStatus = updateRowStatus;
window.loadAppointments = loadAppointments;
window.renderAppointmentsTable = renderAppointmentsTable;
window.updateStats = updateStats;
window.createAppointmentRowHTML = createAppointmentRowHTML;

// ============================================
// TEKRARLAYAN RANDEVU FONKSİYONLARI
// ============================================

function toggleRecurringOptions() {
    const cb = document.getElementById('is_recurring');
    const opts = document.getElementById('recurringOptions');
    if (!cb || !opts) return;
    
    if (cb.checked) {
        opts.style.display = 'block';
        // Bitiş tarihi varsayılan: başlangıç + 4 hafta
        const startDate = document.getElementById('appointment_date').value;
        if (startDate && !document.getElementById('recurring_end_date').value) {
            const d = new Date(startDate);
            d.setDate(d.getDate() + 28);
            document.getElementById('recurring_end_date').value = d.toISOString().split('T')[0];
        }
        updateRecurringPreview();
    } else {
        opts.style.display = 'none';
        const preview = document.getElementById('recurringPreview');
        if (preview) preview.style.display = 'none';
    }
}

function toggleCustomDays() {
    const type = document.getElementById('recurring_type').value;
    const customDiv = document.getElementById('recurringCustomDays');
    if (customDiv) {
        customDiv.style.display = type === 'custom' ? 'block' : 'none';
    }
    updateRecurringPreview();
}

function calculateRecurringDates() {
    const startStr = document.getElementById('appointment_date').value;
    const endStr = document.getElementById('recurring_end_date').value;
    const type = document.getElementById('recurring_type').value;
    
    if (!startStr || !endStr) return [];
    
    const start = new Date(startStr);
    const end = new Date(endStr);
    if (isNaN(start) || isNaN(end) || start > end) return [];
    
    const dates = [];
    const maxDates = 180;
    
    if (type === 'daily') {
        let current = new Date(start);
        while (current <= end && dates.length < maxDates) {
            dates.push(new Date(current));
            current.setDate(current.getDate() + 1);
        }
    } else if (type === 'weekly') {
        let current = new Date(start);
        while (current <= end && dates.length < maxDates) {
            dates.push(new Date(current));
            current.setDate(current.getDate() + 7);
        }
    } else if (type === 'biweekly') {
        let current = new Date(start);
        while (current <= end && dates.length < maxDates) {
            dates.push(new Date(current));
            current.setDate(current.getDate() + 14);
        }
    } else if (type === 'monthly') {
        let current = new Date(start);
        while (current <= end && dates.length < maxDates) {
            dates.push(new Date(current));
            current.setMonth(current.getMonth() + 1);
        }
    } else if (type === 'custom') {
        const checked = document.querySelectorAll('input[name="recurring_days[]"]:checked');
        const selectedDays = Array.from(checked).map(c => parseInt(c.value));
        if (selectedDays.length === 0) return [];
        
        let current = new Date(start);
        while (current <= end && dates.length < maxDates) {
            // JS: 0=Pazar, 1=Pazartesi... → ISO: 1=Pzt..7=Paz
            const isoDay = current.getDay() === 0 ? 7 : current.getDay();
            if (selectedDays.includes(isoDay)) {
                dates.push(new Date(current));
            }
            current.setDate(current.getDate() + 1);
        }
    }
    
    return dates;
}

function updateRecurringPreview() {
    const cb = document.getElementById('is_recurring');
    if (!cb || !cb.checked) return;
    
    const dates = calculateRecurringDates();
    const preview = document.getElementById('recurringPreview');
    const countEl = document.getElementById('recurringCount');
    const datesEl = document.getElementById('recurringDates');
    
    if (!preview || !countEl || !datesEl) return;
    
    if (dates.length === 0) {
        preview.style.display = 'none';
        return;
    }
    
    preview.style.display = 'block';
    countEl.textContent = dates.length;
    
    const dayNames = ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'];
    const monthNames = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
    
    // İlk 12 tarih + gerisini "ve X tarih daha" olarak göster
    const showMax = 12;
    let html = '';
    dates.slice(0, showMax).forEach((d, i) => {
        const day = dayNames[d.getDay()];
        const dateStr = d.getDate() + ' ' + monthNames[d.getMonth()];
        html += '<span class="recurring-date-chip">' + day + ' ' + dateStr + '</span>';
    });
    
    if (dates.length > showMax) {
        html += '<span class="recurring-date-chip recurring-date-more">+' + (dates.length - showMax) + ' tarih daha</span>';
    }
    
    datesEl.innerHTML = html;
}

// Önizlemeyi güncelle: tarih/tip/gün değiştiğinde
document.addEventListener('DOMContentLoaded', function() {
    ['appointment_date', 'recurring_end_date', 'recurring_type'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', updateRecurringPreview);
    });
    // Özel gün chip'leri
    document.querySelectorAll('input[name="recurring_days[]"]').forEach(function(cb) {
        cb.addEventListener('change', updateRecurringPreview);
    });
});

window.toggleRecurringOptions = toggleRecurringOptions;
window.toggleCustomDays = toggleCustomDays;
window.updateRecurringPreview = updateRecurringPreview;

// ============================================
// ÜRÜN TÜKETİM ONAY (Product Consumption)
// ============================================
let _pcAllProducts = []; // Tüm ürünler (ürün ekleme için)

function loadProductConsumption(appointmentId) {
    const section = document.getElementById('productConsumptionSection');
    const list = document.getElementById('productConsumptionList');
    const countBadge = document.getElementById('productConsumptionCount');
    
    if (!section || !list) return;
    
    list.innerHTML = '<div style="text-align:center;padding:8px;color:#6b7280;"><i class="fas fa-spinner fa-spin"></i></div>';
    section.style.display = 'none';
    
    // Seçili hizmet ID'lerini al
    const serviceListContainer = document.getElementById('completeServiceList');
    const checkedIds = serviceListContainer 
        ? Array.from(serviceListContainer.querySelectorAll('.complete-service-checkbox:checked')).map(cb => cb.dataset.id)
        : [];
    
    let url;
    if (checkedIds.length > 0) {
        url = `ajax/get_appointment_products.php?service_ids=${checkedIds.join(',')}`;
    } else {
        url = `ajax/get_appointment_products.php?appointment_id=${appointmentId}`;
    }
    
    fetch(url, {
        headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;
        
        _pcAllProducts = data.all_products || [];
        const defaults = data.defaults || [];
        
        if (defaults.length === 0 && _pcAllProducts.length === 0) {
            section.style.display = 'none';
            return;
        }
        
        section.style.display = 'block';
        list.innerHTML = '';
        
        defaults.forEach(item => {
            addProductRow(list, item.product_id, item.name, parseFloat(item.total_quantity), parseInt(item.stock_quantity));
        });
        
        countBadge.textContent = defaults.length > 0 ? defaults.length : '';
        
        // Varsayılan olarak aç eğer ürün varsa
        if (defaults.length > 0) {
            document.getElementById('productConsumptionBody').style.display = 'block';
            document.getElementById('productConsumptionArrow').style.transform = 'rotate(180deg)';
        } else {
            document.getElementById('productConsumptionBody').style.display = 'none';
            document.getElementById('productConsumptionArrow').style.transform = 'rotate(0deg)';
        }
    })
    .catch(err => {
        console.error('Product consumption load error:', err);
        section.style.display = 'none';
    });
}

function addProductRow(container, productId, productName, quantity, stockQty) {
    const row = document.createElement('div');
    row.className = 'pc-row';
    row.dataset.productId = productId;
    
    const stockInfo = stockQty !== undefined ? ` <small class="pc-stock-info" style="color:${stockQty < quantity ? '#ef4444' : ''}">(Stok: ${stockQty})</small>` : '';
    
    row.innerHTML = `
        <div class="pc-name">
            ${escapeHtml(productName)}${stockInfo}
        </div>
        <div class="pc-controls">
            <button type="button" class="pc-btn" onclick="pcChangeQty(this, -1)">−</button>
            <input type="number" class="pc-qty" value="${quantity}" min="0.5" step="0.5" onchange="pcUpdateCount()">
            <button type="button" class="pc-btn" onclick="pcChangeQty(this, 1)">+</button>
        </div>
        <button type="button" class="pc-remove" onclick="pcRemoveRow(this)">
            <i class="fas fa-times"></i>
        </button>
    `;
    container.appendChild(row);
    pcUpdateCount();
}

function pcChangeQty(btn, delta) {
    const input = btn.parentElement.querySelector('.pc-qty');
    let val = parseFloat(input.value) || 1;
    val = Math.max(0.5, val + delta);
    input.value = val;
}

function pcRemoveRow(btn) {
    const row = btn.closest('.pc-row');
    row.remove();
    pcUpdateCount();
}

function pcUpdateCount() {
    const list = document.getElementById('productConsumptionList');
    const countBadge = document.getElementById('productConsumptionCount');
    if (!list || !countBadge) return;
    const rows = list.querySelectorAll('.pc-row');
    countBadge.textContent = rows.length > 0 ? rows.length : '';
}

// ══════════════════════════════════════════════════════
// ADMİN SEPET MODU — Birden fazla randevu aynı anda ekle
// ══════════════════════════════════════════════════════

let adminCart = [];         // [{employee_id, employee_name, service_ids, service_names, variant_data, appointment_date, appointment_time, status, notes, total_price, total_duration}]
let adminCartMode = false;

/** Sepet modunu aç / kapat */
function toggleAdminCartMode() {
    adminCartMode = !adminCartMode;
    const btn  = document.getElementById('cartModeToggleBtn');
    const lbl  = document.getElementById('cartModeToggleLbl');
    const panel = document.getElementById('adminCartPanel');
    const normalSave = document.getElementById('normalSaveBtn');
    const addToCart  = document.getElementById('addToCartBtn');
    const recurringSection = document.getElementById('recurringSection');

    if (adminCartMode) {
        btn.classList.replace('btn-outline-secondary', 'btn-warning');
        lbl.textContent = 'Sepet Modu Aktif';
        if (panel) panel.style.display = 'block';
        if (normalSave) normalSave.style.display = 'none';
        if (addToCart)  addToCart.style.display = '';
        // Tekrarlayan randevu sepet modunda desteklenmiyor
        if (recurringSection) recurringSection.style.display = 'none';
    } else {
        btn.classList.replace('btn-warning', 'btn-outline-secondary');
        lbl.textContent = 'Sepet Modu';
        if (panel) panel.style.display = 'none';
        if (normalSave) normalSave.style.display = '';
        if (addToCart)  addToCart.style.display = 'none';
        if (recurringSection) recurringSection.style.display = '';
        adminCart = [];
        renderAdminCart();
    }
}

/** Mevcut form alanlarını okuyup sepete ekle */
function addToAdminCart() {
    const form = document.getElementById('appointmentForm');
    const fd   = new FormData(form);

    const empId   = parseInt(fd.get('employee_id'));
    const date    = fd.get('appointment_date');
    const time    = fd.get('appointment_time');
    const status  = fd.get('status') || 'confirmed';
    const notes   = fd.get('notes') || '';
    const svcIds  = fd.getAll('service_ids[]').map(Number).filter(Boolean);

    // Çalışan adını seçili option'dan al
    const empSelect = document.getElementById('employee_id');
    const empName   = empSelect?.options[empSelect?.selectedIndex]?.text || '';

    // Hizmet adlarını checkbox label'larından al
    const svcNames = [];
    form.querySelectorAll('input[name="service_ids[]"]:checked').forEach(cb => {
        const label = cb.closest('label');
        if (label) svcNames.push(label.textContent.trim().split(' - ')[0]);
    });

    // Varyant verisini topla
    const variantData = {};
    form.querySelectorAll('input[name="service_ids[]"]:checked').forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const wrapper = cb.closest('.service-item-wrapper');
            const selected = Array.from(wrapper?.querySelectorAll('.variant-input:checked') || []).map(v => ({
                id: parseInt(v.value),
                extra_price: parseFloat(v.dataset.extraPrice || 0),
                extra_duration: parseInt(v.dataset.extraDuration || 0)
            }));
            if (selected.length) variantData[cb.value] = selected;
        }
    });

    // Fiyat/süre hesapla
    let totalPrice = 0, totalDuration = 0;
    form.querySelectorAll('input[name="service_ids[]"]:checked').forEach(cb => {
        const price = parseFloat(cb.dataset.price || 0);
        const sv    = variantData[cb.value] || [];
        const ep    = sv.reduce((s, v) => s + (v.extra_price || 0), 0);
        totalPrice += price + ep;
        // Süre hesabı için servisler objesinden çek
        const svc = (window.appointmentsData?.services || []).find(s => s.id == cb.value);
        const ed  = sv.reduce((s, v) => s + (v.extra_duration || 0), 0);
        totalDuration += (svc?.duration || 0) + ed;
    });

    // Validasyon
    if (!empId)           { showErrorModal('Çalışan seçiniz');                      return; }
    if (!svcIds.length)   { showErrorModal('En az bir hizmet seçiniz');              return; }
    if (!date)            { showErrorModal('Tarih seçiniz');                         return; }
    if (!time)            { showErrorModal('Saat seçiniz');                          return; }

    adminCart.push({ employee_id: empId, employee_name: empName, service_ids: svcIds, service_names: svcNames, variant_data: variantData, appointment_date: date, appointment_time: time, status, notes, total_price: totalPrice, total_duration: totalDuration });

    renderAdminCart();

    // Formu kısmen temizle (müşteri bilgisi korunur, sadece randevu alanları sıfırlanır)
    document.getElementById('employee_id').value = '';
    document.getElementById('appointment_time').value = '';
    form.querySelectorAll('input[name="service_ids[]"]').forEach(cb => {
        cb.checked = false;
        const wrapper = cb.closest('.service-item-wrapper');
        if (wrapper) {
            const rangeDiv = wrapper.querySelector('.edit-range-input');
            if (rangeDiv) rangeDiv.style.display = 'none';
            const vPanel  = wrapper.querySelector('.variant-panel');
            if (vPanel)   vPanel.style.display = 'none';
        }
    });
    if (typeof updateTotalPrice === 'function') updateTotalPrice();

    showAlert('success', `Randevu sepete eklendi (toplam: ${adminCart.length})`);
}

/** Sepeti ekrana yansıt */
function renderAdminCart() {
    const container = document.getElementById('adminCartItems');
    const countEl   = document.getElementById('adminCartCount');
    const btnEl     = document.getElementById('submitCartBtn');
    const btnCnt    = document.getElementById('cartBtnCount');
    const totalEl   = document.getElementById('adminCartTotal');

    if (!container) return;

    if (countEl)  countEl.textContent = adminCart.length;
    if (btnCnt)   btnCnt.textContent  = adminCart.length;
    if (btnEl)    btnEl.style.display = adminCart.length > 0 ? '' : 'none';

    if (adminCart.length === 0) {
        container.innerHTML = '<div class="admin-cart-empty">Sepet boş. Formu doldurup "Sepete Ekle" butonuna tıklayın.</div>';
        if (totalEl) totalEl.textContent = '';
        return;
    }

    const grandTotal = adminCart.reduce((s, i) => s + i.total_price, 0);
    if (totalEl) totalEl.textContent = 'Toplam: ' + grandTotal.toFixed(2) + ' ₺';

    container.innerHTML = adminCart.map((item, idx) => `
        <div class="admin-cart-item">
            <div class="admin-cart-item-body">
                <div class="admin-cart-item-emp">${escapeHtmlCart(item.employee_name)}</div>
                <div class="admin-cart-item-svc">${escapeHtmlCart(item.service_names.join(', '))}</div>
                <div class="admin-cart-item-meta">
                    <i class="fas fa-calendar-alt"></i> ${formatDateCart(item.appointment_date)} &nbsp;
                    <i class="fas fa-clock"></i> ${item.appointment_time}
                    ${item.total_price > 0 ? `&nbsp; <i class="fas fa-tag"></i> ${item.total_price.toFixed(2)} ₺` : ''}
                </div>
                ${item.notes ? `<div class="admin-cart-item-notes">${escapeHtmlCart(item.notes)}</div>` : ''}
            </div>
            <button type="button" class="admin-cart-item-remove" onclick="removeFromAdminCart(${idx})" title="Kaldır"><i class="fas fa-times"></i></button>
        </div>
    `).join('');
}

function escapeHtmlCart(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatDateCart(dateStr) {
    if (!dateStr) return '';
    const [y,m,d] = dateStr.split('-');
    return `${d}.${m}.${y}`;
}

/** Sepetten item sil */
function removeFromAdminCart(idx) {
    adminCart.splice(idx, 1);
    renderAdminCart();
}

/** Sepeti tamamen temizle */
function clearAdminCart() {
    adminCart = [];
    renderAdminCart();
}

/** Sepetteki tüm randevuları batch olarak gönder */
async function submitAdminCart() {
    if (adminCart.length === 0) { showErrorModal('Sepet boş'); return; }

    const form    = document.getElementById('appointmentForm');
    const custName  = document.getElementById('customer_name')?.value?.trim();
    const custPhone = document.getElementById('customer_phone')?.value?.trim();

    if (!custName)  { showErrorModal('Müşteri adını giriniz');         return; }
    if (!custPhone) { showErrorModal('Müşteri telefonunu giriniz');    return; }

    const spinner = document.getElementById('cartLoadingSpinner');
    const btn     = document.getElementById('submitCartBtn');
    if (spinner) spinner.style.display = '';
    if (btn)     btn.disabled = true;

    const sendSms = !!form?.querySelector('[name="send_customer_sms"]')?.checked;
    if (sendSms && typeof window.warnSmsIfUnavailable === 'function') {
        window.warnSmsIfUnavailable(true);
    }

    const payload = {
        customer_name:     custName,
        customer_phone:    custPhone,
        send_customer_sms: sendSms ? 1 : 0,
        items: adminCart.map(item => ({
            employee_id:      item.employee_id,
            service_ids:      item.service_ids,
            variant_data:     item.variant_data,
            appointment_date: item.appointment_date,
            appointment_time: item.appointment_time,
            status:           item.status,
            notes:            item.notes,
        }))
    };

    try {
        const res = await apiRequest('batch_create.php', 'POST', payload);
        if (res.success) {
            showAlert('success', res.message);
            if (typeof window.notifySmsOutcome === 'function') {
                window.notifySmsOutcome(res);
            } else if (res.sms_warning) {
                showAlert('warning', res.sms_warning, 7000);
            }
            closeAppointmentModal();
            // Listeyi yenile
            if (typeof loadAppointments === 'function') {
                loadAppointments(currentFilters);
            } else {
                setTimeout(() => location.reload(), 600);
            }
        } else {
            showErrorModal(res.message || 'Hata oluştu');
        }
    } catch (e) {
        showErrorModal('Ağ hatası. Lütfen tekrar deneyin.');
    } finally {
        if (spinner) spinner.style.display = 'none';
        if (btn)     btn.disabled = false;
    }
}

window.toggleAdminCartMode  = toggleAdminCartMode;
window.addToAdminCart       = addToAdminCart;
window.removeFromAdminCart  = removeFromAdminCart;
window.clearAdminCart       = clearAdminCart;
window.submitAdminCart      = submitAdminCart;

function toggleProductConsumption() {
    const body = document.getElementById('productConsumptionBody');
    const arrow = document.getElementById('productConsumptionArrow');
    if (!body) return;
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : 'block';
    if (arrow) arrow.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}

function addProductToConsumption() {
    if (_pcAllProducts.length === 0) {
        if (typeof showErrorModal === 'function') showErrorModal('Aktif ürün bulunamadı');
        else if (typeof _notify === 'function') _notify('Aktif ürün bulunamadı', 'warning');
        return;
    }
    
    const list = document.getElementById('productConsumptionList');
    const existingIds = Array.from(list.querySelectorAll('.pc-row')).map(r => r.dataset.productId);
    const available = _pcAllProducts.filter(p => !existingIds.includes(p.id.toString()));
    
    if (available.length === 0) {
        if (typeof showErrorModal === 'function') showErrorModal('Tüm ürünler zaten ekli');
        else if (typeof _notify === 'function') _notify('Tüm ürünler zaten ekli', 'warning');
        return;
    }
    
    _showProductPickerModal(available, function(productId, quantity) {
        const prod = available.find(p => p.id.toString() === productId.toString());
        if (prod) {
            addProductRow(list, prod.id, prod.name, quantity, parseInt(prod.stock_quantity));
        }
    });
}

function collectProductConsumption() {
    const list = document.getElementById('productConsumptionList');
    if (!list) return [];
    
    const products = [];
    list.querySelectorAll('.pc-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.pc-qty')?.value) || 0;
        if (qty > 0) {
            products.push({
                product_id: parseInt(row.dataset.productId),
                quantity: qty
            });
        }
    });
    return products;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function _showProductPickerModal(products, onSelect) {
    // Remove existing modal if any
    const existing = document.getElementById('ppModal');
    if (existing) existing.remove();

    const isDark = document.body.classList.contains('dark-mode');

    const overlay = document.createElement('div');
    overlay.id = 'ppModal';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);';

    const modal = document.createElement('div');
    modal.style.cssText = `background:${isDark ? '#1e293b' : '#fff'};border-radius:16px;width:90%;max-width:420px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3);border:2px solid ${isDark ? '#334155' : '#e2e8f0'};`;

    // Header
    const header = document.createElement('div');
    header.style.cssText = `padding:16px 20px;border-bottom:1px solid ${isDark ? '#334155' : '#e2e8f0'};`;
    header.innerHTML = `<div style="font-weight:700;font-size:1.1rem;color:${isDark ? '#f1f5f9' : '#1f2937'};margin-bottom:10px;">Ürün Seç</div>`;

    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Ürün ara...';
    searchInput.style.cssText = `width:100%;padding:8px 12px;border:2px solid ${isDark ? '#475569' : '#e2e8f0'};border-radius:8px;font-size:0.9rem;outline:none;background:${isDark ? '#0f172a' : '#fff'};color:${isDark ? '#f1f5f9' : '#1f2937'};box-sizing:border-box;`;
    header.appendChild(searchInput);
    modal.appendChild(header);

    // Product list
    const listContainer = document.createElement('div');
    listContainer.style.cssText = 'flex:1;overflow-y:auto;padding:8px 0;';

    products.forEach(p => {
        const stockQty = parseInt(p.stock_quantity) || 0;
        const stockColor = stockQty <= 0 ? '#ef4444' : stockQty <= 5 ? '#f59e0b' : '#10b981';
        const item = document.createElement('label');
        item.className = 'pp-item';
        item.dataset.name = p.name.toLowerCase();
        item.style.cssText = `display:flex;align-items:center;gap:10px;padding:10px 20px;cursor:pointer;transition:background 0.15s;`;
        item.innerHTML = `
            <input type="radio" name="ppProduct" value="${p.id}" style="width:18px;height:18px;accent-color:#3b82f6;flex-shrink:0;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:0.9rem;color:${isDark ? '#f1f5f9' : '#1f2937'};">${escapeHtml(p.name)}</div>
                <div style="font-size:0.75rem;color:${stockColor};">Stok: ${stockQty}</div>
            </div>`;
        item.addEventListener('mouseover', () => item.style.background = isDark ? '#334155' : '#f8fafc');
        item.addEventListener('mouseout', () => item.style.background = 'transparent');
        listContainer.appendChild(item);
    });
    modal.appendChild(listContainer);

    // Footer
    const footer = document.createElement('div');
    footer.style.cssText = `padding:14px 20px;border-top:1px solid ${isDark ? '#334155' : '#e2e8f0'};display:flex;align-items:center;gap:10px;justify-content:space-between;`;

    const qtyWrap = document.createElement('div');
    qtyWrap.style.cssText = 'display:flex;align-items:center;gap:6px;';
    qtyWrap.innerHTML = `<span style="font-weight:600;font-size:0.85rem;color:${isDark ? '#94a3b8' : '#64748b'};">Adet:</span>`;
    const qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.value = '1';
    qtyInput.min = '0.5';
    qtyInput.step = '0.5';
    qtyInput.style.cssText = `width:70px;padding:6px 8px;border:2px solid ${isDark ? '#475569' : '#e2e8f0'};border-radius:8px;font-size:0.9rem;text-align:center;outline:none;background:${isDark ? '#0f172a' : '#fff'};color:${isDark ? '#f1f5f9' : '#1f2937'};`;
    qtyWrap.appendChild(qtyInput);
    footer.appendChild(qtyWrap);

    const btnWrap = document.createElement('div');
    btnWrap.style.cssText = 'display:flex;gap:8px;';

    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.textContent = 'İptal';
    cancelBtn.style.cssText = `padding:8px 16px;border-radius:8px;border:2px solid ${isDark ? '#475569' : '#e2e8f0'};background:transparent;color:${isDark ? '#94a3b8' : '#64748b'};font-weight:600;font-size:0.85rem;cursor:pointer;`;
    cancelBtn.onclick = () => overlay.remove();

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.innerHTML = '<i class="fas fa-plus"></i> Ekle';
    addBtn.style.cssText = 'padding:8px 16px;border-radius:8px;border:none;background:#3b82f6;color:#fff;font-weight:600;font-size:0.85rem;cursor:pointer;';
    addBtn.onclick = () => {
        const selected = modal.querySelector('input[name="ppProduct"]:checked');
        if (!selected) {
            searchInput.style.borderColor = '#ef4444';
            searchInput.placeholder = 'Lütfen bir ürün seçin!';
            setTimeout(() => { searchInput.style.borderColor = isDark ? '#475569' : '#e2e8f0'; searchInput.placeholder = 'Ürün ara...'; }, 1500);
            return;
        }
        const qty = parseFloat(qtyInput.value) || 1;
        if (qty <= 0) { qtyInput.style.borderColor = '#ef4444'; return; }
        overlay.remove();
        onSelect(selected.value, qty);
    };

    btnWrap.appendChild(cancelBtn);
    btnWrap.appendChild(addBtn);
    footer.appendChild(btnWrap);
    modal.appendChild(footer);

    // Search filtering
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.toLowerCase();
        listContainer.querySelectorAll('.pp-item').forEach(el => {
            el.style.display = el.dataset.name.includes(q) ? 'flex' : 'none';
        });
    });

    // Close on overlay click
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    // Close on Escape
    const escHandler = e => { if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); } };
    document.addEventListener('keydown', escHandler);

    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    setTimeout(() => searchInput.focus(), 100);
}

// Global scope
window.loadProductConsumption = loadProductConsumption;
window.toggleProductConsumption = toggleProductConsumption;
window.addProductToConsumption = addProductToConsumption;
window.collectProductConsumption = collectProductConsumption;
window.pcChangeQty = pcChangeQty;
window.pcRemoveRow = pcRemoveRow;
window.pcUpdateCount = pcUpdateCount;
