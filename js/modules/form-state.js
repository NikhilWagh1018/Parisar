/**
 * Form State Manager with Auto-Save
 * Handles localStorage persistence and form state recovery
 */

class FormStateManager {
  constructor(formId, storageKey = 'form-draft') {
    this.form = document.getElementById(formId);
    this.storageKey = storageKey;
    this.autoSaveInterval = 30000; // 30 seconds
    this.isDirty = false;
    this.timer = null;
    
    if (!this.form) {
      console.warn(`Form with ID "${formId}" not found`);
      return;
    }
    
    this.init();
  }

  init() {
    // Check for recovered draft
    this.checkForDraft();
    
    // Track form changes
    this.form.addEventListener('input', () => {
      this.isDirty = true;
      this.scheduleAutoSave();
    });

    this.form.addEventListener('change', () => {
      this.isDirty = true;
      this.scheduleAutoSave();
    });

    // Clear draft on successful submission
    this.form.addEventListener('submit', (e) => {
      // Don't prevent default, just clear draft on success
      this.clearDraft();
    });

    // Warn on unsaved changes — fires from the FIRST keystroke (Fix #17).
    // Previously gated on hasDraft(), which is only true after the 30-second
    // auto-save fires. That left a silent window where navigating away right
    // after typing would lose data without any warning.
    // Now we guard on isDirty alone: any change to the form triggers the warn.
    window.addEventListener('beforeunload', (e) => {
      if (this.isDirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    });
  }

  scheduleAutoSave() {
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => this.saveDraft(), this.autoSaveInterval);
  }

  // Maximum age of a draft before it is automatically discarded (24 hours).
  // Prevents sensitive audit data lingering on shared or public devices.
  static DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000;

  saveDraft() {
    if (!this.isDirty) return;

    const formData = new FormData(this.form);
    const data = {};

    for (let [key, value] of formData.entries()) {
      if (data[key]) {
        // Handle multiple values (checkboxes, multi-select)
        if (!Array.isArray(data[key])) {
          data[key] = [data[key]];
        }
        data[key].push(value);
      } else {
        data[key] = value;
      }
    }

    // Save with timestamp so we can enforce expiry on load
    const draft = {
      data,
      timestamp: new Date().toISOString(),
      formId: this.form.id
    };

    try {
      localStorage.setItem(this.storageKey, JSON.stringify(draft));
      console.log('Draft saved at', new Date().toLocaleTimeString());
    } catch (e) {
      console.error('Failed to save draft:', e);
    }
  }

  checkForDraft() {
    const draft = this.getDraft();
    if (draft && draft.data) {
      this.showRestoreBanner(draft);
    }
  }

  getDraft() {
    try {
      const stored = localStorage.getItem(this.storageKey);
      if (!stored) return null;

      const draft = JSON.parse(stored);

      // Discard drafts older than DRAFT_MAX_AGE_MS (default 24 h).
      // This prevents sensitive audit data lingering on shared devices.
      if (draft && draft.timestamp) {
        const age = Date.now() - new Date(draft.timestamp).getTime();
        if (age > FormStateManager.DRAFT_MAX_AGE_MS) {
          console.log('Draft expired — discarding');
          localStorage.removeItem(this.storageKey);
          return null;
        }
      }

      return draft;
    } catch (e) {
      console.error('Failed to retrieve draft:', e);
      return null;
    }
  }

  hasDraft() {
    return !!this.getDraft();
  }

  showRestoreBanner(draft) {
    const banner = document.createElement('div');
    banner.className = 'restore-banner';
    banner.id = 'restore-banner';
    
    const timestamp = new Date(draft.timestamp);
    const timeStr = timestamp.toLocaleString();
    
    banner.innerHTML = `
      <div class="restore-banner-content">
        <div class="restore-banner-message">
          <strong>📋 Draft Recovered</strong>
          <p>We found an unsaved draft from ${timeStr}</p>
        </div>
        <div class="restore-banner-actions">
          <button type="button" class="btn-restore" id="restore-btn">Restore Draft</button>
          <button type="button" class="btn-discard" id="discard-btn">Discard</button>
        </div>
      </div>
    `;

    // Insert before form
    this.form.parentNode.insertBefore(banner, this.form);

    // Handle restore
    document.getElementById('restore-btn').addEventListener('click', () => {
      this.restoreDraft(draft);
      banner.remove();
    });

    // Handle discard
    document.getElementById('discard-btn').addEventListener('click', () => {
      this.clearDraft();
      banner.remove();
    });
  }

  restoreDraft(draft) {
    const formData = draft.data;

    for (let [key, value] of Object.entries(formData)) {
      const field = this.form.querySelector(`[name="${key}"]`);
      if (!field) continue;

      if (field.type === 'checkbox') {
        field.checked = Array.isArray(value) ? value.includes(field.value) : value === field.value;
      } else if (field.type === 'radio') {
        field.checked = field.value === value;
      } else if (field.tagName === 'TEXTAREA' || field.tagName === 'SELECT') {
        field.value = value;
      } else {
        field.value = value;
      }

      // Trigger change event for dependent fields
      field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    this.isDirty = false;
    console.log('Draft restored successfully');
  }

  clearDraft() {
    try {
      localStorage.removeItem(this.storageKey);
      this.isDirty = false;
      if (this.timer) clearTimeout(this.timer);
      console.log('Draft cleared');
    } catch (e) {
      console.error('Failed to clear draft:', e);
    }
  }

  // Manual save trigger
  save() {
    this.saveDraft();
  }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = FormStateManager;
}