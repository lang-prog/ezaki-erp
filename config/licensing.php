<?php

return [
    'edition' => env('APP_EDITION', 'saas'),
    'public_key' => env('LOCAL_LICENSE_PUBLIC_KEY'),
    'installation_id' => env('LOCAL_LICENSE_INSTALLATION_ID'),
    'customer_binding' => env('LOCAL_LICENSE_CUSTOMER_BINDING'),
];
