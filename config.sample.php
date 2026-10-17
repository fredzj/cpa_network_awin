<?php
/**
 * AWIN cron configuration.
 * Copy to config.php and fill in. Keep this file OUTSIDE public_html.
 */
return [
    // Personal API token from https://ui.awin.com/awin-api (tied to your user, covers all your publisher accounts)
    'api_token' => 'PASTE-YOUR-AWIN-API-TOKEN-HERE',

    // Publisher accounts ("sites") to process.
    //   []                         => auto-discover every publisher account your token can access
    //   [123456, 234567]           => only these IDs
    //   [123456 => 'my-site-nl']   => IDs with a friendly name (used in the log)
    'publishers' => [],

    // MariaDB database (DirectAdmin → MySQL Management). Tables are created automatically from schema.sql.
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'USER_awin',
        'user' => 'USER_awin',
        'pass' => 'PASSWORD',
    ],

    // Where log files are written (absolute path recommended).
    'log_dir' => __DIR__ . '/logs',

    // Timezone sent to AWIN (see https://help.awin.com/apidocs/timezones).
    // Europe/Berlin has the same offset as Amsterdam.
    'timezone' => 'Europe/Berlin',

    // Regions for the performance reports (AT AU BE BR BU CA CH DE DK ES FI FR GB IE IT NL NO PL SE US).
    // [] => derive from the primary regions of your joined programmes.
    'report_regions' => [],

    // How many days back each normal run re-fetches (catches status changes / late validations).
    'transaction_lookback_days' => 45,
    'report_lookback_days'      => 7,

    // Programmes, programme details, commission groups and offers change slowly:
    // they are refreshed only when older than this many hours.
    'static_refresh_hours' => 24,

    // Advertiser relationships to export. Programmes and product feeds are limited to advertisers
    // with one of these relationships ('joined' is always included).
    'programme_relationships' => ['joined', 'pending', 'rejected'],

    // AWIN allows 20 calls/minute per user. Leave a margin.
    'max_calls_per_minute' => 18,

    // Product feeds: feed list download URLs, one per publisher account
    // (AWIN → Toolbox → Create-a-Feed → "Download feed list"). The URL contains your datafeed key: keep it secret.
    // [] => no product import.
    'product_feed_lists' => [
        // 'https://ui.awin.com/productdata-darwin-download/publisher/123456/YOUR-DATAFEED-KEY/1/feedList',
    ],

    // Import products only from feeds with this membership status ('active' = you are joined to the programme).
    'product_feed_membership' => ['active'],

    // Feed IDs to skip, e.g. a second feed of the same advertiser with the same products.
    'product_feed_exclude' => [],

    // Feed IDs to import. [] => every feed (except product_feed_exclude).
    'product_feed_include' => [],

    // Only import products whose category contains one of these texts (case-insensitive).
    //   []                                       => all products
    //   ['Laptop', 'Smartphone']                 => for every feed
    //   [116948 => ['Laptop', 'Smartphone']]     => for this feed only; other feeds use the general texts, or import everything
    'product_categories' => [],
];
