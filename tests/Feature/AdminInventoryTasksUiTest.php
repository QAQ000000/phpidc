<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

function b2InventoryActor(string ...$roles): User
{
    $actor = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'B2 inventory UI acceptance');
    }

    return $actor;
}

function b2InventoryXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function b2InventoryForm(DOMXPath $xpath, string $path): DOMElement
{
    $forms = $xpath->query('//main//form[@method="post"][@action="'.$path.'"]');
    expect($forms)->toHaveCount(1);
    $form = $forms->item(0);
    expect($form)->toBeInstanceOf(DOMElement::class);

    return $form;
}

function b2InventoryField(DOMXPath $xpath, DOMElement $form, string $name): DOMElement
{
    $fields = $xpath->query('.//*[@name="'.$name.'"]', $form);
    expect($fields)->toHaveCount(1);
    $field = $fields->item(0);
    expect($field)->toBeInstanceOf(DOMElement::class);

    return $field;
}

it('keeps B2 inventory and task pages read only for their viewing role', function (string $role, string $path): void {
    $actor = b2InventoryActor($role);
    $auditCount = DB::table('audit_logs')->count();
    $xpath = b2InventoryXPath($this->actingAs($actor)->get($path)->assertOk()->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(0)
        ->and($xpath->query('//main//table'))->not->toHaveCount(0)
        ->and($xpath->query('//main//*[contains(concat(" ", normalize-space(@class), " "), " inventory-box ")]'))->toHaveCount(0)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount);
    Queue::assertNothingPushed();
})->with([
    ['inventory-viewer', '/admin/inventory'], ['inventory-viewer', '/admin/inventory/cards'],
    ['inventory-viewer', '/admin/fulfillment'], ['task-viewer', '/admin/tasks'],
]);

it('keeps B2 inventory and task pages inaccessible to ordinary customers', function (string $path): void {
    $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
})->with(['/admin/inventory', '/admin/inventory/cards', '/admin/fulfillment', '/admin/tasks']);

it('restores a failed card import in its dialog without flashing card contents', function (): void {
    $product = Product::factory()->create(['name' => 'B2 local stock']);
    $reason = str_repeat('批', 1001);
    $this->actingAs(b2InventoryActor('inventory-operator'))->from('/admin/inventory/cards')->post('/admin/inventory/import', [
        '_admin_form' => 'inventory-import', 'product_id' => $product->id, 'codes' => 'B2-PRIVATE-FAILED-CARD', 'reason' => $reason,
    ])->assertSessionHasErrors('reason')->assertSessionMissing('_old_input.codes');
    $response = $this->get('/admin/inventory/cards')->assertOk()->assertDontSee('B2-PRIVATE-FAILED-CARD')->assertSee('请重新粘贴');
    $xpath = b2InventoryXPath($response->getContent());
    $form = b2InventoryForm($xpath, route('inventory.import'));
    expect(b2InventoryField($xpath, $form, 'codes')->textContent)->toBe('')
        ->and(b2InventoryField($xpath, $form, 'reason')->getAttribute('value'))->toBe($reason)
        ->and($xpath->query('.//select[@name="product_id"]/option[@selected][@value="'.$product->id.'"]', $form))->toHaveCount(1)
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1);
    $this->assertDatabaseCount('redemption_codes', 0);
});

