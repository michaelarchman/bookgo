<?php
namespace BookGo\Service;

if (!defined('ABSPATH')) exit;

class GoogleCalendarService
{
    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public static function createEvent(\WC_Order $order, string $calendar_id): bool
    {
        $access_token = GoogleAuthService::getAccessToken($calendar_id);
        if (!$access_token) return false;

        $date     = $order->get_meta('bookgo_date');
        $time     = $order->get_meta('bookgo_time');
        $duration = self::getOrderDuration($order);

        if (!$date || !$time) return false;

        $tz         = wp_timezone_string();
        $start_str  = $date . 'T' . $time . ':00';
        $start_dt   = new \DateTimeImmutable($start_str, new \DateTimeZone($tz));
        $end_dt     = $start_dt->modify("+{$duration} minutes");

        $customer_email = $order->get_billing_email();
        $customer_name  = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $product_name   = self::getOrderProductName($order);

        $body = [
            'summary'          => $product_name . ' — ' . $customer_name,
            'start'            => ['dateTime' => $start_dt->format('c'), 'timeZone' => $tz],
            'end'              => ['dateTime' => $end_dt->format('c'),   'timeZone' => $tz],
            'attendees'        => [
                ['email' => $customer_email, 'displayName' => $customer_name],
            ],
            'conferenceData'   => [
                'createRequest' => [
                    'requestId'             => 'bookgo-' . $order->get_id(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
            'reminders'        => [
                'useDefault' => false,
                'overrides'  => [
                    ['method' => 'email',  'minutes' => 1440],
                    ['method' => 'popup',  'minutes' => 30],
                ],
            ],
        ];

        $response = wp_remote_post(
            self::EVENTS_URL . '?conferenceDataVersion=1&sendUpdates=all',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode($body),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) return false;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['id'])) return false;

        $meet_url = $data['hangoutLink'] ?? ($data['conferenceData']['entryPoints'][0]['uri'] ?? '');

        $order->update_meta_data('_bookgo_gcal_event_id',  $data['id']);
        $order->update_meta_data('_bookgo_gcal_calendar',  $calendar_id);
        if ($meet_url) {
            $order->update_meta_data('_bookgo_meet_url', $meet_url);
        }
        $order->save();

        return true;
    }

    public static function deleteEvent(\WC_Order $order): bool
    {
        $event_id    = $order->get_meta('_bookgo_gcal_event_id');
        $calendar_id = $order->get_meta('_bookgo_gcal_calendar');

        if (!$event_id || !$calendar_id) return false;

        $access_token = GoogleAuthService::getAccessToken($calendar_id);
        if (!$access_token) return false;

        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode($event_id) . '?sendUpdates=all';

        $response = wp_remote_request($url, [
            'method'  => 'DELETE',
            'headers' => ['Authorization' => 'Bearer ' . $access_token],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return false;

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 204 || $code === 200) {
            $order->delete_meta_data('_bookgo_gcal_event_id');
            $order->delete_meta_data('_bookgo_gcal_calendar');
            $order->delete_meta_data('_bookgo_meet_url');
            $order->save();
            return true;
        }

        return false;
    }

    private static function getOrderDuration(\WC_Order $order): int
    {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->is_type('bookgo')) {
                $d = intval(get_post_meta($product->get_id(), '_bookgo_duration', true));
                return max(1, $d ?: 60);
            }
        }
        return 60;
    }

    private static function getOrderProductName(\WC_Order $order): string
    {
        foreach ($order->get_items() as $item) {
            return $item->get_name();
        }
        return __('Wizyta', 'bookgo');
    }
}
