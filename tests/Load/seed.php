<?php

// Load-test seed: bulk INSERTs, deterministic (mt_srand(42)). Spreads 12 months of data.
require __DIR__.'/boot.php';
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

mt_srand(42);
$A = (int) (getenv('AFFILIATES') ?: 200);
$CLICKS = (int) (getenv('CLICKS') ?: 60000);
$ORDERS = (int) (getenv('ORDERS') ?: 6000);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$ts = fn (int $secsAgo) => $now->modify("-{$secsAgo} seconds")->format('Y-m-d H:i:s');
$ins = function (string $t, array $rows) {
    foreach (array_chunk($rows, 1000) as $c) {
        DB::table($t)->insert($c);
    }
};
$ulid = fn () => (string) Str::ulid();
DB::statement('SET FOREIGN_KEY_CHECKS=0');
(new RolesAndPermissionsSeeder)->run();
$pw = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
// staff owner
$adminId = DB::table('users')->insertGetId(['public_id' => $ulid(), 'name' => 'Owner', 'email' => 'owner@perf.test', 'password' => $pw, 'status' => 'active', 'email_verified_at' => $ts(0), 'created_at' => $ts(0), 'updated_at' => $ts(0)]);
User::find($adminId)->assignRole('super_admin');
// categories/products
$ins('product_categories', array_map(fn ($i) => ['name' => "Cat $i", 'slug' => "cat-$i", 'is_active' => 1], range(1, 10)));
$products = [];
foreach (range(1, 200) as $i) {
    $products[] = ['public_id' => $ulid(), 'category_id' => ($i % 10) + 1, 'sku' => "SKU$i", 'name' => "Product $i", 'slug' => "product-$i", 'price_minor' => mt_rand(500, 20000) * 100, 'currency' => 'NPR', 'is_active' => 1, 'created_at' => $ts(0), 'updated_at' => $ts(0)];
}
$ins('products', $products);
// affiliates + users
$users = [];
$profiles = [];
foreach (range(1, $A) as $i) {
    $users[] = ['public_id' => $ulid(), 'name' => "Aff $i", 'email' => "aff$i@perf.test", 'password' => $pw, 'status' => 'active', 'email_verified_at' => $ts(0), 'created_at' => $ts(0), 'updated_at' => $ts(0)];
}
$ins('users', $users);
$firstUser = $adminId + 1;
$statuses = ['approved', 'approved', 'approved', 'approved', 'approved', 'approved', 'pending', 'suspended', 'deactivated', 'rejected'];
foreach (range(1, $A) as $i) {
    $st = $statuses[$i % 10];
    $applied = mt_rand(30, 400) * 86400;
    $profiles[] = ['public_id' => $ulid(), 'user_id' => $firstUser + $i - 1, 'status' => $st, 'display_name' => "Affiliate $i", 'referral_code' => in_array($st, ['pending', 'rejected']) ? null : sprintf('AF%06d', $i), 'referral_slug' => in_array($st, ['pending', 'rejected']) ? null : "aff-$i", 'promotion_channels' => '["facebook"]', 'applied_at' => $ts($applied), 'approved_at' => in_array($st, ['pending', 'rejected']) ? null : $ts($applied - 86400), 'status_changed_at' => $ts($applied - 86400), 'created_at' => $ts($applied), 'updated_at' => $ts(0)];
}
$ins('affiliate_profiles', $profiles);
$earning = array_values(array_keys(array_filter($profiles, fn ($p) => $p['referral_code'] !== null)));
$earning = array_map(fn ($k) => $k + 1, $earning); // profile ids
$earnIdx = array_flip($earning);
$clicksByAff = [];
foreach ($earning as $pid) {
    DB::table('model_has_roles')->insertOrIgnore(['role_id' => DB::table('roles')->where('name', 'affiliate')->value('id'), 'model_type' => User::class, 'model_id' => $firstUser + $pid - 1]);
}
// links: 3 per earning affiliate
$links = [];
foreach ($earning as $pid) {
    foreach (range(1, 3) as $j) {
        $links[] = ['public_id' => $ulid(), 'affiliate_profile_id' => $pid, 'name' => "Link $j", 'slug' => "l$j", 'product_id' => mt_rand(1, 200), 'is_active' => 1, 'created_at' => $ts(0), 'updated_at' => $ts(0)];
    }
}
$ins('affiliate_links', $links);
// coupons: 1 per 4 earning affiliates
$coupons = [];
foreach ($earning as $k => $pid) {
    if ($k % 4 === 0) {
        $coupons[] = ['public_id' => $ulid(), 'code' => "C$pid", 'affiliate_profile_id' => $pid, 'discount_type' => 'percent', 'discount_value' => 10, 'is_active' => 1, 'earns_commission' => 1, 'created_at' => $ts(0), 'updated_at' => $ts(0)];
    }
}
$ins('coupons', $coupons);
$couponByAff = [];
foreach ($coupons as $k => $c) {
    $couponByAff[$c['affiliate_profile_id']] = $k + 1;
}
// clicks (skewed: some affiliates get far more)
$rows = [];
$clickIds = [];
for ($i = 1; $i <= $CLICKS; $i++) {
    $pid = $earning[(int) floor(count($earning) * (mt_rand(0, 1000) / 1000) ** 2) % count($earning)];
    $ago = mt_rand(0, 365 * 86400);
    $rows[] = ['public_id' => $ulid(), 'affiliate_profile_id' => $pid, 'affiliate_link_id' => mt_rand(0, 3) ? null : ($earnIdx[$pid] * 3 + mt_rand(1, 3)), 'landing_path' => '/', 'visitor_hash' => hash('sha256', (string) mt_rand(1, $CLICKS / 2)), 'ip_hash' => hash('sha256', (string) mt_rand()), 'is_unique' => mt_rand(0, 9) < 7 ? 1 : 0, 'set_attribution' => 1, 'created_at' => $ts($ago)];
    $clicksByAff[$pid][] = $i;
    if (count($rows) === 2000) {
        $ins('referral_clicks', $rows);
        $rows = [];
    }
}
$ins('referral_clicks', $rows);
// links ids fix: links table ids are sequential per affiliate order, approximate mapping is fine for perf.
// orders, items, attributions, payments, commissions, ledger
$o = [];
$it = [];
$att = [];
$pay = [];
$com = [];
$led = [];
$ref = [];
$itemId = 0;
$comId = 0;
$refId = 0;
$holding = 14 * 86400;
for ($i = 1; $i <= $ORDERS; $i++) {
    $ago = mt_rand(0, 365 * 86400);
    $placed = $ts($ago);
    $r = mt_rand(1, 100);
    $status = $r <= 8 ? 'cancelled' : ($r <= 18 ? 'pending_payment' : ($r <= 22 ? 'refunded' : ($r <= 25 ? 'partially_refunded' : 'paid')));
    $paid = in_array($status, ['paid', 'refunded', 'partially_refunded']) ? $ts(max(0, $ago - mt_rand(60, 86400))) : null;
    $paidAgo = $paid ? ($now->getTimestamp() - strtotime($paid.' UTC')) : null;
    $pid = mt_rand(1, 100) <= 80 ? $earning[mt_rand(0, count($earning) - 1)] : null;
    $coupon = ($pid && isset($couponByAff[$pid]) && mt_rand(0, 2) === 0) ? $couponByAff[$pid] : null;
    $n = mt_rand(1, 3);
    $sub = 0;
    $lines = [];
    for ($j = 0; $j < $n; $j++) {
        $prod = mt_rand(1, 200);
        $q = mt_rand(1, 2);
        $price = $products[$prod - 1]['price_minor'];
        $itemId++;
        $lines[] = [$itemId, $prod, $q, $price];
        $sub += $price * $q;
        $it[] = ['public_id' => $ulid(), 'order_id' => $i, 'product_id' => $prod, 'category_id' => ($prod % 10) + 1, 'sku' => "SKU$prod", 'name' => "Product $prod", 'unit_price_minor' => $price, 'quantity' => $q, 'line_subtotal_minor' => $price * $q, 'discount_minor' => 0, 'line_total_minor' => $price * $q, 'created_at' => $placed, 'updated_at' => $placed];
    }
    $o[] = ['public_id' => $ulid(), 'order_number' => sprintf('NFC-%07d', $i), 'customer_name' => 'Cust', 'customer_email' => "c$i@x.test", 'customer_phone' => '9800000000', 'shipping_city' => 'Kathmandu', 'shipping_address' => 'X', 'status' => $status, 'fulfilment_status' => 'unfulfilled', 'currency' => 'NPR', 'subtotal_minor' => $sub, 'discount_minor' => 0, 'shipping_minor' => 10000, 'total_minor' => $sub + 10000, 'refunded_minor' => 0, 'coupon_id' => $coupon, 'attribution_status' => $pid ? 'attributed' : (mt_rand(0, 20) ? 'none' : 'blocked'), 'placed_at' => $placed, 'paid_at' => $paid, 'cancelled_at' => $status === 'cancelled' ? $placed : null, 'created_at' => $placed, 'updated_at' => $placed];
    if ($paid) {
        $pay[] = ['public_id' => $ulid(), 'order_id' => $i, 'provider' => 'manual', 'method' => 'bank_transfer', 'reference' => "REF$i", 'amount_minor' => $sub + 10000, 'currency' => 'NPR', 'confirmed_at' => $paid, 'created_at' => $paid, 'updated_at' => $paid];
    }
    if (in_array($status, ['refunded', 'partially_refunded'])) {
        $refId++;
        $ref[] = ['public_id' => $ulid(), 'order_id' => $i, 'reference' => "RF$i", 'amount_minor' => $status === 'refunded' ? $sub : intdiv($sub, 2), 'shipping_minor' => 0, 'currency' => 'NPR', 'reason' => 'perf', 'completed_at' => $ts(max(0, $paidAgo - 86400 * 3)), 'created_at' => $paid, 'updated_at' => $paid];
    }
    if (! $pid) {
        continue;
    }
    $att[] = ['order_id' => $i, 'affiliate_profile_id' => $pid, 'source' => $coupon ? 'coupon' : 'click', 'referral_click_id' => ($coupon || empty($clicksByAff[$pid])) ? null : $clicksByAff[$pid][array_rand($clicksByAff[$pid])], 'coupon_id' => $coupon, 'policy' => 'last_click', 'window_days' => 30, 'clicked_at' => $placed, 'created_at' => $placed, 'updated_at' => $placed];
    if (! $paid) {
        continue;
    }
    foreach ($lines as [$liid, $prod, $q, $price]) {
        $comId++;
        $amt = intdiv($price * $q * 1000, 10000);
        $elig = $paidAgo - $holding;
        if ($status === 'refunded') {
            $cs = 'reversed';
        } elseif ($elig > 0) {
            $cs = $paidAgo > 60 * 86400 ? 'paid' : 'available';
        } else {
            $cs = 'pending';
        }
        if ($cs === 'available' && mt_rand(0, 4) === 0) {
            $cs = 'approved';
        }
        $com[] = ['public_id' => $ulid(), 'affiliate_profile_id' => $pid, 'order_id' => $i, 'order_item_id' => $liid, 'rule_version' => 1, 'rule_scope' => 'global', 'kind' => 'percent', 'base_minor' => $price * $q, 'rate_bp' => 1000, 'quantity' => $q, 'amount_minor' => $amt, 'reversed_minor' => $cs === 'reversed' ? $amt : 0, 'currency' => 'NPR', 'status' => $cs, 'eligible_at' => $ts($elig), 'held_at' => ($cs === 'pending' && mt_rand(0, 20) === 0) ? $paid : null, 'approved_at' => in_array($cs, ['approved', 'available', 'paid']) ? $ts(max(0, $elig)) : null, 'available_at' => in_array($cs, ['available', 'paid']) ? $ts(max(0, $elig)) : null, 'reversed_at' => $cs === 'reversed' ? $paid : null, 'snapshot' => json_encode(['order' => ['number' => sprintf('NFC-%07d', $i)], 'line' => ['name' => "Product $prod", 'sku' => "SKU$prod"], 'rule' => ['rule_id' => null, 'name' => 'Global']]), 'created_at' => $paid, 'updated_at' => $paid];
        $e = fn ($type, $from, $to, $when) => ['affiliate_profile_id' => $pid, 'commission_id' => $comId, 'refund_id' => $type === 'refund_reversal' ? $refId : null, 'type' => $type, 'from_bucket' => $from, 'to_bucket' => $to, 'amount_minor' => $amt, 'currency' => 'NPR', 'idempotency_key' => "$type:$comId", 'created_at' => $when];
        $led[] = $e('accrual', null, 'pending', $paid);
        if ($cs === 'reversed') {
            $led[] = $e('refund_reversal', 'pending', 'reversed', $paid);
        }
        if (in_array($cs, ['approved', 'available', 'paid'])) {
            $led[] = $e('approval', 'pending', 'approved', $ts(max(0, $elig)));
        }
        if (in_array($cs, ['available', 'paid'])) {
            $led[] = $e('release', 'approved', 'available', $ts(max(0, $elig)));
        }
    }
}
foreach (['orders' => $o, 'order_items' => $it, 'order_attributions' => $att, 'order_payments' => $pay, 'refunds' => $ref, 'commissions' => $com] as $t => $rows) {
    $ins($t, $rows);
}
// payouts: per affiliate, one paid payout per month-ish grouping of their 'paid' commissions; one open request for some
$paidByAff = [];
foreach ($com as $k => $c) {
    if ($c['status'] === 'paid') {
        $paidByAff[$c['affiliate_profile_id']][] = $k + 1;
    }
}
$payId = 0;
$po = [];
$pi = [];
foreach ($paidByAff as $pid => $ids) {
    foreach (array_chunk($ids, 8) as $chunk) {
        $payId++;
        $sum = 0;
        foreach ($chunk as $cid) {
            $sum += $com[$cid - 1]['amount_minor'];
            $pi[] = ['payout_id' => $payId, 'commission_id' => $cid, 'amount_minor' => $com[$cid - 1]['amount_minor']];
        }
        $when = $ts(mt_rand(1, 300) * 86400);
        $po[] = ['public_id' => $ulid(), 'affiliate_profile_id' => $pid, 'open_affiliate_profile_id' => null, 'amount_minor' => $sum, 'currency' => 'NPR', 'status' => 'paid', 'method_type' => 'esewa', 'account_name' => 'X', 'destination' => 'enc', 'destination_masked' => '98****00', 'requested_at' => $when, 'paid_at' => $when, 'payment_reference' => "PAY$payId", 'created_at' => $when, 'updated_at' => $when];
        $led[] = ['affiliate_profile_id' => $pid, 'payout_id' => $payId, 'type' => 'payout_reserve', 'from_bucket' => 'available', 'to_bucket' => 'reserved', 'amount_minor' => $sum, 'currency' => 'NPR', 'idempotency_key' => "pr:$payId", 'created_at' => $when];
        $led[] = ['affiliate_profile_id' => $pid, 'payout_id' => $payId, 'type' => 'payout_paid', 'from_bucket' => 'reserved', 'to_bucket' => 'paid', 'amount_minor' => $sum, 'currency' => 'NPR', 'idempotency_key' => "pp:$payId", 'created_at' => $when];
    }
}
$ins('payouts', $po);
$ins('payout_items', $pi);
$led = array_map(fn ($r) => $r + ['commission_id' => null, 'refund_id' => null, 'payout_id' => null], $led);
usort($led, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
$ins('commission_ledger_entries', $led);
// payment attempts: 400, 60 due for recheck
$pa = [];
for ($i = 1; $i <= 400; $i++) {
    $pa[] = ['public_id' => $ulid(), 'order_id' => mt_rand(1, $ORDERS), 'provider' => 'esewa', 'provider_reference' => "PR$i", 'amount_minor' => 1000, 'currency' => 'NPR', 'status' => $i <= 60 ? 'pending' : 'completed', 'next_check_at' => $i <= 60 ? $ts(60) : null, 'created_at' => $ts(3600), 'updated_at' => $ts(3600)];
}
$ins('payment_attempts', $pa);
DB::statement('SET FOREIGN_KEY_CHECKS=1');
foreach (['affiliate_profiles', 'referral_clicks', 'orders', 'order_items', 'order_attributions', 'commissions', 'commission_ledger_entries', 'payouts', 'payout_items', 'refunds', 'payment_attempts'] as $t) {
    echo str_pad($t, 28).DB::table($t)->count()."\n";
}
DB::statement('ANALYZE TABLE referral_clicks, orders, order_items, order_attributions, commissions, commission_ledger_entries, payouts, refunds');
