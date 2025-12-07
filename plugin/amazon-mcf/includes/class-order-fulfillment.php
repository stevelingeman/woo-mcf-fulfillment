<?php
/**
 * Order Fulfillment Handler
 *
 * Handles sending WooCommerce orders to Amazon MCF for fulfillment
 */

namespace Amazon_MCF;

defined('ABSPATH') || exit;

class Order_Fulfillment {

    /**
     * Constructor
     */
    public function __construct() {
        // Auto-submit to MCF when order becomes "processing" (paid)
        add_action('woocommerce_order_status_processing', [$this, 'submit_order_to_mcf'], 10, 2);

        // Add MCF metabox to order page
        add_action('add_meta_boxes', [$this, 'add_mcf_metabox']);

        // AJAX handlers
        add_action('wp_ajax_amazon_mcf_submit_order', [$this, 'ajax_submit_order']);
        add_action('wp_ajax_amazon_mcf_refresh_status', [$this, 'ajax_refresh_status']);
        add_action('wp_ajax_amazon_mcf_cancel_fulfillment', [$this, 'ajax_cancel_fulfillment']);

        // Add order list column
        add_filter('manage_edit-shop_order_columns', [$this, 'add_order_column']);
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'add_order_column']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_order_column'], 10, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_order_column_hpos'], 10, 2);
    }

    /**
     * Submit order to Amazon MCF
     *
     * @param int $order_id WooCommerce order ID
     * @param \WC_Order|null $order Order object
     * @return bool Success or failure
     */
    public function submit_order_to_mcf(int $order_id, $order = null): bool {
        if (!$order) {
            $order = wc_get_order($order_id);
        }

        if (!$order) {
            return false;
        }

        // Check if already submitted
        $mcf_order_id = $order->get_meta('_amazon_mcf_order_id');
        if (!empty($mcf_order_id)) {
            $order->add_order_note(__('MCF: Order already submitted to Amazon MCF.', 'amazon-mcf'));
            return false;
        }

        // Check if all items have Amazon SKUs
        $items = $this->get_mcf_items($order);
        if (empty($items)) {
            $order->add_order_note(__('MCF: No items with Amazon SKUs found. Skipping MCF fulfillment.', 'amazon-mcf'));
            return false;
        }

        // Build fulfillment order request
        $mcf_order_id = 'WOO-' . $order_id . '-' . time();
        $request_data = $this->build_fulfillment_request($order, $mcf_order_id, $items);

        // Submit to Amazon
        $client = new API_Client();
        $result = $client->create_fulfillment_order($request_data);

        if (isset($result['error'])) {
            $error_msg = sprintf(
                __('MCF: Failed to submit order - %s', 'amazon-mcf'),
                $result['error']
            );
            $order->add_order_note($error_msg);
            $order->update_meta_data('_amazon_mcf_error', $result['error']);
            $order->save();

            error_log('Amazon MCF Error for order ' . $order_id . ': ' . print_r($result, true));
            return false;
        }

        // Store MCF order ID
        $order->update_meta_data('_amazon_mcf_order_id', $mcf_order_id);
        $order->update_meta_data('_amazon_mcf_status', 'RECEIVED');
        $order->update_meta_data('_amazon_mcf_submitted_at', current_time('mysql'));
        $order->delete_meta_data('_amazon_mcf_error');
        $order->save();

        $order->add_order_note(sprintf(
            __('MCF: Order submitted to Amazon MCF. Fulfillment ID: %s', 'amazon-mcf'),
            $mcf_order_id
        ));

        return true;
    }

    /**
     * Get items formatted for MCF
     */
    private function get_mcf_items(\WC_Order $order): array {
        $items = [];
        $item_index = 1;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $sku = $product->get_sku();
            if (empty($sku)) continue;

            // Check if this product was imported from Amazon
            $amazon_sku = get_post_meta($product->get_id(), '_amazon_sku', true);
            if (empty($amazon_sku)) {
                $amazon_sku = $sku; // Use WooCommerce SKU if no Amazon SKU stored
            }

            $items[] = [
                'sellerSku' => $amazon_sku,
                'sellerFulfillmentOrderItemId' => $order->get_id() . '-' . $item_index,
                'quantity' => $item->get_quantity(),
            ];

            $item_index++;
        }

        return $items;
    }

    /**
     * Build the fulfillment order request body
     */
    private function build_fulfillment_request(\WC_Order $order, string $mcf_order_id, array $items): array {
        $client = new API_Client();

        return [
            'sellerFulfillmentOrderId' => $mcf_order_id,
            'displayableOrderId' => (string) $order->get_order_number(),
            'displayableOrderDate' => $order->get_date_created()->format('c'),
            'displayableOrderComment' => sprintf('WooCommerce Order #%s', $order->get_order_number()),
            'shippingSpeedCategory' => 'Standard', // Could make this configurable
            'fulfillmentAction' => 'Ship',
            'fulfillmentPolicy' => 'FillOrKill', // Fail if any item unavailable
            'destinationAddress' => [
                'name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                'addressLine1' => $order->get_shipping_address_1(),
                'addressLine2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'stateOrRegion' => $order->get_shipping_state(),
                'postalCode' => $order->get_shipping_postcode(),
                'countryCode' => $order->get_shipping_country(),
                'phone' => $order->get_billing_phone(),
            ],
            'marketplaceId' => $client->get_marketplace_id(),
            'items' => $items,
            'notificationEmails' => [$order->get_billing_email()],
        ];
    }

    /**
     * Add MCF metabox to order edit page
     */
    public function add_mcf_metabox(): void {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'amazon_mcf_fulfillment',
            __('Amazon MCF Fulfillment', 'amazon-mcf'),
            [$this, 'render_mcf_metabox'],
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Render MCF metabox content
     */
    public function render_mcf_metabox($post_or_order): void {
        $order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) return;

        $mcf_order_id = $order->get_meta('_amazon_mcf_order_id');
        $mcf_status = $order->get_meta('_amazon_mcf_status');
        $mcf_error = $order->get_meta('_amazon_mcf_error');
        $mcf_tracking = $order->get_meta('_amazon_mcf_tracking');
        $submitted_at = $order->get_meta('_amazon_mcf_submitted_at');

        wp_nonce_field('amazon_mcf_order_action', 'amazon_mcf_nonce');
        ?>
        <div id="amazon-mcf-metabox">
            <?php if ($mcf_order_id): ?>
                <p><strong><?php esc_html_e('MCF Order ID:', 'amazon-mcf'); ?></strong><br>
                <code><?php echo esc_html($mcf_order_id); ?></code></p>

                <p><strong><?php esc_html_e('Status:', 'amazon-mcf'); ?></strong><br>
                <span id="mcf-status" class="mcf-status-<?php echo esc_attr(strtolower($mcf_status)); ?>">
                    <?php echo esc_html($mcf_status); ?>
                </span></p>

                <?php if ($submitted_at): ?>
                <p><strong><?php esc_html_e('Submitted:', 'amazon-mcf'); ?></strong><br>
                <?php echo esc_html($submitted_at); ?></p>
                <?php endif; ?>

                <?php if ($mcf_tracking): ?>
                <p><strong><?php esc_html_e('Tracking:', 'amazon-mcf'); ?></strong><br>
                <?php echo esc_html($mcf_tracking); ?></p>
                <?php endif; ?>

                <p>
                    <button type="button" class="button" id="mcf-refresh-status" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                        <?php esc_html_e('Refresh Status', 'amazon-mcf'); ?>
                    </button>

                    <?php if (in_array($mcf_status, ['RECEIVED', 'PLANNING'])): ?>
                    <button type="button" class="button" id="mcf-cancel" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                        <?php esc_html_e('Cancel', 'amazon-mcf'); ?>
                    </button>
                    <?php endif; ?>
                </p>

            <?php elseif ($mcf_error): ?>
                <p class="mcf-error"><strong><?php esc_html_e('Error:', 'amazon-mcf'); ?></strong><br>
                <?php echo esc_html($mcf_error); ?></p>

                <button type="button" class="button button-primary" id="mcf-submit-order" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php esc_html_e('Retry Submit to MCF', 'amazon-mcf'); ?>
                </button>

            <?php else: ?>
                <p><?php esc_html_e('Order has not been submitted to Amazon MCF.', 'amazon-mcf'); ?></p>

                <button type="button" class="button button-primary" id="mcf-submit-order" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php esc_html_e('Submit to MCF', 'amazon-mcf'); ?>
                </button>
            <?php endif; ?>

            <span id="mcf-action-status"></span>
        </div>

        <style>
            .mcf-status-received, .mcf-status-planning { color: #0073aa; }
            .mcf-status-processing { color: #ffb900; }
            .mcf-status-complete, .mcf-status-shipped { color: #46b450; }
            .mcf-status-cancelled, .mcf-status-unfulfillable { color: #dc3232; }
            .mcf-error { color: #dc3232; }
            #amazon-mcf-metabox .button { margin: 2px; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            var nonce = $('#amazon_mcf_nonce').val();

            $('#mcf-submit-order').on('click', function() {
                var $btn = $(this);
                var orderId = $btn.data('order-id');
                $btn.prop('disabled', true);
                $('#mcf-action-status').text('<?php esc_html_e('Submitting...', 'amazon-mcf'); ?>');

                $.post(ajaxurl, {
                    action: 'amazon_mcf_submit_order',
                    nonce: nonce,
                    order_id: orderId
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        $btn.prop('disabled', false);
                        $('#mcf-action-status').html('<span style="color:red;">' + response.data.message + '</span>');
                    }
                });
            });

            $('#mcf-refresh-status').on('click', function() {
                var $btn = $(this);
                var orderId = $btn.data('order-id');
                $btn.prop('disabled', true);
                $('#mcf-action-status').text('<?php esc_html_e('Refreshing...', 'amazon-mcf'); ?>');

                $.post(ajaxurl, {
                    action: 'amazon_mcf_refresh_status',
                    nonce: nonce,
                    order_id: orderId
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        location.reload();
                    } else {
                        $('#mcf-action-status').html('<span style="color:red;">' + response.data.message + '</span>');
                    }
                });
            });

            $('#mcf-cancel').on('click', function() {
                if (!confirm('<?php esc_html_e('Are you sure you want to cancel this MCF fulfillment?', 'amazon-mcf'); ?>')) {
                    return;
                }

                var $btn = $(this);
                var orderId = $btn.data('order-id');
                $btn.prop('disabled', true);
                $('#mcf-action-status').text('<?php esc_html_e('Cancelling...', 'amazon-mcf'); ?>');

                $.post(ajaxurl, {
                    action: 'amazon_mcf_cancel_fulfillment',
                    nonce: nonce,
                    order_id: orderId
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        $btn.prop('disabled', false);
                        $('#mcf-action-status').html('<span style="color:red;">' + response.data.message + '</span>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Submit order to MCF
     */
    public function ajax_submit_order(): void {
        check_ajax_referer('amazon_mcf_order_action', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(['message' => 'Invalid order ID']);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }

        // Clear any previous error
        $order->delete_meta_data('_amazon_mcf_error');
        $order->save();

        $result = $this->submit_order_to_mcf($order_id, $order);

        if ($result) {
            wp_send_json_success(['message' => 'Order submitted to MCF']);
        } else {
            $error = $order->get_meta('_amazon_mcf_error') ?: 'Unknown error';
            wp_send_json_error(['message' => $error]);
        }
    }

    /**
     * AJAX: Refresh MCF status
     */
    public function ajax_refresh_status(): void {
        check_ajax_referer('amazon_mcf_order_action', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }

        $mcf_order_id = $order->get_meta('_amazon_mcf_order_id');
        if (!$mcf_order_id) {
            wp_send_json_error(['message' => 'No MCF order ID found']);
        }

        $client = new API_Client();
        $result = $client->get_fulfillment_order($mcf_order_id);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        // Update status
        $new_status = $result['fulfillmentOrder']['fulfillmentOrderStatus'] ?? 'UNKNOWN';
        $order->update_meta_data('_amazon_mcf_status', $new_status);

        // Check for tracking info
        if (!empty($result['fulfillmentShipments'])) {
            foreach ($result['fulfillmentShipments'] as $shipment) {
                if (!empty($shipment['fulfillmentShipmentPackage'])) {
                    foreach ($shipment['fulfillmentShipmentPackage'] as $package) {
                        if (!empty($package['trackingNumber'])) {
                            $order->update_meta_data('_amazon_mcf_tracking', $package['trackingNumber']);
                            $order->update_meta_data('_amazon_mcf_carrier', $package['carrierCode'] ?? '');
                            break 2;
                        }
                    }
                }
            }
        }

        // Update WooCommerce order status based on MCF status
        $status_upper = strtoupper($new_status);
        if (in_array($status_upper, ['COMPLETE', 'COMPLETE_PARTIALLED'])) {
            $order->update_status('completed', __('MCF: Order fulfilled by Amazon', 'amazon-mcf'));
        } elseif (in_array($status_upper, ['CANCELLED', 'UNFULFILLABLE'])) {
            $order->update_status('cancelled', sprintf(__('MCF: Fulfillment %s', 'amazon-mcf'), strtolower($new_status)));
        } else {
            $order->save();
        }

        wp_send_json_success([
            'status' => $new_status,
            'message' => 'Status updated',
        ]);
    }

    /**
     * AJAX: Cancel MCF fulfillment
     */
    public function ajax_cancel_fulfillment(): void {
        check_ajax_referer('amazon_mcf_order_action', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }

        $mcf_order_id = $order->get_meta('_amazon_mcf_order_id');
        if (!$mcf_order_id) {
            wp_send_json_error(['message' => 'No MCF order ID found']);
        }

        $client = new API_Client();
        $result = $client->cancel_fulfillment_order($mcf_order_id);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        $order->update_meta_data('_amazon_mcf_status', 'CANCELLED');
        $order->add_order_note(__('MCF: Fulfillment cancelled', 'amazon-mcf'));
        $order->save();

        wp_send_json_success(['message' => 'Fulfillment cancelled']);
    }

    /**
     * Add MCF column to orders list
     */
    public function add_order_column(array $columns): array {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'order_status') {
                $new_columns['mcf_status'] = __('MCF', 'amazon-mcf');
            }
        }
        return $new_columns;
    }

    /**
     * Render MCF column (legacy)
     */
    public function render_order_column(string $column, int $post_id): void {
        if ($column !== 'mcf_status') return;

        $order = wc_get_order($post_id);
        if (!$order) return;

        $this->output_mcf_status_badge($order);
    }

    /**
     * Render MCF column (HPOS)
     */
    public function render_order_column_hpos(string $column, $order): void {
        if ($column !== 'mcf_status') return;

        $this->output_mcf_status_badge($order);
    }

    /**
     * Output MCF status badge
     */
    private function output_mcf_status_badge($order): void {
        $status = $order->get_meta('_amazon_mcf_status');
        $error = $order->get_meta('_amazon_mcf_error');

        if ($status) {
            $class = 'mcf-badge mcf-' . strtolower($status);
            echo '<span class="' . esc_attr($class) . '">' . esc_html($status) . '</span>';
        } elseif ($error) {
            echo '<span class="mcf-badge mcf-error" title="' . esc_attr($error) . '">ERROR</span>';
        } else {
            echo '<span class="mcf-badge mcf-pending">—</span>';
        }
    }
}
