<x-admin.card id="instance-operations" title="实例操作">
    <div class="admin-ui-card-body">
        @if($classic)
            <div class="admin-instance-actions">
                @if($canMachine)<form method="post" action="{{ $workspaceAction('/admin/upstream-instances/'.$instance->id.'/machine') }}">@csrf<input type="hidden" name="_admin_form" value="instance-machine-read"><input type="hidden" name="instance_id" value="{{ $instance->id }}"><button class="admin-ui-button admin-ui-button-primary">读取机器管理菜单</button></form>@endif
                @if($canSync)<button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="instance-sync" x-on:click="document.getElementById('instance-sync').showModal()">同步上游事实</button>@endif
                @if($canLifecycle)<button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="instance-lifecycle" x-on:click="document.getElementById('instance-lifecycle').showModal()">暂停、恢复或终止</button>@endif
                @if($canHotSwap)<button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="instance-hot-swap" x-on:click="document.getElementById('instance-hot-swap').showModal()">显式换绑</button>@endif
                @if($canMapping)<a class="admin-ui-button" href="/admin/upstream-instances/{{ $instance->id }}/mapping-review">配置映射核准</a>@endif
                <a class="admin-ui-button" href="/admin/machine-operation-review?instance_id={{ $instance->id }}">机器命令核对与历史</a>
            </div>
            @if(!$canMachine && !$canSync)<p class="admin-ui-meta">当前绑定、服务状态或权限不支持机器控制与同步。</p>@endif
        @else
            <p>{{ $instance->driver_type === \App\Enums\DriverType::PROXMOX ? '此驱动尚未交付后台远端控制能力。' : '此类型未开放后台远端机器控制与上游同步。' }}</p>
        @endif
        @if($permissions['tasks'])<p><a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('instance', $instance->id, ['tab' => 'tasks'] + $workspaceQuery) }}">查看履约任务</a></p>@endif
    </div>
</x-admin.card>
