<?php
namespace BookGo\Admin;

if (!defined('ABSPATH')) exit;

/**
 * ProductQuestionnaire — sends an extra email with a PDF attachment after purchase.
 *
 * Admin: product-level fields for configuring the subject, message, and PDF file.
 * Mail:  sends one extra customer email after the order is placed / paid.
 */
class ProductQuestionnaire
{
    private const META_ENABLED       = '_bookgo_questionnaire_enabled';
    private const META_SUBJECT       = '_bookgo_questionnaire_subject';
    private const META_MESSAGE       = '_bookgo_questionnaire_message';
    private const META_ATTACHMENT_ID = '_bookgo_questionnaire_attachment_id';
    private const ORDER_SENT_META    = '_bookgo_questionnaire_email_sent';

    public static function init(): void
    {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'renderField']);
        add_action('woocommerce_admin_process_product_object', [self::class, 'saveField']);
        add_action('admin_footer', [self::class, 'adminAssets']);

        add_action('woocommerce_thankyou', [self::class, 'sendForOrder'], 20);
        add_action('woocommerce_payment_complete', [self::class, 'sendForOrder'], 20);
        add_action('woocommerce_order_status_processing', [self::class, 'sendForOrder'], 20);
        add_action('woocommerce_order_status_completed', [self::class, 'sendForOrder'], 20);
        add_action('woocommerce_order_status_on-hold', [self::class, 'sendForOrder'], 20);
    }

    public static function renderField(): void
    {
        global $post;

        $enabled      = get_post_meta($post->ID, self::META_ENABLED, true) === 'yes';
        $subject      = (string) get_post_meta($post->ID, self::META_SUBJECT, true);
        $message      = (string) get_post_meta($post->ID, self::META_MESSAGE, true);
        $attachmentId = (int) get_post_meta($post->ID, self::META_ATTACHMENT_ID, true);
        $attachment   = $attachmentId ? get_post($attachmentId) : null;
        $attachmentUi = $attachment instanceof \WP_Post
            ? sprintf(
                '%s (%s)',
                $attachment->post_title ?: basename((string) get_attached_file($attachmentId)),
                basename((string) get_attached_file($attachmentId))
            )
            : __('Brak wybranego pliku', 'bookgo');

        echo '<div class="options_group show_if_simple show_if_external show_if_variable show_if_bookgo bookgo-questionnaire-wrapper">';

        woocommerce_wp_checkbox([
            'id'          => self::META_ENABLED,
            'label'       => __('Wyślij dodatkowy e-mail z kwestionariuszem', 'bookgo'),
            'description' => __('Po zakupie tego produktu klient dostanie osobny e-mail z załącznikiem PDF.', 'bookgo'),
            'value'       => $enabled ? 'yes' : 'no',
        ]);

        woocommerce_wp_text_input([
            'id'          => self::META_SUBJECT,
            'label'       => __('Temat e-maila', 'bookgo'),
            'description' => __('Jeśli pole jest puste, zostanie użyty temat domyślny.', 'bookgo'),
            'desc_tip'    => true,
            'value'       => $subject,
            'placeholder' => __('Twój kwestionariusz przed wizytą', 'bookgo'),
        ]);

        woocommerce_wp_textarea_input([
            'id'          => self::META_MESSAGE,
            'label'       => __('Treść e-maila', 'bookgo'),
            'description' => __('Możesz wpisać krótką instrukcję dla klienta. HTML nie jest wymagany.', 'bookgo'),
            'desc_tip'    => true,
            'value'       => $message,
            'placeholder' => __('Dzień dobry, w załączniku przesyłamy kwestionariusz do uzupełnienia przed spotkaniem.', 'bookgo'),
        ]);

        echo '<p class="form-field ' . esc_attr(self::META_ATTACHMENT_ID) . '_field">';
        echo '<label>' . esc_html__('Załącznik PDF', 'bookgo') . '</label>';
        echo '<input type="hidden" id="' . esc_attr(self::META_ATTACHMENT_ID) . '" name="' . esc_attr(self::META_ATTACHMENT_ID) . '" value="' . esc_attr((string) $attachmentId) . '" />';
        echo '<span class="bookgo-questionnaire-file">' . esc_html($attachmentUi) . '</span> ';
        echo '<a href="#" class="button bookgo-questionnaire-select">' . esc_html__('Wybierz PDF', 'bookgo') . '</a> ';
        echo '<a href="#" class="button bookgo-questionnaire-clear">' . esc_html__('Usuń plik', 'bookgo') . '</a>';
        echo '<span class="description">' . esc_html__('Najlepiej wybierz plik z biblioteki mediów WordPress.', 'bookgo') . '</span>';
        echo '</p>';

        echo '</div>';
    }

    public static function saveField(\WC_Product $product): void
    {
        $enabled      = isset($_POST[self::META_ENABLED]) ? 'yes' : 'no';
        $subject      = isset($_POST[self::META_SUBJECT]) ? sanitize_text_field(wp_unslash($_POST[self::META_SUBJECT])) : '';
        $message      = isset($_POST[self::META_MESSAGE]) ? sanitize_textarea_field(wp_unslash($_POST[self::META_MESSAGE])) : '';
        $attachmentId = isset($_POST[self::META_ATTACHMENT_ID]) ? absint(wp_unslash($_POST[self::META_ATTACHMENT_ID])) : 0;

        if ($attachmentId && get_post_mime_type($attachmentId) !== 'application/pdf') {
            $attachmentId = 0;
        }

        $product->update_meta_data(self::META_ENABLED, $enabled);
        $product->update_meta_data(self::META_SUBJECT, $subject);
        $product->update_meta_data(self::META_MESSAGE, $message);
        $product->update_meta_data(self::META_ATTACHMENT_ID, $attachmentId);
    }

    public static function adminAssets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product') {
            return;
        }

        wp_enqueue_media();
        ?>
        <script>
        jQuery(function ($) {
            let frame = null;

            $(document).on('click', '.bookgo-questionnaire-select', function (e) {
                e.preventDefault();

                if (frame) {
                    frame.open();
                    return;
                }

                frame = wp.media({
                    title: '<?php echo esc_js(__('Wybierz plik PDF', 'bookgo')); ?>',
                    button: { text: '<?php echo esc_js(__('Użyj tego pliku', 'bookgo')); ?>' },
                    library: { type: 'application/pdf' },
                    multiple: false
                });

                frame.on('select', function () {
                    const attachment = frame.state().get('selection').first().toJSON();
                    $('#<?php echo esc_js(self::META_ATTACHMENT_ID); ?>').val(attachment.id);
                    $('.bookgo-questionnaire-file').text(attachment.filename || attachment.title);
                });

                frame.open();
            });

            $(document).on('click', '.bookgo-questionnaire-clear', function (e) {
                e.preventDefault();
                $('#<?php echo esc_js(self::META_ATTACHMENT_ID); ?>').val('');
                $('.bookgo-questionnaire-file').text('<?php echo esc_js(__('Brak wybranego pliku', 'bookgo')); ?>');
            });
        });
        </script>
        <style>
            .bookgo-questionnaire-wrapper .bookgo-questionnaire-file {
                display: inline-block;
                margin-right: 8px;
                font-weight: 600;
            }
        </style>
        <?php
    }

    public static function sendForOrder($orderId): void
    {
        $orderId = absint($orderId);
        if (!$orderId) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if ($order->get_meta(self::ORDER_SENT_META) === 'yes') {
            return;
        }

        $recipient = sanitize_email((string) $order->get_billing_email());
        if (!$recipient) {
            return;
        }

        $entries = self::getOrderQuestionnaires($order);
        if (empty($entries)) {
            return;
        }

        $attachments = [];
        foreach ($entries as $entry) {
            if (!in_array($entry['file_path'], $attachments, true)) {
                $attachments[] = $entry['file_path'];
            }
        }

        if (empty($attachments)) {
            return;
        }

        $subject = self::buildSubject($entries, $order);
        $message = self::buildMessage($entries, $order);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($recipient, $subject, $message, $headers, $attachments);

        if ($sent) {
            $order->update_meta_data(self::ORDER_SENT_META, 'yes');
            $order->save();
        }
    }

    /**
     * @return array<int, array{product_name:string, subject:string, message:string, file_path:string, file_name:string}>
     */
    private static function getOrderQuestionnaires(\WC_Order $order): array
    {
        $entries = [];

        foreach ($order->get_items() as $item) {
            $productId = $item->get_product_id();
            if (!$productId) {
                continue;
            }

            if (get_post_meta($productId, self::META_ENABLED, true) !== 'yes') {
                continue;
            }

            $attachmentId = (int) get_post_meta($productId, self::META_ATTACHMENT_ID, true);
            $filePath = $attachmentId ? get_attached_file($attachmentId) : '';

            if (!$filePath || !file_exists($filePath)) {
                continue;
            }

            $entries[] = [
                'product_name' => $item->get_name(),
                'subject'      => (string) get_post_meta($productId, self::META_SUBJECT, true),
                'message'      => (string) get_post_meta($productId, self::META_MESSAGE, true),
                'file_path'    => $filePath,
                'file_name'    => basename($filePath),
            ];
        }

        return $entries;
    }

    /**
     * @param array<int, array{product_name:string, subject:string, message:string, file_path:string, file_name:string}> $entries
     */
    private static function buildSubject(array $entries, \WC_Order $order): string
    {
        foreach ($entries as $entry) {
            if ($entry['subject'] !== '') {
                return $entry['subject'];
            }
        }

        if (count($entries) === 1) {
            return sprintf(__('Kwestionariusz do produktu: %s', 'bookgo'), $entries[0]['product_name']);
        }

        return sprintf(__('Kwestionariusze do zamówienia #%s', 'bookgo'), $order->get_order_number());
    }

    /**
     * @param array<int, array{product_name:string, subject:string, message:string, file_path:string, file_name:string}> $entries
     */
    private static function buildMessage(array $entries, \WC_Order $order): string
    {
        $customerName = trim((string) $order->get_billing_first_name());
        $greeting = $customerName !== ''
            ? sprintf(__('Dzień dobry %s,', 'bookgo'), esc_html($customerName))
            : __('Dzień dobry,', 'bookgo');

        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#222;">';
        $html .= '<p>' . $greeting . '</p>';
        $html .= '<p>' . esc_html__('W załączniku przesyłamy materiały powiązane z Twoim zamówieniem.', 'bookgo') . '</p>';

        foreach ($entries as $entry) {
            $html .= '<h3 style="margin:24px 0 8px;">' . esc_html($entry['product_name']) . '</h3>';

            if ($entry['message'] !== '') {
                $paragraphs = wpautop(esc_html($entry['message']));
                $html .= wp_kses_post($paragraphs);
            } else {
                $html .= '<p>' . esc_html__('Uzupełnij załączony formularz PDF i odeślij go zgodnie z instrukcją otrzymaną od sprzedawcy.', 'bookgo') . '</p>';
            }

            $html .= '<p><strong>' . esc_html__('Załącznik:', 'bookgo') . '</strong> ' . esc_html($entry['file_name']) . '</p>';
        }

        $html .= '<p>' . esc_html__('Pozdrawiamy', 'bookgo') . '</p>';
        $html .= '</div>';

        return $html;
    }
}
