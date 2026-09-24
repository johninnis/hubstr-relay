<?php

declare(strict_types=1);

return [
    'admin_pubkey' => 'YOUR_NPUB_OR_HEX_PUBKEY_HERE',

    'host' => '127.0.0.1',
    'port' => 8080,
    'relay_url' => 'wss://relay.example.com',

    'connection_limits' => [
        'max_connections' => 100,
    ],

    'database_path' => dirname(__DIR__).'/data/hubstr-relay.sqlite',

    'name' => 'Hubstr Relay',
    'description' => 'A personal Nostr relay',
    'contact' => 'mailto:admin@example.com',
    'icon' => 'https://relay.example.com/img/logo.svg',

    'limits' => [
        'max_subscriptions' => 20,
        'max_filters' => 5,
        'max_limit' => 1000,
        'max_content_length' => 65536,
    ],

    'trusted_proxies' => ['127.0.0.1'],

    'log_level' => 'info',
];
