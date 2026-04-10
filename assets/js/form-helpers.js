/**
 * Helpers formulaires : chargement bouton, alertes globales, erreurs champs, POST JSON/FormData.
 */
(function (global) {
  'use strict';

  /**
   * @param {HTMLButtonElement} buttonEl
   * @param {boolean} isLoading
   * @param {string} [loadingText='Chargement...']
   */
  function setLoading(buttonEl, isLoading, loadingText) {
    if (!buttonEl) return;
    var text = loadingText === undefined ? 'Chargement...' : loadingText;
    if (isLoading) {
      if (buttonEl.dataset.originalText === undefined) {
        buttonEl.dataset.originalText = buttonEl.textContent.trim();
      }
      buttonEl.disabled = true;
      buttonEl.classList.add('btn-loading');
      buttonEl.innerHTML = '';
      var label = document.createElement('span');
      label.textContent = text + ' ';
      var spin = document.createElement('span');
      spin.className = 'spinner';
      spin.setAttribute('aria-hidden', 'true');
      buttonEl.appendChild(label);
      buttonEl.appendChild(spin);
    } else {
      buttonEl.disabled = false;
      buttonEl.classList.remove('btn-loading');
      var orig = buttonEl.dataset.originalText;
      if (orig !== undefined) {
        buttonEl.textContent = orig;
      }
    }
  }

  /**
   * @param {string} message
   * @param {'success'|'error'|'warning'|'info'} [type='success']
   * @param {string} [containerId='alert-container']
   * @param {number} [duration=5000]
   */
  function showAlert(message, type, containerId, duration) {
    type = type || 'success';
    containerId = containerId || 'alert-container';
    duration = duration === undefined ? 5000 : duration;
    var container = document.getElementById(containerId);
    if (!container) return;

    var typeClass = {
      success: 'alert-success',
      error: 'alert-error',
      warning: 'alert-warning',
      info: 'alert-info'
    };
    var cls = typeClass[type] || typeClass.success;

    var div = document.createElement('div');
    div.className = 'alert ' + cls;
    div.setAttribute('role', 'alert');
    div.textContent = message;
    container.appendChild(div);

    if (duration > 0) {
      setTimeout(function () {
        if (div.parentNode) {
          div.parentNode.removeChild(div);
        }
      }, duration);
    }
  }

  /**
   * @param {HTMLElement} fieldEl
   * @param {string} message
   */
  function showFieldError(fieldEl, message) {
    if (!fieldEl) return;
    fieldEl.classList.add('field-error');
    var next = fieldEl.nextElementSibling;
    if (next && next.classList && next.classList.contains('error-message')) {
      next.textContent = message;
      return;
    }
    var span = document.createElement('span');
    span.className = 'error-message';
    span.textContent = message;
    fieldEl.insertAdjacentElement('afterend', span);
  }

  /**
   * @param {HTMLFormElement} formEl
   */
  function clearFieldErrors(formEl) {
    if (!formEl) return;
    formEl.querySelectorAll('.field-error').forEach(function (el) {
      el.classList.remove('field-error');
    });
    formEl.querySelectorAll('.error-message').forEach(function (el) {
      el.remove();
    });
  }

  /**
   * @param {string} url
   * @param {Record<string, unknown>|FormData} data
   * @param {RequestInit & { headers?: Record<string, string> }} [options={}]
   * @returns {Promise<unknown>}
   */
  function apiPost(url, data, options) {
    options = options || {};
    var headers = new Headers(options.headers || {});

    var body;
    if (typeof FormData !== 'undefined' && data instanceof FormData) {
      body = data;
    } else {
      if (!headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
      }
      body = typeof data === 'string' ? data : JSON.stringify(data);
    }

    var init = Object.assign({}, options, {
      method: 'POST',
      body: body,
      credentials: options.credentials != null ? options.credentials : 'include',
      headers: headers
    });

    return fetch(url, init)
      .then(function (res) {
        return res.text().then(function (text) {
          if (!text) return null;
          try {
            return JSON.parse(text);
          } catch (_) {
            return { ok: false, error: text, status: res.status };
          }
        });
      })
      .catch(function (err) {
        showAlert('Erreur de connexion', 'error');
        return Promise.reject(err);
      });
  }

  global.setLoading = setLoading;
  global.showAlert = showAlert;
  global.showFieldError = showFieldError;
  global.clearFieldErrors = clearFieldErrors;
  global.apiPost = apiPost;
})(typeof window !== 'undefined' ? window : globalThis);
