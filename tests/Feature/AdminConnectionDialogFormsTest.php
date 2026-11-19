<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Enums\DriverType;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    $operator = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($operator, 'upstream-operator', 'Isolated connection dialog authority');
    $this->actingAs($operator);
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function connectionDialogAccount(string $kind, string $name): UpstreamApi
{
    return UpstreamApi::factory()->create([
        'name' => $name,
        'driver' => $kind === 'classic' ? DriverType::ZJMF_API : DriverType::SUB2API,
        'username' => 'original@example.test',
        'auth_header_mode' => 'raw_jwt',
        'config' => ['allow_credit_limit' => true],
        'is_active' => true,
        'encrypted_secret' => 'DIALOG-STORED-PRIVATE-KEY',
    ]);
}

function connectionDialogXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function connectionDialogForm(DOMXPath $xpath, string $key): DOMElement
{
    $forms = $xpath->query('//form[input[@name="ui_form" and @value="'.$key.'"]]');
    expect($forms)->toHaveCount(1);
    $form = $forms->item(0);
    expect($form)->toBeInstanceOf(DOMElement::class);

    return $form;
}

function connectionDialogValue(DOMXPath $xpath, DOMElement $form, string $name): string
{
    $input = $xpath->query('.//input[@name="'.$name.'"]', $form)->item(0);
    expect($input)->toBeInstanceOf(DOMElement::class);

    return $input->getAttribute('value');
}

