/*!
 * Quote Requests
 *
 * Copyright (C) 2026 Unisolva for Information Technology and App Development L.L.C.
 *
 * This program is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software Foundation;
 * either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 */
(function () {
  'use strict';
  var C = window.QuoteRequestsConfig || { labels: {} };
  var L = window.QuoteRequestsList;
  var T = C.labels || {};
  if (!L) return;

  function safeStorage(kind) {
    try { var s = window[kind]; var k = '__qr_test'; s.setItem(k, '1'); s.removeItem(k); return s; } catch (e) { return null; }
  }
  var store = safeStorage('localStorage');
  var session = safeStorage('sessionStorage');
  function now() { return Date.now(); }
  function load() { return L.read(store, now()); }
  function save(list) { L.write(store, list, now()); refreshLinks(list); refreshButtons(list); }
  // Works with pretty permalinks (/wp-json/...) and plain ones (?rest_route=/...).
  function restUrl(path, query) { var u = C.rest + path; return query ? u + (u.indexOf('?') === -1 ? '?' : '&') + query : u; }
  function fmt(s, v) { return String(s || '').replace('%d', v).replace('%s', v); }

  // Live region for announcements.
  var live = document.createElement('div');
  live.className = 'qr-live screen-reader-text';
  live.setAttribute('role', 'status');
  live.setAttribute('aria-live', 'polite');
  document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(live); });
  function announce(msg) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 50); }

  // Visit details for the request (landing page and outside referrer of this browsing session).
  try {
    if (session && !session.getItem('quote_requests_visit')) {
      session.setItem('quote_requests_visit', JSON.stringify({ landing: location.href, referrer: document.referrer || '' }));
    }
  } catch (e) {}
  function visit() { try { return JSON.parse((session && session.getItem('quote_requests_visit')) || '{}'); } catch (e) { return {}; } }

  // Merge the no-JS cookie list once, then delete the cookie.
  (function mergeCookie() {
    if (!store) return;
    var m = document.cookie.match(/(?:^|; )quote_requests_list=([^;]*)/);
    if (!m || !m[1]) return;
    try {
      var data = JSON.parse(decodeURIComponent(m[1]));
      L.write(store, L.merge(load(), data.items || []), now());
    } catch (e) {}
    document.cookie = 'quote_requests_list=; Max-Age=0; path=/; SameSite=Lax';
  })();

  function refreshLinks(list) {
    var n = L.count(list);
    document.querySelectorAll('[data-qr-link]').forEach(function (a) {
      var badge = a.querySelector('[data-qr-count]');
      if (badge) { badge.textContent = n; badge.hidden = n === 0; }
      a.setAttribute('aria-label', fmt(T.listName, n));
    });
  }

  function refreshButtons(list) {
    document.querySelectorAll('[data-qr-add]').forEach(function (a) {
      var q = L.qtyOf(list, a.getAttribute('data-qr-add'));
      var label = a.querySelector('[data-qr-label]') || a;
      if (q > 0) {
        label.textContent = fmt(T.added, q);
        a.classList.add('is-added');
        if (C.quotePage) a.setAttribute('href', C.quotePage);
      } else {
        label.textContent = T.add;
        a.classList.remove('is-added');
      }
    });
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-qr-add]');
    if (!a || !store) return; // No storage: follow the ?add= link (cookie fallback).
    var id = a.getAttribute('data-qr-add');
    if (L.qtyOf(load(), id) > 0) return; // Already in the list: the link goes to the quote page.
    e.preventDefault();
    save(L.add(load(), id, 1));
    var note = a.parentNode.querySelector('.qr-added-notice');
    if (!note) {
      note = document.createElement('span');
      note.className = 'qr-added-notice';
      a.parentNode.insertBefore(note, a.nextSibling);
    }
    note.textContent = (T.addedNotice || '') + ' ';
    if (C.quotePage) {
      var link = document.createElement('a');
      link.href = C.quotePage;
      link.textContent = T.view;
      note.appendChild(link);
    }
    announce(T.addedNotice);
  });

  document.addEventListener('DOMContentLoaded', function () {
    var list = load();
    refreshLinks(list);
    refreshButtons(list);
    var root = document.querySelector('[data-qr-page]');
    if (root && store) quotePage(root);
  });

  function quotePage(root) {
    var listEl = root.querySelector('[data-qr-list]');
    var emptyEl = root.querySelector('[data-qr-empty]');
    var noteEl = root.querySelector('[data-qr-note]');
    var form = root.querySelector('#quote-requests-form');
    var summary = root.querySelector('[data-qr-errors]');
    var failure = root.querySelector('[data-qr-failure]');
    var doneEl = root.querySelector('[data-qr-done]');
    var body = root.querySelector('.qr-body');
    var button = form.querySelector('.qr-submit');
    var message = form.querySelector('#qr-message');
    var phone = form.querySelector('#qr-phone');
    var email = form.querySelector('#qr-email');
    form.setAttribute('novalidate', 'novalidate');
    form.querySelectorAll('[data-qr-nojs]').forEach(function (n) { n.remove(); });
    // True while a region list is on its way: the region control is disabled then, and a disabled control is not sent.
    var regionsPending = false;

    // Region mode "choose": a change of country swaps the region control for that country's list, or for one line of text.
    (function regionSwitch() {
      var block = form.querySelector('[data-qr-type="region"]');
      var country = block && block.querySelector('select[name="region_country"]');
      var url = form.getAttribute('data-qr-regions-url');
      if (!country || !url || form.getAttribute('data-qr-region-mode') !== 'choose') return;
      var id = 'qr-' + block.getAttribute('data-qr-field');
      var known = {}; // Region lists already fetched, by country code.
      var turn = 0;   // Only the answer for the last change counts.
      // The country the region control is for: at first the one the server rendered it for.
      var shown = country.getAttribute('data-qr-country') || country.value;
      function swap(states) {
        var old = document.getElementById(id);
        var el;
        if (states.length) {
          el = document.createElement('select');
          el.appendChild(new Option(T.choose, ''));
          states.forEach(function (s) { el.appendChild(new Option(String(s.name), String(s.code))); });
        } else {
          el = document.createElement('input');
          el.type = 'text';
          el.maxLength = C.regionMax || 100;
        }
        // The new control is the old one to everything around it: label, description, required state.
        ['id', 'name', 'required', 'aria-required', 'aria-describedby'].forEach(function (a) { if (old.hasAttribute(a)) el.setAttribute(a, old.getAttribute(a)); });
        old.parentNode.replaceChild(el, old);
      }
      function load() {
        var code = country.value;
        var name = country.selectedIndex >= 0 ? country.options[country.selectedIndex].text : code;
        var mine = ++turn;
        var old = document.getElementById(id);
        var err = document.getElementById(id + '-error');
        shown = code;
        regionsPending = true;
        old.value = ''; // A region of the old country does not apply to the new one.
        old.disabled = true;
        block.setAttribute('aria-busy', 'true');
        var ready = known[code] ? Promise.resolve(known[code]) : fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'country=' + encodeURIComponent(code), { credentials: 'same-origin' })
          .then(function (r) { if (!r.ok) throw new Error('regions failed'); return r.json(); })
          .then(function (d) { if (!d || !Array.isArray(d.states)) throw new Error('regions failed'); known[code] = d.states; return d.states; });
        // No list, or no answer: the visitor types the region.
        ready.then(function (states) { return { states: states, failed: false }; }, function () { return { states: [], failed: true }; }).then(function (got) {
          var entry, now;
          if (mine !== turn) return;
          try {
            swap(got.states);
            if (err) { err.hidden = true; err.textContent = ''; }
            // The error of the old control leaves the summary with it; an empty summary is closed.
            entry = summary && summary.querySelector('a[href="#' + id + '"]');
            if (entry) {
              entry.parentNode.parentNode.removeChild(entry.parentNode);
              if (!summary.querySelector('li')) { summary.hidden = true; summary.innerHTML = ''; }
            }
          } finally {
            // Whatever happened, the region control is usable again.
            now = document.getElementById(id);
            if (now) now.disabled = false;
            block.removeAttribute('aria-busy');
            regionsPending = false;
            // The "please wait" of a submit that came too early has done its work.
            if (failure.textContent === T.regionWait) { failure.hidden = true; failure.textContent = ''; }
          }
          announce(fmt(got.failed ? T.regionFail : got.states.length ? T.regionList : T.regionText, name));
        });
      }
      // A browser that restores the form on reload or on "back" sets the country select to what the visitor chose,
      // while the region control is still the one of the country the server rendered. Bring them together.
      function sync() { if (country.value !== shown) load(); }
      country.addEventListener('change', load);
      sync();
      window.addEventListener('pageshow', sync); // Some browsers restore the form only after the script has started.
    })();

    function refreshToken() {
      return fetch(restUrl('token'), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) { form.querySelector('[name=token]').value = d.token || ''; })
        .catch(function () {});
    }
    var tokenReady = refreshToken();

    function render(cards) {
      var list = load();
      var byId = {};
      cards.forEach(function (c) { byId[c.id] = c; });
      var missing = list.items.filter(function (i) { return !byId[i.id]; });
      if (missing.length) {
        missing.forEach(function (i) { list = L.remove(list, i.id); });
        save(list);
        noteEl.textContent = T.unavailable;
        noteEl.hidden = false;
      }
      listEl.innerHTML = '';
      list.items.forEach(function (i) {
        var c = byId[i.id];
        var li = document.createElement('li');
        li.className = 'qr-item';
        li.setAttribute('data-qr-item', i.id);
        if (c.image) { var img = document.createElement('img'); img.className = 'qr-item__img'; img.src = c.image; img.alt = ''; img.width = 64; img.height = 64; li.appendChild(img); }
        var text = document.createElement('span'); text.className = 'qr-item__text';
        var name = document.createElement('a'); name.className = 'qr-item__name'; name.href = c.url; name.textContent = c.name; text.appendChild(name);
        if (c.family) { var fam = document.createElement('span'); fam.className = 'qr-item__family'; fam.textContent = c.family; text.appendChild(fam); }
        li.appendChild(text);
        var lab = document.createElement('label'); lab.className = 'qr-item__qty';
        var sr = document.createElement('span'); sr.className = 'screen-reader-text'; sr.textContent = fmt(T.qtyOf, c.name); lab.appendChild(sr);
        var qty = document.createElement('input'); qty.type = 'number'; qty.min = 1; qty.max = L.MAX_QTY; qty.value = i.qty; qty.inputMode = 'numeric';
        qty.addEventListener('change', function () { save(L.setQty(load(), i.id, qty.value)); qty.value = L.qtyOf(load(), i.id); });
        lab.appendChild(qty); li.appendChild(lab);
        var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'qr-item__remove'; rm.setAttribute('data-qr-remove', i.id);
        rm.textContent = T.remove; var rms = document.createElement('span'); rms.className = 'screen-reader-text'; rms.textContent = ' ' + c.name; rm.appendChild(rms);
        rm.addEventListener('click', function () { save(L.remove(load(), i.id)); li.remove(); emptyState(); announce(T.remove + ' ' + c.name); });
        li.appendChild(rm);
        listEl.appendChild(li);
      });
      emptyState();
    }
    function emptyState() {
      var empty = L.count(load()) === 0;
      emptyEl.hidden = !empty;
      if (empty) message.setAttribute('required', 'required'); else message.removeAttribute('required');
    }

    var ids = load().items.map(function (i) { return i.id; });
    if (ids.length) {
      fetch(restUrl('products', 'ids=' + ids.join(',')), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('lookup failed'); return r.json(); })
        .then(function (cards) {
          // Anything but a list (an error object, a login wall) is a failed lookup: keep the visitor's list untouched.
          if (!Array.isArray(cards)) throw new Error('lookup failed');
          render(cards);
        })
        .catch(function () { emptyState(); });
    } else {
      listEl.innerHTML = '';
      emptyState();
    }

    function clearErrors() {
      summary.hidden = true; summary.innerHTML = '';
      failure.hidden = true; failure.textContent = '';
      form.querySelectorAll('[aria-invalid]').forEach(function (f) { f.removeAttribute('aria-invalid'); });
      form.querySelectorAll('.qr-error').forEach(function (s) { s.hidden = true; s.textContent = ''; });
    }
    function showErrors(errors, msg) {
      var ul = document.createElement('ul');
      Object.keys(errors).forEach(function (f) {
        var field = document.getElementById('qr-' + f);
        var span = document.getElementById('qr-' + f + '-error');
        if (field) field.setAttribute('aria-invalid', 'true');
        if (span) { span.textContent = errors[f]; span.hidden = false; }
        var li = document.createElement('li'); var a = document.createElement('a'); a.href = '#qr-' + f; a.textContent = errors[f]; li.appendChild(a); ul.appendChild(li);
      });
      summary.innerHTML = '';
      if (msg) { var p = document.createElement('p'); p.textContent = msg; summary.appendChild(p); }
      summary.appendChild(ul);
      summary.hidden = false;
      summary.focus();
    }
    function isEmpty(f) { return f.type === 'checkbox' ? !f.checked : !String(f.value || '').trim(); }
    // Checks the controls in form order, from what the markup says: "required", the field type and the contact rule.
    function localCheck() {
      var e = {};
      var neither = form.getAttribute('data-qr-contact') === 'either' && phone && email && isEmpty(phone) && isEmpty(email);
      form.querySelectorAll('input[id^="qr-"], select[id^="qr-"], textarea[id^="qr-"]').forEach(function (f) {
        var key = f.id.slice(3);
        var wrap = f.closest('[data-qr-type]');
        if (neither && f === phone) { e[key] = T.contact; return; }
        // Text typed into a number field never reaches the value: without this it would be dropped unseen.
        if (wrap && wrap.getAttribute('data-qr-type') === 'number' && f.validity && f.validity.badInput) { e[key] = T.number; return; }
        if (f.required && isEmpty(f)) {
          var span = document.getElementById(f.id + '-error');
          e[key] = (span && span.getAttribute('data-msg')) || (f.labels && f.labels[0] ? f.labels[0].textContent.replace('*', '').trim() : key);
        }
      });
      return e;
    }
    function payload() {
      var data = {};
      new FormData(form).forEach(function (v, k) { if (k.indexOf('items[') !== 0) data[k] = v; });
      var v = visit();
      data.items = load().items;
      data.landing = v.landing || '';
      data.referrer = v.referrer || '';
      data.page = location.href;
      data.tz = -new Date().getTimezoneOffset();
      return data;
    }
    function send(retried) {
      return fetch(restUrl('quotes'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload()) })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
        .then(function (res) {
          var code = res.body && res.body.code;
          if (!retried && code && code.indexOf('token_') === 0) {
            return refreshToken().then(function () { return new Promise(function (ok) { setTimeout(ok, 3200); }); }).then(function () { return send(true); });
          }
          return res;
        });
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearErrors();
      // Sent now, the request would leave without a region: the control is disabled until its list has arrived.
      if (regionsPending) { failure.textContent = T.regionWait; failure.hidden = false; return; }
      var local = localCheck();
      if (Object.keys(local).length) { showErrors(local, ''); return; }
      button.disabled = true; button.textContent = T.sending;
      tokenReady.then(function () { return send(false); }).then(function (res) {
        button.disabled = false; button.textContent = T.send;
        if (res.status === 201 && res.body.ok) {
          L.write(store, L.empty(now()), now()); refreshLinks(load()); refreshButtons(load());
          doneEl.innerHTML = '';
          var h = document.createElement('h2'); h.className = 'qr-done__title'; h.textContent = res.body.thanks; doneEl.appendChild(h);
          var p = document.createElement('p'); p.textContent = fmt(T.yourRef, res.body.ref); doneEl.appendChild(p);
          if (res.body.items && res.body.items.length) {
            var ul = document.createElement('ul'); ul.className = 'qr-done__items';
            res.body.items.forEach(function (it) { var li = document.createElement('li'); li.textContent = it.name + ' × ' + it.qty; ul.appendChild(li); });
            doneEl.appendChild(ul);
          }
          body.hidden = true; doneEl.hidden = false; doneEl.focus();
          doneEl.scrollIntoView({ block: 'start' });
          return;
        }
        if (res.body && res.body.errors) { showErrors(res.body.errors, res.body.message); return; }
        failure.textContent = (res.body && res.body.message ? res.body.message + ' ' : '') + T.failure;
        failure.hidden = false;
      }).catch(function () {
        button.disabled = false; button.textContent = T.send;
        failure.textContent = T.failure; failure.hidden = false;
      });
    });
  }
})();
