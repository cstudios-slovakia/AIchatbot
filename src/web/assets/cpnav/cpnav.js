(function () {
  if (window.__csChatbotCpNav) return;
  window.__csChatbotCpNav = true;

  var URL = (window.csChatbotCpNav && window.csChatbotCpNav.badgeUrl) || '/actions/interactive-ai-assistant/handoff/badge-count';
  var INTERVAL = 15000;
  var STORAGE_KEY = 'csChatbotNavCounts';
  var origTitle = document.title.replace(/^\(\d+\)\s*/, '');
  var lastSig = '';

  var audioCtx = null;
  // Two-octave C-major scale. Each chat maps to one note (×7 spread, coprime to 15 → big jumps),
  // so distinct chats sound clearly different and a given chat always sounds the same.
  var NOTE_SCALE = [261.63, 293.66, 329.63, 349.23, 392.00, 440.00, 493.88, 523.25, 587.33, 659.25, 698.46, 783.99, 880.00, 987.77, 1046.50];
  function sessionTone(id) {
    var n = Math.abs(parseInt(id, 10) || 0);
    return NOTE_SCALE[(n * 7) % NOTE_SCALE.length];
  }
  function playTones(freqs) {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      var ctx = audioCtx;
      if (ctx.state === 'suspended') ctx.resume();
      var now = ctx.currentTime;
      freqs.forEach(function (freq, i) {
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        var t0 = now + i * 0.12;
        gain.gain.setValueAtTime(0.0001, t0);
        gain.gain.exponentialRampToValueAtTime(0.12, t0 + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.28);
        osc.connect(gain).connect(ctx.destination);
        osc.start(t0);
        osc.stop(t0 + 0.3);
      });
    } catch (e) {}
  }
  function playChime() { playTones([880, 1320]); }
  // Rising three-note figure, deliberately unlike the chat tones: a lead is a
  // thing to follow up later, not a visitor waiting on a reply right now.
  function playLeadChime() { playTones([523.25, 659.25, 783.99]); }
  function playToneForSession(id) { var f = sessionTone(id); playTones([f, f * 1.5]); }
  window.csChatbotPlayChime = playChime;
  window.csChatbotPlayToneForSession = playToneForSession;

  function isMutedSession(id) {
    try {
      var raw = localStorage.getItem('csChatbotMutedSessions');
      var arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) && arr.indexOf(parseInt(id, 10)) !== -1;
    } catch (e) { return false; }
  }
  var prevUnreadById = null; // {id: unread} from last poll; null until first paint
  function pingNewMessages(sessions) {
    // The live-chat page owns audio (faster, per-chat) and sets this flag; stay silent there.
    if (window.csChatbotSuppressNavChime) { prevUnreadById = null; return; }
    var curr = {};
    (sessions || []).forEach(function (s) { curr[s.id] = s.unread | 0; });
    if (prevUnreadById !== null) {
      Object.keys(curr).forEach(function (id) {
        var nid = parseInt(id, 10);
        if (curr[id] > (prevUnreadById[id] || 0) && !isMutedSession(nid)) {
          playToneForSession(nid);
        }
      });
    }
    prevUnreadById = curr;
  }

  // Leads are counted across page loads, so a lead that arrives while the admin
  // is on some other CP screen still announces itself exactly once.
  var prevLeads = null;
  function pingNewLeads(counts) {
    var n = counts.leads | 0;
    if (prevLeads !== null && n > prevLeads) {
      playLeadChime();
      try {
        var d = n - prevLeads;
        if (window.Craft && Craft.cp && Craft.cp.displayNotice) {
          Craft.cp.displayNotice(d === 1 ? 'New lead captured.' : d + ' new leads captured.');
        }
      } catch (e) {}
    }
    prevLeads = n;
  }

  function readCached() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      var c = JSON.parse(raw);
      if (typeof c !== 'object' || c === null) return null;
      return {
        waiting: c.waiting|0, active: c.active|0, unread: c.unread|0,
        leads: c.leads|0, leadsMissed: c.leadsMissed|0, leadsSubmissions: c.leadsSubmissions|0,
      };
    } catch (e) { return null; }
  }
  function writeCached(counts) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(counts)); } catch (e) {}
  }

  function endsWithChatbotRoot(href) {
    if (/[?&]p=admin\/interactive-ai-assistant(?:&|$)/.test(href)) return true;
    var path = href.split('?')[0].split('#')[0].replace(/\/+$/, '');
    return /\/interactive-ai-assistant$/.test(path);
  }
  function isLiveChatHref(href) {
    return /interactive-ai-assistant\/live-chat(?:[\/?#&]|$)/.test(href);
  }
  function isMissedChatsHref(href) {
    return /interactive-ai-assistant\/missed-chats(?:[\/?#&]|$)/.test(href);
  }

  function findTargets() {
    var anchors = document.querySelectorAll('#global-sidebar a, #subnav a, #nav a');
    var top = null;
    var sub = null;
    var lead = null;
    anchors.forEach(function (a) {
      var href = a.getAttribute('href') || '';
      if (!lead && isMissedChatsHref(href)) { lead = a; return; }
      if (!sub && isLiveChatHref(href)) { sub = a; return; }
      if (!top && endsWithChatbotRoot(href)) { top = a; }
    });
    return { top: top, sub: sub, lead: lead };
  }

  function clearStrayBadges(keep) {
    var anchors = document.querySelectorAll('#global-sidebar a, #subnav a, #nav a');
    anchors.forEach(function (a) {
      var href = a.getAttribute('href') || '';
      if (href.indexOf('interactive-ai-assistant') === -1) return;
      if (a !== keep.top && a !== keep.sub && a !== keep.lead) {
        var b = a.querySelector('.badge, .cs-bot-badges');
        if (b) b.remove();
      }
    });
  }

  function badgeWrap(el) {
    // Remove Craft's native single badge to avoid double display
    var native = el.querySelector('.badge');
    if (native) native.remove();
    // Append inside .label if it exists so it sits inline with text
    var host = el.querySelector('.label') || el;
    var wrap = host.querySelector('.cs-bot-badges');
    if (!wrap) {
      wrap = document.createElement('span');
      wrap.className = 'cs-bot-badges';
      host.appendChild(wrap);
    }
    return wrap;
  }

  function leadTitle(counts) {
    var bits = [];
    if (counts.leadsMissed > 0) bits.push(counts.leadsMissed + ' missed chat' + (counts.leadsMissed === 1 ? '' : 's'));
    if (counts.leadsSubmissions > 0) bits.push(counts.leadsSubmissions + ' form submission' + (counts.leadsSubmissions === 1 ? '' : 's'));
    return bits.length ? 'New leads: ' + bits.join(', ') : 'New leads';
  }

  function leadBadge(counts) {
    return '<span class="cs-bot-badge cs-bot-badge--lead" title="' + leadTitle(counts) + '">' + counts.leads + '</span>';
  }

  function setBadges(el, counts, includeLeads) {
    if (!el) return;
    var wrap = badgeWrap(el);
    var parts = [];
    if (counts.waiting > 0) parts.push('<span class="cs-bot-badge cs-bot-badge--waiting" title="Waiting for an agent">' + counts.waiting + '</span>');
    if (counts.active > 0) parts.push('<span class="cs-bot-badge cs-bot-badge--active" title="Active conversations">' + counts.active + '</span>');
    if (counts.unread > 0) parts.push('<span class="cs-bot-badge cs-bot-badge--unread" title="Unread messages">' + counts.unread + '</span>');
    if (includeLeads && counts.leads > 0) parts.push(leadBadge(counts));
    wrap.innerHTML = parts.join('');
    if (!parts.length) wrap.remove();
  }

  function setLeadBadge(el, counts) {
    if (!el) return;
    var wrap = badgeWrap(el);
    wrap.innerHTML = counts.leads > 0 ? leadBadge(counts) : '';
    if (counts.leads <= 0) wrap.remove();
  }

  function refreshTitle(total) {
    document.title = total > 0 ? '(' + total + ') ' + origTitle : origTitle;
  }

  function ensureStyles() {
    if (document.getElementById('cs-bot-badge-styles')) return;
    var s = document.createElement('style');
    s.id = 'cs-bot-badge-styles';
    s.textContent = [
      '.cs-bot-badges { display:inline-flex; gap:4px; margin-left:6px; vertical-align:middle; }',
      '.cs-bot-badge { display:inline-block; min-width:18px; padding:1px 6px; border-radius:10px; font-size:11px; font-weight:600; line-height:1.4; text-align:center; color:#fff; }',
      '.cs-bot-badge--waiting { background:#d97706; }',
      '.cs-bot-badge--active { background:#2563eb; }',
      '.cs-bot-badge--unread { background:#dc2626; animation: cs-bot-pulse 1.5s infinite; }',
      '.cs-bot-badge--lead { background:#7c3aed; }',
      '@keyframes cs-bot-pulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.12); } }',
    ].join('\n');
    document.head.appendChild(s);
  }

  var lastTotal = -1;
  function applyCounts(counts) {
    var leads = counts.leads | 0;
    var total = counts.waiting + counts.active + counts.unread;
    var sig = counts.waiting + ':' + counts.active + ':' + counts.unread + ':' + leads;
    var targets = findTargets();
    clearStrayBadges(targets);
    ensureStyles();
    if (sig === lastSig) return;
    lastSig = sig;
    lastTotal = total;
    setBadges(targets.top, counts, true);
    setBadges(targets.sub, counts, false);
    setLeadBadge(targets.lead, counts);
    // Leads count towards the tab title too — that is the one indicator visible
    // when the admin is working in another browser tab entirely.
    refreshTitle(total + leads);
    // Audio is handled per-chat by pingNewMessages() (distinct tone per conversation).
  }

  function poll() {
    fetch(URL, { credentials:'same-origin', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'} })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.success) return;
        var counts = {
          waiting: (r.waiting|0), active: (r.active|0), unread: (r.unread|0),
          leads: (r.leads|0), leadsMissed: (r.leadsMissed|0), leadsSubmissions: (r.leadsSubmissions|0),
        };
        writeCached(counts);
        applyCounts(counts);
        pingNewMessages(r.sessions);
        pingNewLeads(counts);
      })
      .catch(function () {});
  }

  function start() {
    // Instant render from cache to avoid flash on page nav
    var cached = readCached();
    if (cached) {
      applyCounts(cached);
      // Seed, don't announce: these were already chimed on the page that saw them.
      prevLeads = cached.leads | 0;
    }
    poll();
    setInterval(poll, INTERVAL);
    window.addEventListener('cs-chatbot:read', function () { lastSig = ''; poll(); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
