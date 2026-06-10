/* global FullCalendar, bookgoAdmin */
(function () {
    'use strict';

    var i18n      = bookgoAdmin.i18n;
    var restUrl   = bookgoAdmin.restUrl;
    var restNonce = bookgoAdmin.restNonce;

    // ── State ─────────────────────────────────────────────────────────────────
    var currentDate    = null;
    var dayData        = null; // { slots, products, booked_intervals }
    var fcCalendar     = null;

    // ── REST helpers ──────────────────────────────────────────────────────────
    function restGet(path) {
        return fetch(restUrl + path, {
            headers: { 'X-WP-Nonce': restNonce },
        }).then(function (r) { return r.json(); });
    }

    function restPost(path, body) {
        return fetch(restUrl + path, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
            body:    JSON.stringify(body),
        }).then(function (r) { return r.json(); });
    }

    function restDelete(path, body) {
        return fetch(restUrl + path, {
            method:  'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
            body:    JSON.stringify(body),
        }).then(function (r) { return r.json(); });
    }

    // ── Modal DOM ─────────────────────────────────────────────────────────────
    function createModal() {
        var el = document.createElement('div');
        el.id  = 'bookgo-modal-overlay';
        el.className = 'bookgo-modal-overlay';
        el.setAttribute('hidden', '');
        el.innerHTML = [
            '<div class="bookgo-modal" role="dialog" aria-modal="true">',
            '  <div class="bookgo-modal-header">',
            '    <h2 id="bookgo-modal-title"></h2>',
            '    <button id="bookgo-modal-close" aria-label="' + i18n.close + '">&#x2715;</button>',
            '  </div>',
            '  <div class="bookgo-modal-body">',
            '    <div class="bookgo-modal-section">',
            '      <h3 class="bookgo-modal-section-title">' + i18n.booked + ' / ' + i18n.available + '</h3>',
            '      <div id="bookgo-timeline-list"></div>',
            '    </div>',
            '    <div class="bookgo-modal-section bookgo-modal-add">',
            '      <h3 class="bookgo-modal-section-title">' + i18n.addSlot + '</h3>',
            '      <div class="bookgo-form-row">',
            '        <label for="bookgo-slot-product">Produkt</label>',
            '        <select id="bookgo-slot-product">',
            '          <option value="">' + i18n.selectProduct + '</option>',
            '        </select>',
            '      </div>',
            '      <div class="bookgo-form-row">',
            '        <label for="bookgo-slot-time">Godzina</label>',
            '        <select id="bookgo-slot-time"></select>',
            '      </div>',
            '      <div id="bookgo-slot-conflict" class="bookgo-conflict-warn" hidden></div>',
            '      <button id="bookgo-slot-save" class="button button-primary" disabled>' + i18n.addSlot + '</button>',
            '    </div>',
            '  </div>',
            '</div>',
        ].join('');
        document.body.appendChild(el);

        // Close handlers
        document.getElementById('bookgo-modal-close').addEventListener('click', closeModal);
        el.addEventListener('click', function (e) { if (e.target === el) closeModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

        // Form change → conflict check
        document.getElementById('bookgo-slot-product').addEventListener('change', onFormChange);
        document.getElementById('bookgo-slot-time').addEventListener('change', onFormChange);

        // Save
        document.getElementById('bookgo-slot-save').addEventListener('click', saveSlot);
    }

    function openModal(dateStr) {
        currentDate = dateStr;
        dayData     = null;

        var overlay = document.getElementById('bookgo-modal-overlay');
        overlay.removeAttribute('hidden');

        var fmt = new Intl.DateTimeFormat('pl-PL', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        document.getElementById('bookgo-modal-title').textContent = fmt.format(new Date(dateStr + 'T12:00:00'));
        document.getElementById('bookgo-timeline-list').innerHTML  = '<p class="bookgo-loading">Ładowanie…</p>';
        document.getElementById('bookgo-slot-save').disabled       = true;
        document.getElementById('bookgo-slot-conflict').setAttribute('hidden', '');

        loadDay(dateStr);
    }

    function closeModal() {
        var overlay = document.getElementById('bookgo-modal-overlay');
        overlay.setAttribute('hidden', '');
        currentDate = null;
        dayData     = null;
    }

    // ── Day data ──────────────────────────────────────────────────────────────
    function loadDay(dateStr) {
        var filterEl  = document.getElementById('bookgo-calendar-filter');
        var calId     = filterEl ? filterEl.value : '';
        var qs        = 'day?date=' + encodeURIComponent(dateStr) + (calId ? '&calendar_id=' + encodeURIComponent(calId) : '');

        restGet(qs).then(function (data) {
            dayData = data;
            renderTimeline(data.slots);
            renderProductSelect(data.products);
            renderTimeSelect();
            onFormChange();
        }).catch(function () {
            document.getElementById('bookgo-timeline-list').innerHTML = '<p style="color:red;">Błąd ładowania danych.</p>';
        });
    }

    // ── Timeline ──────────────────────────────────────────────────────────────
    function renderTimeline(slots) {
        var el = document.getElementById('bookgo-timeline-list');
        if (!slots || !slots.length) {
            el.innerHTML = '<p class="bookgo-empty">' + i18n.noSlots + '</p>';
            return;
        }

        var html = '<table class="bookgo-timeline-table"><thead><tr>'
            + '<th>Czas</th><th>Produkt</th><th>Status</th><th></th>'
            + '</tr></thead><tbody>';

        slots.forEach(function (slot) {
            var badge    = '';
            var rowClass = '';

            if (slot.type === 'booked') {
                var statusLabel = { pending: 'Oczekuje', processing: 'W realizacji', 'on-hold': 'Wstrzymane', completed: 'Zakończona' };
                badge    = '<span class="bookgo-badge bookgo-badge--' + slot.status + '">'
                         + (statusLabel[slot.status] || slot.status) + '</span>';
                if (slot.customer) badge += ' <span class="bookgo-customer">' + escHtml(slot.customer) + '</span>';
                rowClass = 'bookgo-row--booked';
            } else if (slot.type === 'conflict') {
                badge    = '<span class="bookgo-badge bookgo-badge--conflict">' + i18n.conflict + '</span>';
                rowClass = 'bookgo-row--conflict';
            } else {
                badge    = '<span class="bookgo-badge bookgo-badge--available">' + i18n.available + '</span>';
                rowClass = 'bookgo-row--available';
            }

            var deleteBtn = '';
            if (slot.can_delete) {
                deleteBtn = '<button class="button bookgo-delete-slot" '
                    + 'data-product="' + slot.product_id + '" '
                    + 'data-time="'    + escAttr(slot.time_start) + '">'
                    + i18n.deleteSlot + '</button>';
            } else if (slot.order_id) {
                deleteBtn = '<a href="' + bookgoAdmin.adminUrl + 'post.php?post=' + slot.order_id + '&action=edit" class="button">Zamówienie #' + slot.order_id + '</a>';
            }

            html += '<tr class="' + rowClass + '">'
                + '<td class="bookgo-time-col"><strong>' + escHtml(slot.time_start) + '</strong>'
                + ' – ' + escHtml(slot.time_end) + '</td>'
                + '<td>' + escHtml(slot.product_name) + '</td>'
                + '<td>' + badge + '</td>'
                + '<td>' + deleteBtn + '</td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        el.innerHTML = html;

        // Delete slot handlers
        el.querySelectorAll('.bookgo-delete-slot').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm(i18n.confirmDelete)) return;
                var pid  = parseInt(btn.dataset.product, 10);
                var time = btn.dataset.time;
                deleteSlot(pid, currentDate, time);
            });
        });
    }

    // ── Product select ────────────────────────────────────────────────────────
    function renderProductSelect(products) {
        var sel = document.getElementById('bookgo-slot-product');
        sel.innerHTML = '<option value="">' + i18n.selectProduct + '</option>';
        (products || []).forEach(function (p) {
            var label = p.name + (p.calendar_name ? ' (' + p.calendar_name + ')' : '') + ' — ' + p.duration + ' min';
            var opt   = document.createElement('option');
            opt.value = p.id;
            opt.textContent = label;
            opt.dataset.duration = p.duration;
            sel.appendChild(opt);
        });
    }

    // ── Time select (07:00–20:30, configurable step) ─────────────────────────
    function renderTimeSelect() {
        var sel  = document.getElementById('bookgo-slot-time');
        var step = parseInt(bookgoAdmin.timeStep, 10) || 30;
        sel.innerHTML = '';
        for (var totalMin = 7 * 60; totalMin < 21 * 60; totalMin += step) {
            var h   = Math.floor(totalMin / 60);
            var m   = totalMin % 60;
            var hh  = String(h).padStart(2, '0');
            var mm  = String(m).padStart(2, '0');
            var opt = document.createElement('option');
            opt.value = hh + ':' + mm;
            opt.textContent = hh + ':' + mm;
            sel.appendChild(opt);
        }
    }

    // ── Conflict check ────────────────────────────────────────────────────────
    function onFormChange() {
        var productSel = document.getElementById('bookgo-slot-product');
        var timeSel    = document.getElementById('bookgo-slot-time');
        var saveBtn    = document.getElementById('bookgo-slot-save');
        var warnEl     = document.getElementById('bookgo-slot-conflict');

        var pid  = productSel.value;
        var time = timeSel.value;

        if (!pid || !time || !dayData) {
            saveBtn.disabled = true;
            warnEl.setAttribute('hidden', '');
            return;
        }

        var selOpt   = productSel.querySelector('option[value="' + pid + '"]');
        var duration = selOpt ? parseInt(selOpt.dataset.duration, 10) : 60;

        var tsStart = timeToTs(currentDate, time);
        var tsEnd   = tsStart + duration * 60;

        var hasConflict = (dayData.booked_intervals || []).some(function (interval) {
            return tsStart < interval.ts_end && tsEnd > interval.ts_start;
        });

        if (hasConflict) {
            warnEl.textContent = i18n.conflictWarn;
            warnEl.removeAttribute('hidden');
        } else {
            warnEl.setAttribute('hidden', '');
        }

        saveBtn.disabled = false;
    }

    // ── Save slot ─────────────────────────────────────────────────────────────
    function saveSlot() {
        var pid     = document.getElementById('bookgo-slot-product').value;
        var time    = document.getElementById('bookgo-slot-time').value;
        var saveBtn = document.getElementById('bookgo-slot-save');

        if (!pid || !time || !currentDate) return;

        saveBtn.disabled    = true;
        saveBtn.textContent = i18n.saving;

        restPost('slot', { product_id: parseInt(pid, 10), date: currentDate, time: time })
            .then(function (res) {
                saveBtn.textContent = i18n.addSlot;
                if (res.success) {
                    loadDay(currentDate);
                    if (fcCalendar) fcCalendar.refetchEvents();
                } else {
                    alert(res.error || 'Błąd zapisu.');
                    saveBtn.disabled = false;
                }
            })
            .catch(function () {
                saveBtn.textContent = i18n.addSlot;
                saveBtn.disabled    = false;
                alert('Błąd połączenia.');
            });
    }

    // ── Delete slot ───────────────────────────────────────────────────────────
    function deleteSlot(productId, date, time) {
        restDelete('slot', { product_id: productId, date: date, time: time })
            .then(function (res) {
                if (res.success) {
                    loadDay(currentDate);
                    if (fcCalendar) fcCalendar.refetchEvents();
                } else {
                    alert(res.error || 'Błąd usuwania.');
                }
            })
            .catch(function () { alert('Błąd połączenia.'); });
    }

    // ── Utils ─────────────────────────────────────────────────────────────────
    function timeToTs(dateStr, timeStr) {
        // Parse as local time using Date — safe for same-day comparisons
        var parts = timeStr.split(':');
        var d     = new Date(dateStr + 'T' + parts[0] + ':' + parts[1] + ':00');
        return Math.floor(d.getTime() / 1000);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escAttr(str) { return escHtml(str); }

    // ── FullCalendar init ─────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        var calendarEl = document.getElementById('bookgo-calendar');
        if (!calendarEl) return;

        createModal();

        var filterEl = document.getElementById('bookgo-calendar-filter');

        fcCalendar = new FullCalendar.Calendar(calendarEl, {
            locale:       'pl',
            initialView:  'dayGridMonth',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            slotMinTime: '07:00:00',
            slotMaxTime: '21:00:00',
            allDaySlot:  false,

            events: function (info, successCallback, failureCallback) {
                var calId = filterEl ? filterEl.value : '';
                var url   = bookgoAdmin.ajaxUrl
                    + '?action=bookgo_get_appointments'
                    + '&nonce=' + encodeURIComponent(bookgoAdmin.nonce)
                    + '&start=' + encodeURIComponent(info.startStr)
                    + '&end='   + encodeURIComponent(info.endStr)
                    + (calId ? '&calendar_id=' + encodeURIComponent(calId) : '');

                fetch(url)
                    .then(function (r) { return r.json(); })
                    .then(successCallback)
                    .catch(failureCallback);
            },

            dateClick: function (info) {
                openModal(info.dateStr);
            },

            eventDidMount: function (info) {
                var type = info.event.extendedProps.type;
                if (type === 'available') {
                    info.el.classList.add('bookgo-event--available');
                } else if (type === 'conflict') {
                    info.el.classList.add('bookgo-event--conflict');
                } else {
                    info.el.style.cursor = 'pointer';
                }
                var label = type === 'available' ? i18n.available : type === 'conflict' ? i18n.conflict : i18n.booked;
                info.el.title = '[' + label + '] ' + info.event.title;
            },

            eventClick: function (info) {
                var props = info.event.extendedProps;
                if (props.type === 'booked' && props.order_id) {
                    window.location.href = bookgoAdmin.adminUrl + 'post.php?post=' + props.order_id + '&action=edit';
                }
            },
        });

        fcCalendar.render();

        if (filterEl) {
            filterEl.addEventListener('change', function () {
                fcCalendar.refetchEvents();
            });
        }
    });
}());
