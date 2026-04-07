jQuery(function ($) {
    $('.bookgo-form-wrapper').each(function () {
        var $wrapper      = $(this);
        var productId     = $wrapper.data('product-id');
        var $datesWrap    = $wrapper.find('.bookgo-dates');
        var $loading      = $wrapper.find('.bookgo-loading');
        var $hoursSection = $wrapper.find('.bookgo-hours-section');
        var $hoursList    = $wrapper.find('.bookgo-hours-list');
        var $submit       = $wrapper.find('.bookgo-submit');
        var $result       = $wrapper.find('.bookgo-result');
        var i18n          = BookGo.i18n;
        var selectedDate  = null;
        var selectedTime  = null;

        $.getJSON(BookGo.apiBase + '/dates', { product_id: productId })
            .done(function (dates) {
                $loading.hide();
                if (!dates.length) { $datesWrap.html('<p>' + i18n.noSlots + '</p>'); return; }
                dates.forEach(function (d) {
                    $datesWrap.append(
                        $('<button type="button" class="bookgo-date-btn">').attr('data-date', d).text(formatDate(d))
                    );
                });
            })
            .fail(function () { $loading.text(i18n.errorDates); });

        $wrapper.on('click', '.bookgo-date-btn', function () {
            $wrapper.find('.bookgo-date-btn').removeClass('selected');
            $(this).addClass('selected');
            selectedDate = $(this).data('date');
            selectedTime = null;
            $submit.prop('disabled', true);
            $result.text('');
            $hoursList.html('<li>' + i18n.loading + '</li>');
            $hoursSection.show();

            $.getJSON(BookGo.apiBase + '/available', { product_id: productId, date: selectedDate })
                .done(function (slots) {
                    $hoursList.empty();
                    if (!slots.length) { $hoursList.html('<li>' + i18n.noHours + '</li>'); return; }
                    slots.forEach(function (slot) {
                        $hoursList.append(
                            $('<li>').append(
                                $('<button type="button" class="bookgo-hour-btn">').attr('data-time', slot.start).text(slot.start + ' – ' + slot.end)
                            )
                        );
                    });
                })
                .fail(function () { $hoursList.html('<li>' + i18n.errorHours + '</li>'); });
        });

        $wrapper.on('click', '.bookgo-hour-btn', function () {
            $wrapper.find('.bookgo-hour-btn').removeClass('selected');
            $(this).addClass('selected');
            selectedTime = $(this).data('time');
            $submit.prop('disabled', false);
        });

        $submit.on('click', function () {
            if (!selectedDate || !selectedTime) return;
            $submit.prop('disabled', true);
            $result.text(i18n.redirecting);
            window.location.href = '/?add-to-cart=' + productId
                + '&bookgo_date=' + encodeURIComponent(selectedDate)
                + '&bookgo_time=' + encodeURIComponent(selectedTime);
        });
    });

    function formatDate(iso) {
        var p = iso.split('-');
        if (p.length !== 3) return iso;
        var months = ['', 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca',
                      'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
        return parseInt(p[2]) + ' ' + months[parseInt(p[1])] + ' ' + p[0];
    }
});
