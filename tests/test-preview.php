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

// Test item - use one of your actual SKUs
$items = [
    new GetFulfillmentPreviewItem(
        sellerSku: 'YOUR-SKU-HERE',  // Replace with actual SKU
        quantity: 1,
        sellerFulfillmentOrderItemId: 'test-item-1'
    )
];

try {
    $connector = SellingPartnerApi::seller(
        clientId: $config['lwa_client_id'],
        clientSecret: $config['lwa_client_secret'],
        refreshToken: $config['refresh_token'],
        endpoint: Endpoint::NA,
    );

    $api = $connector->fbaOutboundV20200701();

    $request = new GetFulfillmentPreviewRequest(
        marketplaceId: $config['marketplace_id'],
        address: $address,
        items: $items,
        shippingSpeedCategories: ['Standard', 'Expedited', 'Priority']
    );

    $response = $api->getFulfillmentPreview($request);
    $data = $response->dto();

    echo "✓ Fulfillment preview retrieved!\n\n";

    foreach ($data->payload->fulfillmentPreviews as $preview) {
        $fulfillable = $preview->isFulfillable ? 'Yes' : 'No';
        echo "Speed: {$preview->shippingSpeedCategory}\n";
        echo "  Fulfillable: {$fulfillable}\n";

        if ($preview->isFulfillable && !empty($preview->fulfillmentPreviewShipments)) {
            $shipment = $preview->fulfillmentPreviewShipments[0];
            echo "  Earliest Arrival: {$shipment->earliestArrivalDate}\n";
            echo "  Latest Arrival: {$shipment->latestArrivalDate}\n";
        }
        echo "\n";
    }

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
