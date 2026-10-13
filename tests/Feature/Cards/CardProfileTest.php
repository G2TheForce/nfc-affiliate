<?php

namespace Tests\Feature\Cards;

use App\Enums\AffiliateStatus;
use App\Models\AffiliateProfile;
use App\Models\AuditLog;
use App\Models\CardProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CardProfileTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'handle' => 'sita-sharma',
            'display_name' => 'Sita Sharma',
            'headline' => 'Founder',
            'company' => 'Himal Studio',
            'bio' => 'Design for good.',
            'phone' => '+977 9800000000',
            'whatsapp' => '+9779800000000',
            'email' => 'sita@example.com',
            'website' => 'https://himal.example',
            'location' => 'Kathmandu',
            'socials' => ['instagram' => '@sita.designs', 'linkedin' => 'https://linkedin.com/in/sita'],
            'theme' => 'violet',
            'is_published' => true,
        ];
    }

    private function publish(?User $user = null, array $overrides = []): User
    {
        $user ??= User::factory()->create();
        $this->actingAs($user)->putJson('/api/v1/card-profile', $this->payload($overrides))->assertOk();
        auth()->forgetGuards();

        return $user;
    }

    public function test_owner_creates_and_updates_their_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/card-profile')->assertOk()->assertJsonPath('data', null);

        $this->actingAs($user)->putJson('/api/v1/card-profile', $this->payload(['handle' => '  Sita-Sharma ']))
            ->assertOk()
            ->assertJsonPath('data.handle', 'sita-sharma')
            ->assertJsonPath('data.socials.instagram', '@sita.designs')
            ->assertJsonPath('data.public_url', url('/p/sita-sharma'))
            ->assertJsonMissingPath('data.user_id');

        $this->actingAs($user)->putJson('/api/v1/card-profile', $this->payload(['headline' => 'Creative director', 'socials' => []]))
            ->assertOk()->assertJsonPath('data.headline', 'Creative director');

        $this->assertSame(1, CardProfile::query()->count());
        $this->assertSame([], CardProfile::query()->first()->socials);
        $this->assertTrue(AuditLog::query()->where('action', 'card_profile.created')->exists());
    }

    public function test_unverified_users_and_guests_cannot_have_a_profile(): void
    {
        $this->getJson('/api/v1/card-profile')->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->putJson('/api/v1/card-profile', $this->payload())->assertForbidden();
    }

    public function test_handles_must_be_well_formed_unique_and_not_reserved(): void
    {
        $this->publish();
        $other = User::factory()->create();

        foreach (['sita-sharma' => 'taken', 'admin' => 'reserved', 'ab' => null, '-bad' => null, 'a--b' => null, 'has space' => null] as $handle => $why) {
            $this->actingAs($other)->putJson('/api/v1/card-profile', $this->payload(['handle' => $handle]))
                ->assertUnprocessable()->assertJsonValidationErrors('handle');
        }
    }

    public function test_links_must_be_safe(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/card-profile', $this->payload(['website' => 'javascript:alert(1)']))
            ->assertUnprocessable()->assertJsonValidationErrors('website');
        $this->actingAs($user)->putJson('/api/v1/card-profile', $this->payload(['socials' => ['instagram' => 'javascript:alert(1)']]))
            ->assertUnprocessable()->assertJsonValidationErrors('socials.instagram');
        $this->actingAs($user)->putJson('/api/v1/card-profile', $this->payload(['socials' => ['myspace' => 'me']]))
            ->assertUnprocessable()->assertJsonValidationErrors('socials');
    }

    public function test_public_page_shows_a_published_profile_and_counts_one_tap_per_device(): void
    {
        $this->publish();

        $this->withHeader('User-Agent', self::BROWSER)->getJson('/api/v1/cards/sita-sharma')->assertOk()
            ->assertJsonPath('data.display_name', 'Sita Sharma')
            ->assertJsonPath('data.socials.0', ['network' => 'instagram', 'url' => 'https://instagram.com/sita.designs'])
            ->assertJsonPath('data.socials.1.url', 'https://linkedin.com/in/sita')
            ->assertJsonPath('data.get_a_card_url', '/shop')
            ->assertJsonMissingPath('data.taps_total')
            ->assertJsonMissingPath('data.is_published');
        $this->withHeader('User-Agent', self::BROWSER)->getJson('/api/v1/cards/sita-sharma')->assertOk();
        $this->withHeader('User-Agent', 'Googlebot/2.1')->getJson('/api/v1/cards/sita-sharma')->assertOk();

        $profile = CardProfile::query()->first();
        $this->assertSame(1, $profile->taps_total);
        $this->assertSame(1, (int) $profile->dailyStats()->sum('taps'));
    }

    public function test_unpublished_profiles_and_disabled_owners_are_not_found(): void
    {
        $this->publish(null, ['is_published' => false]);
        $this->getJson('/api/v1/cards/sita-sharma')->assertNotFound();
        $this->get('/p/sita-sharma/contact.vcf')->assertNotFound();

        $owner = $this->publish(User::factory()->create(), ['handle' => 'ram-k']);
        $owner->forceFill(['status' => 'disabled'])->save();
        $this->getJson('/api/v1/cards/ram-k')->assertNotFound();
    }

    public function test_an_affiliates_card_links_to_their_referral_code_only_while_approved(): void
    {
        $user = User::factory()->create();
        $affiliate = AffiliateProfile::factory()->forUser($user)->status(AffiliateStatus::Approved)->create();
        $this->publish($user);

        $this->getJson('/api/v1/cards/sita-sharma')->assertJsonPath('data.get_a_card_url', '/ref/'.$affiliate->referral_code);

        $affiliate->forceFill(['status' => AffiliateStatus::Suspended])->save();
        Cache::flush();
        $this->getJson('/api/v1/cards/sita-sharma')->assertJsonPath('data.get_a_card_url', '/shop');
    }

    public function test_save_contact_downloads_an_escaped_vcard_and_counts_a_save(): void
    {
        $this->publish(null, ['company' => "Himal; Studio,\nBEGIN:VCARD"]);

        $response = $this->withHeader('User-Agent', self::BROWSER)->get('/p/sita-sharma/contact.vcf')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/vcard; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="sita-sharma.vcf"');

        $vcf = $response->getContent();
        $this->assertStringStartsWith("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Sita Sharma\r\n", $vcf);
        $this->assertStringContainsString("ORG:Himal\; Studio\\,\\nBEGIN:VCARD\r\n", $vcf);
        // The injected text stays inside ORG; only one line starts a card.
        $this->assertSame(1, count(array_keys(explode("\r\n", $vcf), 'BEGIN:VCARD')));
        $this->assertStringContainsString("TEL;TYPE=CELL:+977 9800000000\r\n", $vcf);
        $this->assertStringContainsString('X-SOCIALPROFILE;TYPE=instagram:https://instagram.com/sita.designs', $vcf);
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame(1, CardProfile::query()->first()->saves_total);
    }

    public function test_page_shell_carries_the_persons_name_for_link_previews(): void
    {
        $this->withoutVite();
        $this->publish(null, ['display_name' => 'Sita <b>Sharma</b>']);

        $this->get('/p/sita-sharma')->assertOk()
            ->assertSee('<meta property="og:title" content="Sita &lt;b&gt;Sharma&lt;/b&gt;">', false)
            ->assertSee('Founder · Himal Studio');
        $this->get('/p/nobody-here')->assertOk()->assertSee('<div id="root"></div>', false);
    }

    public function test_owner_sees_thirty_days_of_stats(): void
    {
        $this->publish($user = User::factory()->create());
        $this->withHeader('User-Agent', self::BROWSER)->getJson('/api/v1/cards/sita-sharma');

        $daily = $this->actingAs($user->fresh())->getJson('/api/v1/card-profile/stats')->assertOk()
            ->assertJsonPath('data.taps_total', 1)
            ->json('data.daily');

        $this->assertCount(30, $daily);
        $this->assertSame(1, end($daily)['taps']);
        $this->assertSame(now('Asia/Kathmandu')->toDateString(), end($daily)['day']);
    }
}
