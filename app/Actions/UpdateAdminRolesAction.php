<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Support\AdminRoles;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateAdminRolesAction
{
    public function execute(User $target, array $roles, array $expectedRoles, User $actor, ?string $reason = null): void
    {
        $allowed = AdminRoles::webAssignable();
        validator(compact('roles', 'expectedRoles', 'reason'), [
            'roles' => ['array', 'max:'.count($allowed)], 'roles.*' => ['required', 'string', 'distinct', Rule::in($allowed)],
            'expectedRoles' => ['array', 'max:'.count($allowed)], 'expectedRoles.*' => ['required', 'string', 'distinct', Rule::in($allowed)],
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validate();
        if ($actor->id === $target->id) {
            throw new DomainException('不能通过网页变更本人角色。');
        }
        sort($roles);
        sort($expectedRoles);
        $note = trim($reason ?? '');
        DB::transaction(function () use ($target, $roles, $expectedRoles, $actor, $note, $allowed): void {
            $users = User::whereKey([$target->id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $operator = $users->get($actor->id);
            $locked = $users->get($target->id);
            if ($operator === null || $locked === null) {
                throw new DomainException('操作人或目标账户已停用。');
            }
            Gate::forUser($operator)->authorize('access.view');
            Gate::forUser($operator)->authorize('access.manage');
            $current = $locked->getRoleNames()->intersect($allowed)->sort()->values()->all();
            if ($current !== $expectedRoles) {
                throw ValidationException::withMessages(['roles' => '此账户的角色已变化，请刷新后重新选择。']);
            }
            $assign = app(AssignAdminRoleAction::class);
            foreach (array_diff($current, $roles) as $role) {
                $assign->execute($locked, $role, $note !== '' ? $note : '撤销后台角色：'.AdminRoles::labels()[$role], true, $operator);
            }
            foreach (array_diff($roles, $current) as $role) {
                $assign->execute($locked, $role, $note !== '' ? $note : '授予后台角色：'.AdminRoles::labels()[$role], false, $operator);
            }
        });
        $target->unsetRelation('roles')->unsetRelation('permissions');
    }
}
