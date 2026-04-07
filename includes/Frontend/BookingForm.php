<?php
namespace BookGo\Frontend;

if (!defined('ABSPATH')) exit;

class BookingForm
{
    public static function init(): void
    {
        add_shortcode('bookgo_form', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function enqueueAssets(): void
    {
        wp_enqueue_style('bookgo-form', plugins_url('assets/booking-form.css', BOOKGO_PLUGIN_FILE), [], BOOKGO_VERSION);
        wp_enqueue_script('bookgo-form', plugins_url('assets/booking-form.js', BOOKGO_PLUGIN_FILE), ['jquery'], BOOKGO_VERSION, true);
        wp_localize_script('bookgo-form', 'BookGo', [
            'apiBase' => esc_url(rest_url('bookgo/v1')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'i18n'    => [
                'loading'     => __('Ładowanie…', 'bookgo'),
                'noSlots'     => __('Brak dostępnych terminów.', 'bookgo'),
                'noHours'     => __('Brak wolnych godzin w tym dniu.', 'bookgo'),
                'redirecting' => __('Przechodzę do koszyka…', 'bookgo'),
                'errorDates'  => __('Błąd ładowania terminów.', 'bookgo'),
                'errorHours'  => __('Błąd ładowania godzin.', 'bookgo'),
            ],
        ]);
    }

    public static function render(array $atts = []): string
    {
        global $product;

        if (!$product || !is_a($product, 'WC_Product')) {
            $atts    = shortcode_atts(['product_id' => get_the_ID()], $atts);
            $product = wc_get_product(intval($atts['product_id']));
        }

        if (!$product || !is_a($product, 'WC_Product')) {
            return '<p>' . esc_html__('Nie udało się załadować produktu.', 'bookgo') . '</p>';
        }

        if (!$product->is_type('bookgo')) {
            return '<p>' . esc_html__('Ten shortcode działa tylko dla produktu typu „Wizyta (BookGo)".', 'bookgo') . '</p>';
        }

        ob_start();
        ?>
        <div class="bookgo-form-wrapper" data-product-id="<?php echo esc_attr($product->get_id()); ?>">
            <div class="bookgo-dates-section">
                <p class="bookgo-loading"><?php esc_html_e('Ładowanie dostępnych terminów…', 'bookgo'); ?></p>
                <div class="bookgo-dates"></div>
            </div>
            <div class="bookgo-hours-section" style="display:none;">
                <h4><?php esc_html_e('Wybierz godzinę:', 'bookgo'); ?></h4>
                <ul class="bookgo-hours-list"></ul>
            </div>
            <button class="bookgo-submit button alt" disabled>
                <?php esc_html_e('Zarezerwuj', 'bookgo'); ?>
            </button>
            <p class="bookgo-result"></p>
        </div>
        <?php
        return ob_get_clean();
    }
}
