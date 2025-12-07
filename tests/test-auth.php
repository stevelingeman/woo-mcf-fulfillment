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

    echo "✓ Authentication successful!\n";
    echo "Connector created. Ready to make API calls.\n";

} catch (Exception $e) {
    echo "✗ Authentication failed: " . $e->getMessage() . "\n";
    exit(1);
}
