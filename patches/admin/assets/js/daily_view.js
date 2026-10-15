/**
 * Daily View - AJAX/API JavaScript
 * Tüm işlemler API üzerinden gerçekleştirilir, sayfa yenilenmez
 */

function getPaymentMethodCssClass(paymentMethod, paidAmount = 0) {
    if (paidAmount > 0 && paymentMethod !== 'not_paid') {
        return ' not-paid';
    }
    switch (paymentMethod) {
        case 'cash': return ' pm-cash';
        case 'credit_card': return ' pm-card';
        case 'bank_transfer': return ' pm-bank';
        case 'not_paid': return ' not-paid';
        default: return '';
    }
}

// --- BİLDİRİM SİSTEMİ (Self-contained) ---
// Harici notification.js/css'e bağımlılık YOK
// Tüm CSS daily_view.css'te, tüm JS burada

// Eski notification container'ları temizle (varsa)
(function() {
    var oldContainers = document.querySelectorAll('.notification-container, ul.notifications, .toast-container');
    oldContainers.forEach(function(el) { el.remove(); });
    // Eski notification CSS'i devre dışı bırak
    var links = document.querySelectorAll('link[href*="notification.css"]');
    links.forEach(function(link) { link.disabled = true; link.remove(); });
})();

const _dvNotifyIcons = {
    success: '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.5 11.5 11 14l4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"></path></svg>',
    info: '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 11h2v5m-2 0h4m-2.592-8.5h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"></path></svg>',
    warning: '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 13V8m0 8h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"></path></svg>',
    error: '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 9-6 6m0-6 6 6m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"></path></svg>',
    close: '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 17.94 6M18 18 6.06 6"></path></svg>'
};

function _notify(message, type = 'info', duration = 3000) {
    let container = document.querySelector('.dv-notification-container');
    if (!container) {
        container = document.createElement('ul');
        container.className = 'dv-notification-container';
        document.body.appendChild(container);
    }

    const notification = document.createElement('li');
    notification.className = 'dv-notification-item ' + type;
    const icon = _dvNotifyIcons[type] || _dvNotifyIcons.info;
    notification.innerHTML =
        '<div class="dv-notification-content">' +
            '<div class="dv-notification-icon">' + icon + '</div>' +
            '<div class="dv-notification-text">' + message + '</div>' +
        '</div>' +
        '<div class="dv-notification-icon dv-notification-close">' + _dvNotifyIcons.close + '</div>' +
        '<div class="dv-notification-progress-bar" style="animation-duration:' + duration + 'ms;"></div>';

    container.appendChild(notification);

    notification.querySelector('.dv-notification-close').addEventListener('click', function() {
        _dvRemoveNotification(notification);
    });

    if (duration > 0) {
        setTimeout(function() { _dvRemoveNotification(notification); }, duration);
    }
    return notification;
}

function _dvRemoveNotification(el) {
    if (!el || !el.parentElement) return;
    el.classList.add('removing');
    setTimeout(function() {
        if (el.parentElement) el.remove();
        var c = document.querySelector('.dv-notification-container');
        if (c && c.children.length === 0) c.remove();
    }, 300);
}

// Footer.php ve diğer scriptler için global erişim
window.showNotification = _notify;
window.showSuccess = function(msg) { _notify(msg, 'success'); };
window.showError = function(msg) { _notify(msg, 'error'); };
window.showWarning = function(msg) { _notify(msg, 'warning'); };
window.showInfo = function(msg) { _notify(msg, 'info'); };

// Arama için telefon numarasını normalize et (TR: başına 0 ekle)
function normalizePhoneForDial(phone) {
    const digits = String(phone || '').replace(/\D/g, '');
    if (!digits) return '';
    if (digits.length === 10) return '0' + digits;
    if (digits.length === 12 && digits.startsWith('90')) return '0' + digits.substring(2);
    return digits;
}

// ============================================
// RANDEVU BİLDİRİM KUYRUĞU (QUEUE SYSTEM) - daily_view self-contained
// ============================================
var _apptNotifQueue = [];

// Gösterilen bildirim ID'lerini sessionStorage'da tut
function _getShownApptIdsDV() {
    try {
        var raw = sessionStorage.getItem('_shownApptIds');
        return raw ? JSON.parse(raw) : [];
    } catch(e) { return []; }
}
function _addShownApptIdDV(id) {
    try {
        var ids = _getShownApptIdsDV();
        if (ids.indexOf(id) === -1) {
            ids.push(id);
            if (ids.length > 200) ids = ids.slice(-100);
            sessionStorage.setItem('_shownApptIds', JSON.stringify(ids));
        }
    } catch(e) {}
}
var _apptNotifShowing = false;

function _buildDetailRowsDV(details) {
    var dateStr = '';
    if (details.date) {
        var p = details.date.split('-');
        dateStr = p.length === 3 ? p[2]+'.'+p[1]+'.'+p[0] : details.date;
    }
    var timeStr = details.time ? details.time.substring(0,5) : '';
    var employeeStr = details.employee || '';
    var servicesStr = details.services || '';
    var rows = '';
    if (dateStr) {
        rows += '<div class="appt-notif-detail-row">' +
            '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 10h16M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01M5 6h14a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Zm3-2v2M16 4v2"></path></svg>' +
            '<span class="appt-notif-detail-label">Tarih</span>' +
            '<strong>' + dateStr + '</strong></div>';
    }
    if (timeStr) {
        rows += '<div class="appt-notif-detail-row">' +
            '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"></path></svg>' +
            '<span class="appt-notif-detail-label">Saat</span>' +
            '<strong>' + timeStr + '</strong></div>';
    }
    if (employeeStr) {
        rows += '<div class="appt-notif-detail-row">' +
            '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7Z"></path></svg>' +
            '<span class="appt-notif-detail-label">Personel</span>' +
            '<strong>' + employeeStr + '</strong></div>';
    }
    if (servicesStr) {
        rows += '<div class="appt-notif-detail-row">' +
            '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.143 4H4.857A.857.857 0 0 0 4 4.857v4.286c0 .473.384.857.857.857h4.286A.857.857 0 0 0 10 9.143V4.857A.857.857 0 0 0 9.143 4Zm10 0h-4.286a.857.857 0 0 0-.857.857v4.286c0 .473.384.857.857.857h4.286A.857.857 0 0 0 20 9.143V4.857A.857.857 0 0 0 19.143 4Zm-10 10H4.857a.857.857 0 0 0-.857.857v4.286c0 .473.384.857.857.857h4.286a.857.857 0 0 0 .857-.857v-4.286A.857.857 0 0 0 9.143 14Zm10 0h-4.286a.857.857 0 0 0-.857.857v4.286c0 .473.384.857.857.857h4.286a.857.857 0 0 0 .857-.857v-4.286a.857.857 0 0 0-.857-.857Z"></path></svg>' +
            '<span class="appt-notif-detail-label">Hizmet</span>' +
            '<strong>' + servicesStr + '</strong></div>';
    }
    return rows;
}

function _playLoudNotifSound() {
    try {
        var AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (AudioCtx) {
            var ctx = new AudioCtx();
            var audio = new Audio('assets/notification/notification.mp3');
            audio.crossOrigin = 'anonymous';
            var source = ctx.createMediaElementSource(audio);
            var gain = ctx.createGain();
            gain.gain.value = 2.0; // %200 ses seviyesi
            source.connect(gain);
            gain.connect(ctx.destination);
            audio.play().catch(function(){});
            audio.addEventListener('ended', function() { ctx.close(); });
        } else {
            var audio = new Audio('assets/notification/notification.mp3');
            audio.volume = 1.0;
            audio.play().catch(function(){});
        }
    } catch(e) {}
}

function _getApptApiBaseUrlDV() {
    try {
        var loc = window.location.pathname;
        var adminIdx = loc.indexOf('/admin/');
        if (adminIdx !== -1) return loc.substring(0, adminIdx) + '/admin/api/appointments/';
    } catch(e) {}
    return 'api/appointments/';
}

function confirmAppointmentFromNotif(appointmentId) {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.content : '';
    var btn = document.querySelector('.appt-notif-btn-confirm');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }

    fetch(_getApptApiBaseUrlDV() + 'status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: JSON.stringify({ appointment_id: appointmentId, status: 'confirmed' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { _notify('Randevu onaylandı!', 'success'); }
        else { _notify(data.message || 'Onaylama başarısız', 'error'); }
        closeAppointmentNotification();
        if (typeof window.loadDailyView === 'function') window.loadDailyView();
    })
    .catch(function() { _notify('Bir hata oluştu', 'error'); closeAppointmentNotification(); });
}

function cancelAppointmentFromNotif(appointmentId) {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.content : '';
    var btn = document.querySelector('.appt-notif-btn-cancel');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }

    fetch(_getApptApiBaseUrlDV() + 'delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: JSON.stringify({ appointment_id: appointmentId, cancellation_reason: 'Bildirimden iptal edildi' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) { _notify('Randevu iptal edildi', 'success'); }
        else { _notify(data.message || 'İptal başarısız', 'error'); }
        closeAppointmentNotification();
        if (typeof window.loadDailyView === 'function') window.loadDailyView();
    })
    .catch(function() { _notify('Bir hata oluştu', 'error'); closeAppointmentNotification(); });
}

function _renderNextNotificationDV() {
    if (_apptNotifQueue.length === 0) {
        _apptNotifShowing = false;
        var ov = document.querySelector('.appt-notif-overlay');
        if (ov) { ov.classList.add('removing'); setTimeout(function(){ if (ov.parentElement) ov.remove(); }, 300); }
        return;
    }
    _apptNotifShowing = true;
    var item = _apptNotifQueue[0];
    var remaining = _apptNotifQueue.length;
    var initial = (item.customerName || 'M').charAt(0).toUpperCase();
    var phoneStr = item.details.phone || '';
    var detailRows = _buildDetailRowsDV(item.details);

    var overlay = document.querySelector('.appt-notif-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'appt-notif-overlay';
        overlay.addEventListener('click', closeAppointmentNotification);
        document.body.appendChild(overlay);
    }

    var oldCard = document.querySelector('.appt-notif-card');
    if (oldCard) oldCard.remove();

    var card = document.createElement('div');
    card.className = 'appt-notif-card';
    card.innerHTML =
        '<div class="appt-notif-header">' +
            '<button class="appt-notif-close" onclick="closeAppointmentNotification()">' +
                '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 17.94 6M18 18 6.06 6"></path></svg>' +
                (remaining > 1 ? '<span class="appt-notif-close-badge">' + (remaining - 1) + '</span>' : '') +
            '</button>' +
            '<div class="appt-notif-bell">' +
                '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5.365V3m0 2.365a5.338 5.338 0 0 1 5.133 5.368v1.8c0 2.386 1.867 2.982 1.867 4.175 0 .593 0 1.292-.538 1.292H5.538C5 18 5 17.301 5 16.708c0-1.193 1.867-1.789 1.867-4.175v-1.8A5.338 5.338 0 0 1 12 5.365ZM8.733 18c.094.852.306 1.54.944 2.112a3.48 3.48 0 0 0 4.646 0c.638-.572 1.236-1.26 1.33-2.112h-6.92Z"></path></svg>' +
                '<span class="appt-notif-badge">' + remaining + '</span>' +
            '</div>' +
            '<h3 class="appt-notif-title">' + (remaining > 1 ? remaining + ' Yeni Randevu!' : 'Yeni Randevu!') + '</h3>' +
            '<p class="appt-notif-subtitle">' + (remaining > 1 ? 'Sağ üst ✕ ile sonrakine geçebilirsiniz' : 'Az önce yeni bir randevu alındı') + '</p>' +
        '</div>' +
        '<div class="appt-notif-body">' +
            '<div class="appt-notif-customer">' +
                '<div class="appt-notif-avatar">' + initial + '</div>' +
                '<div class="appt-notif-customer-info">' +
                    '<p class="appt-notif-customer-name">' + (item.customerName || 'Misafir') + '</p>' +
                    (phoneStr ? '<p class="appt-notif-customer-phone">' + phoneStr + '</p>' : '') +
                '</div>' +
            '</div>' +
            (detailRows ? '<div class="appt-notif-details">' + detailRows + '</div>' : '') +
            '<div class="appt-notif-actions">' +
                '<button class="appt-notif-btn appt-notif-btn-confirm" onclick="confirmAppointmentFromNotif(' + item.appointmentId + ')">' +
                    '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>' +
                    'Onayla</button>' +
                '<button class="appt-notif-btn appt-notif-btn-cancel" onclick="cancelAppointmentFromNotif(' + item.appointmentId + ')">' +
                    '<svg fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>' +
                    'İptal Et</button>' +
            '</div>' +
        '</div>';

    document.body.appendChild(card);
}

function showAppointmentNotification(appointmentId, customerName, count, details) {
    details = details || {};
    // Duplicate kontrolü (kuyruk + sessionStorage)
    var isDuplicate = false;
    for (var i = 0; i < _apptNotifQueue.length; i++) {
        if (_apptNotifQueue[i].appointmentId === appointmentId) { isDuplicate = true; break; }
    }
    if (isDuplicate) return;
    var shownIds = _getShownApptIdsDV();
    if (shownIds.indexOf(appointmentId) !== -1) return;
    _addShownApptIdDV(appointmentId);

    _apptNotifQueue.push({
        appointmentId: appointmentId,
        customerName: customerName || 'Misafir',
        details: details
    });

    if (_apptNotifShowing) {
        // Mevcut kartın badge'ini güncelle
        var badge = document.querySelector('.appt-notif-card .appt-notif-badge');
        var title = document.querySelector('.appt-notif-card .appt-notif-title');
        var subtitle = document.querySelector('.appt-notif-card .appt-notif-subtitle');
        var closeBadge = document.querySelector('.appt-notif-card .appt-notif-close-badge');
        var rem = _apptNotifQueue.length;
        if (badge) badge.textContent = rem;
        if (title) title.textContent = rem + ' Yeni Randevu!';
        if (subtitle) subtitle.textContent = 'Sağ üst ✕ ile sonrakine geçebilirsiniz';
        if (closeBadge) { closeBadge.textContent = rem - 1; } else {
            var closeBtn = document.querySelector('.appt-notif-card .appt-notif-close');
            if (closeBtn) { var b = document.createElement('span'); b.className = 'appt-notif-close-badge'; b.textContent = rem - 1; closeBtn.appendChild(b); }
        }
        // Ses çal
        _playLoudNotifSound();
        return;
    }

    _renderNextNotificationDV();
    _playLoudNotifSound();
}

function closeAppointmentNotification() {
    var card = document.querySelector('.appt-notif-card');
    if (card) {
        card.classList.add('removing');
        setTimeout(function() {
            if (card.parentElement) card.remove();
            if (_apptNotifQueue.length > 0) _apptNotifQueue.shift();
            if (_apptNotifQueue.length > 0) {
                _renderNextNotificationDV();
            } else {
                _apptNotifShowing = false;
                var ov = document.querySelector('.appt-notif-overlay');
                if (ov) { ov.classList.add('removing'); setTimeout(function(){ if (ov.parentElement) ov.remove(); }, 300); }
            }
        }, 300);
    } else {
        _apptNotifQueue.length = 0;
        _apptNotifShowing = false;
        var ov = document.querySelector('.appt-notif-overlay');
        if (ov) { ov.classList.add('removing'); setTimeout(function(){ if (ov.parentElement) ov.remove(); }, 300); }
    }
}

function viewAppointmentFromNotif(appointmentId) {
    _apptNotifQueue.length = 0;
    _apptNotifShowing = false;
    var card = document.querySelector('.appt-notif-card');
    var ov = document.querySelector('.appt-notif-overlay');
    if (card) card.remove();
    if (ov) ov.remove();
    window.location.href = ((window.RANDU_ADMIN && window.RANDU_ADMIN.appointmentsUrl) || 'appointments.php') + '?highlight=' + appointmentId;
}

window.showAppointmentNotification = showAppointmentNotification;
window.closeAppointmentNotification = closeAppointmentNotification;
window.viewAppointmentFromNotif = viewAppointmentFromNotif;
window.confirmAppointmentFromNotif = confirmAppointmentFromNotif;
window.cancelAppointmentFromNotif = cancelAppointmentFromNotif;

// API isteği helper fonksiyonu
async function apiRequest(endpoint, method = 'GET', data = null) {
    // CSRF token'ı multiple kaynaklardan almayı dene
    let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    // Meta tag yoksa, hidden input'tan almayı dene
    if (!csrfToken) {
        csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    }
    
    // Hala yoksa, global değişkenden almayı dene
    if (!csrfToken && typeof window.csrfToken !== 'undefined') {
        csrfToken = window.csrfToken;
    }
    
    const options = {
        method: method,
        headers: {
            'X-CSRF-Token': csrfToken
        }
    };
    
    if (method === 'GET' && data) {
        const params = new URLSearchParams(data);
        endpoint += '?' + params.toString();
    } else if (method === 'POST' && data) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data);
    }
    
    const response = await fetch(endpoint, options);
    const result = await response.json();
    
    if (!response.ok) {
        throw new Error(result.message || 'API hatası');
    }
    
    return result;
}

