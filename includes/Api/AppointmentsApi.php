<?php
namespace BookGo\Api;

use WP_REST_Request;
use WP_Error;
use BookGo\Service\BookingService;

if (!defined('ABSPATH')) exit;

class AppointmentsApi
{
    public static function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('bookgo/v1', '/ping', [
                'methods'             => 'GET',
                'callback'            => [self::class, 'ping'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('bookgo/v1', '/dates', [
                'methods'             => 'GET',
                'callback'            => [self::class, 'dates'],
                'permission_callback' => '__return_true',
                'args'                => ['product_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1]],
            ]);

            register_rest_route('bookgo/v1', '/available', [
                'methods'             => 'GET',
                'callback'            => [self::class, 'available'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'product_id' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                    'date'       => ['required' => true, 'type' => 'string'],
                ],
            ]);
        });
    }

    public static function ping(): array
    {
        return ['ok' => true, 'version' => BOOKGO_VERSION];
    }

    public static function dates(WP_REST_Request $request)
    {
        $service = new BookingService();
        return rest_ensure_response($service->getAvailableDates(intval($request->get_param('product_id'))));
    }

    public static function available(WP_REST_Request $request)
    {
        $date = sanitize_text_field($request->get_param('date'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('bookgo_invalid_date', 'Nieprawidłowy format daty.', ['status' => 400]);
        }

        $service = new BookingService();
        return rest_ensure_response($service->getAvailableSlots(intval($request->get_param('product_id')), $date));
    }
}
