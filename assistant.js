(function () {
  const root = document.getElementById('mdh-assistant');
  if (!root) return;

  const launch = root.querySelector('.mdh-assistant-launch');
  const panel = root.querySelector('.mdh-assistant-panel');
  const close = root.querySelector('.mdh-assistant-close');
  const messages = root.querySelector('.mdh-assistant-messages');
  const form = root.querySelector('form');
  const input = root.querySelector('input');
  const send = form.querySelector('button');
  const searchIntro = root.querySelector('[data-search-intro]');
  const aiIntro = root.querySelector('[data-ai-intro]');
  const contactToggle = root.querySelector('[data-contact-toggle]');
  const contactForm = root.querySelector('[data-contact-form]');
  const contactStatus = root.querySelector('[data-contact-status]');
  const faqs = root.querySelector('[data-faqs]');
  const cookieNotice = root.querySelector('[data-cookie-notice]');
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
    contactToggle.textContent = 'Hide contact form';
    const message = contactForm.querySelector('[name="message"]');
    if (message) message.focus();
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
    input.placeholder = mode === 'ai' ? 'Ask AI about Mustdohr' : 'Search Mustdohr';
    send.textContent = mode === 'ai' ? 'Ask' : 'Search';
  }

  async function ask(question) {
    const clean = String(question || '').trim();
    if (!clean || send.disabled) return;
    add(clean, 'user');
    lastQuestion = clean;
    send.disabled = true;
    input.value = '';
    const waiting = add(mode === 'ai' ? 'Preparing an AI answer...' : 'Searching the Mustdohr website...', 'assistant');
    const endpoint = mode === 'ai' ? MustdohrAssistant.aiEndpoint : MustdohrAssistant.endpoint;
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: clean, lang: MustdohrAssistant.language || navigator.language.slice(0, 2) || 'en', visitor_id: visitorId })
      });
      const data = await response.json();
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
      addResults(data.results, mode === 'search' ? searchTerms(clean) : []);
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
    contactToggle.textContent = contactForm.hidden ? 'Contact Mustdohr' : 'Hide contact form';
    if (!contactForm.hidden && contactStatus) contactStatus.hidden = true;
  });
  contactForm.addEventListener('submit', async function (event) {
    event.preventDefault();
    const button = contactForm.querySelector('button');
    if (button.disabled) return;
    button.disabled = true;
    try {
      const data = Object.fromEntries(new FormData(contactForm).entries());
      data.page_url = window.location.href;
      data.visitor_id = visitorId;
      data.trigger_reason = lastTrigger;
      data.chat_question = lastQuestion;
      data.chat_transcript = JSON.stringify(chatHistory.slice(-100));
      data.source_website = config.sourceWebsite || window.location.hostname || 'Mustdohr';
      const response = await fetch(MustdohrAssistant.contactEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || 'Your enquiry could not be sent.');
      contactForm.reset();
      contactForm.hidden = true;
      contactToggle.textContent = 'Contact Mustdohr';
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