// Fiyat formatla: tam sayıysa ondalık gösterme (1500 ₺), değilse 2 hane (1500,50 ₺)
function formatTL(val) {
    const n = parseFloat(val || 0);
    if (n === Math.floor(n)) return n.toLocaleString('tr-TR') + ' ₺';
    return n.toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ₺';
}
function formatNum(val) {
    const n = parseFloat(val || 0);
    if (n === Math.floor(n)) return n.toLocaleString('tr-TR');
    return n.toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Global state
let dailyViewState = {
    selectedDate: window.dailyViewData?.selectedDate || new Date().toISOString().split('T')[0],
    employees: window.dailyViewData?.employeeList || [],
    services: window.dailyViewData?.services || [],
    schedule: {},
    timeSlots: [],
    offGridSlots: {},
    workingHours: window.dailyViewData?.workingHours || { start: 9, end: 21 },
    employeeSummary: window.dailyViewData?.employeeSummary || {},
    grandTotalCash: window.dailyViewData?.grandTotalCash || 0,
    grandTotalBank: window.dailyViewData?.grandTotalBank || 0,
    grandTotalCreditCard: window.dailyViewData?.grandTotalCreditCard || 0,
    grandTotalNotPaid: window.dailyViewData?.grandTotalNotPaid || 0,
    grandTotalPendingCash: window.dailyViewData?.grandTotalPendingCash || 0,
    grandTotalPendingBank: window.dailyViewData?.grandTotalPendingBank || 0,
    grandTotalPendingCreditCard: window.dailyViewData?.grandTotalPendingCreditCard || 0,
    pendingRevenueToday: window.dailyViewData?.pendingRevenueToday || [],
    canAccessRevenue: window.dailyViewData?.canAccessRevenue || false,
    slotDuration: window.dailyViewData?.slotDuration || 30,
    nextSlot: window.dailyViewData?.nextSlot || null,
    canAddMore: window.dailyViewData?.canAddMore || false,
    waitingList: window.dailyViewData?.waitingList || []
};

let pendingFormData = null;
let currentActiveModal = null;

// Verileri API'den yükle
async function loadDailyViewData(date = null) {
    const targetDate = date || dailyViewState.selectedDate;
    
    try {
        const result = await apiRequest(`api/daily_view/list.php?date=${targetDate}`, 'GET');
        
        if (result.success) {
            dailyViewState.selectedDate = result.data.date;
            dailyViewState.employees = result.data.employees;
            dailyViewState.services = result.data.services;
            dailyViewState.schedule = result.data.schedule;
            dailyViewState.timeSlots = result.data.time_slots;
            dailyViewState.offGridSlots = result.data.off_grid_slots || {};
            dailyViewState.workingHours = result.data.working_hours;
            dailyViewState.employeeSummary = result.stats.employee_summary;
            dailyViewState.grandTotalCash = result.stats.grand_total_cash;
            dailyViewState.grandTotalBank = result.stats.grand_total_bank;
            dailyViewState.grandTotalCreditCard = result.stats.grand_total_credit_card || 0;
            dailyViewState.grandTotalNotPaid = result.stats.grand_total_not_paid || 0;
            dailyViewState.grandTotalPendingCash = result.stats.grand_total_pending_cash || 0;
            dailyViewState.grandTotalPendingBank = result.stats.grand_total_pending_bank || 0;
            dailyViewState.grandTotalPendingCreditCard = result.stats.grand_total_pending_credit_card || 0;
            dailyViewState.pendingRevenueToday = result.stats.pending_revenue_today || [];
            dailyViewState.slotDuration = result.data.slot_duration;
            dailyViewState.nextSlot = result.data.next_slot;
            dailyViewState.canAddMore = result.data.can_add_more;
            dailyViewState.waitingList = result.data.waiting_list || [];
            
            renderScheduleGrid();
            renderWaitingList();
            initializeDragAndDrop(); // Drag-drop event'lerini yeniden bağla
            updateSummaryRow();
            updatePendingRevenueSection();
            updateDateNavigation(result.data.prev_date, result.data.next_date);
            
            // Tarih input'unu güncelle
            const dateInput = document.getElementById('dateSelect');
            if (dateInput) dateInput.value = result.data.date;
            
            return result;
        }
    } catch (error) {
        console.error('Veri yükleme hatası:', error);
        _notify('Veriler yüklenirken hata oluştu', 'error');
    }
}

// Tarih navigasyonu - AJAX ile (sayfa yenilenmeden)
function navigateDate(date) {
    dailyViewState.selectedDate = date;
    
    // URL'i temiz tut (history API ile) - tarih parametresi dahil
    if (window.history && window.history.pushState) {
        window.history.pushState({date: date}, '', 'daily_view.php?date=' + date);
    }
    
    // Tarih input'unu güncelle
    const dateInput = document.getElementById('dateSelect');
    if (dateInput) dateInput.value = date;

    const waitDateInput = document.getElementById('waitlist_date');
    if (waitDateInput) waitDateInput.value = date;
    
    // AJAX ile veriyi yükle
    loadDailyViewData(date);
}

function updateDateNavigation(prevDate, nextDate) {
    // Tüm date-nav-btn'leri bul ve güncelle
    const navBtns = document.querySelectorAll('.date-nav-btn');
    navBtns.forEach((btn, index) => {
        if (index === 0) {
            // İlk buton = önceki gün
            btn.onclick = () => navigateDate(prevDate);
        } else {
            // İkinci buton = sonraki gün
            btn.onclick = () => navigateDate(nextDate);
        }
    });
    
    // Tarih input'unu güncelle
    const dateInput = document.getElementById('dateSelect');
    if (dateInput) dateInput.value = dailyViewState.selectedDate;
}

function renderWaitingList() {
    const mainContainer = document.getElementById('waitingListItems');
    const modalContainer = document.getElementById('waitingListItemsModal');
    const mainCountEl = document.getElementById('waitingListCount');
    const modalCountEl = document.getElementById('waitingListCountModal');
    const badgeEl = document.getElementById('waitingListBadge');
    
    const entries = Array.isArray(dailyViewState.waitingList) ? dailyViewState.waitingList : [];
    
    if (mainCountEl) mainCountEl.textContent = String(entries.length);
    if (modalCountEl) modalCountEl.textContent = String(entries.length);
    if (badgeEl) badgeEl.textContent = String(entries.length);

    const emptyHtml = '<div class="waiting-empty">Bekleme listesinde kayit yok.</div>';
    const itemsHtml = entries.length === 0 ? emptyHtml : entries.map((entry) => {
        const phone = escapeHtml(entry.customer_phone || '-');
        const name = escapeHtml(entry.customer_name || 'Musteri');
        const prefEmployee = escapeHtml(entry.preferred_employee_name || 'Fark etmez');
        const prefTime = escapeHtml(entry.preferred_time || 'Fark etmez');
        const services = escapeHtml(entry.requested_services || 'Belirtilmedi');
        const note = escapeHtml(entry.note || '');
        const calledClass = entry.status === 'called' ? 'called' : '';
        const dialPhone = normalizePhoneForDial(entry.customer_phone || '');

        const calledIcon = entry.status === 'called' ? '<i class="fas fa-check waiting-called-icon"></i>' : '';
        return `
            <div class="waiting-item ${calledClass}">
                <div class="waiting-item-top">
                    <div class="waiting-customer">${name} ${calledIcon}</div>
                    <span class="waiting-chip"><i class="fas fa-phone"></i> ${phone}</span>
                </div>
                <div class="waiting-meta">
                    <span class="waiting-chip"><i class="fas fa-user-tie"></i> ${prefEmployee}</span>
                    <span class="waiting-chip"><i class="fas fa-clock"></i> ${prefTime}</span>
                    <span class="waiting-chip"><i class="fas fa-cut"></i> ${services}</span>
                    ${entry.call_when_available == 1 ? '<span class="waiting-chip"><i class="fas fa-bell"></i> Bosluk olursa aranabilir</span>' : ''}
                </div>
                ${note ? `<div class="waiting-chip waiting-chip-note">${note}</div>` : ''}
                <div class="waiting-actions">
                    <button class="btn btn-success" onclick="openWaitlistAsAppointment(${entry.id})"><i class="fas fa-calendar-plus"></i> Randevuya Cevir</button>
                    <button class="btn btn-primary" onclick="markWaitlistCalled(${entry.id})"><i class="fas fa-phone-volume"></i> Arandi</button>
                    <button class="btn btn-secondary" onclick="window.location.href='tel:${dialPhone}'"><i class="fas fa-phone"></i> Ara</button>
                    <button class="btn btn-danger" onclick="cancelWaitlistEntry(${entry.id})"><i class="fas fa-times"></i> Listeden Cikar</button>
                </div>
            </div>
        `;
    }).join('');

    if (mainContainer) mainContainer.innerHTML = itemsHtml;
    if (modalContainer) modalContainer.innerHTML = itemsHtml;
}

async function fillWaitlistCustomerInfo() {
    const phoneInput = document.getElementById('waitlist_customer_phone');
    const nameInput = document.getElementById('waitlist_customer_name');
    
    if (!phoneInput || !nameInput) return;
    
    const phone = phoneInput.value.trim();
    if (!phone || phone.length < 10) {
        nameInput.readOnly = false;
        return;
    }
    
    try {
        const result = await apiRequest(`api/daily_view/customer.php?phone=${encodeURIComponent(phone)}`, 'GET');
        
        if (result.success && result.found && result.customer) {
            nameInput.value = result.customer.name;
            nameInput.readOnly = true;
        } else {
            nameInput.readOnly = false;
        }
    } catch (error) {
        console.error('Müşteri bilgisi alınırken hata:', error);
        nameInput.readOnly = false;
    }
}

function toggleWaitlistInlineForm(show) {
    const container = document.getElementById('waitlistInlineFormContainer');
    const form = document.getElementById('waitlistForm');
    const waitDateInput = document.getElementById('waitlist_date');
    if (!container) return;

    if (show) {
        container.style.display = 'block';
        if (waitDateInput) waitDateInput.value = dailyViewState.selectedDate;
        setTimeout(() => {
            const phoneInput = document.getElementById('waitlist_customer_phone');
            if (phoneInput) phoneInput.focus();
        }, 50);
        return;
    }

    container.style.display = 'none';
    if (form) form.reset();
    if (waitDateInput) waitDateInput.value = dailyViewState.selectedDate;
}

async function submitWaitlistForm() {
    const form = document.getElementById('waitlistForm');
    if (!form) return;

    const payload = {
        action: 'create',
        wait_date: document.getElementById('waitlist_date')?.value || dailyViewState.selectedDate,
        customer_name: form.customer_name.value,
        customer_phone: form.customer_phone.value,
        preferred_employee_id: form.preferred_employee_id?.value || '',
        preferred_time: form.preferred_time?.value || '',
        requested_services: form.requested_services?.value || '',
        note: form.note?.value || '',
        call_when_available: form.call_when_available?.checked ? 1 : 0
    };

    try {
        const result = await apiRequest('api/daily_view/waitlist.php', 'POST', payload);
        if (!result.success) {
            _notify(result.message || 'Bekleme listesine eklenemedi', 'error');
            return;
        }

        toggleWaitlistInlineForm(false);
        _notify(result.message || 'Bekleme listesine eklendi', 'success');
        loadDailyViewData();
    } catch (error) {
        console.error('Bekleme listesi ekleme hatasi:', error);
        _notify('Bekleme listesine ekleme sirasinda hata olustu', 'error');
    }
}

async function updateWaitlistStatus(id, status) {
    try {
        const result = await apiRequest('api/daily_view/waitlist.php', 'POST', {
            action: 'status',
            id,
            status
        });
        if (!result.success) {
            _notify(result.message || 'Durum guncellenemedi', 'error');
            return;
        }
        loadDailyViewData();
    } catch (error) {
        console.error('Bekleme listesi durum guncelleme hatasi:', error);
        _notify('Bekleme listesi guncellenirken hata olustu', 'error');
    }
}

function openWaitlistAsAppointment(waitlistId) {
    const entry = (dailyViewState.waitingList || []).find((x) => Number(x.id) === Number(waitlistId));
    if (!entry) return;

    _waitlistBetweenConfirmAccepted = false;
    _conflictBetweenConfirmAccepted = false;

    closeModal('waitingListModal');
    showAddModal();

    const idInput = document.getElementById('waitlist_id');
    if (idInput) idInput.value = String(waitlistId);

    const nameEl = document.getElementById('customer_name');
    const phoneEl = document.getElementById('customer_phone');
    const empEl = document.getElementById('employee_id');
    const notesEl = document.getElementById('notes');
    const timeEl = document.getElementById('appointment_time');

    if (nameEl) nameEl.value = entry.customer_name || '';
    if (phoneEl) phoneEl.value = entry.customer_phone || '';
    if (empEl && entry.preferred_employee_id) empEl.value = String(entry.preferred_employee_id);
    if (timeEl && entry.preferred_time) timeEl.value = entry.preferred_time;

    const notes = [];
    if (entry.requested_services) notes.push('Bekleme hizmeti: ' + entry.requested_services);
    if (entry.note) notes.push('Bekleme notu: ' + entry.note);
    if (notesEl && notes.length > 0) notesEl.value = notes.join('\n');
}

function markWaitlistCalled(waitlistId) {
    updateWaitlistStatus(waitlistId, 'called');
}

function cancelWaitlistEntry(waitlistId) {
    closeModal('waitingListModal');
    showConfirmationModal('Bekleme Listesi', 'Kaydi listeden cikarmak istiyor musunuz?', function() {
        updateWaitlistStatus(waitlistId, 'cancelled');
    });
}

// Schedule grid'i render et
function renderScheduleGrid() {
    const scheduleGrid = document.querySelector('.schedule-grid:not(.summary-grid)');
    if (!scheduleGrid) return;
    
    // Grid içeriğini temizle
    scheduleGrid.innerHTML = '';
    
    // Saat Sütunu
    const timeColumn = document.createElement('div');
    timeColumn.className = 'time-column';
    
    const timeHeader = document.createElement('div');
    timeHeader.className = 'employee-header';
    timeHeader.style.background = '#495057';
    timeHeader.textContent = 'Saat';
    timeColumn.appendChild(timeHeader);
    
    dailyViewState.timeSlots.forEach(timeSlot => {
        const timeLabel = document.createElement('div');
        timeLabel.className = 'time-label';
        if (dailyViewState.offGridSlots && dailyViewState.offGridSlots[timeSlot]) {
            timeLabel.classList.add('off-grid-slot');
            timeLabel.title = 'Standart slot dışında manuel eklenmiş saat';
        }
        timeLabel.textContent = timeSlot;
        timeColumn.appendChild(timeLabel);
    });
    
    scheduleGrid.appendChild(timeColumn);
    
    // Çalışan Kolonları
    dailyViewState.employees.forEach(emp => {
        const empColumn = document.createElement('div');
        empColumn.className = 'employee-column';
        
        // Header
        const empHeader = document.createElement('div');
        empHeader.className = 'employee-header';
        empHeader.innerHTML = `<i class="fas fa-user"></i> ${escapeHtml(emp.name)}`;
        empColumn.appendChild(empHeader);
        
        // Her zaman dilimi için slot
        dailyViewState.timeSlots.forEach(timeSlot => {
            const slot = document.createElement('div');
            slot.className = 'appointment-slot';
            if (dailyViewState.offGridSlots && dailyViewState.offGridSlots[timeSlot]) {
                slot.classList.add('off-grid-slot');
            }
            slot.dataset.employeeId = emp.id;
            slot.dataset.time = timeSlot;
            slot.dataset.date = dailyViewState.selectedDate;
            
            // Bu slot'ta randevu var mı?
            const appointments = dailyViewState.schedule[emp.id]?.[timeSlot] || [];
            
            if (appointments.length > 0) {
                // Randevuları sırala
                appointments.sort((a, b) => {
                    const order = {pending: 0, confirmed: 1, break: 2, completed: 3, cancelled: 4};
                    return (order[a.status] || 5) - (order[b.status] || 5);
                });
                
                // Slot durumunu belirle
                let hasBreak = false, hasCompleted = false, hasPending = false, hasConfirmed = false, hasCancelled = false;
                
                appointments.forEach(app => {
                    if (app.status === 'break') hasBreak = true;
                    else if (app.status === 'completed') hasCompleted = true;
                    else if (app.status === 'cancelled') hasCancelled = true;
                    else if (app.status === 'confirmed') hasConfirmed = true;
                    else hasPending = true;
                });
                
                // Slot class belirleme
                const statusCount = (hasPending ? 1 : 0) + (hasCompleted ? 1 : 0) + (hasCancelled ? 1 : 0) + (hasBreak ? 1 : 0);
                if (statusCount > 1) {
                    slot.classList.add('mixed-status');
                } else if (hasCancelled) {
                    slot.classList.add('slot-cancelled');
                } else if (hasBreak) {
                    slot.classList.add('slot-break');
                } else if (hasCompleted) {
                    slot.classList.add('completed');
                } else if (hasConfirmed) {
                    slot.classList.add('confirmed');
                } else {
                    slot.classList.add('booked');
                }
                
                if (appointments.length > 1) {
                    slot.classList.add('has-multiple');
                }
                
                // Randevuları render et
                appointments.forEach((app, index) => {
                    const appItem = createAppointmentItemForSlot(app, appointments.length, index);
                    slot.appendChild(appItem);
                });
                
                slot.onclick = (e) => handleSlotClick(e, slot);
            } else {
                // Boş slot
                slot.classList.add('available');
                slot.innerHTML = '<div class="slot-add-icon">+</div><div class="slot-add-text">Randevu Ekle</div>';
                slot.onclick = (e) => handleAvailableSlotClick(e, slot);
            }
            
            empColumn.appendChild(slot);
        });
        
        scheduleGrid.appendChild(empColumn);
    });
    
    // Saat ekleme container'ı güncelle
    updateAddTimeSlotContainer();
    
    // Grid kolonlarını güncelle
    updateGridColumns();
    
    // NOT: initializeDragAndDrop() burada çağrılmıyor, loadDailyViewData'da çağrılıyor
}

// Saat ekleme container'ını güncelle
function updateAddTimeSlotContainer() {
    let container = document.querySelector('.add-time-slot-container');
    const scheduleContainer = document.querySelector('.schedule-container');
    
    // API'den gelen next_slot bilgisini kullan
    const nextSlot = dailyViewState.nextSlot;
    const canAddMore = dailyViewState.canAddMore;
    
    if (canAddMore && nextSlot) {
        if (!container && scheduleContainer) {
            container = document.createElement('div');
            container.className = 'add-time-slot-container';
            const summaryGrid = document.querySelector('.summary-grid');
            if (summaryGrid) {
                scheduleContainer.insertBefore(container, summaryGrid);
            } else {
                scheduleContainer.appendChild(container);
            }
        }
        if (container) {
            container.innerHTML = `
                <div class="add-time-label">${nextSlot}</div>
                <div class="add-slot-button" onclick="addNewTimeSlotForAll('${nextSlot}')">+</div>
            `;
            container.style.display = '';
        }
    } else if (container) {
        container.style.display = 'none';
    }
}

// Slot içindeki randevu item'ı oluştur (orijinal PHP yapısına uyumlu)
function createAppointmentItemForSlot(appointment, totalInSlot, index) {
    const item = document.createElement('div');
    
    // Status class belirleme
    let itemStatusClass = 'item-pending';
    if (appointment.status === 'completed') itemStatusClass = 'item-completed';
    else if (appointment.status === 'cancelled') itemStatusClass = 'item-cancelled';
    else if (appointment.status === 'break') itemStatusClass = 'item-break';
    else if (appointment.status === 'confirmed') itemStatusClass = 'item-confirmed';
    
    item.className = `appointment-item ${itemStatusClass}`;
    item.dataset.appointmentDetails = JSON.stringify(appointment);
    item.dataset.isBetween = appointment.is_between == 1 ? 'true' : 'false';
    item.onclick = (e) => handleAppointmentItemClick(e, item);
    
    if (appointment.status === 'break') {
        item.innerHTML = `<div class="slot-patient">Açık Saat</div>`;
    } else {
        let content = '';
        
        // Düzenlenmiş randevu göstergesi
        if (appointment.is_edited == 1 && appointment.status === 'completed') {
            content += '<div class="edited-indicator" title="Düzenlenmiş Randevu">D</div>';
        }
        
        // Birden fazla randevu badge
        if (totalInSlot > 1) {
            if (index === 0) {
                content += `<div class="appointment-count-badge"><i class="fas fa-layer-group"></i> ${totalInSlot} randevu</div>`;
            } else {
                content += '<div class="between-label">ARAYA</div>';
            }
        }
        
        // Araya ekleme butonu (break hariç)
        if (appointment.status !== 'break') {
            const timeFormatted = appointment.appointment_time_formatted || appointment.appointment_time;
            content += `<button class="add-between-btn" onclick="showAddBetweenModal(event, ${appointment.employee_id}, '${timeFormatted}', '${dailyViewState.selectedDate}')" title="Araya Müşteri Ekle">+</button>`;
        }
        
        // Müşteri adı
        const customerName = escapeHtml(appointment.customer_name || 'Müşteri');
        content += `<div class="slot-patient">${customerName}</div>`;
        
        // Fiyat ve ödeme yöntemi
        const price = formatTL(appointment.total_price).replace(' ₺', '');
        const paymentMethod = appointment.payment_method || 'cash';
        const paidAmount = appointment.paid_amount != null ? parseFloat(appointment.paid_amount) : null;
        const isPartial = paidAmount !== null && paidAmount > 0 && paidAmount < parseFloat(appointment.total_price);

        let paymentText;
        let paymentStyle;
        if (isPartial) {
            const pmLabel = paymentMethod === 'cash' ? 'Nakit'
                : paymentMethod === 'bank_transfer' ? 'Havale/EFT'
                : paymentMethod === 'credit_card' ? 'Kredi Kartı'
                : 'Ödeme';
            paymentText = `Kısmi: ${formatTL(paidAmount).replace(' ₺','')} ₺ ${pmLabel}`;
            paymentStyle = ' not-paid';
        } else {
            paymentText = paymentMethod === 'cash' ? 'Nakit'
                : paymentMethod === 'bank_transfer' ? 'Havale/EFT'
                : paymentMethod === 'credit_card' ? 'Kredi Kartı'
                : paymentMethod === 'not_paid' ? 'Alınmadı'
                : 'Havale/EFT';
            paymentStyle = getPaymentMethodCssClass(paymentMethod, 0);
        }
        content += `<div class="slot-details${paymentStyle}">${price} ₺ - ${paymentText}</div>`;
        
        // Durum
        let statusText = 'Onaylandı';
        if (appointment.status === 'completed') statusText = 'Tamamlandı';
        else if (appointment.status === 'cancelled') statusText = 'İptal Edildi';
        else if (appointment.status === 'pending') statusText = 'Bekliyor';
        content += `<div class="slot-status">${statusText}</div>`;
        
        item.innerHTML = content;
    }
    
    return item;
}

// Summary row'u güncelle
function updateSummaryRow() {
    const summaryGrid = document.querySelector('.summary-grid');
    if (!summaryGrid) return;
    
    // Summary grid'i yeniden oluştur
    summaryGrid.innerHTML = '';
    
    // Özet label kolonu
    const labelColumn = document.createElement('div');
    labelColumn.className = 'time-column summary-label-column';
    labelColumn.innerHTML = '<div class="summary-vertical-label">ÖZET</div>';
    summaryGrid.appendChild(labelColumn);
    
    // Her çalışan için özet
    dailyViewState.employees.forEach(emp => {
        const summary = dailyViewState.employeeSummary[emp.id] || {
            total_appointments: 0,
            total_revenue: 0,
            cash_total: 0,
            bank_total: 0,
            credit_card_total: 0,
            cash_count: 0,
            bank_count: 0,
            credit_card_count: 0,
            not_paid_total: 0,
            not_paid_count: 0
        };
        
        const empColumn = document.createElement('div');
        empColumn.className = 'employee-column';
        empColumn.innerHTML = `
            <div class="employee-summary">
                <div class="summary-name">${escapeHtml(emp.name)}</div>
                <div class="summary-item summary-total">
                    <span class="summary-label">Toplam Randevu:</span>
                    <span class="summary-value">${summary.total_appointments}</span>
                </div>
                <div class="summary-item summary-total">
                    <span class="summary-label">Toplam Kazanç:</span>
                    <span class="summary-value">${formatTL(summary.total_revenue)}</span>
                </div>
                <div class="summary-item summary-payment summary-pm-cash">
                    <span class="summary-label">Nakit:</span>
                    <span class="summary-value">${summary.cash_count || 0} Adet (${formatTL(summary.cash_total)})</span>
                </div>
                <div class="summary-item summary-payment summary-pm-bank">
                    <span class="summary-label">Havale/EFT:</span>
                    <span class="summary-value">${summary.bank_count || 0} Adet (${formatTL(summary.bank_total)})</span>
                </div>
                <div class="summary-item summary-payment summary-pm-card">
                    <span class="summary-label">Kredi Kartı:</span>
                    <span class="summary-value">${summary.credit_card_count || 0} Adet (${formatTL(summary.credit_card_total)})</span>
                </div>
                <div class="summary-item summary-payment summary-not-paid">
                    <span class="summary-label">Alınmadı:</span>
                    <span class="summary-value">${summary.not_paid_count || 0} Adet (${formatTL(summary.not_paid_total)})</span>
                </div>
            </div>
        `;
        summaryGrid.appendChild(empColumn);
    });
    
    // Genel toplamları güncelle
    const totalsFooter = document.querySelector('.totals-footer');
    if (totalsFooter) {
        const cashTotal = formatTL(dailyViewState.grandTotalCash);
        const bankTotal = formatTL(dailyViewState.grandTotalBank);
        const creditCardTotal = formatTL(dailyViewState.grandTotalCreditCard);
        const notPaidTotal = formatTL(dailyViewState.grandTotalNotPaid);
        const pendingCashNote = dailyViewState.grandTotalPendingCash > 0
            ? ` <small style="color:#64748b; font-weight:normal;">(${formatTL(dailyViewState.grandTotalPendingCash)} bekleyen tahsilat dahil)</small>`
            : '';
        const pendingBankNote = dailyViewState.grandTotalPendingBank > 0
            ? ` <small style="color:#64748b; font-weight:normal;">(${formatTL(dailyViewState.grandTotalPendingBank)} bekleyen tahsilat dahil)</small>`
            : '';
        const pendingCardNote = dailyViewState.grandTotalPendingCreditCard > 0
            ? ` <small style="color:#64748b; font-weight:normal;">(${formatTL(dailyViewState.grandTotalPendingCreditCard)} bekleyen tahsilat dahil)</small>`
            : '';
        totalsFooter.innerHTML = `
            <div class="total-item"><strong>GÜN SONU TOPLAM NAKİT:</strong> ${cashTotal}${pendingCashNote}</div>
            <div class="total-item"><strong>GÜN SONU TOPLAM HAVALE/EFT:</strong> ${bankTotal}${pendingBankNote}</div>
            <div class="total-item"><strong>GÜN SONU TOPLAM KREDİ KARTI:</strong> ${creditCardTotal}${pendingCardNote}</div>
            <div class="total-item" style="color:#ef4444"><strong>GÜN SONU TOPLAM ALINMADI:</strong> ${notPaidTotal}</div>
        `;
    }
}

function updatePendingRevenueSection() {
    if (!dailyViewState.canAccessRevenue) return;

    const section = document.getElementById('pendingRevenueSection');
    if (!section) return;

    const items = Array.isArray(dailyViewState.pendingRevenueToday) ? dailyViewState.pendingRevenueToday : [];
    const totalPending = (dailyViewState.grandTotalPendingCash || 0)
        + (dailyViewState.grandTotalPendingBank || 0)
        + (dailyViewState.grandTotalPendingCreditCard || 0);

    if (items.length === 0) {
        section.style.display = 'none';
        const list = document.getElementById('pendingRevenueList');
        if (list) list.innerHTML = '';
        return;
    }

    section.style.display = '';

    const header = section.querySelector('.pending-revenue-header');
    if (header) {
        header.innerHTML = `
            <span><i class="fas fa-clock"></i> Bugün Tahsil Edilen Bekleyen Ödemeler</span>
            <span class="pending-revenue-count">${items.length} adet — ${formatTL(totalPending)}</span>
        `;
    }

    const list = document.getElementById('pendingRevenueList');
    if (!list) return;

    list.innerHTML = items.map((item) => {
        const paymentLabel = item.payment_method === 'cash'
            ? 'Nakit'
            : item.payment_method === 'bank_transfer'
                ? 'Havale/EFT'
                : 'Kredi Kartı';
        const paymentClass = item.payment_method === 'cash'
            ? 'pr-cash'
            : item.payment_method === 'bank_transfer'
                ? 'pr-bank'
                : 'pr-card';
        const employee = dailyViewState.employees.find((emp) => Number(emp.id) === Number(item.employee_id));
        const appointmentDate = item.appointment_date
            ? new Date(item.appointment_date).toLocaleDateString('tr-TR')
            : '-';

        return `
            <div class="pending-revenue-item">
                <div class="pr-customer">${escapeHtml(item.customer_name || '-')}</div>
                <div class="pr-date">Randevu: ${appointmentDate}</div>
                ${employee ? `<div class="pr-employee"><i class="fas fa-user"></i> ${escapeHtml(employee.name)}</div>` : ''}
                ${item.service_name ? `<div class="pr-service">${escapeHtml(item.service_name)}</div>` : ''}
                <div class="pr-amount">${formatTL(item.total_price)}</div>
                <div class="pr-method ${paymentClass}">${paymentLabel}</div>
            </div>
        `;
    }).join('');
}

// Grid kolonlarını ayarla
function updateGridColumns() {
    const scheduleGrid = document.querySelector('.schedule-grid');
    const summaryRow = document.querySelector('.summary-row');
    
    if (!scheduleGrid || dailyViewState.employees.length === 0) return;
    
    scheduleGrid.style.setProperty('--employee-count', dailyViewState.employees.length);
    
    const addTimeSlotContainer = document.querySelector('.add-time-slot-container');
    if (addTimeSlotContainer) {
        addTimeSlotContainer.style.setProperty('--employee-count', dailyViewState.employees.length);
    }
}

// Çalışma saatlerini güncelle
async function submitWorkingHours(event) {
    event.preventDefault();
    
    const startHour = parseInt(document.getElementById('start_hour_select').value);
    const endHour = parseInt(document.getElementById('end_hour_select').value);
    
    try {
        const result = await apiRequest('api/daily_view/working_hours.php', 'POST', {
            start_hour: startHour,
            end_hour: endHour
        });
        
        if (result.success) {
            _notify(result.message, 'success');
            dailyViewState.workingHours = result.working_hours;
            loadDailyViewData();
        } else {
            _notify(result.message, 'error');
        }
    } catch (error) {
        console.error('Çalışma saatleri güncellenirken hata:', error);
        _notify('Çalışma saatleri güncellenirken hata oluştu', 'error');
    }
}

// Yeni zaman dilimi ekle
async function addNewTimeSlot(mode = 'single') {
    try {
        const result = await apiRequest('api/daily_view/add_slot.php', 'POST', { mode });
        
        if (result.success) {
            _notify(result.message, 'success');
            loadDailyViewData();
        } else {
            _notify(result.message, 'error');
        }
    } catch (error) {
        console.error('Zaman dilimi eklenirken hata:', error);
        _notify('Zaman dilimi eklenirken hata oluştu', 'error');
    }
}

function addNewTimeSlotForAll(timeValue) {
    // Tek slot ekle, sayfayı yenile
    addNewTimeSlot('single');
}

// Müşteri bilgilerini telefon numarasından al (debounce ile)
let _customerPhoneDebounce = null;
let _betweenCustomerPhoneDebounce = null;
let _waitlistPhoneDebounce = null;
let _customerNameDebounce = null;
let _betweenCustomerNameDebounce = null;
let _customerPhoneAutoDebounce = null;
let _betweenCustomerPhoneAutoDebounce = null;

const _customerAutocompleteState = {
    main: { requestId: 0, suppress: false },
    between: { requestId: 0, suppress: false }
};

function _normalizePhoneDigits(value) {
    return String(value || '').replace(/\D/g, '');
}

function _escapeHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function _formatPhoneForDisplay(rawPhone) {
    const digits = _normalizePhoneDigits(rawPhone);
    let local = digits;
    if (local.length === 12 && local.startsWith('90')) local = local.substring(2);
    if (local.length === 11 && local.startsWith('0')) local = local.substring(1);
    if (local.length !== 10) return rawPhone || '';
    return `${local.substring(0, 3)} ${local.substring(3, 6)} ${local.substring(6, 8)} ${local.substring(8, 10)}`;
}

function _showCustomerFoundAlert(alertId) {
    const infoAlert = document.getElementById(alertId);
    if (!infoAlert) return;
    infoAlert.style.display = 'flex';
    setTimeout(() => {
        if (infoAlert.style.display === 'flex') {
            infoAlert.style.display = 'none';
        }
    }, 5000);
}

function _hideAutocomplete(listEl) {
    if (!listEl) return;
    listEl.innerHTML = '';
    listEl.style.display = 'none';
}

function _applySuggestedCustomer(config, customer) {
    const phoneInput = document.getElementById(config.phoneInputId);
    const nameInput = document.getElementById(config.nameInputId);
    const state = _customerAutocompleteState[config.stateKey];
    if (!phoneInput || !nameInput || !state) return;

    state.suppress = true;
    phoneInput.value = _normalizePhoneDigits(customer.phone || '');
    nameInput.value = customer.name || '';
    nameInput.readOnly = true;
    state.suppress = false;

    _hideAutocomplete(document.getElementById(config.phoneListId));
    _hideAutocomplete(document.getElementById(config.nameListId));
    _showCustomerFoundAlert(config.alertId);
}

function _renderCustomerSuggestions(config, customers, targetListId) {
    const listEl = document.getElementById(targetListId);
    if (!listEl) return;

    if (!Array.isArray(customers) || customers.length === 0) {
        listEl.innerHTML = '<div class="customer-autocomplete-empty">Eşleşen müşteri bulunamadı</div>';
        listEl.style.display = 'block';
        return;
    }

    listEl.innerHTML = customers.map((c) => {
        const safeName = _escapeHtml(c.name || 'İsimsiz Müşteri');
        const phoneText = _escapeHtml(_formatPhoneForDisplay(c.phone || ''));
        return `
            <button type="button" class="customer-autocomplete-item" data-customer-id="${c.id || ''}" data-customer-name="${safeName}" data-customer-phone="${_escapeHtml(c.phone || '')}">
                <span class="customer-autocomplete-name">${safeName}</span>
                <span class="customer-autocomplete-phone">${phoneText}</span>
            </button>
        `;
    }).join('');

    listEl.style.display = 'block';

    listEl.querySelectorAll('.customer-autocomplete-item').forEach((btn) => {
        btn.addEventListener('mousedown', function(ev) {
            ev.preventDefault();
            const customer = {
                id: this.dataset.customerId || null,
                name: this.dataset.customerName || '',
                phone: this.dataset.customerPhone || ''
            };
            _applySuggestedCustomer(config, customer);
        });
    });
}

async function _searchCustomersForAutocomplete(config, query, field, targetListId) {
    const state = _customerAutocompleteState[config.stateKey];
    if (!state) return;

    const cleanQuery = String(query || '').trim();
    if (!cleanQuery) {
        _hideAutocomplete(document.getElementById(targetListId));
        return;
    }

    const minLen = field === 'phone' ? 3 : 2;
    if (cleanQuery.length < minLen) {
        _hideAutocomplete(document.getElementById(targetListId));
        return;
    }

    const reqId = ++state.requestId;
    try {
        const result = await apiRequest(`api/daily_view/customer_search.php?field=${encodeURIComponent(field)}&q=${encodeURIComponent(cleanQuery)}&limit=10`, 'GET');
        if (reqId !== state.requestId) return;
        _renderCustomerSuggestions(config, result.customers || [], targetListId);
    } catch (err) {
        if (reqId !== state.requestId) return;
        console.error('Müşteri autocomplete hatası:', err);
        _hideAutocomplete(document.getElementById(targetListId));
    }
}

function _bindCustomerAutocomplete(config) {
    const phoneInput = document.getElementById(config.phoneInputId);
    const nameInput = document.getElementById(config.nameInputId);
    const phoneList = document.getElementById(config.phoneListId);
    const nameList = document.getElementById(config.nameListId);
    const state = _customerAutocompleteState[config.stateKey];
    if (!phoneInput || !nameInput || !phoneList || !nameList || !state) return;

    phoneInput.addEventListener('input', function() {
        if (state.suppress) return;
        nameInput.readOnly = false;
        clearTimeout(config.phoneDebounceRef.get());
        config.phoneDebounceRef.set(setTimeout(() => {
            _searchCustomersForAutocomplete(config, _normalizePhoneDigits(phoneInput.value), 'phone', config.phoneListId);
        }, 250));
    });

    nameInput.addEventListener('input', function() {
        if (state.suppress) return;
        clearTimeout(config.nameDebounceRef.get());
        config.nameDebounceRef.set(setTimeout(() => {
            _searchCustomersForAutocomplete(config, nameInput.value.trim(), 'name', config.nameListId);
        }, 250));
    });

    phoneInput.addEventListener('focus', function() {
        if (_normalizePhoneDigits(phoneInput.value).length >= 3) {
            _searchCustomersForAutocomplete(config, _normalizePhoneDigits(phoneInput.value), 'phone', config.phoneListId);
        }
    });

    nameInput.addEventListener('focus', function() {
        if (nameInput.value.trim().length >= 2) {
            _searchCustomersForAutocomplete(config, nameInput.value.trim(), 'name', config.nameListId);
        }
    });

    phoneInput.addEventListener('blur', function() {
        setTimeout(() => _hideAutocomplete(phoneList), 120);
    });

    nameInput.addEventListener('blur', function() {
        setTimeout(() => _hideAutocomplete(nameList), 120);
    });
}

async function fillCustomerInfo() {
    const phoneInput = document.getElementById('customer_phone');
    const nameInput = document.getElementById('customer_name');
    const infoAlert = document.getElementById('customerInfoAlert');
    
    if (!phoneInput || !nameInput) return;
    
    const phone = phoneInput.value.trim();
    if (!phone || phone.length < 10) {
        if (infoAlert) infoAlert.style.display = 'none';
        nameInput.readOnly = false;
        return;
    }
    
    try {
        const result = await apiRequest(`api/daily_view/customer.php?phone=${encodeURIComponent(phone)}`, 'GET');
        
        if (result.success && result.found && result.customer) {
            nameInput.value = result.customer.name;
            nameInput.readOnly = true;
            
            if (infoAlert) {
                infoAlert.style.display = 'flex';
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
    } catch (error) {
        console.error('Müşteri bilgisi alınırken hata:', error);
        nameInput.readOnly = false;
        if (infoAlert) infoAlert.style.display = 'none';
    }
}

// Araya ekleme için müşteri bilgisi
async function fillBetweenCustomerInfo() {
    const phoneInput = document.getElementById('between_customer_phone');
    const nameInput = document.getElementById('between_customer_name');
    const infoAlert = document.getElementById('betweenCustomerInfoAlert');
    
    if (!phoneInput || !nameInput) return;
    
    const phone = phoneInput.value.trim();
    if (!phone || phone.length < 10) {
        nameInput.readOnly = false;
        if (infoAlert) infoAlert.style.display = 'none';
        return;
    }
    
    try {
        const result = await apiRequest(`api/daily_view/customer.php?phone=${encodeURIComponent(phone)}`, 'GET');
        
        if (result.success && result.found && result.customer) {
            nameInput.value = result.customer.name;
            nameInput.readOnly = true;
            
            if (infoAlert) {
                infoAlert.style.display = 'flex';
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
    } catch (error) {
        console.error('Müşteri bilgisi alınırken hata:', error);
        nameInput.readOnly = false;
        if (infoAlert) infoAlert.style.display = 'none';
    }
}



// Toplam fiyat hesaplama
function updateTotalPrice() {
    let total = 0;
    document.querySelectorAll('#servicesSelection .service-checkbox').forEach(checkbox => {
        const wrapper = checkbox.closest('.service-item-wrapper');
        const rangeDiv = wrapper?.querySelector('.range-price-input');
        const variantPanel = wrapper?.querySelector('.variant-panel');
        if (rangeDiv) {
            rangeDiv.style.display = checkbox.checked ? 'flex' : 'none';
        }
        if (variantPanel) {
            variantPanel.style.display = checkbox.checked ? 'block' : 'none';
            if (!checkbox.checked) {
                variantPanel.querySelectorAll('.variant-input').forEach(v => v.checked = false);
            }
        }
        if (checkbox.checked) {
            if (checkbox.dataset.isRange === '1' && rangeDiv) {
                const rangeInput = rangeDiv.querySelector('.range-actual-price');
                total += parseFloat(rangeInput?.value || checkbox.dataset.price);
            } else if (checkbox.dataset.isVariant === '1') {
                total += parseFloat(checkbox.dataset.price);
                if (variantPanel) {
                    variantPanel.querySelectorAll('.variant-input:checked').forEach(v => {
                        total += parseFloat(v.dataset.extraPrice || 0);
                    });
                }
            } else {
                total += parseFloat(checkbox.dataset.price);
            }
        }
    });
    document.getElementById('totalPriceDisplay').textContent = `Toplam: ${formatTL(total)}`;
}

function updateBetweenTotalPrice() {
    let total = 0;
    document.querySelectorAll('#betweenServicesSelection .between-service-checkbox').forEach(checkbox => {
        const wrapper = checkbox.closest('.service-item-wrapper');
        const rangeDiv = wrapper?.querySelector('.range-price-input');
        const variantPanel = wrapper?.querySelector('.variant-panel');
        if (rangeDiv) {
            rangeDiv.style.display = checkbox.checked ? 'flex' : 'none';
        }
        if (variantPanel) {
            variantPanel.style.display = checkbox.checked ? 'block' : 'none';
            if (!checkbox.checked) {
                variantPanel.querySelectorAll('.variant-input').forEach(v => v.checked = false);
            }
        }
        if (checkbox.checked) {
            if (checkbox.dataset.isRange === '1' && rangeDiv) {
                const rangeInput = rangeDiv.querySelector('.range-actual-price');
                total += parseFloat(rangeInput?.value || checkbox.dataset.price);
            } else if (checkbox.dataset.isVariant === '1') {
                total += parseFloat(checkbox.dataset.price);
                if (variantPanel) {
                    variantPanel.querySelectorAll('.variant-input:checked').forEach(v => {
                        total += parseFloat(v.dataset.extraPrice || 0);
                    });
                }
            } else {
                total += parseFloat(checkbox.dataset.price);
            }
        }
    });
    document.getElementById('betweenTotalPriceDisplay').textContent = `Toplam: ${formatTL(total)}`;
}

// Müşteri SMS gönderim tercihini hatırla
const DAILY_SMS_PREF_KEY = 'daily_view_send_customer_sms';

function getSendCustomerSmsPreference() {
    return localStorage.getItem(DAILY_SMS_PREF_KEY) === '1';
}

function setSendCustomerSmsPreference(isChecked) {
    localStorage.setItem(DAILY_SMS_PREF_KEY, isChecked ? '1' : '0');
}

function applySendCustomerSmsPreference() {
    const checked = getSendCustomerSmsPreference();
    const normal = document.getElementById('send_customer_sms');
    const between = document.getElementById('between_send_customer_sms');
    if (normal) normal.checked = checked;
    if (between) between.checked = checked;
}

function bindSendCustomerSmsPreference() {
    const normal = document.getElementById('send_customer_sms');
    const between = document.getElementById('between_send_customer_sms');

    if (normal) {
        normal.addEventListener('change', () => {
            setSendCustomerSmsPreference(normal.checked);
            if (between) between.checked = normal.checked;
            if (typeof window.warnSmsIfUnavailable === 'function') {
                window.warnSmsIfUnavailable(normal.checked);
            }
        });
    }

    if (between) {
        between.addEventListener('change', () => {
            setSendCustomerSmsPreference(between.checked);
            if (normal) normal.checked = between.checked;
            if (typeof window.warnSmsIfUnavailable === 'function') {
                window.warnSmsIfUnavailable(between.checked);
            }
        });
    }
}

function notifyAppointmentSmsOutcome(result) {
    if (typeof window.notifySmsOutcome === 'function') {
        window.notifySmsOutcome(result);
    } else if (result && result.sms_warning) {
        _notify(result.sms_warning, 'warning', 7000);
    }
}

// Modal fonksiyonları
function showAddModal() {
    const modal = document.getElementById('appointmentModal');
    const modalTitle = document.getElementById('modalTitle');
    const form = document.getElementById('appointmentForm');
    const dateInput = document.getElementById('appointment_date');
    
    if (!modal || !modalTitle || !form || !dateInput) return;

    _waitlistBetweenConfirmAccepted = false;
    _conflictBetweenConfirmAccepted = false;
    
    modalTitle.textContent = 'Yeni Randevu';
    form.reset();
    // Edit modundan çık
    const formActionInput = document.getElementById('form_action');
    if (formActionInput) formActionInput.value = 'add';
    const apptIdInput = document.getElementById('appointment_id');
    if (apptIdInput) apptIdInput.value = '';
    const waitlistInput = document.getElementById('waitlist_id');
    if (waitlistInput) waitlistInput.value = '';
    dateInput.value = dailyViewState.selectedDate;
    
    const nameInput = document.getElementById('customer_name');
    if (nameInput) nameInput.readOnly = false;
    
    const infoAlert = document.getElementById('customerInfoAlert');
    if (infoAlert) infoAlert.style.display = 'none';

    applySendCustomerSmsPreference();
    
    document.getElementById('employee_field')?.classList.remove('calendar-mode-hidden');
    document.getElementById('date_field')?.classList.remove('calendar-mode-hidden');
    document.getElementById('time_field')?.classList.remove('calendar-mode-hidden');
    
    updateTotalPrice();
    // Sepet toggle butonunu göster, modu sıfırla
    const cartToggleBtn = document.getElementById('cartModeToggleBtn');
    if (cartToggleBtn) cartToggleBtn.style.display = '';
    if (typeof adminCartMode !== 'undefined' && adminCartMode) {
        adminCartMode = false;
        const lbl = document.getElementById('cartModeToggleLbl');
        if (cartToggleBtn) cartToggleBtn.classList.replace('btn-warning', 'btn-outline-secondary');
        if (lbl) lbl.textContent = 'Sepet Modu';
        const panel = document.getElementById('adminCartPanel');
        if (panel) panel.style.display = 'none';
        const normalSave = document.getElementById('normalSaveBtn');
        if (normalSave) normalSave.style.display = '';
        const addToCartBtn = document.getElementById('addToCartBtn');
        if (addToCartBtn) addToCartBtn.style.display = 'none';
        if (typeof adminCart !== 'undefined') adminCart = [];
        if (typeof renderAdminCart === 'function') renderAdminCart();
    }
    openModal('appointmentModal');
}

// Tam düzenleme — randevular sayfasındaki gibi tüm alanlar düzenlenebilir
function editFullAppointment(appointment) {
    const modal = document.getElementById('appointmentModal');
    const form = document.getElementById('appointmentForm');
    if (!modal || !form) return;

    // Önce formu sıfırla
    form.reset();
    document.getElementById('modalTitle').textContent = 'Randevu Düzenle';
    const formActionInput = document.getElementById('form_action');
    if (formActionInput) formActionInput.value = 'update';
    const apptIdInput = document.getElementById('appointment_id');
    if (apptIdInput) apptIdInput.value = appointment.id;

    // Müşteri / çalışan / tarih / saat / durum / not
    const formatTime = (t) => t ? String(t).substring(0, 5) : '';
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = (v ?? ''); };
    setVal('customer_phone', appointment.customer_phone);
    setVal('customer_name', appointment.customer_name);
    setVal('employee_id', appointment.employee_id);
    setVal('appointment_date', appointment.appointment_date);
    setVal('appointment_time', formatTime(appointment.appointment_time));
    setVal('appointment_status', appointment.status);
    setVal('notes', appointment.notes);

    const nameInput = document.getElementById('customer_name');
    if (nameInput) nameInput.readOnly = false;
    const infoAlert = document.getElementById('customerInfoAlert');
    if (infoAlert) infoAlert.style.display = 'none';

    // Alanları görünür yap
    document.getElementById('employee_field')?.classList.remove('calendar-mode-hidden');
    document.getElementById('date_field')?.classList.remove('calendar-mode-hidden');
    document.getElementById('time_field')?.classList.remove('calendar-mode-hidden');

    // Önce tüm hizmet/varyant seçimlerini temizle
    document.querySelectorAll('#servicesSelection .service-checkbox').forEach(cb => {
        cb.checked = false;
        const wrapper = cb.closest('.service-item-wrapper');
        const rangeDiv = wrapper?.querySelector('.range-price-input');
        if (rangeDiv) rangeDiv.style.display = 'none';
        const variantPanel = wrapper?.querySelector('.variant-panel');
        if (variantPanel) {
            variantPanel.style.display = 'none';
            variantPanel.querySelectorAll('.variant-input').forEach(v => v.checked = false);
        }
    });

    // Hizmetleri ve varyantları detail.php'den çek
    apiRequest('api/daily_view/detail.php?id=' + appointment.id)
        .then(result => {
            const detailAppointment = (result && result.success && result.appointment) ? result.appointment : null;
            if (detailAppointment) {
                const phoneFromDetail = detailAppointment.customer_phone || detailAppointment.phone || '';
                const nameFromDetail = detailAppointment.customer_name || detailAppointment.name || '';
                if (phoneFromDetail) setVal('customer_phone', phoneFromDetail);
                if (nameFromDetail && !document.getElementById('customer_name')?.value) setVal('customer_name', nameFromDetail);
            }

            const services = (result && result.success && result.services) ? result.services : [];
            services.forEach(sd => {
                const sid = String(sd.service_id || sd.id);
                const cb = document.querySelector(`#servicesSelection .service-checkbox[value="${sid}"]`);
                if (!cb) return;
                cb.checked = true;
                const wrapper = cb.closest('.service-item-wrapper');

                // Aralıklı fiyat
                const rangeDiv = wrapper?.querySelector('.range-price-input');
                if (rangeDiv) {
                    rangeDiv.style.display = 'flex';
                    const priceInput = rangeDiv.querySelector('.range-actual-price');
                    if (priceInput && sd.final_price != null) priceInput.value = parseFloat(sd.final_price);
                }

                // Varyantlar
                const variantPanel = wrapper?.querySelector('.variant-panel');
                if (variantPanel) {
                    variantPanel.style.display = 'block';
                    let variantIds = [];
                    if (sd.variant_ids) {
                        try { variantIds = typeof sd.variant_ids === 'string' ? JSON.parse(sd.variant_ids) : sd.variant_ids; } catch(e) {}
                    }
                    (variantIds || []).forEach(vid => {
                        const v = variantPanel.querySelector(`.variant-input[value="${vid}"]`);
                        if (v) v.checked = true;
                    });
                }
            });
            updateTotalPrice();
        })
        .catch(err => {
            console.error('editFullAppointment detail fetch error:', err);
            // Fallback: appointment.service_ids stringinden işaretle
            if (appointment.service_ids) {
                const ids = String(appointment.service_ids).split(',').map(s => s.trim());
                ids.forEach(id => {
                    const cb = document.querySelector(`#servicesSelection .service-checkbox[value="${id}"]`);
                    if (cb) cb.checked = true;
                });
                updateTotalPrice();
            }
        });

    // Düzenleme modunda sepet toggle gizle
    const cartToggleBtnEdit = document.getElementById('cartModeToggleBtn');
    if (cartToggleBtnEdit) cartToggleBtnEdit.style.display = 'none';
    openModal('appointmentModal');
}

function showAddBetweenModal(event, employeeId, time, date) {
    event.stopPropagation();
    
    document.getElementById('addBetweenForm').reset();
    document.getElementById('between_employee_id').value = employeeId;
    document.getElementById('between_appointment_time').value = time;
    document.getElementById('between_appointment_date').value = date;
    
    const nameInput = document.getElementById('between_customer_name');
    if (nameInput) nameInput.readOnly = false;
    
    const infoAlert = document.getElementById('betweenCustomerInfoAlert');
    if (infoAlert) infoAlert.style.display = 'none';

    applySendCustomerSmsPreference();
    
    updateBetweenTotalPrice();
    openModal('addBetweenModal');
}

function showAddModalForSlot(event, element) {
    event.stopPropagation();
    
    // GUARD: Taşıma modu aktifse modal açma!
    if (moveMode.active && moveMode.appointmentData) {
        console.log('showAddModalForSlot: Move mode active, blocking modal');
        handleAvailableSlotClick(event, element);
        return;
    }
    
    const employeeId = element.dataset.employeeId;
    const time = element.dataset.time;
    const date = element.dataset.date;
    
    if (employeeId && time && date) {
        document.getElementById('modalTitle').textContent = `Yeni Randevu (${time})`;
        document.getElementById('appointmentForm').reset();

        // Edit modalinden kalan state'i temizle (aksi halde mevcut randevu yanlışlıkla güncellenebilir)
        const formActionInput = document.getElementById('form_action');
        if (formActionInput) formActionInput.value = 'add';
        const apptIdInput = document.getElementById('appointment_id');
        if (apptIdInput) apptIdInput.value = '';
        
        const nameInput = document.getElementById('customer_name');
        if (nameInput) nameInput.readOnly = false;
        
        const infoAlert = document.getElementById('customerInfoAlert');
        if (infoAlert) infoAlert.style.display = 'none';

        const waitlistInput = document.getElementById('waitlist_id');
        if (waitlistInput) waitlistInput.value = '';

        applySendCustomerSmsPreference();
        
        document.getElementById('employee_field')?.classList.add('calendar-mode-hidden');
        document.getElementById('date_field')?.classList.add('calendar-mode-hidden');
        document.getElementById('time_field')?.classList.add('calendar-mode-hidden');
        
        if (employeeId > 0) document.getElementById('employee_id').value = employeeId;
        document.getElementById('appointment_date').value = date;
        document.getElementById('appointment_time').value = time;
        
        updateTotalPrice();
        // Sepet toggle göster, modu sıfırla
        const cartToggleBtnSlot = document.getElementById('cartModeToggleBtn');
        if (cartToggleBtnSlot) cartToggleBtnSlot.style.display = '';
        if (typeof adminCartMode !== 'undefined' && adminCartMode) {
            adminCartMode = false;
            const lbl2 = document.getElementById('cartModeToggleLbl');
            if (cartToggleBtnSlot) cartToggleBtnSlot.classList.replace('btn-warning', 'btn-outline-secondary');
            if (lbl2) lbl2.textContent = 'Sepet Modu';
            const panel2 = document.getElementById('adminCartPanel');
            if (panel2) panel2.style.display = 'none';
            const normalSave2 = document.getElementById('normalSaveBtn');
            if (normalSave2) normalSave2.style.display = '';
            const addToCartBtn2 = document.getElementById('addToCartBtn');
            if (addToCartBtn2) addToCartBtn2.style.display = 'none';
            if (typeof adminCart !== 'undefined') adminCart = [];
            if (typeof renderAdminCart === 'function') renderAdminCart();
        }
        openModal('appointmentModal');
    }
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    if (modalId === 'waitingListModal' && typeof toggleWaitlistInlineForm === 'function') {
        toggleWaitlistInlineForm(false);
    }
    
    modal.style.display = 'flex';
    modal.style.visibility = 'visible';
    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    document.body.classList.add('modal-scroll-lock');
    
    setTimeout(() => {
        const firstInput = modal.querySelector('input:not([type="hidden"]), select, textarea, button');
        if (firstInput) firstInput.focus();
    }, 100);
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    
    modal.style.display = 'none';
    modal.style.visibility = 'hidden';
    modal.style.opacity = '0';
    modal.style.pointerEvents = 'none';
    modal.classList.remove('active');
    const anyOpen = Array.from(document.querySelectorAll('.modal.active')).length > 0;
    if (!anyOpen) {
        document.body.style.overflow = '';
        document.body.classList.remove('modal-scroll-lock');
    }
    
    const form = modal.querySelector('form');
    if (form) form.reset();
    // appointmentModal kapanırsa sepeti temizle
    if (modalId === 'appointmentModal') {
        _waitlistBetweenConfirmAccepted = false;
        _conflictBetweenConfirmAccepted = false;
        if (typeof adminCartMode !== 'undefined' && adminCartMode) {
            adminCartMode = false;
            const cartBtn = document.getElementById('cartModeToggleBtn');
            const cartLbl = document.getElementById('cartModeToggleLbl');
            if (cartBtn) cartBtn.classList.replace('btn-warning', 'btn-outline-secondary');
            if (cartLbl) cartLbl.textContent = 'Sepet Modu';
            const cartPanel = document.getElementById('adminCartPanel');
            if (cartPanel) cartPanel.style.display = 'none';
            const normalSave = document.getElementById('normalSaveBtn');
            if (normalSave) normalSave.style.display = '';
            const addToCartBtn2 = document.getElementById('addToCartBtn');
            if (addToCartBtn2) addToCartBtn2.style.display = 'none';
        }
        if (typeof adminCart !== 'undefined') adminCart = [];
    }
}

// Randevu tıklama işlemleri
function handleAppointmentItemClick(event, item) {
    event.stopPropagation();
    
    if (event.target.closest('.add-between-btn')) return;
    
    const appointmentData = JSON.parse(item.dataset.appointmentDetails);
    showAppointmentMenuForItem(event, item, appointmentData);
}

function handleSlotClick(event, element) {
    event.stopPropagation();
    
    // Taşıma modu aktifse - dolu slot'a tıklanmış demek, uyar ve iptal et
    if (moveMode.active && moveMode.appointmentData) {
        console.log('handleSlotClick: Move mode active but non-available slot clicked');
        _notify('Lütfen yeşil (boş) bir alana tıklayın veya iptal edin', 'warning');
        return; // Hiçbir işlem yapma
    }
    
    // Drag işlemi sırasında tıklamayı yoksay (farenin sol tuşu basılıysa = drag)
    if (event.buttons === 1 && currentDragData) {
        return;
    }
    
    if (event.target.closest('.appointment-item')) return;
    
    if (element.classList.contains('slot-available') || element.classList.contains('available')) {
        // Normal mod - yeni randevu ekle
        showAddModalForSlot(event, element);
    }
}

// Randevu menüsü
const menu = document.getElementById('appointmentMenu');

function createMenuOverlay() {
    let overlay = document.getElementById('menuOverlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'menuOverlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.3);z-index:99998;display:none;';
        overlay.onclick = hideMenu;
        document.body.appendChild(overlay);
    }
    return overlay;
}

function showMenuOverlay() {
    const overlay = createMenuOverlay();
    overlay.style.display = 'block';
}

function hideMenu() {
    if (menu) menu.style.display = 'none';
    const overlay = document.getElementById('menuOverlay');
    if (overlay) overlay.style.display = 'none';
}

function showAppointmentMenuForItem(event, item, appointmentData) {
    event.stopPropagation();
    event.preventDefault();
    
    const rect = item.getBoundingClientRect();
    menu.innerHTML = '';
    
    if (appointmentData.status === 'break') {
        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'delete';
        deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Sil';
        deleteBtn.onclick = () => {
            hideMenu();
            showConfirmationModal('Boş Saati Sil', 'Bu rezerve edilmiş saati silmek istediğinizden emin misiniz?', () => {
                deleteAppointment(appointmentData.id);
            });
        };
        menu.appendChild(deleteBtn);
    } else if (appointmentData.status === 'pending') {
        // Onayla butonu
        const confirmBtn = document.createElement('button');
        confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Onayla';
        confirmBtn.onclick = () => {
            hideMenu();
            confirmAppointment(appointmentData);
        };
        menu.appendChild(confirmBtn);

        // Düzenle butonu (tüm bilgileri düzenle)
        const editBtn = document.createElement('button');
        editBtn.innerHTML = '<i class="fas fa-edit"></i> Düzenle';
        editBtn.onclick = () => {
            hideMenu();
            editFullAppointment(appointmentData);
        };
        menu.appendChild(editBtn);

        // Tamamla butonu (direkt tamamlama)
        const completeBtn = document.createElement('button');
        completeBtn.innerHTML = '<i class="fas fa-check"></i> Tamamla';
        completeBtn.onclick = () => {
            showCompleteModal(appointmentData);
            hideMenu();
        };
        menu.appendChild(completeBtn);
        
        // Notlar butonu
        const notesBtn = document.createElement('button');
        notesBtn.className = 'notes';
        notesBtn.innerHTML = '<i class="fas fa-sticky-note"></i> Notları Gör';
        notesBtn.onclick = () => {
            showNotesModal(appointmentData);
            hideMenu();
        };
        menu.appendChild(notesBtn);
        
        // Taşı butonu
        const moveBtn = document.createElement('button');
        moveBtn.className = 'move';
        moveBtn.innerHTML = '<i class="fas fa-arrows-alt"></i> Taşı';
        moveBtn.onclick = () => {
            hideMenu();
            startMoveMode(appointmentData);
        };
        menu.appendChild(moveBtn);
        
        // İptal Et butonu
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'delete';
        cancelBtn.innerHTML = '<i class="fas fa-times-circle"></i> İptal Et';
        cancelBtn.onclick = () => {
            hideMenu();
            showConfirmationModal('Randevuyu İptal Et', `<strong>${appointmentData.customer_name || 'Müşteri'}</strong>'in randevusunu iptal etmek istediğinizden emin misiniz?`, () => {
                cancelAppointment(appointmentData);
            });
        };
        menu.appendChild(cancelBtn);
        
        // Detaylar butonu
        const detailBtn = document.createElement('button');
        detailBtn.innerHTML = '<i class="fas fa-info-circle"></i> Detaylar';
        detailBtn.onclick = () => {
            showDetailModal(appointmentData);
            hideMenu();
        };
        menu.appendChild(detailBtn);

        // Sil butonu
        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'delete';
        deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Sil';
        deleteBtn.onclick = () => {
            hideMenu();
            showConfirmationModal('Randevuyu Sil', `<strong>${appointmentData.customer_name || 'Müşteri'}</strong>'in randevusunu kalıcı olarak silmek istediğinizden emin misiniz?`, () => {
                deleteAppointment(appointmentData.id);
            });
        };
        menu.appendChild(deleteBtn);
    } else {
        if (appointmentData.status === 'completed') {
            // Tam düzenleme (müşteri/çalışan/saat dahil)
            const editFullBtn = document.createElement('button');
            editFullBtn.innerHTML = '<i class="fas fa-edit"></i> Düzenle';
            editFullBtn.onclick = () => {
                hideMenu();
                editFullAppointment(appointmentData);
            };
            menu.appendChild(editFullBtn);

            // Hizmet/ödeme düzenle (eski sınırlı düzenleme)
            const editPayBtn = document.createElement('button');
            editPayBtn.innerHTML = '<i class="fas fa-money-bill-wave"></i> Hizmet/Ödeme Düzenle';
            editPayBtn.onclick = () => {
                showEditModal(appointmentData);
                hideMenu();
            };
            menu.appendChild(editPayBtn);
        } else if (appointmentData.status === 'confirmed') {
            // Düzenle butonu (tüm bilgileri düzenle)
            const editBtn = document.createElement('button');
            editBtn.innerHTML = '<i class="fas fa-edit"></i> Düzenle';
            editBtn.onclick = () => {
                hideMenu();
                editFullAppointment(appointmentData);
            };
            menu.appendChild(editBtn);

            const completeBtn = document.createElement('button');
            completeBtn.innerHTML = '<i class="fas fa-check"></i> Tamamla';
            completeBtn.onclick = () => {
                showCompleteModal(appointmentData);
                hideMenu();
            };
            menu.appendChild(completeBtn);
            
            // İptal Et butonu
            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'delete';
            cancelBtn.innerHTML = '<i class="fas fa-times-circle"></i> İptal Et';
            cancelBtn.onclick = () => {
                hideMenu();
                showConfirmationModal('Randevuyu İptal Et', `<strong>${appointmentData.customer_name || 'Müşteri'}</strong>'in randevusunu iptal etmek istediğinizden emin misiniz?`, () => {
                    cancelAppointment(appointmentData);
                });
            };
            menu.appendChild(cancelBtn);
        }
        
        const notesBtn = document.createElement('button');
        notesBtn.className = 'notes';
        notesBtn.innerHTML = '<i class="fas fa-sticky-note"></i> Notları Gör';
        notesBtn.onclick = () => {
            showNotesModal(appointmentData);
            hideMenu();
        };
        menu.appendChild(notesBtn);
        
        // Taşı butonu (completed ve cancelled hariç tüm durumlar için)
        if (appointmentData.status !== 'cancelled') {
            const moveBtn = document.createElement('button');
            moveBtn.className = 'move';
            moveBtn.innerHTML = '<i class="fas fa-arrows-alt"></i> Taşı';
            moveBtn.onclick = () => {
                hideMenu();
                startMoveMode(appointmentData);
            };
            menu.appendChild(moveBtn);
        }
        
        // Detaylar butonu
        const detailBtn = document.createElement('button');
        detailBtn.innerHTML = '<i class="fas fa-info-circle"></i> Detaylar';
        detailBtn.onclick = () => {
            showDetailModal(appointmentData);
            hideMenu();
        };
        menu.appendChild(detailBtn);

        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'delete';
        deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Sil';
        deleteBtn.onclick = () => {
            hideMenu();
            showConfirmationModal('Randevuyu Sil', `<strong>${appointmentData.customer_name || 'Müşteri'}</strong>'in randevusunu kalıcı olarak silmek istediğinizden emin misiniz?`, () => {
                deleteAppointment(appointmentData.id);
            });
        };
        menu.appendChild(deleteBtn);
    }
    
    if (window.innerWidth <= 768) {
        showMenuOverlay();
    }
    
    menu.style.display = 'block';
    
    const viewportHeight = window.innerHeight;
    const viewportWidth = window.innerWidth;
    const menuHeight = menu.offsetHeight || 150;
    const menuWidth = menu.offsetWidth || 160;
    
    if (viewportWidth <= 768) {
        menu.style.position = 'fixed';
        menu.style.top = '50%';
        menu.style.left = '50%';
        menu.style.transform = 'translate(-50%, -50%)';
    } else {
        menu.style.position = 'fixed';
        menu.style.transform = 'none';
        
        let topPos = rect.bottom + 5;
        let leftPos = rect.left;
        
        if (topPos + menuHeight > viewportHeight - 10) {
            topPos = rect.top - menuHeight - 5;
        }
        
        if (leftPos + menuWidth > viewportWidth - 10) {
            leftPos = viewportWidth - menuWidth - 20;
        }
        
        if (leftPos < 10) leftPos = 10;
        
        menu.style.top = `${topPos}px`;
        menu.style.left = `${leftPos}px`;
    }
}

function showNotesModal(appointment) {
    const notesDisplay = document.getElementById('notesDisplay');
    
    if (appointment.notes && appointment.notes.trim() !== '') {
        notesDisplay.textContent = appointment.notes.trim();
        notesDisplay.classList.remove('notes-empty');
    } else {
        notesDisplay.innerHTML = '<div class="notes-empty">Bu randevu için not bulunmuyor.</div>';
        notesDisplay.classList.add('notes-empty');
    }
    
    openModal('notesModal');
}

// Detay modalı
function showDetailModal(appointment) {
    const body = document.getElementById('detailModalBody');
    body.innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x"></i><p style="margin-top:0.5rem;">Yükleniyor...</p></div>';
    openModal('detailModal');

    fetch('api/daily_view/detail.php?id=' + appointment.id)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = '<div style="text-align:center;color:#ef4444;padding:1rem;"><i class="fas fa-exclamation-circle"></i> ' + (data.message || 'Hata oluştu') + '</div>';
                return;
            }
            const apt = data.appointment;
            const svcs = data.services || [];
            const cs = data.customer_stats || {};

            const statusMap = {
                pending: {label:'Bekliyor', cls:'s-pending', icon:'fa-clock'},
                confirmed: {label:'Onaylandı', cls:'s-confirmed', icon:'fa-check-circle'},
                completed: {label:'Tamamlandı', cls:'s-completed', icon:'fa-check-double'},
                cancelled: {label:'İptal', cls:'s-cancelled', icon:'fa-times-circle'}
            };
            const st = statusMap[apt.status] || {label:apt.status, cls:'', icon:'fa-question'};

            const payMap = {cash:'Nakit', bank_transfer:'Havale/EFT', credit_card:'Kredi Kartı', not_paid:'Alınmadı', mixed:'Karma'};
            const payLabel = payMap[apt.payment_method] || apt.payment_method || '-';

            function fmtDate(d) {
                if (!d) return '-';
                const parts = d.split('-');
                return parts[2] + '.' + parts[1] + '.' + parts[0];
            }

            let servicesHtml = '';
            svcs.forEach(s => {
                let variantText = '';
                if (s.variants && s.variants.length > 0) {
                    variantText = '<div class="svc-variants">' + s.variants.map(v => v.name + (parseFloat(v.extra_price) > 0 ? ' (+' + formatNum(v.extra_price) + ' ₺)' : '')).join(', ') + '</div>';
                }
                const price = s.final_price !== null && s.final_price !== undefined ? parseFloat(s.final_price) : parseFloat(s.service_price);
                servicesHtml += `<div class="detail-service-row">
                    <div><div class="svc-name">${s.service_name}</div>${variantText}</div>
                    <div class="svc-price">${formatTL(price)}</div>
                </div>`;
            });
            if (!servicesHtml) {
                servicesHtml = '<div style="color:#94a3b8;font-size:0.85rem;padding:6px 0;">Hizmet bilgisi bulunamadı</div>';
            }

            let customerSection = '';
            if (apt.customer_id) {
                customerSection = `
                <div class="detail-section">
                    <div class="detail-section-title"><i class="fas fa-user"></i> Müşteri Bilgileri</div>
                    <div class="detail-grid">
                        <div class="detail-item"><span class="dl">Ad Soyad</span><span class="dv">${apt.customer_name || '-'}</span></div>
                        <div class="detail-item"><span class="dl">Telefon</span><span class="dv"><a href="tel:${normalizePhoneForDial(apt.customer_phone || '')}" style="color:#3b82f6;text-decoration:none;">${apt.customer_phone || '-'}</a></span></div>
                        ${apt.customer_email ? '<div class="detail-item full"><span class="dl">E-posta</span><span class="dv">' + apt.customer_email + '</span></div>' : ''}
                    </div>
                </div>
                <div class="detail-section">
                    <div class="detail-section-title"><i class="fas fa-chart-bar"></i> Müşteri Geçmişi</div>
                    <div class="customer-stat-grid">
                        <div class="customer-stat-card"><div class="csv">${parseInt(cs.total_appointments) || 0}</div><div class="csl">Toplam Randevu</div></div>
                        <div class="customer-stat-card"><div class="csv">${parseInt(cs.total_completed) || 0}</div><div class="csl">Tamamlanan</div></div>
                        <div class="customer-stat-card"><div class="csv">${formatTL(cs.total_spent || 0)}</div><div class="csl">Toplam Harcama</div></div>
                    </div>
                    <div class="detail-grid" style="margin-top:8px;">
                        ${cs.first_visit ? '<div class="detail-item"><span class="dl">İlk Ziyaret</span><span class="dv">' + fmtDate(cs.first_visit) + '</span></div>' : ''}
                        ${cs.last_visit ? '<div class="detail-item"><span class="dl">Son Ziyaret</span><span class="dv">' + fmtDate(cs.last_visit) + '</span></div>' : ''}
                    </div>
                </div>`;
            }

            body.innerHTML = `
                <div class="detail-section">
                    <div class="detail-section-title"><i class="fas fa-calendar-check"></i> Randevu Bilgileri</div>
                    <div class="detail-grid">
                        <div class="detail-item"><span class="dl">Tarih</span><span class="dv">${fmtDate(apt.appointment_date)}</span></div>
                        <div class="detail-item"><span class="dl">Saat</span><span class="dv">${apt.appointment_time ? apt.appointment_time.substring(0,5) : '-'}</span></div>
                        <div class="detail-item"><span class="dl">Durum</span><span class="dv"><span class="detail-status-badge ${st.cls}"><i class="fas ${st.icon}"></i> ${st.label}</span></span></div>
                        <div class="detail-item"><span class="dl">Çalışan</span><span class="dv">${apt.employee_name || '-'}</span></div>
                        <div class="detail-item"><span class="dl">Toplam Tutar</span><span class="dv" style="color:#059669;font-size:1rem;">${formatTL(apt.total_price || 0)}</span></div>
                        <div class="detail-item"><span class="dl">Ödeme</span><span class="dv">${payLabel}</span></div>
                        <div class="detail-item"><span class="dl">Süre</span><span class="dv">${apt.total_duration || '-'} dk</span></div>
                        ${apt.created_at ? '<div class="detail-item"><span class="dl">Oluşturulma</span><span class="dv">' + new Date(apt.created_at).toLocaleString('tr-TR') + '</span></div>' : ''}
                    </div>
                    ${apt.notes ? '<div class="detail-item full" style="margin-top:8px;"><span class="dl">Notlar</span><span class="dv" style="font-weight:400;font-size:0.85rem;color:#475569;white-space:pre-wrap;">' + escapeHtml(apt.notes) + '</span></div>' : ''}
                </div>
                <div class="detail-section">
                    <div class="detail-section-title"><i class="fas fa-concierge-bell"></i> Hizmetler</div>
                    ${servicesHtml}
                </div>
                ${customerSection}
            `;
        })
        .catch(err => {
            body.innerHTML = '<div style="text-align:center;color:#ef4444;padding:1rem;"><i class="fas fa-exclamation-circle"></i> Bağlantı hatası</div>';
            console.error('Detail fetch error:', err);
        });
}

