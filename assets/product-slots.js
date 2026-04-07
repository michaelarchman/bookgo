jQuery(function ($) {
    function toggleSlotsMeta() {
        var isBookGo = $('#product-type').val() === 'bookgo';
        $('#bookgo-slots-content').toggle(isBookGo);
        $('#bookgo-type-notice').toggle(!isBookGo);
    }

    $('#product-type').on('change', toggleSlotsMeta);
    toggleSlotsMeta();

    $('#bookgo-add-slot').on('click', function () {
        var i   = bookgoSlotIndex++;
        var row = '<tr class="bookgo-slot-row">'
            + '<td><input type="date"   name="_bookgo_slots[' + i + '][date]"     class="short"></td>'
            + '<td><input type="time"   name="_bookgo_slots[' + i + '][time]"     class="short" step="1800"></td>'
            + '<td><input type="number" name="_bookgo_slots[' + i + '][capacity]" class="short" value="1" min="1" style="width:64px;"></td>'
            + '<td><button type="button" class="button bookgo-remove-slot">' + bookgoL10n.remove + '</button></td>'
            + '</tr>';
        $('#bookgo-slots-body').append(row);
    });

    $(document).on('click', '.bookgo-remove-slot', function () {
        $(this).closest('tr').remove();
    });
});
