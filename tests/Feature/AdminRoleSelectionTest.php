<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\UpdateAdminRolesAction;
use App\Models\User;
use App\Support\AdminRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function roleSelectionOperator(string ...$roles): User
{
    $user = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($user, $role, 'Isolated role selection test');
    }

    return $user;
}

it('shows named role choices and presets with the targets current roles already selected', function (): void {
    $manager = roleSelectionOperator('access-manager');
    $target = roleSelectionOperator('inventory-operator');
    $response = $this->actingAs($manager)->get('/admin/access?user_id='.$target->id)->assertOk()
        ->assertSee('日常运营')->assertSee('财务只读')->assertSee('全部业务权限（含退款和出款）')
        ->assertSee('备注（可选）')->assertSee('保存角色')->assertDontSee('操作原因');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//input[@name="roles[]"]')->length)->toBe(count(AdminRoles::webAssignable()))
        ->and($xpath->query('//input[@name="roles[]" and @checked]/@value')->item(0)->nodeValue)->toBe('inventory-operator');
    foreach (AdminRoles::presets() as $preset) {
        expect(array_diff($preset['roles'], AdminRoles::webAssignable()))->toBe([]);
    }
    expect(array_diff(array_keys(AdminRoles::permissions()), array_keys(AdminRoles::labels())))->toBe([]);
});

it('saves multiple role changes atomically with per-change audit and preserves protected roles and direct permissions', function (): void {
    $manager = roleSelectionOperator('access-manager');
    $target = roleSelectionOperator('access-manager', 'inventory-viewer', 'support-viewer');
    $target->givePermissionTo('support.view');
    $target->removeRole('support-viewer');
    $target->assignRole(Role::findOrCreate('legacy-private-role', 'web'));
    $body = ['roles' => ['finance-operator', 'cms-editor'], 'expected_roles' => ['inventory-viewer'], 'confirm' => 1];
    $this->actingAs($manager)->post('/admin/access/'.$target->id.'/roles', $body)
        ->assertRedirect('/admin/access?user_id='.$target->id);
    expect($target->fresh()->getRoleNames()->sort()->values()->all())->toBe(['access-manager', 'cms-editor', 'finance-operator', 'legacy-private-role'])
        ->and($target->fresh()->can('access.manage'))->toBeTrue()->and($target->fresh()->can('support.view'))->toBeTrue()
        ->and($target->fresh()->can('finance.refund'))->toBeTrue()->and($target->fresh()->can('inventory.view'))->toBeFalse();
    $audits = DB::table('audit_logs')->where('actor_id', $manager->id)->orderBy('id')->get();
    expect($audits)->toHaveCount(3)->and($audits->pluck('action')->all())->toBe(['admin.role.revoke', 'admin.role.grant', 'admin.role.grant'])
        ->and($audits->pluck('reason')->all())->toBe(['撤销后台角色：库存查看', '授予后台角色：内容编辑', '授予后台角色：全额退款']);
    foreach ($audits as $audit) {
        expect($audit->subject_id)->toBe($target->id)->and($audit->actor_type)->toBe('user')
            ->and($audit->ip)->toBe('127.0.0.1')->and($audit->request_id)->toBeString()->not->toBe('');
    }
    $this->post('/admin/access/'.$target->id.'/roles', ['roles' => $body['roles'], 'expected_roles' => $body['roles'], 'confirm' => 1])->assertRedirect();
    expect(DB::table('audit_logs')->where('actor_id', $manager->id)->count())->toBe(3);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
});

it('can clear business roles while retaining the server-managed role and direct permissions', function (): void {
    $manager = roleSelectionOperator('access-manager');
    $target = roleSelectionOperator('access-manager', 'finance-viewer');
    $target->givePermissionTo('finance.view');
    $this->actingAs($manager)->post('/admin/access/'.$target->id.'/roles', ['expected_roles' => ['finance-viewer'], 'confirm' => 1])->assertRedirect();
    expect($target->fresh()->getRoleNames()->all())->toBe(['access-manager'])
        ->and($target->fresh()->can('access.manage'))->toBeTrue()->and($target->fresh()->can('finance.view'))->toBeTrue();
    expect(DB::table('audit_logs')->where('actor_id', $manager->id)->sole()->action)->toBe('admin.role.revoke');
});

it('rejects a stale selection rather than overwriting another administrators changes', function (): void {
    $manager = roleSelectionOperator('access-manager');
    $target = roleSelectionOperator('inventory-viewer');
    app(AssignAdminRoleAction::class)->execute($target, 'cms-editor', 'Independent administrator update');
    $this->actingAs($manager)->postJson('/admin/access/'.$target->id.'/roles', [
        'roles' => ['finance-operator'], 'expected_roles' => ['inventory-viewer'], 'confirm' => 1,
    ])->assertUnprocessable()->assertJsonValidationErrors('roles');
    expect($target->fresh()->getRoleNames()->sort()->values()->all())->toBe(['cms-editor', 'inventory-viewer'])
        ->and($target->fresh()->can('finance.refund'))->toBeFalse()
        ->and(DB::table('audit_logs')->where('actor_id', $manager->id)->count())->toBe(0);
});

