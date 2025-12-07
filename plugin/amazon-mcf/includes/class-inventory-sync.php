<?php
/**
 * Inventory Sync Handler
 *
 * Keeps WooCommerce product stock in sync with Amazon FBA inventory
 */

namespace Amazon_MCF;

defined('ABSPATH') || exit;

class Inventory_Sync {

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'amazon_mcf_inventory_sync';

    /**
     * Constructor
     */
    public function __construct() {
        // Schedule cron on init
        add_action('init', [$this, 'schedule_sync']);

        // Cron action
        add_action(self::CRON_HOOK, [$this, 'run_sync']);

        // AJAX handlers
        add_action('wp_ajax_amazon_mcf_manual_sync', [$this, 'ajax_manual_sync']);
        add_action('wp_ajax_amazon_mcf_sync_status', [$this, 'ajax_sync_status']);

        // Add sync page
        add_action('admin_menu', [$this, 'add_sync_submenu'], 20);
    }

    /**
     * Schedule sync cron job
     */
    public function schedule_sync(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * Unschedule sync (call on plugin deactivation)
     */
    public static function unschedule_sync(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Run inventory sync
     *
     * @param bool $return_results Whether to return results (for AJAX) or just log
     * @return array|void Results if $return_results is true
     */
    public function run_sync(bool $return_results = false) {
        $client = new API_Client();

        if (!$client->has_credentials()) {
            $error = 'API credentials not configured';
            error_log('Amazon MCF Inventory Sync: ' . $error);
            if ($return_results) {
                return ['error' => $error];
            }
            return;
        }

        // Get FBA inventory
        $inventory = $client->get_active_inventory();

        if (isset($inventory['error'])) {
            error_log('Amazon MCF Inventory Sync Error: ' . $inventory['error']);
            if ($return_results) {
                return ['error' => $inventory['error']];
            }
            return;
        }

        // Also get zero-quantity items for out-of-stock updates
        $all_inventory = $this->get_all_inventory($client);

        $results = [
            'updated' => 0,
            'skipped' => 0,
            'not_found' => 0,
            'errors' => 0,
            'details' => [],
        ];

        // Build SKU => quantity map
        $sku_quantities = [];
        foreach ($all_inventory as $item) {
            $sku_quantities[$item['sku']] = $item['quantity'];
        }

        // Get all WooCommerce products with Amazon SKUs
        $products = $this->get_amazon_products();

        foreach ($products as $product_id => $sku) {
            $product = wc_get_product($product_id);
            if (!$product) {
                $results['errors']++;
                continue;
            }

            if (!isset($sku_quantities[$sku])) {
                // SKU not found in Amazon inventory
                $results['not_found']++;
                $results['details'][] = [
                    'sku' => $sku,
                    'product_id' => $product_id,
                    'status' => 'not_found',
                    'message' => 'SKU not found in Amazon FBA inventory',
                ];
                continue;
            }

            $fba_qty = (int) $sku_quantities[$sku];
            $wc_qty = (int) $product->get_stock_quantity();

            if ($fba_qty === $wc_qty) {
                $results['skipped']++;
                continue;
            }

            // Update stock
            $product->set_stock_quantity($fba_qty);
            $product->set_stock_status($fba_qty > 0 ? 'instock' : 'outofstock');
            $product->save();

            $results['updated']++;
            $results['details'][] = [
                'sku' => $sku,
                'product_id' => $product_id,
                'status' => 'updated',
                'old_qty' => $wc_qty,
                'new_qty' => $fba_qty,
            ];
        }

        // Log results
        $log_msg = sprintf(
            'Amazon MCF Inventory Sync: Updated %d, Skipped %d, Not Found %d, Errors %d',
            $results['updated'],
            $results['skipped'],
            $results['not_found'],
            $results['errors']
        );
        error_log($log_msg);

        // Store last sync time and results
        update_option('amazon_mcf_last_sync', [
            'time' => current_time('mysql'),
            'timestamp' => time(),
            'updated' => $results['updated'],
            'skipped' => $results['skipped'],
            'not_found' => $results['not_found'],
            'errors' => $results['errors'],
        ]);

        if ($return_results) {
            return $results;
        }
    }

    /**
     * Get all inventory including zero quantities
     */
    private function get_all_inventory(API_Client $client): array {
        $result = $client->request('GET', '/fba/inventory/v1/summaries', [
            'granularityType' => 'Marketplace',
            'granularityId' => $client->get_marketplace_id(),
            'marketplaceIds' => $client->get_marketplace_id(),
        ]);

        if (isset($result['error'])) {
            return [];
        }

        $items = [];
        foreach ($result['payload']['inventorySummaries'] ?? [] as $item) {
            $items[] = [
                'sku' => $item['sellerSku'],
                'asin' => $item['asin'] ?? '',
                'quantity' => $item['totalQuantity'] ?? 0,
                'name' => $item['productName'] ?? '',
            ];
        }

        return $items;
    }

    /**
     * Get WooCommerce products that have Amazon SKUs
     *
     * @return array product_id => sku
     */
    private function get_amazon_products(): array {
        global $wpdb;

        // Get products that were imported from Amazon (have _amazon_sku meta)
        // or match by WooCommerce SKU
        $results = $wpdb->get_results("
            SELECT p.ID,
                   COALESCE(amazon_sku.meta_value, wc_sku.meta_value) as sku
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} amazon_sku
                ON p.ID = amazon_sku.post_id AND amazon_sku.meta_key = '_amazon_sku'
            LEFT JOIN {$wpdb->postmeta} wc_sku
                ON p.ID = wc_sku.post_id AND wc_sku.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation')
                AND p.post_status = 'publish'
                AND (amazon_sku.meta_value IS NOT NULL OR wc_sku.meta_value IS NOT NULL)
                AND COALESCE(amazon_sku.meta_value, wc_sku.meta_value) != ''
        ", ARRAY_A);

        $products = [];
        foreach ($results as $row) {
            $products[(int) $row['ID']] = $row['sku'];
        }

        return $products;
    }

    /**
     * Add sync submenu page
     */
    public function add_sync_submenu(): void {
        add_submenu_page(
            'amazon-mcf',
            __('Inventory Sync', 'amazon-mcf'),
            __('Inventory Sync', 'amazon-mcf'),
            'manage_woocommerce',
            'amazon-mcf-sync',
            [$this, 'render_sync_page']
        );
    }

    /**
     * Render sync admin page
     */
    public function render_sync_page(): void {
        $last_sync = get_option('amazon_mcf_last_sync', []);
        $next_sync = wp_next_scheduled(self::CRON_HOOK);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Inventory Sync', 'amazon-mcf'); ?></h1>

            <div class="card" style="max-width: 600px; padding: 20px;">
                <h2><?php esc_html_e('Sync Status', 'amazon-mcf'); ?></h2>

                <?php if (!empty($last_sync)): ?>
                    <table class="widefat" style="margin-bottom: 20px;">
                        <tr>
                            <th><?php esc_html_e('Last Sync', 'amazon-mcf'); ?></th>
                            <td><?php echo esc_html($last_sync['time'] ?? 'Never'); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Products Updated', 'amazon-mcf'); ?></th>
                            <td><?php echo esc_html($last_sync['updated'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Unchanged (Skipped)', 'amazon-mcf'); ?></th>
                            <td><?php echo esc_html($last_sync['skipped'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Not Found in FBA', 'amazon-mcf'); ?></th>
                            <td><?php echo esc_html($last_sync['not_found'] ?? 0); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Errors', 'amazon-mcf'); ?></th>
                            <td><?php echo esc_html($last_sync['errors'] ?? 0); ?></td>
                        </tr>
                    </table>
                <?php else: ?>
                    <p><?php esc_html_e('No sync has been performed yet.', 'amazon-mcf'); ?></p>
                <?php endif; ?>

                <?php if ($next_sync): ?>
                    <p>
                        <strong><?php esc_html_e('Next Scheduled Sync:', 'amazon-mcf'); ?></strong>
                        <?php echo esc_html(date('Y-m-d H:i:s', $next_sync)); ?>
                        (<?php echo esc_html(human_time_diff(time(), $next_sync)); ?> from now)
                    </p>
                <?php endif; ?>

                <p style="margin-top: 20px;">
                    <button type="button" id="run-sync-btn" class="button button-primary">
                        <?php esc_html_e('Run Sync Now', 'amazon-mcf'); ?>
                    </button>
                    <span id="sync-status" style="margin-left: 10px;"></span>
                </p>

                <div id="sync-results" style="margin-top: 20px; display: none;">
                    <h3><?php esc_html_e('Sync Results', 'amazon-mcf'); ?></h3>
                    <div id="sync-results-content"></div>
                </div>
            </div>

            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                <h2><?php esc_html_e('How It Works', 'amazon-mcf'); ?></h2>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php esc_html_e('Inventory syncs automatically every hour', 'amazon-mcf'); ?></li>
                    <li><?php esc_html_e('WooCommerce stock quantities are updated to match FBA', 'amazon-mcf'); ?></li>
                    <li><?php esc_html_e('Products are matched by SKU', 'amazon-mcf'); ?></li>
                    <li><?php esc_html_e('Out-of-stock products are marked as such', 'amazon-mcf'); ?></li>
                </ul>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#run-sync-btn').on('click', function() {
                var $btn = $(this);
                var $status = $('#sync-status');
                var $results = $('#sync-results');
                var $content = $('#sync-results-content');

                $btn.prop('disabled', true);
                $status.html('<span style="color:#666;"><?php esc_html_e('Syncing...', 'amazon-mcf'); ?></span>');
                $results.hide();

                $.post(ajaxurl, {
                    action: 'amazon_mcf_manual_sync',
                    nonce: '<?php echo esc_js(wp_create_nonce('amazon_mcf_sync')); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);

                    if (response.success) {
                        var data = response.data;
                        $status.html('<span style="color:green;">✓ <?php esc_html_e('Sync complete!', 'amazon-mcf'); ?></span>');

                        var html = '<table class="widefat">' +
                            '<tr><th><?php esc_html_e('Updated', 'amazon-mcf'); ?></th><td>' + data.updated + '</td></tr>' +
                            '<tr><th><?php esc_html_e('Skipped', 'amazon-mcf'); ?></th><td>' + data.skipped + '</td></tr>' +
                            '<tr><th><?php esc_html_e('Not Found', 'amazon-mcf'); ?></th><td>' + data.not_found + '</td></tr>' +
                            '<tr><th><?php esc_html_e('Errors', 'amazon-mcf'); ?></th><td>' + data.errors + '</td></tr>' +
                            '</table>';

                        if (data.details && data.details.length > 0) {
                            html += '<h4 style="margin-top:15px;"><?php esc_html_e('Changes:', 'amazon-mcf'); ?></h4><ul>';
                            data.details.forEach(function(item) {
                                if (item.status === 'updated') {
                                    html += '<li>SKU: ' + item.sku + ' — ' + item.old_qty + ' → ' + item.new_qty + '</li>';
                                } else if (item.status === 'not_found') {
                                    html += '<li style="color:#999;">SKU: ' + item.sku + ' — not found in FBA</li>';
                                }
                            });
                            html += '</ul>';
                        }

                        $content.html(html);
                        $results.show();
                    } else {
                        $status.html('<span style="color:red;">✗ ' + response.data.message + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $status.html('<span style="color:red;">✗ <?php esc_html_e('Request failed', 'amazon-mcf'); ?></span>');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Run manual sync
     */
    public function ajax_manual_sync(): void {
        check_ajax_referer('amazon_mcf_sync', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $results = $this->run_sync(true);

        if (isset($results['error'])) {
            wp_send_json_error(['message' => $results['error']]);
        }

        wp_send_json_success($results);
    }

    /**
     * AJAX: Get sync status
     */
    public function ajax_sync_status(): void {
        check_ajax_referer('amazon_mcf_sync', 'nonce');

        $last_sync = get_option('amazon_mcf_last_sync', []);
        wp_send_json_success($last_sync);
    }
}
