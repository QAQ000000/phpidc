<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\AuthenticateCustomerAction;
use App\Actions\ConfigureIdentityAction;
use App\Actions\CustomerIdentityAction;
use App\Actions\ManageTicketAction;
use App\Actions\RegisterCustomerAction;
use App\Contracts\MailDriverInterface;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\DriverManager;
use App\Support\CustomerEmailLookup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

it('preserves mixed case identity lookup and reserves deleted customer mailboxes', function (): void {
    $user = User::factory()->create(['email' => 'Legacy@Example.Test', 'password' => 'Original-Password-123']);
    app(AuthenticateCustomerAction::class)->execute(' LEGACY@example.test ', 'Original-Password-123');
    expect(Auth::id())->toBe($user->id);
    Auth::logout();
    $user->delete();

    expect(fn () => app(RegisterCustomerAction::class)->execute('Duplicate', 'legacy@example.test', 'Customer-Password-123'))
        ->toThrow(ValidationException::class);
    expect(User::withTrashed()->count())->toBe(1)
        ->and(app(CustomerEmailLookup::class)->matching('legacy@example.test')->exists())->toBeFalse();
});

it('locks a matched recovery identity by its primary key and keeps password reset effects scoped', function (): void {
    $user = User::factory()->create(['email' => 'Recovery@Example.Test', 'password' => 'Original-Password-123', 'remember_token' => 'old-remember']);
    $other = User::factory()->create(['password' => 'Other-Password-123']);
    $token = Password::broker()->createToken($user);
    DB::table('sessions')->insert(['id' => 'review-old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    DB::enableQueryLog();
    app(CustomerIdentityAction::class)->resetPassword('recovery@example.test', $token, 'Updated-Password-123');
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'select')
        && preg_match('/from ["`]users["`]/', $query['query']) === 1);
    DB::disableQueryLog();

    // The current identity is read again by ID before the password/token mutation.
    expect($queries->contains(fn (array $query): bool => preg_match('/["`]id["`] = \?/', $query['query']) === 1))->toBeTrue();
    expect(Hash::check('Updated-Password-123', $user->fresh()->password))->toBeTrue()
        ->and(Hash::check('Other-Password-123', $other->fresh()->password))->toBeTrue()
        ->and($user->fresh()->remember_token)->not->toBe('old-remember')
        ->and(DB::table('sessions')->where('id', 'review-old-session')->exists())->toBeFalse()
        ->and(Password::broker()->tokenExists($user, $token))->toBeFalse();
});

it('rechecks a candidate email before a recovery row lock can authorize a different mailbox', function (): void {
    $user = User::factory()->create(['email' => 'before@example.test']);
    $changed = false;
    DB::listen(function ($query) use ($user, &$changed): void {
        if (! $changed && str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'users') && str_contains($query->sql, 'limit 2')) {
            $changed = true;
            DB::table('users')->where('id', $user->id)->update(['email' => 'after@example.test']);
        }
    });

    expect(DB::transaction(fn () => app(CustomerEmailLookup::class)->lockUnique('before@example.test')))->toBeNull()
        ->and($changed)->toBeTrue()->and($user->fresh()->email)->toBe('after@example.test');
});

it('creates one broker token for mixed case recovery requests and never sends to a missing identity', function (): void {
    app(ConfigureIdentityAction::class)->execute(['mail.transport' => 'smtp', 'mail.host' => 'smtp.example.test', 'mail.from_address' => 'accounts@example.test'], 'Isolated review setup');
    $user = User::factory()->create(['email' => 'Recovery@Example.Test']);
    $mail = Mockery::mock(MailDriverInterface::class);
    $mail->shouldReceive('send')->once()->with($user->email, '重置密码', Mockery::type('string'))->andReturn(true);
    $manager = Mockery::mock(DriverManager::class)->makePartial();
    $manager->shouldReceive('mail')->andReturn($mail);
    app()->instance(DriverManager::class, $manager);

    app(CustomerIdentityAction::class)->requestPasswordReset('recovery@example.test');
    app(CustomerIdentityAction::class)->requestPasswordReset('RECOVERY@example.test');
    app(CustomerIdentityAction::class)->requestPasswordReset('missing@example.test');

    expect(DB::table('password_reset_tokens')->count())->toBe(1);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'identity.password_reset_sent', 'subject_id' => $user->id]);
});

it('returns a body field error before oversized four byte ticket text can reach MySQL', function (): void {
    $user = User::factory()->create();
    $body = str_repeat('😀', 16384);
    expect(mb_strlen($body))->toBeLessThan(20000)->and(strlen($body))->toBe(65536);

    $this->actingAs($user)->from('/console/tickets')->post('/console/tickets', ['subject' => 'Oversized', 'body' => $body])
        ->assertRedirect('/console/tickets')->assertSessionHasErrors('body');

    expect(Ticket::count())->toBe(0)->and(TicketReply::count())->toBe(0);
});

it('accepts the exact TEXT byte boundary and normal Chinese ticket content without truncation', function (string $body, int $bytes): void {
    $ticket = app(ManageTicketAction::class)->create(User::factory()->create(), ['subject' => 'Within both limits', 'body' => $body]);

    expect(strlen(TicketReply::where('ticket_id', $ticket->id)->sole()->body))->toBe($bytes)
        ->and(TicketReply::where('ticket_id', $ticket->id)->sole()->body)->toBe($body);
})->with([
    [str_repeat('😀', 16383).'abc', 65535],
    [str_repeat('中', 20000), 60000],
]);

