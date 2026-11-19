<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConfigureSub2ApiAction;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Sub2ApiSettingsController extends Controller
{
    public function store(Request $request, ConfigureSub2ApiAction $configure): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('upstream.view');
        Gate::forUser($actor)->authorize('upstream.configure');
        $data = $request->validate(['account_id' => ['nullable', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:255'], 'api_secret' => [Rule::requiredIf(! $request->filled('account_id')), 'nullable', 'string', 'max:4096'],
            'active' => ['sometimes', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);
        $reason = trim($data['reason'] ?? '');
        if ($reason === '') {
            $reason = $request->filled('account_id') ? '编辑 Sub2API 连接配置' : '新增 Sub2API 连接';
        }
        try {
            $configure->execute($data['name'], $data['url'], $data['api_secret'] ?? '', $reason, $actor,
                isset($data['account_id']) ? (int) $data['account_id'] : null, isset($data['active']) ? $request->boolean('active') : null);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['operation' => '已有同名 Sub2API 连接，请使用其他名称。']);
        } catch (DomainException) {
            throw ValidationException::withMessages(['operation' => '连接未保存，请检查名称、地址和密钥。已有连接不能更换站点地址。']);
        }

        return redirect('/admin/upstreams')->with('inventory_status', 'Sub2API 连接已保存；密钥不会回显，历史发码意图保持原状。');
    }
}
