<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Steam catalog crawl. Production needs one cron entry: `* * * * * php artisan schedule:run`.
// Batch sizes in config/catalog.php keep each run within Valve's and SteamSpy's rate limits.
Schedule::command('catalog:discover')->daily()->withoutOverlapping();
Schedule::command('catalog:fetch-details')->everyMinute()->withoutOverlapping();
Schedule::command('catalog:fetch-stats')->everyMinute()->withoutOverlapping();
