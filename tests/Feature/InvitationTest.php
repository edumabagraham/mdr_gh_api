<?php

use App\Access\AccountStatus;
use App\Access\Role;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationIssued;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->withHeader('Origin', 'http://localhost:3000');
    $this->admin = User::factory()->admin()->create(['name' => 'Registry Administrator']);
});

/** Invite someone and return the token that was emailed to them. */
function inviteAndCaptureToken(string $email = 'a.owusu@mdr.kath.org', string $role = Role::CLINICIAN): string
{
    test()->actingAs(test()->admin)
        ->postJson('/api/admin/invitations', ['email' => $email, 'role' => $role])
        ->assertCreated();

    $token = null;

    Notification::assertSentOnDemand(
        InvitationIssued::class,
        function (InvitationIssued $notification) use (&$token) {
            $token = $notification->token;

            return true;
        },
    );

    return $token;
}

it('stores only a hash of the token it emails', function () {
    $token = inviteAndCaptureToken();

    $invitation = Invitation::sole();

    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and($invitation->getAttributes())->not->toHaveKey('token');
});

it('never returns the token from the endpoint that created it', function () {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/admin/invitations', ['email' => 'a.owusu@mdr.kath.org', 'role' => Role::CLINICIAN])
        ->assertCreated();

    expect(json_encode($response->json()))->not->toContain(Invitation::sole()->token_hash)
        ->and(array_keys($response->json()))->not->toContain('token');
});

it('carries an account from invitation through to a verified login', function () {
    $token = inviteAndCaptureToken('a.owusu@mdr.kath.org', Role::RESEARCH_ASSISTANT);

    $this->postJson('/api/invitations/accept', [
        'token' => $token,
        'name' => 'Dr A. Owusu',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'specialty' => 'Neurology',
        'mdc_number' => 'MDC/RN/12345',
    ])->assertCreated()->assertJsonPath('role', Role::RESEARCH_ASSISTANT);

    $user = User::where('email', 'a.owusu@mdr.kath.org')->sole();

    // Criterion 4: active, but no patient data until the code is entered.
    expect($user->status)->toBe(AccountStatus::ACTIVE)
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->specialty)->toBe('Neurology')
        ->and($user->invited_by)->toBe($this->admin->id)
        ->and(Invitation::sole()->accepted_at)->not->toBeNull();

    $this->actingAs($user)->getJson('/api/dashboard')->assertForbidden();
});

it('will not let the invited person choose their own role', function () {
    $token = inviteAndCaptureToken('a.owusu@mdr.kath.org', Role::RESEARCH_ASSISTANT);

    $this->postJson('/api/invitations/accept', [
        'token' => $token,
        'name' => 'Dr A. Owusu',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'role' => Role::ADMIN,
    ])->assertCreated();

    expect(User::where('email', 'a.owusu@mdr.kath.org')->sole()->role)->toBe(Role::RESEARCH_ASSISTANT);
});

it('refuses an expired invitation', function () {
    $token = inviteAndCaptureToken();

    Invitation::sole()->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/invitations/accept', acceptancePayload($token))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('token');

    expect(User::where('email', 'a.owusu@mdr.kath.org')->exists())->toBeFalse();
});

it('refuses a revoked invitation', function () {
    $token = inviteAndCaptureToken();

    $this->actingAs($this->admin)
        ->deleteJson('/api/admin/invitations/'.Invitation::sole()->id)
        ->assertOk()
        ->assertJsonPath('status', 'revoked');

    $this->postJson('/api/invitations/accept', acceptancePayload($token))->assertStatus(422);

    expect(AuditEntry::where('action', 'invitation.revoked')->count())->toBe(1);
});

it('refuses a token that has already been used', function () {
    $token = inviteAndCaptureToken();

    $this->postJson('/api/invitations/accept', acceptancePayload($token))->assertCreated();

    // A forwarded link arrives in a different browser, so the second attempt
    // carries none of the first one's session.
    asAStranger();

    $this->postJson('/api/invitations/accept', acceptancePayload($token))->assertStatus(422);

    expect(User::where('email', 'a.owusu@mdr.kath.org')->count())->toBe(1);
});

it('refuses a tampered token', function () {
    $token = inviteAndCaptureToken();

    $this->postJson('/api/invitations/accept', acceptancePayload($token.'x'))->assertStatus(422);
    $this->postJson('/api/invitations/accept', acceptancePayload(strrev($token)))->assertStatus(422);

    expect(User::count())->toBe(1); // the admin only
});

