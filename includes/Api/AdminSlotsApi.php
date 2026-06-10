<?php
namespace BookGo\Api;

use DateTimeImmutable;
use BookGo\Admin\CalendarManager;
use BookGo\Admin\ProductSlots;
use BookGo\Admin\Settings;

if (!defined('ABSPATH')) exit;

class AdminSlotsApi
{
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route('bookgo/v1', '/admin/day', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'getDay'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args'                => [
                'date'        => ['required' => true,  'sanitize_callback' => 'sanitize_text_field'],
                'calendar_id' => ['required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('bookgo/v1', '/admin/slot', [
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'addSlot'],
                'permission_callback' => [self::class, 'checkPermission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'deleteSlot'],
                'permission_callback' => [self::class, 'checkPermission'],
            ],
        ]);
    }

    public static function checkPermission(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    // ── GET /admin/day ────────────────────────────────────────────────────────

    public static function getDay(\WP_REST_Request $request): \WP_REST_Response
    {
        $date        = $request->get_param('date');
        $calendar_id = $request->get_param('calendar_id');
        $tz          = wp_timezone();

        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return new \WP_REST_Response(['error' => 'Invalid date'], 400);
        }

        $statuses = apply_filters('bookgo_conflict_statuses', Settings::conflictStatuses());

        // Products in scope
        $scopedIds = $calendar_id
            ? CalendarManager::getProductIds($calendar_id)
            : self::getAllBookgoProductIds();

        // All bookgo product IDs (for "add slot" form dropdown)
        $allProducts = self::buildProductsList();

        // ── Booked orders ────────────────────────────────────────────────────

        $orders = wc_get_orders([
            'limit'      => -1,
            'status'     => $statuses,
            'meta_query' => [
                ['key' => 'bookgo_date', 'value' => $date],
                ['key' => 'bookgo_time', 'compare' => 'EXISTS'],
            ],
        ]);

        $bookedIntervals = [];
        $slots           = [];

        foreach ($orders as $order) {
            $time = $order->get_meta('bookgo_time');
            if (!$time) continue;

            $bookedProduct = null;
            foreach ($order->get_items() as $item) {
                $p = $item->get_product();
                if ($p && $p->is_type('bookgo')) { $bookedProduct = $p; break; }
            }
            if (!$bookedProduct) continue;

            $pid = $bookedProduct->get_id();
            if (!empty($scopedIds) && !in_array($pid, $scopedIds, true)) continue;

            $duration = intval(get_post_meta($pid, '_bookgo_duration', true)) ?: 60;
            $dtStart  = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz);
            if (!$dtStart) continue;
            $dtEnd = $dtStart->modify("+{$duration} minutes");

            $bookedIntervals[] = [
                'ts_start'   => $dtStart->getTimestamp(),
                'ts_end'     => $dtEnd->getTimestamp(),
                'product_id' => $pid,
            ];

            $slots[] = [
                'product_id'   => $pid,
                'product_name' => $bookedProduct->get_name(),
                'time_start'   => $time,
                'time_end'     => $dtEnd->format('H:i'),
                'duration'     => $duration,
                'type'         => 'booked',
                'status'       => $order->get_status(),
                'order_id'     => $order->get_id(),
                'customer'     => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'can_delete'   => false,
            ];
        }

        // ── Available slots ───────────────────────────────────────────────────

        foreach ($scopedIds as $pid) {
            $productSlots = ProductSlots::getSlots($pid);
            $duration     = intval(get_post_meta($pid, '_bookgo_duration', true)) ?: 60;
            $prodName     = get_the_title($pid);

            foreach ($productSlots as $slot) {
                if (($slot['date'] ?? '') !== $date) continue;
                $time = $slot['time'] ?? '';
                if (!$time) continue;

                $dtStart = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz);
                if (!$dtStart) continue;

                $dtEnd   = $dtStart->modify("+{$duration} minutes");
                $tsStart = $dtStart->getTimestamp();
                $tsEnd   = $dtEnd->getTimestamp();

                // Skip if already emitted as booked
                $isBooked = false;
                foreach ($bookedIntervals as $interval) {
                    if ($interval['product_id'] === $pid && $interval['ts_start'] === $tsStart) {
                        $isBooked = true;
                        break;
                    }
                }
                if ($isBooked) continue;

                // Conflict check
                $conflicted = false;
                foreach ($bookedIntervals as $interval) {
                    if ($tsStart < $interval['ts_end'] && $tsEnd > $interval['ts_start']) {
                        $conflicted = true;
                        break;
                    }
                }

                $slots[] = [
                    'product_id'   => $pid,
                    'product_name' => $prodName,
                    'time_start'   => $time,
                    'time_end'     => $dtEnd->format('H:i'),
                    'duration'     => $duration,
                    'type'         => $conflicted ? 'conflict' : 'available',
                    'can_delete'   => true,
                ];
            }
        }

        usort($slots, fn($a, $b) => strcmp($a['time_start'], $b['time_start']));

        return new \WP_REST_Response([
            'date'             => $date,
            'slots'            => $slots,
            'products'         => $allProducts,
            'booked_intervals' => $bookedIntervals,
        ]);
    }

    // ── POST /admin/slot ──────────────────────────────────────────────────────

    public static function addSlot(\WP_REST_Request $request): \WP_REST_Response
    {
        $product_id = intval($request->get_param('product_id'));
        $date       = sanitize_text_field($request->get_param('date')  ?? '');
        $time       = sanitize_text_field($request->get_param('time')  ?? '');

        if (!$product_id || !$date || !$time) {
            return new \WP_REST_Response(['error' => 'Missing params'], 400);
        }

        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return new \WP_REST_Response(['error' => 'Invalid date'], 400);
        }
        $t = \DateTime::createFromFormat('H:i', $time);
        if (!$t || $t->format('H:i') !== $time) {
            return new \WP_REST_Response(['error' => 'Invalid time'], 400);
        }

        $slots = ProductSlots::getSlots($product_id);

        foreach ($slots as $slot) {
            if ($slot['date'] === $date && $slot['time'] === $time) {
                return new \WP_REST_Response(['error' => 'Slot already exists'], 409);
            }
        }

        $slots[] = ['date' => $date, 'time' => $time, 'capacity' => 1];
        usort($slots, fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
        update_post_meta($product_id, '_bookgo_slots', $slots);

        return new \WP_REST_Response(['success' => true], 201);
    }

    // ── DELETE /admin/slot ────────────────────────────────────────────────────

    public static function deleteSlot(\WP_REST_Request $request): \WP_REST_Response
    {
        $product_id = intval($request->get_param('product_id'));
        $date       = sanitize_text_field($request->get_param('date')  ?? '');
        $time       = sanitize_text_field($request->get_param('time')  ?? '');

        if (!$product_id || !$date || !$time) {
            return new \WP_REST_Response(['error' => 'Missing params'], 400);
        }

        $slots  = ProductSlots::getSlots($product_id);
        $before = count($slots);
        $slots  = array_values(array_filter($slots, fn($s) => !($s['date'] === $date && $s['time'] === $time)));

        if (count($slots) === $before) {
            return new \WP_REST_Response(['error' => 'Slot not found'], 404);
        }

        update_post_meta($product_id, '_bookgo_slots', $slots);
        return new \WP_REST_Response(['success' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function getAllBookgoProductIds(): array
    {
        return array_map('intval', get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'tax_query'      => [
                ['taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'bookgo'],
            ],
        ]));
    }

    private static function buildProductsList(): array
    {
        $products = [];
        foreach (self::getAllBookgoProductIds() as $pid) {
            $calId   = get_post_meta($pid, '_bookgo_calendar_id', true) ?: '';
            $calName = '';
            if ($calId) {
                $cal     = CalendarManager::getCalendar($calId);
                $calName = $cal ? $cal['name'] : '';
            }
            $products[] = [
                'id'            => $pid,
                'name'          => get_the_title($pid),
                'duration'      => intval(get_post_meta($pid, '_bookgo_duration', true)) ?: 60,
                'calendar_id'   => $calId,
                'calendar_name' => $calName,
            ];
        }
        return $products;
    }
}
