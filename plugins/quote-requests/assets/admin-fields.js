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
// The settings screen. Without this script the screen still works: a field is added in the blank last row,
// the order is typed as numbers and a field is removed with its tick box. With it: "Add field", "Move up",
// "Move down" and "Remove" buttons that keep the order numbers in step, and the region settings that do not
// apply to the chosen region mode are hidden.
(function () {
  'use strict';

  // Puts values into a translated text: %s in turn, or %1$s, %2$s by number.
  function fill(text) {
    var values = Array.prototype.slice.call(arguments, 1);
    var turn = 0;
    return String(text || '').replace(/%(?:(\d+)\$)?s/g, function (match, number) {
      var value = values[number ? parseInt(number, 10) - 1 : turn++];
      return value === undefined ? '' : String(value);
    });
  }

  function fields(table) {
    var body = table.tBodies[0];
    var wrap = table.closest('.qr-settings') || table.parentNode;
    var live = document.getElementById('qr-fields-live');
    var template = document.getElementById('qr-field-template');
    var add = document.getElementById('qr-add-field');
    var count = document.getElementById('qr-fields-count');
    var form = table.closest('form');
    var max = parseInt(table.getAttribute('data-qr-max'), 10) || 20;
    var reserved = [];
    var serial = body.rows.length; // The number of the next new row: one no posted row uses.
    var timer = 0;
    var T = {};
    try { T = JSON.parse(table.getAttribute('data-qr-text') || '{}'); } catch (e) { T = {}; }
    try { reserved = JSON.parse(table.getAttribute('data-qr-reserved') || '[]'); } catch (e) { reserved = []; }

    function rows() { return Array.prototype.slice.call(body.querySelectorAll('tr[data-qr-row]')); }

    // The field as the buttons and the announcements name it: its label, else its key, else "new field".
    function nameOf(row) {
      var label = row.querySelector('[data-qr-label]');
      var key = row.querySelector('input[type="text"][name$="[key]"]');
      return (label && label.value.trim()) || row.getAttribute('data-qr-key') || (key && key.value.trim()) || T.unnamed || '';
    }

    // Emptied first, so the same words are announced again when the same thing happens twice.
    function say(text) {
      if (!live) return;
      window.clearTimeout(timer);
      live.textContent = '';
      timer = window.setTimeout(function () { live.textContent = text; }, 50);
    }

    // The choices box belongs to the type "select" only.
    function options(row) {
      var type = row.querySelector('[data-qr-type]');
      var box = row.querySelector('[data-qr-options]');
      if (type && box) box.hidden = type.value !== 'select';
    }

    // After every change: order numbers, button names, which ends cannot move further, the count, the Add button.
    function refresh() {
      var list = rows();
      var custom = 0;
      list.forEach(function (row, i) {
        var name = nameOf(row);
        var order = row.querySelector('[data-qr-order]');
        var up = row.querySelector('[data-qr-move="up"]');
        var down = row.querySelector('[data-qr-move="down"]');
        var remove = row.querySelector('[data-qr-remove]');
        if (order) order.value = String(i + 1);
        if (!row.hasAttribute('data-qr-system')) custom++;
        // aria-disabled, not disabled: the button keeps the focus when its row reaches an end.
        if (up) { up.hidden = false; up.setAttribute('aria-label', fill(T.up, name)); up.setAttribute('aria-disabled', i === 0 ? 'true' : 'false'); }
        if (down) { down.hidden = false; down.setAttribute('aria-label', fill(T.down, name)); down.setAttribute('aria-disabled', i === list.length - 1 ? 'true' : 'false'); }
        if (remove) { remove.hidden = false; remove.setAttribute('aria-label', fill(T.remove, name)); }
      });
      if (count) count.textContent = fill(T.count, custom, max);
      if (add) add.hidden = !template || custom >= max;
    }

    function move(row, button, up) {
      var list = rows();
      var i = list.indexOf(row);
      var name = nameOf(row);
      if (up ? i === 0 : i === list.length - 1) { say(fill(up ? T.first : T.last, name)); return; }
      // The neighbour is the row that is taken out and put back, so the row with the focus stays in the page.
      if (up) body.insertBefore(list[i - 1], row.nextSibling); else body.insertBefore(list[i + 1], row);
      refresh();
      button.focus();
      say(fill(up ? T.movedUp : T.movedDown, name, up ? i : i + 2, list.length));
    }

    function remove(row) {
      var list = rows();
      var i = list.indexOf(row);
      var name = nameOf(row);
      var near = list[i + 1] || list[i - 1];
      var target = near && near.querySelector('[data-qr-move="up"]');
      row.parentNode.removeChild(row);
      refresh();
      if (target) target.focus(); else if (add && !add.hidden) add.focus();
      say(fill(T.removed, name));
    }

    function addRow() {
      var holder = document.createElement('tbody');
      var row, label, total;
      if (!template) return;
      holder.innerHTML = template.innerHTML.replace(/__i__/g, String(serial++));
      row = holder.querySelector('tr[data-qr-row]');
      if (!row) return;
      body.appendChild(row);
      options(row);
      refresh();
      label = row.querySelector('[data-qr-label]');
      if (label) label.focus();
      total = rows().length;
      say(fill(T.added, total, total));
    }

    // Marks a control as invalid, with the reason as text under it and in its description.
    function flag(control, text) {
      var id = control.id + '-error';
      var note = document.getElementById(id);
      var described = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (part) { return part && part !== id; });
      if (!note) {
        note = document.createElement('span');
        note.id = id;
        note.className = 'qr-field-error';
        control.parentNode.insertBefore(note, control.nextSibling);
      }
      note.textContent = text;
      control.setAttribute('aria-invalid', 'true');
      control.setAttribute('aria-describedby', described.concat(id).join(' '));
    }

    function unflag(control) {
      var id = control.id + '-error';
      var note = document.getElementById(id);
      var described = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (part) { return part && part !== id; });
      if (!control.hasAttribute('aria-invalid')) return;
      if (note) note.parentNode.removeChild(note);
      control.removeAttribute('aria-invalid');
      if (described.length) control.setAttribute('aria-describedby', described.join(' ')); else control.removeAttribute('aria-describedby');
    }

    // What the server would refuse, caught before the form is sent: a list type without choices, and on a
    // new row a key that is reserved or in use. Returns the first control that is wrong, with its message.
    function validate() {
      var first = null;
      var used = {};
      function wrong(control, text) { flag(control, text); if (!first) first = { control: control, text: text }; }
      rows().forEach(function (row) { var key = row.getAttribute('data-qr-key'); if (key) used[key] = true; });
      rows().forEach(function (row) {
        var type = row.querySelector('[data-qr-type]');
        var box = row.querySelector('[data-qr-options] textarea');
        var key = row.querySelector('input[type="text"][name$="[key]"]');
        var value = key ? key.value.toLowerCase().replace(/[^a-z0-9_]/g, '') : ''; // As the server cleans it.
        if (type && box) {
          if (type.value === 'select' && box.value.trim() === '') wrong(box, T.choices); else unflag(box);
        }
        if (key) {
          if (value && reserved.indexOf(value) !== -1) wrong(key, fill(T.reserved, value));
          else if (value && used[value]) wrong(key, fill(T.used, value));
          else unflag(key);
          if (value) used[value] = true;
        }
      });
      return first;
    }

    // The blank row is for the screen without the script; "Add field" replaces it, unless something is typed in it.
    (function () {
      var list = rows();
      var blank = list[list.length - 1];
      var typed = false;
      if (!template || !blank || blank.hasAttribute('data-qr-system') || blank.getAttribute('data-qr-key') !== '') return;
      Array.prototype.forEach.call(blank.querySelectorAll('input[type="text"], textarea'), function (el) { if (el.value.trim() !== '') typed = true; });
      if (!typed) body.removeChild(blank);
    })();

    wrap.classList.add('qr-js');
    rows().forEach(options);
    refresh();

    body.addEventListener('click', function (event) {
      var button = event.target.closest ? event.target.closest('button[data-qr-move], button[data-qr-remove]') : null;
      var row = button && button.closest('tr[data-qr-row]');
      if (!row) return;
      if (button.hasAttribute('data-qr-remove')) remove(row); else move(row, button, button.getAttribute('data-qr-move') === 'up');
    });
    body.addEventListener('input', function (event) {
      if (!event.target.matches) return;
      if (event.target.matches('[data-qr-label], input[name$="[key]"]')) refresh();
      if (event.target.hasAttribute('aria-invalid')) unflag(event.target); // Typing takes the mark away; sending checks again.
    });
    body.addEventListener('change', function (event) {
      var row = event.target.matches && event.target.matches('[data-qr-type]') ? event.target.closest('tr[data-qr-row]') : null;
      var box = row && row.querySelector('[data-qr-options] textarea');
      if (row) options(row);
      if (box && event.target.value !== 'select') unflag(box);
    });
    if (form) {
      form.addEventListener('submit', function (event) {
        var bad = validate();
        if (!bad) return;
        event.preventDefault();
        bad.control.focus();
        say(bad.text);
      });
    }
    if (add) add.addEventListener('click', addRow);
  }

  // Shows the region settings of the chosen mode only: "Outside" for one country, the country list otherwise.
  function regions(box) {
    var radios = Array.prototype.slice.call(box.querySelectorAll('input[type="radio"][name$="[region_mode]"]'));
    function apply() {
      var mode = '';
      radios.forEach(function (radio) { if (radio.checked) mode = radio.value; });
      Array.prototype.forEach.call(box.querySelectorAll('[data-qr-mode]'), function (row) { row.hidden = mode !== '' && row.getAttribute('data-qr-mode') !== mode; });
    }
    radios.forEach(function (radio) { radio.addEventListener('change', apply); });
    apply();
  }

  function start() {
    var table = document.getElementById('qr-fields');
    var box = document.getElementById('qr-regions');
    if (table && table.tBodies[0]) fields(table);
    if (box) regions(box);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
