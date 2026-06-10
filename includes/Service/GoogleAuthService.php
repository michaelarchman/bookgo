<?php
namespace BookGo\Service;

if (!defined('ABSPATH')) exit;

class GoogleAuthService
{
    private const TOKEN_OPTION_PREFIX = 'bookgo_gcal_token_';
    private const TOKEN_URL           = 'https://oauth2.googleapis.com/token';
    private const AUTH_URL            = 'https://accounts.google.com/o/oauth2/v2/auth';

    public static function getClientId(): string
    {
        return get_option('bookgo_gcal_client_id', '');
    }

    public static function getClientSecret(): string
    {
        return get_option('bookgo_gcal_client_secret', '');
    }

    public static function getRedirectUri(): string
    {
        return admin_url('admin.php?page=bookgo-calendars&bookgo_gcal_callback=1');
    }

    public static function getAuthUrl(string $calendar_id): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => self::getClientId(),
            'redirect_uri'  => self::getRedirectUri(),
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/calendar.events',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $calendar_id,
        ]);
    }

    public static function handleCallback(): void
    {
        $code        = sanitize_text_field($_GET['code']  ?? '');
        $calendar_id = sanitize_text_field($_GET['state'] ?? '');

        if (!$code || !$calendar_id) return;

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'code'          => $code,
                'client_id'     => self::getClientId(),
                'client_secret' => self::getClientSecret(),
                'redirect_uri'  => self::getRedirectUri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) return;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) return;

        $data['created_at'] = time();
        update_option(self::TOKEN_OPTION_PREFIX . $calendar_id, $data);

        wp_safe_redirect(add_query_arg([
            'page'            => 'bookgo-calendars',
            'gcal_connected'  => '1',
            'cal'             => $calendar_id,
        ], admin_url('admin.php')));
        exit;
    }

    public static function getAccessToken(string $calendar_id): ?string
    {
        $token = get_option(self::TOKEN_OPTION_PREFIX . $calendar_id);
        if (!$token || empty($token['access_token'])) return null;

        $expires_in  = $token['expires_in'] ?? 3600;
        $created_at  = $token['created_at'] ?? 0;

        if (time() > $created_at + $expires_in - 60) {
            return self::refreshToken($calendar_id, $token);
        }

        return $token['access_token'];
    }

    private static function refreshToken(string $calendar_id, array $token): ?string
    {
        if (empty($token['refresh_token'])) return null;

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'client_id'     => self::getClientId(),
                'client_secret' => self::getClientSecret(),
                'refresh_token' => $token['refresh_token'],
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) return null;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) return null;

        $data['refresh_token'] = $token['refresh_token'];
        $data['created_at']    = time();
        update_option(self::TOKEN_OPTION_PREFIX . $calendar_id, $data);

        return $data['access_token'];
    }

    public static function isConnected(string $calendar_id): bool
    {
        $token = get_option(self::TOKEN_OPTION_PREFIX . $calendar_id);
        return !empty($token['access_token']);
    }

    public static function disconnect(string $calendar_id): void
    {
        delete_option(self::TOKEN_OPTION_PREFIX . $calendar_id);
    }
}