it('honors a different selected product after a card import failed', function (): void {
    $first = Product::factory()->create();
    $second = Product::factory()->create();
    $this->actingAs(b2InventoryActor('inventory-operator'))->from('/admin/inventory/cards')->post('/admin/inventory/import', [
        '_admin_form' => 'inventory-import', 'product_id' => $first->id, 'codes' => '', 'reason' => 'Original product note',
    ])->assertSessionHasErrors('codes');
    $xpath = b2InventoryXPath($this->get('/admin/inventory/cards?product_id='.$second->id)->assertOk()->getContent());
    $form = b2InventoryForm($xpath, route('inventory.import'));
    expect($xpath->query('.//select[@name="product_id"]/option[@selected][@value="'.$second->id.'"]', $form))->toHaveCount(1)
        ->and(b2InventoryField($xpath, $form, 'reason')->getAttribute('value'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(0);
});

it('shows only card metadata and preserves card filters in pagination', function (): void {
    $product = Product::factory()->create();
    RedemptionCode::factory()->count(21)->create(['product_id' => $product->id, 'batch_no' => 'B2-batch', 'status' => 'available']);
    $private = RedemptionCode::factory()->create(['product_id' => $product->id, 'batch_no' => 'B2-batch', 'code' => 'B2-PRIVATE-STORED-CARD']);
    $response = $this->actingAs(b2InventoryActor('inventory-viewer'))->get('/admin/inventory/cards?product_id='.$product->id.'&status=available&batch=B2-batch')->assertOk()
        ->assertDontSee('B2-PRIVATE-STORED-CARD')->assertDontSee($private->code_hash);
    $xpath = b2InventoryXPath($response->getContent());
    $links = $xpath->query('//main//nav[@aria-label="结果分页"]//a[@href]');
    expect($links->length)->toBeGreaterThan(0);
    foreach ($links as $link) {
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        expect($query['product_id'])->toBe((string) $product->id)->and($query['status'])->toBe('available')->and($query['batch'])->toBe('B2-batch');
    }
});

it('restores only the failed fulfillment retry and keeps its original confirmation contract', function (): void {
    $first = FulfillmentTask::factory()->create(['status' => FulfillmentTaskStatus::FAILED]);
    $second = FulfillmentTask::factory()->create(['status' => FulfillmentTaskStatus::FAILED]);
    $this->actingAs(b2InventoryActor('inventory-operator'))->from('/admin/fulfillment')->post('/admin/fulfillment/'.$first->id.'/retry', [
        '_admin_form' => 'fulfillment-retry', 'record_id' => $first->id, 'reason' => 'First task stock checked',
    ])->assertSessionHasErrors('confirm');
    $xpath = b2InventoryXPath($this->get('/admin/fulfillment')->assertOk()->getContent());
    $form = b2InventoryForm($xpath, route('inventory.retry', $first->id));
    $other = b2InventoryForm($xpath, route('inventory.retry', $second->id));
    expect(b2InventoryField($xpath, $form, 'reason')->getAttribute('value'))->toBe('First task stock checked')
        ->and(b2InventoryField($xpath, $other, 'reason')->getAttribute('value'))->toBe('')
        ->and(b2InventoryField($xpath, $form, 'confirm')->hasAttribute('required'))->toBeTrue()
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1)
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $other))->toHaveCount(0)
        ->and($first->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
    Queue::assertNothingPushed();
});

it('does not reopen a retry after its task has entered a running lease', function (): void {
    $task = FulfillmentTask::factory()->create(['status' => FulfillmentTaskStatus::FAILED]);
    $this->actingAs(b2InventoryActor('task-viewer', 'inventory-operator'))->from('/admin/tasks')->post('/admin/tasks/'.$task->id.'/retry', [
        '_admin_form' => 'task-retry', 'record_id' => $task->id, 'reason' => 'Retry stale view',
    ])->assertSessionHasErrors('confirm');
    $task->update(['status' => FulfillmentTaskStatus::RUNNING, 'locked_by' => 'B2-current-worker', 'lease_expires_at' => now()->addMinutes(3)]);
    $xpath = b2InventoryXPath($this->get('/admin/tasks')->assertOk()->getContent());
    expect($xpath->query('//main//form[@action="/admin/tasks/'.$task->id.'/retry"]'))->toHaveCount(0)
        ->and($task->fresh()->locked_by)->toBe('B2-current-worker');
    Queue::assertNothingPushed();
});

