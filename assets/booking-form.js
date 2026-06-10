/* global BookGo */
(function () {
    'use strict';

    var i18n    = BookGo.i18n;
    var apiBase = BookGo.apiBase;
    var nonce   = BookGo.nonce;

    function apiFetch(url) {
        return fetch(url, { headers: { 'X-WP-Nonce': nonce } })
            .then(function (r) {
                if (!r.ok) throw new Error(r.status);
                return r.json();
            });
    }

    function formatDate(iso) {
        var p = iso.split('-');
        if (p.length !== 3) return iso;
        var months = ['', 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca',
                      'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
        return parseInt(p[2], 10) + ' ' + months[parseInt(p[1], 10)] + ' ' + p[0];
    }

    document.querySelectorAll('.bookgo-form-wrapper').forEach(function (wrapper) {
        var productId  = wrapper.dataset.productId;
        var datesEl    = wrapper.querySelector('.bookgo-dates');
        var loadingEl  = wrapper.querySelector('.bookgo-loading');
        var hoursSec   = wrapper.querySelector('.bookgo-hours-section');
        var hoursList  = wrapper.querySelector('.bookgo-hours-list');
        var submitBtn  = wrapper.querySelector('.bookgo-submit');
        var resultEl   = wrapper.querySelector('.bookgo-result');

        var selectedDate = null;
        var selectedTime = null;

        // ── Load available dates ──────────────────────────────────────────────
        apiFetch(apiBase + '/dates?product_id=' + productId)
            .then(function (dates) {
                loadingEl.style.display = 'none';
                if (!dates.length) {
                    datesEl.innerHTML = '<p>' + i18n.noSlots + '</p>';
                    return;
                }
                datesEl.innerHTML = '';
                dates.forEach(function (d) {
                    var btn = document.createElement('button');
                    btn.type      = 'button';
                    btn.className = 'bookgo-date-btn';
                    btn.dataset.date = d;
                    btn.textContent  = formatDate(d);
                    datesEl.appendChild(btn);
                });
            })
            .catch(function () { loadingEl.textContent = i18n.errorDates; });

        // ── Date click ────────────────────────────────────────────────────────
        datesEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.bookgo-date-btn');
            if (!btn) return;

            wrapper.querySelectorAll('.bookgo-date-btn').forEach(function (b) { b.classList.remove('selected'); });
            btn.classList.add('selected');

            selectedDate = btn.dataset.date;
            selectedTime = null;
            submitBtn.disabled  = true;
            resultEl.textContent = '';
            hoursList.innerHTML  = '<li>' + i18n.loading + '</li>';
            hoursSec.style.display = '';

            apiFetch(apiBase + '/available?product_id=' + productId + '&date=' + encodeURIComponent(selectedDate))
                .then(function (slots) {
                    hoursList.innerHTML = '';
                    if (!slots.length) {
                        hoursList.innerHTML = '<li>' + i18n.noHours + '</li>';
                        return;
                    }
                    slots.forEach(function (slot) {
                        var li  = document.createElement('li');
                        var btn = document.createElement('button');
                        btn.type      = 'button';
                        btn.className = 'bookgo-hour-btn';
                        btn.dataset.time = slot.start;
                        btn.textContent  = slot.start + ' – ' + slot.end;
                        li.appendChild(btn);
                        hoursList.appendChild(li);
                    });
                })
                .catch(function () { hoursList.innerHTML = '<li>' + i18n.errorHours + '</li>'; });
        });

        // ── Hour click ────────────────────────────────────────────────────────
        hoursList.addEventListener('click', function (e) {
            var btn = e.target.closest('.bookgo-hour-btn');
            if (!btn) return;
            wrapper.querySelectorAll('.bookgo-hour-btn').forEach(function (b) { b.classList.remove('selected'); });
            btn.classList.add('selected');
            selectedTime       = btn.dataset.time;
            submitBtn.disabled = false;
        });

        // ── Submit ────────────────────────────────────────────────────────────
        submitBtn.addEventListener('click', function () {
            if (!selectedDate || !selectedTime) return;
            submitBtn.disabled   = true;
            resultEl.textContent = i18n.redirecting;
            window.location.href = '/?add-to-cart=' + productId
                + '&bookgo_date=' + encodeURIComponent(selectedDate)
                + '&bookgo_time=' + encodeURIComponent(selectedTime);
        });
    });
}());