// Tamamlama modalı
function showCompleteModal(appointment) {
    document.getElementById('completeAppointmentId').value = appointment.id;
    
    const serviceListContainer = document.getElementById('serviceList');
    serviceListContainer.innerHTML = '<div style="text-align:center;padding:10px;color:#6b7280;"><i class="fas fa-spinner fa-spin"></i> Yükleniyor...</div>';
    
    document.getElementById('complete_notes').value = appointment.notes || '';
    
    const selectedServiceIds = appointment.service_ids ? appointment.service_ids.split(',') : [];
    
    // Varyant bilgilerini detail API'den çek
    apiRequest('api/daily_view/detail.php?id=' + appointment.id)
        .then(result => {
            serviceListContainer.innerHTML = '';
            
            // service_id => detail map (varyant bilgileriyle)
            const detailMap = {};
            if (result.success && result.services) {
                result.services.forEach(sd => { detailMap[sd.service_id || sd.id] = sd; });
            }
            
            buildDailyCompleteServiceList(serviceListContainer, selectedServiceIds, detailMap);
            updateFinalPrice();
            
            serviceListContainer.querySelectorAll('.service-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const wrapper = this.closest('[style]') || this.parentElement.parentElement;
                    const rangeDiv = serviceListContainer.querySelector(`.range-price-input[data-service-id="${this.dataset.id}"]`);
                    const variantPanel = serviceListContainer.querySelector(`.complete-variant-panel[data-service-id="${this.dataset.id}"]`);
                    if (rangeDiv) {
                        rangeDiv.style.display = this.checked ? 'flex' : 'none';
                    }
                    if (variantPanel) {
                        variantPanel.style.display = this.checked ? 'block' : 'none';
                        if (!this.checked) {
                            variantPanel.querySelectorAll('.complete-variant-input').forEach(v => v.checked = false);
                        }
                    }
                    updateFinalPrice();
                    // Hizmet değişince ürün listesini güncelle
                    loadProductConsumptionDV(appointment.id);
                });
            });
            
            openModal('completeModal');
            
            // Ürün tüketim verilerini yükle
            loadProductConsumptionDV(appointment.id);
        })
        .catch(err => {
            console.error('Detail fetch error:', err);
            // Fallback: eski davranış (varyant bilgisi olmadan)
            serviceListContainer.innerHTML = '';
            buildDailyCompleteServiceList(serviceListContainer, selectedServiceIds, {});
            updateFinalPrice();
            
            serviceListContainer.querySelectorAll('.service-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const rangeDiv = serviceListContainer.querySelector(`.range-price-input[data-service-id="${this.dataset.id}"]`);
                    const variantPanel = serviceListContainer.querySelector(`.complete-variant-panel[data-service-id="${this.dataset.id}"]`);
                    if (rangeDiv) rangeDiv.style.display = this.checked ? 'flex' : 'none';
                    if (variantPanel) {
                        variantPanel.style.display = this.checked ? 'block' : 'none';
                        if (!this.checked) variantPanel.querySelectorAll('.complete-variant-input').forEach(v => v.checked = false);
                    }
                    updateFinalPrice();
                    loadProductConsumptionDV(appointment.id);
                });
            });
            
            openModal('completeModal');
            
            // Ürün tüketim verilerini yükle (fallback)
            loadProductConsumptionDV(appointment.id);
        });
}

