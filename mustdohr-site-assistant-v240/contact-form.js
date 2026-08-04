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
        data.page_url = window.location.href;
        data.visitor_id = visitorId;
        data.trigger_reason = 'web-contact-form';
        data.source_website = (root && root.getAttribute('data-source-website')) || config.sourceWebsite || window.location.hostname || 'Mustdohr';
        data.chat_question = '';
        data.chat_transcript = '';
        var response = await fetch(config.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
        var result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Your enquiry could not be sent.');
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
