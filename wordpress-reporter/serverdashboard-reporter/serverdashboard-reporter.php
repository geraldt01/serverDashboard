<?php
/**
 * Plugin Name: ServerDashboard Plugin Reporter
 * Description: Securely reports installed WordPress plugin/core update status and wp-admin logins to ServerDashboard.
 * Version: 1.5.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

if (! defined('ABSPATH')) {
    exit;
}

const SERVER_DASHBOARD_REPORTER_OPTION = 'serverdashboard_reporter_settings';
const SERVER_DASHBOARD_REPORTER_AUDIT_OPTION = 'serverdashboard_reporter_audit_log';
const SERVER_DASHBOARD_REPORTER_CRON = 'serverdashboard_reporter_daily_report';
const SERVER_DASHBOARD_REPORTER_SCHEDULE = 'serverdashboard_six_hourly';
const SERVER_DASHBOARD_REPORTER_VERSION = '1.5.0';
const SERVER_DASHBOARD_REPORTER_VERSION_OPTION = 'serverdashboard_reporter_version';
const SERVER_DASHBOARD_REPORTER_MAX_AUDIT_EVENTS = 20;
const SERVER_DASHBOARD_REPORTER_TRIGGER_NONCE_PREFIX = 'sdr_trigger_nonce_';
const SERVER_DASHBOARD_REPORTER_UPDATE_MANIFEST_TRANSIENT = 'serverdashboard_reporter_update_manifest';

function serverdashboard_reporter_audit(string $event, string $message): void
{
    $events = get_option(SERVER_DASHBOARD_REPORTER_AUDIT_OPTION, []);
    $events[] = [
        'at' => current_time('mysql', true),
        'event' => sanitize_key($event),
        'message' => sanitize_text_field($message),
    ];

    update_option(
        SERVER_DASHBOARD_REPORTER_AUDIT_OPTION,
        array_slice($events, -SERVER_DASHBOARD_REPORTER_MAX_AUDIT_EVENTS),
        false
    );
}

function serverdashboard_reporter_encryption_key(): string
{
    return hash('sha256', wp_salt('auth') . AUTH_KEY, true);
}

function serverdashboard_reporter_encrypt_token(string $token): string
{
    if (! function_exists('openssl_encrypt') || ! function_exists('openssl_random_pseudo_bytes')) {
        return '';
    }

    $key = serverdashboard_reporter_encryption_key();
    $iv = openssl_random_pseudo_bytes(16);
    if ($iv === false) {
        return '';
    }

    $ciphertext = openssl_encrypt($token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        return '';
    }

    $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

    return 'v1:' . base64_encode($iv . $mac . $ciphertext);
}

function serverdashboard_reporter_decrypt_token(string $encryptedToken): string
{
    if (substr($encryptedToken, 0, 3) !== 'v1:' || ! function_exists('openssl_decrypt')) {
        return '';
    }

    $decoded = base64_decode(substr($encryptedToken, 3), true);
    if ($decoded === false || strlen($decoded) <= 48) {
        return '';
    }

    $key = serverdashboard_reporter_encryption_key();
    $iv = substr($decoded, 0, 16);
    $mac = substr($decoded, 16, 32);
    $ciphertext = substr($decoded, 48);
    $expectedMac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

    if (! hash_equals($expectedMac, $mac)) {
        return '';
    }

    $token = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

    return is_string($token) ? $token : '';
}

function serverdashboard_reporter_is_development_http_endpoint(string $endpoint, bool $allowDevelopmentHttp): bool
{
    $parts = wp_parse_url($endpoint);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));

    return $allowDevelopmentHttp && $scheme === 'http';
}

function serverdashboard_reporter_validate_endpoint(string $endpoint, bool $allowDevelopmentHttp = false): string
{
    $endpoint = esc_url_raw(trim($endpoint));
    $parts = wp_parse_url($endpoint);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $isDevelopmentHttpEndpoint = serverdashboard_reporter_is_development_http_endpoint($endpoint, $allowDevelopmentHttp);

    if (! is_array($parts) || ! in_array($scheme, ['https', 'http'], true) || empty($host) || isset($parts['user'], $parts['pass']) || isset($parts['fragment']) || ($scheme === 'https' && ! wp_http_validate_url($endpoint)) || ($scheme !== 'https' && ! $isDevelopmentHttpEndpoint)) {
        return '';
    }

    return $endpoint;
}

function serverdashboard_reporter_stored_settings(): array
{
    return wp_parse_args(get_option(SERVER_DASHBOARD_REPORTER_OPTION, []), [
        'endpoint' => '',
        'encrypted_token' => '',
        'allow_development_http' => false,
    ]);
}

function serverdashboard_reporter_settings(): array
{
    $settings = serverdashboard_reporter_stored_settings();

    return [
        'endpoint' => (string) $settings['endpoint'],
        'token' => serverdashboard_reporter_decrypt_token((string) $settings['encrypted_token']),
        'has_token' => ! empty($settings['encrypted_token']),
        'allow_development_http' => ! empty($settings['allow_development_http']),
    ];
}

function serverdashboard_reporter_sanitize_settings($settings): array
{
    delete_transient(SERVER_DASHBOARD_REPORTER_UPDATE_MANIFEST_TRANSIENT);

    $current = serverdashboard_reporter_stored_settings();
    $allowDevelopmentHttp = ! empty($settings['allow_development_http']);
    $endpoint = serverdashboard_reporter_validate_endpoint((string) ($settings['endpoint'] ?? ''), $allowDevelopmentHttp);
    $token = trim((string) ($settings['token'] ?? ''));

    if ($endpoint === '') {
        add_settings_error(SERVER_DASHBOARD_REPORTER_OPTION, 'invalid_endpoint', 'Use an HTTPS dashboard endpoint, or explicitly enable Development HTTP endpoint for temporary development-only use.', 'error');
        $endpoint = (string) $current['endpoint'];
    }

    $encryptedToken = (string) $current['encrypted_token'];
    if ($token !== '') {
        if (! preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $token)) {
            add_settings_error(SERVER_DASHBOARD_REPORTER_OPTION, 'invalid_token', 'The site token format is invalid.', 'error');
        } else {
            $encryptedToken = serverdashboard_reporter_encrypt_token($token);
            if ($encryptedToken === '') {
                add_settings_error(SERVER_DASHBOARD_REPORTER_OPTION, 'token_encryption_failed', 'Token encryption is unavailable. Contact the site administrator.', 'error');
                $encryptedToken = (string) $current['encrypted_token'];
            }
        }
    }

    return [
        'endpoint' => $endpoint,
        'encrypted_token' => $encryptedToken,
        'allow_development_http' => $allowDevelopmentHttp,
    ];
}

function serverdashboard_reporter_render_settings(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $settings = serverdashboard_reporter_settings();
    $auditEvents = array_reverse(get_option(SERVER_DASHBOARD_REPORTER_AUDIT_OPTION, []));
    ?>
    <div class="wrap">
        <h1>ServerDashboard Reporter</h1>
        <?php settings_errors(SERVER_DASHBOARD_REPORTER_OPTION); ?>
        <p>This plugin requires an HTTPS dashboard endpoint by default. Development HTTP is an explicit temporary exception. The site token is encrypted before storage and is never sent over the network.</p>
        <p>Once an endpoint is configured, future plugin releases show up as a normal update on the Plugins page &mdash; use <strong>Update now</strong> there instead of re-uploading the ZIP.</p>
        <form method="post" action="options.php">
            <?php settings_fields('serverdashboard_reporter'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="serverdashboard_endpoint">Reporter endpoint</label></th>
                    <td><input name="<?php echo esc_attr(SERVER_DASHBOARD_REPORTER_OPTION); ?>[endpoint]" id="serverdashboard_endpoint" type="url" class="regular-text code" value="<?php echo esc_attr($settings['endpoint']); ?>" placeholder="https://dashboard.example.com/ingest/wordpress/site/example" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="serverdashboard_token">Site token</label></th>
                    <td><input name="<?php echo esc_attr(SERVER_DASHBOARD_REPORTER_OPTION); ?>[token]" id="serverdashboard_token" type="password" class="regular-text code" value="" autocomplete="new-password" placeholder="<?php echo $settings['has_token'] ? esc_attr__('Configured. Enter a value only to replace it.', 'serverdashboard-reporter') : esc_attr__('Paste the site token from ServerDashboard.', 'serverdashboard-reporter'); ?>" <?php echo $settings['has_token'] ? '' : 'required'; ?>></td>
                </tr>
                <tr>
                    <th scope="row">Development transport</th>
                    <td><label><input name="<?php echo esc_attr(SERVER_DASHBOARD_REPORTER_OPTION); ?>[allow_development_http]" type="checkbox" value="1" <?php checked($settings['allow_development_http']); ?>> Allow a temporary HTTP reporter endpoint for development only.</label><p class="description">Disable this before production. HTTP does not protect report metadata in transit.</p></td>
                </tr>
            </table>
            <?php submit_button('Save secure settings'); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('serverdashboard_report_now'); ?>
            <input type="hidden" name="action" value="serverdashboard_report_now">
            <?php submit_button('Send signed plugin report now', 'secondary', 'submit', false); ?>
        </form>
        <h2>Recent reporter activity</h2>
        <table class="widefat striped" style="max-width:850px"><thead><tr><th>Time (UTC)</th><th>Event</th><th>Result</th></tr></thead><tbody>
        <?php if ($auditEvents === []) : ?><tr><td colspan="3">No reporter activity recorded.</td></tr><?php endif; ?>
        <?php foreach ($auditEvents as $event) : ?><tr><td><?php echo esc_html((string) ($event['at'] ?? '')); ?></td><td><?php echo esc_html((string) ($event['event'] ?? '')); ?></td><td><?php echo esc_html((string) ($event['message'] ?? '')); ?></td></tr><?php endforeach; ?>
        </tbody></table>
    </div>
    <?php
}

function serverdashboard_reporter_register_settings(): void
{
    register_setting('serverdashboard_reporter', SERVER_DASHBOARD_REPORTER_OPTION, [
        'type' => 'array',
        'sanitize_callback' => 'serverdashboard_reporter_sanitize_settings',
        'default' => ['endpoint' => '', 'encrypted_token' => '', 'allow_development_http' => false],
    ]);
}

function serverdashboard_reporter_collect_plugins(): array
{
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    wp_update_plugins();
    $updates = get_site_transient('update_plugins');
    $plugins = get_plugins();
    $payload = [];

    foreach (array_slice($plugins, 0, 500, true) as $pluginFile => $plugin) {
        $update = $updates->response[$pluginFile] ?? null;
        $noUpdate = $updates->no_update[$pluginFile] ?? null;
        $payload[] = [
            'pluginName' => $pluginFile,
            'currentVersion' => (string) ($plugin['Version'] ?? 'unknown'),
            'latestVersion' => (string) ($update->new_version ?? $noUpdate->new_version ?? $plugin['Version'] ?? 'unknown'),
            'status' => $update ? 'outdated' : 'up_to_date',
        ];
    }

    return $payload;
}

function serverdashboard_reporter_collect_core_update(): array
{
    require_once ABSPATH . 'wp-admin/includes/update.php';
    wp_version_check();
    $updates = get_core_updates();
    $current = get_bloginfo('version');
    $latest = $current;
    $status = 'unknown';

    if (is_array($updates) && isset($updates[0])) {
        $status = ($updates[0]->response ?? '') === 'upgrade' ? 'outdated' : 'up_to_date';
        $latest = (string) ($updates[0]->version ?? $current);
    }

    return [
        'currentVersion' => (string) $current,
        'latestVersion' => (string) $latest,
        'status' => $status,
    ];
}

function serverdashboard_reporter_send(): array
{
    $settings = serverdashboard_reporter_settings();
    if ($settings['endpoint'] === '' || $settings['token'] === '') {
        serverdashboard_reporter_audit('report_failed', 'Secure endpoint or encrypted site token is not configured.');
        return ['ok' => false, 'message' => 'Configure the secure endpoint and site token first.'];
    }

    $body = wp_json_encode([
        'plugins' => serverdashboard_reporter_collect_plugins(),
        'core' => serverdashboard_reporter_collect_core_update(),
    ]);
    if (! is_string($body)) {
        serverdashboard_reporter_audit('report_failed', 'The plugin report could not be encoded.');
        return ['ok' => false, 'message' => 'The plugin report could not be encoded.'];
    }

    try {
        $nonce = bin2hex(random_bytes(16));
    } catch (\Throwable $exception) {
        serverdashboard_reporter_audit('report_failed', 'A secure request nonce could not be generated.');
        return ['ok' => false, 'message' => 'A secure request nonce could not be generated.'];
    }

    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $settings['token']);
    $requestArgs = [
        'timeout' => 15,
        'redirection' => 0,
        'sslverify' => true,
        'reject_unsafe_urls' => ! serverdashboard_reporter_is_development_http_endpoint($settings['endpoint'], $settings['allow_development_http']),
        'limit_response_size' => 8192,
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-WordPress-Monitor-Timestamp' => $timestamp,
            'X-WordPress-Monitor-Nonce' => $nonce,
            'X-WordPress-Monitor-Signature' => $signature,
        ],
        'body' => $body,
    ];
    $response = serverdashboard_reporter_is_development_http_endpoint($settings['endpoint'], $settings['allow_development_http'])
        ? wp_remote_post($settings['endpoint'], $requestArgs)
        : wp_safe_remote_post($settings['endpoint'], $requestArgs);

    if (is_wp_error($response)) {
        serverdashboard_reporter_audit('report_failed', 'Transport error: ' . $response->get_error_code());
        return ['ok' => false, 'message' => 'Dashboard connection failed. Check the endpoint and TLS certificate.'];
    }

    $statusCode = wp_remote_retrieve_response_code($response);
    if ($statusCode < 200 || $statusCode >= 300) {
        serverdashboard_reporter_audit('report_failed', 'Dashboard returned HTTP ' . $statusCode . '.');
        return ['ok' => false, 'message' => 'Dashboard rejected the report (HTTP ' . $statusCode . ').'];
    }

    serverdashboard_reporter_audit('report_sent', sprintf('Signed report sent for %d plugins.', count(json_decode($body, true)['plugins'] ?? [])));

    return ['ok' => true, 'message' => 'Signed plugin update report sent.'];
}

function serverdashboard_reporter_client_ip(): string
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function serverdashboard_reporter_report_login(string $userLogin): void
{
    $settings = serverdashboard_reporter_settings();
    if ($settings['endpoint'] === '' || $settings['token'] === '') {
        return;
    }

    $ip = serverdashboard_reporter_client_ip();
    if ($ip === '') {
        serverdashboard_reporter_audit('login_report_failed', 'Could not determine a valid client IP address.');
        return;
    }

    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $body = wp_json_encode([
        'username' => sanitize_user($userLogin),
        'ipAddress' => $ip,
        'userAgent' => $userAgent !== '' ? substr($userAgent, 0, 255) : null,
    ]);

    if (! is_string($body)) {
        return;
    }

    try {
        $nonce = bin2hex(random_bytes(16));
    } catch (\Throwable $exception) {
        serverdashboard_reporter_audit('login_report_failed', 'A secure request nonce could not be generated.');
        return;
    }

    $loginEndpoint = rtrim($settings['endpoint'], '/') . '/login';
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $settings['token']);
    $isDevelopmentHttpEndpoint = serverdashboard_reporter_is_development_http_endpoint($settings['endpoint'], $settings['allow_development_http']);
    $requestArgs = [
        'timeout' => 10,
        'redirection' => 0,
        'sslverify' => true,
        'reject_unsafe_urls' => ! $isDevelopmentHttpEndpoint,
        'limit_response_size' => 4096,
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-WordPress-Monitor-Timestamp' => $timestamp,
            'X-WordPress-Monitor-Nonce' => $nonce,
            'X-WordPress-Monitor-Signature' => $signature,
        ],
        'body' => $body,
    ];
    $response = $isDevelopmentHttpEndpoint
        ? wp_remote_post($loginEndpoint, $requestArgs)
        : wp_safe_remote_post($loginEndpoint, $requestArgs);

    if (is_wp_error($response)) {
        serverdashboard_reporter_audit('login_report_failed', 'Transport error: ' . $response->get_error_code());
        return;
    }

    $statusCode = wp_remote_retrieve_response_code($response);
    if ($statusCode < 200 || $statusCode >= 300) {
        serverdashboard_reporter_audit('login_report_failed', 'Dashboard returned HTTP ' . $statusCode . '.');
        return;
    }

    serverdashboard_reporter_audit('login_reported', sprintf('Reported wp-admin login for user "%s".', $userLogin));
}

function serverdashboard_reporter_handle_manual_report(): void
{
    if (! current_user_can('manage_options')) {
        wp_die('Unauthorized.');
    }

    check_admin_referer('serverdashboard_report_now');
    $result = serverdashboard_reporter_send();
    $redirect = add_query_arg([
        'page' => 'serverdashboard-reporter',
        'serverdashboard_status' => $result['ok'] ? 'success' : 'error',
        'serverdashboard_message' => rawurlencode($result['message']),
    ], admin_url('options-general.php'));
    wp_safe_redirect($redirect);
    exit;
}

function serverdashboard_reporter_admin_notice(): void
{
    if (! isset($_GET['serverdashboard_status'], $_GET['serverdashboard_message'])) {
        return;
    }

    $class = $_GET['serverdashboard_status'] === 'success' ? 'notice-success' : 'notice-error';
    printf('<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr($class), esc_html(rawurldecode((string) $_GET['serverdashboard_message'])));
}

/**
 * REST route the dashboard calls to ask this site for an immediate report, so an admin
 * clicking "Sync EC2 updates" on the dashboard also gets fresh WordPress data right away
 * instead of waiting for the next 6-hourly cron run.
 */
