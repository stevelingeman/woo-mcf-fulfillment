<?php
/**
 * Amazon SP-API Client
 *
 * Handles authentication and API calls to Amazon Selling Partner API
 */

namespace Amazon_MCF;

defined('ABSPATH') || exit;

class API_Client {

    private string $client_id;
    private string $client_secret;
    private string $refresh_token;
    private string $marketplace_id;
    private string $endpoint = 'https://sellingpartnerapi-na.amazon.com';
    private string $token_endpoint = 'https://api.amazon.com/auth/o2/token';

    private ?string $access_token = null;
    private int $token_expires = 0;

    /**
     * Constructor - load credentials from options
     */
    public function __construct() {
        $settings = get_option('amazon_mcf_settings', []);

        $this->client_id = $settings['lwa_client_id'] ?? '';
        $this->client_secret = $settings['lwa_client_secret'] ?? '';
        $this->refresh_token = $settings['refresh_token'] ?? '';
        $this->marketplace_id = $settings['marketplace_id'] ?? 'ATVPDKIKX0DER';
    }

    /**
     * Check if credentials are configured
     */
    public function has_credentials(): bool {
        return !empty($this->client_id)
            && !empty($this->client_secret)
            && !empty($this->refresh_token);
    }

    /**
     * Get access token (with caching)
     */
    private function get_access_token(): ?string {
        // Return cached token if still valid
        if ($this->access_token && time() < $this->token_expires - 60) {
            return $this->access_token;
        }

        // Check transient cache
        $cached = get_transient('amazon_mcf_access_token');
        if ($cached) {
            $this->access_token = $cached;
            $this->token_expires = time() + 3000; // Assume ~50 min left
            return $this->access_token;
        }

        // Request new token
        $response = wp_remote_post($this->token_endpoint, [
            'timeout' => 30,
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refresh_token,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('Amazon MCF: Token request failed - ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['access_token'])) {
            $this->access_token = $body['access_token'];
            $this->token_expires = time() + ($body['expires_in'] ?? 3600);

            // Cache for 50 minutes (tokens last 1 hour)
            set_transient('amazon_mcf_access_token', $this->access_token, 3000);

            return $this->access_token;
        }

        error_log('Amazon MCF: Token response error - ' . print_r($body, true));
        return null;
    }

    /**
     * Make authenticated API request
     */
    public function request(string $method, string $path, array $query = [], array $body = []): array {
        $token = $this->get_access_token();

        if (!$token) {
            return ['error' => 'Failed to obtain access token'];
        }

        $url = $this->endpoint . $path;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'x-amz-access-token' => $token,
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($body) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return [
                'error' => $data['errors'][0]['message'] ?? 'API request failed',
                'code' => $code,
                'response' => $data,
            ];
        }

        return $data;
    }

    /**
     * Test API connection
     */
    public function test_connection(): array {
        $result = $this->request('GET', '/sellers/v1/marketplaceParticipations');

        if (isset($result['error'])) {
            return [
                'success' => false,
                'message' => $result['error'],
            ];
        }

        $marketplaces = [];
        foreach ($result['payload'] ?? [] as $participation) {
            if ($participation['participation']['isParticipating'] ?? false) {
                $marketplaces[] = $participation['marketplace']['name'] ?? 'Unknown';
            }
        }

        return [
            'success' => true,
            'message' => 'Connected successfully',
            'marketplaces' => $marketplaces,
        ];
    }

    /**
     * Get FBA inventory with quantities > 0
     */
    public function get_active_inventory(): array {
        $result = $this->request('GET', '/fba/inventory/v1/summaries', [
            'granularityType' => 'Marketplace',
            'granularityId' => $this->marketplace_id,
            'marketplaceIds' => $this->marketplace_id,
        ]);

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        $active = [];
        foreach ($result['payload']['inventorySummaries'] ?? [] as $item) {
            $qty = $item['totalQuantity'] ?? 0;
            if ($qty > 0) {
                $active[] = [
                    'sku' => $item['sellerSku'],
                    'asin' => $item['asin'],
                    'fnsku' => $item['fnSku'] ?? '',
                    'name' => $item['productName'] ?? '',
                    'quantity' => $qty,
                ];
            }
        }

        return ['products' => $active];
    }

    /**
     * Get catalog item details by ASIN
     */
    public function get_catalog_item(string $asin): array {
        $result = $this->request('GET', '/catalog/2022-04-01/items/' . $asin, [
            'marketplaceIds' => $this->marketplace_id,
            'includedData' => 'summaries,images,attributes',
        ]);

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return $this->parse_catalog_item($result);
    }

    /**
     * Parse catalog item into simplified structure
     */
    private function parse_catalog_item(array $data): array {
        $attributes = $data['attributes'] ?? [];
        $images = [];
        $summaries = $data['summaries'][0] ?? [];

        // Extract images (prefer largest)
        foreach ($data['images'][0]['images'] ?? [] as $img) {
            $variant = $img['variant'] ?? 'MAIN';
            if (!isset($images[$variant]) || $img['height'] > $images[$variant]['height']) {
                $images[$variant] = [
                    'url' => $img['link'],
                    'width' => $img['width'],
                    'height' => $img['height'],
                ];
            }
        }

        // Extract bullet points
        $bullets = [];
        foreach ($attributes['bullet_point'] ?? [] as $bullet) {
            $bullets[] = $bullet['value'] ?? '';
        }

        // Get first value helper
        $getValue = function($key) use ($attributes) {
            return $attributes[$key][0]['value'] ?? '';
        };

        return [
            'asin' => $data['asin'] ?? '',
            'title' => $getValue('item_name') ?: ($summaries['itemName'] ?? ''),
            'brand' => $getValue('brand') ?: ($summaries['brand'] ?? ''),
            'description' => $getValue('product_description'),
            'bullets' => $bullets,
            'price' => $getValue('list_price'),
            'images' => array_values($images),
            'weight' => $attributes['item_weight'][0] ?? null,
            'dimensions' => $attributes['item_package_dimensions'][0] ?? null,
            'upc' => $this->find_identifier($attributes, 'upc'),
            'ean' => $this->find_identifier($attributes, 'ean'),
        ];
    }

    /**
     * Find product identifier by type
     */
    private function find_identifier(array $attributes, string $type): string {
        foreach ($attributes['externally_assigned_product_identifier'] ?? [] as $id) {
            if (($id['type'] ?? '') === $type) {
                return $id['value'] ?? '';
            }
        }
        return '';
    }

    /**
     * Get marketplace ID
     */
    public function get_marketplace_id(): string {
        return $this->marketplace_id;
    }

    /**
     * Create MCF fulfillment order
     *
     * @param array $order_data Fulfillment order data
     * @return array Response with fulfillment order ID or error
     */
    public function create_fulfillment_order(array $order_data): array {
        $result = $this->request('POST', '/fba/outbound/2020-07-01/fulfillmentOrders', [], $order_data);

        if (isset($result['error'])) {
            return ['error' => $result['error'], 'response' => $result['response'] ?? null];
        }

        return ['success' => true];
    }

    /**
     * Get fulfillment order status
     *
     * @param string $seller_fulfillment_order_id The order ID we assigned
     * @return array Order status data or error
     */
    public function get_fulfillment_order(string $seller_fulfillment_order_id): array {
        $result = $this->request('GET', '/fba/outbound/2020-07-01/fulfillmentOrders/' . $seller_fulfillment_order_id);

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return $result['payload'] ?? $result;
    }

    /**
     * Cancel fulfillment order
     *
     * @param string $seller_fulfillment_order_id The order ID to cancel
     * @return array Success or error
     */
    public function cancel_fulfillment_order(string $seller_fulfillment_order_id): array {
        $result = $this->request('PUT', '/fba/outbound/2020-07-01/fulfillmentOrders/' . $seller_fulfillment_order_id . '/cancel');

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return ['success' => true];
    }

    /**
     * Get fulfillment preview (delivery estimates)
     *
     * @param array $address Shipping address
     * @param array $items Array of items with sku and quantity
     * @return array Preview data or error
     */
    public function get_fulfillment_preview(array $address, array $items): array {
        $request_body = [
            'marketplaceId' => $this->marketplace_id,
            'address' => $address,
            'items' => $items,
            'shippingSpeedCategories' => ['Standard', 'Expedited', 'Priority'],
        ];

        $result = $this->request('POST', '/fba/outbound/2020-07-01/fulfillmentOrders/preview', [], $request_body);

        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return $result['payload'] ?? $result;
    }
}
