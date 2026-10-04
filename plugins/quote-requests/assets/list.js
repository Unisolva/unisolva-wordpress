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
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.QuoteRequestsList = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';
  var KEY = 'quote_requests_list';
  var TTL_MS = 30 * 24 * 3600 * 1000;
  var MAX_LINES = 50;
  var MAX_QTY = 9999;

  function toId(id) { var n = parseInt(id, 10); return n > 0 ? n : 0; }
  function toQty(q) { var n = parseInt(q, 10); if (!(n > 0)) n = 1; return Math.min(MAX_QTY, n); }
  function empty(now) { return { v: 1, updated: now, items: [] }; }
  function clone(list, now) { return { v: 1, updated: now === undefined ? list.updated : now, items: list.items.map(function (i) { return { id: i.id, qty: i.qty }; }) }; }

  function add(list, id, qty) {
    var n = toId(id);
    var out = clone(list);
    if (!n) return out;
    var q = qty === undefined ? 1 : toQty(qty);
    for (var i = 0; i < out.items.length; i++) {
      if (out.items[i].id === n) { out.items[i].qty = Math.min(MAX_QTY, out.items[i].qty + q); return out; }
    }
    if (out.items.length >= MAX_LINES) return out;
    out.items.push({ id: n, qty: q });
    return out;
  }
  function setQty(list, id, qty) {
    var n = toId(id);
    var out = clone(list);
    out.items.forEach(function (i) { if (i.id === n) i.qty = toQty(qty); });
    return out;
  }
  function remove(list, id) {
    var n = toId(id);
    var out = clone(list);
    out.items = out.items.filter(function (i) { return i.id !== n; });
    return out;
  }
  function merge(list, items) {
    var out = clone(list);
    (items || []).forEach(function (i) { out = add(out, i && i.id, i && i.qty); });
    return out;
  }
  function count(list) { return list.items.length; }
  function qtyOf(list, id) {
    var n = toId(id);
    for (var i = 0; i < list.items.length; i++) if (list.items[i].id === n) return list.items[i].qty;
    return 0;
  }
  function read(storage, now) {
    try {
      if (!storage) return empty(now);
      var raw = storage.getItem(KEY);
      if (!raw) return empty(now);
      var data = JSON.parse(raw);
      if (!data || data.v !== 1 || !Array.isArray(data.items) || typeof data.updated !== 'number') return empty(now);
      if (now - data.updated > TTL_MS) return empty(now);
      var out = { v: 1, updated: data.updated, items: [] };
      data.items.forEach(function (i) {
        var n = toId(i && i.id);
        var q = parseInt(i && i.qty, 10);
        if (n && q > 0 && out.items.length < MAX_LINES) out.items.push({ id: n, qty: Math.min(MAX_QTY, q) });
      });
      return out;
    } catch (e) {
      return empty(now);
    }
  }
  function write(storage, list, now) {
    try {
      storage.setItem(KEY, JSON.stringify(clone(list, now)));
      return true;
    } catch (e) {
      return false;
    }
  }
  return { KEY: KEY, TTL_MS: TTL_MS, MAX_LINES: MAX_LINES, MAX_QTY: MAX_QTY, empty: empty, read: read, write: write, add: add, setQty: setQty, remove: remove, merge: merge, count: count, qtyOf: qtyOf };
});
