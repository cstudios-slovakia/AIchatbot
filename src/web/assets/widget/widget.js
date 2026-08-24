(function () {
  if (window.__csChatbotInit) return;
  window.__csChatbotInit = true;

  var STORAGE_TOKEN = 'csChatbotSession';
  var STORAGE_THEME = 'csChatbotTheme';
  var STORAGE_MINIMIZED = 'csChatbotMinimized';
  var STORAGE_FORM = 'csChatbotForm';

  // English fallbacks for all widget UI strings. The server sends localized
  // overrides (per the current site's language) in config.strings; T() reads
  // those when present and falls back to these otherwise.
  var STR_DEFAULTS = {
    previous: 'Previous',
    next: 'Next',
    slide: 'Slide',
    msgTooShort: 'Message is too short.',
    msgTooLong: 'Message is too long.',
    gibberish: 'Please send a real question or sentence.',
    clickMinimize: 'Click to minimize',
    conversationId: 'Conversation ID — share with support',
    endConversation: 'End conversation',
    toggleTheme: 'Toggle theme',
    more: 'More',
    newConversation: 'New conversation',
    minimize: 'Minimize',
    askQuestion: 'Ask a question…',
    send: 'Send',
    talkToHuman: 'Talk to a human',
    startNewConversation: 'Start a new conversation',
    openChat: 'Open chat',
    startNewConfirm: 'Start a new conversation?',
    endConfirm: 'End this conversation? You will not be able to continue it.',
    youEnded: 'You ended the conversation.',
    conversationEnded: 'Conversation ended',
    waitingAgent: 'Waiting for a human agent…',
    chattingWith: 'Chatting with',
    aHumanAgent: 'a human agent',
    replyTo: 'Reply to',
    theAgent: 'the agent',
    messageReachAgent: 'Message will reach the agent once connected…',
    hello: 'Hello!',
    disclaimer: 'AI assistant — answers can be inaccurate. Please verify important information.',
    agent: 'Agent',
    howWasChat: 'How was this chat?',
    notFinding: 'Not finding what you need?',
    messageRejected: 'Message rejected.',
    somethingWrong: 'Sorry, something went wrong:',
    unknown: 'unknown',
    networkError: 'Network error. Please try again.',
    couldNotRequestHuman: 'Could not request human right now.',
    askedForHuman: 'Asked for a human agent.',
    leaveDetails: 'Leave your details',
    dontWait: 'Don’t want to wait? Leave your details',
    leaveDetailsPrompt: 'Leave your email or phone and a real person will get back to you.',
    contactName: 'Name (optional)',
    contactEmail: 'Email',
    contactPhone: 'Phone',
    contactNote: 'Anything else? (optional)',
    contactSubmit: 'Send details',
    contactNeedOne: 'Please enter an email or phone number.',
    contactThanks: 'Thanks! We’ll be in touch soon.',
    contactError: 'Could not save your details. Please check and try again.',
    formSubmit: 'Submit',
    formRequired: 'This field is required.',
    formInvalidEmail: 'Please enter a valid email address.',
    formThanks: 'Thanks! Your form has been submitted.',
    formError: 'Could not submit the form. Please try again.',
    formSelectPrompt: 'Choose…'
  };
  function T(key) {
    var s = (window.csChatbot && window.csChatbot.strings) || {};
    return (s[key] != null && s[key] !== '') ? s[key] : STR_DEFAULTS[key];
  }

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'class') n.className = attrs[k];
      else if (k === 'html') n.innerHTML = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) {
      if (typeof c === 'string') n.appendChild(document.createTextNode(c));
      else if (c) n.appendChild(c);
    });
    return n;
  }

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      body: body
    }).then(function (r) { return r.json(); });
  }
  function getJson(url) {
    return fetch(url, { credentials:'same-origin', headers:{'Accept':'application/json'} }).then(function(r){ return r.json(); });
  }

  var _audioCtx = null;
  function playChime() {
    try {
      _audioCtx = _audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      var ctx = _audioCtx;
      var now = ctx.currentTime;
      [880, 1320].forEach(function (freq, i) {
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, now + i * 0.12);
        gain.gain.exponentialRampToValueAtTime(0.1, now + i * 0.12 + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.12 + 0.28);
        osc.connect(gain).connect(ctx.destination);
        osc.start(now + i * 0.12);
        osc.stop(now + i * 0.12 + 0.3);
      });
    } catch (e) {}
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
  // Minimal safe markdown renderer. Escapes all HTML, then transforms
  // a conservative subset: fenced/inline code, bold, italic, headings,
  // links (http/https/mailto only), bullet/ordered lists, paragraphs.
  function renderMarkdown(input) {
    if (input == null) return '';
    var src = String(input);
    var codeBlocks = [];
    src = src.replace(/```([A-Za-z0-9_-]*)\n?([\s\S]*?)```/g, function (_, lang, code) {
      codeBlocks.push({ lang: lang, code: code });
      return '\x00CB' + (codeBlocks.length - 1) + '\x00';
    });
    var inlineCodes = [];
    src = src.replace(/`([^`\n]+)`/g, function (_, code) {
      inlineCodes.push(code);
      return '\x00IC' + (inlineCodes.length - 1) + '\x00';
    });
    src = escapeHtml(src);
    src = src.replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/g, function (_, txt, url) {
      var safe = url.replace(/"/g, '&quot;');
      // On-site links navigate in the same tab (the widget reopens itself after
      // the load); external links open in a new tab.
      var attrs = isOnSiteUrl(url)
        ? 'rel="nofollow"'
        : 'target="_blank" rel="noopener nofollow"';
      return '<a href="' + safe + '" ' + attrs + ' title="' + safe + '" data-cs-href="' + safe + '">' + txt + '</a>';
    });
    // Autolink standalone bare URLs the model emitted without [text](url) syntax.
    // Compliance is flaky — sometimes it emits a proper link (card), sometimes a
    // raw URL (plain text). Linkifying bare URLs too makes the link-card path fire
    // either way (the card is built from OG metadata, not the anchor text). Stash
    // the anchors we just built so their attribute URLs aren't relinked.
    var anchors = [];
    src = src.replace(/<a [^>]*>[\s\S]*?<\/a>/g, function (m) {
      anchors.push(m);
      return '\x00AN' + (anchors.length - 1) + '\x00';
    });
    src = src.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, function (_, pre, url) {
      var trimmed = url.replace(/[.,;:!?]+$/, '');
      var trail = url.slice(trimmed.length);
      var safe = trimmed.replace(/"/g, '&quot;');
      var attrs = isOnSiteUrl(trimmed)
        ? 'rel="nofollow"'
        : 'target="_blank" rel="noopener nofollow"';
      return pre + '<a href="' + safe + '" ' + attrs + ' title="' + safe + '" data-cs-href="' + safe + '">' + trimmed + '</a>' + trail;
    });
    src = src.replace(/\x00AN(\d+)\x00/g, function (_, i) { return anchors[+i]; });
    src = src.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    src = src.replace(/(^|[^*\w])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
    src = src.replace(/(^|[^_\w])_([^_\n]+)_(?!_)/g, '$1<em>$2</em>');
    src = renderBlocks(src);
    src = src.replace(/\x00IC(\d+)\x00/g, function (_, i) {
      return '<code>' + escapeHtml(inlineCodes[+i]) + '</code>';
    });
    src = src.replace(/\x00CB(\d+)\x00/g, function (_, i) {
      var b = codeBlocks[+i];
      return '<pre><code' + (b.lang ? ' data-lang="' + escapeHtml(b.lang) + '"' : '') + '>' + escapeHtml(b.code.replace(/\n$/, '')) + '</code></pre>';
    });
    return src;
  }
  // A list bullet or number opening a line, with the indent that decides
  // nesting depth. Returns null for anything else.
  function listMarker(line) {
    var m = /^([ \t]*)([-*+]|\d+[.)])[ \t]+(.*)$/.exec(line);
    if (!m) return null;
    return {
      indent: m[1].replace(/\t/g, '    ').length,
      ordered: /\d/.test(m[2]),
      text: m[3],
    };
  }

  // One list, plus anything nested under it, starting at lines[start].
  // Indented lines without a bullet of their own belong to the item above them:
  // the assistant answers with "- **Shop name**" followed by an indented address
  // and phone, and treating those as separate blocks scattered the details
  // outside the list entirely.
  function parseList(lines, start) {
    var first = listMarker(lines[start]);
    var indent = first.indent;
    var ordered = first.ordered;
    var items = [];
    var i = start;
    while (i < lines.length) {
      var line = lines[i];
      if (!line.trim()) {
        var after = i + 1 < lines.length ? listMarker(lines[i + 1]) : null;
        if (after && after.indent >= indent) { i++; continue; }
        break;
      }
      var m = listMarker(line);
      if (m && m.indent < indent) break;
      if (m && m.indent === indent) {
        if (m.ordered !== ordered) break;
        items.push({ text: [m.text], children: [] });
        i++;
        continue;
      }
      if (!items.length) break;
      if (m) {
        var sub = parseList(lines, i);
        items[items.length - 1].children.push(sub.html);
        i = sub.next;
        continue;
      }
      items[items.length - 1].text.push(line.trim());
      i++;
    }
    var tag = ordered ? 'ol' : 'ul';
    var html = '<' + tag + '>' + items.map(function (it) {
      return '<li>' + it.text.join('<br>') + it.children.join('') + '</li>';
    }).join('') + '</' + tag + '>';
    return { html: html, next: i };
  }

  function tableCells(row) {
    return row.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (c) { return c.trim(); });
  }

  function isTableDivider(line) {
    return /\|/.test(line) && /^[\s|:-]*-{2,}[\s|:-]*$/.test(line);
  }

  function parseTable(lines, start) {
    var aligns = tableCells(lines[start + 1]).map(function (c) {
      if (/^:.*:$/.test(c)) return 'center';
      if (/:$/.test(c)) return 'right';
      return '';
    });
    var style = function (n) { return aligns[n] ? ' style="text-align:' + aligns[n] + '"' : ''; };
    var head = tableCells(lines[start]).map(function (c, n) {
      return '<th' + style(n) + '>' + c + '</th>';
    }).join('');
    var i = start + 2;
    var body = '';
    while (i < lines.length && lines[i].trim() && /\|/.test(lines[i])) {
      body += '<tr>' + tableCells(lines[i]).map(function (c, n) {
        return '<td' + style(n) + '>' + c + '</td>';
      }).join('') + '</tr>';
      i++;
    }
    return {
      // Wrapped so a wide table scrolls inside the bubble instead of stretching it.
      html: '<div class="cs-chatbot__table"><table><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>',
      next: i,
    };
  }

  // Group already-inlined text into block elements: headings, tables, lists,
  // fenced code and paragraphs.
  function renderBlocks(src) {
    var lines = src.split('\n');
    var isCodePlaceholder = function (line) { return /^\x00CB\d+\x00$/.test(line.trim()); };
    var startsTable = function (n) {
      return /\|/.test(lines[n]) && n + 1 < lines.length && isTableDivider(lines[n + 1]);
    };
    var out = [];
    var i = 0;
    while (i < lines.length) {
      if (!lines[i].trim()) { i++; continue; }
      if (isCodePlaceholder(lines[i])) { out.push(lines[i].trim()); i++; continue; }
      var heading = /^(#{1,6})[ \t]+(.+)$/.exec(lines[i]);
      if (heading) {
        out.push('<h' + heading[1].length + '>' + heading[2].trim() + '</h' + heading[1].length + '>');
        i++;
        continue;
      }
      if (startsTable(i)) {
        var table = parseTable(lines, i);
        out.push(table.html);
        i = table.next;
        continue;
      }
      if (listMarker(lines[i])) {
        var list = parseList(lines, i);
        out.push(list.html);
        i = list.next;
        continue;
      }
      var para = [];
      while (
        i < lines.length && lines[i].trim() &&
        !listMarker(lines[i]) &&
        !/^(#{1,6})[ \t]+/.test(lines[i]) &&
        !isCodePlaceholder(lines[i]) &&
        !startsTable(i)
      ) {
        para.push(lines[i].trim());
        i++;
      }
      if (para.length) out.push('<p>' + para.join('<br>') + '</p>');
    }
    return out.join('');
  }

  window.csChatbotRenderMarkdown = renderMarkdown;

  // Read a server-sent-event stream from a POST. EventSource cannot POST, and
  // the message body is what carries the question, so the frames are parsed by
  // hand. onEvent is called with (eventName, data) per frame.
  function postStream(url, data, onEvent) {
    return fetch(url, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: { 'Accept': 'text/event-stream' },
    }).then(function (response) {
      var type = response.headers.get('Content-Type') || '';
      if (!response.ok || type.indexOf('text/event-stream') === -1 || !response.body) {
        // Server declined to stream (disabled, filtered, banned) — hand the
        // body back so the caller can treat it as an ordinary reply.
        return response.json().then(function (json) { return { streamed: false, json: json }; });
      }
      var reader = response.body.getReader();
      var decoder = new TextDecoder();
      var buffer = '';
      function pump() {
        return reader.read().then(function (chunk) {
          if (chunk.done) return { streamed: true };
          buffer += decoder.decode(chunk.value, { stream: true });
          var frames = buffer.split('\n\n');
          buffer = frames.pop();
          frames.forEach(function (frame) {
            var name = 'message';
            var payload = '';
            frame.split('\n').forEach(function (line) {
              if (line.indexOf('event:') === 0) name = line.slice(6).trim();
              else if (line.indexOf('data:') === 0) payload += line.slice(5).trim();
            });
            if (!payload) return;
            try { onEvent(name, JSON.parse(payload)); } catch (e) {}
          });
          return pump();
        });
      }
      return pump();
    });
  }

  var ogCache = {};
  function fetchOg(url) {
    if (ogCache[url]) return ogCache[url];
    var endpoint = (window.csChatbot && window.csChatbot.urls && window.csChatbot.urls.og) || '/actions/_cs-chatbot/og/fetch';
    ogCache[url] = fetch(endpoint + (endpoint.indexOf('?') > -1 ? '&' : '?') + 'url=' + encodeURIComponent(url), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    }).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
    return ogCache[url];
  }
  // Find paragraphs that contain only a single link — replace with rich link card if OG metadata is found.
  // Inline links inside prose stay as-is.
  function buildLinkCarousel(cards, prefix) {
    // prefix: 'cs-chatbot' for widget, 'lc' for admin pane
    var wrap = document.createElement('div');
    wrap.className = prefix + '__link-carousel';
    var viewport = document.createElement('div');
    viewport.className = prefix + '__link-carousel-viewport';
    var track = document.createElement('div');
    track.className = prefix + '__link-carousel-track';
    cards.forEach(function (c) { c.classList.add(prefix + '__link-carousel-item'); track.appendChild(c); });
    viewport.appendChild(track);
    wrap.appendChild(viewport);

    var prev = document.createElement('button');
    prev.type = 'button'; prev.className = prefix + '__link-carousel-btn ' + prefix + '__link-carousel-btn--prev';
    prev.setAttribute('aria-label', T('previous')); prev.textContent = '‹';
    var next = document.createElement('button');
    next.type = 'button'; next.className = prefix + '__link-carousel-btn ' + prefix + '__link-carousel-btn--next';
    next.setAttribute('aria-label', T('next')); next.textContent = '›';
    wrap.appendChild(prev); wrap.appendChild(next);

    var dots = document.createElement('div');
    dots.className = prefix + '__link-carousel-dots';
    var dotEls = cards.map(function (_, i) {
      var d = document.createElement('button');
      d.type = 'button'; d.className = prefix + '__link-carousel-dot';
      d.setAttribute('aria-label', T('slide') + ' ' + (i + 1));
      dots.appendChild(d);
      return d;
    });
    wrap.appendChild(dots);

    var idx = 0;
    function update() {
      track.style.transform = 'translateX(' + (-idx * 100) + '%)';
      dotEls.forEach(function (d, i) { d.classList.toggle('is-active', i === idx); });
      prev.disabled = idx === 0;
      next.disabled = idx === cards.length - 1;
    }
    prev.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); if (idx > 0) { idx--; update(); } });
    next.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); if (idx < cards.length - 1) { idx++; update(); } });
    dotEls.forEach(function (d, i) { d.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); idx = i; update(); }); });
    update();
    return wrap;
  }
  function groupAdjacentLinkCards(container, cardClass, carouselClass) {
    if (!container) return;
    var prefix = carouselClass.replace('__link-carousel', '');
    var cards = Array.prototype.slice.call(container.children).filter(function (n) { return n.classList && n.classList.contains(cardClass); });
    var i = 0;
    while (i < cards.length) {
      var run = [cards[i]];
      while (i + 1 < cards.length && cards[i].nextElementSibling === cards[i + 1]) {
        run.push(cards[i + 1]);
        i++;
      }
      if (run.length >= 2 && run[0].parentNode) {
        var firstParent = run[0].parentNode;
        var anchorNode = run[run.length - 1].nextSibling; // capture insertion point BEFORE detaching
        var carousel = buildLinkCarousel(run, prefix); // this detaches the cards
        if (anchorNode) {
          firstParent.insertBefore(carousel, anchorNode);
        } else {
          firstParent.appendChild(carousel);
        }
      }
      i++;
    }
  }
  function isOnSiteUrl(url) {
    var hosts = (window.csChatbot && window.csChatbot.config && window.csChatbot.config.siteHosts) || [];
    if (!hosts.length) return false;
    try {
      var h = new URL(url).hostname.toLowerCase();
      return hosts.indexOf(h) !== -1;
    } catch (e) { return false; }
  }
  // Split a <p>'s child nodes into segments delimited by <br>. Each segment is an array of nodes.
  function paragraphSegments(p) {
    var segs = [[]];
    Array.prototype.forEach.call(p.childNodes, function (n) {
      if (n.nodeType === 1 && n.tagName === 'BR') {
        segs.push([]);
      } else {
        segs[segs.length - 1].push(n);
      }
    });
    return segs;
  }
  // Returns the on-site anchor node if a segment is just one (ignoring whitespace), else null.
  function segmentOnSiteAnchor(seg) {
    var meaningful = seg.filter(function (n) {
      return n.nodeType !== 3 || n.textContent.trim() !== '';
    });
    if (meaningful.length !== 1) return null;
    var node = meaningful[0];
    if (node.nodeType !== 1 || node.tagName !== 'A') return null;
    var href = node.getAttribute('data-cs-href') || node.getAttribute('href');
    if (!href || !/^https?:/i.test(href) || !isOnSiteUrl(href)) return null;
    return { node: node, href: href };
  }
  function enrichLinkCards(container) {
    if (!container) return;
    var ps = Array.prototype.slice.call(container.querySelectorAll('p'));
    var tasks = [];
    ps.forEach(function (p) {
      var segs = paragraphSegments(p);
      var anchorInfo = segs.map(segmentOnSiteAnchor);
      if (!anchorInfo.some(Boolean)) return;
      // Build the list of OG fetches we need; index keeps slot mapping.
      var fetches = anchorInfo.map(function (info) {
        return info ? fetchOg(info.href).then(function (r) { return r && r.ok ? r : null; }) : Promise.resolve(null);
      });
      tasks.push(Promise.all(fetches).then(function (metas) {
        return { p: p, segs: segs, anchorInfo: anchorInfo, metas: metas };
      }));
    });
    if (!tasks.length) return;
    Promise.all(tasks).then(function (results) {
      results.forEach(function (r) {
        if (!r.p.parentNode) return;
        // If literally every segment is a successful on-site card, replace the whole <p>.
        var allCards = r.anchorInfo.every(Boolean) && r.metas.every(Boolean);
        if (allCards) {
          var nodes = r.metas.map(buildLinkCard);
          if (nodes.length === 1) {
            r.p.parentNode.replaceChild(nodes[0], r.p);
          } else {
            r.p.parentNode.replaceChild(buildLinkCarousel(nodes, 'cs-chatbot'), r.p);
          }
          return;
        }
        // Mixed: emit a sequence of text-<p>s and cards, replacing the original <p>.
        var fragment = document.createDocumentFragment();
        var textBuf = [];
        function flushText() {
          var hasContent = textBuf.some(function (n) { return n.nodeType !== 3 || n.textContent.trim() !== ''; });
          if (hasContent) {
            var newP = document.createElement('p');
            textBuf.forEach(function (n) { newP.appendChild(n.cloneNode(true)); });
            // restore <br> between accumulated text segments by joining w/ a single BR — simple: keep as plain run; <p>'s own line-wrap handles breaks
            fragment.appendChild(newP);
          }
          textBuf = [];
        }
        r.segs.forEach(function (seg, i) {
          if (r.anchorInfo[i] && r.metas[i]) {
            flushText();
            fragment.appendChild(buildLinkCard(r.metas[i]));
          } else {
            if (textBuf.length) textBuf.push(document.createElement('br'));
            textBuf = textBuf.concat(seg);
          }
        });
        flushText();
        r.p.parentNode.replaceChild(fragment, r.p);
      });
      // group cards from adjacent separate top-level positions into a carousel
      groupAdjacentLinkCards(container, 'cs-chatbot__link-card', 'cs-chatbot__link-carousel');
    });
  }
  function buildLinkCard(meta) {
    var a = document.createElement('a');
    a.className = 'cs-chatbot__link-card';
    a.href = meta.url;
    // On-site cards open in the same tab; external ones in a new tab.
    if (isOnSiteUrl(meta.url)) {
      a.rel = 'nofollow';
    } else {
      a.target = '_blank';
      a.rel = 'noopener nofollow';
    }
    a.setAttribute('title', meta.url);
    if (meta.image) {
      var img = document.createElement('div');
      img.className = 'cs-chatbot__link-card-img';
      img.style.backgroundImage = 'url("' + meta.image.replace(/"/g, '%22') + '")';
      a.appendChild(img);
    }
    var body = document.createElement('div');
    body.className = 'cs-chatbot__link-card-body';
    if (meta.siteName) {
      var s = document.createElement('div');
      s.className = 'cs-chatbot__link-card-site';
      s.textContent = meta.siteName;
      body.appendChild(s);
    }
    if (meta.title) {
      var t = document.createElement('div');
      t.className = 'cs-chatbot__link-card-title';
      t.textContent = meta.title;
      body.appendChild(t);
    }
    if (meta.description) {
      var d = document.createElement('div');
      d.className = 'cs-chatbot__link-card-desc';
      d.textContent = meta.description;
      body.appendChild(d);
    }
    a.appendChild(body);
    return a;
  }
  window.csChatbotEnrichLinkCards = enrichLinkCards;

  function formatTime(s) {
    if (!s) return '';
    // server now sends UTC strings; parse as UTC by appending Z
    var d = new Date(s.replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return '';
    var hh = String(d.getHours()).padStart(2, '0');
    var mm = String(d.getMinutes()).padStart(2, '0');
    return hh + ':' + mm;
  }

  function looksLikeGibberish(text) {
    var t = (text || '').trim();
    if (t.length === 0) return true;
    if (t.length < 4) return false;
    // Latin letters (incl. extended). If string contains non-Latin chars (CJK, Arabic, Cyrillic), skip heuristics.
    if (/[^ -ɏ\s\d.,!?;:'"()\-\[\]\/@#&%*+=]/.test(t)) return false;
    var letters = t.replace(/[^A-Za-zÀ-ɏ]/g, '');
    if (letters.length === 0) return true;
    var lower = letters.toLowerCase();
    if (letters.length >= 6) {
      var vowels = (lower.match(/[aeiouy]/g) || []).length;
      var ratio = vowels / letters.length;
      if (ratio < 0.15 || ratio > 0.85) return true;
    }
    if (/[bcdfghjklmnpqrstvwxz]{7,}/i.test(lower)) return true;
    if (/(.)\1{5,}/.test(lower)) return true;
    if (letters.length >= 12) {
      var uniq = new Set(lower.split('')).size;
      if (uniq <= 3 || (uniq / letters.length) < 0.2) return true;
    }
    if (t.length >= 40 && !/\s/.test(t)) return true;
    return false;
  }

  function init(config) {
    if (!config.enabled) return;

    var filterCfg = (config.filter && config.filter.enabled !== false) ? (config.filter || {}) : null;
    function localFilter(text) {
      if (!filterCfg) return { ok: true };
      var t = (text || '').trim();
      var min = filterCfg.minLength || 2;
      var max = filterCfg.maxLength || 2000;
      if (t.length < min) return { ok: false, message: T('msgTooShort') };
      if (t.length > max) return { ok: false, message: T('msgTooLong') };
      if (looksLikeGibberish(t)) return { ok: false, message: T('gibberish') };
      return { ok: true };
    }

    var root = el('div', { class: 'cs-chatbot' });
    var agentMode = config.operationMode === 'agent';
    root.dataset.mode = agentMode ? 'agent' : 'chat';
    root.dataset.theme = localStorage.getItem(STORAGE_THEME) || config.defaultTheme || 'light';
    var POSITIONS = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];
    var position = POSITIONS.indexOf(config.widgetPosition) !== -1 ? config.widgetPosition : 'bottom-right';
    root.dataset.position = position;
    var dockLeft = position.indexOf('-left') !== -1;
    if (agentMode) {
      var agentWidth = Math.max(280, Math.min(900, parseInt(config.agentPanelWidth, 10) || 420));
      document.documentElement.style.setProperty('--cb-agent-width', agentWidth + 'px');
      document.documentElement.classList.add('cs-chatbot-agent');
      if (dockLeft) document.documentElement.classList.add('cs-chatbot-agent-left');
    }
    root.style.setProperty('--cb-primary', config.primaryColor || '#2563eb');
    root.style.setProperty('--cb-logo-bg', config.logoBgColor || '#1f2937');

    function contrastFg(hex) {
      var h = (hex || '').replace('#', '');
      if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
      if (h.length < 6) return '#ffffff';
      var r = parseInt(h.slice(0,2), 16), g = parseInt(h.slice(2,4), 16), b = parseInt(h.slice(4,6), 16);
      // perceived luminance
      var L = (0.299*r + 0.587*g + 0.114*b) / 255;
      return L > 0.6 ? '#111827' : '#ffffff';
    }
    if (config.bubbleBotColor) {
      root.style.setProperty('--cb-bubble-bot', config.bubbleBotColor);
      root.style.setProperty('--cb-bubble-bot-fg', contrastFg(config.bubbleBotColor));
    }
    if (config.bubbleAdminColor) {
      root.style.setProperty('--cb-bubble-admin', config.bubbleAdminColor);
      root.style.setProperty('--cb-bubble-admin-fg', contrastFg(config.bubbleAdminColor));
    }
    if (config.bubbleUserColor) {
      root.style.setProperty('--cb-bubble-user', config.bubbleUserColor);
      root.style.setProperty('--cb-bubble-user-fg', contrastFg(config.bubbleUserColor));
    } else {
      // user bubble defaults to primary — auto contrast for primary
      root.style.setProperty('--cb-bubble-user-fg', contrastFg(config.primaryColor || '#2563eb'));
    }

    var logoInner = config.logoUrl
      ? el('img', { src: config.logoUrl, alt: '' })
      : document.createTextNode((config.logoText || 'CB').slice(0, 3));

    var logo = el('div', { class: 'cs-chatbot__logo' }, [logoInner]);
    var title = el('div', { class: 'cs-chatbot__title', role: 'button', tabindex: '0', title: T('clickMinimize') }, [config.companyName || 'Chatbot']);
    var shortIdBadge = el('div', { class: 'cs-chatbot__short-id', title: T('conversationId') });
    shortIdBadge.style.display = 'none';

    var endMenuItem = el('button', { class: 'cs-chatbot__menu-item', type: 'button', html: '<span class="cs-chatbot__menu-icon">⏻</span>' + T('endConversation') });
    endMenuItem.dataset.role = 'end';
    var themeMenuItem = el('button', { class: 'cs-chatbot__menu-item', type: 'button', html: '<span class="cs-chatbot__menu-icon cs-chatbot__theme-icon">☾</span>' + T('toggleTheme') });
    themeMenuItem.dataset.role = 'theme';
    var menu = el('div', { class: 'cs-chatbot__menu' }, [endMenuItem, themeMenuItem]);
    var menuBtn = el('button', { class: 'cs-chatbot__icon-btn', title: T('more'), type: 'button', html: '⋯', 'aria-haspopup': 'true' });
    var menuWrap = el('div', { class: 'cs-chatbot__menu-wrap' }, [menuBtn, menu]);
    // Compatibility shims so existing code referring to endBtn/themeBtn keeps working.
    var endBtn = endMenuItem;
    var themeBtn = themeMenuItem;

    var refreshBtn = el('button', { class: 'cs-chatbot__icon-btn', title: T('newConversation'), type: 'button', html: '↻' });
    var closeBtn = el('button', { class: 'cs-chatbot__icon-btn', title: T('minimize'), type: 'button', html: '×' });

    var header = el('div', { class: 'cs-chatbot__header' }, [logo, title, shortIdBadge, menuWrap, refreshBtn, closeBtn]);

    menuBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      menu.classList.toggle('is-open');
    });
    document.addEventListener('click', function (e) {
      if (!menuWrap.contains(e.target)) menu.classList.remove('is-open');
    });
    menu.addEventListener('click', function () { menu.classList.remove('is-open'); });
    var banner = el('div', { class: 'cs-chatbot__banner' });
    banner.style.display = 'none';
    var messages = el('div', { class: 'cs-chatbot__messages' });
    var suggestionsBar = el('div', { class: 'cs-chatbot__suggestions' });
    var ratingBar = el('div', { class: 'cs-chatbot__chat-rate' });
    ratingBar.style.display = 'none';

    var input = el('textarea', { class: 'cs-chatbot__input', rows: '1', placeholder: T('askQuestion') });
    var send = el('button', { class: 'cs-chatbot__send', type: 'submit' }, [T('send')]);
    var form = el('form', { class: 'cs-chatbot__form' }, [input, send]);

    var humanBtn = el('button', { class: 'cs-chatbot__human', type: 'button' }, [T('talkToHuman')]);
    var newChatBtn = el('button', { class: 'cs-chatbot__new-chat', type: 'button' }, [T('startNewConversation')]);
    newChatBtn.style.display = 'none';

    // Standing note under the input: the answers are generated and can be wrong.
    // Sits below the composer so it stays visible without pushing the header
    // banner (handoff/ended status) around.
    var disclaimer = el('div', { class: 'cs-chatbot__disclaimer' }, [config.disclaimerText || T('disclaimer')]);
    if (!config.disclaimerEnabled) disclaimer.style.display = 'none';

    var panel = el('div', { class: 'cs-chatbot__panel' }, [header, banner, messages, suggestionsBar, humanBtn, ratingBar, newChatBtn, form, disclaimer]);
    var launcher = el('button', { class: 'cs-chatbot__launcher', type: 'button', 'aria-label': T('openChat'), html: '💬' });
    var launcherDot = el('span', { class: 'cs-chatbot__launcher-dot' });
    launcherDot.style.display = 'none';
    launcher.appendChild(launcherDot);
    root.appendChild(panel);
    root.appendChild(launcher);
    document.body.appendChild(root);

    var state = {
      handoffStatus: 'none',
      adminName: null,
      lastMessageId: 0,
      pollTimer: null,
      lastActivity: Date.now(),
      lastSender: null,
      pendingTimeEl: null, // latest msg's time element
      chatEnded: false,
      chatRating: null,
      shortId: null,
      unread: false,
      botPending: false,
      contactShown: false,
      contactCaptured: false
    };

    function isCont(sender) { return state.lastSender === sender; }

    function scrollMessagesToBottom() { messages.scrollTop = messages.scrollHeight; }
    function open() {
      root.classList.add('is-open');
      if (agentMode) document.documentElement.classList.add('cs-chatbot-agent-open');
      input.focus();
      scrollMessagesToBottom();
      state.unread = false;
      localStorage.removeItem(STORAGE_MINIMIZED);
      refreshLauncherDot();
    }
    function close() {
      root.classList.remove('is-open');
      if (agentMode) document.documentElement.classList.remove('cs-chatbot-agent-open');
      localStorage.setItem(STORAGE_MINIMIZED, '1');
      refreshLauncherDot();
    }
    function toggle() { root.classList.contains('is-open') ? close() : open(); }

    launcher.addEventListener('click', toggle);
    closeBtn.addEventListener('click', close);
    title.addEventListener('click', close);
    title.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); close(); } });

    function setThemeIcon() {
      var ic = themeBtn.querySelector('.cs-chatbot__theme-icon');
      if (ic) ic.textContent = root.dataset.theme === 'dark' ? '☀' : '☾';
    }
    themeBtn.addEventListener('click', function () {
      var t = root.dataset.theme === 'dark' ? 'light' : 'dark';
      root.dataset.theme = t;
      localStorage.setItem(STORAGE_THEME, t);
      setThemeIcon();
    });
    setThemeIcon();

    function startNewConversation(confirmFirst) {
      if (confirmFirst && !confirm(T('startNewConfirm'))) return;
      // Abandon any in-progress handoff server-side so it stops showing in admin's waiting/active lists.
      var oldToken = localStorage.getItem(STORAGE_TOKEN);
      if (oldToken && !state.chatEnded && (state.handoffStatus === 'requested' || state.handoffStatus === 'active')) {
        var fd = new FormData();
        fd.append('sessionToken', oldToken);
        try { post(urls().end, fd); } catch (e) {}
      }
      localStorage.removeItem(STORAGE_TOKEN);
      localStorage.removeItem(STORAGE_MINIMIZED);
      localStorage.removeItem(STORAGE_FORM);
      stopPolling();
      state.handoffStatus = 'none';
      state.lastMessageId = 0;
      state.adminName = null;
      state.lastSender = null;
      state.pendingTimeEl = null;
      state.chatEnded = false;
      state.chatRating = null;
      state.shortId = null;
      state.unread = false;
      state.contactShown = false;
      state.contactCaptured = false;
      messages.innerHTML = '';
      ratingBar.style.display = 'none';
      shortIdBadge.style.display = 'none';
      updateBanner();
      refreshLauncherDot();
      renderGreeting();
    }
    refreshBtn.addEventListener('click', function () { startNewConversation(true); });
    newChatBtn.addEventListener('click', function () { startNewConversation(false); });

    endBtn.addEventListener('click', function () {
      if (!confirm(T('endConfirm'))) return;
      var token = localStorage.getItem(STORAGE_TOKEN);
      if (!token) return;
      var data = new FormData();
      data.append('sessionToken', token);
      post(urls().end, data).then(function (r) {
        if (!r.success) return;
        state.chatEnded = true;
        state.handoffStatus = state.handoffStatus === 'active' || state.handoffStatus === 'requested' ? 'ended' : state.handoffStatus;
        addSystem(T('youEnded'), new Date().toISOString());
        updateBanner();
        renderChatRating();
        scrollMessagesToBottom();
      });
    });

    humanBtn.addEventListener('click', requestHuman);

    function updateBanner() {
      banner.innerHTML = '';
      if (state.chatEnded) {
        banner.style.display = '';
        banner.className = 'cs-chatbot__banner cs-chatbot__banner--ended';
        banner.appendChild(document.createTextNode(T('conversationEnded')));
      } else if (state.handoffStatus === 'requested') {
        banner.style.display = '';
        banner.className = 'cs-chatbot__banner cs-chatbot__banner--waiting';
        banner.appendChild(document.createTextNode(T('waitingAgent')));
        var canDecline = (config && config.contactCaptureEnabled !== false) && !state.contactShown && !state.contactCaptured;
        if (canDecline) {
          var declineBtn = el('button', { type: 'button', class: 'cs-chatbot__banner-action' }, [T('dontWait')]);
          declineBtn.addEventListener('click', function () { showContactForm('handoff_timeout'); });
          banner.appendChild(declineBtn);
        }
      } else if (state.handoffStatus === 'active') {
        banner.style.display = '';
        banner.className = 'cs-chatbot__banner cs-chatbot__banner--active';
        banner.appendChild(document.createTextNode(T('chattingWith') + ' ' + (state.adminName || T('aHumanAgent'))));
      } else {
        banner.style.display = 'none';
      }

      // End button visible when we have a session and not yet ended
      var hasSession = !!localStorage.getItem(STORAGE_TOKEN);
      endBtn.style.display = (hasSession && !state.chatEnded) ? '' : 'none';

      // Hide reply form when ended
      form.style.display = state.chatEnded ? 'none' : 'flex';
      var humanEnabled = !config || config.humanHandoffEnabled !== false;
      var humanAllowed = humanEnabled && (config && config.humanHandoffMode) !== 'ai';
      humanBtn.style.display = (humanAllowed && !state.chatEnded && (state.handoffStatus === 'none' || state.handoffStatus === 'ended')) ? '' : 'none';
      newChatBtn.style.display = state.chatEnded ? '' : 'none';

      // Hide suggestion buttons once a human is involved (requested or active) or chat ended.
      if (state.handoffStatus === 'active' || state.handoffStatus === 'requested' || state.chatEnded) {
        suggestionsBar.style.display = 'none';
        suggestionsBar.innerHTML = '';
      }
      input.placeholder = state.handoffStatus === 'active'
        ? T('replyTo') + ' ' + (state.adminName || T('theAgent')) + '…'
        : (state.handoffStatus === 'requested' ? T('messageReachAgent') : T('askQuestion'));

      // Short ID badge
      if (state.shortId) {
        shortIdBadge.textContent = state.shortId;
        shortIdBadge.style.display = '';
      } else {
        shortIdBadge.style.display = 'none';
      }
    }

    function refreshLauncherDot() {
      var hasSession = !!localStorage.getItem(STORAGE_TOKEN);
      var panelClosed = !root.classList.contains('is-open');
      var handoffOpen = state.handoffStatus === 'active' || state.handoffStatus === 'requested';
      if (!hasSession || !panelClosed) {
        launcherDot.style.display = 'none';
        launcherDot.className = 'cs-chatbot__launcher-dot';
        return;
      }
      launcherDot.style.display = '';
      if (state.unread) {
        launcherDot.className = 'cs-chatbot__launcher-dot is-unread';
      } else if (handoffOpen) {
        launcherDot.className = 'cs-chatbot__launcher-dot is-active';
      } else {
        launcherDot.className = 'cs-chatbot__launcher-dot is-idle';
      }
    }

    function renderGreeting() {
      addBot(config.initialMessage || T('hello'), new Date().toISOString());
      suggestionsBar.innerHTML = '';
      suggestionsBar.style.display = '';
      if (config.suggestionsEnabled && (config.suggestions || []).length) {
        (config.suggestions || []).forEach(function (s) {
          var b = el('button', { class: 'cs-chatbot__suggestion', type: 'button' }, [s]);
          b.addEventListener('click', function () {
            // disable all suggestions so user can't spam
            suggestionsBar.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
            // hide the bar entirely once one is used
            suggestionsBar.style.display = 'none';
            var data = new FormData();
            data.append('suggestion', s);
            post(urls().suggestionClick, data);
            ask(s);
          });
          suggestionsBar.appendChild(b);
        });
      }
    }

    function resolveTime(timeOrDate, fullDate) {
      // accept either prebuilt HH:MM or a date string
      if (timeOrDate && /^\d{1,2}:\d{2}$/.test(timeOrDate)) {
        return { time: timeOrDate, full: fullDate || timeOrDate };
      }
      return { time: formatTime(timeOrDate), full: fullDate || timeOrDate };
    }
    function stampTime(bubble, timeOrDate, fullDate) {
      var r = resolveTime(timeOrDate, fullDate);
      if (!r.time || !bubble) return;
      bubble.dataset.time = r.time;
      if (r.full) bubble.setAttribute('title', r.full);
      // remove previous visible time, append new one at end (only latest msg keeps visible time)
      if (state.pendingTimeEl) {
        state.pendingTimeEl.remove();
        state.pendingTimeEl = null;
      }
      var timeEl = el('div', { class: 'cs-chatbot__time' }, [r.time]);
      timeEl.dataset.sender = state.lastSender;
      messages.appendChild(timeEl);
      state.pendingTimeEl = timeEl;
    }

    function addBot(text, timeOrDate, opts) {
      var fullDate = (opts && opts.fullDate) || timeOrDate;
      opts = opts || {};
      var cont = isCont('bot');
      var cls = 'cs-chatbot__msg cs-chatbot__msg--bot cs-chatbot__msg--md' + (cont ? ' cs-chatbot__msg--cont' : '');
      var bubble = el('div', { class: cls, html: renderMarkdown(text) });
      messages.appendChild(bubble);
      enrichLinkCards(bubble);
      state.lastSender = 'bot';
      if (opts.messageId && config.ratingsEnabled) {
        var rate = el('div', { class: 'cs-chatbot__rate' });
        ['1', '-1'].forEach(function (v) {
          var b = el('button', { type: 'button', 'data-value': v }, [v === '1' ? '👍' : '👎']);
          if (parseInt(opts.initialRating || 0, 10) === parseInt(v, 10)) {
            b.classList.add('is-active');
          }
          b.addEventListener('click', function () {
            var current = b.classList.contains('is-active') ? 0 : parseInt(v, 10);
            rate.querySelectorAll('button').forEach(function (x) { x.classList.remove('is-active'); });
            if (current !== 0) b.classList.add('is-active');
            var data = new FormData();
            data.append('messageId', opts.messageId);
            data.append('rating', String(current));
            post(urls().rate, data);
          });
          rate.appendChild(b);
        });
        var meta = el('div', { class: 'cs-chatbot__msg-meta' }, [rate]);
        messages.appendChild(meta);
      }
      var humanOk = (!config || config.humanHandoffEnabled !== false);
      var contactOk = (config && config.contactCaptureEnabled !== false);
      if (opts.offerHuman && (humanOk || contactOk)) {
        var inline = el('div', { class: 'cs-chatbot__offer' });
        inline.appendChild(document.createTextNode(T('notFinding') + ' '));
        if (humanOk) {
          var btn = el('button', { type: 'button', class: 'cs-chatbot__offer-btn' }, [T('talkToHuman')]);
          btn.addEventListener('click', requestHuman);
          inline.appendChild(btn);
        }
        if (contactOk) {
          var cbtn = el('button', { type: 'button', class: 'cs-chatbot__offer-btn cs-chatbot__offer-btn--alt' }, [T('leaveDetails')]);
          cbtn.addEventListener('click', function () { showContactForm('ai_unanswered'); });
          inline.appendChild(cbtn);
        }
        messages.appendChild(inline);
      }
      stampTime(bubble, timeOrDate, fullDate);
      scrollMessagesToBottom();
    }

    // A bare bot bubble that text is appended into while the model writes.
    // It carries no timestamp, rating or handoff offer: those belong to the
    // finished message, which replaces this one when the stream ends.
    function addStreamingBot() {
      var cls = 'cs-chatbot__msg cs-chatbot__msg--bot cs-chatbot__msg--md'
        + (isCont('bot') ? ' cs-chatbot__msg--cont' : '');
      var bubble = el('div', { class: cls });
      messages.appendChild(bubble);
      scrollMessagesToBottom();
      return bubble;
    }

    function addUser(text, timeOrDate, fullDate) {
      var cls = 'cs-chatbot__msg cs-chatbot__msg--user cs-chatbot__msg--md' + (isCont('user') ? ' cs-chatbot__msg--cont' : '');
      var bubble = el('div', { class: cls, html: renderMarkdown(text) });
      messages.appendChild(bubble);
      state.lastSender = 'user';
      stampTime(bubble, timeOrDate, fullDate);
      scrollMessagesToBottom();
    }

    function addAdmin(text, timeOrDate, fullDate) {
      var cont = isCont('admin');
      if (!cont) {
        var name = state.adminName || T('agent');
        var meta = el('div', { class: 'cs-chatbot__msg-from' }, [name]);
        messages.appendChild(meta);
      }
      var cls = 'cs-chatbot__msg cs-chatbot__msg--admin cs-chatbot__msg--md' + (cont ? ' cs-chatbot__msg--cont' : '');
      var bubble = el('div', { class: cls, html: renderMarkdown(text) });
      messages.appendChild(bubble);
      enrichLinkCards(bubble);
      state.lastSender = 'admin';
      stampTime(bubble, timeOrDate, fullDate);
      scrollMessagesToBottom();
    }

    function addSystem(text, timeOrDate, fullDate) {
      var s = el('div', { class: 'cs-chatbot__system' }, [text]);
      messages.appendChild(s);
      state.lastSender = 'system';
      stampTime(s, timeOrDate, fullDate);
      scrollMessagesToBottom();
    }

    function addTyping() {
      var t = el('div', { class: 'cs-chatbot__typing' }, [el('span'), el('span'), el('span')]);
      messages.appendChild(t);
      scrollMessagesToBottom();
      return t;
    }

    function renderChatRating() {
      ratingBar.innerHTML = '';
      ratingBar.style.display = '';
      var label = el('span', { class: 'cs-chatbot__chat-rate-label' }, [T('howWasChat')]);
      ratingBar.appendChild(label);
      ['1', '-1'].forEach(function (v) {
        var b = el('button', { type: 'button', class: 'cs-chatbot__chat-rate-btn', 'data-value': v }, [v === '1' ? '👍' : '👎']);
        if (parseInt(state.chatRating || 0, 10) === parseInt(v, 10)) {
          b.classList.add('is-active');
        }
        b.addEventListener('click', function () {
          var current = b.classList.contains('is-active') ? 0 : parseInt(v, 10);
          ratingBar.querySelectorAll('button').forEach(function (x) { x.classList.remove('is-active'); });
          if (current !== 0) b.classList.add('is-active');
          state.chatRating = current;
          var token = localStorage.getItem(STORAGE_TOKEN);
          if (!token) return;
          var data = new FormData();
          data.append('sessionToken', token);
          data.append('rating', String(current));
          post(urls().rateChat, data);
        });
        ratingBar.appendChild(b);
      });
    }

    function ask(question) {
      if (state.chatEnded) return;
      // Throttle: when chatting with the bot, block sending another question until the previous bot reply lands.
      // No throttle for admin/handoff chats — humans can be slow, user may want to add context.
      var isBotChat = (state.handoffStatus !== 'active' && state.handoffStatus !== 'requested');
      if (isBotChat && state.botPending) {
        return;
      }
      // Frontend filter wall — server still re-checks
      var local = localFilter(question);
      if (!local.ok) {
        addUser(question, new Date().toISOString());
        addSystem(local.message, new Date().toISOString());
        return;
      }
      addUser(question, new Date().toISOString());
      state.lastActivity = Date.now();
      var showTyping = isBotChat;
      var typing = showTyping ? addTyping() : null;
      if (isBotChat) {
        state.botPending = true;
        send.disabled = true;
        input.disabled = true;
      }
      var data = new FormData();
      data.append('message', question);
      data.append('pageUrl', location.href);
      var token = localStorage.getItem(STORAGE_TOKEN);
      if (token) data.append('sessionToken', token);
      function clearPending() {
        state.botPending = false;
        send.disabled = false;
        if (!state.chatEnded) {
          input.disabled = false;
          input.focus();
        }
      }
      // While streaming, the reply lands in a bubble that grows; the final
      // event replaces it with the processed text (hallucinated links removed,
      // plugin transforms applied), so what the visitor keeps is never the raw
      // model output.
      var streamBubble = null;
      var streamText = '';

      function onStreamEvent(name, payload) {
        if (name === 'delta' && payload && payload.text) {
          if (typing) typing.remove();
          if (!streamBubble) streamBubble = addStreamingBot();
          streamText += payload.text;
          streamBubble.innerHTML = renderMarkdown(streamText);
          scrollMessagesToBottom();
          return;
        }
        if (name === 'done') {
          if (streamBubble) { streamBubble.remove(); streamBubble = null; }
          handleReply(payload || {});
        }
      }

      function handleReply(r) {
        if (typing) typing.remove();
        clearPending();
        if (r && r.filtered) {
          addSystem(r.error || T('messageRejected'), new Date().toISOString());
          return;
        }
        if (r && r.banned) { root.remove(); return; }
        if (!r.success) {
          addBot(T('somethingWrong') + ' ' + (r.error || T('unknown')), new Date().toISOString());
          return;
        }
        if (r.sessionToken) localStorage.setItem(STORAGE_TOKEN, r.sessionToken);
        if (r.shortId) { state.shortId = r.shortId; updateBanner(); }
        if (r.handoff) {
          startPolling();
          updateBanner();
          refreshLauncherDot();
          return;
        }
        if (r.messageId) state.lastMessageId = Math.max(state.lastMessageId, parseInt(r.messageId, 10));
        addBot(r.reply, new Date().toISOString(), { messageId: r.messageId, offerHuman: r.offerHuman });
        if (r.form && r.form.fields && r.form.fields.length) {
          renderFormCard(r.form);
        }
      }

      function onFailure() {
        if (streamBubble) { streamBubble.remove(); streamBubble = null; }
        if (typing) typing.remove();
        clearPending();
        addBot(T('networkError'), new Date().toISOString());
      }

      var canStream = config.streamingEnabled !== false
        && typeof TextDecoder !== 'undefined'
        && typeof ReadableStream !== 'undefined';

      if (canStream && isBotChat) {
        postStream(urls().stream, data, onStreamEvent)
          .then(function (outcome) {
            // The server answered with plain JSON instead of a stream — a
            // rejected message, a ban, or streaming switched off.
            if (outcome && outcome.streamed === false) handleReply(outcome.json || {});
          })
          .catch(onFailure);
        return;
      }

      post(urls().send, data).then(handleReply).catch(onFailure);
    }

    function requestHuman() {
      if (state.chatEnded) return;
      if (state.handoffStatus === 'requested' || state.handoffStatus === 'active') return;
      var data = new FormData();
      data.append('pageUrl', location.href);
      var token = localStorage.getItem(STORAGE_TOKEN);
      if (token) data.append('sessionToken', token);
      humanBtn.disabled = true;
      post(urls().requestHuman, data).then(function (r) {
        humanBtn.disabled = false;
        if (!r.success) {
          addBot(T('couldNotRequestHuman'), new Date().toISOString());
          return;
        }
        if (r.sessionToken) localStorage.setItem(STORAGE_TOKEN, r.sessionToken);
        if (r.shortId) state.shortId = r.shortId;
        state.handoffStatus = r.handoffStatus || 'requested';
        addSystem(T('askedForHuman'), new Date().toISOString());
        updateBanner();
        refreshLauncherDot();
        startPolling();
      });
    }

    function showContactForm(source) {
      if (state.contactCaptured || state.contactShown) return;
      if (!(config && config.contactCaptureEnabled !== false)) return;
      state.contactShown = true;
      updateBanner();

      var card = el('div', { class: 'cs-chatbot__contact' });
      card.appendChild(el('div', { class: 'cs-chatbot__contact-prompt' }, [T('leaveDetailsPrompt')]));
      var nameI = el('input', { class: 'cs-chatbot__contact-input', type: 'text', placeholder: T('contactName'), autocomplete: 'name' });
      var emailI = el('input', { class: 'cs-chatbot__contact-input', type: 'email', placeholder: T('contactEmail'), autocomplete: 'email' });
      var phoneI = el('input', { class: 'cs-chatbot__contact-input', type: 'tel', placeholder: T('contactPhone'), autocomplete: 'tel' });
      var noteI = el('textarea', { class: 'cs-chatbot__contact-input', rows: '2', placeholder: T('contactNote') });
      var err = el('div', { class: 'cs-chatbot__contact-err' });
      err.style.display = 'none';
      var submit = el('button', { type: 'button', class: 'cs-chatbot__contact-submit' }, [T('contactSubmit')]);
      card.appendChild(nameI);
      card.appendChild(emailI);
      card.appendChild(phoneI);
      card.appendChild(noteI);
      card.appendChild(err);
      card.appendChild(submit);
      messages.appendChild(card);
      scrollMessagesToBottom();

      submit.addEventListener('click', function () {
        var email = emailI.value.trim();
        var phone = phoneI.value.trim();
        if (!email && !phone) {
          err.textContent = T('contactNeedOne');
          err.style.display = '';
          return;
        }
        err.style.display = 'none';
        submit.disabled = true;
        var data = new FormData();
        data.append('name', nameI.value.trim());
        data.append('email', email);
        data.append('phone', phone);
        data.append('note', noteI.value.trim());
        data.append('source', source || 'ai_unanswered');
        data.append('pageUrl', location.href);
        var token = localStorage.getItem(STORAGE_TOKEN);
        if (token) data.append('sessionToken', token);
        post(urls().submitContact, data).then(function (r) {
          if (!r || !r.success) {
            submit.disabled = false;
            err.textContent = (r && r.error) || T('contactError');
            err.style.display = '';
            return;
          }
          if (r.sessionToken) localStorage.setItem(STORAGE_TOKEN, r.sessionToken);
          state.contactCaptured = true;
          if (card.parentNode) card.remove();
          addSystem(T('contactThanks'), new Date().toISOString());
          scrollMessagesToBottom();
        }).catch(function () {
          submit.disabled = false;
          err.textContent = T('contactError');
          err.style.display = '';
        });
      });
    }

    // Render an inline (user-filled) form the bot chose to display, collect the
    // values and submit them. The schema (no delivery details) comes from the
    // send response as `r.form`.
    function renderFormCard(form) {
      // Only one pending form at a time; drop any earlier card.
      var stale = messages.querySelector('.cs-chatbot__leadform');
      if (stale) stale.remove();
      // Persist so the form survives a page reload until it's submitted.
      try {
        localStorage.setItem(STORAGE_FORM, JSON.stringify({ token: localStorage.getItem(STORAGE_TOKEN) || '', form: form }));
      } catch (e) {}

      var card = el('div', { class: 'cs-chatbot__contact cs-chatbot__leadform' });
      if (form.label) {
        card.appendChild(el('div', { class: 'cs-chatbot__form-title' }, [form.label]));
      }
      var body = el('div', { class: 'cs-chatbot__form-body' });
      var inputs = {};
      (form.fields || []).forEach(function (f) {
        var wrap = el('div', { class: 'cs-chatbot__form-field' });
        var label = el('label', { class: 'cs-chatbot__form-label' }, [f.label || f.name]);
        if (f.required) label.appendChild(el('span', { class: 'cs-chatbot__form-req' }, ['*']));
        wrap.appendChild(label);
        var input;
        if (f.type === 'textarea') {
          input = el('textarea', { class: 'cs-chatbot__contact-input', rows: '2' });
        } else if (f.type === 'select') {
          input = el('select', { class: 'cs-chatbot__contact-input' });
          input.appendChild(el('option', { value: '' }, [T('formSelectPrompt')]));
          // Option values stay canonical; optionLabels carries the site-language
          // text, and is absent on a form persisted before this shipped.
          (f.options || []).forEach(function (o, i) {
            input.appendChild(el('option', { value: o }, [(f.optionLabels && f.optionLabels[i]) || o]));
          });
        } else {
          var t = f.type === 'email' ? 'email' : (f.type === 'tel' ? 'tel' : (f.type === 'number' ? 'number' : 'text'));
          input = el('input', { class: 'cs-chatbot__contact-input', type: t });
        }
        wrap.appendChild(input);
        var err = el('div', { class: 'cs-chatbot__contact-err' });
        err.style.display = 'none';
        wrap.appendChild(err);
        inputs[f.name] = { el: input, field: f, err: err };
        body.appendChild(wrap);
      });
      var generalErr = el('div', { class: 'cs-chatbot__contact-err' });
      generalErr.style.display = 'none';
      body.appendChild(generalErr);
      var submit = el('button', { type: 'button', class: 'cs-chatbot__contact-submit cs-chatbot__form-submit' }, [T('formSubmit')]);
      body.appendChild(submit);
      card.appendChild(body);
      messages.appendChild(card);
      scrollMessagesToBottom();

      function showErr(name, msg) { var it = inputs[name]; if (!it) return; it.err.textContent = msg; it.err.style.display = ''; }
      function clearErrs() {
        Object.keys(inputs).forEach(function (n) { inputs[n].err.style.display = 'none'; });
        generalErr.style.display = 'none';
      }

      submit.addEventListener('click', function () {
        clearErrs();
        var values = {};
        var bad = false;
        Object.keys(inputs).forEach(function (name) {
          var it = inputs[name];
          var v = (it.el.value || '').trim();
          values[name] = v;
          if (it.field.required && v === '') { showErr(name, T('formRequired')); bad = true; }
          else if (v !== '' && it.field.type === 'email' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) { showErr(name, T('formInvalidEmail')); bad = true; }
        });
        if (bad) { scrollMessagesToBottom(); return; }
        submit.disabled = true;
        var data = new FormData();
        data.append('form', form.name);
        data.append('pageUrl', location.href);
        var token = localStorage.getItem(STORAGE_TOKEN);
        if (token) data.append('sessionToken', token);
        Object.keys(values).forEach(function (name) { data.append('fields[' + name + ']', values[name]); });
        post(urls().submitForm, data).then(function (r) {
          if (!r || !r.success) {
            submit.disabled = false;
            if (r && r.errors) {
              Object.keys(r.errors).forEach(function (name) {
                showErr(name, r.errors[name] === 'invalid' ? T('formInvalidEmail') : T('formRequired'));
              });
            }
            generalErr.textContent = (r && r.error) || T('formError');
            generalErr.style.display = '';
            scrollMessagesToBottom();
            return;
          }
          if (r.sessionToken) localStorage.setItem(STORAGE_TOKEN, r.sessionToken);
          try { localStorage.removeItem(STORAGE_FORM); } catch (e) {}
          if (card.parentNode) card.remove();
          addSystem(T('formThanks'), new Date().toISOString());
          scrollMessagesToBottom();
        }).catch(function () {
          submit.disabled = false;
          generalErr.textContent = T('formError');
          generalErr.style.display = '';
        });
      });
    }

    function poll() {
      var token = localStorage.getItem(STORAGE_TOKEN);
      if (!token) return;
      var url = urls().poll + (urls().poll.indexOf('?') > -1 ? '&' : '?') + 'sessionToken=' + encodeURIComponent(token) + '&afterId=' + state.lastMessageId;
      getJson(url).then(function (r) {
        if (r && r.banned) { stopPolling(); root.remove(); return; }
        if (!r.success) return;
        var statusChanged = state.handoffStatus !== r.handoffStatus || state.chatEnded !== !!r.chatEnded;
        state.handoffStatus = r.handoffStatus;
        state.adminName = r.adminName;
        state.chatEnded = !!r.chatEnded;
        state.chatRating = r.chatRating;
        state.shortId = r.shortId || state.shortId;
        var panelClosed = !root.classList.contains('is-open');
        var newIncoming = false;
        (r.messages || []).forEach(function (m) {
          if (m.id <= state.lastMessageId) return;
          state.lastMessageId = m.id;
          if (m.role === 'admin') { addAdmin(m.content, m.time || m.dateCreated, m.dateLocal || m.dateCreated); newIncoming = true; }
          else if (m.role === 'system') { addSystem(m.content, m.time || m.dateCreated, m.dateLocal || m.dateCreated); newIncoming = true; }
          // user/bot already displayed locally
        });
        if (newIncoming && panelClosed) state.unread = true;
        if (newIncoming) playChime();
        if (statusChanged || newIncoming) {
          updateBanner();
          refreshLauncherDot();
          if (state.chatEnded) { renderChatRating(); scrollMessagesToBottom(); }
        }
        if (r.contactCaptured) state.contactCaptured = true;
        if (r.requestContact && !state.contactCaptured) {
          showContactForm('handoff_timeout');
        }
        scheduleNextPoll();
      }).catch(function(){ scheduleNextPoll(); });
    }

    function scheduleNextPoll() {
      stopPolling();
      if (state.chatEnded) return;
      if (state.handoffStatus !== 'requested' && state.handoffStatus !== 'active') return;
      var idle = (Date.now() - state.lastActivity) > 60000;
      var delay = idle ? 5000 : 1500;
      state.pollTimer = setTimeout(poll, delay);
    }

    function startPolling() { stopPolling(); poll(); }
    function stopPolling() { if (state.pollTimer) { clearTimeout(state.pollTimer); state.pollTimer = null; } }

    function autoGrow() {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 140) + 'px';
    }
    input.addEventListener('focus', function(){ state.lastActivity = Date.now(); });
    input.addEventListener('input', autoGrow);
    input.addEventListener('keydown', function (e) {
      state.lastActivity = Date.now();
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.dispatchEvent(new Event('submit', { cancelable: true }));
      }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = input.value.trim();
      if (!q) return;
      input.value = '';
      autoGrow();
      ask(q);
    });

    function restoreSession(token) {
      var url = urls().poll + (urls().poll.indexOf('?') > -1 ? '&' : '?') + 'sessionToken=' + encodeURIComponent(token) + '&afterId=0';
      return getJson(url).then(function (r) {
        if (!r.success) {
          localStorage.removeItem(STORAGE_TOKEN);
          renderGreeting();
          updateBanner();
          refreshLauncherDot();
          return;
        }
        state.handoffStatus = r.handoffStatus || 'none';
        state.adminName = r.adminName;
        state.chatEnded = !!r.chatEnded;
        state.chatRating = r.chatRating;
        state.shortId = r.shortId;
        state.contactCaptured = !!r.contactCaptured;
        var msgs = r.messages || [];
        if (!msgs.length) {
          renderGreeting();
        } else {
          // Opening line isn't stored in the DB — re-render it at the top so it persists across reloads.
          addBot(config.initialMessage || T('hello'), msgs[0].dateCreated || new Date().toISOString());
          msgs.forEach(function (m) {
            state.lastMessageId = Math.max(state.lastMessageId, m.id);
            var tm = m.time || m.dateCreated;
            var fd = m.dateLocal || m.dateCreated;
            if (m.role === 'user') addUser(m.content, tm, fd);
            else if (m.role === 'bot') addBot(m.content, tm, { messageId: m.id, initialRating: m.rating || 0, fullDate: fd, offerHuman: !!m.offerHuman });
            else if (m.role === 'admin') addAdmin(m.content, tm, fd);
            else if (m.role === 'system') addSystem(m.content, tm, fd);
          });
        }
        updateBanner();
        refreshLauncherDot();
        if (state.chatEnded) renderChatRating();
        scrollMessagesToBottom();
        if (!state.chatEnded && r.requestContact && !state.contactCaptured) {
          showContactForm('handoff_timeout');
        }
        // Re-render an inline form the user hadn't submitted before reloading.
        if (!state.chatEnded) {
          try {
            var pf = JSON.parse(localStorage.getItem(STORAGE_FORM) || 'null');
            if (pf && pf.form && pf.form.fields && pf.token === token) {
              renderFormCard(pf.form);
            } else if (pf && pf.token !== token) {
              localStorage.removeItem(STORAGE_FORM);
            }
          } catch (e) {}
        }
        var handoffOpen = state.handoffStatus === 'requested' || state.handoffStatus === 'active';
        if (handoffOpen) scheduleNextPoll();
        // Auto-open across page navigations when there's a live conversation the
        // user was already in — any messages exchanged and not ended — unless they
        // explicitly minimized it. Covers bot chats too, not just human handoffs.
        var hasActiveChat = !state.chatEnded && msgs.length > 0;
        if (hasActiveChat && localStorage.getItem(STORAGE_MINIMIZED) !== '1') {
          open();
        }
      }).catch(function () {
        renderGreeting();
        updateBanner();
        refreshLauncherDot();
      });
    }

    var existingToken = localStorage.getItem(STORAGE_TOKEN);
    if (existingToken) {
      restoreSession(existingToken);
    } else {
      renderGreeting();
      updateBanner();
      refreshLauncherDot();
    }
  }

  function urls() {
    return (window.csChatbot && window.csChatbot.urls) || {
      config: '/actions/_cs-chatbot/chat/config',
      send: '/actions/_cs-chatbot/chat/send',
      rate: '/actions/_cs-chatbot/chat/rate',
      suggestionClick: '/actions/_cs-chatbot/chat/suggestion-click',
      poll: '/actions/_cs-chatbot/chat/poll',
      requestHuman: '/actions/_cs-chatbot/chat/request-human',
      submitContact: '/actions/_cs-chatbot/chat/submit-contact',
      submitForm: '/actions/_cs-chatbot/chat/submit-form',
      end: '/actions/_cs-chatbot/chat/end',
      rateChat: '/actions/_cs-chatbot/chat/rate-chat',
      og: '/actions/_cs-chatbot/og/fetch'
    };
  }

  function boot() {
    var cfgUrl = urls().config + (urls().config.indexOf('?') > -1 ? '&' : '?') + 'pageUrl=' + encodeURIComponent(location.href);
    fetch(cfgUrl, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (cfg) {
        if (cfg && cfg.enabled) {
          window.csChatbot = window.csChatbot || {};
          window.csChatbot.config = cfg;
          window.csChatbot.strings = cfg.strings || {};
          init(cfg);
        }
      })
      .catch(function () {});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
