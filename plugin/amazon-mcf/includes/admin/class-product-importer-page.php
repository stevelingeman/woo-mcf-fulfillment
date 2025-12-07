<?php
/**
 * Product Importer Admin Page
 *
 * Handles the product import wizard UI and AJAX handlers
 */

namespace Amazon_MCF\Admin;

defined('ABSPATH') || exit;

class Product_Importer {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_amazon_mcf_fetch_products', [$this, 'ajax_fetch_products']);
        add_action('wp_ajax_amazon_mcf_import_product', [$this, 'ajax_import_product']);
    }

    /**
     * Render the import page
     */
    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $client = new \Amazon_MCF\API_Client();
        $has_credentials = $client->has_credentials();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Import Products from Amazon', 'amazon-mcf'); ?></h1>

            <?php if (!$has_credentials): ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e('Please configure your SP-API credentials first.', 'amazon-mcf'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=amazon-mcf')); ?>">
                            <?php esc_html_e('Go to Settings', 'amazon-mcf'); ?>
                        </a>
                    </p>
                </div>
            <?php else: ?>
                <div id="amazon-mcf-importer">
                    <div class="amazon-mcf-step" id="step-1">
                        <h2><?php esc_html_e('Step 1: Fetch Products from Amazon FBA', 'amazon-mcf'); ?></h2>
                        <p><?php esc_html_e('Click the button below to retrieve your active FBA inventory.', 'amazon-mcf'); ?></p>
                        <button type="button" id="fetch-products-btn" class="button button-primary">
                            <?php esc_html_e('Fetch Products', 'amazon-mcf'); ?>
                        </button>
                        <span id="fetch-status"></span>
                    </div>

                    <div class="amazon-mcf-step" id="step-2" style="display:none;">
                        <h2><?php esc_html_e('Step 2: Select Products to Import', 'amazon-mcf'); ?></h2>
                        <p><?php esc_html_e('Select the products you want to import into WooCommerce.', 'amazon-mcf'); ?></p>

                        <table class="wp-list-table widefat fixed striped" id="products-table">
                            <thead>
                                <tr>
                                    <th class="check-column"><input type="checkbox" id="select-all"></th>
                                    <th><?php esc_html_e('SKU', 'amazon-mcf'); ?></th>
                                    <th><?php esc_html_e('ASIN', 'amazon-mcf'); ?></th>
                                    <th><?php esc_html_e('Product Name', 'amazon-mcf'); ?></th>
                                    <th><?php esc_html_e('FBA Qty', 'amazon-mcf'); ?></th>
                                    <th><?php esc_html_e('Status', 'amazon-mcf'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="products-list">
                            </tbody>
                        </table>

                        <p style="margin-top:20px;">
                            <button type="button" id="import-selected-btn" class="button button-primary" disabled>
                                <?php esc_html_e('Import Selected Products', 'amazon-mcf'); ?>
                            </button>
                            <span id="import-status"></span>
                        </p>
                    </div>

                    <div class="amazon-mcf-step" id="step-3" style="display:none;">
                        <h2><?php esc_html_e('Import Complete!', 'amazon-mcf'); ?></h2>
                        <p id="import-summary"></p>
                        <p>
                            <a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>" class="button">
                                <?php esc_html_e('View Products', 'amazon-mcf'); ?>
                            </a>
                            <button type="button" id="import-more-btn" class="button">
                                <?php esc_html_e('Import More Products', 'amazon-mcf'); ?>
                            </button>
                        </p>
                    </div>
                </div>

                <style>
                    .amazon-mcf-step { margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; }
                    #products-table { margin-top: 15px; }
                    #products-table .check-column { width: 30px; }
                    .import-status-pending { color: #666; }
                    .import-status-importing { color: #0073aa; }
                    .import-status-success { color: #46b450; }
                    .import-status-error { color: #dc3232; }
                    .import-status-exists { color: #ffb900; }
                </style>

                <script>
                jQuery(document).ready(function($) {
                    var products = [];

                    // Fetch products from Amazon
                    $('#fetch-products-btn').on('click', function() {
                        var $btn = $(this);
                        var $status = $('#fetch-status');

                        $btn.prop('disabled', true);
                        $status.html('<span style="color:#666;">Fetching inventory from Amazon...</span>');

                        $.post(ajaxurl, {
                            action: 'amazon_mcf_fetch_products',
                            nonce: '<?php echo esc_js(wp_create_nonce('amazon_mcf_import')); ?>'
                        }, function(response) {
                            $btn.prop('disabled', false);

                            if (response.success) {
                                products = response.data.products;
                                renderProducts(products);
                                $status.html('<span style="color:green;">✓ Found ' + products.length + ' products</span>');
                                $('#step-2').show();
                            } else {
                                $status.html('<span style="color:red;">✗ ' + response.data.message + '</span>');
                            }
                        }).fail(function() {
                            $btn.prop('disabled', false);
                            $status.html('<span style="color:red;">✗ Request failed</span>');
                        });
                    });

                    // Render products table
                    function renderProducts(products) {
                        var $list = $('#products-list');
                        $list.empty();

                        products.forEach(function(product) {
                            var existsClass = product.exists ? 'import-status-exists' : '';
                            var existsText = product.exists ? '<?php esc_html_e('Already exists', 'amazon-mcf'); ?>' : '<?php esc_html_e('Ready', 'amazon-mcf'); ?>';

                            var row = '<tr data-sku="' + product.sku + '" data-asin="' + product.asin + '">' +
                                '<td><input type="checkbox" class="product-checkbox" ' + (product.exists ? 'disabled' : '') + '></td>' +
                                '<td>' + escapeHtml(product.sku) + '</td>' +
                                '<td>' + escapeHtml(product.asin) + '</td>' +
                                '<td>' + escapeHtml(product.name) + '</td>' +
                                '<td>' + product.quantity + '</td>' +
                                '<td class="import-status ' + existsClass + '">' + existsText + '</td>' +
                                '</tr>';
                            $list.append(row);
                        });

                        updateImportButton();
                    }

                    // Select all checkbox
                    $('#select-all').on('change', function() {
                        var checked = $(this).is(':checked');
                        $('.product-checkbox:not(:disabled)').prop('checked', checked);
                        updateImportButton();
                    });

                    // Individual checkbox
                    $(document).on('change', '.product-checkbox', function() {
                        updateImportButton();
                    });

                    // Update import button state
                    function updateImportButton() {
                        var count = $('.product-checkbox:checked').length;
                        $('#import-selected-btn')
                            .prop('disabled', count === 0)
                            .text(count > 0 ? '<?php esc_html_e('Import', 'amazon-mcf'); ?> (' + count + ')' : '<?php esc_html_e('Import Selected Products', 'amazon-mcf'); ?>');
                    }

                    // Import selected products
                    $('#import-selected-btn').on('click', function() {
                        var $btn = $(this);
                        var $rows = $('#products-list tr').has('.product-checkbox:checked');

                        if ($rows.length === 0) return;

                        $btn.prop('disabled', true);
                        $('#import-status').html('<span style="color:#666;">Importing...</span>');

                        var queue = [];
                        $rows.each(function() {
                            queue.push({
                                sku: $(this).data('sku'),
                                asin: $(this).data('asin'),
                                $row: $(this)
                            });
                        });

                        var imported = 0;
                        var failed = 0;

                        function processNext() {
                            if (queue.length === 0) {
                                // Done
                                $('#step-3').show();
                                $('#import-summary').html(
                                    '<?php esc_html_e('Successfully imported', 'amazon-mcf'); ?> <strong>' + imported + '</strong> <?php esc_html_e('products.', 'amazon-mcf'); ?>' +
                                    (failed > 0 ? ' <span style="color:red;">' + failed + ' <?php esc_html_e('failed.', 'amazon-mcf'); ?></span>' : '')
                                );
                                $('#import-status').html('<span style="color:green;">✓ Complete</span>');
                                return;
                            }

                            var item = queue.shift();
                            item.$row.find('.import-status')
                                .removeClass('import-status-pending import-status-success import-status-error import-status-exists')
                                .addClass('import-status-importing')
                                .text('<?php esc_html_e('Importing...', 'amazon-mcf'); ?>');

                            $.post(ajaxurl, {
                                action: 'amazon_mcf_import_product',
                                nonce: '<?php echo esc_js(wp_create_nonce('amazon_mcf_import')); ?>',
                                sku: item.sku,
                                asin: item.asin
                            }, function(response) {
                                if (response.success) {
                                    imported++;
                                    item.$row.find('.import-status')
                                        .removeClass('import-status-importing')
                                        .addClass('import-status-success')
                                        .html('<a href="' + response.data.edit_url + '" target="_blank"><?php esc_html_e('Imported', 'amazon-mcf'); ?> ✓</a>');
                                    item.$row.find('.product-checkbox').prop('checked', false).prop('disabled', true);
                                } else {
                                    failed++;
                                    item.$row.find('.import-status')
                                        .removeClass('import-status-importing')
                                        .addClass('import-status-error')
                                        .text('<?php esc_html_e('Error:', 'amazon-mcf'); ?> ' + response.data.message);
                                }

                                $('#import-status').html('<span style="color:#666;"><?php esc_html_e('Importing...', 'amazon-mcf'); ?> ' + imported + '/' + (imported + failed + queue.length) + '</span>');
                                processNext();
                            }).fail(function() {
                                failed++;
                                item.$row.find('.import-status')
                                    .removeClass('import-status-importing')
                                    .addClass('import-status-error')
                                    .text('<?php esc_html_e('Request failed', 'amazon-mcf'); ?>');
                                processNext();
                            });
                        }

                        processNext();
                    });

                    // Import more
                    $('#import-more-btn').on('click', function() {
                        $('#step-3').hide();
                        $('#fetch-products-btn').click();
                    });

                    // Escape HTML helper
                    function escapeHtml(text) {
                        var div = document.createElement('div');
                        div.textContent = text;
                        return div.innerHTML;
                    }
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: Fetch products from Amazon
     */
    public function ajax_fetch_products(): void {
        check_ajax_referer('amazon_mcf_import', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $client = new \Amazon_MCF\API_Client();
        $result = $client->get_active_inventory();

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        // Check which products already exist in WooCommerce
        foreach ($result['products'] as &$product) {
            $existing = wc_get_product_id_by_sku($product['sku']);
            $product['exists'] = !empty($existing);
            $product['wc_id'] = $existing ?: null;
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Import single product
     */
    public function ajax_import_product(): void {
        check_ajax_referer('amazon_mcf_import', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $asin = sanitize_text_field($_POST['asin'] ?? '');

        if (empty($sku) || empty($asin)) {
            wp_send_json_error(['message' => 'Missing SKU or ASIN']);
        }

        // Check if already exists
        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            wp_send_json_error(['message' => 'Product already exists']);
        }

        // Fetch catalog data
        $client = new \Amazon_MCF\API_Client();
        $catalog = $client->get_catalog_item($asin);

        if (isset($catalog['error'])) {
            wp_send_json_error(['message' => $catalog['error']]);
        }

        // Get inventory for stock qty
        $inventory = $client->get_active_inventory();
        $stock_qty = 0;
        foreach ($inventory['products'] ?? [] as $inv) {
            if ($inv['sku'] === $sku) {
                $stock_qty = $inv['quantity'];
                break;
            }
        }

        // Create WooCommerce product
        try {
            $product_id = $this->create_wc_product($sku, $catalog, $stock_qty);

            wp_send_json_success([
                'product_id' => $product_id,
                'edit_url' => admin_url('post.php?post=' . $product_id . '&action=edit'),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Create WooCommerce product from Amazon catalog data
     */
    private function create_wc_product(string $sku, array $catalog, int $stock_qty): int {
        $product = new \WC_Product_Simple();

        // Basic info
        $product->set_name($catalog['title']);
        $product->set_sku($sku);
        $product->set_status('draft'); // Start as draft for review

        // Description - combine description and bullets
        $description = $catalog['description'] ?? '';
        if (!empty($catalog['bullets'])) {
            $bullets_html = '<ul><li>' . implode('</li><li>', array_map('esc_html', $catalog['bullets'])) . '</li></ul>';
            $description .= "\n\n" . $bullets_html;
        }
        $product->set_description($description);

        // Short description from first bullet
        if (!empty($catalog['bullets'][0])) {
            $product->set_short_description($catalog['bullets'][0]);
        }

        // Price
        if (!empty($catalog['price'])) {
            $price = is_array($catalog['price']) ? $catalog['price'] : $catalog['price'];
            $product->set_regular_price((string) $price);
        }

        // Stock
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock_qty);
        $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');

        // Weight
        if (!empty($catalog['weight'])) {
            $weight_g = $catalog['weight']['value'] ?? 0;
            $weight_kg = $weight_g / 1000;
            $product->set_weight((string) $weight_kg);
        }

        // Dimensions
        if (!empty($catalog['dimensions'])) {
            $dims = $catalog['dimensions'];
            // Convert cm to store units if needed
            if (isset($dims['length']['value'])) {
                $product->set_length((string) $dims['length']['value']);
            }
            if (isset($dims['width']['value'])) {
                $product->set_width((string) $dims['width']['value']);
            }
            if (isset($dims['height']['value'])) {
                $product->set_height((string) $dims['height']['value']);
            }
        }

        // Save product first to get ID
        $product_id = $product->save();

        // Store Amazon metadata
        update_post_meta($product_id, '_amazon_asin', $catalog['asin']);
        update_post_meta($product_id, '_amazon_sku', $sku);

        // Import images
        if (!empty($catalog['images'])) {
            $this->import_product_images($product_id, $catalog['images']);
        }

        return $product_id;
    }

    /**
     * Import product images from Amazon
     */
    private function import_product_images(int $product_id, array $images): void {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $image_ids = [];

        foreach ($images as $index => $image) {
            $url = $image['url'] ?? '';
            if (empty($url)) continue;

            // Download image
            $tmp = download_url($url);

            if (is_wp_error($tmp)) {
                error_log('Amazon MCF: Failed to download image - ' . $tmp->get_error_message());
                continue;
            }

            // Prepare file array
            $file_array = [
                'name' => basename(parse_url($url, PHP_URL_PATH)),
                'tmp_name' => $tmp,
            ];

            // Import into media library
            $attachment_id = media_handle_sideload($file_array, $product_id);

            // Clean up temp file
            if (file_exists($tmp)) {
                @unlink($tmp);
            }

            if (is_wp_error($attachment_id)) {
                error_log('Amazon MCF: Failed to sideload image - ' . $attachment_id->get_error_message());
                continue;
            }

            $image_ids[] = $attachment_id;
        }

        // Set featured image (first image)
        if (!empty($image_ids)) {
            set_post_thumbnail($product_id, $image_ids[0]);

            // Set gallery images (remaining)
            if (count($image_ids) > 1) {
                $gallery_ids = array_slice($image_ids, 1);
                update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
            }
        }
    }
}