function buildDailyCompleteServiceList(container, selectedServiceIds, detailMap) {
    const serviceVariants = window.dailyViewData?.serviceVariants || {};
    dailyViewState.services.forEach(service => {
        const isChecked = selectedServiceIds.includes(service.id.toString());
        const isRange = service.pricing_type === 'range' && parseFloat(service.price_min) >= 0 && parseFloat(service.price_max) > 0;
        const isVariant = service.pricing_type === 'variant';
        const detail = detailMap[service.id];
        const variants = serviceVariants[service.id] || [];
        
        // Varyantlı hizmetlerde appointment_services.final_price kullan
        let effectivePrice;
        if (detail && detail.final_price !== null && detail.final_price !== undefined) {
            // Aralıklı/varyantlı hizmetlerde kaydedilmiş nihai fiyatı koru
            effectivePrice = parseFloat(detail.final_price);
        } else if (isRange) {
            effectivePrice = parseFloat(service.price_min);
        } else {
            effectivePrice = parseFloat(service.price);
        }
        
        // Mevcut seçili varyant ID'leri
        let selectedVariantIds = [];
        if (isVariant && detail && detail.variant_ids) {
            try {
                selectedVariantIds = JSON.parse(detail.variant_ids);
            } catch(e) { selectedVariantIds = []; }
        }
        
        // Fiyat etiketi
        let priceLabel;
        if (isVariant) {
            priceLabel = variants.length > 0 ? '(' + formatNum(service.price) + '+' + ' TL)' : '(' + formatNum(effectivePrice) + ' TL)';
        } else if (isRange) {
            priceLabel = '<span style="color:#8b5cf6;font-size:0.8em;">(Aralık: ' + formatNum(service.price_min) + ' - ' + formatNum(service.price_max) + ' TL)</span>';
        } else {
            priceLabel = '(' + formatNum(effectivePrice) + ' TL)';
        }
        
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'margin-bottom: 6px;';
        
        const label = document.createElement('label');
        label.style.cssText = 'display: flex; align-items: center; gap: 6px;';
        label.innerHTML = `<input type="checkbox" class="service-checkbox" value="${service.price}" data-id="${service.id}" data-base-price="${service.price}" data-is-range="${isRange ? '1' : '0'}" data-is-variant="${isVariant ? '1' : '0'}" data-min="${service.price_min || service.price}" data-max="${service.price_max || service.price}" ${isChecked ? 'checked' : ''}> ${service.name} ${priceLabel}`;
        wrapper.appendChild(label);
        
        if (isRange) {
            const rangeInput = document.createElement('div');
            rangeInput.className = 'range-price-input';
            rangeInput.dataset.serviceId = service.id;
            rangeInput.style.cssText = 'margin-left: 24px; margin-top: 4px; display: ' + (isChecked ? 'flex' : 'none') + '; align-items: center; gap: 6px;';
            rangeInput.innerHTML = `<span style="font-size:0.8em; color:#6b7280;">Alınan tutar:</span><input type="number" class="range-actual-price" data-service-id="${service.id}" value="${effectivePrice}" min="${service.price_min}" max="${service.price_max}" step="any" style="width:90px; padding:4px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.85em; text-align:right;" onchange="updateFinalPrice()"><span style="font-size:0.8em; color:#6b7280;">TL</span>`;
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
                let vHtml = `<input type="${inputType}" class="variant-input complete-variant-input" value="${v.id}" data-service-id="${service.id}" data-extra-price="${v.extra_price}" data-extra-duration="${v.extra_duration}" ${isVarChecked ? 'checked' : ''} onchange="updateFinalPrice()"> <span>${v.name}</span>`;
                if (parseFloat(v.extra_price) > 0) vHtml += `<span style="color:#8b5cf6;font-weight:600;">+${formatNum(v.extra_price)} TL</span>`;
                if (parseInt(v.extra_duration) > 0) vHtml += `<span style="color:#6b7280;font-size:0.85em;">(+${v.extra_duration} dk)</span>`;
                vLabel.innerHTML = vHtml;
                variantPanel.appendChild(vLabel);
            });
            wrapper.appendChild(variantPanel);
        }
        
        container.appendChild(wrapper);
    });
}