it('rejects unknown protected or duplicate roles without partially changing the target', function (array $roles): void {
    $manager = roleSelectionOperator('access-manager');
    $target = roleSelectionOperator('inventory-viewer');
    $this->actingAs($manager)->postJson('/admin/access/'.$target->id.'/roles', [
        'roles' => $roles, 'expected_roles' => ['inventory-viewer'], 'confirm' => 1,
    ])->assertUnprocessable();
    expect($target->fresh()->getRoleNames()->all())->toBe(['inventory-viewer'])
        ->and(DB::table('audit_logs')->where('actor_id', $manager->id)->count())->toBe(0);
})->with([
    'unknown' => [['cms-editor', 'super_admin']],
    'server-managed' => [['cms-editor', 'access-manager']],
    'duplicate' => [['cms-editor', 'cms-editor']],
]);

it('does not allow web self-assignment through the selection endpoint', function (): void {
    $manager = roleSelectionOperator('access-manager');
    $this->actingAs($manager)->postJson('/admin/access/'.$manager->id.'/roles', ['roles' => ['finance-operator'], 'confirm' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('roles');
    expect($manager->fresh()->getRoleNames()->all())->toBe(['access-manager'])->and($manager->fresh()->can('finance.refund'))->toBeFalse();
});

it('requires current manage authority and explicit form submission for bulk authorization', function (): void {
    $target = User::factory()->create();
    $viewer = roleSelectionOperator('access-manager');
    $viewer->givePermissionTo('access.view');
    $viewer->removeRole('access-manager');
    $this->actingAs($viewer)->get('/admin/access')->assertOk()->assertDontSee('保存角色');
    $this->postJson('/admin/access/'.$target->id.'/roles', ['roles' => ['finance-operator'], 'confirm' => 1])->assertForbidden();
    $manager = roleSelectionOperator('access-manager');
    $this->actingAs($manager)->postJson('/admin/access/'.$target->id.'/roles', ['roles' => ['finance-operator']])
        ->assertUnprocessable()->assertJsonValidationErrors('confirm');
    expect($target->fresh()->getRoleNames()->all())->toBe([]);
});

it('rechecks the actual actor inside the transaction even if the caller cached an old permission', function (): void {
    $manager = roleSelectionOperator('access-manager');
    $target = User::factory()->create();
    expect($manager->can('access.manage'))->toBeTrue();
    app(AssignAdminRoleAction::class)->execute(User::findOrFail($manager->id), 'access-manager', 'Revoke stale operator', true);
    expect(fn () => app(UpdateAdminRolesAction::class)->execute($target, ['finance-operator'], [], $manager))
        ->toThrow(AuthorizationException::class);
    expect($target->fresh()->getRoleNames()->all())->toBe([]);
});

it('rolls back every role change when the audit for a later grant fails', function (): void {
    $manager = roleSelectionOperator('access-manager');
    $target = roleSelectionOperator('inventory-viewer');
    DB::unprepared("CREATE TRIGGER reject_later_role BEFORE INSERT ON audit_logs WHEN NEW.actor_id IS NOT NULL AND NEW.action = 'admin.role.grant' AND NEW.after LIKE '%finance-operator%' BEGIN SELECT RAISE(ABORT, 'Later role audit unavailable'); END");
    expect(fn () => app(UpdateAdminRolesAction::class)->execute($target, ['cms-editor', 'finance-operator'], ['inventory-viewer'], $manager))
        ->toThrow(QueryException::class, 'Later role audit unavailable');
    expect($target->fresh()->getRoleNames()->all())->toBe(['inventory-viewer'])
        ->and($target->fresh()->can('finance.refund'))->toBeFalse()->and($target->fresh()->can('cms.edit'))->toBeFalse()
        ->and(DB::table('audit_logs')->where('actor_id', $manager->id)->count())->toBe(0);
});

it('keeps the single role endpoint compatible and retains explicit notes including zero', function (?string $note): void {
    $manager = roleSelectionOperator('access-manager');
    $target = User::factory()->create();
    $body = ['role' => 'cms-editor', 'operation' => 'grant', 'confirm' => 1];
    if ($note !== null) {
        $body['reason'] = $note;
    }
    $this->actingAs($manager)->post('/admin/access/'.$target->id.'/role', $body)->assertRedirect();
    expect($target->fresh()->can('cms.edit'))->toBeTrue()
        ->and(DB::table('audit_logs')->where('actor_id', $manager->id)->sole()->reason)
        ->toBe($note === null || trim($note) === '' ? '授予后台角色：内容编辑' : trim($note));
})->with([null, '   ', '0', 'Specified operator note']);
