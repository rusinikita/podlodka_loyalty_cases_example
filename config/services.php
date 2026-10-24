<?php

return [

    // Token of this service in calls to payout-gateway and notifications.
    'service_token' => env('LOYALTY_SERVICE_TOKEN', ''),

    // Token the orders and pricing services send to /v1/orders.
    'orders_token' => env('LOYALTY_ORDERS_TOKEN', ''),

];
