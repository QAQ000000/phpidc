<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Models\AffiliateWithdrawal;
use App\Models\Instance;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use App\Services\NotificationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

/** @return array{User, array<array{Model, array<string, array<string, mixed>>, array<string, string>}>} */
function sensitiveSerializationFixtures(): array
{
    $owner = User::factory()->create();
    $withdrawalSecrets = [
        'encrypted_payout_details' => ['account' => 'PRIVATE-SERIALIZATION-PAYEE'],
        'encrypted_payout_evidence' => ['proof' => 'PRIVATE-SERIALIZATION-PAYOUT-PROOF'],
        'operation_requests' => ['request' => ['intent' => ['evidence' => 'PRIVATE-SERIALIZATION-INTENT']]],
    ];
    $withdrawal = AffiliateWithdrawal::create(['user_id' => $owner->id, 'amount' => '1.00', 'destination' => 'external',
        'status' => 'pending', 'payout_method' => 'bank', 'request_id' => (string) Str::ulid(), ...$withdrawalSecrets]);
    $instanceSecrets = ['encrypted_credentials' => ['password' => 'PRIVATE-SERIALIZATION-INSTANCE-PASSWORD']];
    $instanceTokens = ['downstream_token' => 'PRIVATE-SERIALIZATION-CALLBACK-TOKEN'];
    $instance = Instance::factory()->create(['user_id' => $owner->id, ...$instanceSecrets, ...$instanceTokens]);
    $case = ReconciliationCase::create(['user_id' => $owner->id, 'kind' => 'payment_attention', 'source_type' => 'callback',
        'source_id' => 'serialization-'.Str::ulid(), 'source_fingerprint' => str_repeat('a', 64), 'initial_reason' => 'wallet_capacity']);
    $entrySecrets = ['evidence' => ['note' => 'PRIVATE-SERIALIZATION-RECONCILIATION']];
    $entry = ReconciliationEntry::create(['case_id' => $case->id, 'sequence' => 1, 'request_key' => 'original',
        'action' => 'observed', 'reason' => 'Isolated original evidence', 'created_at' => now(), ...$entrySecrets]);
    app(NotificationManager::class)->queue($owner->id, 'ticket.reply', ['status' => 'PRIVATE-SERIALIZATION-NOTIFICATION'],
        eventKey: 'serialization:notification');
    $event = NotificationEvent::sole();
    $delivery = NotificationDelivery::sole();

    return [$owner, [
        [$withdrawal->fresh(), $withdrawalSecrets, []],
        [$instance->fresh(), $instanceSecrets, $instanceTokens],
        [$entry->fresh(), $entrySecrets, []],
        [$event, ['encrypted_payload' => $event->encrypted_payload], []],
        [$delivery, ['encrypted_payload' => $delivery->encrypted_payload], []],
    ]];
}

it('keeps persisted sensitive model attributes out of default arrays JSON and nested collection serialization', function (): void {
    [, $fixtures] = sensitiveSerializationFixtures();
    $models = collect();
    foreach ($fixtures as [$record, $encrypted, $plain]) {
        $array = $record->toArray();
        expect($array['id'])->toBe($record->id);
        foreach ($encrypted as $field => $value) {
            expect($record->getAttribute($field))->toBe($value)
                ->and($record->getRawOriginal($field))->not->toBe(json_encode($value, JSON_THROW_ON_ERROR))
                ->and($array)->not->toHaveKey($field);
        }
        foreach ($plain as $field => $value) {
            expect($record->getAttribute($field))->toBe($value)->and($array)->not->toHaveKey($field);
        }
        expect($record->toJson())->not->toContain('PRIVATE-SERIALIZATION-');
        $models->push($record);
    }
    expect($models->toJson())->not->toContain('PRIVATE-SERIALIZATION-');
    Http::assertNothingSent();
});

it('does not decrypt hidden ciphertext merely to serialize safe model metadata', function (): void {
    [, $fixtures] = sensitiveSerializationFixtures();
    foreach ($fixtures as [$record, $encrypted]) {
        $attributes = $record->getAttributes();
        foreach (array_keys($encrypted) as $field) {
            $attributes[$field] = 'not-a-valid-encrypted-envelope';
        }
        $record->setRawAttributes($attributes, true);
        expect($record->toArray()['id'])->toBe($record->id)
            ->and($record->toJson())->not->toContain('not-a-valid-encrypted-envelope', 'PRIVATE-SERIALIZATION-');
    }
});

it('preserves explicit authorized access and audit for hidden payee and reconciliation evidence', function (): void {
    [$owner, $fixtures] = sensitiveSerializationFixtures();
    $withdrawal = $fixtures[0][0];
    $entry = $fixtures[2][0];
    $actor = User::factory()->create();
    foreach (['external-payout-operator', 'finance-reconciliation'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated protected serialization acceptance');
    }
    $payeePath = '/admin/external-payouts/'.$withdrawal->id.'/details';
    $evidencePath = '/admin/reconciliations/'.$entry->case_id.'/evidence';
    $this->actingAs($owner)->post($payeePath)->assertForbidden();
    $this->post($evidencePath)->assertForbidden();
    $this->actingAs($actor)->post($payeePath)->assertOk()->assertSee('PRIVATE-SERIALIZATION-PAYEE')
        ->assertDontSee('PRIVATE-SERIALIZATION-INTENT')->assertHeader('Cache-Control', 'no-store, private');
    $this->post($evidencePath)->assertOk()->assertSee('PRIVATE-SERIALIZATION-RECONCILIATION')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect(DB::table('audit_logs')->where('action', 'affiliate.external_details_view')->where('actor_id', $actor->id)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'reconciliation.evidence_view')->where('actor_id', $actor->id)->count())->toBe(1);
    Http::assertNothingSent();
});
