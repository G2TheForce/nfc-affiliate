<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function verificationUrl(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('api.v1.auth.verify-email', now()->addHour(), [
            'user' => $user->public_id,
            'hash' => $hash ?? sha1($user->email),
        ]);
    }

    public function test_signed_link_verifies_email_and_redirects_to_spa(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user))
            ->assertRedirect(config('affiliate.frontend_url').'/email-verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.email_verified', 'subject_id' => $user->public_id]);
    }

    public function test_emailed_link_uses_public_id_not_database_id(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, $user->public_id) && str_contains($url, 'signature=');
        });
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson($this->verificationUrl($user).'tampered')->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_link_for_another_email_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson($this->verificationUrl($user, sha1('other@example.com')))->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('api.v1.auth.verify-email', now()->subMinute(), [
            'user' => $user->public_id, 'hash' => sha1($user->email),
        ]);

        $this->getJson($url)->assertForbidden();
    }

    public function test_user_can_request_a_new_link(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')->assertAccepted();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_verification_email_is_queued(): void
    {
        $this->assertContains(
            ShouldQueue::class,
            class_implements(VerifyEmailNotification::class),
        );
    }
}
