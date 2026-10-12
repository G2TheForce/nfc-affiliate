<?php

require __DIR__.'/boot.php';
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

Http::fake(['*' => Http::response(['status' => 'PENDING'], 200)]);
Notification::fake();
foreach (explode(',', getenv('CMDS') ?: 'affiliate:reconcile-commissions,affiliate:mature-commissions,payments:recheck,affiliate:prune-referral-data') as $cmd) {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $m0 = memory_get_usage(true);
    $t = microtime(true);
    $code = Artisan::call($cmd);
    $ms = (microtime(true) - $t) * 1000;
    $log = DB::getQueryLog();
    DB::disableQueryLog();
    $shapes = array_count_values(array_map(fn ($q) => preg_replace('/\d+/', 'N', substr($q['query'], 0, 90)), $log));
    arsort($shapes);
    printf('%-34s exit=%d q=%-5d total=%7.1fms db=%7.1fms peakmem=%dMB | %s', $cmd, $code, count($log), $ms, array_sum(array_column($log, 'time')), memory_get_peak_usage(true) / 1048576, Artisan::output());
    foreach (array_slice($shapes, 0, (int) (getenv('TOP') ?: 6), true) as $s => $n) {
        echo "     x$n  $s\n";
    }
}
