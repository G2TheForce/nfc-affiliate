<?php

namespace Tests\Feature\Marketing;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\MarketingAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Commissions\CommissionTestHelpers;
use Tests\TestCase;

class MarketingAssetTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** A real 1×1 PNG, so the type is detected from the content. */
    private function png(string $name = 'banner.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    public function test_staff_upload_an_image_and_affiliates_download_it_under_a_safe_name(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);
        $product = $this->product();

        $id = $this->actingAs($manager)->post('/api/v1/admin/marketing-assets', [
            'title' => 'Dashain banner 1080',
            'caption' => 'Tap to get your NFC card before Dashain!',
            'product_id' => $product->public_id,
            'file' => $this->png('../../evil name.png'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.mime', 'image/png')
            ->assertJsonPath('data.product.id', $product->public_id)
            ->json('data.id');

        $asset = MarketingAsset::where('public_id', $id)->sole();
        $this->assertMatchesRegularExpression('#^marketing/[0-9A-Z]{26}\.png$#', $asset->path);
        Storage::disk('local')->assertExists($asset->path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing_asset.created']);

        $affiliate = $this->approvedAffiliate();
        $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/marketing-assets')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.caption', 'Tap to get your NFC card before Dashain!')
            ->assertJsonMissingPath('data.0.download_count')
            ->assertJsonMissingPath('data.0.path');

        $response = $this->actingAs($affiliate->user)->get("/api/v1/affiliate/marketing-assets/{$id}/file");
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment; filename=dashain-banner-1080.png', $response->headers->get('Content-Disposition'));
        $this->assertSame(1, $asset->fresh()->download_count);

        // In production the site-wide CSP must not replace the file's sandbox policy.
        $this->app['env'] = 'production';
        $inline = $this->actingAs($affiliate->user)->get("/api/v1/affiliate/marketing-assets/{$id}/file?inline=1");
        $this->assertStringStartsWith('inline', $inline->headers->get('Content-Disposition'));
        $this->assertSame("default-src 'none'; sandbox", $inline->headers->get('Content-Security-Policy'));
        $this->assertSame(1, $asset->fresh()->download_count);
    }

    public function test_dangerous_or_unknown_files_are_refused_whatever_their_name(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $php = UploadedFile::fake()->createWithContent('shell.png', '<?php echo "x";');

        foreach ([$svg, $php] as $file) {
            $this->actingAs($manager)->post('/api/v1/admin/marketing-assets', ['title' => 'Bad', 'file' => $file], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertSame(0, MarketingAsset::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_text_assets_need_text_and_files_cannot_be_swapped(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);

        $this->actingAs($manager)->postJson('/api/v1/admin/marketing-assets', ['title' => 'Empty'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $id = $this->actingAs($manager)->postJson('/api/v1/admin/marketing-assets', ['title' => 'Post idea', 'caption' => 'Share your contact with one tap.'])
            ->assertCreated()->assertJsonPath('data.kind', 'text')->assertJsonPath('data.has_file', false)->json('data.id');

        $this->actingAs($manager)->patchJson("/api/v1/admin/marketing-assets/{$id}", ['caption' => ''])
            ->assertUnprocessable();
        $this->actingAs($manager)->patch("/api/v1/admin/marketing-assets/{$id}", ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($this->approvedAffiliate()->user)->get("/api/v1/affiliate/marketing-assets/{$id}/file")->assertNotFound();
    }

    public function test_hidden_campaign_and_inactive_materials_are_not_shown_to_affiliates(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);
        $affiliate = $this->approvedAffiliate();
        $private = Campaign::create(['name' => 'VIP', 'starts_at' => now()->subDay(), 'all_affiliates' => false]);
        $private->affiliates()->sync([$this->approvedAffiliate()->id]);
        $ended = Campaign::create(['name' => 'Old', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);
        $open = Campaign::create(['name' => 'Open', 'starts_at' => now()->subDay()]);

        $make = fn (array $extra) => $this->actingAs($manager)->postJson('/api/v1/admin/marketing-assets', ['title' => 'Asset', 'caption' => 'Copy text'] + $extra)->json('data.id');
        $visible = [$make([]), $make(['campaign_id' => $open->public_id])];
        $hidden = [$make(['campaign_id' => $private->public_id]), $make(['campaign_id' => $ended->public_id]), $make(['is_active' => false])];

        $ids = $this->actingAs($affiliate->user)->getJson('/api/v1/affiliate/marketing-assets')->assertOk()->json('data.*.id');
        $this->assertEqualsCanonicalizing($visible, $ids);
        $this->assertSame([], array_intersect($hidden, $ids));
    }

    public function test_only_staff_with_the_permission_manage_materials_and_deleting_removes_the_file(): void
    {
        $manager = $this->userWithRole(Role::AffiliateManager);
        $id = $this->actingAs($manager)->post('/api/v1/admin/marketing-assets', ['title' => 'Banner', 'file' => $this->png()], ['Accept' => 'application/json'])->json('data.id');
        $path = MarketingAsset::where('public_id', $id)->value('path');

        $this->actingAs($this->userWithRole(Role::FinanceManager))->getJson('/api/v1/admin/marketing-assets')->assertForbidden();
        $this->actingAs($this->approvedAffiliate()->user)->postJson('/api/v1/admin/marketing-assets', ['title' => 'x', 'caption' => 'y'])->assertForbidden();
        $this->actingAs($this->userWithRole(Role::Customer))->getJson('/api/v1/affiliate/marketing-assets')->assertForbidden();

        $this->actingAs($manager)->deleteJson("/api/v1/admin/marketing-assets/{$id}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing_asset.deleted']);
    }
}
