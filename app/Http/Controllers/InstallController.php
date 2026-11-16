<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Web installer (charter §3.6). Phase 0 responsibility is narrow: gate access
 * on `installed.lock`, validate the bootstrap form, write the lock file.
 * Schema provisioning and admin creation belong to Phase 1.
 */
class InstallController extends Controller
{
    public function show(): View
    {
        return view('install.show');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_url' => ['required', 'url'],
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:64'],
            'db_password' => ['nullable', 'string', 'max:255'],
        ]);

        // Persisting the bootstrap env and running migrations is Phase 1 work.
        // Until then the installer records intent and refuses to claim success.
        report(new \RuntimeException('Installer store() reached before Phase 1 implementation.'));

        return back()->withErrors([
            'install' => '安装流程尚未实现（Phase 1）。',
        ]);
    }
}
