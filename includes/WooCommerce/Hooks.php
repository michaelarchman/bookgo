<?php
namespace BookGo\WooCommerce;

use BookGo\Service\BookingService;

if (!defined('ABSPATH')) exit;

class Hooks
{
    public static function init(): void
    {
        add_filter('woocommerce_add_cart_item_data',               [self::class, 'injectCartItemData'], 10, 2);
        add_filter('woocommerce_add_to_cart_validation',           [self::class, 'validateSlot'], 10, 2);
        add_filter('woocommerce_get_item_data',                    [self::class, 'displayCartItemData'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item',  [self::class, 'saveOrderItemMeta'], 10, 3);
        add_action('woocommerce_checkout_order_created',           [self::class, 'saveOrderMeta']);
    }

    public static function injectCartItemData(array $cart_item_data, int $product_id): array
    {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('bookgo')) return $cart_item_data;

        $date = sanitize_text_field(wp_unslash($_GET['bookgo_date'] ?? ''));
        $time = sanitize_text_field(wp_unslash($_GET['bookgo_time'] ?? ''));

        if ($date) $cart_item_data['bookgo_date'] = $date;
        if ($time) $cart_item_data['bookgo_time'] = $time;

        return $cart_item_data;
    }

    public static function validateSlot(bool $passed, int $product_id): bool
    {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('bookgo')) return $passed;

        $date = sanitize_text_field(wp_unslash($_GET['bookgo_date'] ?? ''));
        $time = sanitize_text_field(wp_unslash($_GET['bookgo_time'] ?? ''));

        if (!$date || !$time) {
            wc_add_notice(__('Wybierz datę i godzinę wizyty.', 'bookgo'), 'error');
            return false;
        }

        $service = new BookingService();
        if (!$service->isSlotAvailable($product_id, $date, $time)) {
            wc_add_notice(__('Wybrany termin jest już zajęty. Wybierz inny.', 'bookgo'), 'error');
            return false;
        }

        return $passed;
    }

    public static function displayCartItemData(array $item_data, array $cart_item): array
    {
        if (!empty($cart_item['bookgo_date'])) {
            $item_data[] = ['key' => __('Data wizyty', 'bookgo'),    'value' => wc_clean($cart_item['bookgo_date'])];
        }
        if (!empty($cart_item['bookgo_time'])) {
            $item_data[] = ['key' => __('Godzina wizyty', 'bookgo'), 'value' => wc_clean($cart_item['bookgo_time'])];
        }
        return $item_data;
    }

    public static function saveOrderItemMeta(\WC_Order_Item_Product $item, string $cart_item_key, array $values): void
    {
        if (!empty($values['bookgo_date'])) {
            $item->add_meta_data(__('Data wizyty', 'bookgo'),    wc_clean($values['bookgo_date']), true);
        }
        if (!empty($values['bookgo_time'])) {
            $item->add_meta_data(__('Godzina wizyty', 'bookgo'), wc_clean($values['bookgo_time']), true);
        }
    }

    public static function saveOrderMeta(\WC_Order $order): void
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product || !$product->is_type('bookgo')) continue;

            $date = $item->get_meta(__('Data wizyty', 'bookgo'));
            $time = $item->get_meta(__('Godzina wizyty', 'bookgo'));

            if ($date) $order->update_meta_data('bookgo_date', wc_clean($date));
            if ($time) $order->update_meta_data('bookgo_time', wc_clean($time));
        }
        $order->save();
    }
}
