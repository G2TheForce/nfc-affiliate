<?php

namespace Database\Seeders;

use App\Domain\Commissions\CommissionRuleService;
use App\Enums\AffiliateStatus;
use App\Enums\Role;
use App\Models\AffiliateProfile;
use App\Models\AffiliateTermsVersion;
use App\Models\CommissionRule;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * DEVELOPMENT ONLY. Creates one verified user per role with emails ending in
 *
 * @dev.invalid so they can never be confused with real accounts.
 *
 *   php artisan db:seed --class=DevelopmentSeeder
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DevelopmentSeeder must never run in production.');
        }

        $this->call(RolesAndPermissionsSeeder::class);

        foreach (Role::cases() as $role) {
            $user = User::firstOrCreate(
                ['email' => str_replace('_', '-', $role->value).'@dev.invalid'],
                ['name' => '[DEV] '.$role->label(), 'password' => 'DevPassword123'],
            );
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$role->value]);
        }

        $terms = AffiliateTermsVersion::firstOrCreate(['version' => 'dev-1'], [
            'title' => '[DEV] Affiliate programme terms',
            'body' => "[DEVELOPMENT PLACEHOLDER - NOT LEGAL TEXT]\n\nReplace these with terms reviewed by a qualified Nepal lawyer before launch.",
            'published_at' => now(),
        ]);

        // Sample applicants in different states, all @dev.invalid.
        foreach ([
            ['pending', AffiliateStatus::Pending, 'Pending Blogger'],
            ['approved', AffiliateStatus::Approved, 'Approved Creator'],
            ['rejected', AffiliateStatus::Rejected, 'Rejected Applicant'],
        ] as [$key, $status, $name]) {
            $user = User::firstOrCreate(
                ['email' => "applicant-{$key}@dev.invalid"],
                ['name' => "[DEV] {$name}", 'password' => 'DevPassword123', 'phone' => '+9779800000000'],
            );
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles($status === AffiliateStatus::Approved ? [Role::Customer->value, Role::Affiliate->value] : [Role::Customer->value]);

            if (! $user->affiliateProfile) {
                $profile = AffiliateProfile::factory()->forUser($user)->status($status)->create(['display_name' => "[DEV] {$name}"]);
                $profile->statusHistory()->create(['to_status' => AffiliateStatus::Pending, 'actor_id' => $user->id]);
                if ($status !== AffiliateStatus::Pending) {
                    $profile->statusHistory()->create(['from_status' => AffiliateStatus::Pending, 'to_status' => $status, 'public_reason' => $status === AffiliateStatus::Rejected ? '[DEV] Sample rejection reason.' : null]);
                }
                $profile->termsAcceptances()->create(['terms_version_id' => $terms->id, 'accepted_at' => now()]);
            }
        }

        // Sample catalog so the store journey can be tried locally.
        $cards = ProductCategory::firstOrCreate(['slug' => 'nfc-cards'], ['name' => 'NFC business cards']);
        $stands = ProductCategory::firstOrCreate(['slug' => 'review-stands'], ['name' => 'Review stands']);
        foreach ([
            ['DEV-PVC', '[DEV] PVC smart visiting card', 'pvc-smart-card', 120000, $cards],
            ['DEV-METAL', '[DEV] Metal NFC business card', 'metal-nfc-card', 350000, $cards],
            ['DEV-STAND', '[DEV] Google review NFC stand', 'review-stand', 180000, $stands],
        ] as [$sku, $name, $slug, $price, $category]) {
            Product::firstOrCreate(['sku' => $sku], [
                'name' => $name, 'slug' => $slug, 'price_minor' => $price, 'currency' => 'NPR',
                'category_id' => $category->id, 'description' => 'Development sample product.',
            ]);
        }

        $approved = AffiliateProfile::whereHas('user', fn ($q) => $q->where('email', 'applicant-approved@dev.invalid'))->first();
        if ($approved) {
            Coupon::firstOrCreate(['code' => 'DEVCREATOR10'], [
                'affiliate_profile_id' => $approved->id, 'discount_type' => 'percent', 'discount_value' => 1000,
            ]);
        }

        // Sample commission rules: 10% for everyone, a fixed amount on review stands.
        if (! CommissionRule::query()->exists()) {
            $admin = User::where('email', 'super-admin@dev.invalid')->first() ?? User::query()->orderBy('id')->firstOrFail();
            $rules = app(CommissionRuleService::class);
            $rules->create(['name' => '[DEV] Global default 10%', 'scope' => 'global', 'kind' => 'percent', 'rate_bp' => 1000, 'starts_at' => now()->subYear()], $admin);
            $rules->create(['name' => '[DEV] Review stands NPR 150 each', 'scope' => 'category', 'product_category_id' => $stands->id, 'kind' => 'fixed', 'fixed_minor' => 15000, 'starts_at' => now()->subYear()], $admin);
        }

        $this->command?->warn('Development users created with password DevPassword123 (local use only).');
    }
}