// Düzenleme modalı
function showEditModal(appointment) {
    document.getElementById('editAppointmentId').value = appointment.id;
    
    const editServiceListContainer = document.getElementById('editServiceList');
    editServiceListContainer.innerHTML = '<div style="text-align:center;padding:10px;color:#6b7280;"><i class="fas fa-spinner fa-spin"></i> Yükleniyor...</div>';
    
    document.getElementById('edit_notes').value = appointment.notes || '';
    document.getElementById('edit_final_price').value = appointment.total_price;
    
    const selectedServiceIds = appointment.service_ids ? appointment.service_ids.split(',') : [];
    
    // Varyant bilgilerini detail API'den çek
    apiRequest('api/daily_view/detail.php?id=' + appointment.id)
        .then(result => {
            editServiceListContainer.innerHTML = '';
            
            const detailMap = {};
            if (result.success && result.services) {
                result.services.forEach(sd => { detailMap[sd.service_id || sd.id] = sd; });
            }
            
            buildDailyEditServiceList(editServiceListContainer, selectedServiceIds, detailMap);
            
            editServiceListContainer.querySelectorAll('.edit-service-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const rangeDiv = editServiceListContainer.querySelector(`.edit-range-price-input[data-service-id="${this.dataset.id}"]`);
                    const variantPanel = editServiceListContainer.querySelector(`.edit-variant-panel[data-service-id="${this.dataset.id}"]`);
                    if (rangeDiv) rangeDiv.style.display = this.checked ? 'flex' : 'none';
                    if (variantPanel) {
                        variantPanel.style.display = this.checked ? 'block' : 'none';
                        if (!this.checked) variantPanel.querySelectorAll('.edit-variant-input').forEach(v => v.checked = false);
                    }
                    updateEditFinalPrice();
                });
            });
            
            openModal('editModal');
        })
        .catch(err => {
            console.error('Detail fetch error:', err);
            editServiceListContainer.innerHTML = '';
            buildDailyEditServiceList(editServiceListContainer, selectedServiceIds, {});
            
            editServiceListContainer.querySelectorAll('.edit-service-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const rangeDiv = editServiceListContainer.querySelector(`.edit-range-price-input[data-service-id="${this.dataset.id}"]`);
                    const variantPanel = editServiceListContainer.querySelector(`.edit-variant-panel[data-service-id="${this.dataset.id}"]`);
                    if (rangeDiv) rangeDiv.style.display = this.checked ? 'flex' : 'none';
                    if (variantPanel) {
                        variantPanel.style.display = this.checked ? 'block' : 'none';
                        if (!this.checked) variantPanel.querySelectorAll('.edit-variant-input').forEach(v => v.checked = false);
                    }
                    updateEditFinalPrice();
                });
            });
            
            openModal('editModal');
        });
}

function buildDailyEditServiceList(container, selectedServiceIds, detailMap) {
    const serviceVariants = window.dailyViewData?.serviceVariants || {};
    dailyViewState.services.forEach(service => {
        const isChecked = selectedServiceIds.includes(service.id.toString());
        const isRange = service.pricing_type === 'range' && parseFloat(service.price_min) >= 0 && parseFloat(service.price_max) > 0;
        const isVariant = service.pricing_type === 'variant';
        const detail = detailMap[service.id];
        const variants = serviceVariants[service.id] || [];

        let effectivePrice;
        if (detail && detail.final_price !== null && detail.final_price !== undefined) {
            // Aralıklı/varyantlı hizmetlerde kaydedilmiş nihai fiyatı koru
            effectivePrice = parseFloat(detail.final_price);
        } else if (isRange) {
            effectivePrice = parseFloat(service.price_min);
        } else {
            effectivePrice = parseFloat(service.price);
        }

        // Mevcut seçili varyant ID'leri
        let selectedVariantIds = [];
        if (isVariant && detail && detail.variant_ids) {
            try { selectedVariantIds = JSON.parse(detail.variant_ids); } catch(e) { selectedVariantIds = []; }
        }

        let priceLabel;
        if (isVariant) {
            priceLabel = variants.length > 0 ? '(' + formatNum(service.price) + '+' + ' TL)' : '(' + formatNum(effectivePrice) + ' TL)';
        } else if (isRange) {
            priceLabel = '<span style="color:#8b5cf6;font-size:0.8em;">(Aralık: ' + formatNum(service.price_min) + ' - ' + formatNum(service.price_max) + ' TL)</span>';
        } else {
            priceLabel = '(' + formatNum(effectivePrice) + ' TL)';
        }

        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'margin-bottom: 6px;';
        
        const label = document.createElement('label');
        label.style.cssText = 'display: flex; align-items: center; gap: 6px;';
        label.innerHTML = `<input type="checkbox" class="edit-service-checkbox" value="${service.price}" data-id="${service.id}" data-base-price="${service.price}" data-is-range="${isRange ? '1' : '0'}" data-is-variant="${isVariant ? '1' : '0'}" data-min="${service.price_min || service.price}" data-max="${service.price_max || service.price}" ${isChecked ? 'checked' : ''}> ${service.name} ${priceLabel}`;
        wrapper.appendChild(label);
        
        if (isRange) {
            const rangeInput = document.createElement('div');
            rangeInput.className = 'edit-range-price-input';
            rangeInput.dataset.serviceId = service.id;
            rangeInput.style.cssText = 'margin-left: 24px; margin-top: 4px; display: ' + (isChecked ? 'flex' : 'none') + '; align-items: center; gap: 6px;';
            rangeInput.innerHTML = `<span style="font-size:0.8em; color:#6b7280;">Alınan tutar:</span><input type="number" class="edit-range-actual-price" data-service-id="${service.id}" value="${effectivePrice}" min="${service.price_min}" max="${service.price_max}" step="any" style="width:90px; padding:4px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:0.85em; text-align:right;" onchange="updateEditFinalPrice()"><span style="font-size:0.8em; color:#6b7280;">TL</span>`;
            wrapper.appendChild(rangeInput);
        }
        
        if (isVariant && variants.length > 0) {
            const isMultiple = parseInt(service.is_multiple || 0);
            const variantPanel = document.createElement('div');
            variantPanel.className = 'variant-panel edit-variant-panel';
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
                let vHtml = `<input type="${inputType}" class="variant-input edit-variant-input" value="${v.id}" data-service-id="${service.id}" data-extra-price="${v.extra_price}" data-extra-duration="${v.extra_duration}" ${isVarChecked ? 'checked' : ''} onchange="updateEditFinalPrice()"> <span>${v.name}</span>`;
                if (parseFloat(v.extra_price) > 0) vHtml += `<span style="color:#8b5cf6;font-weight:600;">+${formatNum(v.extra_price)} TL</span>`;
                if (parseInt(v.extra_duration) > 0) vHtml += `<span style="color:#6b7280;font-size:0.85em;">(+${v.extra_duration} dk)</span>`;
                vLabel.innerHTML = vHtml;
                variantPanel.appendChild(vLabel);
            });
            wrapper.appendChild(variantPanel);
        }
        
        container.appendChild(wrapper);
    });
}

function updateFinalPrice() {
    let total = 0;
    document.querySelectorAll('#serviceList .service-checkbox:checked').forEach(checkbox => {
        if (checkbox.dataset.isRange === '1') {
            const rangeInput = document.querySelector(`.range-actual-price[data-service-id="${checkbox.dataset.id}"]`);
            total += rangeInput ? parseFloat(rangeInput.value) || 0 : parseFloat(checkbox.dataset.basePrice || checkbox.value);
        } else if (checkbox.dataset.isVariant === '1') {
            total += parseFloat(checkbox.dataset.basePrice || checkbox.value);
            document.querySelectorAll(`#serviceList .complete-variant-panel[data-service-id="${checkbox.dataset.id}"] .complete-variant-input:checked`).forEach(v => {
                total += parseFloat(v.dataset.extraPrice || 0);
            });
        } else {
            total += parseFloat(checkbox.value);
        }
    });
    document.getElementById('final_price').value = total.toFixed(2);
}

function updateEditFinalPrice() {
    let total = 0;
    document.querySelectorAll('#editServiceList .edit-service-checkbox:checked').forEach(checkbox => {
        if (checkbox.dataset.isRange === '1') {
            const rangeInput = document.querySelector(`.edit-range-actual-price[data-service-id="${checkbox.dataset.id}"]`);
            total += rangeInput ? parseFloat(rangeInput.value) || 0 : parseFloat(checkbox.dataset.basePrice || checkbox.value);
        } else if (checkbox.dataset.isVariant === '1') {
            total += parseFloat(checkbox.dataset.basePrice || checkbox.value);
            document.querySelectorAll(`#editServiceList .edit-variant-panel[data-service-id="${checkbox.dataset.id}"] .edit-variant-input:checked`).forEach(v => {
                total += parseFloat(v.dataset.extraPrice || 0);
            });
        } else {
            total += parseFloat(checkbox.value);
        }
    });
    document.getElementById('edit_final_price').value = total.toFixed(2);
}

// Onay modalı
let onConfirmCallback = () => {};
let _waitlistBetweenConfirmAccepted = false;
let _conflictBetweenConfirmAccepted = false;

function hasSlotConflictForWaitlist(dateValue, employeeIdValue, timeValue) {
    if (!dateValue || !employeeIdValue || !timeValue) return false;
    if (String(dateValue) !== String(dailyViewState.selectedDate)) return false;

    const empKey = String(employeeIdValue);
    const empSchedule = dailyViewState.schedule?.[empKey] || dailyViewState.schedule?.[Number(employeeIdValue)] || null;
    if (!empSchedule) return false;

    const slotItems = empSchedule[timeValue];
    if (!Array.isArray(slotItems) || slotItems.length === 0) return false;

    // İptal edilenler hariç mevcut randevular dolu kabul edilir
    return slotItems.some((x) => String(x?.status || '') !== 'cancelled');
}

function showConfirmationModal(title, message, callback) {
    // Her seferinde taze element al (DOM güncellenince referans kopmasın)
    const modal = document.getElementById('confirmModal');
    if (!modal) {
        console.error('confirmModal not found!');
        return;
    }
    
    const titleEl = document.getElementById('confirmTitle');
    const messageEl = document.getElementById('confirmMessage');
    
    if (titleEl) titleEl.textContent = title;
    if (messageEl) messageEl.innerHTML = message;
    
    onConfirmCallback = callback;
    
    // Modal'ı göster
    modal.style.display = 'flex';
    modal.style.visibility = 'visible';
    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';
    
    console.log('showConfirmationModal: Modal displayed');
}

