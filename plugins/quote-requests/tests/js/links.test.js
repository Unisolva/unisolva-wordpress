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

const link = (key, url, label) => ({ key, url, label });

test('safeLinks reads anything that is not a list as no links', () => {
  // An answer stored or cached by 0.2.0 has no "links" key at all.
  for (const value of [undefined, null, false, 'https://example.com/', 7, {}, { 0: link('a', '/a', 'A') }]) {
    assert.deepEqual(L.safeLinks(value), []);
  }
});

test('safeLinks keeps http, https and site-relative addresses', () => {
  const list = [link('a', 'https://example.com/shop/?x=1&y=2#top', 'Shop'), link('b', 'http://example.com/', 'Home'), link('c', '/products/', 'Products'), link('d', '/', 'Start')];
  assert.deepEqual(L.safeLinks(list), list);
});

test('safeLinks drops addresses that must not be linked', () => {
  const bad = ['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'java\tscript:alert(1)', 'data:text/html,x', 'mailto:sales@example.com', 'ftp://example.com/', '//example.com/', '/\\example.com/', '/\t/example.com/', ' https://example.com/', 'https://exa mple.com/', 'https://', 'example.com/page', 'products/', '?page=2', ''];
  for (const url of bad) {
    assert.deepEqual(L.safeLinks([link('x', url, 'Label')]), [], JSON.stringify(url));
  }
});

test('safeLinks drops entries without a usable address or label', () => {
  const good = link('ok', '/ok/', 'OK');
  const list = [null, 'junk', 5, [], {}, { key: 'no-url', label: 'No address' }, { key: 'no-label', url: '/a/' }, link('empty', '/a/', ''), link('blank', '/a/', '  \n'), { key: 'n', url: '/a/', label: 5 }, { key: 'u', url: ['/a/'], label: 'A' }, good];
  assert.deepEqual(L.safeLinks(list), [good]);
});

test('safeLinks keeps at most four links, in order, and only the three keys', () => {
  const list = ['a', 'b', 'c', 'd', 'e'].map(k => ({ key: k, url: '/' + k + '/', label: k.toUpperCase(), extra: true }));
  list.unshift('junk');
  const out = L.safeLinks(list);
  assert.deepEqual(out.map(l => l.key), ['a', 'b', 'c', 'd']);
  assert.deepEqual(Object.keys(out[0]), ['key', 'url', 'label']);
  assert.equal(L.MAX_LINKS, 4);
});

test('safeLinks gives a link without a key the key "link"', () => {
  assert.deepEqual(L.safeLinks([{ url: '/a/', label: 'A' }, { key: 9, url: '/b/', label: 'B' }]), [link('link', '/a/', 'A'), link('link', '/b/', 'B')]);
});

test('safeLinks does not change what it is given', () => {
  const list = [link('a', '/a/', 'A'), link('bad', 'javascript:alert(1)', 'Bad')];
  const copy = JSON.parse(JSON.stringify(list));
  L.safeLinks(list);
  assert.deepEqual(list, copy);
});

test('the page script draws the panel without links when list.js has no safeLinks', () => {
  // The line of assets/quote-requests.js that reads the links, taken from the file and run as written.
  // An optimiser can serve an older list.js beside the new script: the request is stored, so the panel must still be drawn.
  const source = require('node:fs').readFileSync(require('node:path').join(__dirname, '../../assets/quote-requests.js'), 'utf8');
  const found = source.match(/^\s*(var links = [^\n]*;)\s*$/m);
  assert.ok(found, 'the script has one line that reads the links');
  const read = new Function('L', 'res', found[1] + ' return links;');
  const res = { body: { links: [link('home', '/', 'Home'), link('bad', 'javascript:alert(1)', 'Bad')] } };
  assert.deepEqual(read({}, res), [], 'an older list.js: no links, no error');
  assert.deepEqual(read(L, res), [link('home', '/', 'Home')], 'this list.js: the checked links');
});
