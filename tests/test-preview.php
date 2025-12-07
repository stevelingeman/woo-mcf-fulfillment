<?php
require_once __DIR__ . '/vendor/autoload.php';

use SellingPartnerApi\SellingPartnerApi;
use SellingPartnerApi\Enums\Endpoint;
use SellingPartnerApi\Seller\FBAOutboundV20200701\Dto\Address;
use SellingPartnerApi\Seller\FBAOutboundV20200701\Dto\GetFulfillmentPreviewItem;
use SellingPartnerApi\Seller\FBAOutboundV20200701\Dto\GetFulfillmentPreviewRequest;

$config = require __DIR__ . '/config.php';

// Test address
$address = new Address(
    name: 'Test Customer',
    addressLine1: '123 Main Street',
    city: 'Seattle',
    stateOrRegion: 'WA',
    postalCode: '98101',
    countryCode: 'US'
);

// Use first SKU from your inventory - replace if needed
$testSku = $argv[1] ?? '1U-9KEJ-M7PL';

$items = [
    new GetFulfillmentPreviewItem(
        sellerSku: $testSku,
        quantity: 1,
        sellerFulfillmentOrderItemId: 'test-item-1'
    )
];

try {
    $baseConnector = new SellingPartnerApi(
        clientId: $config['lwa_client_id'],
        clientSecret: $config['lwa_client_secret'],
        refreshToken: $config['refresh_token'],
        endpoint: Endpoint::NA,
    );

    $connector = $baseConnector->seller();
    $api = $connector->fbaOutboundV20200701();

    $request = new GetFulfillmentPreviewRequest(
        marketplaceId: $config['marketplace_id'],
        address: $address,
        items: $items,
        shippingSpeedCategories: ['Standard', 'Expedited', 'Priority']
    );

    $response = $api->getFulfillmentPreview($request);

    // Use raw JSON due to potential SDK deserialization issues
    $data = $response->json();

    echo "✓ Fulfillment preview retrieved for SKU: {$testSku}\n\n";

    if (empty($data['payload']['fulfillmentPreviews'])) {
        echo "No fulfillment previews returned.\n";
    } else {
        foreach ($data['payload']['fulfillmentPreviews'] as $preview) {
            $fulfillable = $preview['isFulfillable'] ? 'Yes' : 'No';
            echo "Speed: {$preview['shippingSpeedCategory']}\n";
            echo "  Fulfillable: {$fulfillable}\n";

            if (!$preview['isFulfillable'] && !empty($preview['unfulfillablePreviewItems'])) {
                foreach ($preview['unfulfillablePreviewItems'] as $unfulfillable) {
                    $reasons = $unfulfillable['itemUnfulfillableReasons'] ?? [];
                    if (!empty($reasons)) {
                        echo "  Reason: {$reasons[0]}\n";
                    }
                }
            }

            if ($preview['isFulfillable'] && !empty($preview['fulfillmentPreviewShipments'])) {
                $shipment = $preview['fulfillmentPreviewShipments'][0];
                echo "  Earliest Arrival: {$shipment['earliestArrivalDate']}\n";
                echo "  Latest Arrival: {$shipment['latestArrivalDate']}\n";
            }
            echo "\n";
        }
    }

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
