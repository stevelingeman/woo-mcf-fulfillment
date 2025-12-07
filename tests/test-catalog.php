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

    // First get our active SKUs from inventory
    $inventoryApi = $connector->fbaInventoryV1();
    $invResponse = $inventoryApi->getInventorySummaries(
        granularityType: 'Marketplace',
        granularityId: $config['marketplace_id'],
        marketplaceIds: [$config['marketplace_id']]
    );
    $invData = $invResponse->json();

    $activeSkus = [];
    foreach ($invData['payload']['inventorySummaries'] as $item) {
        if (($item['totalQuantity'] ?? 0) > 0) {
            $activeSkus[] = [
                'sku' => $item['sellerSku'],
                'asin' => $item['asin'],
                'fnSku' => $item['fnSku'],
                'productName' => $item['productName'],
                'quantity' => $item['totalQuantity']
            ];
        }
    }

    echo "=== ACTIVE SKUs ===\n\n";
    foreach ($activeSkus as $sku) {
        echo "SKU: {$sku['sku']}\n";
        echo "ASIN: {$sku['asin']}\n";
        echo "Name: {$sku['productName']}\n";
        echo "Qty: {$sku['quantity']}\n";
        echo "---\n";
    }

    // Now try to get catalog data for one ASIN
    echo "\n=== CATALOG DATA FOR FIRST ASIN ===\n\n";

    $testAsin = $activeSkus[0]['asin'];
    echo "Fetching catalog data for ASIN: {$testAsin}\n\n";

    $catalogApi = $connector->catalogItemsV20220401();

    $response = $catalogApi->getCatalogItem(
        asin: $testAsin,
        marketplaceIds: [$config['marketplace_id']],
        includedData: ['summaries', 'images', 'productTypes', 'salesRanks', 'attributes']
    );

    $data = $response->json();
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";

    // Show more details if available
    if (method_exists($e, 'getResponse')) {
        $response = $e->getResponse();
        if ($response) {
            echo "Response: " . $response->getBody()->getContents() . "\n";
        }
    }
    exit(1);
}
