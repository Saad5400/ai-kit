<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    |
    | Each module registers its own service provider when enabled. Apps opt
    | out of what they don't use (uqucc never enables credits; config-only
    | catalogs skip the sync command, etc.).
    |
    */

    'modules' => [
        'gateway' => true,
        'agents' => true,
        'conversations' => true,
        'streaming' => true,
        'approvals' => true,
        'attachments' => true,
        'usage' => true,
        'catalog' => true,
        'safety' => true,
        'rag' => false,
        'credits' => false,
    ],

];
