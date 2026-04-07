<?php
namespace BookGo\Admin;

if (!defined('ABSPATH')) exit;

/**
 * ShareLinks — product-level share links attached to WooCommerce products.
 *
 * Admin:   product data field + save handler + inline JS/CSS.
 * Front:   renders on the order details page, thank-you page,
 *          and in customer emails.
 */
class ShareLinks
{
    public static function init(): void
    {
        // Admin
        add_action('woocommerce_product_options_general_product_data', [self::class, 'renderField']);
        add_action('woocommerce_admin_process_product_object',         [self::class, 'saveField']);
        add_action('admin_footer',                                     [self::class, 'adminAssets']);

        // Frontend
        add_action('woocommerce_order_details_after_order_table', [self::class, 'renderOrderSection'], 20);
        add_action('woocommerce_thankyou',                        [self::class, 'renderThankyouSection'], 20);
        add_action('woocommerce_email_after_order_table',         [self::class, 'renderEmailSection'], 20, 4);
    }

    // -------------------------------------------------------------------------
    // Admin: product-level field
    // -------------------------------------------------------------------------

    public static function renderField(): void
    {
        global $post;

        $links = get_post_meta($post->ID, '_bookgo_share_links', true);
        if (!is_array($links)) {
            $links = [];
        }

        echo '<div class="options_group show_if_simple show_if_external show_if_variable show_if_bookgo bookgo-share-links-wrapper">';
        echo '<p class="form-field"><label style="font-weight:600;">' . esc_html__('Linki do udostępnienia', 'bookgo') . '</label></p>';

        echo '<div class="form-field bookgo_share_links_field">';
        echo '<table class="widefat wc_input_table sortable" cellspacing="0">';
        echo '<thead><tr>';
        echo '<th class="sort">&nbsp;</th>';
        echo '<th style="width:40%;">' . esc_html__('Nazwa', 'bookgo') . '</th>';
        echo '<th style="width:58%;">' . esc_html__('Adres URL linku', 'bookgo') . '</th>';
        echo '<th style="width:1%;">&nbsp;</th>';
        echo '</tr></thead>';
        echo '<tbody id="bookgo-share-links-rows">';

        foreach ($links as $link) {
            $name = isset($link['name']) ? esc_attr($link['name']) : '';
            $url  = isset($link['url'])  ? esc_attr($link['url'])  : '';
            self::renderRow($name, $url);
        }

        echo '</tbody>';
        echo '<tfoot><tr><th colspan="4">';
        echo '<a href="#" class="button bookgo-add-share-link">' . esc_html__('Dodaj link', 'bookgo') . '</a>';
        echo '</th></tr></tfoot>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
    }

    private static function renderRow(string $name = '', string $url = ''): void
    {
        echo '<tr>';
        echo '<td class="sort">&#8597;</td>';
        echo '<td><input type="text" class="input_text" name="bookgo_share_link_names[]" value="' . $name . '" placeholder="' . esc_attr__('Nazwa linku', 'bookgo') . '" /></td>';
        echo '<td><input type="text" class="input_text" name="bookgo_share_link_urls[]"  value="' . $url  . '" placeholder="https://" /></td>';
        echo '<td><a href="#" class="button bookgo-remove-share-link">' . esc_html__('Usuń', 'bookgo') . '</a></td>';
        echo '</tr>';
    }

    public static function saveField(\WC_Product $product): void
    {
        $names = isset($_POST['bookgo_share_link_names']) ? (array) $_POST['bookgo_share_link_names'] : [];
        $urls  = isset($_POST['bookgo_share_link_urls'])  ? (array) $_POST['bookgo_share_link_urls']  : [];

        $links = [];
        $count = max(count($names), count($urls));

        for ($i = 0; $i < $count; $i++) {
            $name = isset($names[$i]) ? sanitize_text_field(wp_unslash($names[$i])) : '';
            $url  = isset($urls[$i])  ? esc_url_raw(wp_unslash($urls[$i]))          : '';

            if ($url === '') {
                continue;
            }

            $links[] = ['name' => $name, 'url' => $url];
        }

        $product->update_meta_data('_bookgo_share_links', $links);
    }

