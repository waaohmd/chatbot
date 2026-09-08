(function () {
  'use strict';

  var config = window.MustdohrContactFormConfig || {};
  var visitorCookie = 'mdh_visitor_id';
  var noticeCookie = 'mdh_cookie_notice';

  function readCookie(name) {
    var prefix = name + '=';
    var found = document.cookie.split('; ').find(function (part) { return part.indexOf(prefix) === 0; });
    return found ? decodeURIComponent(found.slice(prefix.length)) : '';
  }

  function writeCookie(name, value, maxAge) {
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
  }

  async function postContact(target, data) {
    var isAjax = target && target.ajax === true;
    var options = { method: 'POST', headers: { 'Accept': 'application/json', 'X-MDH-Nonce': config.nonce || '' } };
    if (isAjax) {
      var formData = new URLSearchParams(data);
      formData.set('action', 'mdh_chatbot_submit_contact');
      formData.set('_mdh_nonce', config.nonce || '');
      options.body = formData;
      options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(Object.assign({}, data, { _mdh_nonce: config.nonce || '' }));
    }
    var response;
    try {
      response = await fetch(target.url, options);
    } catch (networkError) {
      networkError.retryableContact = true;
      throw networkError;
    }
    var raw = await response.text();
    var result;
    try {
      result = raw ? JSON.parse(raw) : {};
    } catch (parseError) {
      var nonJsonError = new Error('This WordPress endpoint returned a web page. Trying the next contact endpoint.');
      nonJsonError.retryableContact = true;
      nonJsonError.status = response.status;
      throw nonJsonError;
    }

    if (result && result.success === true && result.data) result = result.data;
    // The server writes the contact before attempting email delivery. A
    // notification failure therefore means the record is already saved and
    // must not fall through to the legacy endpoint retry loop, which would
    // create duplicate contact rows.
    var savedWithoutNotification = result && (
      result.code === 'contact_notification_failed' ||
      result.notification_sent === false ||
      (result.data && (result.data.code === 'contact_notification_failed' || result.data.notification_sent === false))
    );
    if (savedWithoutNotification) {
      return {
        ok: true,
        notification_sent: false,
        message: result.message || 'Your enquiry was saved, but the email notification could not be sent. Please try again or contact us directly.'
      };
    }
    if (!response.ok || (result && result.success === false)) {
      var responseMessage = (result && (result.message || (result.data && result.data.message))) || 'Your enquiry could not be sent.';
      var responseError = new Error(responseMessage);
      responseError.status = response.status;
      var responseCode = result && (result.code || (result.data && result.data.code));
      responseError.retryableContact = ['captcha_required', 'captcha_failed', 'captcha_unavailable', 'captcha_not_configured'].indexOf(responseCode) === -1 && (response.status === 403 || response.status === 404 || response.status === 405 || response.status >= 500);
      throw responseError;
    }
    if (!result || result.ok !== true) {
      var inactiveError = new Error('This contact endpoint is not handled by the active plugin. Trying the next endpoint.');
      inactiveError.retryableContact = true;
      inactiveError.status = response.status;
      throw inactiveError;
    }
    return result;
  }

  var visitorId = readCookie(visitorCookie) || sessionStorage.getItem('mdh-assistant-visitor-session') || '';
  if (!visitorId) {
    visitorId = ((window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : String(Date.now()) + Math.random()).replace(/[^a-zA-Z0-9_-]/g, '');
  }
  writeCookie(visitorCookie, visitorId, 60 * 60 * 24 * 365);
  sessionStorage.setItem('mdh-assistant-visitor-session', visitorId);

  document.querySelectorAll('[data-web-contact-submit]').forEach(function (form) {
    var root = form.closest('[data-source-website]') || form.closest('.mdh-web-contact-form');
    var status = root && root.querySelector('[data-web-contact-status]');
    var cookieNotice = root && root.querySelector('[data-web-contact-cookie]');
    var params = new URLSearchParams(window.location.search);
    var prefillCountry = params.get('prefill_country') || '';
    var prefillService = params.get('prefill_service') || '';
    var prefillMessage = params.get('prefill_message') || '';
    var countryField = form.querySelector('[name="country"]');
    var serviceField = form.querySelector('[name="request_type"]');
    var messageField = form.querySelector('[name="message"]');
    if (countryField && prefillCountry) countryField.value = prefillCountry;
    if (serviceField && prefillService) serviceField.value = prefillService;
    if (messageField && prefillMessage) messageField.value = prefillMessage;
    if (root && (prefillCountry || prefillService || prefillMessage)) {
      window.setTimeout(function () {
        root.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 50);
    }
    if (cookieNotice && readCookie(noticeCookie) !== '1') cookieNotice.hidden = false;
    var dismiss = cookieNotice && cookieNotice.querySelector('[data-web-contact-cookie-dismiss]');
    if (dismiss) dismiss.addEventListener('click', function () {
      writeCookie(noticeCookie, '1', 60 * 60 * 24 * 365);
      cookieNotice.hidden = true;
    });

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      var submit = form.querySelector('button[type="submit"]');
      if (submit) submit.disabled = true;
      if (status) { status.hidden = false; status.className = 'mdh-web-contact-form__status is-pending'; status.textContent = 'Sending your enquiry…'; }
      try {
        var data = Object.fromEntries(new FormData(form).entries());
        data.trigger_reason = 'website_contact_form';
        var endpoints = [
          { url: config.ajaxEndpoint, ajax: true },
          { url: config.postEndpoint, ajax: true },
          { url: config.directEndpoint },
          { url: config.endpoint },
          { url: config.fallbackEndpoint }
        ].filter(function (target, index, list) {
          return target.url && list.findIndex(function (item) { return item.url === target.url; }) === index;
        });
        var result;
        var lastError;
        for (var endpointIndex = 0; endpointIndex < endpoints.length; endpointIndex += 1) {
          try {
            result = await postContact(endpoints[endpointIndex], data);
            lastError = null;
            break;
          } catch (endpointError) {
            lastError = endpointError;
            if (!endpointError.retryableContact || endpointIndex === endpoints.length - 1) throw endpointError;
          }
        }
        if (lastError) throw lastError;
        form.reset();
        if (status) { status.className = 'mdh-web-contact-form__status is-success'; status.textContent = result.message || 'Thank you. The Mustdohr team will be in touch.'; }
      } catch (error) {
        if (status) { status.className = 'mdh-web-contact-form__status is-error'; status.textContent = error.message || 'Your enquiry could not be sent. Please try again.'; }
      } finally {
        if (submit) submit.disabled = false;
      }
    });
  });
}());
