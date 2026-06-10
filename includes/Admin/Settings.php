<?php
namespace BookGo\Admin;

if (!defined('ABSPATH')) exit;

class Settings
{
    public static function init(): void
    {
        add_action('admin_menu',  [self::class, 'addMenuPage']);
        add_action('admin_init',  [self::class, 'registerSettings']);
    }

    public static function addMenuPage(): void
    {
        add_submenu_page(
            'bookgo-calendar',
            __('Ustawienia (BookGo)', 'bookgo'),
            __('Ustawienia', 'bookgo'),
            'manage_woocommerce',
            'bookgo-settings',
            [self::class, 'renderPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting('bookgo_settings', 'bookgo_gcal_client_id', [
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('bookgo_settings', 'bookgo_gcal_client_secret', [
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('bookgo_settings', 'bookgo_auto_inject_form', [
            'sanitize_callback' => fn($v) => $v ? '1' : '0',
            'default'           => '1',
        ]);

        register_setting('bookgo_settings', 'bookgo_slot_time_step', [
            'sanitize_callback' => fn($v) => in_array((int) $v, [15, 30, 60], true) ? (string)(int) $v : '30',
            'default'           => '30',
        ]);

        register_setting('bookgo_settings', 'bookgo_conflict_statuses', [
            'sanitize_callback' => [self::class, 'sanitizeStatuses'],
            'default'           => ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed'],
        ]);
    }

    public static function sanitizeStatuses($value): array
    {
        $allowed = ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled'];
        return array_values(array_filter((array) $value, fn($s) => in_array($s, $allowed, true)));
    }

    /** Returns statuses that block a slot (used in BookingService & admin calendar). */
    public static function conflictStatuses(): array
    {
        $saved = get_option('bookgo_conflict_statuses', []);
        return !empty($saved) ? (array) $saved : ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed'];
    }

    /** Returns time step (minutes) for admin slot-creator modal. */
    public static function timeStep(): int
    {
        return max(15, (int) get_option('bookgo_slot_time_step', 30));
    }

    /** Returns whether the booking form auto-injects on bookgo product pages. */
    public static function autoInjectForm(): bool
    {
        return get_option('bookgo_auto_inject_form', '1') === '1';
    }

    public static function renderPage(): void
    {
        $savedStatuses = self::conflictStatuses();
        $step          = self::timeStep();
        $gcal_client_id     = get_option('bookgo_gcal_client_id', '');
        $gcal_client_secret = get_option('bookgo_gcal_client_secret', '');
        $redirect_uri       = admin_url('admin.php?page=bookgo-calendars&bookgo_gcal_callback=1');
        $allStatuses   = [
            'wc-pending'    => __('Oczekuje na płatność', 'bookgo'),
            'wc-processing' => __('W realizacji', 'bookgo'),
            'wc-on-hold'    => __('Wstrzymane', 'bookgo'),
            'wc-completed'  => __('Zakończone', 'bookgo'),
            'wc-cancelled'  => __('Anulowane', 'bookgo'),
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Ustawienia BookGo', 'bookgo'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('bookgo_settings'); ?>
                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row"><?php esc_html_e('Google OAuth — Client ID', 'bookgo'); ?></th>
                        <td>
                            <input type="text" name="bookgo_gcal_client_id" value="<?php echo esc_attr($gcal_client_id); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Z Google Cloud Console → APIs & Services → Credentials.', 'bookgo'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Google OAuth — Client Secret', 'bookgo'); ?></th>
                        <td>
                            <input type="password" name="bookgo_gcal_client_secret" value="<?php echo esc_attr($gcal_client_secret); ?>" class="regular-text">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Google OAuth — Redirect URI', 'bookgo'); ?></th>
                        <td>
                            <code><?php echo esc_html($redirect_uri); ?></code>
                            <p class="description"><?php esc_html_e('Dodaj ten URI w Google Cloud Console → Credentials → Authorized redirect URIs.', 'bookgo'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Formularz rezerwacji', 'bookgo'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="bookgo_auto_inject_form" value="1"
                                    <?php checked(self::autoInjectForm()); ?>>
                                <?php esc_html_e('Automatycznie wyświetlaj formularz rezerwacji na stronie produktu BookGo (zastępuje przycisk "Dodaj do koszyka")', 'bookgo'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Krok czasu w kreatorze slotów', 'bookgo'); ?></th>
                        <td>
                            <?php foreach ([15, 30, 60] as $s) : ?>
                                <label style="margin-right:20px;">
                                    <input type="radio" name="bookgo_slot_time_step" value="<?php echo esc_attr($s); ?>"
                                        <?php checked($step, $s); ?>>
                                    <?php echo esc_html($s); ?> min
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e('Dotyczy dropdownu godzin w panelu admina (Wizyty → klik w dzień).', 'bookgo'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Statusy blokujące slot', 'bookgo'); ?></th>
                        <td>
                            <?php foreach ($allStatuses as $status => $label) : ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox" name="bookgo_conflict_statuses[]"
                                        value="<?php echo esc_attr($status); ?>"
                                        <?php checked(in_array($status, $savedStatuses, true)); ?>>
                                    <?php echo esc_html($label); ?>
                                    <code style="margin-left:4px;"><?php echo esc_html($status); ?></code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e('Zamówienia z tymi statusami blokują termin dla innych klientów i w widoku kalendarza.', 'bookgo'); ?></p>
                        </td>
                    </tr>

                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