it('rejects oversized customer and staff replies without changing ticket history or status', function (bool $staff): void {
    $owner = User::factory()->create();
    $ticket = app(ManageTicketAction::class)->create($owner, ['subject' => 'Original', 'body' => 'Original message']);
    $actor = $staff ? User::factory()->create() : $owner;
    if ($staff) {
        app(AssignAdminRoleAction::class)->execute($actor, 'support-agent', 'Isolated staff reply review');
    }

    $path = $staff ? '/admin/operations/tickets/'.$ticket->id : '/console/tickets/'.$ticket->id;
    $this->actingAs($actor)->from('/console/tickets/'.$ticket->id)->post($path,
        ['operation' => 'reply', 'body' => str_repeat('😀', 16384), 'reason' => 'Reply review'])
        ->assertRedirect('/console/tickets/'.$ticket->id)->assertSessionHasErrors('body');

    expect(TicketReply::where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and($ticket->fresh()->status)->toBe('open');
})->with([false, true]);

it('still enforces the original ticket character limit below the TEXT byte ceiling', function (): void {
    expect(fn () => app(ManageTicketAction::class)->create(User::factory()->create(), ['subject' => 'Too many characters', 'body' => str_repeat('a', 20001)]))
        ->toThrow(ValidationException::class);
    expect(Ticket::count())->toBe(0)->and(TicketReply::count())->toBe(0);
});

it('bounds ticket service choices while keeping older and deleted own services searchable', function (): void {
    $owner = User::factory()->create();
    $product = Product::factory()->create();
    $old = Instance::factory()->create(['user_id' => $owner->id, 'product_id' => $product->id, 'display_name' => 'Historical selected service',
        'primary_ip' => '192.0.2.71', 'status' => InstanceStatus::DELETED]);
    Instance::factory()->count(60)->create(['user_id' => $owner->id, 'product_id' => $product->id, 'display_name' => 'Recent service']);
    $foreign = Instance::factory()->create(['display_name' => 'Historical foreign service', 'primary_ip' => '192.0.2.71']);
    $first = $this->actingAs($owner)->get('/console/tickets')->assertOk()->assertDontSee($old->display_name)->assertDontSee($foreign->display_name);
    expect($first->viewData('instances')->count())->toBe(50)->and($first->viewData('instanceHasMore'))->toBeTrue();

    foreach (['Historical', '192.0.2.71', (string) $old->id] as $term) {
        $response = $this->get('/console/tickets?'.http_build_query(['service_q' => $term]))->assertOk()->assertSee($old->display_name)->assertDontSee($foreign->display_name);
        expect($response->viewData('instances')->pluck('id')->all())->toBe([$old->id]);
    }

    $this->post('/console/tickets', ['subject' => 'Old service remains supported', 'body' => 'Support request', 'instance_id' => $old->id])->assertRedirect();
    $this->assertDatabaseHas('tickets', ['user_id' => $owner->id, 'instance_id' => $old->id]);
    $this->post('/console/tickets', ['subject' => 'Wrong owner', 'body' => 'Support request', 'instance_id' => $foreign->id])->assertForbidden();
    expect(Ticket::count())->toBe(1);
});

it('keeps service search wildcards literal and preserves the search through ticket pagination', function (): void {
    $owner = User::factory()->create();
    $literal = Instance::factory()->create(['user_id' => $owner->id, 'display_name' => 'Literal 100% service']);
    Instance::factory()->create(['user_id' => $owner->id, 'display_name' => 'Unrelated service']);
    for ($n = 0; $n < 21; $n++) {
        Ticket::create(['user_id' => $owner->id, 'subject' => 'Ticket '.$n, 'status' => 'open']);
    }

    $response = $this->actingAs($owner)->get('/console/tickets?service_q=%25')->assertOk()->assertSee($literal->display_name)->assertDontSee('Unrelated service');
    expect($response->viewData('instances')->pluck('id')->all())->toBe([$literal->id])
        ->and($response->viewData('tickets')->nextPageUrl())->toContain('service_q=%25', 'page=2');
    $this->get('/console/tickets?service_q='.str_repeat('a', 101))->assertSessionHasErrors('service_q');
});

it('retains a previous own service selection after validation without expanding or leaking the selector', function (): void {
    $owner = User::factory()->create();
    $product = Product::factory()->create();
    $old = Instance::factory()->create(['user_id' => $owner->id, 'product_id' => $product->id, 'display_name' => 'Previously selected old service']);
    Instance::factory()->count(60)->create(['user_id' => $owner->id, 'product_id' => $product->id, 'display_name' => 'Recent service']);
    $foreign = Instance::factory()->create(['display_name' => 'Foreign selected service']);
    $this->actingAs($owner)->from('/console/tickets')->post('/console/tickets',
        ['subject' => '', 'body' => 'Draft body preserved', 'instance_id' => $old->id])->assertSessionHasErrors('subject');
    $response = $this->get('/console/tickets')->assertOk()->assertSee($old->display_name)->assertSee('Draft body preserved');
    expect($response->viewData('instances')->count())->toBe(50);
    expect($response->getContent())->toContain('value="'.$old->id.'" selected');

    $response = $this->withSession(['_old_input' => ['instance_id' => $foreign->id]])->get('/console/tickets')
        ->assertOk()->assertDontSee($foreign->display_name);
    expect($response->viewData('instances')->count())->toBe(50);
});
