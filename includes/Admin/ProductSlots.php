<?php
namespace BookGo\Admin;

if (!defined('ABSPATH')) exit;

class ProductSlots
{
    public static function init(): void
    {
        add_action('add_meta_boxes',                   [self::class, 'addMetaBox']);
        add_action('woocommerce_process_product_meta', [self::class, 'save'], 20);
        add_action('admin_enqueue_scripts',            [self::class, 'enqueueAssets']);
    }

    public static function enqueueAssets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        global $post;
        if (!$post || $post->post_type !== 'product') return;

        wp_enqueue_script('bookgo-product-slots', plugins_url('assets/product-slots.js', BOOKGO_PLUGIN_FILE), ['jquery'], BOOKGO_VERSION, true);
        wp_localize_script('bookgo-product-slots', 'bookgoL10n', ['remove' => __('Usuń', 'bookgo')]);
        wp_enqueue_style('bookgo-product-slots', plugins_url('assets/product-slots.css', BOOKGO_PLUGIN_FILE), [], BOOKGO_VERSION);
    }

    public static function addMetaBox(): void
    {
        add_meta_box('bookgo_product_slots', __('Terminy wizyty (BookGo)', 'bookgo'), [self::class, 'render'], 'product', 'normal', 'high');
    }

    public static function render(\WP_Post $post): void
    {
        $slots    = self::getSlots($post->ID);
        $duration = intval(get_post_meta($post->ID, '_bookgo_duration', true)) ?: 60;
        wp_nonce_field('bookgo_slots_save', '_bookgo_slots_nonce');
        ?>
        <div id="bookgo-slots-wrapper">
            <p id="bookgo-type-notice" style="display:none;color:#888;font-style:italic;">
                <?php esc_html_e('Dostępne tylko dla produktów typu „Wizyta (BookGo)".', 'bookgo'); ?>
            </p>
            <div id="bookgo-slots-content">
                <p>
                    <label style="font-weight:600;"><?php esc_html_e('Czas trwania wizyty (minuty)', 'bookgo'); ?></label><br>
                    <input type="number" name="_bookgo_duration" value="<?php echo esc_attr($duration); ?>" min="1" style="width:80px;" class="short">
                </p>
                <hr style="margin:12px 0;">
                <table class="bookgo-slots-table widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Data', 'bookgo'); ?></th>
                            <th><?php esc_html_e('Godzina', 'bookgo'); ?></th>
                            <th><?php esc_html_e('Liczba miejsc', 'bookgo'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="bookgo-slots-body">
                        <?php foreach ($slots as $i => $slot) : ?>
                        <tr class="bookgo-slot-row">
                            <td><input type="date"   name="_bookgo_slots[<?php echo $i; ?>][date]"     value="<?php echo esc_attr($slot['date']); ?>"                class="short"></td>
                            <td><input type="time"   name="_bookgo_slots[<?php echo $i; ?>][time]"     value="<?php echo esc_attr($slot['time']); ?>"                class="short" step="1800"></td>
                            <td><input type="number" name="_bookgo_slots[<?php echo $i; ?>][capacity]" value="<?php echo esc_attr(max(1, (int)$slot['capacity'])); ?>" class="short" min="1" style="width:64px;"></td>
                            <td><button type="button" class="button bookgo-remove-slot"><?php esc_html_e('Usuń', 'bookgo'); ?></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top:8px;">
                    <button type="button" class="button" id="bookgo-add-slot">
                        + <?php esc_html_e('Dodaj termin', 'bookgo'); ?>
                    </button>
                </p>
            </div>
        </div>
        <script>var bookgoSlotIndex = <?php echo count($slots); ?>;</script>
        <?php
    }

    public static function save(int $post_id): void
    {
        $nonce = sanitize_text_field(wp_unslash($_POST['_bookgo_slots_nonce'] ?? ''));
        if (!$nonce || !wp_verify_nonce($nonce, 'bookgo_slots_save')) return;

        $raw   = is_array($_POST['_bookgo_slots'] ?? null) ? $_POST['_bookgo_slots'] : [];
        $slots = [];

        foreach ($raw as $entry) {
            $date     = sanitize_text_field($entry['date']     ?? '');
            $time     = sanitize_text_field($entry['time']     ?? '');
            $capacity = max(1, intval($entry['capacity']       ?? 1));

            if (!$date || !$time) continue;

            $d = \DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) continue;

            $t = \DateTime::createFromFormat('H:i', $time);
            if (!$t || $t->format('H:i') !== $time) continue;

            $slots[] = compact('date', 'time', 'capacity');
        }

        usort($slots, static fn($a, $b) => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
        update_post_meta($post_id, '_bookgo_slots', $slots);

        $duration = max(1, intval($_POST['_bookgo_duration'] ?? 60)) ?: 60;
        update_post_meta($post_id, '_bookgo_duration', $duration);
    }

    public static function getSlots(int $product_id): array
    {
        $slots = get_post_meta($product_id, '_bookgo_slots', true);
        return is_array($slots) ? $slots : [];
    }
}
