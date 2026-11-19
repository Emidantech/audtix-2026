/* Audtix Assistant: website chat widget. Talks to api/chat.php. If the server part is not reachable (for example a static preview),
   it falls back to built-in answers in this file, so it never looks broken. Nothing here changes the rest of the page. */
(function () {
  'use strict';
  var WA = '256701287388', API = (document.currentScript && document.currentScript.getAttribute('data-api')) || 'api/chat.php';
  var DEMO = !!(document.currentScript && document.currentScript.hasAttribute('data-demo'));
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
  var token = store.get('audtix_chat') || '', busy = false, handed = false, asked = false, localLog = [], bot = 0;

  var RULES = [
    [['tax', 'ura', 'vat', 'paye', 'return'], 'We handle URA returns, tax registration, compliance reviews and tax advice. Send the details at request-tax.html and we will respond. Free tax tools are at tools.html.'],
    [['audit', 'assurance', 'due diligence'], 'We provide statutory and internal audit and assurance. Tell us about your organisation at request-audit.html and we will reply with the way forward.'],
    [['efris', 'e-invoic', 'integration'], 'We connect business systems to URA, including EFRIS e-invoicing: assessment, integration, testing and go-live. Request a quotation at quote.html?service=ura.'],
    [['erp', 'odoo', 'erpnext', 'otibee', 'inventory'], 'We deploy Odoo, ERPNext and Otibee ERP: licences, set-up, data migration and training. Request a quotation at quote.html?service=erp.'],
    [['laptop', 'desktop', 'server', 'hardware', 'lenovo', 'dell', 'computer', 'equipment'], 'We supply Lenovo and Dell laptops, desktops, servers and storage with delivery and warranty. List what you need at quote.html?service=ict.'],
    [['cyber', 'security', 'phishing'], 'We offer cyber security assessments, tools and staff training. Request a quotation at quote.html?service=cyber.'],
    [['network', 'wifi', 'cabling'], 'We design and install networks: cabling, Wi-Fi and equipment. Request a quotation at quote.html?service=net.'],
    [['software', 'app', 'website', 'fintech', 'custom'], 'We build custom fintech systems and business software. Describe it at quote.html?service=custom and we will send a scoped quotation.'],
    [['quote', 'quotation', 'price', 'cost', 'how much'], 'A quotation is prepared once we understand your need. Choose the service on quote.html, fill the short form and we will respond.'],
    [['where', 'office', 'location', 'ntinda'], 'Our Africa office is in Ntinda, Kampala, and our headquarters is in Kentucky, USA. WhatsApp: +256 701 287 388.'],
    [['contact', 'phone', 'email', 'whatsapp', 'number'], 'WhatsApp +256 701 287 388 or info@audtix.com. Share your name and number here and a colleague will contact you.'],
    [['hello', 'hi', 'hey', 'good morning', 'good afternoon'], 'Hello, and welcome to Audtix Systems! I can help with tax, audit, ERP, systems, cyber security, networking and ICT hardware. What do you need?'],
    [['thank', 'thanks'], 'You are welcome! Message us any time.']
  ], HAND = ['human', 'agent', 'person', 'someone', 'speak to', 'talk to', 'call me', 'meeting', 'complain', 'urgent', 'negotiat', 'discount', 'contract'];
  function localReply(m) {
    var l = ' ' + m.toLowerCase() + ' ', i, j;
    for (i = 0; i < HAND.length; i++) if (l.indexOf(HAND[i]) > -1) return { reply: 'Understood. I will pass this to a colleague together with our conversation so you do not have to repeat yourself. They will pick it up on WhatsApp shortly.', handoff: true };
    for (i = 0; i < RULES.length; i++) for (j = 0; j < RULES[i][0].length; j++) if (l.indexOf(RULES[i][0][j]) > -1) return { reply: RULES[i][1], handoff: false };
    return bot >= 2 ? { reply: 'I want to get this right, so I will bring in a colleague who can help properly.', handoff: true } : { reply: 'Thanks for your message. Is it about tax, audit, an ERP or software system, cyber security, networking or ICT hardware?', handoff: false };
  }
  function waLink(extra) { var t = 'Hello Audtix, I was chatting on your website.\n' + localLog.slice(-8).map(function (x) { return (x[0] === 'u' ? 'Me: ' : 'Audtix bot: ') + x[1].slice(0, 160); }).join('\n'); return 'https://wa.me/' + WA + '?text=' + encodeURIComponent((extra || t).slice(0, 900)); }

  /* ---------- UI ---------- */
  var root = document.createElement('div'); root.className = 'cb';
  root.innerHTML = '<button type="button" class="cb-btn" aria-expanded="false" aria-controls="cb-panel" aria-label="Chat with Audtix"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg><span class="cb-lab">Chat</span></button>' +
    '<section class="cb-panel" id="cb-panel" role="dialog" aria-label="Audtix Assistant" hidden><header class="cb-head"><div><b>Audtix Assistant</b><small>Replies instantly. A person can step in.</small></div><button type="button" class="cb-x" aria-label="Close chat">&times;</button></header>' +
    '<div class="cb-msgs" aria-live="polite"></div><div class="cb-chips"></div>' +
    '<form class="cb-contact" hidden><b>So a colleague can reach you</b><input name="name" placeholder="Your name" autocomplete="name" maxlength="120"><input name="phone" placeholder="Phone or WhatsApp" autocomplete="tel" maxlength="40"><input name="email" type="email" placeholder="Email (optional)" autocomplete="email" maxlength="120"><div><button class="cb-send" type="submit">Save</button> <button type="button" class="cb-skip">Not now</button></div></form>' +
    '<form class="cb-form"><input class="cb-hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true"><input class="cb-in" name="m" placeholder="Type your question" maxlength="1000" autocomplete="off" aria-label="Your message"><button class="cb-send" type="submit" aria-label="Send">Send</button></form></section>';
  document.body.appendChild(root);
  var btn = root.querySelector('.cb-btn'), panel = root.querySelector('.cb-panel'), msgs = root.querySelector('.cb-msgs'), form = root.querySelector('.cb-form'), inp = root.querySelector('.cb-in'), chips = root.querySelector('.cb-chips'), contact = root.querySelector('.cb-contact');
  function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function linkify(s) { return esc(s).replace(/(https?:\/\/[^\s<]+|(?:quote|request-tax|request-audit|tools|contact)\.html[^\s<]*)/g, function (u) { var h = u.replace(/[.,)]+$/, ''), tail = u.slice(h.length); return '<a href="' + h + '"' + (/^https?:/.test(h) ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + h.replace(/^https?:\/\/(www\.)?/, '') + '</a>' + tail; }); }
  function add(role, text, extraHtml) { var d = document.createElement('div'); d.className = 'cb-m ' + role; d.innerHTML = linkify(text) + (extraHtml || ''); msgs.appendChild(d); msgs.scrollTop = msgs.scrollHeight; return d; }
  function typing(on) { var t = msgs.querySelector('.typing'); if (on && !t) { t = add('b typing', '<i></i><i></i><i></i>'); t.className = 'cb-m b typing'; t.innerHTML = '<i></i><i></i><i></i>'; } else if (!on && t) t.remove(); }
  function waBtn(url) { return '<a class="cb-wa" href="' + url + '" target="_blank" rel="noopener noreferrer">Continue on WhatsApp</a>'; }
  function open(o) { panel.hidden = !o; btn.setAttribute('aria-expanded', o ? 'true' : 'false'); root.classList.toggle('open', o); if (o) { if (!msgs.children.length) start(); setTimeout(function () { inp.focus(); }, 50); } }
  btn.addEventListener('click', function () { open(panel.hidden); });
  root.querySelector('.cb-x').addEventListener('click', function () { open(false); btn.focus(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { open(false); btn.focus(); } });

  function start() {
    add('b', 'Hello! I am the Audtix Assistant. Ask me about tax, audit, ERP and systems, cyber security, networking or ICT hardware.');
    ['Request a quotation', 'Tax help', 'ICT hardware', 'Talk to a person'].forEach(function (c) { var b = document.createElement('button'); b.type = 'button'; b.textContent = c; b.addEventListener('click', function () { chips.innerHTML = ''; send(c === 'Request a quotation' ? 'I want a quotation' : c === 'Talk to a person' ? 'I want to talk to a person' : c); }); chips.appendChild(b); });
    if (token && !DEMO) fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'history', token: token }) }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.messages && j.messages.length) { msgs.innerHTML = ''; chips.innerHTML = ''; j.messages.forEach(function (m) { add(m.role === 'user' ? 'u' : 'b', m.body); }); handed = j.status === 'handoff'; asked = !!j.has_contact; }
    }).catch(function () {});
  }

  function finish(j, userText) {
    typing(false); var wa = j.handoff ? (j.wa_url || waLink()) : '';
    add('b', j.reply || 'Thank you.', wa ? waBtn(wa) : ''); localLog.push(['b', j.reply || '']); bot++;
    if (j.handoff) handed = true;
    if (!asked && (j.need_contact !== false) && bot >= 1 && !DEMO) { contact.hidden = false; }
    if (!asked && DEMO && bot >= 1) { contact.hidden = false; }
    busy = false; inp.disabled = false; inp.focus();
  }
  function send(text) {
    if (busy || !text.trim()) return; busy = true; inp.value = ''; inp.disabled = true; add('u', text); localLog.push(['u', text]); chips.innerHTML = ''; typing(true);
    var page = location.pathname.split('/').pop() || 'index.html';
    if (DEMO) { setTimeout(function () { finish(localReply(text), text); }, 650); return; }
    fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'send', token: token, message: text, page: page, website: root.querySelector('.cb-hp').value }) })
      .then(function (r) { if (!r.ok && r.status !== 429) throw new Error('http'); return r.json(); })
      .then(function (j) { if (!j || !j.reply) throw new Error('bad'); if (j.token) { token = j.token; store.set('audtix_chat', token); } if (j.need_contact === false) asked = true; finish(j, text); })
      .catch(function () { finish(localReply(text), text); });   // server part not reachable: keep helping with the built-in answers
  }
  form.addEventListener('submit', function (e) { e.preventDefault(); send(inp.value); });
  root.querySelector('.cb-skip').addEventListener('click', function () { contact.hidden = true; asked = true; });
  contact.addEventListener('submit', function (e) {
    e.preventDefault(); var d = { action: 'contact', token: token, name: contact.name.value, phone: contact.phone.value, email: contact.email.value };
    if (!d.name.trim() || (!d.phone.trim() && !d.email.trim())) { add('b', 'Please give your name and a phone number or email.'); return; }
    contact.hidden = true; asked = true; add('b', 'Thank you, ' + d.name.split(' ')[0] + '. A colleague can now reach you if needed.');
    if (DEMO) return; fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(d) }).then(function (r) { return r.json(); }).then(function (j) { if (j && j.token) { token = j.token; store.set('audtix_chat', token); } }).catch(function () {});
  });
})();
