<?php
/**
 * Admin Settings Page
 *
 * Handles the plugin settings page for API credentials
 */

namespace Amazon_MCF\Admin;

defined('ABSPATH') || exit;

class Settings {

    private string $option_name = 'amazon_mcf_settings';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_amazon_mcf_test_connection', [$this, 'ajax_test_connection']);
    }

    /**
     * Add admin menu pages
     */
    public function add_menu_pages(): void {
        add_menu_page(
            __('Amazon MCF', 'amazon-mcf'),
            __('Amazon MCF', 'amazon-mcf'),
            'manage_woocommerce',
            'amazon-mcf',
            [$this, 'render_settings_page'],
            'dashicons-amazon',
            56
        );

        add_submenu_page(
            'amazon-mcf',
            __('Settings', 'amazon-mcf'),
            __('Settings', 'amazon-mcf'),
            'manage_woocommerce',
            'amazon-mcf',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'amazon-mcf',
            __('Import Products', 'amazon-mcf'),
            __('Import Products', 'amazon-mcf'),
            'manage_woocommerce',
            'amazon-mcf-import',
            [$this, 'render_import_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings(): void {
        register_setting($this->option_name, $this->option_name, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        add_settings_section(
            'amazon_mcf_credentials',
            __('SP-API Credentials', 'amazon-mcf'),
            [$this, 'render_credentials_section'],
            'amazon-mcf'
        );

        $fields = [
            'lwa_client_id' => __('LWA Client ID', 'amazon-mcf'),
            'lwa_client_secret' => __('LWA Client Secret', 'amazon-mcf'),
            'refresh_token' => __('Refresh Token', 'amazon-mcf'),
            'marketplace_id' => __('Marketplace ID', 'amazon-mcf'),
        ];

        foreach ($fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                [$this, 'render_field'],
                'amazon-mcf',
                'amazon_mcf_credentials',
                ['key' => $key, 'label' => $label]
            );
        }
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings(array $input): array {
        $sanitized = [];

        $sanitized['lwa_client_id'] = sanitize_text_field($input['lwa_client_id'] ?? '');
        $sanitized['lwa_client_secret'] = sanitize_text_field($input['lwa_client_secret'] ?? '');
        $sanitized['refresh_token'] = sanitize_text_field($input['refresh_token'] ?? '');
        $sanitized['marketplace_id'] = sanitize_text_field($input['marketplace_id'] ?? 'ATVPDKIKX0DER');

        // Clear token cache when credentials change
        delete_transient('amazon_mcf_access_token');

        return $sanitized;
    }

    /**
     * Render credentials section description
     */
    public function render_credentials_section(): void {
        echo '<p>' . esc_html__('Enter your Amazon SP-API credentials. You can obtain these from Seller Central under Apps & Services > Develop Apps.', 'amazon-mcf') . '</p>';
        echo '<p><a href="https://developer-docs.amazon.com/sp-api/docs/self-authorization" target="_blank">' . esc_html__('SP-API Self-Authorization Guide', 'amazon-mcf') . '</a></p>';
    }

    /**
     * Render settings field
     */
    public function render_field(array $args): void {
        $settings = get_option($this->option_name, []);
        $key = $args['key'];
        $value = $settings[$key] ?? '';

        $type = ($key === 'lwa_client_secret' || $key === 'refresh_token') ? 'password' : 'text';

        if ($key === 'marketplace_id') {
            $marketplaces = [
                'ATVPDKIKX0DER' => 'United States (amazon.com)',
                'A2EUQ1WTGCTBG2' => 'Canada (amazon.ca)',
                'A1AM78C64UM0Y8' => 'Mexico (amazon.com.mx)',
            ];

            echo '<select name="' . esc_attr($this->option_name . '[' . $key . ']') . '" id="' . esc_attr($key) . '">';
            foreach ($marketplaces as $id => $name) {
                $selected = selected($value, $id, false);
                echo '<option value="' . esc_attr($id) . '"' . $selected . '>' . esc_html($name) . '</option>';
            }
            echo '</select>';
        } else {
            printf(
                '<input type="%s" name="%s" id="%s" value="%s" class="regular-text" autocomplete="off">',
                esc_attr($type),
                esc_attr($this->option_name . '[' . $key . ']'),
                esc_attr($key),
                esc_attr($value)
            );
        }

        // Show hint for refresh token
        if ($key === 'refresh_token') {
            echo '<p class="description">' . esc_html__('Starts with "Atzr|" - obtained via self-authorization in Seller Central', 'amazon-mcf') . '</p>';
        }
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form action="options.php" method="post">
                <?php
                settings_fields($this->option_name);
                do_settings_sections('amazon-mcf');
                ?>

                <p>
                    <button type="button" id="amazon-mcf-test-connection" class="button button-secondary">
                        <?php esc_html_e('Test Connection', 'amazon-mcf'); ?>
                    </button>
                    <span id="amazon-mcf-test-result"></span>
                </p>

                <?php submit_button(); ?>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#amazon-mcf-test-connection').on('click', function() {
                var $btn = $(this);
                var $result = $('#amazon-mcf-test-result');

                $btn.prop('disabled', true);
                $result.html('<span style="color:#666;">Testing...</span>');

                $.post(ajaxurl, {
                    action: 'amazon_mcf_test_connection',
                    nonce: '<?php echo esc_js(wp_create_nonce('amazon_mcf_test')); ?>',
                    settings: {
                        lwa_client_id: $('#lwa_client_id').val(),
                        lwa_client_secret: $('#lwa_client_secret').val(),
                        refresh_token: $('#refresh_token').val(),
                        marketplace_id: $('#marketplace_id').val()
                    }
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.html('<span style="color:green;">✓ ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color:red;">✗ ' + response.data.message + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $result.html('<span style="color:red;">✗ Request failed</span>');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render import page (placeholder - handled by Product_Importer)
     */
    public function render_import_page(): void {
        $importer = new Product_Importer();
        $importer->render_page();
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection(): void {
        check_ajax_referer('amazon_mcf_test', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        // Temporarily save settings for testing
        $settings = [
            'lwa_client_id' => sanitize_text_field($_POST['settings']['lwa_client_id'] ?? ''),
            'lwa_client_secret' => sanitize_text_field($_POST['settings']['lwa_client_secret'] ?? ''),
            'refresh_token' => sanitize_text_field($_POST['settings']['refresh_token'] ?? ''),
            'marketplace_id' => sanitize_text_field($_POST['settings']['marketplace_id'] ?? 'ATVPDKIKX0DER'),
        ];

        // Temporarily update for test
        $original = get_option('amazon_mcf_settings');
        update_option('amazon_mcf_settings', $settings);
        delete_transient('amazon_mcf_access_token');

        $client = new \Amazon_MCF\API_Client();
        $result = $client->test_connection();

        // Restore original settings
        if ($original) {
            update_option('amazon_mcf_settings', $original);
        }

        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(
                    __('Connected! Marketplaces: %s', 'amazon-mcf'),
                    implode(', ', $result['marketplaces'])
                ),
            ]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }
}
