<?php
require_once __DIR__ . '/vendor/autoload.php';

use SellingPartnerApi\SellingPartnerApi;
use SellingPartnerApi\Enums\Endpoint;

$config = require __DIR__ . '/config.php';

try {
    $connector = SellingPartnerApi::seller(
        clientId: $config['lwa_client_id'],
        clientSecret: $config['lwa_client_secret'],
        refreshToken: $config['refresh_token'],
        endpoint: Endpoint::NA,
    );

    $api = $connector->fbaInventoryV1();

    $response = $api->getInventorySummaries(
        granularityType: 'Marketplace',
        granularityId: $config['marketplace_id'],
        marketplaceIds: [$config['marketplace_id']],
        details: true
    );

    $data = $response->dto();

    echo "✓ Inventory retrieved successfully!\n\n";

    foreach ($data->payload->inventorySummaries as $item) {
        $fulfillable = $item->inventoryDetails->fulfillableQuantity ?? 0;
        $reserved = $item->inventoryDetails->reservedQuantity->totalReservedQuantity ?? 0;

        printf(
            "SKU: %-20s | ASIN: %-12s | Fulfillable: %4d | Reserved: %4d\n",
            $item->sellerSku,
            $item->asin,
            $fulfillable,
            $reserved
        );
    }

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