// Randevu ekleme
async function submitAppointmentForm() {
    const form = document.getElementById('appointmentForm');
    const submitBtn = form.querySelector('button[type="submit"]');
    const btnText = submitBtn?.querySelector('.btn-text');
    const loading = submitBtn?.querySelector('.loading');
    
    const checkboxes = form.querySelectorAll('input[name="service_ids[]"]:checked');
    if (checkboxes.length === 0) {
        _notify('Lütfen en az bir hizmet seçin', 'warning');
        return;
    }
    
    // Varyantlı hizmet seçimi validasyonu
    let variantError = false;
    checkboxes.forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const wrapper = cb.closest('.service-item-wrapper');
            const checkedVariants = wrapper?.querySelectorAll('.variant-input:checked');
            if (!checkedVariants || checkedVariants.length === 0) {
                variantError = true;
            }
        }
    });
    if (variantError) {
        _notify('Lütfen varyantlı hizmetler için varyant seçimi yapın', 'warning');
        return;
    }

    const formAction = (document.getElementById('form_action')?.value || 'add');
    const waitlistId = parseInt(document.getElementById('waitlist_id')?.value || '0', 10);
    if (formAction === 'add' && waitlistId > 0 && !_waitlistBetweenConfirmAccepted) {
        const dateValue = form.appointment_date?.value || dailyViewState.selectedDate;
        const employeeIdValue = form.employee_id?.value || '';
        const timeValue = form.appointment_time?.value || '';
        if (hasSlotConflictForWaitlist(dateValue, employeeIdValue, timeValue)) {
            showConfirmationModal(
                'Dolu Saat',
                'Bu saat dolu görünüyor. Müşteriyi araya almak istediğinize emin misiniz?',
                function() {
                    _waitlistBetweenConfirmAccepted = true;
                    submitAppointmentForm();
                }
            );
            return;
        }
    }
    
    const sendSmsChecked = !!form.querySelector('[name="send_customer_sms"]')?.checked;
    if (formAction === 'add' && sendSmsChecked && typeof window.warnSmsIfUnavailable === 'function') {
        window.warnSmsIfUnavailable(true);
    }

    if (submitBtn) submitBtn.disabled = true;
    if (loading) loading.style.display = 'inline-block';
    if (btnText) btnText.textContent = 'Kaydediliyor...';
    
    const serviceIds = Array.from(checkboxes).map(cb => cb.dataset.serviceId || cb.dataset.id || cb.value);
    
    // Aralıklı fiyatlar için özel fiyat toplama
    const rangePrices = {};
    checkboxes.forEach(cb => {
        if (cb.dataset.isRange === '1') {
            const sid = cb.dataset.serviceId || cb.dataset.id || cb.value;
            const wrapper = cb.closest('.service-item-wrapper');
            const rangeInput = wrapper?.querySelector('.range-actual-price');
            if (rangeInput) {
                rangePrices[sid] = parseFloat(rangeInput.value) || 0;
            }
        }
    });
    
    // Varyant seçimleri
    const variantData = {};
    checkboxes.forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const sid = cb.dataset.serviceId || cb.dataset.id || cb.value;
            const wrapper = cb.closest('.service-item-wrapper');
            const selectedVariants = Array.from(wrapper?.querySelectorAll('.variant-input:checked') || []).map(v => ({
                id: parseInt(v.value),
                extra_price: parseFloat(v.dataset.extraPrice || 0),
                extra_duration: parseInt(v.dataset.extraDuration || 0)
            }));
            if (selectedVariants.length > 0) {
                variantData[sid] = selectedVariants;
            }
        }
    });
    
    try {
        const apptId = parseInt(document.getElementById('appointment_id')?.value || '0', 10);

        const formTotalEl = document.getElementById('totalPrice') || document.getElementById('final_price') || form.querySelector('[name="total_price"], #appointment_total_price');
        const formTotalPrice = formTotalEl ? parseFloat(formTotalEl.value || formTotalEl.textContent) : NaN;

        let result;
        if (formAction === 'update' && apptId > 0) {
            // Tam düzenleme — randevular sayfasındaki gibi
            result = await apiRequest('api/daily_view/update.php', 'POST', {
                appointment_id: apptId,
                full_edit: true,
                appointment_date: form.appointment_date.value,
                appointment_time: form.appointment_time.value,
                employee_id: form.employee_id.value,
                customer_name: form.customer_name.value,
                customer_phone: form.customer_phone.value,
                service_ids: serviceIds,
                range_prices: rangePrices,
                total_price: Number.isFinite(formTotalPrice) ? formTotalPrice : undefined,
                variant_data: variantData,
                notes: form.notes?.value || '',
                status: form.querySelector('[name="status"]')?.value || 'confirmed'
            });
        } else {
            const useBetween = _conflictBetweenConfirmAccepted || _waitlistBetweenConfirmAccepted ? 1 : 0;
            result = await apiRequest('api/daily_view/create.php', 'POST', {
                appointment_date: form.appointment_date.value,
                appointment_time: form.appointment_time.value,
                employee_id: form.employee_id.value,
                customer_name: form.customer_name.value,
                customer_phone: form.customer_phone.value,
                service_ids: serviceIds,
                range_prices: rangePrices,
                total_price: Number.isFinite(formTotalPrice) ? formTotalPrice : undefined,
                variant_data: variantData,
                notes: form.notes?.value || '',
                status: form.querySelector('[name="status"]')?.value || 'confirmed',
                send_customer_sms: form.querySelector('[name="send_customer_sms"]')?.checked ? 1 : 0,
                waitlist_id: parseInt(document.getElementById('waitlist_id')?.value || '0', 10),
                is_between: useBetween
            });
        }
        
        if (result.success) {
            _waitlistBetweenConfirmAccepted = false;
            _conflictBetweenConfirmAccepted = false;
            closeModal('appointmentModal');
            _notify(result.message, 'success');
            notifyAppointmentSmsOutcome(result);
            const refreshDate = form.appointment_date?.value || dailyViewState.selectedDate;
            loadDailyViewData(refreshDate);
        } else if (result.conflict && result.needs_confirm) {
            // Aynı başlangıç saatinde mevcut randevu → araya ekleme onayı
            if (submitBtn) submitBtn.disabled = false;
            if (loading) loading.style.display = 'none';
            if (btnText) btnText.innerHTML = '<i class="fas fa-save"></i> Kaydet';
            showConfirmationModal(
                'Bu saatte randevu var',
                (result.message || 'Bu saatte bir randevu var.') + '<br><br><strong>Araya eklensin mi?</strong>',
                function() {
                    _conflictBetweenConfirmAccepted = true;
                    submitAppointmentForm();
                }
            );
        } else {
            _waitlistBetweenConfirmAccepted = false;
            _conflictBetweenConfirmAccepted = false;
            _notify(result.message, 'error');
        }
    } catch (error) {
        _waitlistBetweenConfirmAccepted = false;
        _conflictBetweenConfirmAccepted = false;
        console.error('Randevu kaydedilirken hata:', error);
        _notify('Randevu kaydedilirken hata oluştu', 'error');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
        if (loading) loading.style.display = 'none';
        if (btnText) btnText.innerHTML = '<i class="fas fa-save"></i> Kaydet';
    }
}

// Araya randevu ekleme
async function submitBetweenAppointmentForm() {
    const form = document.getElementById('addBetweenForm');
    const submitBtn = form.querySelector('button[type="submit"]');
    const btnText = submitBtn?.querySelector('.btn-text');
    const loading = submitBtn?.querySelector('.loading');

    if (form.querySelector('[name="send_customer_sms"]')?.checked && typeof window.warnSmsIfUnavailable === 'function') {
        window.warnSmsIfUnavailable(true);
    }

    // Araya ekleme modalındaki checkbox'ları hedefle (isim çakışmalarına karşı güvenli)
    let checkboxes = form.querySelectorAll('.between-service-checkbox:checked');
    if (!checkboxes || checkboxes.length === 0) {
        checkboxes = form.querySelectorAll('#betweenServicesSelection input[name="service_ids[]"]:checked');
    }
    if (checkboxes.length === 0) {
        _notify('Lütfen en az bir hizmet seçin', 'warning');
        return;
    }
    
    if (submitBtn) submitBtn.disabled = true;
    if (loading) loading.style.display = 'inline-block';
    if (btnText) btnText.textContent = 'Ekleniyor...';
    
    // Varyantlı hizmet seçimi validasyonu
    let betweenVariantError = false;
    checkboxes.forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const wrapper = cb.closest('.service-item-wrapper');
            const checkedVariants = wrapper?.querySelectorAll('.variant-input:checked');
            if (!checkedVariants || checkedVariants.length === 0) {
                betweenVariantError = true;
            }
        }
    });
    if (betweenVariantError) {
        _notify('Lütfen varyantlı hizmetler için varyant seçimi yapın', 'warning');
        if (submitBtn) submitBtn.disabled = false;
        if (loading) loading.style.display = 'none';
        if (btnText) btnText.innerHTML = '<i class="fas fa-plus"></i> Araya Ekle';
        return;
    }

    const serviceIds = Array.from(checkboxes).map(cb => cb.dataset.serviceId || cb.dataset.id || cb.value);
    
    // Aralıklı fiyatlar için özel fiyat toplama
    const rangePrices = {};
    checkboxes.forEach(cb => {
        if (cb.dataset.isRange === '1') {
            const sid = cb.dataset.serviceId || cb.dataset.id || cb.value;
            const wrapper = cb.closest('.service-item-wrapper');
            const rangeInput = wrapper?.querySelector('.range-actual-price');
            if (rangeInput) {
                rangePrices[sid] = parseFloat(rangeInput.value) || 0;
            }
        }
    });
    
    // Varyant seçimleri
    const betweenVariantData = {};
    checkboxes.forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const sid = cb.dataset.serviceId || cb.dataset.id || cb.value;
            const wrapper = cb.closest('.service-item-wrapper');
            const selectedVariants = Array.from(wrapper?.querySelectorAll('.variant-input:checked') || []).map(v => ({
                id: parseInt(v.value),
                extra_price: parseFloat(v.dataset.extraPrice || 0),
                extra_duration: parseInt(v.dataset.extraDuration || 0)
            }));
            if (selectedVariants.length > 0) {
                betweenVariantData[sid] = selectedVariants;
            }
        }
    });
    
    try {
        const betweenTotalEl = document.getElementById('between_total_price') || form.querySelector('[name="total_price"]');
        const betweenTotalPrice = betweenTotalEl ? parseFloat(betweenTotalEl.value) : null;
        const result = await apiRequest('api/daily_view/create.php', 'POST', {
            appointment_date: form.between_appointment_date.value,
            appointment_time: form.between_appointment_time.value,
            employee_id: form.between_employee_id.value,
            customer_name: form.between_customer_name.value,
            customer_phone: form.between_customer_phone.value,
            service_ids: serviceIds,
            range_prices: rangePrices,
            total_price: Number.isFinite(betweenTotalPrice) ? betweenTotalPrice : undefined,
            variant_data: betweenVariantData,
            notes: form.between_notes?.value || '',
            status: form.querySelector('[name="status"]')?.value || 'confirmed',
            send_customer_sms: form.querySelector('[name="send_customer_sms"]')?.checked ? 1 : 0,
            is_between: 1
        });
        
        if (result.success) {
            closeModal('addBetweenModal');
            _notify(result.message, 'success');
            notifyAppointmentSmsOutcome(result);
            loadDailyViewData();
        } else {
            _notify(result.message, 'error');
        }
    } catch (error) {
        console.error('Randevu eklenirken hata:', error);
        _notify('Randevu eklenirken hata oluştu', 'error');
    } finally {
        if (submitBtn) submitBtn.disabled = false;
        if (loading) loading.style.display = 'none';
        if (btnText) btnText.innerHTML = '<i class="fas fa-plus"></i> Araya Ekle';
    }
}

// Randevu silme
async function deleteAppointment(appointmentId) {
    try {
        const result = await apiRequest('api/daily_view/delete.php', 'POST', {
            appointment_id: appointmentId
        });
        
        if (result.success) {
            _notify(result.message, 'success');
            loadDailyViewData();
        } else {
            _notify(result.message, 'error');
        }
    } catch (error) {
        console.error('Randevu silinirken hata:', error);
        _notify('Randevu silinirken hata oluştu', 'error');
    }
}

// Randevu onaylama
async function confirmAppointment(appointmentData) {
    try {
        const result = await apiRequest('api/daily_view/status.php', 'POST', {
            appointment_id: appointmentData.id,
            status: 'confirmed'
        });
        
        if (result.success) {
            _notify(result.message, 'success');
            loadDailyViewData();
        } else {
            _notify(result.message, 'error');
        }
    } catch (error) {
        console.error('Randevu onaylanırken hata:', error);
        _notify('Randevu onaylanırken hata oluştu', 'error');
    }
}

// Randevu iptal etme
async function cancelAppointment(appointmentData) {
    try {
        const result = await apiRequest('api/daily_view/status.php', 'POST', {
            appointment_id: appointmentData.id,
            status: 'cancelled'
        });
        
        if (result.success) {
            _notify(result.message, 'success');
            loadDailyViewData();
        } else {
            _notify(result.message, 'error');
        }
    } catch (error) {
        console.error('Randevu iptal edilirken hata:', error);
        _notify('Randevu iptal edilirken hata oluştu', 'error');
    }
}

// ============================================================
// ALINMADI ALT SEÇENEKLER
// ============================================================
let _notPaidContext = 'complete'; // 'complete' veya 'edit'

function showNotPaidOptions(context) {
    _notPaidContext = context || 'complete';
    const panel = document.getElementById('notPaidOptionsPanel');
    if (!panel) { 
        if (context === 'edit') setEditPaymentMethodAndSubmit('not_paid');
        else setPaymentMethodAndSubmit('not_paid');
        return;
    }
    // Reset
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
    const priceEl = _notPaidContext === 'edit' 
        ? document.getElementById('edit_final_price') 
        : document.getElementById('final_price');
    const total = parseFloat(priceEl?.value) || 0;
    const paid  = parseFloat(document.getElementById('partialPaidAmount')?.value) || 0;
    const remaining = Math.max(0, total - paid);
    const el = document.getElementById('remainingAmountDisplay');
    if (el) el.textContent = remaining.toFixed(2) + ' ₺';
}

function confirmNotPaid() {
    const type = document.querySelector('[name="not_paid_type"]:checked')?.value;
    const panel = document.getElementById('notPaidOptionsPanel');
    if (type === 'partial') {
        const priceEl = _notPaidContext === 'edit'
            ? document.getElementById('edit_final_price')
            : document.getElementById('final_price');
        const total  = parseFloat(priceEl?.value) || 0;
        const paid   = parseFloat(document.getElementById('partialPaidAmount')?.value) || 0;
        const method = document.getElementById('partialPaymentMethod')?.value || 'cash';
        if (paid <= 0) { _notify('Alınan tutar 0’dan büyük olmalıdır', 'error'); return; }
        if (paid >= total && total > 0) { _notify('Alınan tutar toplam tutardan küçük olmalıdır', 'error'); return; }
        if (panel) panel.style.display = 'none';
        if (_notPaidContext === 'edit') setEditPaymentMethodAndSubmit(method, paid);
        else setPaymentMethodAndSubmit(method, paid);
    } else {
        if (panel) panel.style.display = 'none';
        if (_notPaidContext === 'edit') setEditPaymentMethodAndSubmit('not_paid');
        else setPaymentMethodAndSubmit('not_paid');
    }
}

// Randevu tamamlama
async function setPaymentMethodAndSubmit(paymentMethod, paidAmount = null, zeroConfirmed = false) {
    const appointmentId = document.getElementById('completeAppointmentId').value;
    const serviceListContainer = document.getElementById('serviceList');
    const serviceIds = Array.from(serviceListContainer.querySelectorAll('.service-checkbox:checked')).map(cb => cb.dataset.id);
    
    if (serviceIds.length === 0) {
        _notify('Lütfen en az bir hizmet seçin', 'warning');
        return;
    }
    
    // Varyant validasyonu
    let variantErr = false;
    serviceListContainer.querySelectorAll('.service-checkbox:checked').forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const panel = serviceListContainer.querySelector(`.complete-variant-panel[data-service-id="${cb.dataset.id}"]`);
            if (panel && panel.querySelectorAll('.complete-variant-input:checked').length === 0) {
                variantErr = true;
            }
        }
    });
    if (variantErr) {
        _notify('Lütfen varyantlı hizmetler için varyant seçimi yapın', 'warning');
        return;
    }
    
    // Varyant verilerini topla
    const completeVariantData = {};
    serviceListContainer.querySelectorAll('.service-checkbox:checked').forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const panel = serviceListContainer.querySelector(`.complete-variant-panel[data-service-id="${cb.dataset.id}"]`);
            if (panel) {
                const selected = Array.from(panel.querySelectorAll('.complete-variant-input:checked')).map(v => ({
                    id: parseInt(v.value),
                    extra_price: parseFloat(v.dataset.extraPrice || 0),
                    extra_duration: parseInt(v.dataset.extraDuration || 0)
                }));
                if (selected.length > 0) completeVariantData[cb.dataset.id] = selected;
            }
        }
    });
    
    // 0 TL onayı: kaydetmeden önce kullanıcıdan onay al (İptal => forma döner)
    if (!zeroConfirmed && parseFloat(document.getElementById('final_price').value) === 0) {
        const zeroMsg = 'Bu randevuyu 0 TL olarak kaydediyorsunuz. Onaylıyor musunuz?';
        if (document.getElementById('confirmModal')) {
            showConfirmationModal('0 TL Onayı', zeroMsg, () => setPaymentMethodAndSubmit(paymentMethod, paidAmount, true));
        } else if (confirm(zeroMsg)) {
            setPaymentMethodAndSubmit(paymentMethod, paidAmount, true);
        }
        return;
    }
    
    // Aralıklı hizmet fiyatlarını gönder
    const completeRangePrices = {};
    serviceListContainer.querySelectorAll('.service-checkbox:checked').forEach(cb => {
        if (cb.dataset.isRange === '1') {
            const rangeInput = serviceListContainer.querySelector(`.range-actual-price[data-service-id="${cb.dataset.id}"]`);
            if (rangeInput) {
                completeRangePrices[cb.dataset.id] = parseFloat(rangeInput.value) || 0;
            }
        }
    });

    try {
        const result = await apiRequest('api/daily_view/complete.php', 'POST', {
            appointment_id: appointmentId,
            payment_method: paymentMethod,
            service_ids: serviceIds,
            range_prices: completeRangePrices,
            variant_data: completeVariantData,
            // 0 geçerli tutar (ücretsiz hizmet); boş/geçersiz alan => null (sunucu mevcut fiyatı korur)
            total_price: Number.isFinite(parseFloat(document.getElementById('final_price').value)) ? parseFloat(document.getElementById('final_price').value) : null,
            paid_amount: paidAmount,
            product_consumption: collectProductConsumptionDV()
        });
        
        if (result.success) {
            closeModal('completeModal');
            _notify(result.message, 'success');
            loadDailyViewData();
        } else {
            _notify(result.message, 'error');
        }
    } catch (error) {
        console.error('Randevu tamamlanırken hata:', error);
        _notify('Randevu tamamlanırken hata oluştu', 'error');
    }
}

// Randevu düzenleme
async function setEditPaymentMethodAndSubmit(paymentMethod, paidAmount = null) {
    const appointmentId = document.getElementById('editAppointmentId').value;
    const editServiceListContainer = document.getElementById('editServiceList');
    const serviceIds = Array.from(editServiceListContainer.querySelectorAll('.edit-service-checkbox:checked')).map(cb => cb.dataset.id);
    
    if (serviceIds.length === 0) {
        _notify('Lütfen en az bir hizmet seçin', 'warning');
        return;
    }
    
    // Varyant validasyonu
    let editVarErr = false;
    editServiceListContainer.querySelectorAll('.edit-service-checkbox:checked').forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const panel = editServiceListContainer.querySelector(`.edit-variant-panel[data-service-id="${cb.dataset.id}"]`);
            if (panel && panel.querySelectorAll('.edit-variant-input:checked').length === 0) {
                editVarErr = true;
            }
        }
    });
    if (editVarErr) {
        _notify('Lütfen varyantlı hizmetler için varyant seçimi yapın', 'warning');
        return;
    }
    
    // Varyant verilerini topla
    const editVariantData = {};
    editServiceListContainer.querySelectorAll('.edit-service-checkbox:checked').forEach(cb => {
        if (cb.dataset.isVariant === '1') {
            const panel = editServiceListContainer.querySelector(`.edit-variant-panel[data-service-id="${cb.dataset.id}"]`);
            if (panel) {
                const selected = Array.from(panel.querySelectorAll('.edit-variant-input:checked')).map(v => ({
                    id: parseInt(v.value),
                    extra_price: parseFloat(v.dataset.extraPrice || 0),
                    extra_duration: parseInt(v.dataset.extraDuration || 0)
                }));
                if (selected.length > 0) editVariantData[cb.dataset.id] = selected;
            }
        }
    });
    
    const editRangePrices = {};
    editServiceListContainer.querySelectorAll('.edit-service-checkbox:checked').forEach(cb => {
        if (cb.dataset.isRange === '1') {
            const rangeInput = editServiceListContainer.querySelector(`.edit-range-actual-price[data-service-id="${cb.dataset.id}"]`);
            if (rangeInput) {
                editRangePrices[cb.dataset.id] = parseFloat(rangeInput.value) || 0;
            }
        }
    });

    try {
        const result = await apiRequest('api/daily_view/update.php', 'POST', {
            appointment_id: appointmentId,
            payment_method: paymentMethod,
            service_ids: serviceIds,
            range_prices: editRangePrices,
            variant_data: editVariantData,
            total_price: parseFloat(document.getElementById('edit_final_price').value) || 0,
            paid_amount: paidAmount,
            notes: document.getElementById('edit_notes')?.value || ''
        });
        if (result.success) {
            closeModal('editModal');
            _notify(result.message, 'success');
            loadDailyViewData();
        } else {
            _notify(result.message, 'error');
        }
    } catch (error) {
        console.error('Randevu güncellenirken hata:', error);
        _notify('Randevu güncellenirken hata oluştu', 'error');
    }
}

