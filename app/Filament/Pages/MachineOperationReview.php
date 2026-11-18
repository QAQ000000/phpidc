<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Instance;
use App\Support\MachineOperation;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class MachineOperationReview extends Page
{
    protected static ?string $slug = 'machine-operation-review';

    protected static ?string $title = '机器命令人工核对';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.machine-operation-review';

    #[Locked]
    public int $instanceId;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('upstream.view') ?? false;
    }

    public function mount(): void
    {
        $this->instanceId = (int) request('instance_id');
    }

    protected function getViewData(): array
    {
        $instance = Instance::findOrFail($this->instanceId);
        $history = DB::table('audit_logs')->where('subject_type', Instance::class)->where('subject_id', $instance->id)
            ->where('action', 'like', 'instance.machine.%')->latest('id')->limit(30)->get(['id', 'actor_id', 'action', 'reason', 'before', 'after', 'created_at']);

        return ['instance' => $instance, 'operation' => app(MachineOperation::class)->summary($instance),
            'history' => $history->map(fn ($row) => ['id' => $row->id, 'actor_id' => $row->actor_id, 'action' => $row->action,
                'reason' => $row->reason, 'at' => $row->created_at, 'before' => json_decode($row->before ?? '{}', true, flags: JSON_THROW_ON_ERROR),
                'after' => json_decode($row->after ?? '{}', true, flags: JSON_THROW_ON_ERROR)])];
    }
}
