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

    echo "✓ Connector created successfully!\n";
    echo "Testing actual API call...\n";

    // Make a real API call to verify credentials work
    $api = $connector->sellersV1();
    $response = $api->getMarketplaceParticipations();
    $data = $response->dto();

    echo "✓ Authentication successful!\n\n";
    echo "Marketplaces you're registered in:\n";
    foreach ($data->payload as $participation) {
        $marketplace = $participation->marketplace;
        $status = $participation->participation->isParticipating ? 'Active' : 'Inactive';
        echo "  - {$marketplace->name} ({$marketplace->id}): {$status}\n";
    }

} catch (Exception $e) {
    echo "✗ Authentication failed: " . $e->getMessage() . "\n";
    exit(1);
}
