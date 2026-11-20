<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function b2AccessActor(string ...$roles): User
{
    $actor = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated B2 operations presentation');
    }

    return $actor;
}

function b2AccessXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function b2AccessForm(DOMXPath $xpath, string $path, ?string $operation = null): DOMElement
{
    $predicate = $operation === null ? '' : '[.//input[@name="operation"][@value="'.$operation.'"]]';
    $forms = $xpath->query('//main//form[@method="post"][@action="'.$path.'"]'.$predicate);
    expect($forms)->toHaveCount(1);
    $form = $forms->item(0);
    expect($form)->toBeInstanceOf(DOMElement::class);

    return $form;
}

function b2AccessField(DOMXPath $xpath, DOMElement $form, string $name): DOMElement
{
    $fields = $xpath->query('.//*[@name="'.$name.'"]', $form);
    expect($fields)->toHaveCount(1);
    $field = $fields->item(0);
    expect($field)->toBeInstanceOf(DOMElement::class);

    return $field;
}

function b2AccessValue(DOMXPath $xpath, DOMElement $form, string $name): string
{
    $field = b2AccessField($xpath, $form, $name);
    if ($field->tagName === 'textarea') {
        return $field->textContent;
    }
    if ($field->tagName === 'select') {
        $selected = $xpath->query('.//option[@selected]', $field);
        expect($selected)->toHaveCount(1);

        return $selected->item(0)->getAttribute('value');
    }

    return $field->getAttribute('value');
}

function b2AccessCase(string $kind = 'payment_attention', string $status = 'open'): ReconciliationCase
{
    return ReconciliationCase::create(['user_id' => User::factory()->create()->id, 'kind' => $kind, 'source_type' => 'ui-isolated',
        'source_id' => (string) Str::ulid(), 'source_fingerprint' => str_repeat('a', 64), 'initial_reason' => 'wallet_capacity', 'status' => $status]);
}

function b2AccessDelivery(string $status = 'uncertain'): NotificationDelivery
{
    $owner = User::factory()->create();
    $key = (string) Str::ulid();
    $event = NotificationEvent::create(['user_id' => $owner->id, 'event_type' => 'invoice.paid', 'event_key' => $key,
        'dedupe_key' => hash('sha256', $key), 'encrypted_payload' => ['private' => 'PRIVATE-B2-EVENT-SNAPSHOT']]);

    return NotificationDelivery::create(['user_id' => $owner->id, 'notification_event_id' => $event->id, 'channel' => 'email',
        'recipient_key' => hash('sha256', $key), 'status' => $status, 'attempts' => 1, 'max_attempts' => 3,
        'encrypted_payload' => ['target' => 'PRIVATE-B2-RECIPIENT@example.invalid', 'body' => 'PRIVATE-B2-MESSAGE'], 'send_started_at' => now()]);
}

it('renders B2 operational pages locally with tables and no management forms for viewers', function (string $role, string $path): void {
    $actor = b2AccessActor($role);
    if ($path === '/admin/access') {
        $actor->givePermissionTo('access.view');
        $actor->removeRole('access-manager');
    }
    $audits = DB::table('audit_logs')->count();
    $response = $this->actingAs($actor)->get($path)->assertOk();
    $xpath = b2AccessXPath($response->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(0)
        ->and($xpath->query('//main//table'))->not->toHaveCount(0)
        ->and($xpath->query('//main//*[contains(concat(" ", normalize-space(@class), " "), " inventory-box ")]'))->toHaveCount(0)
        ->and($xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " fi-sidebar-nav ")]'))->toHaveCount(1)
        ->and(DB::table('audit_logs')->count())->toBe($audits);
    $this->assertDatabaseCount('financial_transactions', 0);
    Queue::assertNothingPushed();
})->with([
    ['access-manager', '/admin/access'], ['audit-viewer', '/admin/audit'], ['notification-viewer', '/admin/notification-outbox'],
    ['finance-viewer', '/admin/reconciliations'], ['upstream-viewer', '/admin/reconciliations'],
]);

