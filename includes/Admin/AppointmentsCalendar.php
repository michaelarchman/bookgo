<?php
namespace BookGo\Admin;

use DateTimeImmutable;
use BookGo\Admin\Settings;

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
        wp_enqueue_script('bookgo-admin-calendar', plugins_url('assets/admin-calendar.js', BOOKGO_PLUGIN_FILE), ['fullcalendar'], BOOKGO_VERSION, true);
        wp_localize_script('bookgo-admin-calendar', 'bookgoAdmin', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'adminUrl'  => admin_url(),
            'nonce'     => wp_create_nonce('bookgo_calendar'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'restUrl'   => rest_url('bookgo/v1/admin/'),
            'timeStep'  => Settings::timeStep(),
            'calendars' => CalendarManager::getCalendars(),
            'i18n'      => [
                'available'    => __('Dostępny', 'bookgo'),
                'conflict'     => __('Zablokowany', 'bookgo'),
                'booked'       => __('Zarezerwowany', 'bookgo'),
                'addSlot'      => __('Dodaj termin', 'bookgo'),
                'deleteSlot'   => __('Usuń', 'bookgo'),
                'confirmDelete'=> __('Usunąć ten termin?', 'bookgo'),
                'selectProduct'=> __('— wybierz produkt —', 'bookgo'),
                'noSlots'      => __('Brak terminów w tym dniu.', 'bookgo'),
                'conflictWarn' => __('⚠ Termin nachodzi na istniejącą wizytę!', 'bookgo'),
                'saving'       => __('Zapisywanie…', 'bookgo'),
                'close'        => __('Zamknij', 'bookgo'),
            ],
        ]);
        wp_enqueue_style('bookgo-admin-calendar', plugins_url('assets/admin-calendar.css', BOOKGO_PLUGIN_FILE), [], BOOKGO_VERSION);
    }

    public static function renderPage(): void
    {
        $calendars = CalendarManager::getCalendars();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wizyty (BookGo)', 'bookgo'); ?></h1>

            <div class="bookgo-cal-toolbar">
                <?php if (!empty($calendars)) : ?>
                <div class="bookgo-cal-filter">
                    <label for="bookgo-calendar-filter"><?php esc_html_e('Kalendarz:', 'bookgo'); ?></label>
                    <select id="bookgo-calendar-filter">
                        <option value=""><?php esc_html_e('— wszystkie —', 'bookgo'); ?></option>
                        <?php foreach ($calendars as $cal) : ?>
                            <option value="<?php echo esc_attr($cal['id']); ?>" data-color="<?php echo esc_attr($cal['color']); ?>">
                                <?php echo esc_html($cal['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="bookgo-cal-legend">
                    <span class="bookgo-legend-item"><span class="bookgo-legend-dot" style="background:#27ae60;"></span><?php esc_html_e('Dostępny', 'bookgo'); ?></span>
                    <span class="bookgo-legend-item"><span class="bookgo-legend-dot" style="background:#e74c3c;"></span><?php esc_html_e('Zablokowany', 'bookgo'); ?></span>
                    <span class="bookgo-legend-item"><span class="bookgo-legend-dot" style="background:#f1c40f;"></span><?php esc_html_e('Oczekuje', 'bookgo'); ?></span>
                    <span class="bookgo-legend-item"><span class="bookgo-legend-dot" style="background:#0073aa;"></span><?php esc_html_e('W realizacji', 'bookgo'); ?></span>
                    <span class="bookgo-legend-item"><span class="bookgo-legend-dot" style="background:#2ecc71;"></span><?php esc_html_e('Zakończona', 'bookgo'); ?></span>
                </div>
            </div>

            <div id="bookgo-calendar"></div>
        </div>
        <?php
    }

    public static function ajaxGetAppointments(): void
    {
        check_ajax_referer('bookgo_calendar', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.', 403);

        $startDate   = substr(sanitize_text_field($_GET['start'] ?? ''), 0, 10);
        $endDate     = substr(sanitize_text_field($_GET['end']   ?? ''), 0, 10);
        $calendar_id = sanitize_text_field($_GET['calendar_id'] ?? '');
        $statuses    = apply_filters('bookgo_conflict_statuses', Settings::conflictStatuses());
        $statusColors = [
            'wc-pending'    => '#f1c40f',
            'wc-processing' => '#0073aa',
            'wc-on-hold'    => '#e67e22',
            'wc-completed'  => '#2ecc71',
        ];
        $tz = wp_timezone();

        $calProductIds = $calendar_id ? CalendarManager::getProductIds($calendar_id) : null;

        // ── 1. Booked orders ─────────────────────────────────────────────────

        $orderArgs = [
            'limit'      => -1,
            'status'     => $statuses,
            'meta_query' => [['key' => 'bookgo_date', 'compare' => 'EXISTS']],
        ];
        if ($startDate) $orderArgs['meta_query'][] = ['key' => 'bookgo_date', 'value' => $startDate, 'compare' => '>=', 'type' => 'DATE'];
        if ($endDate)   $orderArgs['meta_query'][] = ['key' => 'bookgo_date', 'value' => $endDate,   'compare' => '<=', 'type' => 'DATE'];

        $orders          = wc_get_orders($orderArgs);
        $events          = [];
        $bookedIntervals = []; // date => [['ts_start', 'ts_end', 'product_id']]

        foreach ($orders as $order) {
            $date = $order->get_meta('bookgo_date');
            $time = $order->get_meta('bookgo_time');
            if (!$date) continue;

            $bookedProduct = null;
            foreach ($order->get_items() as $item) {
                $p = $item->get_product();
                if ($p && $p->is_type('bookgo')) { $bookedProduct = $p; break; }
            }

            if ($calProductIds !== null) {
                if (!$bookedProduct || !in_array($bookedProduct->get_id(), $calProductIds, true)) continue;
            }

            $startIso = $time ? "{$date}T{$time}" : $date;
            $endIso   = null;

            if ($bookedProduct && $time) {
                $duration = intval(get_post_meta($bookedProduct->get_id(), '_bookgo_duration', true)) ?: 60;
                $dtStart  = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz);
                if ($dtStart) {
                    $dtEnd  = $dtStart->modify("+{$duration} minutes");
                    $endIso = $dtEnd->format('Y-m-d\TH:i');

                    $bookedIntervals[$date][] = [
                        'ts_start'   => $dtStart->getTimestamp(),
                        'ts_end'     => $dtEnd->getTimestamp(),
                        'product_id' => $bookedProduct->get_id(),
                    ];
                }
            }

            $name     = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $products = implode(', ', array_map(fn(\WC_Order_Item $i) => $i->get_name(), array_values($order->get_items())));

            $events[] = [
                'id'            => 'order_' . $order->get_id(),
                'title'         => $name . ($products ? ' — ' . $products : ''),
                'start'         => $startIso,
                'end'           => $endIso,
                'color'         => $statusColors['wc-' . $order->get_status()] ?? '#95a5a6',
                'extendedProps' => [
                    'type'     => 'booked',
                    'order_id' => $order->get_id(),
                ],
            ];
        }

        // ── 2. Available slots ───────────────────────────────────────────────

        if ($calProductIds !== null) {
            $productIds = $calProductIds;
        } else {
            $productIds = array_map('intval', get_posts([
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'tax_query'      => [
                    ['taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'bookgo'],
                ],
            ]));
        }

        foreach ($productIds as $pid) {
            $slots    = ProductSlots::getSlots($pid);
            $duration = intval(get_post_meta($pid, '_bookgo_duration', true)) ?: 60;
            $prodName = get_the_title($pid);

            foreach ($slots as $slot) {
                $date = $slot['date'] ?? '';
                $time = $slot['time'] ?? '';
                if (!$date || !$time) continue;
                if ($startDate && $date < $startDate) continue;
                if ($endDate   && $date > $endDate)   continue;

                $dtStart = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz);
                if (!$dtStart) continue;

                $dtEnd   = $dtStart->modify("+{$duration} minutes");
                $tsStart = $dtStart->getTimestamp();
                $tsEnd   = $dtEnd->getTimestamp();

                // Skip — already displayed as booked order event
                $isBooked = false;
                foreach ($bookedIntervals[$date] ?? [] as $interval) {
                    if ($interval['product_id'] === $pid && $interval['ts_start'] === $tsStart) {
                        $isBooked = true;
                        break;
                    }
                }
                if ($isBooked) continue;

                // Check overlap with any booked interval
                $conflicted = false;
                foreach ($bookedIntervals[$date] ?? [] as $interval) {
                    if ($tsStart < $interval['ts_end'] && $tsEnd > $interval['ts_start']) {
                        $conflicted = true;
                        break;
                    }
                }

                $events[] = [
                    'id'            => 'slot_' . $pid . '_' . $date . '_' . str_replace(':', '', $time),
                    'title'         => $prodName . ' — ' . $time,
                    'start'         => $dtStart->format('Y-m-d\TH:i'),
                    'end'           => $dtEnd->format('Y-m-d\TH:i'),
                    'color'         => $conflicted ? '#e74c3c' : '#27ae60',
                    'extendedProps' => [
                        'type'       => $conflicted ? 'conflict' : 'available',
                        'product_id' => $pid,
                    ],
                ];
            }
        }

        wp_send_json($events);
    }
}
