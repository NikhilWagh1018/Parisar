/**
 * Loading State Manager
 * Handles button and form loading states with spinner
 */

class LoadingState {
  constructor(element) {
    this.element = typeof element === 'string' 
      ? document.querySelector(element) 
      : element;
    this.originalContent = null;
    this.originalDisabled = false;
  }

  show(loadingText = 'Loading...') {
    if (!this.element) return;

    // Store original state
    this.originalContent = this.element.innerHTML;
    this.originalDisabled = this.element.disabled;

    // Create spinner
    const spinner = document.createElement('span');
    spinner.className = 'spinner';
    spinner.innerHTML = '<span></span><span></span><span></span>';

    // Update button
    this.element.innerHTML = '';
    this.element.appendChild(spinner);
    this.element.appendChild(document.createTextNode(' ' + loadingText));
    this.element.disabled = true;
    this.element.classList.add('loading');
  }

  hide() {
    if (!this.element) return;

    this.element.innerHTML = this.originalContent;
    this.element.disabled = this.originalDisabled;
    this.element.classList.remove('loading');
  }

  static showFor(selector, loadingText = 'Loading...') {
    const elements = document.querySelectorAll(selector);
    const instances = [];
    elements.forEach(el => {
      const loader = new LoadingState(el);
      loader.show(loadingText);
      instances.push(loader);
    });
    return instances;
  }

  static hideAll(selector) {
    const elements = document.querySelectorAll(selector);
    elements.forEach(el => {
      const loader = new LoadingState(el);
      loader.hide();
    });
  }
}

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = LoadingState;
}