it('denies ordinary customer access to each B2 operations page', function (string $path): void {
    $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
})->with(['/admin/access', '/admin/audit', '/admin/notification-outbox', '/admin/reconciliations']);

it('keeps role selection in dialogs with optimistic concurrency and no web self-management', function (): void {
    $manager = b2AccessActor('access-manager');
    $target = b2AccessActor('inventory-viewer');
    $xpath = b2AccessXPath($this->actingAs($manager)->get('/admin/access')->assertOk()->getContent());
    $form = b2AccessForm($xpath, '/admin/access/'.$target->id.'/roles');
    expect($xpath->query('ancestor::dialog[@aria-labelledby]', $form))->toHaveCount(1)
        ->and(b2AccessValue($xpath, $form, '_admin_form'))->toBe('access-roles')
        ->and(b2AccessValue($xpath, $form, 'record_id'))->toBe((string) $target->id)
        ->and(b2AccessValue($xpath, $form, 'expected_roles[]'))->toBe('inventory-viewer')
        ->and(b2AccessValue($xpath, $form, '_token'))->not->toBe('')
        ->and($xpath->query('//main//form[@action="/admin/access/'.$manager->id.'/roles"]'))->toHaveCount(0);
});

it('restores a failed role selection only to its target while retaining its original expected roles', function (): void {
    $manager = b2AccessActor('access-manager');
    $first = b2AccessActor('inventory-viewer');
    $second = b2AccessActor('support-viewer');
    $this->actingAs($manager)->from('/admin/access')->post('/admin/access/'.$first->id.'/roles', [
        '_admin_form' => 'access-roles', 'record_id' => $first->id, 'roles' => ['finance-viewer'],
        'expected_roles' => ['inventory-viewer'], 'reason' => 'B2 retry role selection',
    ])->assertSessionHasErrors('confirm');
    $xpath = b2AccessXPath($this->get('/admin/access')->assertOk()->getContent());
    $firstForm = b2AccessForm($xpath, '/admin/access/'.$first->id.'/roles');
    expect(b2AccessValue($xpath, $firstForm, 'reason'))->toBe('B2 retry role selection')
        ->and(b2AccessValue($xpath, $firstForm, 'expected_roles[]'))->toBe('inventory-viewer')
        ->and($xpath->query('.//input[@name="roles[]"][@checked]/@value', $firstForm)->item(0)->nodeValue)->toBe('finance-viewer')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $firstForm))->toHaveCount(1);
    $secondForm = b2AccessForm($xpath, '/admin/access/'.$second->id.'/roles');
    expect(b2AccessValue($xpath, $secondForm, 'reason'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $secondForm))->toHaveCount(0)
        ->and($first->fresh()->getRoleNames()->all())->toBe(['inventory-viewer']);
});

it('preserves an explicitly cleared role selection after validation fails', function (): void {
    $manager = b2AccessActor('access-manager');
    $target = b2AccessActor('inventory-viewer');
    $this->actingAs($manager)->from('/admin/access?user_id='.$target->id)->post('/admin/access/'.$target->id.'/roles', [
        '_admin_form' => 'access-roles', 'record_id' => $target->id, 'expected_roles' => ['inventory-viewer'],
    ])->assertSessionHasErrors('confirm');
    $xpath = b2AccessXPath($this->get('/admin/access?user_id='.$target->id)->assertOk()->getContent());
    $form = b2AccessForm($xpath, '/admin/access/'.$target->id.'/roles');
    expect($xpath->query('.//input[@name="roles[]"][@checked]', $form))->toHaveCount(0)
        ->and(b2AccessValue($xpath, $form, 'expected_roles[]'))->toBe('inventory-viewer')
        ->and($target->fresh()->getRoleNames()->all())->toBe(['inventory-viewer']);
});