// Taşıma modu (mobil ve alternatif için)
let moveMode = {
    active: false,
    appointmentData: null
};

// Taşıma modunu başlat
function startMoveMode(appointmentData) {
    moveMode.active = true;
    moveMode.appointmentData = appointmentData;
    
    // Müsait slot'ları highlight et
    document.querySelectorAll('.appointment-slot.available').forEach(slot => {
        slot.classList.add('move-target');
        slot.style.animation = 'pulse-green 1s infinite';
    });
    
    // Bilgi mesajı göster
    showMoveInfoBanner();
    
    console.log('Move mode started for:', appointmentData.customer_name);
}

// Taşıma modunu bitir
function endMoveMode() {
    moveMode.active = false;
    moveMode.appointmentData = null;
    
    // Highlight'ları kaldır
    document.querySelectorAll('.appointment-slot.move-target').forEach(slot => {
        slot.classList.remove('move-target');
        slot.style.animation = '';
    });
    
    // Info banner'ı kaldır
    hideMoveInfoBanner();
    
    console.log('Move mode ended');
}

// Taşıma info banner'ı göster
function showMoveInfoBanner() {
    let banner = document.getElementById('moveInfoBanner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'moveInfoBanner';
        banner.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 12px 20px;
            text-align: center;
            z-index: 99999;
            font-weight: 500;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
        `;
        banner.innerHTML = `
            <span><i class="fas fa-arrows-alt"></i> Taşımak için yeşil alanlardan birine tıklayın</span>
            <button onclick="endMoveMode()" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 5px 15px; border-radius: 5px; cursor: pointer;">
                <i class="fas fa-times"></i> İptal
            </button>
        `;
        document.body.prepend(banner);
    }
    banner.style.display = 'flex';
}

// Taşıma info banner'ı gizle
function hideMoveInfoBanner() {
    const banner = document.getElementById('moveInfoBanner');
    if (banner) banner.style.display = 'none';
}

// Müsait slot tıklaması - taşıma modu kontrolü
function handleAvailableSlotClick(event, slot) {
    event.stopPropagation();
    event.preventDefault(); // Tüm default davranışları engelle
    
    console.log('handleAvailableSlotClick called, moveMode.active:', moveMode.active);
    
    // Taşıma modu aktifse - BU ÖNCELİKLİDİR!
    if (moveMode.active && moveMode.appointmentData) {
        const newTime = slot.dataset.time;
        const newEmployeeId = slot.dataset.employeeId;
        const savedData = {...moveMode.appointmentData};
        
        // Taşıma modunu bitir (banner'ı gizle)
        endMoveMode();
        
        // Onay modalı göster
        showConfirmationModal(
            'Randevuyu Taşı',
            `<strong>${savedData.customer_name || 'Müşteri'}</strong>'in randevusunu <strong>${newTime}</strong> saatine taşımak istediğinizden emin misiniz?`,
            () => {
                console.log('Confirmation accepted, calling dragAppointment');
                dragAppointment(savedData.id, newEmployeeId, newTime);
            }
        );
        return;
    }
    
    // Normal mod - yeni randevu ekle
    showAddModalForSlot(event, slot);
}

// Drag & Drop işlemleri - Named functions ile
let currentDragData = null;

// Named handler functions
function handleDragStart(e) {
    const item = e.target.closest('.appointment-item[draggable="true"]');
    console.log('DRAGSTART triggered, item:', item);
    if (!item) {
        console.log('DRAGSTART: No draggable item found');
        return;
    }
    
    let details;
    try {
        details = JSON.parse(item.dataset.appointmentDetails || '{}');
    } catch (err) {
        console.log('DRAGSTART: JSON parse error', err);
        return;
    }
    
    if (details.status === 'break') {
        console.log('DRAGSTART: Break item, preventing');
        e.preventDefault();
        return;
    }
    
    currentDragData = details;
    console.log('DRAGSTART: currentDragData set:', currentDragData);
    e.dataTransfer.setData('text/plain', JSON.stringify(details));
    e.dataTransfer.effectAllowed = 'move';
    
    item.classList.add('dragging');
    const slot = item.closest('.appointment-slot');
    if (slot) slot.classList.add('dragging');
}

function handleDragEnd(e) {
    document.querySelectorAll('.dragging').forEach(el => el.classList.remove('dragging'));
    document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
    // currentDragData'yı burada temizleme - drop'ta lazım olabilir
}

function handleDragOver(e) {
    if (!currentDragData) return;
    
    const slot = e.target.closest('.appointment-slot.available');
    if (!slot) return;
    
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    
    // Diğer slot'lardan drag-over'ı kaldır
    document.querySelectorAll('.appointment-slot.drag-over').forEach(el => {
        if (el !== slot) el.classList.remove('drag-over');
    });
    slot.classList.add('drag-over');
}

function handleDragLeave(e) {
    const slot = e.target.closest('.appointment-slot.available');
    if (!slot) return;
    
    const relatedSlot = e.relatedTarget?.closest('.appointment-slot.available');
    if (relatedSlot !== slot) {
        slot.classList.remove('drag-over');
    }
}

function handleDrop(e) {
    console.log('DROP triggered');
    const slot = e.target.closest('.appointment-slot.available');
    if (!slot) {
        console.log('DROP: No available slot found');
        return;
    }
    
    e.preventDefault();
    e.stopPropagation();
    slot.classList.remove('drag-over');
    
    console.log('DROP: currentDragData:', currentDragData);
    let draggedItemData = currentDragData;
    
    // Fallback: dataTransfer'dan al
    if (!draggedItemData) {
        console.log('DROP: Trying dataTransfer fallback');
        try {
            const data = e.dataTransfer.getData('text/plain');
            console.log('DROP: dataTransfer data:', data);
            if (data) draggedItemData = JSON.parse(data);
        } catch (err) {
            console.error('Drop parse error:', err);
        }
    }
    
    console.log('DROP: Final draggedItemData:', draggedItemData);
    
    if (draggedItemData && draggedItemData.id) {
        const newTime = slot.dataset.time;
        const newEmployeeId = slot.dataset.employeeId;
        
        // Drag data'yı sakla ve temizle
        const savedData = {...draggedItemData};
        currentDragData = null;
        
        console.log('DROP: Showing confirmation modal');
        showConfirmationModal(
            'Randevuyu Taşı',
            `<strong>${savedData.customer_name || 'Müşteri'}</strong>'in randevusunu <strong>${newTime}</strong> saatine taşımak istediğinizden emin misiniz?`,
            () => {
                console.log('DROP: Callback executing dragAppointment');
                dragAppointment(savedData.id, newEmployeeId, newTime);
            }
        );
    } else {
        console.log('DROP: No valid data, clearing');
        currentDragData = null;
    }
}

function initializeDragAndDrop() {
    console.log('initializeDragAndDrop called');
    
    // Draggable attribute'larını ayarla
    const items = document.querySelectorAll('.appointment-item');
    console.log('Found appointment items:', items.length);
    
    items.forEach(item => {
        const details = JSON.parse(item.dataset.appointmentDetails || '{}');
        if (details.status === 'break') {
            item.removeAttribute('draggable');
        } else {
            item.setAttribute('draggable', 'true');
            item.style.cursor = 'grab';
        }
    });
    
    // Önceki listener'ları kaldır
    document.removeEventListener('dragstart', handleDragStart, true);
    document.removeEventListener('dragend', handleDragEnd, true);
    document.removeEventListener('dragover', handleDragOver, true);
    document.removeEventListener('dragleave', handleDragLeave, true);
    document.removeEventListener('drop', handleDrop, true);
    
    // Yeni listener'ları ekle (capture phase)
    document.addEventListener('dragstart', handleDragStart, true);
    document.addEventListener('dragend', handleDragEnd, true);
    document.addEventListener('dragover', handleDragOver, true);
    document.addEventListener('dragleave', handleDragLeave, true);
    document.addEventListener('drop', handleDrop, true);
    
    console.log('Drag & Drop listeners attached');
}

async function dragAppointment(appointmentId, newEmployeeId, newTime) {
    try {
        console.log('dragAppointment called with:', { appointmentId, newEmployeeId, newTime });
        
        const result = await apiRequest('api/daily_view/drag.php', 'POST', {
            appointment_id: appointmentId,
            employee_id: newEmployeeId,
            new_time: newTime
        });
        
        console.log('dragAppointment result:', result);
        
        if (result.success) {
            _notify(result.message, 'success');
            // MoveMode'u kesinlikle kapat
            if (moveMode.active) endMoveMode();
            loadDailyViewData();
        } else {
            _notify(result.message, 'error');
            // Hata olsa da moveMode'u kapat
            if (moveMode.active) endMoveMode();
        }
    } catch (error) {
        console.error('Randevu taşınırken hata:', error);
        _notify('Randevu taşınırken hata oluştu', 'error');
        // Hata olsa da moveMode'u kapat
        if (moveMode.active) endMoveMode();
    }
}

// Gün sonu modalı
function openEndOfDayModal() {
    updateAllCalculations();
    
    document.getElementById('eod_total_cash').textContent = formatTL(dailyViewState.grandTotalCash);
    document.getElementById('eod_total_bank').textContent = formatTL(dailyViewState.grandTotalBank);
    const eodCreditCardEl = document.getElementById('eod_total_credit_card');
    if (eodCreditCardEl) eodCreditCardEl.textContent = formatTL(dailyViewState.grandTotalCreditCard);
    
    const eodNotPaidEl = document.getElementById('eod_total_not_paid');
    if (eodNotPaidEl) eodNotPaidEl.textContent = formatTL(dailyViewState.grandTotalNotPaid);
    
    openModal('endOfDayModal');
}

function updateAllCalculations() {
    const employeeListContainer = document.getElementById('employeePayoutList');
    employeeListContainer.innerHTML = '';
    
    dailyViewState.employees.forEach(employee => {
        const summary = dailyViewState.employeeSummary[employee.id];
        if (!summary) return;
        
        const commissionRate = (parseFloat(employee.commission_rate) || 0) / 100;
        const totalRevenue = summary.total_revenue;
        const totalCash = summary.cash_total;
        const totalBank = summary.bank_total;
        const totalCreditCard = summary.credit_card_total || 0;
        const totalNotPaid = summary.not_paid_total || 0;
        const employeeShare = totalRevenue * commissionRate;
        const salonShare = totalRevenue - employeeShare;
        const commissionPercent = (commissionRate * 100).toFixed(0);
        const salonPercent = (100 - commissionPercent).toFixed(0);
        
        const card = document.createElement('div');
        card.className = 'eod-employee-card';
        card.innerHTML = `
            <h5><i class="fas fa-user"></i> ${employee.name}</h5>
            <div class="stat"><strong>Toplam Nakit:</strong><span>${formatTL(totalCash)}</span></div>
            <div class="stat"><strong>Toplam Havale/EFT:</strong><span>${formatTL(totalBank)}</span></div>
            <div class="stat"><strong>Toplam Kredi Kartı:</strong><span>${formatTL(totalCreditCard)}</span></div>
            <div class="stat" style="color:#ef4444"><strong>Toplam Alınmadı:</strong><span>${formatTL(totalNotPaid)}</span></div>
            <div class="stat stat-total"><strong>TOPLAM KAZANÇ:</strong><span>${formatTL(totalRevenue)}</span></div>
            <hr>
            <div class="stat"><strong>Salon Payı (${salonPercent}%):</strong><span>${formatTL(salonShare)}</span></div>
            <div class="stat stat-share"><strong>ÇALIŞAN PAYI (${commissionPercent}%):</strong><span>${formatTL(employeeShare)}</span></div>
            <hr>
            <h6><i class="fas fa-wallet"></i> Ödeme Yap</h6>
            <div class="eod-payout-inputs">
                <div class="form-group"><label>Ödenecek Nakit:</label><input type="number" step="0.01" class="form-control payout-cash" data-employee-id="${employee.id}" data-employee-name="${employee.name}" data-max-share="${employeeShare.toFixed(2)}" value="0" oninput="validatePayout(this, ${employee.id})"></div>
                <div class="form-group"><label>Ödenecek Havale/EFT:</label><input type="number" step="0.01" class="form-control payout-iban" data-employee-id="${employee.id}" data-employee-name="${employee.name}" data-max-share="${employeeShare.toFixed(2)}" value="0" oninput="validatePayout(this, ${employee.id})"></div>
                <div class="form-group eod-advance-group" id="advance-group-${employee.id}" style="display:none;">
                    <label><i class="fas fa-hand-holding-usd"></i> Avans:</label>
                    <input type="number" step="0.01" class="form-control payout-advance" data-employee-id="${employee.id}" value="0" oninput="updateFinalSummary()">
                </div>
                <div class="eod-payout-remaining" id="remaining-payout-${employee.id}">Kalan Ödeme: ${formatTL(employeeShare)}</div>
            </div>
        `;
        employeeListContainer.appendChild(card);
    });
    
    updateFinalSummary();
}

function validatePayout(inputElement, employeeId) {
    const maxShare = parseFloat(inputElement.dataset.maxShare);
    const cashInput = document.querySelector(`.payout-cash[data-employee-id="${employeeId}"]`);
    const ibanInput = document.querySelector(`.payout-iban[data-employee-id="${employeeId}"]`);
    
    let cashValue = parseFloat(cashInput.value) || 0;
    let ibanValue = parseFloat(ibanInput.value) || 0;
    let totalPaid = cashValue + ibanValue;
    
    // Negatif değer önle
    if (cashValue < 0) { cashValue = 0; cashInput.value = 0; }
    if (ibanValue < 0) { ibanValue = 0; ibanInput.value = 0; }
    totalPaid = cashValue + ibanValue;
    
    const remaining = maxShare - totalPaid;
    const remainingEl = document.getElementById(`remaining-payout-${employeeId}`);
    const advanceGroup = document.getElementById(`advance-group-${employeeId}`);
    
    if (remaining > 0) {
        remainingEl.textContent = `Kalan Ödeme: ${formatTL(remaining)}`;
        remainingEl.style.color = '#dc2626';
        if (advanceGroup) advanceGroup.style.display = 'none';
    } else if (remaining < 0) {
        // Fazla ödeme = avans
        remainingEl.textContent = `Fazla Ödeme (Avans): ${formatTL(Math.abs(remaining))}`;
        remainingEl.style.color = '#f59e0b';
        if (advanceGroup) {
            advanceGroup.style.display = 'block';
            const advanceInput = advanceGroup.querySelector('.payout-advance');
            if (advanceInput) advanceInput.value = Math.abs(remaining).toFixed(2);
        }
    } else {
        remainingEl.textContent = 'Ödeme Tamamlandı';
        remainingEl.style.color = '#16a34a';
        if (advanceGroup) advanceGroup.style.display = 'none';
    }
    
    updateFinalSummary();
}

function updateFinalSummary() {
    let totalPaidCash = 0;
    document.querySelectorAll('.payout-cash').forEach(input => {
        totalPaidCash += parseFloat(input.value) || 0;
    });
    
    let totalPaidBank = 0;
    document.querySelectorAll('.payout-iban').forEach(input => {
        totalPaidBank += parseFloat(input.value) || 0;
    });
    
    const remainingCash = dailyViewState.grandTotalCash - totalPaidCash;
    const remainingBank = dailyViewState.grandTotalBank - totalPaidBank;
    
    document.getElementById('eod_paid_cash').textContent = formatTL(totalPaidCash);
    document.getElementById('eod_paid_iban').textContent = formatTL(totalPaidBank);
    document.getElementById('eod_remaining_cash').textContent = formatTL(remainingCash);
    document.getElementById('eod_remaining_iban').textContent = formatTL(remainingBank);
}

async function saveDayEndPayments() {
    const payments = [];
    
    dailyViewState.employees.forEach(employee => {
        const cashInput = document.querySelector(`.payout-cash[data-employee-id="${employee.id}"]`);
        const ibanInput = document.querySelector(`.payout-iban[data-employee-id="${employee.id}"]`);
        const advanceInput = document.querySelector(`.payout-advance[data-employee-id="${employee.id}"]`);
        
        const cashAmount = parseFloat(cashInput?.value) || 0;
        const ibanAmount = parseFloat(ibanInput?.value) || 0;
        const advanceAmount = parseFloat(advanceInput?.value) || 0;
        const expectedAmount = parseFloat(cashInput?.dataset.maxShare) || 0;
        const employeeName = cashInput?.dataset.employeeName || employee.name;
        
        if (cashAmount > 0 || ibanAmount > 0 || advanceAmount > 0) {
            payments.push({
                employee_id: employee.id,
                employee_name: employeeName,
                cash_amount: cashAmount,
                iban_amount: ibanAmount,
                advance_amount: advanceAmount,
                expected_amount: expectedAmount
            });
        }
    });
    
    if (payments.length === 0) {
        _notify('Kaydedilecek ödeme bulunamadı. En az bir çalışana ödeme girin.', 'warning');
        return;
    }
    
    const saveBtn = document.getElementById('saveDayEndBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Kaydediliyor...';
    
    try {
        const result = await apiRequest('api/employee_salaries/day_end_save.php', 'POST', {
            payment_date: dailyViewState.selectedDate,
            payments: payments
        });
        
        if (result.success) {
            _notify(result.message, 'success');
            closeModal('endOfDayModal');
        } else {
            _notify(result.message || 'Ödeme kaydedilemedi', 'error');
        }
    } catch (error) {
        console.error('Day end save error:', error);
        _notify('Ödemeler kaydedilirken bir hata oluştu', 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save"></i> Ödemeleri Kaydet';
    }
}

// Çalışma saatleri dropdown kontrolü
function updateEndHourOptions() {
    const startSelect = document.getElementById('start_hour_select');
    const endSelect = document.getElementById('end_hour_select');
    
    if (!startSelect || !endSelect) return;
    
    const startHour = parseInt(startSelect.value);
    const currentEndValue = parseInt(endSelect.value);
    
    endSelect.innerHTML = '';
    
    for (let h = startHour + 1; h <= 24; h++) {
        const option = document.createElement('option');
        option.value = h;
        option.textContent = h === 24 ? '24:00' : String(h).padStart(2, '0') + ':00';
        
        if (h === currentEndValue && currentEndValue > startHour) {
            option.selected = true;
        }
        endSelect.appendChild(option);
    }
    
    if (currentEndValue <= startHour) {
        endSelect.value = startHour + 1;
    }
}

// HTML escape helper
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Global alert gösterme
// showGlobalAlert fonksiyonu kaldırıldı - artık global notification.js kullanılıyor

// Mevcut müşteri onay modalı
function showCustomerConfirmationModal(customerName, customerPhone) {
    document.getElementById('existing-customer-name').textContent = customerName;
    document.getElementById('existing-customer-phone').textContent = customerPhone;
    
    pendingFormData = {
        customerName: customerName,
        customerPhone: customerPhone
    };
    
    openModal('customerConfirmationModal');
}

function confirmExistingCustomer() {
    closeModal('customerConfirmationModal');
    
    if (pendingFormData) {
        if (currentActiveModal === 'appointmentModal') {
            document.getElementById('customer_name').value = pendingFormData.customerName;
        } else if (currentActiveModal === 'addBetweenModal') {
            document.getElementById('between_customer_name').value = pendingFormData.customerName;
        }
        pendingFormData = null;
        currentActiveModal = null;
    }
}

function cancelExistingCustomer() {
    closeModal('customerConfirmationModal');
    pendingFormData = null;
    currentActiveModal = null;
    
    const nameInputs = document.querySelectorAll('#customer_name, #between_customer_name');
    const phoneInputs = document.querySelectorAll('#customer_phone, #between_customer_phone');
    
    nameInputs.forEach(input => { if (input) input.value = ''; });
    phoneInputs.forEach(input => { if (input) input.value = ''; });
}

// DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    updateEndHourOptions();
    updateGridColumns();
    
    // Resize listener
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(updateGridColumns, 100);
    });
    
    // Form submit handlers
    const appointmentForm = document.getElementById('appointmentForm');
    if (appointmentForm) {
        appointmentForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitAppointmentForm();
        });
    }
    
    const addBetweenForm = document.getElementById('addBetweenForm');
    if (addBetweenForm) {
        addBetweenForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitBetweenAppointmentForm();
        });
    }

    const waitlistForm = document.getElementById('waitlistForm');
    if (waitlistForm) {
        waitlistForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitWaitlistForm();
        });
    }

    applySendCustomerSmsPreference();
    bindSendCustomerSmsPreference();

    _bindCustomerAutocomplete({
        stateKey: 'main',
        phoneInputId: 'customer_phone',
        nameInputId: 'customer_name',
        phoneListId: 'customer_phone_suggestions',
        nameListId: 'customer_name_suggestions',
        alertId: 'customerInfoAlert',
        phoneDebounceRef: {
            get: () => _customerPhoneAutoDebounce,
            set: (val) => { _customerPhoneAutoDebounce = val; }
        },
        nameDebounceRef: {
            get: () => _customerNameDebounce,
            set: (val) => { _customerNameDebounce = val; }
        }
    });

    _bindCustomerAutocomplete({
        stateKey: 'between',
        phoneInputId: 'between_customer_phone',
        nameInputId: 'between_customer_name',
        phoneListId: 'between_customer_phone_suggestions',
        nameListId: 'between_customer_name_suggestions',
        alertId: 'betweenCustomerInfoAlert',
        phoneDebounceRef: {
            get: () => _betweenCustomerPhoneAutoDebounce,
            set: (val) => { _betweenCustomerPhoneAutoDebounce = val; }
        },
        nameDebounceRef: {
            get: () => _betweenCustomerNameDebounce,
            set: (val) => { _betweenCustomerNameDebounce = val; }
        }
    });
    
    // Telefon inputlarına debounced müşteri arama
    const customerPhoneInput = document.getElementById('customer_phone');
    if (customerPhoneInput) {
        customerPhoneInput.addEventListener('input', function() {
            clearTimeout(_customerPhoneDebounce);
            _customerPhoneDebounce = setTimeout(() => fillCustomerInfo(), 500);
        });
    }
    const betweenPhoneInput = document.getElementById('between_customer_phone');
    if (betweenPhoneInput) {
        betweenPhoneInput.addEventListener('input', function() {
            clearTimeout(_betweenCustomerPhoneDebounce);
            _betweenCustomerPhoneDebounce = setTimeout(() => fillBetweenCustomerInfo(), 500);
        });
    }
    
    // Bekleme listesi telefon inputu
    const waitlistPhoneInput = document.getElementById('waitlist_customer_phone');
    if (waitlistPhoneInput) {
        waitlistPhoneInput.addEventListener('input', function() {
            clearTimeout(_waitlistPhoneDebounce);
            _waitlistPhoneDebounce = setTimeout(() => fillWaitlistCustomerInfo(), 500);
        });
        waitlistPhoneInput.addEventListener('blur', function() {
            fillWaitlistCustomerInfo();
        });
    }
    
    // Close butonları
    document.querySelectorAll('.modal .close').forEach(el => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const modal = el.closest('.modal');
            if (modal) closeModal(modal.id);
        });
    });
    
    // Confirm modal butonları - Event delegation ile (DOM değişse de çalışır)
    document.addEventListener('click', function(e) {
        // Cancel butonu
        if (e.target.closest('#confirmCancelBtn')) {
            e.preventDefault();
            e.stopPropagation();
            closeModal('confirmModal');
        }
        
        // OK butonu
        if (e.target.closest('#confirmOkBtn')) {
            e.preventDefault();
            e.stopPropagation();
            console.log('confirmOkBtn clicked, onConfirmCallback:', typeof onConfirmCallback);
            
            // Modal'ı kapat
            const modal = document.getElementById('confirmModal');
            if (modal) {
                modal.style.display = 'none';
                modal.style.visibility = 'hidden';
                modal.style.opacity = '0';
                modal.style.pointerEvents = 'none';
            }
            
            if (typeof onConfirmCallback === 'function') {
                const callback = onConfirmCallback;
                onConfirmCallback = () => {}; // Reset to prevent double execution
                callback();
            }
        }
    });
    
    // Modal overlay click
    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            closeModal(event.target.id);
        }
        
        if (!event.target.closest('.appointment-menu') && !event.target.closest('.appointment-item') && !event.target.closest('#menuOverlay')) {
            hideMenu();
        }
    });
    
    // ESC tuşu
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            // Taşıma modunu iptal et
            if (moveMode.active) {
                endMoveMode();
                _notify('Taşıma işlemi iptal edildi', 'info');
            }
            hideMenu();
            const openModals = document.querySelectorAll('.modal[style*="display: flex"]');
            openModals.forEach(modal => closeModal(modal.id));
        }
    });
    
    // Drag & Drop başlat
    initializeDragAndDrop();
    
    // Tarih değişikliği
    const dateSelect = document.getElementById('dateSelect');
    if (dateSelect) {
        dateSelect.addEventListener('change', function() {
            navigateDate(this.value);
        });
    }

    // İlk açılışta güncel bekleme listesi ve takvimi API'den çek
    loadDailyViewData(dailyViewState.selectedDate);
});

