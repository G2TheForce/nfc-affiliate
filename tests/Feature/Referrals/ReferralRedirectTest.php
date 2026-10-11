<?php

namespace Tests\Feature\Referrals;

use App\Enums\AffiliateStatus;
use App\Models\AffiliateLink;
use App\Models\ReferralClick;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReferralRedirectTest extends TestCase
{
    use ReferralTestHelpers;
    use RefreshDatabase;

    public function test_valid_referral_link_records_a_click_sets_the_cookie_and_redirects_home(): void
    {
        $affiliate = $this->affiliate();

        $response = $this->visit('/ref/'.$affiliate->referral_code.'?utm_source=facebook&utm_campaign=dashain&sub=post-12', ['Referer' => 'https://m.facebook.com/story']);

        $response->assertRedirect('/');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $click = ReferralClick::sole();
        $this->assertSame($affiliate->id, $click->affiliate_profile_id);
        $this->assertSame('facebook', $click->utm_source);
        $this->assertSame('dashain', $click->utm_campaign);
        $this->assertSame('post-12', $click->sub_id);
        $this->assertSame('m.facebook.com', $click->referrer_host);
        $this->assertTrue($click->is_unique);
        $this->assertSame($click->public_id, $this->clickCookie($response));

        // Minimised metadata: hashes only, never the raw address.
        $this->assertSame(64, strlen($click->ip_hash));
        $this->assertNotSame('127.0.0.1', $click->ip_hash);
        $cookie = $response->getCookie(config('affiliate.attribution.cookie'), false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_slug_and_lower_case_code_also_work(): void
    {
        $affiliate = $this->affiliate();

        $this->visit('/ref/'.strtolower($affiliate->referral_code))->assertRedirect('/');
        $this->visit('/ref/'.$affiliate->referral_slug)->assertRedirect('/');

        $this->assertSame(2, ReferralClick::where('affiliate_profile_id', $affiliate->id)->count());
    }

    public function test_tracking_link_for_a_product_redirects_to_that_product(): void
    {
        $affiliate = $this->affiliate();
        $product = $this->product(['slug' => 'metal-nfc-card']);
        $link = new AffiliateLink(['name' => 'Insta bio', 'slug' => 'insta', 'product_id' => $product->id, 'utm_source' => 'instagram']);
        $link->affiliate_profile_id = $affiliate->id;
        $link->save();

        $this->visit("/ref/{$affiliate->referral_code}/insta")->assertRedirect('/shop/metal-nfc-card');

        $click = ReferralClick::sole();
        $this->assertSame($link->id, $click->affiliate_link_id);
        $this->assertSame($product->id, $click->product_id);
        $this->assertSame('instagram', $click->utm_source);
    }

    public function test_allowed_destination_query_is_followed(): void
    {
        $affiliate = $this->affiliate();
        $this->product(['slug' => 'pvc-card']);

        $this->visit("/ref/{$affiliate->referral_code}?to=/shop/pvc-card")->assertRedirect('/shop/pvc-card');
        $this->visit("/ref/{$affiliate->referral_code}?to=/shop")->assertRedirect('/shop');
        $this->visit("/ref/{$affiliate->referral_code}?to=/shop/not-a-product")->assertRedirect('/shop');
    }

    public static function hostileDestinations(): array
    {
        return [
            'absolute url' => ['https://evil.example/phish'],
            'protocol relative' => ['//evil.example'],
            'backslash trick' => ['/\\evil.example'],
            'encoded slashes' => ['/%2F%2Fevil.example'],
            'javascript' => ['javascript:alert(1)'],
            'staff area' => ['/admin'],
            'path traversal' => ['/shop/../admin'],
            'header injection' => ["/shop\r\nLocation: https://evil.example"],
        ];
    }

    #[DataProvider('hostileDestinations')]
    public function test_destinations_outside_the_allow_list_go_home(string $to): void
    {
        $affiliate = $this->affiliate();

        $response = $this->visit('/ref/'.$affiliate->referral_code.'?to='.urlencode($to));

        $response->assertRedirect('/');
        $this->assertStringStartsWith(url('/'), $response->headers->get('Location'));
    }

    public function test_unknown_or_ineligible_affiliates_leave_no_click_and_no_cookie(): void
    {
        $suspended = $this->affiliate(AffiliateStatus::Suspended);
        $deactivated = $this->affiliate(AffiliateStatus::Deactivated);

        foreach (['/ref/NOSUCHCODE', '/ref/'.$suspended->referral_code, '/ref/'.$deactivated->referral_slug] as $path) {
            $response = $this->visit($path);
            $response->assertRedirect('/');
            $this->assertNull($this->clickCookie($response));
        }

        $this->assertSame(0, ReferralClick::count());
    }

    public function test_bots_link_previews_and_prefetches_are_not_recorded(): void
    {
        $affiliate = $this->affiliate();

        $this->withHeaders(['User-Agent' => 'WhatsApp/2.23.20.0'])->get('/ref/'.$affiliate->referral_code)->assertRedirect('/');
        $this->withHeaders(['User-Agent' => 'facebookexternalhit/1.1'])->get('/ref/'.$affiliate->referral_code)->assertRedirect('/');
        $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])->get('/ref/'.$affiliate->referral_code)->assertRedirect('/');
        $this->withHeaders(['User-Agent' => ''])->get('/ref/'.$affiliate->referral_code)->assertRedirect('/');
        $this->visit('/ref/'.$affiliate->referral_code, ['Sec-Purpose' => 'prefetch'])->assertRedirect('/');

        $this->assertSame(0, ReferralClick::count());
    }

    public function test_repeat_clicks_from_the_same_visitor_are_not_unique(): void
    {
        $affiliate = $this->affiliate();

        $first = $this->visit('/ref/'.$affiliate->referral_code);
        $visitor = $first->getCookie(config('affiliate.attribution.visitor_cookie'))->getValue();

        $this->withCookie(config('affiliate.attribution.visitor_cookie'), $visitor);
        $this->visit('/ref/'.$affiliate->referral_code);

        $this->assertSame([true, false], ReferralClick::orderBy('id')->pluck('is_unique')->all());
    }

    public function test_click_floods_from_one_ip_still_redirect_but_are_not_recorded(): void
    {
        config(['affiliate.attribution.clicks_per_minute_per_ip' => 3]);
        $affiliate = $this->affiliate();

        for ($i = 0; $i < 5; $i++) {
            $this->visit('/ref/'.$affiliate->referral_code)->assertRedirect('/');
        }

        $this->assertSame(3, ReferralClick::count());
    }

    public function test_last_click_policy_replaces_an_earlier_affiliates_cookie(): void
    {
        $first = $this->affiliate();
        $second = $this->affiliate();

        $firstClick = $this->clickCookie($this->visit('/ref/'.$first->referral_code));
        $this->withCookie(config('affiliate.attribution.cookie'), $firstClick);
        $response = $this->visit('/ref/'.$second->referral_code);

        $secondClick = ReferralClick::where('affiliate_profile_id', $second->id)->sole();
        $this->assertSame($secondClick->public_id, $this->clickCookie($response));
        $this->assertTrue($secondClick->set_attribution);
    }

    public function test_first_click_policy_keeps_the_earlier_affiliates_cookie(): void
    {
        config(['affiliate.attribution.policy' => 'first_click']);
        $first = $this->affiliate();
        $second = $this->affiliate();

        $firstClick = $this->clickCookie($this->visit('/ref/'.$first->referral_code));
        $this->withCookie(config('affiliate.attribution.cookie'), $firstClick);
        $response = $this->visit('/ref/'.$second->referral_code);

        $this->assertNull($this->clickCookie($response));
        $this->assertFalse(ReferralClick::where('affiliate_profile_id', $second->id)->sole()->set_attribution);
    }

    public function test_tracking_tags_are_sanitised_and_truncated(): void
    {
        $affiliate = $this->affiliate();

        $this->visit('/ref/'.$affiliate->referral_code.'?utm_source='.urlencode('<script>alert(1)</script>').'&utm_medium='.str_repeat('a', 200));

        $click = ReferralClick::sole();
        $this->assertSame('scriptalert1script', $click->utm_source);
        $this->assertSame(64, strlen($click->utm_medium));
    }

    public function test_inactive_tracking_link_falls_back_to_the_main_link(): void
    {
        $affiliate = $this->affiliate();
        $link = new AffiliateLink(['name' => 'Old', 'slug' => 'old', 'is_active' => false]);
        $link->affiliate_profile_id = $affiliate->id;
        $link->save();

        $this->visit("/ref/{$affiliate->referral_code}/old")->assertRedirect('/');

        $this->assertNull(ReferralClick::sole()->affiliate_link_id);
    }
}
