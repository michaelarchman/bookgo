<?php
namespace BookGo\Service;

use DateTimeImmutable;
use BookGo\Admin\CalendarManager;
use BookGo\Admin\ProductSlots;
use BookGo\Admin\Settings;

if (!defined('ABSPATH')) exit;

class BookingService
{
    public function getAvailableSlots(int $product_id, string $date): array
    {
        $tz  = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);

        if ($date < $now->format('Y-m-d')) return [];

        $allSlots = ProductSlots::getSlots($product_id);
        if (empty($allSlots)) return [];

        $duration        = max(1, intval(get_post_meta($product_id, '_bookgo_duration', true)) ?: 60);
        $calendar_id     = get_post_meta($product_id, '_bookgo_calendar_id', true) ?: null;
        $bookedIntervals = $this->getBookedIntervals($date, $calendar_id, $calendar_id ? null : $product_id);
        $available       = [];

        foreach ($allSlots as $slot) {
            if (($slot['date'] ?? '') !== $date) continue;
            $time = $slot['time'] ?? '';
            if (!$time) continue;

            $slotStart = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz);
            if (!$slotStart || $slotStart <= $now) continue;

            $slotEnd = $slotStart->modify("+{$duration} minutes");

            if ($this->countOverlappingBookings($slotStart, $slotEnd, $bookedIntervals) >= 1) continue;

            $available[] = [
                'start' => $time,
                'end'   => $slotEnd->format('H:i'),
            ];
        }

        return $available;
    }

    public function getAvailableDates(int $product_id): array
    {
        $tz          = wp_timezone();
        $now         = new DateTimeImmutable('now', $tz);
        $allSlots    = ProductSlots::getSlots($product_id);
        $duration    = max(1, intval(get_post_meta($product_id, '_bookgo_duration', true)) ?: 60);
        $calendar_id = get_post_meta($product_id, '_bookgo_calendar_id', true) ?: null;

        $slotsByDate = [];
        foreach ($allSlots as $slot) {
            $d = $slot['date'] ?? '';
            $t = $slot['time'] ?? '';
            if ($d && $t) $slotsByDate[$d][] = $slot;
        }

        $dates = [];
        foreach ($slotsByDate as $date => $slots) {
            if ($date < $now->format('Y-m-d')) continue;

            $bookedIntervals = $this->getBookedIntervals($date, $calendar_id, $calendar_id ? null : $product_id);

            foreach ($slots as $slot) {
                $time      = $slot['time'] ?? '';
                $slotStart = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz);
                if (!$slotStart || $slotStart <= $now) continue;

                $slotEnd = $slotStart->modify("+{$duration} minutes");

                if ($this->countOverlappingBookings($slotStart, $slotEnd, $bookedIntervals) < 1) {
                    $dates[$date] = true;
                    break;
                }
            }
        }

        $result = array_keys($dates);
        sort($result);
        return $result;
    }

    /**
     * Returns all booked intervals for a date.
     *
     * Scoping (mutually exclusive):
     *   $calendar_id   → intervals from all products in this calendar
     *   $product_id    → intervals from this product only
     *   both null      → all intervals (admin use only)
     *
     * Each entry: ['start' => DateTimeImmutable, 'end' => DateTimeImmutable, 'product_id' => int]
     */
    public function getBookedIntervals(string $date, ?string $calendar_id = null, ?int $product_id = null): array
    {
        $statuses = apply_filters('bookgo_conflict_statuses', Settings::conflictStatuses());
        $tz       = wp_timezone();

        $orders = wc_get_orders([
            'limit'      => -1,
            'status'     => $statuses,
            'meta_query' => [
                ['key' => 'bookgo_date', 'value' => $date],
                ['key' => 'bookgo_time', 'compare' => 'EXISTS'],
            ],
        ]);

        $calendarProductIds = $calendar_id ? CalendarManager::getProductIds($calendar_id) : null;

        $intervals = [];
        foreach ($orders as $order) {
            $time = $order->get_meta('bookgo_time');
            if (!$time) continue;

            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) continue;

                $pid = $product->get_id();

                if ($calendarProductIds !== null && !in_array($pid, $calendarProductIds, true)) continue;
                if ($product_id !== null && $pid !== $product_id) continue;

                $duration = max(1, intval(get_post_meta($pid, '_bookgo_duration', true)) ?: 60);
                $startDt  = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz);
                if (!$startDt) continue;

                $intervals[] = [
                    'start'      => $startDt,
                    'end'        => $startDt->modify("+{$duration} minutes"),
                    'product_id' => $pid,
                ];
            }
        }

        return $intervals;
    }

    /**
     * Overlap: A overlaps B when A_start < B_end AND A_end > B_start
     */
    private function countOverlappingBookings(
        DateTimeImmutable $slotStart,
        DateTimeImmutable $slotEnd,
        array $bookedIntervals
    ): int {
        $count = 0;
        foreach ($bookedIntervals as $interval) {
            if ($interval['start'] < $slotEnd && $interval['end'] > $slotStart) {
                $count++;
            }
        }
        return $count;
    }

    public function countBookingsForSlot(int $product_id, string $date, string $time): int
    {
        $tz        = wp_timezone();
        $duration  = max(1, intval(get_post_meta($product_id, '_bookgo_duration', true)) ?: 60);
        $slotStart = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz);
        if (!$slotStart) return 0;

        $calendar_id     = get_post_meta($product_id, '_bookgo_calendar_id', true) ?: null;
        $bookedIntervals = $this->getBookedIntervals($date, $calendar_id, $calendar_id ? null : $product_id);

        return $this->countOverlappingBookings($slotStart, $slotStart->modify("+{$duration} minutes"), $bookedIntervals);
    }

    public function isSlotAvailable(int $product_id, string $date, string $time): bool
    {
        foreach (ProductSlots::getSlots($product_id) as $slot) {
            if (($slot['date'] ?? '') !== $date || ($slot['time'] ?? '') !== $time) continue;
            return $this->countBookingsForSlot($product_id, $date, $time) < 1;
        }
        return false;
    }
}
