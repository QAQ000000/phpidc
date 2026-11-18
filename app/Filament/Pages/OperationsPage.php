<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateRule;
use App\Models\AffiliateWithdrawal;
use App\Models\Coupon;
use App\Models\DownloadResource;
use App\Models\FinancialTransaction;
use App\Models\NotificationTemplate;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\AffiliateFunds;
use App\Services\NotificationManager;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

abstract class OperationsPage extends Page
{
    protected const SECTION = '';

    protected const PERMISSION = '';

    protected string $view = 'filament.pages.operations';

    public static function canAccess(): bool
    {
        return Auth::user()?->can(static::PERMISSION) ?? false;
    }

    protected function getViewData(): array
    {
        $section = static::SECTION;
        $edit = request()->integer('edit');
        $data = match ($section) {
            'coupons' => ['records' => Coupon::latest('id')->paginate(20), 'editing' => $edit ? Coupon::findOrFail($edit) : null],
            'affiliates' => ['records' => AffiliateRule::orderByDesc('priority')->orderBy('id')->paginate(20, ['*'], 'rules_page'),
                'editing' => $edit ? AffiliateRule::findOrFail($edit) : null,
                'productIds' => $edit ? DB::table('affiliate_rule_product')->where('affiliate_rule_id', $edit)->pluck('product_id')->all() : [],
                'commissions' => AffiliateCommission::query()->when(request()->integer('user_id'), fn ($q, $id) => $q->where('referrer_id', $id))
                    ->latest('id')->paginate(20, ['id', 'referrer_id', 'buyer_id', 'invoice_id', 'amount', 'rate_snapshot', 'status', 'available_at'], 'commissions_page')->withQueryString()],
            'payouts' => ['records' => AffiliateWithdrawal::query()->when(request('status'), fn ($q, $status) => $q->where('status', $status))
                ->when(request()->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))->latest('id')
                ->paginate(20, ['id', 'user_id', 'amount', 'destination', 'status', 'processed_by', 'processed_at'])->withQueryString()],
            'support' => ['records' => Ticket::query()->when(request('status'), fn ($q, $status) => $q->where('status', $status))
                ->when(request()->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))->latest('id')->paginate(20)->withQueryString(),
                'ticket' => $edit ? Ticket::findOrFail($edit) : null,
                'replies' => $edit ? TicketReply::where('ticket_id', $edit)->orderBy('id')->paginate(30, ['*'], 'replies_page')->withQueryString() : null],
            'notifications' => ['records' => NotificationTemplate::orderBy('event_key')->paginate(20, ['*'], 'templates_page'),
                'editing' => $edit ? NotificationTemplate::findOrFail($edit) : null,
                'logs' => DB::table('notification_logs')->select(['id', 'user_id', 'event_key', 'channel', 'status', 'error_message', 'created_at'])
                    ->when(request()->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
                    ->when(in_array(request('delivery_status'), ['sent', 'failed', 'simulated', 'skipped', 'disabled'], true), fn ($q) => $q->where('status', request('delivery_status')))
                    ->latest('id')->paginate(20, ['*'], 'logs_page')->withQueryString()],
            'downloads' => ['records' => DownloadResource::orderBy('sort_order')->orderBy('id')->paginate(20, ['id', 'category', 'title', 'version', 'description', 'storage_type', 'file_size', 'sha256', 'access_level', 'download_count', 'is_active', 'sort_order']),
                'editing' => $edit ? DownloadResource::findOrFail($edit) : null],
            default => [],
        };
        if ($section === 'notifications') {
            foreach ($data['logs'] as $log) {
                // Render allowlisted classifications only, never historical raw transport exception text.
                $log->delivery_note = match (true) {
                    $log->status === 'simulated' => '仅模拟，未向外发送',
                    $log->status === 'sent' => '传输已受理，非阅读证明',
                    str_starts_with($log->error_message ?? '', 'Retryable rejection;') => '临时拒绝：最多三次尝试，耗尽后核查失败队列',
                    str_starts_with($log->error_message ?? '', 'Delivery uncertain;') => '结果不确定：核对供应商记录，禁止直接重放',
                    $log->status === 'failed' => '拒绝、配置或传输失败：人工核对',
                    default => '未发送：绑定失效或模板停用',
                };
            }
        }
        if ($section === 'payouts') {
            $funds = app(AffiliateFunds::class);
            $data['payoutUsers'] = User::whereKey($data['records']->pluck('user_id'))->get(['id', 'credit', 'affiliate_balance'])->keyBy('id');
            $data['payoutFunds'] = [];
            foreach ($data['payoutUsers'] as $user) {
                $data['payoutFunds'][$user->id] = ['pending' => $funds->pending($user->id), 'receivable' => $funds->hasReceivable($user->id)];
            }
            $data['receivables'] = FinancialTransaction::whereIn('user_id', $data['records']->pluck('user_id'))->where('type', TransactionType::AFFILIATE_CLAWBACK)->get(['id', 'user_id', 'invoice_id', 'meta']);
        }

        return ['section' => $section, 'events' => NotificationManager::EVENTS, 'variables' => NotificationManager::VARIABLES] + $data;
    }
}
