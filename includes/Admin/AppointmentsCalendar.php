<?php
namespace BookGo\Admin;

if (!defined('ABSPATH')) exit;

class AppointmentsCalendar
{
    public static function init(): void
    {
        add_action('admin_menu',            [self::class, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_bookgo_get_appointments', [self::class, 'ajaxGetAppointments']);
    }

    public static function addMenuPage(): void
    {
        add_menu_page(
            __('Wizyty (BookGo)', 'bookgo'),
            __('Wizyty', 'bookgo'),
            'manage_woocommerce',
            'bookgo-calendar',
            [self::class, 'renderPage'],
            'dashicons-calendar-alt',
            56
        );
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_bookgo-calendar') return;

        wp_enqueue_script('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js', [], '6.1.11', true);
        wp_add_inline_script('fullcalendar', self::calendarScript(), 'after');
    }

    public static function renderPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wizyty (BookGo)', 'bookgo'); ?></h1>
            <div id="bookgo-calendar" style="background:#fff;padding:16px;border-radius:4px;"></div>
        </div>
        <?php
    }

    private static function calendarScript(): string
    {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce    = wp_create_nonce('bookgo_calendar');

        return <<<JS
document.addEventListener('DOMContentLoaded', function () {
    var calendarEl = document.getElementById('bookgo-calendar');
    if (!calendarEl) return;

    var calendar = new FullCalendar.Calendar(calendarEl, {
        locale:       'pl',
        initialView:  'dayGridMonth',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
        events: function (info, successCallback, failureCallback) {
            fetch('{$ajax_url}?action=bookgo_get_appointments&nonce={$nonce}&start=' + info.startStr + '&end=' + info.endStr)
                .then(function (r) { return r.json(); })
                .then(successCallback)
                .catch(failureCallback);
        },
        eventClick: function (info) {
            var orderId = info.event.extendedProps.order_id;
            if (orderId) window.location.href = '/wp-admin/post.php?post=' + orderId + '&action=edit';
        },
    });

    calendar.render();
});
JS;
    }

    public static function ajaxGetAppointments(): void
    {
        check_ajax_referer('bookgo_calendar', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.', 403);

        $start    = sanitize_text_field($_GET['start'] ?? '');
        $end      = sanitize_text_field($_GET['end']   ?? '');
        $statuses = ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed'];

        $args = [
            'limit'      => -1,
            'status'     => $statuses,
            'meta_query' => [['key' => 'bookgo_date', 'compare' => 'EXISTS']],
        ];

        if ($start) $args['meta_query'][] = ['key' => 'bookgo_date', 'value' => substr($start, 0, 10), 'compare' => '>=', 'type' => 'DATE'];
        if ($end)   $args['meta_query'][] = ['key' => 'bookgo_date', 'value' => substr($end,   0, 10), 'compare' => '<=', 'type' => 'DATE'];

        $orders = wc_get_orders($args);
        $statusColors = ['wc-pending' => '#f1c40f', 'wc-processing' => '#0073aa', 'wc-on-hold' => '#e67e22', 'wc-completed' => '#2ecc71'];
        $events = [];

        foreach ($orders as $order) {
            $date = $order->get_meta('bookgo_date');
            $time = $order->get_meta('bookgo_time');
            if (!$date) continue;

            $start_iso = $time ? "{$date}T{$time}" : $date;
            $name      = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $products  = implode(', ', array_map(fn(\WC_Order_Item $i) => $i->get_name(), array_values($order->get_items())));
            $color     = $statusColors['wc-' . $order->get_status()] ?? '#95a5a6';

            $end_iso = null;
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product && $product->is_type('bookgo') && $time) {
                    $duration = intval(get_post_meta($product->get_id(), '_bookgo_duration', true)) ?: 60;
                    $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", wp_timezone());
                    if ($dt) $end_iso = $dt->modify("+{$duration} minutes")->format('Y-m-d\TH:i');
                    break;
                }
            }

            $events[] = [
                'id'            => $order->get_id(),
                'title'         => $name . ($products ? ' — ' . $products : ''),
                'start'         => $start_iso,
                'end'           => $end_iso,
                'color'         => $color,
                'extendedProps' => ['order_id' => $order->get_id()],
            ];
        }

        wp_send_json($events);
    }
}
