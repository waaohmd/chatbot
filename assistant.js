(function () {
  const root = document.getElementById('mdh-assistant');
  if (!root) return;

  const launch = root.querySelector('.mdh-assistant-launch');
  const panel = root.querySelector('.mdh-assistant-panel');
  const close = root.querySelector('.mdh-assistant-close');
  const messages = root.querySelector('.mdh-assistant-messages');
  const form = root.querySelector('.mdh-assistant-search-form');
  const input = root.querySelector('input');
  const send = form.querySelector('button');
  const searchIntro = root.querySelector('[data-search-intro]');
  const aiIntro = root.querySelector('[data-ai-intro]');
  const contactToggle = root.querySelector('[data-contact-toggle]');
  const contactForm = root.querySelector('[data-contact-form]');
  const contactRemove = root.querySelector('[data-contact-remove]');
  const contactStatus = root.querySelector('[data-contact-status]');
  const quickToggle = root.querySelector('[data-quick-toggle]');
  const quickPanel = root.querySelector('[data-quick-access]');
  const quickForm = root.querySelector('[data-quick-form]');
  const quickResult = root.querySelector('[data-quick-result]');
  const faqs = root.querySelector('[data-faqs]');
  const cookieNotice = root.querySelector('[data-cookie-notice]');
  const aiCaptcha = root.querySelector('[data-ai-captcha]');
  const config = MustdohrAssistant.config || {};
  const visitorCookie = 'mdh_visitor_id';
  const noticeCookie = 'mdh_cookie_notice';

  function readCookie(name) {
    const prefix = name + '=';
    const found = document.cookie.split('; ').find(function (part) { return part.indexOf(prefix) === 0; });
    return found ? decodeURIComponent(found.slice(prefix.length)) : '';
  }

  function writeCookie(name, value, maxAge) {
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
  }

  async function submitContactRequest(target, data) {
    const options = { method: 'POST', headers: { 'Accept': 'application/json', 'X-MDH-Nonce': MustdohrAssistant.nonce || '' } };
    if (target.ajax) {
      const body = new URLSearchParams(data);
      body.set('action', 'mdh_chatbot_submit_contact');
      body.set('_mdh_nonce', MustdohrAssistant.nonce || '');
      options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      options.body = body;
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(Object.assign({}, data, { _mdh_nonce: MustdohrAssistant.nonce || '' }));
    }

    let response;
    try {
      response = await fetch(target.url, options);
    } catch (networkError) {
      networkError.retryableContact = true;
      throw networkError;
    }
    const raw = await response.text();
    let result;
    try {
      result = raw ? JSON.parse(raw) : {};
    } catch (parseError) {
      const error = new Error('This WordPress endpoint returned a web page. Trying the next contact endpoint.');
      error.retryableContact = true;
      error.status = response.status;
      throw error;
    }

    if (result && result.success === true && result.data) result = result.data;
    if (!response.ok || (result && result.success === false)) {
      const error = new Error((result && (result.message || (result.data && result.data.message))) || 'Your enquiry could not be sent.');
      error.status = response.status;
      const errorCode = result && (result.code || (result.data && result.data.code));
      error.retryableContact = !['captcha_required', 'captcha_failed', 'captcha_unavailable', 'captcha_not_configured'].includes(errorCode) && (response.status === 403 || response.status === 404 || response.status === 405 || response.status >= 500);
      throw error;
    }
    if (!result || result.ok !== true) {
      const error = new Error('This contact endpoint is not handled by the active plugin. Trying the next endpoint.');
      error.retryableContact = true;
      error.status = response.status;
      throw error;
    }
    return result;
  }

  async function submitContactWithFallback(data) {
    const targets = [
      { url: MustdohrAssistant.contactAjaxEndpoint, ajax: true },
      { url: MustdohrAssistant.contactPostEndpoint, ajax: true },
      { url: MustdohrAssistant.contactDirectEndpoint },
      { url: MustdohrAssistant.contactEndpoint },
      { url: MustdohrAssistant.contactFallbackEndpoint }
    ].filter(function (target, index, list) {
      return target.url && list.findIndex(function (item) { return item.url === target.url; }) === index;
    });

    let lastError = new Error('The contact endpoint is not configured.');
    for (let index = 0; index < targets.length; index += 1) {
      try {
        return await submitContactRequest(targets[index], data);
      } catch (error) {
        lastError = error;
        if (!error.retryableContact || index === targets.length - 1) throw error;
      }
    }
    throw lastError;
  }

  // A one-year first-party cookie lets the server associate every chat and
  // contact submission from the same browser, even after a tab is closed.
  let visitorId = readCookie(visitorCookie) || sessionStorage.getItem('mdh-assistant-visitor-session') || '';
  if (!visitorId) {
    visitorId = ((window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : String(Date.now()) + Math.random()).replace(/[^a-zA-Z0-9_-]/g, '');
  }
  writeCookie(visitorCookie, visitorId, 60 * 60 * 24 * 365);
  sessionStorage.setItem('mdh-assistant-visitor-session', visitorId);
  let mode = 'search';
  let lastQuestion = '';
  let lastTrigger = 'manual';
  const chatHistory = [];
  let aiCaptchaToken = '';
  let aiCaptchaVerified = false;

  // Turnstile calls this global callback after the visitor completes the
  // one-time AI security check. The token is sent only with the first AI call.
  window.mdhChatbotAiTurnstileCallback = function (token) {
    aiCaptchaToken = String(token || '');
  };

  if (cookieNotice && readCookie(noticeCookie) !== '1') cookieNotice.hidden = false;
  if (cookieNotice) {
    const dismiss = cookieNotice.querySelector('[data-cookie-dismiss]');
    if (dismiss) dismiss.addEventListener('click', function () {
      writeCookie(noticeCookie, '1', 60 * 60 * 24 * 365);
      cookieNotice.hidden = true;
    });
  }

  function toggle(open) {
    panel.hidden = !open;
    launch.setAttribute('aria-expanded', String(open));
    if (open) input.focus();
  }

  function add(text, type) {
    const item = document.createElement('div');
    item.className = 'mdh-assistant-message ' + type;
    item.textContent = text;
    messages.appendChild(item);
    messages.scrollTop = messages.scrollHeight;
    return item;
  }

  function setContactStatus(text, type) {
    if (!contactStatus) return;
    contactStatus.hidden = false;
    contactStatus.textContent = text;
    contactStatus.className = 'mdh-assistant-contact-status ' + type;
  }

  function showContact(reason) {
    lastTrigger = reason || 'contact';
    if (!contactToggle || !contactForm) return;
    contactToggle.hidden = false;
    if (config.contactMode === 'link' && config.contactUrl) {
      contactToggle.textContent = 'Open contact form';
      return;
    }
    contactForm.hidden = false;
    contactToggle.textContent = 'Contact us';
    const message = contactForm.querySelector('[name="message"]');
    if (message) message.focus();
  }

  function prepareQuickEnquiry(event) {
    event.preventDefault();
    if (!quickForm || !contactForm) return;
    const values = Object.fromEntries(new FormData(quickForm).entries());
    const serviceMap = {
      hiring: { request: 'Onboarding', label: 'Hiring and onboarding' },
      payroll: { request: 'Payroll', label: 'Payroll and payments' },
      compliance: { request: 'HR support', label: 'Compliance and policies' },
      'employee-support': { request: 'HR support', label: 'Employee support and benefits' },
      general: { request: 'General enquiry', label: 'Something else' },
    };
    const service = serviceMap[values.service] || serviceMap.general;
    const setField = function (name, value) {
      const field = contactForm.querySelector('[name="' + name + '"]');
      if (field) field.value = value || '';
    };
    setField('country', values.country);
    setField('request_type', service.request);
    // Keep the message field empty so the visitor can describe their request in their own words.
    showContact('quick_access');
    if (quickResult) {
      quickResult.hidden = false;
      quickResult.textContent = 'We prepared a ' + service.label.toLowerCase() + ' enquiry for ' + values.country + '. Add your name and email below, then send it to our team.';
    }
  }

  function searchTerms(question) {
    const stopWords = new Set([
      'a', 'about', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'been', 'being', 'but', 'by',
      'can', 'could', 'did', 'do', 'does', 'find', 'for', 'from', 'get', 'give', 'has', 'have',
      'help', 'how', 'i', 'if', 'in', 'information', 'into', 'is', 'it', 'its', 'looking', 'may',
      'me', 'mentions', 'might', 'my', 'of', 'on', 'or', 'please', 'search', 'show', 'tell', 'than',
      'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'to', 'us',
      'was', 'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'would',
      'you', 'your'
    ]);
    return Array.from(new Set(String(question || '').toLowerCase().match(/[a-z0-9]{2,}/g) || []))
      .filter(function (term) { return !stopWords.has(term); });
  }

  function wordSimilarity(first, second) {
    if (first === second) return 1;
    const rows = Array.from({ length: first.length + 1 }, function (_, index) { return [index]; });
    for (let column = 0; column <= second.length; column += 1) rows[0][column] = column;
    for (let row = 1; row <= first.length; row += 1) {
      for (let column = 1; column <= second.length; column += 1) {
        rows[row][column] = first[row - 1] === second[column - 1]
          ? rows[row - 1][column - 1]
          : Math.min(rows[row - 1][column], rows[row][column - 1], rows[row - 1][column - 1]) + 1;
      }
    }
    return 1 - rows[first.length][second.length] / Math.max(first.length, second.length);
  }

  function matchesSearchTerm(word, terms) {
    const candidate = String(word || '').toLowerCase();
    return terms.some(function (term) { return wordSimilarity(term, candidate) >= 0.8; });
  }

  function setHighlightedText(element, text, terms) {
    element.textContent = '';
    if (!terms.length) {
      element.textContent = text;
      return;
    }
    const source = String(text || '');
    const pattern = /\b[a-z0-9]+\b/gi;
    let cursor = 0;
    source.replace(pattern, function (match, offset) {
      element.appendChild(document.createTextNode(source.slice(cursor, offset)));
      if (matchesSearchTerm(match, terms)) {
        const bold = document.createElement('b');
        bold.textContent = match;
        element.appendChild(bold);
      } else {
        element.appendChild(document.createTextNode(match));
      }
      cursor = offset + match.length;
      return match;
    });
    element.appendChild(document.createTextNode(source.slice(cursor)));
  }

  function addResults(results, terms) {
    if (!Array.isArray(results) || !results.length) return;
    const list = document.createElement('div');
    list.className = 'mdh-assistant-results';
    results.forEach(function (result) {
      const link = document.createElement('a');
      link.href = result.url;
      link.innerHTML = '<strong></strong><span></span><small>View public page &rarr;</small>';
      setHighlightedText(link.querySelector('strong'), result.title, terms || []);
      setHighlightedText(link.querySelector('span'), result.snippet, terms || []);
      list.appendChild(link);
    });
    messages.appendChild(list);
    messages.scrollTop = messages.scrollHeight;
  }

  function offerAi(question) {
    const offer = document.createElement('div');
    offer.className = 'mdh-assistant-ai-offer';
    offer.innerHTML = '<span>Not satisfied?</span><button type="button">Ask AI</button>';
    offer.querySelector('button').addEventListener('click', function () {
      selectMode('ai');
      ask(question);
    });
    messages.appendChild(offer);
    messages.scrollTop = messages.scrollHeight;
  }

  function renderFaqs() {
    if (!faqs || !Array.isArray(config.faqs) || !config.faqs.length) return;
    config.faqs.forEach(function (faq) {
      if (!faq.question || !faq.answer) return;
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = faq.question;
      button.addEventListener('click', function () {
        add(faq.question, 'user');
        add(faq.answer, 'assistant');
      });
      faqs.appendChild(button);
    });
  }

  function selectMode(nextMode) {
    mode = nextMode === 'ai' ? 'ai' : 'search';
    searchIntro.hidden = mode !== 'search';
    aiIntro.hidden = mode !== 'ai';
    input.placeholder = mode === 'ai' ? 'Ask AI about this website' : 'Search this website';
    send.textContent = mode === 'ai' ? 'Ask' : 'Search';
    root.querySelectorAll('.mdh-assistant-results, .mdh-assistant-ai-offer').forEach(function (element) {
      element.hidden = mode === 'ai';
    });
  }

  async function ask(question) {
    const clean = String(question || '').trim();
    if (!clean || send.disabled) return;
    const requestId = (window.crypto && typeof window.crypto.randomUUID === 'function')
      ? window.crypto.randomUUID()
      : ('mdh-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2));
    add(clean, 'user');
    lastQuestion = clean;
    if (mode === 'ai' && config.turnstileSiteKey && !aiCaptchaVerified && !aiCaptchaToken) {
      if (aiCaptcha) aiCaptcha.hidden = false;
      add('Complete the security check above once, then click Ask again.', 'assistant');
      return;
    }
    send.disabled = true;
    input.value = '';
    const waiting = add(mode === 'ai' ? 'Preparing an AI answer...' : 'Searching the website...', 'assistant');
    const endpoint = mode === 'ai' ? MustdohrAssistant.aiEndpoint : MustdohrAssistant.endpoint;
    try {
      const aiTokenForRequest = mode === 'ai' ? aiCaptchaToken : '';
      const requestBody = {
        message: clean,
        lang: MustdohrAssistant.language || navigator.language.slice(0, 2) || 'en',
        request_id: requestId,
        _mdh_nonce: MustdohrAssistant.nonce || '',
      };
      if (aiTokenForRequest) requestBody.turnstile_token = aiTokenForRequest;
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-MDH-Nonce': MustdohrAssistant.nonce || '' },
        body: JSON.stringify(requestBody)
      });
      const data = await response.json();
      if (mode === 'ai' && response.ok) aiCaptchaVerified = true;
      if (mode === 'ai' && aiTokenForRequest) aiCaptchaToken = '';
      if (!response.ok && data && ['captcha_required', 'captcha_failed', 'captcha_unavailable', 'captcha_not_configured'].indexOf(data.code) !== -1) {
        aiCaptchaVerified = false;
        if (window.turnstile && aiCaptcha) {
          const widget = aiCaptcha.querySelector('.cf-turnstile');
          if (widget && widget.dataset && widget.dataset.widgetId) window.turnstile.reset(widget.dataset.widgetId);
        }
        if (aiCaptcha) aiCaptcha.hidden = false;
        waiting.textContent = data.message || 'Complete the security check and try again.';
        return;
      }
      const answer = data.answer || data.message || 'The assistant is temporarily unavailable.';
      waiting.textContent = answer;
      chatHistory.push({ question: clean, answer: answer, mode: mode, page_url: window.location.href, created_at: new Date().toISOString() });
      if (mode === 'ai' && data.show_contact) {
        showContact(data.trigger_reason || data.screening || 'contact');
      }
      if (response.status === 429 || data.code === 'question_limit_reached') {
        waiting.classList.add('warning');
        waiting.style.background = '#fff4ce';
        waiting.style.border = '1px solid #d9a400';
        waiting.style.color = '#5d4300';
        showContact('limit');
      }
      if (data.low_relevance) {
        waiting.classList.add('warning');
        waiting.style.background = '#fff4ce';
        waiting.style.border = '1px solid #d9a400';
        waiting.style.color = '#5d4300';
      }
      // AI mode should show only the conversation. Search-result keyword cards
      // belong to search mode and must not be appended to an AI answer.
      if (mode === 'search') addResults(data.results, searchTerms(clean));
      if (mode === 'search') {
        offerAi(clean);
      }
    } catch (_) {
      waiting.textContent = 'The assistant is temporarily unavailable. Please try again.';
      chatHistory.push({ question: clean, answer: waiting.textContent, mode: mode, page_url: window.location.href, created_at: new Date().toISOString() });
    } finally {
      send.disabled = false;
      input.focus();
    }
  }

  launch.addEventListener('click', function () { toggle(panel.hidden); });
  close.addEventListener('click', function () { toggle(false); });
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    ask(input.value);
  });
  contactToggle.addEventListener('click', function () {
    if (config.contactMode === 'link' && config.contactUrl) {
      window.location.assign(config.contactUrl);
      return;
    }
    contactForm.hidden = !contactForm.hidden;
    contactToggle.textContent = contactForm.hidden ? 'Contact us' : 'Hide contact form';
    if (!contactForm.hidden && contactStatus) contactStatus.hidden = true;
  });
  if (contactRemove) contactRemove.addEventListener('click', function () {
    contactForm.hidden = true;
    contactToggle.textContent = 'Contact us';
    if (contactStatus) contactStatus.hidden = true;
  });
  if (quickToggle && quickPanel) {
    quickToggle.addEventListener('click', function () {
      quickPanel.hidden = !quickPanel.hidden;
      quickToggle.textContent = quickPanel.hidden ? 'Quick access: find the right service' : 'Hide quick access';
    });
  }
  if (quickForm) quickForm.addEventListener('submit', prepareQuickEnquiry);
  contactForm.addEventListener('submit', async function (event) {
    event.preventDefault();
    const button = contactForm.querySelector('button');
    if (button.disabled) return;
    button.disabled = true;
    try {
      const data = Object.fromEntries(new FormData(contactForm).entries());
      data.trigger_reason = lastTrigger;
      const result = await submitContactWithFallback(data);
      contactForm.reset();
      contactForm.hidden = true;
      contactToggle.textContent = 'Contact us';
      const confirmation = result.message || 'Thank you. The Mustdohr team will be in touch.';
      setContactStatus(confirmation, 'success');
      add(confirmation, 'assistant');
    } catch (error) {
      const failure = error.message || 'Your enquiry could not be sent. Please try again.';
      setContactStatus(failure, 'error');
      add(failure, 'assistant');
    } finally {
      button.disabled = false;
    }
  });
  renderFaqs();
  selectMode('search');
})();