it('does not restore a role error into another explicitly selected user', function (): void {
    $manager = b2AccessActor('access-manager');
    $first = b2AccessActor('inventory-viewer');
    $second = b2AccessActor('support-viewer');
    $this->actingAs($manager)->from('/admin/access')->post('/admin/access/'.$first->id.'/roles', [
        '_admin_form' => 'access-roles', 'record_id' => $first->id, 'roles' => ['finance-viewer'], 'reason' => 'PRIVATE-B2-FIRST-NOTE',
    ])->assertSessionHasErrors('confirm');
    $xpath = b2AccessXPath($this->get('/admin/access?user_id='.$second->id)->assertOk()->getContent());
    $form = b2AccessForm($xpath, '/admin/access/'.$second->id.'/roles');
    expect(b2AccessValue($xpath, $form, 'reason'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(0);
});

it('restores outbox conclusions only for the original uncertain delivery and never flashes supplier evidence', function (): void {
    $first = b2AccessDelivery();
    $second = b2AccessDelivery();
    $actor = b2AccessActor('notification-operator');
    $this->actingAs($actor)->from('/admin/notification-outbox')->post('/admin/notification-outbox/'.$first->id.'/resolve', [
        '_admin_form' => 'outbox-resolve', 'record_id' => $first->id, 'decision' => 'retry', 'reason' => 'B2 original offline review',
        'evidence' => 'PRIVATE-B2-SUPPLIER-EVIDENCE', 'worker_stopped' => '1', 'not_accepted' => '1',
    ])->assertSessionHasErrors('confirm')->assertSessionMissing('_old_input.evidence');
    $response = $this->get('/admin/notification-outbox')->assertOk()->assertDontSee('PRIVATE-B2-EVENT-SNAPSHOT')
        ->assertDontSee('PRIVATE-B2-RECIPIENT')->assertDontSee('PRIVATE-B2-MESSAGE')->assertDontSee('PRIVATE-B2-SUPPLIER-EVIDENCE');
    $xpath = b2AccessXPath($response->getContent());
    $form = b2AccessForm($xpath, '/admin/notification-outbox/'.$first->id.'/resolve');
    expect($xpath->query('ancestor::dialog[@aria-labelledby]', $form))->toHaveCount(1)
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1)
        ->and(b2AccessValue($xpath, $form, 'decision'))->toBe('retry')
        ->and(b2AccessValue($xpath, $form, 'reason'))->toBe('B2 original offline review')
        ->and(b2AccessValue($xpath, $form, 'evidence'))->toBe('')
        ->and(b2AccessField($xpath, $form, 'worker_stopped')->hasAttribute('checked'))->toBeTrue()
        ->and(b2AccessField($xpath, $form, 'not_accepted')->hasAttribute('checked'))->toBeTrue();
    $other = b2AccessForm($xpath, '/admin/notification-outbox/'.$second->id.'/resolve');
    expect(b2AccessValue($xpath, $other, 'reason'))->toBe('')
        ->and(b2AccessValue($xpath, $other, 'decision'))->toBe('accepted')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $other))->toHaveCount(0)
        ->and($first->fresh()->status)->toBe('uncertain');
    Queue::assertNothingPushed();
});

