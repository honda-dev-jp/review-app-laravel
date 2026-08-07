<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);

        $statusElements = $this->createXPath($response->getContent())
            ->query('//*[@role="status"]');

        $this->assertNotFalse($statusElements);
        $this->assertCount(0, $statusElements);
    }

    public function test_email_verification_notification_can_be_resent_with_accessible_status(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/verify-email')
            ->post(route('verification.send'));

        $response->assertRedirect('/verify-email');

        $pageResponse = $this->actingAs($user)->get('/verify-email');
        $pageResponse->assertOk();

        $statusElements = $this->createXPath($pageResponse->getContent())
            ->query('//*[@role="status"]');

        $this->assertNotFalse($statusElements);
        $this->assertCount(1, $statusElements);

        $statusElement = $statusElements->item(0);
        $this->assertInstanceOf(DOMElement::class, $statusElement);
        $this->assertSame('status', $statusElement->getAttribute('role'));
        $this->assertSame(
            __('A new verification link has been sent to the email address you provided during registration.'),
            trim($statusElement->textContent)
        );
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(RouteServiceProvider::HOME.'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    private function createXPath(string $html): DOMXPath
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $this->assertTrue($document->loadHTML($html, LIBXML_NONET));

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }
}
