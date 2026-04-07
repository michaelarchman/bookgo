<?php
/**
 * Plugin Name: BookGo
 * Description: System rezerwacji wizyt zintegrowany z WooCommerce — typ produktu „Wizyta (BookGo)".
 * Version:     1.0.0
 * Author:      Michał Łuczak
 * Text Domain: bookgo
 */

namespace BookGo;

if (!defined('ABSPATH')) exit;

use BookGo\Admin\AppointmentsCalendar;
use BookGo\Admin\ProductSlots;
use BookGo\Api\AppointmentsApi;
use BookGo\Frontend\BookingForm;
use BookGo\WooCommerce\BookingProductType;
use BookGo\WooCommerce\Hooks;

define('BOOKGO_VERSION',     '1.0.0');
define('BOOKGO_PLUGIN_FILE', __FILE__);
define('BOOKGO_PLUGIN_DIR',  plugin_dir_path(__FILE__));

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'BookGo\\') !== 0) return;
    $file = BOOKGO_PLUGIN_DIR . 'includes/' . str_replace(['BookGo\\', '\\'], ['', DIRECTORY_SEPARATOR], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

function table_name(): string
{
    global $wpdb;
    return $wpdb->prefix . 'bookgo_appointments';
}

function activate(): void
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table   = table_name();
    $charset = $wpdb->get_charset_collate();
    dbDelta("
        CREATE TABLE {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NULL,
            product_id  BIGINT UNSIGNED NOT NULL,
            start_time  DATETIME        NOT NULL,
            end_time    DATETIME        NOT NULL,
            status      VARCHAR(20)     NOT NULL DEFAULT 'pending',
            notes       TEXT            NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_product_time (product_id, start_time, end_time)
        ) {$charset};
    ");
}
register_activation_hook(BOOKGO_PLUGIN_FILE, __NAMESPACE__ . '\activate');

add_action('plugins_loaded', function (): void {
    if (is_admin()) {
        AppointmentsCalendar::init();
        ProductSlots::init();
    }

    AppointmentsApi::register();
    BookingForm::init();

    add_action('init', function (): void {
        if (!class_exists('WooCommerce')) return;
        BookingProductType::init();
        Hooks::init();
    });
});