it('does not render reconciliation forms for completed notification deliveries or viewers', function (): void {
    $sent = b2AccessDelivery('sent');
    $uncertain = b2AccessDelivery();
    $xpath = b2AccessXPath($this->actingAs(b2AccessActor('notification-operator'))->get('/admin/notification-outbox')->assertOk()->getContent());
    expect($xpath->query('//main//form[@action="/admin/notification-outbox/'.$sent->id.'/resolve"]'))->toHaveCount(0);
    b2AccessForm($xpath, '/admin/notification-outbox/'.$uncertain->id.'/resolve');
    $viewer = b2AccessActor('notification-viewer');
    $xpath = b2AccessXPath($this->actingAs($viewer)->get('/admin/notification-outbox')->assertOk()->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(0);
});

it('restores a payment reconciliation to its posted case without showing encrypted evidence or changing its revision', function (): void {
    $case = b2AccessCase();
    ReconciliationEntry::create(['case_id' => $case->id, 'sequence' => 1, 'request_key' => 'observed', 'action' => 'observed',
        'reason' => 'Original observation', 'evidence' => ['private' => 'PRIVATE-B2-STORED-EVIDENCE'], 'created_at' => now()]);
    $requestId = (string) Str::ulid();
    $this->actingAs(b2AccessActor('finance-reconciliation'))->from('/admin/reconciliations')->post('/admin/reconciliations/'.$case->id.'/payment', [
        '_admin_form' => 'reconciliation-payment', 'record_id' => $case->id, 'request_id' => $requestId, 'expected_revision' => 1,
        'evidence' => 'PRIVATE-B2-NEW-EVIDENCE', 'reason' => 'B2 reconciliation note',
    ])->assertSessionHasErrors('confirm')->assertSessionMissing('_old_input.evidence');
    $response = $this->get('/admin/reconciliations')->assertOk()->assertDontSee('PRIVATE-B2-STORED-EVIDENCE')->assertDontSee('PRIVATE-B2-NEW-EVIDENCE');
    $xpath = b2AccessXPath($response->getContent());
    $form = b2AccessForm($xpath, '/admin/reconciliations/'.$case->id.'/payment');
    expect($xpath->query('ancestor::dialog[@aria-labelledby]', $form))->toHaveCount(1)
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1)
        ->and(b2AccessValue($xpath, $form, 'request_id'))->toBe($requestId)
        ->and(b2AccessValue($xpath, $form, 'expected_revision'))->toBe('1')
        ->and(b2AccessValue($xpath, $form, 'reason'))->toBe('B2 reconciliation note')
        ->and(b2AccessValue($xpath, $form, 'evidence'))->toBe('')
        ->and($case->fresh()->revision)->toBe(1)->and($case->fresh()->status)->toBe('open');
    $evidence = b2AccessForm($xpath, '/admin/reconciliations/'.$case->id.'/evidence');
    expect($xpath->query('.//*[@name="confirm" or @name="reason"]', $evidence))->toHaveCount(0);
    $this->assertDatabaseCount('reconciliation_entries', 1);
});

it('isolates binding reconciliation operations and requires reentry of protected transfer facts', function (): void {
    $case = b2AccessCase('binding_orphan');
    $requestId = (string) Str::ulid();
    $this->actingAs(b2AccessActor('upstream-orphan-reviewer'))->from('/admin/reconciliations?case='.$case->id)
        ->post('/admin/reconciliations/'.$case->id.'/binding', [
            '_admin_form' => 'reconciliation-binding', 'record_id' => $case->id, 'operation' => 'transfer_verified',
            'request_id' => $requestId, 'expected_revision' => 1, 'reason' => 'B2 original transfer review',
            'evidence' => 'PRIVATE-B2-TRANSFER-EVIDENCE', 'recipient' => 'PRIVATE-B2-TRANSFER-RECIPIENT', 'target_resource' => 'Original cloud resource 14',
            'acceptance_reference' => 'PRIVATE-B2-TRANSFER-RECEIPT', 'historical_endpoint_evidence' => 'PRIVATE-B2-ENDPOINT-EVIDENCE',
            'accepted_at' => now()->subHour()->format('Y-m-d\TH:i'), 'responsibility_accepted' => '1',
        ])->assertSessionHasErrors('confirm')->assertSessionMissing('_old_input.evidence')->assertSessionMissing('_old_input.recipient')
        ->assertSessionMissing('_old_input.acceptance_reference')->assertSessionMissing('_old_input.historical_endpoint_evidence');
    $response = $this->get('/admin/reconciliations?case='.$case->id)->assertOk()->assertDontSee('PRIVATE-B2-TRANSFER')->assertDontSee('PRIVATE-B2-ENDPOINT');
    $xpath = b2AccessXPath($response->getContent());
    $transfer = b2AccessForm($xpath, '/admin/reconciliations/'.$case->id.'/binding', 'transfer_verified');
    expect(b2AccessValue($xpath, $transfer, 'target_resource'))->toBe('Original cloud resource 14')
        ->and(b2AccessValue($xpath, $transfer, 'request_id'))->toBe($requestId)
        ->and(b2AccessValue($xpath, $transfer, 'recipient'))->toBe('')
        ->and(b2AccessValue($xpath, $transfer, 'acceptance_reference'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $transfer))->toHaveCount(1);
    $termination = b2AccessForm($xpath, '/admin/reconciliations/'.$case->id.'/binding', 'terminate_verified');
    expect(b2AccessValue($xpath, $termination, 'reason'))->toBe('')
        ->and(b2AccessValue($xpath, $termination, 'request_id'))->not->toBe($requestId)
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $termination))->toHaveCount(0);
    $this->assertDatabaseCount('reconciliation_entries', 0);
});

