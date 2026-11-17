<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\InstallApplicationAction;
use App\Services\DeploymentRequirements;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InstallController extends Controller
{
    public function show(DeploymentRequirements $requirements): View
    {
        return view('install.show', ['checks' => $requirements->runtime()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 403, 'HTTPS required for installation.');
        try {
            app(InstallApplicationAction::class)->execute($request->only([
                'install_token', 'admin_name', 'admin_email', 'admin_password', 'admin_password_confirmation', 'reason',
            ]), web: true);
        } catch (DomainException $exception) {
            return back()->withErrors(['install' => $exception->getMessage()]);
        } catch (\PDOException|\RedisException $exception) {
            // Do not log exception SQL/bindings: a migration or account insert may contain private values.
            logger()->error('Installation infrastructure failure', ['exception_type' => $exception::class]);

            return back()->withErrors(['install' => '连接或迁移失败。请核对服务器配置与数据库迁移状态后重试；安装尚未完成。']);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }
}
