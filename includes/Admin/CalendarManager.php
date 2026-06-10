<?php
namespace BookGo\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Manages BookGo calendars — each calendar represents one resource (person, room, etc.).
 * Products assigned to the same calendar block each other on overlap.
 * Data stored in wp_options as bookgo_calendars (serialized array).
 */
class CalendarManager
{
    private const OPTION = 'bookgo_calendars';

    public static function init(): void
    {
        add_action('admin_menu',    [self::class, 'addMenuPage']);
        add_action('admin_post_bookgo_save_calendar',   [self::class, 'handleSave']);
        add_action('admin_post_bookgo_delete_calendar', [self::class, 'handleDelete']);
    }

    // ── Data ──────────────────────────────────────────────────────────────────

    public static function getCalendars(): array
    {
        $data = get_option(self::OPTION, []);
        return is_array($data) ? $data : [];
    }

    public static function getCalendar(string $id): ?array
    {
        foreach (self::getCalendars() as $cal) {
            if ($cal['id'] === $id) return $cal;
        }
        return null;
    }

    /**
     * Returns product IDs (int[]) assigned to a given calendar ID.
     */
    public static function getProductIds(string $calendar_id): array
    {
        $posts = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft', 'private'],
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_bookgo_calendar_id', 'value' => $calendar_id]],
        ]);
        return array_map('intval', $posts);
    }

    private static function saveCalendars(array $calendars): void
    {
        update_option(self::OPTION, array_values($calendars));
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public static function handleSave(): void
    {
        check_admin_referer('bookgo_calendar_save');
        if (!current_user_can('manage_woocommerce')) wp_die('Brak uprawnień.');

        $id    = sanitize_text_field($_POST['calendar_id']    ?? '');
        $name  = sanitize_text_field($_POST['calendar_name']  ?? '');
        $color = sanitize_hex_color($_POST['calendar_color']  ?? '#0073aa') ?: '#0073aa';

        if (!$name) {
            wp_safe_redirect(add_query_arg(['page' => 'bookgo-calendars', 'error' => 'empty_name'], admin_url('admin.php')));
            exit;
        }

        $calendars = self::getCalendars();

        if ($id) {
            foreach ($calendars as &$cal) {
                if ($cal['id'] === $id) { $cal['name'] = $name; $cal['color'] = $color; break; }
            }
        } else {
            $calendars[] = ['id' => uniqid('cal_', true), 'name' => $name, 'color' => $color];
        }

        self::saveCalendars($calendars);
        wp_safe_redirect(add_query_arg(['page' => 'bookgo-calendars', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public static function handleDelete(): void
    {
        check_admin_referer('bookgo_calendar_delete');
        if (!current_user_can('manage_woocommerce')) wp_die('Brak uprawnień.');

        $id        = sanitize_text_field($_POST['calendar_id'] ?? '');
        $calendars = array_filter(self::getCalendars(), fn($c) => $c['id'] !== $id);
        self::saveCalendars($calendars);

        wp_safe_redirect(add_query_arg(['page' => 'bookgo-calendars', 'deleted' => '1'], admin_url('admin.php')));
        exit;
    }

    // ── UI ────────────────────────────────────────────────────────────────────

    public static function addMenuPage(): void
    {
        add_submenu_page(
            'bookgo-calendar',
            __('Kalendarze (BookGo)', 'bookgo'),
            __('Kalendarze', 'bookgo'),
            'manage_woocommerce',
            'bookgo-calendars',
            [self::class, 'renderPage']
        );
    }

    public static function renderPage(): void
    {
        $calendars  = self::getCalendars();
        $edit_id    = sanitize_text_field($_GET['edit'] ?? '');
        $edit_cal   = $edit_id ? self::getCalendar($edit_id) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Kalendarze BookGo', 'bookgo'); ?></h1>

            <?php if (!empty($_GET['saved']))   echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Zapisano.', 'bookgo') . '</p></div>'; ?>
            <?php if (!empty($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Usunięto.', 'bookgo') . '</p></div>'; ?>
            <?php if (!empty($_GET['error']))   echo '<div class="notice notice-error"><p>' . esc_html__('Nazwa kalendarza nie może być pusta.', 'bookgo') . '</p></div>'; ?>

            <div style="display:flex;gap:32px;align-items:flex-start;margin-top:16px;">

                <!-- Calendar list -->
                <div style="flex:1;">
                    <?php if (empty($calendars)) : ?>
                        <p><?php esc_html_e('Brak kalendarzy. Utwórz pierwszy.', 'bookgo'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width:24px;"></th>
                                    <th><?php esc_html_e('Nazwa', 'bookgo'); ?></th>
                                    <th><?php esc_html_e('ID', 'bookgo'); ?></th>
                                    <th><?php esc_html_e('Produkty', 'bookgo'); ?></th>
                                    <th><?php esc_html_e('Akcje', 'bookgo'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($calendars as $cal) :
                                    $count = count(self::getProductIds($cal['id']));
                                ?>
                                <tr>
                                    <td><span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:<?php echo esc_attr($cal['color']); ?>;"></span></td>
                                    <td><strong><?php echo esc_html($cal['name']); ?></strong></td>
                                    <td><code style="font-size:11px;"><?php echo esc_html($cal['id']); ?></code></td>
                                    <td><?php echo intval($count); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(add_query_arg(['page' => 'bookgo-calendars', 'edit' => $cal['id']], admin_url('admin.php'))); ?>"><?php esc_html_e('Edytuj', 'bookgo'); ?></a>
                                        &nbsp;|&nbsp;
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e('Na pewno usunąć?', 'bookgo'); ?>');">
                                            <?php wp_nonce_field('bookgo_calendar_delete'); ?>
                                            <input type="hidden" name="action"      value="bookgo_delete_calendar">
                                            <input type="hidden" name="calendar_id" value="<?php echo esc_attr($cal['id']); ?>">
                                            <button type="submit" class="button-link" style="color:#a00;"><?php esc_html_e('Usuń', 'bookgo'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Add / Edit form -->
                <div style="min-width:280px;">
                    <h2><?php echo $edit_cal ? esc_html__('Edytuj kalendarz', 'bookgo') : esc_html__('Nowy kalendarz', 'bookgo'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('bookgo_calendar_save'); ?>
                        <input type="hidden" name="action"      value="bookgo_save_calendar">
                        <input type="hidden" name="calendar_id" value="<?php echo esc_attr($edit_cal['id'] ?? ''); ?>">
                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th><label for="calendar_name"><?php esc_html_e('Nazwa', 'bookgo'); ?></label></th>
                                <td><input type="text" id="calendar_name" name="calendar_name" value="<?php echo esc_attr($edit_cal['name'] ?? ''); ?>" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="calendar_color"><?php esc_html_e('Kolor', 'bookgo'); ?></label></th>
                                <td><input type="color" id="calendar_color" name="calendar_color" value="<?php echo esc_attr($edit_cal['color'] ?? '#0073aa'); ?>"></td>
                            </tr>
                        </table>
                        <p style="margin-top:12px;">
                            <?php submit_button($edit_cal ? __('Zapisz zmiany', 'bookgo') : __('Utwórz kalendarz', 'bookgo'), 'primary', 'submit', false); ?>
                            <?php if ($edit_cal) : ?>
                                <a href="<?php echo esc_url(add_query_arg(['page' => 'bookgo-calendars'], admin_url('admin.php'))); ?>" class="button" style="margin-left:8px;"><?php esc_html_e('Anuluj', 'bookgo'); ?></a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }
}