it('restores invalid connection input only into its originating dialog form', function (string $kind, bool $editing): void {
    $account = connectionDialogAccount($kind, 'Original target');
    $other = connectionDialogAccount($kind, 'Other target');
    $opposite = connectionDialogAccount($kind === 'classic' ? 'sub2api' : 'classic', 'Other kind');
    $before = $account->fresh()->getRawOriginal();
    $key = $kind.'-'.($editing ? $account->id : 'new');
    $payload = [
        'ui_dialog' => 'upstream-connections', 'ui_form' => $key,
        'name' => 'Unsaved target', 'url' => 'not-an-interface-url',
        'api_secret' => 'DIALOG-FAILED-PRIVATE-KEY', 'reason' => '尚未保存的备注',
        ...($editing ? ['account_id' => $account->id] : []),
        ...($kind === 'classic' ? ['username' => 'unsaved@example.test', 'raw_jwt' => $editing ? '0' : '1', 'credit_limit' => $editing ? '0' : '1'] : ['active' => '0']),
    ];
    $this->from('/admin/upstreams')->post('/admin/upstreams'.($kind === 'classic' ? '' : '/sub2api').'/configure', $payload)
        ->assertRedirect('/admin/upstreams')->assertSessionHasErrors('url');
    expect(session('_old_input.api_secret'))->toBeNull();
    $urlError = session('errors')->first('url');
    $response = $this->get('/admin/upstreams')->assertOk()
        ->assertDontSee('DIALOG-FAILED-PRIVATE-KEY')->assertDontSee('DIALOG-STORED-PRIVATE-KEY')
        ->assertDontSee($account->getRawOriginal('encrypted_secret'));
    $xpath = connectionDialogXPath($response->getContent());
    $alerts = $xpath->query('//dialog[@id="upstream-connections"]//*[@role="alert"]');
    expect($alerts)->toHaveCount(1)
        ->and($alerts->item(0)->textContent)->toContain($urlError)
        ->and($xpath->query('//dialog[@id="upstream-import"]//*[@role="alert"]'))->toHaveCount(0);
    $form = connectionDialogForm($xpath, $key);
    expect(connectionDialogValue($xpath, $form, 'ui_dialog'))->toBe('upstream-connections')
        ->and(connectionDialogValue($xpath, $form, 'name'))->toBe('Unsaved target')
        ->and(connectionDialogValue($xpath, $form, 'url'))->toBe('not-an-interface-url')
        ->and(connectionDialogValue($xpath, $form, 'reason'))->toBe('尚未保存的备注')
        ->and(connectionDialogValue($xpath, $form, 'api_secret'))->toBe('');
    expect($xpath->query('.//input[@name="api_secret" and @required]', $form))->toHaveCount($editing ? 0 : 1)
        ->and($xpath->query('.//input[@name="reason" and @required]', $form))->toHaveCount(0)
        ->and($xpath->query('.//input[@name="reason"]', $form)->item(0)->getAttribute('maxlength'))->toBe($kind === 'classic' ? '1000' : '255');
    if ($kind === 'classic') {
        expect(connectionDialogValue($xpath, $form, 'username'))->toBe('unsaved@example.test');
        foreach (['raw_jwt', 'credit_limit'] as $flag) {
            expect($xpath->query('.//input[@type="hidden" and @name="'.$flag.'" and @value="0"]', $form))->toHaveCount(1)
                ->and($xpath->query('.//input[@type="checkbox" and @name="'.$flag.'" and @value="1"]', $form))->toHaveCount(1)
                ->and($xpath->query('.//input[@type="checkbox" and @name="'.$flag.'" and @checked]', $form))->toHaveCount($editing ? 0 : 1);
        }
    } else {
        expect($xpath->query('.//input[@name="url" and @readonly]', $form))->toHaveCount($editing ? 1 : 0)
            ->and($xpath->query('.//input[@type="hidden" and @name="active" and @value="0"]', $form))->toHaveCount(1)
            ->and($xpath->query('.//input[@type="checkbox" and @name="active" and @value="1"]', $form))->toHaveCount(1)
            ->and($xpath->query('.//input[@type="checkbox" and @name="active" and @checked]', $form))->toHaveCount(0);
    }
    foreach ([$other, $opposite] as $untouched) {
        $otherKey = ($untouched->driver === DriverType::ZJMF_API ? 'classic' : 'sub2api').'-'.$untouched->id;
        $otherForm = connectionDialogForm($xpath, $otherKey);
        expect(connectionDialogValue($xpath, $otherForm, 'name'))->toBe($untouched->name)
            ->and(connectionDialogValue($xpath, $otherForm, 'url'))->toBe($untouched->base_url)
            ->and(connectionDialogValue($xpath, $otherForm, 'reason'))->toBe('')
            ->and(connectionDialogValue($xpath, $otherForm, 'api_secret'))->toBe('');
    }
    expect($account->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('upstream_apis', 3);
})->with([
    'classic edit' => ['classic', true], 'classic create' => ['classic', false],
    'sub2api edit' => ['sub2api', true], 'sub2api create' => ['sub2api', false],
]);

it('renders invalid array input without a server error or copying it to another connection form', function (string $kind): void {
    $account = connectionDialogAccount($kind, 'Original scalar values');
    $key = $kind.'-'.$account->id;
    $this->from('/admin/upstreams')->post('/admin/upstreams'.($kind === 'classic' ? '' : '/sub2api').'/configure', [
        'ui_dialog' => 'upstream-connections', 'ui_form' => $key, 'account_id' => $account->id,
        'name' => ['invalid'], 'url' => ['invalid'], 'reason' => ['invalid'], 'api_secret' => 'DIALOG-ARRAY-PRIVATE-KEY',
        ...($kind === 'classic' ? ['username' => ['invalid'], 'raw_jwt' => ['invalid'], 'credit_limit' => ['invalid']] : ['active' => ['invalid']]),
    ])->assertRedirect('/admin/upstreams')->assertSessionHasErrors(['name', 'url', 'reason']);
    $response = $this->get('/admin/upstreams')->assertOk()->assertDontSee('DIALOG-ARRAY-PRIVATE-KEY');
    $xpath = connectionDialogXPath($response->getContent());
    $form = connectionDialogForm($xpath, $key);
    expect(connectionDialogValue($xpath, $form, 'name'))->toBe($account->name)
        ->and(connectionDialogValue($xpath, $form, 'url'))->toBe($account->base_url)
        ->and(connectionDialogValue($xpath, $form, 'reason'))->toBe('')
        ->and(connectionDialogValue($xpath, $form, 'api_secret'))->toBe('');
    if ($kind === 'classic') {
        expect(connectionDialogValue($xpath, $form, 'username'))->toBe($account->username);
    }
})->with(['classic', 'sub2api']);

it('keeps import validation feedback and submitted fields inside the import dialog', function (): void {
    $account = connectionDialogAccount('classic', 'Import source');
    $this->from('/admin/upstreams')->post('/admin/upstreams/import', [
        'ui_dialog' => 'upstream-import', 'ui_form' => 'classic-import',
        'account_id' => $account->id, 'pid' => '77', 'cycle' => 'monthly',
        'currency' => 'CNY', 'markup' => 'invalid-markup', 'reason' => '尚未导入',
    ])->assertRedirect('/admin/upstreams')->assertSessionHasErrors('markup');
    $markupError = session('errors')->first('markup');
    $response = $this->get('/admin/upstreams')->assertOk();
    $xpath = connectionDialogXPath($response->getContent());
    $alerts = $xpath->query('//dialog[@id="upstream-import"]//*[@role="alert"]');
    expect($alerts)->toHaveCount(1)
        ->and($alerts->item(0)->textContent)->toContain($markupError)
        ->and($xpath->query('//dialog[@id="upstream-connections"]//*[@role="alert"]'))->toHaveCount(0);
    $form = connectionDialogForm($xpath, 'classic-import');
    expect(connectionDialogValue($xpath, $form, 'ui_dialog'))->toBe('upstream-import')
        ->and(connectionDialogValue($xpath, $form, 'pid'))->toBe('77')
        ->and(connectionDialogValue($xpath, $form, 'markup'))->toBe('invalid-markup')
        ->and(connectionDialogValue($xpath, $form, 'reason'))->toBe('尚未导入');
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('upstream_apis', 1);
});
