<?php

require __DIR__.'/boot.php';
use App\Domain\Reporting\ReportRegistry;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

Model::preventLazyLoading(true); // surfaces N+1
$kernel = app(Kernel::class);
$owner = User::where('email', 'owner@perf.test')->first();
// busiest affiliate
$busy = DB::table('order_attributions')->selectRaw('affiliate_profile_id, count(*) n')->groupBy('affiliate_profile_id')->orderByDesc('n')->first();
$affUser = User::find(DB::table('affiliate_profiles')->where('id', $busy->affiliate_profile_id)->value('user_id'));
$y = now('Asia/Kathmandu')->subDays(364)->toDateString();
$eps = [
    [$owner, '/api/v1/admin/dashboard'], [$owner, '/api/v1/admin/dashboard?from='.$y],
    [$owner, '/api/v1/admin/finance/dashboard'], [$owner, '/api/v1/admin/finance/dashboard?from='.$y],
    [$owner, '/api/v1/admin/referrals/summary?from='.$y],
    [$owner, '/api/v1/admin/orders'], [$owner, '/api/v1/admin/orders?status=paid'], [$owner, '/api/v1/admin/orders?page=200'],
    [$owner, '/api/v1/admin/commissions'], [$owner, '/api/v1/admin/commissions?status=pending'], [$owner, '/api/v1/admin/commissions/summary'], [$owner, '/api/v1/admin/commissions?status=paid'], [$owner, '/api/v1/admin/commissions?status=paid&page=1000'],
    [$owner, '/api/v1/admin/payouts'], [$owner, '/api/v1/admin/payouts/summary'], [$owner, '/api/v1/admin/affiliates'],
    [$owner, '/api/v1/admin/payment-attempts'], [$owner, '/api/v1/admin/coupons'], [$owner, '/api/v1/admin/adjustments'], [$owner, '/api/v1/admin/audit-logs'],
    [$affUser, '/api/v1/affiliate/dashboard'], [$affUser, '/api/v1/affiliate/dashboard?from='.$y], [$affUser, '/api/v1/affiliate/earnings/summary'],
    [$affUser, '/api/v1/affiliate/earnings/commissions'], [$affUser, '/api/v1/affiliate/earnings/ledger'], [$affUser, '/api/v1/affiliate/referrals/summary?from='.$y],
    [$affUser, '/api/v1/affiliate/referrals/orders'], [$affUser, '/api/v1/affiliate/referral-links'], [$affUser, '/api/v1/affiliate/payouts'],
];
foreach (array_map(fn ($k) => [$owner, "/api/v1/admin/reports/$k?from=$y"], array_keys(app(ReportRegistry::class)->staff())) as $e) {
    $eps[] = $e;
}
$filter = getenv('ONLY');
foreach ($eps as [$user, $uri]) {
    if ($filter && ! str_contains($uri, $filter)) {
        continue;
    }
    $qs = [];
    DB::flushQueryLog();
    DB::enableQueryLog();
    app('auth')->forgetGuards();
    Sanctum::actingAs($user, ['*']);
    $req = Request::create($uri, 'GET');
    $req->headers->set('Accept', 'application/json');
    $t = microtime(true);
    $res = $kernel->handle($req);
    $ms = (microtime(true) - $t) * 1000;
    $log = DB::getQueryLog();
    DB::disableQueryLog();
    $dbms = array_sum(array_column($log, 'time'));
    $dups = array_count_values(array_map(fn ($q) => $q['query'], $log));
    arsort($dups);
    if (getenv('DUPS')) {
        foreach ($dups as $sql => $n) {
            if ($n > 1) {
                echo "   DUP x$n: ".substr($sql, 0, 200)."\n";
            }
        }
    }
    usort($log, fn ($a, $b) => $b['time'] <=> $a['time']);
    printf("%-60s %3d  q=%-3d  total=%7.1fms db=%7.1fms  slowest=%.1fms\n", $uri, $res->getStatusCode(), count($log), $ms, $dbms, $log[0]['time'] ?? 0);
    if ($res->getStatusCode() >= 400) {
        echo '   ', substr($res->getContent(), 0, 300), "\n";
    }
    if (getenv('SHOWQ')) {
        foreach (array_slice($log, 0, (int) getenv('SHOWQ')) as $q) {
            echo "   {$q['time']}ms ", substr(vsprintf(str_replace('?', "'%s'", str_replace('%', '%%', $q['query'])), array_map(fn ($b) => $b instanceof DateTimeInterface ? $b->format('Y-m-d H:i:s') : $b, $q['bindings'])), 0, 600), "\n";
        }
    }
    $kernel->terminate($req, $res);
}