    public static function adminAssets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product') {
            return;
        }
        ?>
        <script>
        jQuery(function ($) {
            function bookgoShareLinkRow() {
                return '<tr>' +
                    '<td class="sort">&#8597;</td>' +
                    '<td><input type="text" class="input_text" name="bookgo_share_link_names[]" value="" placeholder="<?php echo esc_js(__('Nazwa linku', 'bookgo')); ?>" /></td>' +
                    '<td><input type="text" class="input_text" name="bookgo_share_link_urls[]"  value="" placeholder="https://" /></td>' +
                    '<td><a href="#" class="button bookgo-remove-share-link"><?php echo esc_js(__('Usuń', 'bookgo')); ?></a></td>' +
                    '</tr>';
            }

            $(document).on('click', '.bookgo-add-share-link', function (e) {
                e.preventDefault();
                $('#bookgo-share-links-rows').append(bookgoShareLinkRow());
            });

            $(document).on('click', '.bookgo-remove-share-link', function (e) {
                e.preventDefault();
                $(this).closest('tr').remove();
            });
        });
        </script>
        <style>
            .bookgo_share_links_field table th,
            .bookgo_share_links_field table td { vertical-align: middle; }
            .bookgo_share_links_field .input_text { width: 100%; }
            .bookgo-share-links-wrapper .form-field > label { width: 150px; }
            .bookgo_share_links_field { padding: 5px 9px 5px 162px; box-sizing: border-box; }
            .bookgo_share_links_field table.widefat { margin: 0; }
            .bookgo_share_links_field .sort { width: 24px; text-align: center; cursor: move; }
        </style>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns all share links attached to products in the order.
     *
     * @return array{ product_name: string, name: string, url: string }[]
     */
    public static function getOrderLinks(\WC_Order $order): array
    {
        $result = [];

        foreach ($order->get_items() as $item) {
            $productId = $item->get_product_id();
            if (!$productId) {
                continue;
            }

            $links = get_post_meta($productId, '_bookgo_share_links', true);
            if (!is_array($links) || empty($links)) {
                continue;
            }

            $productName = $item->get_name();

            foreach ($links as $link) {
                $url  = $link['url']  ?? '';
                $name = ($link['name'] ?? '') !== '' ? $link['name'] : __('Otwórz link', 'bookgo');

                if ($url === '') {
                    continue;
                }

                $result[] = [
                    'product_name' => $productName,
                    'name'         => $name,
                    'url'          => $url,
                ];
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Frontend: order details page
    // -------------------------------------------------------------------------

    public static function renderOrderSection(\WC_Order $order): void
    {
        $links = self::getOrderLinks($order);
        if (empty($links)) {
            return;
        }

        echo '<section class="woocommerce-order-share-links">';
        echo '<h2>' . esc_html__('Linki do udostępnienia', 'bookgo') . '</h2>';
        echo '<table class="shop_table shop_table_responsive my_account_orders">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Produkt', 'bookgo') . '</th>';
        echo '<th>' . esc_html__('Link', 'bookgo') . '</th>';
        echo '<th>' . esc_html__('Akcja', 'bookgo') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($links as $link) {
            echo '<tr>';
            echo '<td>' . esc_html($link['product_name']) . '</td>';
            echo '<td>' . esc_html($link['name']) . '</td>';
            echo '<td><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Otwórz link', 'bookgo') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table></section>';
    }

    // -------------------------------------------------------------------------
    // Frontend: thank-you page
    // -------------------------------------------------------------------------

    public static function renderThankyouSection(int $orderId): void
    {
        if (!$orderId || is_wc_endpoint_url('order-received')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $links = self::getOrderLinks($order);
        if (empty($links)) {
            return;
        }

        echo '<section class="woocommerce-order-share-links" style="margin-top:24px;">';
        echo '<h2>' . esc_html__('Linki do udostępnienia', 'bookgo') . '</h2>';
        echo '<ul>';

        foreach ($links as $link) {
            echo '<li>';
            echo esc_html($link['product_name']) . ' &mdash; ';
            echo '<a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link['name']) . '</a>';
            echo '</li>';
        }

        echo '</ul></section>';
    }

    // -------------------------------------------------------------------------
    // Frontend: customer email
    // -------------------------------------------------------------------------

    /**
     * @param \WC_Order  $order
     * @param bool       $sentToAdmin
     * @param bool       $plainText
     * @param \WC_Email  $email
     */
    public static function renderEmailSection($order, bool $sentToAdmin, bool $plainText, $email): void
    {
        if ($sentToAdmin || !$order instanceof \WC_Order) {
            return;
        }

        $links = self::getOrderLinks($order);
        if (empty($links)) {
            return;
        }

        if ($plainText) {
            echo "\n" . strtoupper(__('Linki do udostępnienia', 'bookgo')) . "\n";
            foreach ($links as $link) {
                echo $link['product_name'] . ' - ' . $link['name'] . ': ' . $link['url'] . "\n";
            }
            echo "\n";
            return;
        }

        echo '<h2>' . esc_html__('Linki do udostępnienia', 'bookgo') . '</h2>';
        echo '<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:20px;" border="1">';
        echo '<thead><tr>';
        echo '<th style="text-align:left;">' . esc_html__('Produkt', 'bookgo') . '</th>';
        echo '<th style="text-align:left;">' . esc_html__('Link', 'bookgo') . '</th>';
        echo '<th style="text-align:left;">' . esc_html__('Akcja', 'bookgo') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($links as $link) {
            echo '<tr>';
            echo '<td style="text-align:left;">' . esc_html($link['product_name']) . '</td>';
            echo '<td style="text-align:left;">' . esc_html($link['name']) . '</td>';
            echo '<td style="text-align:left;"><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Otwórz link', 'bookgo') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
