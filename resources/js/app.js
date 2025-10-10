/**
 * CIC Campus Portal - Main Application JavaScript
 * Global utilities and functionality for the campus portal application
 */

// Import Bootstrap if needed
// import './bootstrap';

class CampusPortal {
    constructor() {
        this.init();
    }

    init() {
        this.setupGlobalEventListeners();
        this.setupAjaxDefaults();
        this.initializeTooltips();
        this.setupSessionRefresh();
        this.setupGlobalLoading();
    }

    /**
     * Setup global event listeners
     */
    setupGlobalEventListeners() {
        // Handle auto-dismissing alerts
        document.addEventListener('DOMContentLoaded', () => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });

        // Handle form submissions with loading states
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (form.classList.contains('ajax-form')) {
                e.preventDefault();
                this.handleAjaxForm(form);
            }
        });

        // Handle logout confirmation
        document.querySelectorAll('[data-logout]').forEach(button => {
            button.addEventListener('click', (e) => {
                if (!confirm('Apakah Anda yakin ingin logout?')) {
                    e.preventDefault();
                }
            });
        });

        // Handle mobile menu toggles
        document.querySelectorAll('[data-toggle="mobile-menu"]').forEach(button => {
            button.addEventListener('click', () => {
                this.toggleMobileMenu();
            });
        });

        // Handle responsive sidebar
        this.setupResponsiveSidebar();
    }

    /**
     * Setup AJAX defaults
     */
    setupAjaxDefaults() {
        // Setup default headers for fetch requests
        const originalFetch = window.fetch;
        window.fetch = async function(...args) {
            const [url, options = {}] = args;

            // Add default headers
            const defaultHeaders = {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            };

            // Add CSRF token if available
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (csrfToken) {
                defaultHeaders['X-CSRF-TOKEN'] = csrfToken;
            }

            options.headers = {
                ...defaultHeaders,
                ...options.headers,
            };

            try {
                const response = await originalFetch(url, options);

                // Handle session expiration
                if (response.status === 401) {
                    this.handleSessionExpired();
                    return response;
                }

                return response;
            } catch (error) {
                console.error('AJAX request failed:', error);
                this.showGlobalError('Terjadi kesalahan jaringan. Silakan coba lagi.');
                throw error;
            }
        }.bind(this);
    }

    /**
     * Initialize Bootstrap tooltips
     */
    initializeTooltips() {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    /**
     * Setup automatic session refresh
     */
    setupSessionRefresh() {
        // Check session every 5 minutes
        setInterval(async () => {
            try {
                const response = await fetch('/api/campus/check-session');
                const data = await response.json();

                if (!data.meta?.campus_session_valid) {
                    this.handleSessionExpired();
                } else {
                    this.updateSessionStatus(true);
                }
            } catch (error) {
                console.error('Session check failed:', error);
            }
        }, 300000); // 5 minutes
    }

    /**
     * Setup global loading states
     */
    setupGlobalLoading() {
        // Create loading overlay if it doesn't exist
        if (!document.getElementById('globalLoading')) {
            const loadingOverlay = document.createElement('div');
            loadingOverlay.id = 'globalLoading';
            loadingOverlay.className = 'global-loading-overlay';
            loadingOverlay.innerHTML = `
                <div class="loading-content">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="loading-text">Memuat...</div>
                </div>
            `;
            document.body.appendChild(loadingOverlay);
        }
    }

    /**
     * Handle AJAX form submissions
     */
    async handleAjaxForm(form) {
        const formData = new FormData(form);
        const submitButton = form.querySelector('[type="submit"]');
        const originalButtonText = submitButton?.innerHTML;

        try {
            // Show loading state
            this.setFormLoading(form, true);

            const response = await fetch(form.action, {
                method: form.method,
                body: formData,
            });

            const data = await response.json();

            if (data.status === 'success') {
                this.showGlobalSuccess(data.message || 'Operasi berhasil');

                // Handle redirect if specified
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1000);
                }

                // Trigger custom event for success
                form.dispatchEvent(new CustomEvent('ajax-success', { detail: data }));
            } else {
                this.showGlobalError(data.message || 'Operasi gagal');
                form.dispatchEvent(new CustomEvent('ajax-error', { detail: data }));
            }
        } catch (error) {
            console.error('Form submission failed:', error);
            this.showGlobalError('Terjadi kesalahan. Silakan coba lagi.');
        } finally {
            // Restore button state
            if (submitButton && originalButtonText) {
                submitButton.innerHTML = originalButtonText;
                submitButton.disabled = false;
            }
            this.setFormLoading(form, false);
        }
    }

    /**
     * Set loading state for forms
     */
    setFormLoading(form, isLoading) {
        const submitButton = form.querySelector('[type="submit"]');
        const inputs = form.querySelectorAll('input, select, textarea');

        if (isLoading) {
            submitButton?.setAttribute('disabled', 'disabled');
            inputs.forEach(input => input.setAttribute('disabled', 'disabled'));

            if (submitButton) {
                const originalText = submitButton.innerHTML;
                submitButton.setAttribute('data-original-text', originalText);
                submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
            }
        } else {
            submitButton?.removeAttribute('disabled');
            inputs.forEach(input => input.removeAttribute('disabled'));

            if (submitButton) {
                const originalText = submitButton.getAttribute('data-original-text');
                if (originalText) {
                    submitButton.innerHTML = originalText;
                    submitButton.removeAttribute('data-original-text');
                }
            }
        }
    }

    /**
     * Handle session expiration
     */
    handleSessionExpired() {
        this.showGlobalError('Session Anda telah expired. Mengalihkan ke halaman login...', 10000);
        this.updateSessionStatus(false);

        setTimeout(() => {
            window.location.href = '/login';
        }, 3000);
    }

    /**
     * Update session status indicator
     */
    updateSessionStatus(isValid) {
        const sessionStatus = document.getElementById('sessionStatus');
        if (sessionStatus) {
            if (isValid) {
                sessionStatus.className = 'session-status valid';
                sessionStatus.innerHTML = '<i class="fas fa-circle text-success"></i><span>Session Aktif</span>';
            } else {
                sessionStatus.className = 'session-status invalid';
                sessionStatus.innerHTML = '<i class="fas fa-circle text-danger"></i><span>Session Expired</span>';
            }
        }
    }

    /**
     * Setup responsive sidebar
     */
    setupResponsiveSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileToggle = document.getElementById('mobileToggle');

        if (!sidebar || !mainContent) return;

        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                mainContent.classList.remove('expanded');
            }
        });

        // Handle mobile menu toggle
        mobileToggle?.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(e.target) && !mobileToggle?.contains(e.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });
    }

    /**
     * Toggle mobile menu
     */
    toggleMobileMenu() {
        const sidebar = document.getElementById('sidebar');
        sidebar?.classList.toggle('active');
    }

    /**
     * Show global loading overlay
     */
    showGlobalLoading(text = 'Memuat...') {
        const loadingOverlay = document.getElementById('globalLoading');
        if (loadingOverlay) {
            const loadingText = loadingOverlay.querySelector('.loading-text');
            if (loadingText) {
                loadingText.textContent = text;
            }
            loadingOverlay.style.display = 'flex';
        }
    }

    /**
     * Hide global loading overlay
     */
    hideGlobalLoading() {
        const loadingOverlay = document.getElementById('globalLoading');
        if (loadingOverlay) {
            loadingOverlay.style.display = 'none';
        }
    }

    /**
     * Show global success message
     */
    showGlobalSuccess(message, duration = 3000) {
        this.showGlobalAlert(message, 'success', duration);
    }

    /**
     * Show global error message
     */
    showGlobalError(message, duration = 5000) {
        this.showGlobalAlert(message, 'danger', duration);
    }

    /**
     * Show global alert message
     */
    showGlobalAlert(message, type = 'info', duration = 3000) {
        const alertId = 'alert-' + Date.now();
        const alertHtml = `
            <div id="${alertId}" class="alert alert-${type} alert-dismissible fade show position-fixed"
                 style="top: 20px; right: 20px; z-index: 9999; min-width: 300px;" role="alert">
                <i class="fas fa-${this.getAlertIcon(type)} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', alertHtml);

        // Auto-dismiss after duration
        setTimeout(() => {
            const alertElement = document.getElementById(alertId);
            if (alertElement) {
                const bsAlert = new bootstrap.Alert(alertElement);
                bsAlert.close();
            }
        }, duration);
    }

    /**
     * Get appropriate icon for alert type
     */
    getAlertIcon(type) {
        const icons = {
            success: 'check-circle',
            danger: 'exclamation-triangle',
            warning: 'exclamation-triangle',
            info: 'info-circle',
        };
        return icons[type] || 'info-circle';
    }

    /**
     * Format date to Indonesian format
     */
    formatDate(date) {
        const options = {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        };
        return new Date(date).toLocaleDateString('id-ID', options);
    }

    /**
     * Format currency to Indonesian format
     */
    formatCurrency(amount) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR'
        }).format(amount);
    }

    /**
     * Debounce function for search inputs
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
}

// Initialize the application when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.campusPortal = new CampusPortal();
});

// Add global CSS for loading overlay
const globalStyles = `
    .global-loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.9);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        backdrop-filter: blur(2px);
    }

    .loading-content {
        text-align: center;
    }

    .loading-text {
        margin-top: 15px;
        font-weight: 500;
        color: #2c3e50;
    }
`;

// Inject global styles
const styleSheet = document.createElement('style');
styleSheet.textContent = globalStyles;
document.head.appendChild(styleSheet);

// Export for module usage
export default CampusPortal;
