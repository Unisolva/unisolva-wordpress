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
const test = require('node:test');
const assert = require('node:assert/strict');
const L = require('../../assets/list.js');

function memory() {
  const m = new Map();
  return { getItem: k => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: k => m.delete(k), _m: m };
}
const throwing = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); }, removeItem() {} };
const NOW = 1_800_000_000_000;

test('add merges and clamps', () => {
  let l = L.empty(NOW);
  l = L.add(l, 10, 2);
  l = L.add(l, '10', 3);
  l = L.add(l, 11, 0);
  l = L.add(l, 12, 50000);
  assert.deepEqual(l.items, [{ id: 10, qty: 5 }, { id: 11, qty: 1 }, { id: 12, qty: 9999 }]);
  assert.equal(L.count(l), 3);
  assert.equal(L.qtyOf(l, 10), 5);
  assert.equal(L.qtyOf(l, 99), 0);
});

test('add ignores bad ids and the 51st line', () => {
  let l = L.empty(NOW);
  l = L.add(l, -1); l = L.add(l, 'x'); l = L.add(l, 0);
  assert.equal(L.count(l), 0);
  for (let i = 1; i <= 51; i++) l = L.add(l, i);
  assert.equal(L.count(l), 50);
  assert.equal(L.qtyOf(l, 51), 0);
});

test('setQty, remove, merge', () => {
  let l = L.add(L.add(L.empty(NOW), 1), 2);
  l = L.setQty(l, 1, 7);
  l = L.setQty(l, 2, -3);
  assert.deepEqual(l.items, [{ id: 1, qty: 7 }, { id: 2, qty: 1 }]);
  l = L.remove(l, 1);
  assert.deepEqual(l.items, [{ id: 2, qty: 1 }]);
  l = L.merge(l, [{ id: 2, qty: 4 }, { id: 3, qty: 1 }]);
  assert.deepEqual(l.items, [{ id: 2, qty: 5 }, { id: 3, qty: 1 }]);
});

test('functions are pure', () => {
  const a = L.add(L.empty(NOW), 1);
  const b = L.add(a, 2);
  assert.equal(L.count(a), 1);
  assert.equal(L.count(b), 2);
});

test('read/write round trip and expiry', () => {
  const s = memory();
  const l = L.add(L.empty(NOW), 5, 2);
  assert.equal(L.write(s, l, NOW), true);
  assert.deepEqual(L.read(s, NOW + 1000).items, [{ id: 5, qty: 2 }]);
  assert.deepEqual(L.read(s, NOW + L.TTL_MS + 1).items, []);
});

test('corrupt or blocked storage never throws', () => {
  const s = memory();
  s.setItem(L.KEY, '{not json');
  assert.deepEqual(L.read(s, NOW).items, []);
  s.setItem(L.KEY, JSON.stringify({ v: 1, updated: NOW, items: [{ id: 'a', qty: 'b' }, { id: 4, qty: 2 }] }));
  assert.deepEqual(L.read(s, NOW).items, [{ id: 4, qty: 2 }]);
  assert.deepEqual(L.read(throwing, NOW).items, []);
  assert.equal(L.write(throwing, L.empty(NOW), NOW), false);
  assert.deepEqual(L.read(null, NOW).items, []);
});

// ---- The quote list for other forms: hidden inputs named quote_requests_items. ----

test('itemsJson is the list as the server reads it', () => {
  let l = L.add(L.add(L.empty(NOW), 12, 3), 7);
  assert.equal(L.itemsJson(l), '[{"id":12,"qty":3},{"id":7,"qty":1}]');
  assert.equal(L.itemsJson(L.empty(NOW)), '[]');
});

test('itemsJson of anything that is not a list is an empty list, never an error', () => {
  [null, undefined, 'x', 5, {}, { items: 'x' }, { items: null }].forEach(v => assert.equal(L.itemsJson(v), '[]'));
  assert.equal(L.itemsJson({ items: [{ id: 'a', qty: 1 }, null, { id: 4, qty: '2' }, { id: 5, qty: 0 }] }), '[{"id":4,"qty":2},{"id":5,"qty":1}]');
});

test('isItemsInput knows the plain name and the name a form builder wraps it in', () => {
  assert.equal(L.ITEMS_INPUT, 'quote_requests_items');
  ['quote_requests_items', 'form_fields[quote_requests_items]', 'data[fields][quote_requests_items]'].forEach(n => assert.equal(L.isItemsInput(n), true, n));
  ['', 'items', 'quote_requests_items[]', 'my_quote_requests_items', 'quote_requests_items_2', 'form_fields[quote_requests_items][0]', null, undefined, 5].forEach(n => assert.equal(L.isItemsInput(n), false, String(n)));
});

test('the selector finds hidden inputs by both forms of the name', () => {
  assert.equal(L.ITEMS_SELECTOR, 'input[type="hidden"][name="quote_requests_items"],input[type="hidden"][name$="[quote_requests_items]"]');
});

test('fillInputs writes the list into every input and reports how many changed', () => {
  const l = L.add(L.empty(NOW), 12, 3);
  const inputs = [{ name: 'quote_requests_items', value: '' }, { name: 'form_fields[quote_requests_items]', value: 'old' }, { name: 'quote_requests_items', value: '[{"id":12,"qty":3}]' }];
  assert.equal(L.fillInputs(inputs, l), 2);
  inputs.forEach(i => assert.equal(i.value, '[{"id":12,"qty":3}]'));
  assert.equal(L.fillInputs(inputs, l), 0);
  assert.equal(L.fillInputs(inputs, L.empty(NOW)), 3);
  inputs.forEach(i => assert.equal(i.value, '[]'));
});

test('fillInputs takes an array-like and skips what is not an items input', () => {
  const other = { name: 'email', value: 'a@example.com' };
  const nodeList = { 0: { name: 'quote_requests_items', value: '' }, 1: other, 2: null, length: 3 };
  assert.equal(L.fillInputs(nodeList, L.add(L.empty(NOW), 1)), 1);
  assert.equal(nodeList[0].value, '[{"id":1,"qty":1}]');
  assert.equal(other.value, 'a@example.com');
  assert.equal(L.fillInputs(null, L.empty(NOW)), 0);
  assert.equal(L.fillInputs(undefined, L.empty(NOW)), 0);
});