function serverdashboard_reporter_register_rest_routes(): void
{
    register_rest_route('serverdashboard/v1', '/trigger-report', [
        'methods' => 'POST',
        'callback' => 'serverdashboard_reporter_handle_trigger_report',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * Authenticates the same way outbound reports are signed (HMAC-SHA256 over
 * timestamp.nonce.body using the shared site token), but in reverse: the dashboard is the
 * caller here, so the token is the only secret proving the request is genuine.
 */
function serverdashboard_reporter_handle_trigger_report(\WP_REST_Request $request)
{
    $settings = serverdashboard_reporter_settings();
    if ($settings['token'] === '') {
        return new \WP_REST_Response(['ok' => false, 'message' => 'Reporter is not configured.'], 403);
    }

    $timestamp = (string) $request->get_header('x_serverdashboard_timestamp');
    $nonce = (string) $request->get_header('x_serverdashboard_nonce');
    $signature = (string) $request->get_header('x_serverdashboard_signature');
    $body = (string) $request->get_body();

    if (! preg_match('/\A[0-9]{1,20}\z/', $timestamp) || ! preg_match('/\A[a-f0-9]{32}\z/i', $nonce) || ! preg_match('/\A[a-f0-9]{64}\z/i', $signature)) {
        return new \WP_REST_Response(['ok' => false, 'message' => 'Invalid request.'], 400);
    }

    if (abs(time() - (int) $timestamp) > 300) {
        return new \WP_REST_Response(['ok' => false, 'message' => 'Request expired.'], 401);
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $settings['token']);
    if (! hash_equals($expected, $signature)) {
        serverdashboard_reporter_audit('trigger_rejected', 'Received a trigger-report request with an invalid signature.');
        return new \WP_REST_Response(['ok' => false, 'message' => 'Invalid signature.'], 401);
    }

    $nonceKey = SERVER_DASHBOARD_REPORTER_TRIGGER_NONCE_PREFIX . md5($nonce);
    if (get_transient($nonceKey)) {
        return new \WP_REST_Response(['ok' => false, 'message' => 'Duplicate request.'], 401);
    }
    set_transient($nonceKey, 1, DAY_IN_SECONDS);

    serverdashboard_reporter_audit('trigger_received', 'Dashboard requested an immediate report.');

    $result = serverdashboard_reporter_send();

    return new \WP_REST_Response($result, $result['ok'] ? 200 : 502);
}

/**
 * The plugin ZIP/update manifest live on the same host as the configured reporter
 * endpoint, so self-hosted dashboards on any domain work without hardcoding one here.
 */
function serverdashboard_reporter_dashboard_base_url(): string
{
    $settings = serverdashboard_reporter_settings();
    $parts = wp_parse_url($settings['endpoint']);

    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    return $parts['scheme'] . '://' . $parts['host'] . $port;
}

/**
 * Fetches the dashboard's plugin update manifest (version/download URL), cached briefly
 * so WordPress's own update checks don't hit the dashboard on every admin page load.
 */
function serverdashboard_reporter_fetch_update_manifest(): ?array
{
    $cached = get_transient(SERVER_DASHBOARD_REPORTER_UPDATE_MANIFEST_TRANSIENT);
    if (is_array($cached)) {
        return $cached;
    }

    $baseUrl = serverdashboard_reporter_dashboard_base_url();
    if ($baseUrl === '') {
        return null;
    }

    $settings = serverdashboard_reporter_settings();
    $manifestUrl = $baseUrl . '/downloads/serverdashboard-reporter/update-info.json';
    $isDevelopmentHttpEndpoint = serverdashboard_reporter_is_development_http_endpoint($manifestUrl, $settings['allow_development_http']);
    $requestArgs = [
        'timeout' => 10,
        'redirection' => 0,
        'sslverify' => true,
        'reject_unsafe_urls' => ! $isDevelopmentHttpEndpoint,
        'limit_response_size' => 8192,
        'headers' => ['Accept' => 'application/json'],
    ];
    $response = $isDevelopmentHttpEndpoint
        ? wp_remote_get($manifestUrl, $requestArgs)
        : wp_safe_remote_get($manifestUrl, $requestArgs);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (! is_array($data) || empty($data['version']) || empty($data['download_url'])) {
        return null;
    }

    set_transient(SERVER_DASHBOARD_REPORTER_UPDATE_MANIFEST_TRANSIENT, $data, 6 * HOUR_IN_SECONDS);

    return $data;
}

/**
 * Injects a self-hosted update entry into WordPress's own update-checking transient, so
 * the Plugins page shows "Update now" the same way it would for a wordpress.org plugin.
 */
function serverdashboard_reporter_check_for_update($transient)
{
    if (! is_object($transient)) {
        $transient = new stdClass();
    }

    $pluginFile = plugin_basename(__FILE__);
    $manifest = serverdashboard_reporter_fetch_update_manifest();

    if ($manifest === null || version_compare((string) $manifest['version'], SERVER_DASHBOARD_REPORTER_VERSION, '<=')) {
        unset($transient->response[$pluginFile]);

        return $transient;
    }

    $transient->response[$pluginFile] = (object) [
        'id' => $pluginFile,
        'slug' => dirname($pluginFile),
        'plugin' => $pluginFile,
        'new_version' => $manifest['version'],
        'url' => (string) ($manifest['url'] ?? ''),
        'package' => $manifest['download_url'],
        'requires' => (string) ($manifest['requires'] ?? ''),
        'requires_php' => (string) ($manifest['requires_php'] ?? ''),
    ];

    return $transient;
}

/**
 * Feeds the "View version details" popup WordPress shows next to the update notice.
 */
function serverdashboard_reporter_plugins_api($result, $action, $args)
{
    $slug = dirname(plugin_basename(__FILE__));

    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $slug) {
        return $result;
    }

    $manifest = serverdashboard_reporter_fetch_update_manifest();
    if ($manifest === null) {
        return $result;
    }

    return (object) [
        'name' => 'ServerDashboard Plugin Reporter',
        'slug' => $slug,
        'version' => $manifest['version'],
        'download_link' => $manifest['download_url'],
        'requires' => (string) ($manifest['requires'] ?? ''),
        'requires_php' => (string) ($manifest['requires_php'] ?? ''),
        'sections' => ['description' => 'Securely reports installed WordPress plugin/core update status and wp-admin logins to ServerDashboard.'],
    ];
}

function serverdashboard_reporter_cron_schedules(array $schedules): array
{
    $schedules[SERVER_DASHBOARD_REPORTER_SCHEDULE] = [
        'interval' => 6 * HOUR_IN_SECONDS,
        'display' => __('Every 6 hours (ServerDashboard)', 'serverdashboard-reporter'),
    ];

    return $schedules;
}

function serverdashboard_reporter_schedule_cron(): void
{
    $scheduledEvent = wp_get_scheduled_event(SERVER_DASHBOARD_REPORTER_CRON);
    if ($scheduledEvent && $scheduledEvent->schedule === SERVER_DASHBOARD_REPORTER_SCHEDULE) {
        return;
    }

    if ($scheduledEvent) {
        wp_unschedule_event($scheduledEvent->timestamp, SERVER_DASHBOARD_REPORTER_CRON);
    }

    wp_schedule_event(time() + HOUR_IN_SECONDS, SERVER_DASHBOARD_REPORTER_SCHEDULE, SERVER_DASHBOARD_REPORTER_CRON);
}

function serverdashboard_reporter_maybe_upgrade(): void
{
    if (get_option(SERVER_DASHBOARD_REPORTER_VERSION_OPTION) === SERVER_DASHBOARD_REPORTER_VERSION) {
        return;
    }

    serverdashboard_reporter_schedule_cron();
    update_option(SERVER_DASHBOARD_REPORTER_VERSION_OPTION, SERVER_DASHBOARD_REPORTER_VERSION, false);
}

function serverdashboard_reporter_activate(): void
{
    if (! function_exists('openssl_encrypt')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('ServerDashboard Reporter requires the PHP OpenSSL extension for encrypted token storage.');
    }

    serverdashboard_reporter_schedule_cron();
    update_option(SERVER_DASHBOARD_REPORTER_VERSION_OPTION, SERVER_DASHBOARD_REPORTER_VERSION, false);
}

function serverdashboard_reporter_deactivate(): void
{
    $timestamp = wp_next_scheduled(SERVER_DASHBOARD_REPORTER_CRON);
    if ($timestamp) {
        wp_unschedule_event($timestamp, SERVER_DASHBOARD_REPORTER_CRON);
    }
}

add_filter('cron_schedules', 'serverdashboard_reporter_cron_schedules');
add_filter('pre_set_site_transient_update_plugins', 'serverdashboard_reporter_check_for_update');
add_filter('plugins_api', 'serverdashboard_reporter_plugins_api', 10, 3);
add_action('rest_api_init', 'serverdashboard_reporter_register_rest_routes');
add_action('init', 'serverdashboard_reporter_maybe_upgrade');
add_action('admin_init', 'serverdashboard_reporter_register_settings');
add_action('admin_menu', fn () => add_options_page('ServerDashboard Reporter', 'ServerDashboard Reporter', 'manage_options', 'serverdashboard-reporter', 'serverdashboard_reporter_render_settings'));
add_action('admin_post_serverdashboard_report_now', 'serverdashboard_reporter_handle_manual_report');
add_action('admin_notices', 'serverdashboard_reporter_admin_notice');
add_action(SERVER_DASHBOARD_REPORTER_CRON, 'serverdashboard_reporter_send');
add_action('wp_login', 'serverdashboard_reporter_report_login', 10, 1);
register_activation_hook(__FILE__, 'serverdashboard_reporter_activate');
register_deactivation_hook(__FILE__, 'serverdashboard_reporter_deactivate');
