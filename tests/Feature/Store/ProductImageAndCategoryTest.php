<?php

namespace Tests\Feature\Store;

use App\Enums\Role;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Commissions\CommissionTestHelpers;
use Tests\TestCase;

class ProductImageAndCategoryTest extends TestCase
{
    use CommissionTestHelpers;
    use RefreshDatabase;

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('card.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    public function test_staff_add_a_photo_that_the_public_store_shows_and_replacing_it_removes_the_old_file(): void
    {
        Storage::fake('local');
        $admin = $this->userWithRole(Role::SuperAdmin);
        $product = $this->product(['slug' => 'metal-card']);

        $url = $this->actingAs($admin)->post("/api/v1/admin/products/{$product->public_id}/image", ['image' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk()->json('data.image_url');
        $first = $product->fresh()->image_path;
        $this->assertMatchesRegularExpression('#/media/products/[0-9A-Z]{26}\.png$#', $url);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.image_changed']);

        auth()->forgetGuards();
        $this->getJson('/api/v1/store/products/metal-card')->assertOk()->assertJsonPath('data.image_url', $url);
        $image = $this->get(parse_url($url, PHP_URL_PATH));
        $image->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('immutable', $image->headers->get('Cache-Control'));

        $this->actingAs($admin)->post("/api/v1/admin/products/{$product->public_id}/image", ['image' => $this->png()], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($first);
        auth()->forgetGuards();
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound();

        $this->actingAs($admin)->deleteJson("/api/v1/admin/products/{$product->public_id}/image")->assertOk()->assertJsonPath('data.image_url', null);
        $this->assertSame([], Storage::disk('local')->allFiles('products'));
    }

    public function test_non_images_are_refused_and_unknown_files_are_not_served(): void
    {
        Storage::fake('local');
        $admin = $this->userWithRole(Role::SuperAdmin);
        $product = $this->product();

        $fake = UploadedFile::fake()->createWithContent('photo.jpg', '<?php system($_GET["c"]);');
        $this->actingAs($admin)->post("/api/v1/admin/products/{$product->public_id}/image", ['image' => $fake], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertNull($product->fresh()->image_path);

        Storage::disk('local')->put('products/01J0000000000000000000000A.png', 'x');
        auth()->forgetGuards();
        $this->get('/media/products/01J0000000000000000000000A.png')->assertNotFound();
        // Anything else under /media is just the web app, never a stored file.
        $this->get('/media/products/..%2F.env')->assertDontSee('APP_KEY');

        $this->actingAs($this->userWithRole(Role::Support))
            ->post("/api/v1/admin/products/{$product->public_id}/image", ['image' => $this->png()], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_categories_can_be_renamed_hidden_and_deleted_only_when_unused(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $used = ProductCategory::create(['name' => 'Metal', 'slug' => 'metal']);
        $empty = ProductCategory::create(['name' => 'Old stock', 'slug' => 'old-stock']);
        $ruled = ProductCategory::create(['name' => 'Wood', 'slug' => 'wood']);
        $this->product(['category_id' => $used->id]);
        $this->rule(['scope' => 'category', 'product_category_id' => $ruled->id]);

        $this->actingAs($admin)->patchJson("/api/v1/admin/categories/{$used->id}", ['name' => 'Metal cards', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Metal cards')->assertJsonPath('data.slug', 'metal')->assertJsonPath('data.products_count', 1);
        $this->actingAs($admin)->patchJson("/api/v1/admin/categories/{$used->id}", ['slug' => 'other'])->assertUnprocessable();
        auth()->forgetGuards();
        $this->assertNotContains('metal', array_column($this->getJson('/api/v1/store/categories')->json('data'), 'slug'));

        $this->actingAs($admin)->deleteJson("/api/v1/admin/categories/{$used->id}")->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->actingAs($admin)->deleteJson("/api/v1/admin/categories/{$ruled->id}")->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->actingAs($admin)->deleteJson("/api/v1/admin/categories/{$empty->id}")->assertNoContent();
        $this->assertDatabaseMissing('product_categories', ['id' => $empty->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_category.deleted']);
    }
}