it('revokes the previous invitation when an address is re-invited', function () {
    $first = inviteAndCaptureToken();
    $second = inviteAndCaptureToken();

    expect(Invitation::count())->toBe(2)
        ->and(Invitation::pending()->count())->toBe(1);

    $this->postJson('/api/invitations/accept', acceptancePayload($first))->assertStatus(422);
    $this->postJson('/api/invitations/accept', acceptancePayload($second))->assertCreated();
});

it('refuses to invite an address that already has an active account', function () {
    User::factory()->create(['email' => 'taken@mdr.kath.org']);

    $this->actingAs($this->admin)
        ->postJson('/api/admin/invitations', ['email' => 'taken@mdr.kath.org', 'role' => Role::CLINICIAN])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('email');
});

it('keeps invitation administration to admins', function () {
    foreach ([Role::CLINICIAN, Role::RESEARCH_ASSISTANT, Role::DATA_MANAGER] as $role) {
        $this->actingAs(User::factory()->role($role)->create())
            ->postJson('/api/admin/invitations', ['email' => 'x@mdr.kath.org', 'role' => Role::CLINICIAN])
            ->assertForbidden();
    }

    asAStranger();

    $this->postJson('/api/admin/invitations', ['email' => 'x@mdr.kath.org', 'role' => Role::CLINICIAN])
        ->assertUnauthorized();
});

it('lists invitations with their status', function () {
    inviteAndCaptureToken('one@mdr.kath.org');
    inviteAndCaptureToken('two@mdr.kath.org');
    $this->actingAs($this->admin)->deleteJson('/api/admin/invitations/'.Invitation::latest('id')->first()->id);

    $this->actingAs($this->admin)->getJson('/api/admin/invitations?status=pending')
        ->assertOk()
        ->assertJsonCount(1, 'invitations')
        ->assertJsonPath('invitations.0.email', 'one@mdr.kath.org')
        ->assertJsonPath('invitations.0.invited_by', 'Registry Administrator');
});

/**
 * Drop every trace of who this test client was: a fresh browser, nobody signed
 * in, and the default guard back where a real request would find it.
 */
function asAStranger(): void
{
    test()->flushSession();
    app('auth')->forgetGuards();
    app('auth')->shouldUse(config('auth.defaults.guard'));
}

/**
 * @return array<string, string>
 */
function acceptancePayload(string $token): array
{
    return [
        'token' => $token,
        'name' => 'Dr A. Owusu',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ];
}

it('sends a reminder with a fresh token, retiring the old one', function () {
    $original = inviteAndCaptureToken();

    $this->actingAs($this->admin)
        ->postJson('/api/admin/invitations/'.Invitation::sole()->id.'/resend')
        ->assertOk()
        ->assertJsonPath('status', 'pending');

    $tokens = [];

    Notification::assertSentOnDemand(InvitationIssued::class, function (InvitationIssued $notification) use (&$tokens) {
        $tokens[] = $notification->token;

        return true;
    });

    $reminder = end($tokens);

    expect($reminder)->not->toBe($original)
        ->and(Invitation::count())->toBe(1);

    asAStranger();

    // The link that was sitting in the inbox no longer works; the reminder does.
    $this->postJson('/api/invitations/accept', acceptancePayload($original))->assertStatus(422);
    $this->postJson('/api/invitations/accept', acceptancePayload($reminder))->assertCreated();
});

it('restarts the clock on an expired invitation when reminding', function () {
    inviteAndCaptureToken();
    Invitation::sole()->update(['expires_at' => now()->subDay()]);

    $this->actingAs($this->admin)
        ->postJson('/api/admin/invitations/'.Invitation::sole()->id.'/resend')
        ->assertOk();

    expect(Invitation::sole()->expires_at->isFuture())->toBeTrue();
});

it('will not remind someone who has already accepted', function () {
    $token = inviteAndCaptureToken();
    $this->postJson('/api/invitations/accept', acceptancePayload($token))->assertCreated();

    // Acceptance signed the new account in; the admin comes back in their own
    // browser, not in the one that just accepted.
    asAStranger();

    $this->actingAs($this->admin)
        ->postJson('/api/admin/invitations/'.Invitation::sole()->id.'/resend')
        ->assertStatus(422);
});
