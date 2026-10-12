<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Boots the app for the load-test scripts, refusing any database whose name
// does not end in _test or _load (the seed wipes and fills it).
// See docs/load-and-concurrency.md.
$db = (string) getenv('DB_DATABASE');
if (! preg_match('/_(test|load)$/', $db)) {
    fwrite(STDERR, "Refusing: set DB_DATABASE to a scratch database ending in _test or _load\n");
    exit(1);
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== $db) {
    fwrite(STDERR, "Wrong database\n");
    exit(1);
}
