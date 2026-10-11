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

    // Programme relationships to export.
    'programme_relationships' => ['joined', 'pending', 'suspended', 'rejected'],

    // AWIN allows 20 calls/minute per user. Leave a margin.
    'max_calls_per_minute' => 18,
];
