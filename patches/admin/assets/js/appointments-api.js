/**
 * Appointments API Client
 * 
 * Randevu API'leri için JavaScript istemcisi.
 * Tüm AJAX çağrıları bu modül üzerinden yapılır.
 * 
 * Kullanım:
 * const api = new AppointmentsAPI();
 * api.list({ date_from: '2026-01-01' }).then(data => console.log(data));
 * 
 * @package Public/JS
 * @version 1.0.0
 */

class AppointmentsAPI {
    /**
     * API base URL
     */
    constructor(baseUrl = 'api/appointments') {
        this.baseUrl = baseUrl;
        this.csrfToken = this.getCsrfToken();
    }

    /**
     * CSRF token'ı al
     * @returns {string}
     */
    getCsrfToken() {
        // Meta tag'den
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.content;
        
        // Global değişkenden
        if (typeof CSRF_TOKEN !== 'undefined') return CSRF_TOKEN;
        
        // Hidden input'tan
        const input = document.querySelector('input[name="csrf_token"]');
        if (input) return input.value;
        
        return '';
    }

    /**
     * HTTP isteği yap
     * @param {string} endpoint 
     * @param {object} options 
     * @returns {Promise}
     */
    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}/${endpoint}`;
        
        const defaultOptions = {
            headers: {
                'X-CSRF-Token': this.csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        };

        // JSON body için Content-Type ekle
        if (options.body && !(options.body instanceof FormData)) {
            defaultOptions.headers['Content-Type'] = 'application/json';
            if (typeof options.body === 'object') {
                options.body = JSON.stringify(options.body);
            }
        }

        const finalOptions = {
            ...defaultOptions,
            ...options,
            headers: {
                ...defaultOptions.headers,
                ...(options.headers || {})
            }
        };

        try {
            const response = await fetch(url, finalOptions);
            const data = await response.json();
            
            // Unauthorized - session expired
            if (response.status === 401) {
                this.handleUnauthorized();
                throw new Error(data.message || 'Oturum süresi dolmuş');
            }
            
            return data;
        } catch (error) {
            console.error('API Request Error:', error);
            throw error;
        }
    }

    /**
     * Oturum süresi dolduğunda
     */
    handleUnauthorized() {
        // Bildirim göster
        if (typeof showNotification === 'function') {
            showNotification('Oturum süreniz doldu. Yeniden giriş yapın.', 'warning');
        }
        
        // 2 saniye sonra login sayfasına yönlendir
        setTimeout(() => {
            window.location.href = 'login.php?timeout=1';
        }, 2000);
    }

    // ============================================
    // RANDEVU İŞLEMLERİ
    // ============================================

    /**
     * Randevu listesini getir
     * @param {object} filters - Filtre parametreleri
     * @returns {Promise}
     */
    async list(filters = {}) {
        const params = new URLSearchParams();
        
        if (filters.date_from) params.append('date_from', filters.date_from);
        if (filters.date_to) params.append('date_to', filters.date_to);
        if (filters.employee_id) params.append('employee_id', filters.employee_id);
        if (filters.status) params.append('status', filters.status);
        if (filters.customer_id) params.append('customer_id', filters.customer_id);
        if (filters.page) params.append('page', filters.page);
        if (filters.per_page) params.append('per_page', filters.per_page);
        
        const queryString = params.toString();
        const endpoint = queryString ? `list.php?${queryString}` : 'list.php';
        
        return this.request(endpoint, { method: 'GET' });
    }

    /**
     * Tek randevu getir
     * @param {number} id - Randevu ID
     * @returns {Promise}
     */
    async get(id) {
        return this.request(`get.php?id=${id}`, { method: 'GET' });
    }

    /**
     * Yeni randevu oluştur
     * @param {object} data - Randevu verileri
     * @returns {Promise}
     */
    async create(data) {
        return this.request('create.php', {
            method: 'POST',
            body: data
        });
    }

    /**
     * Randevu güncelle
     * @param {number} id - Randevu ID
     * @param {object} data - Güncellenecek veriler
     * @returns {Promise}
     */
    async update(id, data) {
        return this.request('update.php', {
            method: 'POST',
            body: { appointment_id: id, ...data }
        });
    }

    /**
     * Randevu iptal et
     * @param {number} id - Randevu ID
     * @param {string} reason - İptal sebebi (opsiyonel)
     * @returns {Promise}
     */
    async cancel(id, reason = '') {
        return this.request('delete.php', {
            method: 'POST',
            body: { appointment_id: id, cancellation_reason: reason, hard_delete: false }
        });
    }

    /**
     * Randevu kalıcı sil
     * @param {number} id - Randevu ID
     * @returns {Promise}
     */
    async delete(id) {
        return this.request('delete.php', {
            method: 'POST',
            body: { appointment_id: id, hard_delete: true }
        });
    }

    /**
     * Randevuyu tamamla
     * @param {number} id - Randevu ID
     * @param {number} finalPrice - Final fiyat
     * @param {string} paymentMethod - Ödeme yöntemi (cash, bank_transfer)
     * @returns {Promise}
     */
    async complete(id, finalPrice, paymentMethod = 'cash') {
        return this.request('complete.php', {
            method: 'POST',
            body: {
                appointment_id: id,
                final_price: finalPrice,
                payment_method: paymentMethod
            }
        });
    }

    // ============================================
    // HELPER METODLAR
    // ============================================

    /**
     * FormData'dan randevu oluştur
     * @param {HTMLFormElement} form 
     * @returns {Promise}
     */
    async createFromForm(form) {
        const formData = new FormData(form);
        
        // Service IDs'i diziye çevir
        const serviceIds = formData.getAll('service_ids[]');
        formData.delete('service_ids[]');
        
        const data = Object.fromEntries(formData);
        data.service_ids = serviceIds.map(id => parseInt(id));
        
        // CSRF token'ı body'ye de ekle (FormData için)
        data.csrf_token = this.csrfToken;
        
        return this.create(data);
    }

    /**
     * FormData'dan randevu güncelle
     * @param {HTMLFormElement} form 
     * @returns {Promise}
     */
    async updateFromForm(form) {
        const formData = new FormData(form);
        
        const serviceIds = formData.getAll('service_ids[]');
        formData.delete('service_ids[]');
        
        const data = Object.fromEntries(formData);
        data.service_ids = serviceIds.map(id => parseInt(id));
        
        const id = data.appointment_id;
        delete data.appointment_id;
        
        return this.update(id, data);
    }
}

// ============================================
// GLOBAL API İNSTANCE
// ============================================

// Sayfa yüklendiğinde global instance oluştur
const appointmentsAPI = new AppointmentsAPI();

// ============================================
// UI HELPER FONKSİYONLARI
// ============================================

/**
 * Randevu listesini tabloya render et
 * @param {array} appointments 
 * @param {HTMLElement} container 
 */
function renderAppointmentsList(appointments, container) {
    if (!container) return;
    
    if (!appointments || appointments.length === 0) {
        container.innerHTML = `
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    <i class="fas fa-calendar-times fa-2x mb-2"></i>
                    <p>Randevu bulunamadı</p>
                </td>
            </tr>
        `;
        return;
    }
    
    const html = appointments.map(apt => `
        <tr data-id="${apt.id}">
            <td>
                <strong>${apt.customer_name}</strong>
                <br><small class="text-muted">${formatPhone(apt.customer_phone)}</small>
            </td>
            <td>${apt.appointment_date_formatted}</td>
            <td>${apt.appointment_time_formatted}</td>
            <td>${apt.employee_name || '-'}</td>
            <td>
                ${apt.services.map(s => `<span class="badge badge-secondary">${s.name}</span>`).join(' ')}
            </td>
            <td>${apt.total_price_formatted}</td>
            <td>
                <span class="badge badge-${getStatusBadgeClass(apt.status)}">${getStatusLabel(apt.status)}</span>
            </td>
            <td>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-info btn-edit" data-id="${apt.id}" title="Düzenle">
                        <i class="fas fa-edit"></i>
                    </button>
                    ${apt.status !== 'completed' && apt.status !== 'cancelled' ? `
                        <button class="btn btn-success btn-complete" data-id="${apt.id}" title="Tamamla">
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="btn btn-danger btn-delete" data-id="${apt.id}" title="İptal">
                            <i class="fas fa-times"></i>
                        </button>
                    ` : ''}
                </div>
            </td>
        </tr>
    `).join('');
    
    container.innerHTML = html;
    
    // Event listener'ları ekle
    attachAppointmentActions(container);
}

/**
 * Durum badge sınıfını döndür
 * @param {string} status 
 * @returns {string}
 */
function getStatusBadgeClass(status) {
    const classes = {
        'confirmed': 'primary',
        'completed': 'success',
        'cancelled': 'danger',
        'pending': 'warning'
    };
    return classes[status] || 'secondary';
}

/**
 * Durum etiketini döndür
 * @param {string} status 
 * @returns {string}
 */
function getStatusLabel(status) {
    const labels = {
        'confirmed': 'Onaylı',
        'completed': 'Tamamlandı',
        'cancelled': 'İptal',
        'pending': 'Bekliyor'
    };
    return labels[status] || status;
}

/**
 * Telefon formatla
 * @param {string} phone 
 * @returns {string}
 */
function formatPhone(phone) {
    if (!phone) return '-';
    const cleaned = phone.replace(/\D/g, '');
    if (cleaned.length === 10) {
        return `(${cleaned.slice(0,3)}) ${cleaned.slice(3,6)} ${cleaned.slice(6,8)} ${cleaned.slice(8)}`;
    }
    return phone;
}

/**
 * Randevu action butonlarına event listener ekle
 * @param {HTMLElement} container 
 */
function attachAppointmentActions(container) {
    // Düzenle
    container.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            try {
                const response = await appointmentsAPI.get(id);
                if (response.success) {
                    openEditModal(response.data);
                } else {
                    showNotification(response.message, 'error');
                }
            } catch (error) {
                showNotification('Randevu bilgileri alınamadı', 'error');
            }
        });
    });
    
    // Tamamla
    container.querySelectorAll('.btn-complete').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            openCompleteModal(id);
        });
    });
    
    // Sil/İptal
    container.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            confirmDelete(id);
        });
    });
}

/**
 * Silme onayı
 * @param {number} id 
 */
async function confirmDelete(id) {
    if (!confirm('Bu randevuyu kalıcı olarak silmek istediğinize emin misiniz?\n\nBu işlem geri alınamaz!')) {
        return;
    }
    
    try {
        const response = await appointmentsAPI.delete(id);
        
        if (response.success) {
            showNotification(response.message, 'success');
            // Listeyi yenile
            if (typeof refreshAppointmentsList === 'function') {
                refreshAppointmentsList();
            } else {
                location.reload();
            }
        } else {
            showNotification(response.message, 'error');
        }
    } catch (error) {
        showNotification('Randevu silinirken hata oluştu', 'error');
    }
}

/**
 * Bildirim göster
 * @param {string} message 
 * @param {string} type - success, error, warning, info
 */
function showNotification(message, type = 'info') {
    // Mevcut notification sistemini kullan
    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
        return;
    }
    
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, type, 3000);
        return;
    }
    
    // Fallback: otomatik kapanan toast
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;top:20px;right:20px;padding:12px 20px;border-radius:8px;color:white;z-index:99999;font-size:14px;';
    toast.style.backgroundColor = type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ============================================
// SAYFA BAŞLANGICINDA
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    console.log('Appointments API Client loaded');
    
    // Form submit handler
    const appointmentForm = document.getElementById('appointmentForm');
    if (appointmentForm) {
        appointmentForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const isEdit = appointmentForm.dataset.mode === 'edit';
            const submitBtn = appointmentForm.querySelector('[type="submit"]');
            
            try {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> İşleniyor...';
                
                const response = isEdit 
                    ? await appointmentsAPI.updateFromForm(appointmentForm)
                    : await appointmentsAPI.createFromForm(appointmentForm);
                
                if (response.success) {
                    showNotification(response.message, 'success');
                    
                    // Modal kapat ve listeyi yenile
                    const modal = appointmentForm.closest('.modal');
                    if (modal && typeof bootstrap !== 'undefined') {
                        bootstrap.Modal.getInstance(modal)?.hide();
                    }
                    
                    if (typeof refreshAppointmentsList === 'function') {
                        refreshAppointmentsList();
                    } else {
                        location.reload();
                    }
                } else {
                    // Validation hataları
                    if (response.errors) {
                        Object.entries(response.errors).forEach(([field, msg]) => {
                            const input = appointmentForm.querySelector(`[name="${field}"]`);
                            if (input) {
                                input.classList.add('is-invalid');
                                const feedback = input.parentNode.querySelector('.invalid-feedback');
                                if (feedback) feedback.textContent = msg;
                            }
                        });
                    }
                    showNotification(response.message, 'error');
                }
            } catch (error) {
                showNotification('İşlem sırasında hata oluştu', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = isEdit ? 'Güncelle' : 'Kaydet';
            }
        });
    }
});

// Global export
window.AppointmentsAPI = AppointmentsAPI;
window.appointmentsAPI = appointmentsAPI;
