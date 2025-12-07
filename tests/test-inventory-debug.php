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

    $data = $response->json();

    echo "=== RAW API RESPONSE ===\n\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
