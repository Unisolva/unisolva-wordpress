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
