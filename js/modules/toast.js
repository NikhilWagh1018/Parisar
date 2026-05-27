/**
 * Toast Notification System
 * Non-blocking notifications for user feedback
 */

class Toast {
  static types = {
    success: 'success',
    error: 'error',
    warning: 'warning',
    info: 'info'
  };

  static defaults = {
    duration: 4000, // Auto-dismiss after 4 seconds
    position: 'bottom-right' // top-left, top-right, bottom-left, bottom-right, top-center, bottom-center
  };

  static init() {
    // Create toast container if not exists
    if (!document.getElementById('toast-container')) {
      const container = document.createElement('div');
      container.id = 'toast-container';
      container.className = 'toast-container';
      document.body.appendChild(container);
    }
  }

  static show(message, options = {}) {
    this.init();

    const config = {
      type: options.type || this.types.info,
      duration: options.duration !== undefined ? options.duration : this.defaults.duration,
      position: options.position || this.defaults.position,
      action: options.action || null
    };

    const toastId = `toast-${Date.now()}-${Math.random()}`;
    const toast = this.createToastElement(toastId, message, config);

    const container = document.getElementById('toast-container');
    container.appendChild(toast);

    // Trigger animation
    setTimeout(() => toast.classList.add('show'), 10);

    // Auto-dismiss
    if (config.duration > 0) {
      setTimeout(() => this.dismiss(toastId), config.duration);
    }

    return toastId;
  }

  static createToastElement(id, message, config) {
    const toast = document.createElement('div');
    toast.id = id;
    toast.className = `toast toast-${config.type} toast-${config.position}`;

    const iconMap = {
      success: '✓',
      error: '✕',
      warning: '⚠',
      info: 'ℹ'
    };

    let html = `
      <div class="toast-content">
        <span class="toast-icon">${iconMap[config.type]}</span>
        <span class="toast-message">${this.escapeHtml(message)}</span>
    `;

    if (config.action) {
      html += `
        <button type="button" class="toast-action" data-action="${config.action.name}">
          ${this.escapeHtml(config.action.label)}
        </button>
      `;
    }

    html += `
        <button type="button" class="toast-close" aria-label="Close notification">×</button>
      </div>
      <div class="toast-progress"></div>
    `;

    toast.innerHTML = html;

    // Close button
    toast.querySelector('.toast-close').addEventListener('click', () => {
      this.dismiss(id);
    });

    // Action button
    if (config.action && config.action.callback) {
      toast.querySelector('.toast-action').addEventListener('click', () => {
        config.action.callback();
        this.dismiss(id);
      });
    }

    return toast;
  }

  static dismiss(toastId) {
    const toast = document.getElementById(toastId);
    if (toast) {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 300);
    }
  }

  static dismissAll() {
    const container = document.getElementById('toast-container');
    if (container) {
      const toasts = container.querySelectorAll('.toast');
      toasts.forEach(toast => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
      });
    }
  }

  static success(message, options = {}) {
    return this.show(message, { ...options, type: this.types.success });
  }

  static error(message, options = {}) {
    return this.show(message, { ...options, type: this.types.error });
  }

  static warning(message, options = {}) {
    return this.show(message, { ...options, type: this.types.warning });
  }

  static info(message, options = {}) {
    return this.show(message, { ...options, type: this.types.info });
  }

  static escapeHtml(text) {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
  }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = Toast;
}