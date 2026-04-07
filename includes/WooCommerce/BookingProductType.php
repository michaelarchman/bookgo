<?php
namespace BookGo\WooCommerce;

if (!defined('ABSPATH')) exit;

class BookingProductType extends \WC_Product
{
    public function get_type(): string { return 'bookgo'; }
    public function is_virtual(): bool { return true; }
    public function is_downloadable(): bool { return false; }

    public static function init(): void
    {
        add_filter('product_type_selector',   [self::class, 'addType']);
        add_filter('woocommerce_product_class', [self::class, 'mapClass'], 10, 2);
        add_action('admin_footer',            [self::class, 'adminUiScript']);
    }

    public static function addType(array $types): array
    {
        $types['bookgo'] = __('Wizyta (BookGo)', 'bookgo');
        return $types;
    }

    public static function mapClass(string $class, string $product_type): string
    {
        return $product_type === 'bookgo' ? self::class : $class;
    }

    public static function adminUiScript(): void
    {
        global $post;
        if (!$post || $post->post_type !== 'product') return;
        ?>
        <script>
        jQuery(function ($) {
            function applyBookGoLayout() {
                if ($('#product-type').val() !== 'bookgo') return;

                $('.product_data_tabs .general_tab').show();
                $('.product_data_tabs .inventory_tab').show();
                $('.product_data_tabs .shipping_tab').hide();
                $('.product_data_tabs .linked_product_tab').hide();
                $('.product_data_tabs .attribute_tab').hide();
                $('.product_data_tabs .variations_tab').hide();

                $('.options_group.pricing').show();
                $('#_tax_status, #_tax_class').closest('.options_group').show();
            }

            function scheduleApply() { setTimeout(applyBookGoLayout, 1); }

            $('#product-type').on('change', scheduleApply);
            $('body').on('woocommerce-product-type-change', scheduleApply);
            scheduleApply();
        });
        </script>
        <?php
    }
}
