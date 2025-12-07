<?php
/**
 * Plugin Name: Amazon MCF for WooCommerce
 * Plugin URI: https://github.com/stevelingeman/woo-mcf-fulfillment
 * Description: Integrate WooCommerce with Amazon Multi-Channel Fulfillment (MCF) via SP-API. Import products from Amazon catalog and fulfill orders through FBA.
 * Version: 1.0.0
 * Author: Portawipes
 * Author URI: https://woo.mex-econo.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: amazon-mcf
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 10.3
 */

defined('ABSPATH') || exit;

// Plugin constants
define('AMAZON_MCF_VERSION', '1.0.0');
define('AMAZON_MCF_PLUGIN_FILE', __FILE__);
define('AMAZON_MCF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AMAZON_MCF_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class
 */
final class Amazon_MCF {

    /**
     * Single instance
     */
    private static ?Amazon_MCF $instance = null;

    /**
     * Get singleton instance
     */
    public static function instance(): Amazon_MCF {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Check dependencies on plugins_loaded
        add_action('plugins_loaded', [$this, 'check_dependencies']);

        // Initialize plugin
        add_action('plugins_loaded', [$this, 'init'], 20);

        // Declare HPOS compatibility
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);

        // Activation/deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    /**
     * Declare compatibility with WooCommerce HPOS
     */
    public function declare_hpos_compatibility(): void {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    /**
     * Check if WooCommerce is active
     */
    public function check_dependencies(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                echo esc_html__('Amazon MCF requires WooCommerce to be installed and active.', 'amazon-mcf');
                echo '</p></div>';
            });
            return;
        }
    }

    /**
     * Initialize plugin components
     */
    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Load autoloader
        $this->load_autoloader();

        // Initialize components
        if (is_admin()) {
            new Amazon_MCF\Admin\Settings();
            new Amazon_MCF\Admin\Product_Importer();
        }

        // Initialize order fulfillment (runs on both admin and frontend for hooks)
        new Amazon_MCF\Order_Fulfillment();
    }

    /**
     * Load Composer autoloader or manual includes
     */
    private function load_autoloader(): void {
        $autoloader = AMAZON_MCF_PLUGIN_DIR . 'vendor/autoload.php';

        if (file_exists($autoloader)) {
            require_once $autoloader;
        } else {
            // Manual includes if no composer
            $this->manual_includes();
        }
    }

    /**
     * Manual file includes
     */
    private function manual_includes(): void {
        $includes = [
            'includes/class-api-client.php',
            'includes/class-order-fulfillment.php',
            'includes/admin/class-settings.php',
            'includes/admin/class-product-importer-page.php',
        ];

        foreach ($includes as $file) {
            $path = AMAZON_MCF_PLUGIN_DIR . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    /**
     * Plugin activation
     */
    public function activate(): void {
        // Create options with defaults
        if (get_option('amazon_mcf_settings') === false) {
            add_option('amazon_mcf_settings', [
                'lwa_client_id' => '',
                'lwa_client_secret' => '',
                'refresh_token' => '',
                'marketplace_id' => 'ATVPDKIKX0DER', // US default
            ]);
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        flush_rewrite_rules();
    }
}

/**
 * Initialize plugin
 */
function amazon_mcf(): Amazon_MCF {
    return Amazon_MCF::instance();
}

// Start the plugin
amazon_mcf();
