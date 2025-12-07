<?php
require_once __DIR__ . '/vendor/autoload.php';

use SellingPartnerApi\SellingPartnerApi;
use SellingPartnerApi\Enums\Endpoint;

$config = require __DIR__ . '/config.php';

try {
    $baseConnector = new SellingPartnerApi(
        clientId: $config['lwa_client_id'],
        clientSecret: $config['lwa_client_secret'],
        refreshToken: $config['refresh_token'],
        endpoint: Endpoint::NA,
    );

    $connector = $baseConnector->seller();
    $api = $connector->fbaInventoryV1();

    $response = $api->getInventorySummaries(
        granularityType: 'Marketplace',
        granularityId: $config['marketplace_id'],
        marketplaceIds: [$config['marketplace_id']]
    );

    // Use raw JSON due to SDK deserialization bug
    $data = $response->json();

    echo "✓ Inventory retrieved successfully!\n\n";

    if (empty($data['payload']['inventorySummaries'])) {
        echo "No inventory found in FBA.\n";
    } else {
        foreach ($data['payload']['inventorySummaries'] as $item) {
            $fulfillable = $item['inventoryDetails']['fulfillableQuantity'] ?? 0;
            $reserved = $item['inventoryDetails']['reservedQuantity']['totalReservedQuantity'] ?? 0;

            printf(
                "SKU: %-20s | ASIN: %-12s | Fulfillable: %4d | Reserved: %4d\n",
                $item['sellerSku'],
                $item['asin'],
                $fulfillable,
                $reserved
            );
        }
    }

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