// Global scope'a fonksiyonları ekle
window.showAddModal = showAddModal;
window.showAddModalForSlot = showAddModalForSlot;
window.showAddBetweenModal = showAddBetweenModal;
window.editFullAppointment = editFullAppointment;
window.openModal = openModal;
window.closeModal = closeModal;
window.openEndOfDayModal = openEndOfDayModal;
window.fillCustomerInfo = fillCustomerInfo;
window.fillBetweenCustomerInfo = fillBetweenCustomerInfo;
window.updateTotalPrice = updateTotalPrice;
window.updateBetweenTotalPrice = updateBetweenTotalPrice;
window.setPaymentMethodAndSubmit = setPaymentMethodAndSubmit;
window.setEditPaymentMethodAndSubmit = setEditPaymentMethodAndSubmit;
window.confirmExistingCustomer = confirmExistingCustomer;
window.cancelExistingCustomer = cancelExistingCustomer;
window.validatePayout = validatePayout;
window.handleSlotClick = handleSlotClick;
window.handleAppointmentItemClick = handleAppointmentItemClick;
window.hideMenu = hideMenu;
window.navigateDate = navigateDate;
window.loadDailyViewData = loadDailyViewData;
window.loadAppointments = loadDailyViewData; // footer.php polling hook
window.submitWorkingHours = submitWorkingHours;
window.addNewTimeSlot = addNewTimeSlot;
window.addNewTimeSlotForAll = addNewTimeSlotForAll;
window.updateEndHourOptions = updateEndHourOptions;
window.reinitializeDragAndDrop = initializeDragAndDrop;
window.openWaitlistAsAppointment = openWaitlistAsAppointment;
window.markWaitlistCalled = markWaitlistCalled;
window.cancelWaitlistEntry = cancelWaitlistEntry;
window.toggleWaitlistInlineForm = toggleWaitlistInlineForm;
// showNotification global notification.js'den geliyor (ezmiyoruz)
window.startMoveMode = startMoveMode;
window.endMoveMode = endMoveMode;

// ============================================
// ÜRÜN TÜKETİM ONAY (Product Consumption) - Daily View
// ============================================
let _pcAllProductsDV = [];

function loadProductConsumptionDV(appointmentId) {
    const section = document.getElementById('productConsumptionSection');
    const list = document.getElementById('productConsumptionList');
    const countBadge = document.getElementById('productConsumptionCount');
    
    if (!section || !list) return;
    
    list.innerHTML = '<div style="text-align:center;padding:8px;color:#6b7280;"><i class="fas fa-spinner fa-spin"></i></div>';
    section.style.display = 'none';
    
    // Seçili hizmet ID'lerini al
    const serviceListContainer = document.getElementById('serviceList');
    const checkedIds = serviceListContainer 
        ? Array.from(serviceListContainer.querySelectorAll('.service-checkbox:checked')).map(cb => cb.dataset.id)
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
        
        _pcAllProductsDV = data.all_products || [];
        const defaults = data.defaults || [];
        
        if (defaults.length === 0 && _pcAllProductsDV.length === 0) {
            section.style.display = 'none';
            return;
        }
        
        section.style.display = 'block';
        list.innerHTML = '';
        
        defaults.forEach(item => {
            addProductRowDV(list, item.product_id, item.name, parseFloat(item.total_quantity), parseInt(item.stock_quantity));
        });
        
        countBadge.textContent = defaults.length > 0 ? defaults.length : '';
        
        if (defaults.length > 0) {
            document.getElementById('productConsumptionBody').style.display = 'block';
            const arrow = document.getElementById('productConsumptionArrow');
            if (arrow) arrow.style.transform = 'rotate(180deg)';
        } else {
            document.getElementById('productConsumptionBody').style.display = 'none';
            const arrow = document.getElementById('productConsumptionArrow');
            if (arrow) arrow.style.transform = 'rotate(0deg)';
        }
    })
    .catch(err => {
        console.error('Product consumption load error:', err);
        section.style.display = 'none';
    });
}

function addProductRowDV(container, productId, productName, quantity, stockQty) {
    const row = document.createElement('div');
    row.className = 'pc-row';
    row.dataset.productId = productId;
    
    const stockColor = (stockQty !== undefined && stockQty < quantity) ? '#ef4444' : '';
    const stockInfo = stockQty !== undefined ? ` <small class="pc-stock-info" style="color:${stockColor}">(Stok: ${stockQty})</small>` : '';
    
    const safeName = document.createElement('span');
    safeName.textContent = productName;
    
    row.innerHTML = `
        <div class="pc-name">
            ${safeName.innerHTML}${stockInfo}
        </div>
        <div class="pc-controls">
            <button type="button" class="pc-btn" onclick="pcChangeQtyDV(this, -1)">−</button>
            <input type="number" class="pc-qty" value="${quantity}" min="0.5" step="0.5" onchange="pcUpdateCountDV()">
            <button type="button" class="pc-btn" onclick="pcChangeQtyDV(this, 1)">+</button>
        </div>
        <button type="button" class="pc-remove" onclick="pcRemoveRowDV(this)">
            <i class="fas fa-times"></i>
        </button>
    `;
    container.appendChild(row);
    pcUpdateCountDV();
}

function pcChangeQtyDV(btn, delta) {
    const input = btn.parentElement.querySelector('.pc-qty');
    let val = parseFloat(input.value) || 1;
    val = Math.max(0.5, val + delta);
    input.value = val;
}

function pcRemoveRowDV(btn) {
    btn.closest('.pc-row').remove();
    pcUpdateCountDV();
}

function pcUpdateCountDV() {
    const list = document.getElementById('productConsumptionList');
    const countBadge = document.getElementById('productConsumptionCount');
    if (!list || !countBadge) return;
    countBadge.textContent = list.querySelectorAll('.pc-row').length || '';
}

function toggleProductConsumption() {
    const body = document.getElementById('productConsumptionBody');
    const arrow = document.getElementById('productConsumptionArrow');
    if (!body) return;
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : 'block';
    if (arrow) arrow.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}

function addProductToConsumption() {
    if (_pcAllProductsDV.length === 0) {
        _notify('Aktif ürün bulunamadı', 'warning');
        return;
    }
    
    const list = document.getElementById('productConsumptionList');
    const existingIds = Array.from(list.querySelectorAll('.pc-row')).map(r => r.dataset.productId);
    const available = _pcAllProductsDV.filter(p => !existingIds.includes(p.id.toString()));
    
    if (available.length === 0) {
        _notify('Tüm ürünler zaten ekli', 'warning');
        return;
    }
    
    _showProductPickerModalDV(available, function(productId, quantity) {
        const prod = available.find(p => p.id.toString() === productId.toString());
        if (prod) {
            addProductRowDV(list, prod.id, prod.name, quantity, parseInt(prod.stock_quantity));
        }
    });
}

function _showProductPickerModalDV(products, onSelect) {
    const existing = document.getElementById('ppModal');
    if (existing) existing.remove();

    const isDark = document.body.classList.contains('dark-mode');

    const overlay = document.createElement('div');
    overlay.id = 'ppModal';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);';

    const modal = document.createElement('div');
    modal.style.cssText = `background:${isDark ? '#1e293b' : '#fff'};border-radius:16px;width:90%;max-width:420px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3);border:2px solid ${isDark ? '#334155' : '#e2e8f0'};`;

    const header = document.createElement('div');
    header.style.cssText = `padding:16px 20px;border-bottom:1px solid ${isDark ? '#334155' : '#e2e8f0'};`;
    header.innerHTML = `<div style="font-weight:700;font-size:1.1rem;color:${isDark ? '#f1f5f9' : '#1f2937'};margin-bottom:10px;">Ürün Seç</div>`;

    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Ürün ara...';
    searchInput.style.cssText = `width:100%;padding:8px 12px;border:2px solid ${isDark ? '#475569' : '#e2e8f0'};border-radius:8px;font-size:0.9rem;outline:none;background:${isDark ? '#0f172a' : '#fff'};color:${isDark ? '#f1f5f9' : '#1f2937'};box-sizing:border-box;`;
    header.appendChild(searchInput);
    modal.appendChild(header);

    const listContainer = document.createElement('div');
    listContainer.style.cssText = 'flex:1;overflow-y:auto;padding:8px 0;';

    function escHtml(text) { const d = document.createElement('div'); d.textContent = text; return d.innerHTML; }

    products.forEach(p => {
        const stockQty = parseInt(p.stock_quantity) || 0;
        const stockColor = stockQty <= 0 ? '#ef4444' : stockQty <= 5 ? '#f59e0b' : '#10b981';
        const item = document.createElement('label');
        item.className = 'pp-item';
        item.dataset.name = p.name.toLowerCase();
        item.style.cssText = 'display:flex;align-items:center;gap:10px;padding:10px 20px;cursor:pointer;transition:background 0.15s;';
        item.innerHTML = `
            <input type="radio" name="ppProduct" value="${p.id}" style="width:18px;height:18px;accent-color:#3b82f6;flex-shrink:0;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:0.9rem;color:${isDark ? '#f1f5f9' : '#1f2937'};">${escHtml(p.name)}</div>
                <div style="font-size:0.75rem;color:${stockColor};">Stok: ${stockQty}</div>
            </div>`;
        item.addEventListener('mouseover', () => item.style.background = isDark ? '#334155' : '#f8fafc');
        item.addEventListener('mouseout', () => item.style.background = 'transparent');
        listContainer.appendChild(item);
    });
    modal.appendChild(listContainer);

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

    searchInput.addEventListener('input', () => {
        const q = searchInput.value.toLowerCase();
        listContainer.querySelectorAll('.pp-item').forEach(el => {
            el.style.display = el.dataset.name.includes(q) ? 'flex' : 'none';
        });
    });

    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
    const escHandler = e => { if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', escHandler); } };
    document.addEventListener('keydown', escHandler);

    overlay.appendChild(modal);
    document.body.appendChild(overlay);
    setTimeout(() => searchInput.focus(), 100);
}

function collectProductConsumptionDV() {
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

window.toggleProductConsumption = toggleProductConsumption;
window.addProductToConsumption = addProductToConsumption;
window.pcChangeQtyDV = pcChangeQtyDV;
window.pcRemoveRowDV = pcRemoveRowDV;
window.pcUpdateCountDV = pcUpdateCountDV;

// ══════════════════════════════════════════════════════
// ADMİN SEPET MODU — daily_view için (appointments.js'deki mantık ile aynı)
// ══════════════════════════════════════════════════════

var adminCart = Array.isArray(window.adminCart) ? window.adminCart : [];
var adminCartMode = typeof window.adminCartMode === 'boolean' ? window.adminCartMode : false;

function toggleAdminCartMode() {
    adminCartMode = !adminCartMode;
    const btn   = document.getElementById('cartModeToggleBtn');
    const lbl   = document.getElementById('cartModeToggleLbl');
    const panel = document.getElementById('adminCartPanel');
    const normalSave = document.getElementById('normalSaveBtn');
    const addToCartBtn = document.getElementById('addToCartBtn');

    if (adminCartMode) {
        if (btn) btn.classList.replace('btn-outline-secondary', 'btn-warning');
        if (lbl) lbl.textContent = 'Sepet Modu Aktif';
        if (panel) panel.style.display = 'block';
        if (normalSave) normalSave.style.display = 'none';
        if (addToCartBtn) addToCartBtn.style.display = '';
    } else {
        if (btn) btn.classList.replace('btn-warning', 'btn-outline-secondary');
        if (lbl) lbl.textContent = 'Sepet Modu';
        if (panel) panel.style.display = 'none';
        if (normalSave) normalSave.style.display = '';
        if (addToCartBtn) addToCartBtn.style.display = 'none';
        adminCart = [];
        renderAdminCart();
    }
}

function addToAdminCart() {
    const form = document.getElementById('appointmentForm');
    const fd   = new FormData(form);

    const empId   = parseInt(fd.get('employee_id'));
    const date    = fd.get('appointment_date');
    const time    = fd.get('appointment_time');
    const status  = fd.get('status') || 'confirmed';
    const notes   = fd.get('notes') || '';
    const svcIds  = fd.getAll('service_ids[]').map(Number).filter(Boolean);

    const empSelect = document.getElementById('employee_id');
    const empName   = empSelect?.options[empSelect?.selectedIndex]?.text || '';

    const svcNames = [];
    form.querySelectorAll('input[name="service_ids[]"]:checked').forEach(cb => {
        const label = cb.closest('label');
        if (label) svcNames.push(label.textContent.trim().split(' - ')[0]);
    });

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

    let totalPrice = 0, totalDuration = 0;
    form.querySelectorAll('input[name="service_ids[]"]:checked').forEach(cb => {
        const price = parseFloat(cb.dataset.price || 0);
        const sv    = variantData[cb.value] || [];
        const ep    = sv.reduce((s, v) => s + (v.extra_price || 0), 0);
        totalPrice += price + ep;
    });

    if (!empId)           { _notify('Çalışan seçiniz', 'error');           return; }
    if (!svcIds.length)   { _notify('En az bir hizmet seçiniz', 'error');  return; }
    if (!date)            { _notify('Tarih seçiniz', 'error');              return; }
    if (!time)            { _notify('Saat seçiniz', 'error');               return; }

    adminCart.push({ employee_id: empId, employee_name: empName, service_ids: svcIds, service_names: svcNames, variant_data: variantData, appointment_date: date, appointment_time: time, status, notes, total_price: totalPrice, total_duration: totalDuration });

    renderAdminCart();

    // Saat ve hizmetleri temizle, müşteri bilgisi korunur
    const timeInput = document.getElementById('appointment_time');
    if (timeInput) timeInput.value = '';
    form.querySelectorAll('input[name="service_ids[]"]').forEach(cb => {
        cb.checked = false;
        const wrapper = cb.closest('.service-item-wrapper');
        if (wrapper) {
            const rangeDiv = wrapper.querySelector('.range-price-input');
            if (rangeDiv) rangeDiv.style.display = 'none';
            const vPanel  = wrapper.querySelector('.variant-panel');
            if (vPanel)   vPanel.style.display = 'none';
        }
    });
    if (typeof updateTotalPrice === 'function') updateTotalPrice();
    _notify(`Randevu sepete eklendi (toplam: ${adminCart.length})`, 'success');
}

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
                <div class="admin-cart-item-emp">${_escCart(item.employee_name)}</div>
                <div class="admin-cart-item-svc">${_escCart(item.service_names.join(', '))}</div>
                <div class="admin-cart-item-meta">
                    <i class="fas fa-calendar-alt"></i> ${_fmtDateCart(item.appointment_date)} &nbsp;
                    <i class="fas fa-clock"></i> ${item.appointment_time}
                    ${item.total_price > 0 ? `&nbsp; <i class="fas fa-tag"></i> ${item.total_price.toFixed(2)} ₺` : ''}
                </div>
                ${item.notes ? `<div class="admin-cart-item-notes">${_escCart(item.notes)}</div>` : ''}
            </div>
            <button type="button" class="admin-cart-item-remove" onclick="removeFromAdminCart(${idx})" title="Kaldır"><i class="fas fa-times"></i></button>
        </div>
    `).join('');
}

function _escCart(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function _fmtDateCart(d) {
    if (!d) return '';
    const [y,m,dd] = d.split('-');
    return `${dd}.${m}.${y}`;
}

function removeFromAdminCart(idx) {
    adminCart.splice(idx, 1);
    renderAdminCart();
}

function clearAdminCart() {
    adminCart = [];
    renderAdminCart();
}

async function submitAdminCart() {
    if (adminCart.length === 0) { _notify('Sepet boş', 'error'); return; }

    const custName  = document.getElementById('customer_name')?.value?.trim();
    const custPhone = document.getElementById('customer_phone')?.value?.trim();

    if (!custName)  { _notify('Müşteri adını giriniz', 'error');      return; }
    if (!custPhone) { _notify('Müşteri telefonunu giriniz', 'error'); return; }

    const spinner = document.getElementById('cartLoadingSpinner');
    const btn     = document.getElementById('submitCartBtn');
    if (spinner) spinner.style.display = '';
    if (btn)     btn.disabled = true;

    const form = document.getElementById('appointmentForm');
    const sendSmsVal = !!form?.querySelector('[name="send_customer_sms"]')?.checked;
    if (sendSmsVal && typeof window.warnSmsIfUnavailable === 'function') {
        window.warnSmsIfUnavailable(true);
    }

    const payload = {
        customer_name:     custName,
        customer_phone:    custPhone,
        send_customer_sms: sendSmsVal ? 1 : 0,
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
        const res = await apiRequest('api/appointments/batch_create.php', 'POST', payload);
        if (res.success) {
            _notify(res.message, 'success');
            notifyAppointmentSmsOutcome(res);
            closeModal('appointmentModal');
            // Günlük listeyi yenile
            if (typeof loadDailyView === 'function') {
                loadDailyView(dailyViewState.selectedDate);
            } else {
                setTimeout(() => location.reload(), 600);
            }
        } else {
            _notify(res.message || 'Hata oluştu', 'error');
        }
    } catch (e) {
        _notify('Ağ hatası. Lütfen tekrar deneyin.', 'error');
    } finally {
        if (spinner) spinner.style.display = 'none';
        if (btn)     btn.disabled = false;
    }
}

window.toggleAdminCartMode = toggleAdminCartMode;
window.addToAdminCart      = addToAdminCart;
window.removeFromAdminCart = removeFromAdminCart;
window.clearAdminCart      = clearAdminCart;
window.submitAdminCart     = submitAdminCart;

// iOS: modal açıkken hizmet listesinde yatay lastik kaydırmayı kes
(function() {
    var startX = 0, startY = 0;
    document.addEventListener('touchstart', function(e) {
        if (!document.body.classList.contains('modal-scroll-lock')) return;
        if (!e.touches || !e.touches[0]) return;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
    }, { passive: true });
    document.addEventListener('touchmove', function(e) {
        if (!document.body.classList.contains('modal-scroll-lock')) return;
        if (!e.touches || !e.touches[0]) return;
        var dx = e.touches[0].clientX - startX;
        var dy = e.touches[0].clientY - startY;
        if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 8) {
            e.preventDefault();
        }
    }, { passive: false });
})();
