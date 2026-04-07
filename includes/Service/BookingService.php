<?php
namespace BookGo\Service;

use DateTimeImmutable;
use BookGo\Admin\ProductSlots;

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

        $duration  = max(1, intval(get_post_meta($product_id, '_bookgo_duration', true)) ?: 60);
        $available = [];

        foreach ($allSlots as $slot) {
            if (($slot['date'] ?? '') !== $date) continue;
            $time = $slot['time'] ?? '';
            if (!$time) continue;

            $slotDt = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz);
            if (!$slotDt || $slotDt <= $now) continue;

            $capacity = max(1, intval($slot['capacity'] ?? 1));
            if ($this->countBookingsForSlot($product_id, $date, $time) >= $capacity) continue;

            $available[] = [
                'start' => $time,
                'end'   => $slotDt->modify("+{$duration} minutes")->format('H:i'),
            ];
        }

        return $available;
    }

    public function getAvailableDates(int $product_id): array
    {
        $tz       = wp_timezone();
        $now      = new DateTimeImmutable('now', $tz);
        $allSlots = ProductSlots::getSlots($product_id);
        $dates    = [];

        foreach ($allSlots as $slot) {
            $date = $slot['date'] ?? '';
            $time = $slot['time'] ?? '';
            if (!$date || !$time) continue;

            $slotDt = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", $tz);
            if (!$slotDt || $slotDt <= $now) continue;

            $capacity = max(1, intval($slot['capacity'] ?? 1));
            if ($this->countBookingsForSlot($product_id, $date, $time) < $capacity) {
                $dates[$date] = true;
            }
        }

        $result = array_keys($dates);
        sort($result);
        return $result;
    }

    public function countBookingsForSlot(int $product_id, string $date, string $time): int
    {
        $statuses = apply_filters('bookgo_conflict_statuses', ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed']);

        $orders = wc_get_orders([
            'limit'      => -1,
            'status'     => $statuses,
            'meta_query' => [
                ['key' => 'bookgo_date', 'value' => $date],
                ['key' => 'bookgo_time', 'value' => $time],
            ],
        ]);

        $count = 0;
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product && $product->get_id() === $product_id) { $count++; break; }
            }
        }

        return $count;
    }

    public function isSlotAvailable(int $product_id, string $date, string $time): bool
    {
        foreach (ProductSlots::getSlots($product_id) as $slot) {
            if (($slot['date'] ?? '') !== $date || ($slot['time'] ?? '') !== $time) continue;
            return $this->countBookingsForSlot($product_id, $date, $time) < max(1, intval($slot['capacity'] ?? 1));
        }
        return false;
    }
}