it('isolates task retry inputs and honors an explicitly filtered task after another retry failed', function (): void {
    $first = FulfillmentTask::factory()->create(['status' => FulfillmentTaskStatus::FAILED]);
    $second = FulfillmentTask::factory()->create(['status' => FulfillmentTaskStatus::FAILED]);
    $this->actingAs(b2InventoryActor('task-viewer', 'inventory-operator'))->from('/admin/tasks')->post('/admin/tasks/'.$first->id.'/retry', [
        '_admin_form' => 'task-retry', 'record_id' => $first->id, 'reason' => 'First generic task checked',
    ])->assertSessionHasErrors('confirm');
    $xpath = b2InventoryXPath($this->get('/admin/tasks')->assertOk()->getContent());
    $firstForm = b2InventoryForm($xpath, '/admin/tasks/'.$first->id.'/retry');
    $secondForm = b2InventoryForm($xpath, '/admin/tasks/'.$second->id.'/retry');
    expect(b2InventoryField($xpath, $firstForm, 'reason')->getAttribute('value'))->toBe('First generic task checked')
        ->and(b2InventoryField($xpath, $secondForm, 'reason')->getAttribute('value'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $firstForm))->toHaveCount(1)
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $secondForm))->toHaveCount(0);
    $xpath = b2InventoryXPath($this->get('/admin/tasks?task_id='.$second->id)->assertOk()->getContent());
    $secondForm = b2InventoryForm($xpath, '/admin/tasks/'.$second->id.'/retry');
    expect(b2InventoryField($xpath, $secondForm, 'reason')->getAttribute('value'))->toBe('')
        ->and($xpath->query('//main//form[@action="/admin/tasks/'.$first->id.'/retry"]'))->toHaveCount(0);
});

it('retains separate classic and local retry permissions and blocks remote card replay in the UI', function (): void {
    $local = FulfillmentTask::factory()->create(['status' => FulfillmentTaskStatus::FAILED]);
    $classicInstance = Instance::factory()->create(['driver_type' => DriverType::ZJMF_API]);
    $classic = FulfillmentTask::factory()->create(['instance_id' => $classicInstance->id, 'status' => FulfillmentTaskStatus::FAILED]);
    $remoteAccount = UpstreamApi::factory()->create(['driver' => DriverType::SUB2API]);
    $remoteInstance = Instance::factory()->create(['driver_type' => DriverType::SUB2API, 'upstream_api_id' => $remoteAccount->id]);
    $remote = FulfillmentTask::factory()->create(['instance_id' => $remoteInstance->id, 'status' => FulfillmentTaskStatus::FAILED]);
    $actor = b2InventoryActor('task-viewer', 'inventory-operator');
    $xpath = b2InventoryXPath($this->actingAs($actor)->get('/admin/tasks')->assertOk()->getContent());
    b2InventoryForm($xpath, '/admin/tasks/'.$local->id.'/retry');
    expect($xpath->query('//main//form[@action="/admin/tasks/'.$classic->id.'/retry"]'))->toHaveCount(0)
        ->and($xpath->query('//main//form[@action="/admin/tasks/'.$remote->id.'/retry"]'))->toHaveCount(0);
    app(AssignAdminRoleAction::class)->execute($actor, 'upstream-lifecycle', 'B2 classic recovery authority');
    $xpath = b2InventoryXPath($this->get('/admin/tasks')->assertOk()->getContent());
    b2InventoryForm($xpath, '/admin/tasks/'.$classic->id.'/retry');
    expect($xpath->query('//main//form[@action="/admin/tasks/'.$remote->id.'/retry"]'))->toHaveCount(0);
});

it('retains safe failure metadata and independent task and queue pagination', function (): void {
    FulfillmentTask::factory()->count(21)->create(['payload' => ['password' => 'B2-PRIVATE-TASK-PAYLOAD'], 'error_message' => 'B2-PRIVATE-TASK-ERROR']);
    foreach (range(1, 11) as $index) {
        DB::table('failed_jobs')->insert(['uuid' => 'b2-failure-'.$index, 'connection' => 'database', 'queue' => 'default',
            'payload' => 'B2-PRIVATE-QUEUE-PAYLOAD', 'exception' => 'B2-PRIVATE-QUEUE-EXCEPTION', 'failed_at' => now()]);
    }
    $response = $this->actingAs(b2InventoryActor('task-viewer'))->get('/admin/tasks?tasks_page=2&failures_page=2')->assertOk()
        ->assertDontSee('B2-PRIVATE-TASK-PAYLOAD')->assertDontSee('B2-PRIVATE-TASK-ERROR')
        ->assertDontSee('B2-PRIVATE-QUEUE-PAYLOAD')->assertDontSee('B2-PRIVATE-QUEUE-EXCEPTION');
    $xpath = b2InventoryXPath($response->getContent());
    $links = $xpath->query('//main//nav[@aria-label="结果分页"]//a[@href]');
    expect($links->length)->toBeGreaterThan(1);
    foreach ($links as $link) {
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        expect($query)->toHaveKeys(['tasks_page', 'failures_page']);
    }
});
