<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operations Path
    |--------------------------------------------------------------------------
    |
    | This is the directory where your timestamped operation files are stored.
    | The generator creates files here, and the runner executes pending files
    | in filename order. Only PHP files directly in this directory are read.
    |
    */

    'path' => base_path('operations'),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | This connection stores the operations table and runs transactions for
    | operations implementing WithinTransaction. A null value uses your
    | application's default connection. Set this before migrating.
    |
    */

    'connection' => null,

];