it('honors an explicit case choice after a different case fails validation', function (): void {
    $first = b2AccessCase();
    $second = b2AccessCase();
    $this->actingAs(b2AccessActor('finance-reconciliation'))->from('/admin/reconciliations')->post('/admin/reconciliations/'.$first->id.'/payment', [
        '_admin_form' => 'reconciliation-payment', 'record_id' => $first->id, 'request_id' => (string) Str::ulid(),
        'expected_revision' => 1, 'reason' => 'B2 first case unsaved', 'evidence' => 'Do not flash',
    ])->assertSessionHasErrors('confirm');
    $xpath = b2AccessXPath($this->get('/admin/reconciliations?case='.$second->id)->assertOk()->getContent());
    $form = b2AccessForm($xpath, '/admin/reconciliations/'.$second->id.'/payment');
    expect(b2AccessValue($xpath, $form, 'reason'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(0)
        ->and($xpath->query('//main//form[@action="/admin/reconciliations/'.$first->id.'/payment"]'))->toHaveCount(0);
});

it('keeps resolved case operations distinct from unclosed payment or binding processing', function (): void {
    $case = b2AccessCase('payment_attention', 'resolved');
    $xpath = b2AccessXPath($this->actingAs(b2AccessActor('finance-reconciliation'))->get('/admin/reconciliations?case='.$case->id)->assertOk()->getContent());
    $form = b2AccessForm($xpath, '/admin/reconciliations/'.$case->id.'/reopen');
    expect($xpath->query('ancestor::dialog[@aria-labelledby]', $form))->toHaveCount(1)
        ->and(b2AccessField($xpath, $form, 'confirm')->hasAttribute('required'))->toBeTrue()
        ->and(b2AccessField($xpath, $form, 'evidence')->hasAttribute('required'))->toBeTrue()
        ->and($xpath->query('//main//form[@action="/admin/reconciliations/'.$case->id.'/payment"]'))->toHaveCount(0);
});

it('keeps finance and upstream reconciliation visibility separated', function (): void {
    $payment = b2AccessCase();
    $binding = b2AccessCase('binding_orphan');
    $this->actingAs(b2AccessActor('finance-reconciliation'))->get('/admin/reconciliations?case='.$binding->id)->assertNotFound();
    $this->actingAs(b2AccessActor('upstream-orphan-reviewer'))->get('/admin/reconciliations?case='.$payment->id)->assertNotFound();
});

it('keeps audit snapshots allowlisted and rendered as escaped read-only fields', function (): void {
    $actor = b2AccessActor('audit-viewer');
    DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'b2.ui.audit', 'subject_type' => User::class, 'subject_id' => $actor->id,
        'reason' => '<script>B2-ESCAPE-AUDIT</script>', 'before' => json_encode(['status' => 'Paid', 'password' => 'PRIVATE-B2-AUDIT-PASSWORD']),
        'after' => json_encode(['credit' => '12.00', 'request' => ['secret' => 'PRIVATE-B2-AUDIT-REQUEST']]), 'created_at' => now(), 'updated_at' => now()]);
    $response = $this->actingAs($actor)->get('/admin/audit?action=b2.ui.audit')->assertOk()->assertSee('Paid')->assertSee('12.00')
        ->assertSee('&lt;script&gt;B2-ESCAPE-AUDIT&lt;/script&gt;', false)->assertDontSee('<script>B2-ESCAPE-AUDIT</script>', false)
        ->assertDontSee('PRIVATE-B2-AUDIT-PASSWORD')->assertDontSee('PRIVATE-B2-AUDIT-REQUEST');
    $xpath = b2AccessXPath($response->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(0)
        ->and($xpath->query('//main//details/summary'))->toHaveCount(1);
});

it('keeps populated reconciliation details read-only for each viewing domain', function (string $role, string $kind): void {
    $case = b2AccessCase($kind);
    ReconciliationEntry::create(['case_id' => $case->id, 'sequence' => 1, 'request_key' => 'original', 'action' => 'observed',
        'reason' => 'Original nonsecret classification', 'evidence' => ['private' => 'PRIVATE-B2-VIEWER-EVIDENCE'], 'created_at' => now()]);
    $response = $this->actingAs(b2AccessActor($role))->get('/admin/reconciliations?case='.$case->id)->assertOk()
        ->assertSee('Original nonsecret classification')->assertDontSee('PRIVATE-B2-VIEWER-EVIDENCE');
    $xpath = b2AccessXPath($response->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(0);
})->with([['finance-viewer', 'payment_attention'], ['upstream-viewer', 'binding_orphan']]);

it('keeps both outbox paginations bound to the active user and delivery filters', function (): void {
    $first = b2AccessDelivery();
    $event = NotificationEvent::findOrFail($first->notification_event_id);
    for ($index = 1; $index <= 30; $index++) {
        $key = (string) Str::ulid();
        $nextEvent = $event->replicate();
        $nextEvent->fill(['event_key' => $key, 'dedupe_key' => hash('sha256', $key)])->save();
        $nextDelivery = $first->replicate();
        $nextDelivery->fill(['notification_event_id' => $nextEvent->id, 'recipient_key' => hash('sha256', $key)])->save();
    }
    $response = $this->actingAs(b2AccessActor('notification-viewer'))
        ->get('/admin/notification-outbox?user_id='.$first->user_id.'&delivery_status=uncertain')->assertOk();
    $xpath = b2AccessXPath($response->getContent());
    $nextPages = $xpath->query('//main//a[@rel="next"]');
    expect($nextPages)->toHaveCount(2);
    foreach ($nextPages as $link) {
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        expect($query['user_id'])->toBe((string) $first->user_id)->and($query['delivery_status'])->toBe('uncertain');
    }
});

it('renders validation feedback safely when a role selection contains invalid structured values', function (): void {
    $manager = b2AccessActor('access-manager');
    $target = b2AccessActor('inventory-viewer');
    $this->actingAs($manager)->from('/admin/access')->post('/admin/access/'.$target->id.'/roles', [
        '_admin_form' => 'access-roles', 'record_id' => $target->id, 'roles' => [['invalid-structured-role']],
        'expected_roles' => ['inventory-viewer'], 'reason' => ['invalid-structured-note'],
    ])->assertSessionHasErrors(['reason', 'confirm']);
    $xpath = b2AccessXPath($this->get('/admin/access')->assertOk()->getContent());
    $form = b2AccessForm($xpath, '/admin/access/'.$target->id.'/roles');
    expect(b2AccessValue($xpath, $form, 'reason'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1)
        ->and($target->fresh()->getRoleNames()->all())->toBe(['inventory-viewer']);
});
