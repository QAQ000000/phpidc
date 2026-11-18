<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AcceptDownloadAction;
use App\Actions\ClaimCouponAction;
use App\Actions\ManageTicketAction;
use App\Actions\RequestAffiliateWithdrawalAction;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\DownloadResource;
use App\Models\Instance;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\AffiliateFunds;
use App\Services\AffiliateReceivableBalance;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CustomerOperationsController extends Controller
{
    public function referral(Request $request, User $user)
    {
        if ($request->user() === null) {
            $request->session()->put('affiliate.referrer_id', $user->id);
        }

        return redirect('/register');
    }

    public function coupons(Request $request)
    {
        $user = $request->user();

        return $this->page('coupons', ['coupons' => Coupon::whereIn('id', DB::table('coupon_claims')->where('user_id', $user->id)->select('coupon_id'))->latest('id')->paginate(20),
            'usages' => CouponUsage::where('user_id', $user->id)->latest('id')->limit(100)->get()]);
    }

    public function claim(Request $request, ClaimCouponAction $action)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $action->execute($request->user(), $data['code']);

        return redirect('/console/coupons')->with('status', '优惠券已领取；使用时仍会复核条件。');
    }

    public function affiliate(Request $request, AffiliateFunds $funds)
    {
        $user = $request->user();
        $pending = $funds->pending($user->id);

        return $this->page('affiliate', ['balance' => $user->affiliate_balance, 'pending' => $pending, 'available' => Money::subFloorZero($user->affiliate_balance, $pending),
            'hasReceivable' => $funds->hasReceivable($user->id), 'requestId' => (string) Str::ulid(),
            'referralUrl' => URL::signedRoute('affiliate.referral', ['user' => $user->id], absolute: false),
            'commissions' => AffiliateCommission::where('referrer_id', $user->id)->latest('id')->paginate(20, ['id', 'invoice_id', 'amount', 'rate_snapshot', 'status', 'available_at'], 'commissions_page'),
            'withdrawals' => AffiliateWithdrawal::where('user_id', $user->id)->where('destination', 'wallet')->latest('id')->paginate(20, ['id', 'amount', 'destination', 'status', 'created_at', 'processed_at'], 'withdrawals_page'),
            'receivables' => app(AffiliateReceivableBalance::class)->forUser($user->id)]);
    }

    public function withdraw(Request $request, RequestAffiliateWithdrawalAction $action)
    {
        $data = $request->validate(['amount' => ['required', 'string'], 'request_id' => ['required', 'ulid']]);
        $action->execute($request->user(), $data['amount'], $data['request_id']);

        return redirect('/console/affiliate')->with('status', '转钱包申请已提交，审核前仅占用可申请额度。');
    }

    public function tickets(Request $request)
    {
        $data = $request->validate(['service_q' => ['nullable', 'string', 'max:100']]);
        $serviceSearch = trim($data['service_q'] ?? '');
        $instances = Instance::where('user_id', $request->user()->id)
            ->when($serviceSearch !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($serviceSearch): void {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $serviceSearch).'%';
                $match->whereRaw("display_name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("primary_ip LIKE ? ESCAPE '!'", [$term]);
                if (ctype_digit($serviceSearch)) {
                    $match->orWhereKey($serviceSearch);
                }
            }))->latest('id')->limit(51)->get(['id', 'display_name']);
        $choices = $instances->take(50);
        $previousId = $request->session()->getOldInput('instance_id');
        if ((is_string($previousId) || is_int($previousId)) && ctype_digit((string) $previousId)
            && ! $choices->contains('id', $previousId)) {
            $previous = Instance::where('user_id', $request->user()->id)->whereKey($previousId)->first(['id', 'display_name']);
            if ($previous !== null) {
                $choices = $choices->take(49)->push($previous);
            }
        }

        return $this->page('tickets', ['tickets' => Ticket::where('user_id', $request->user()->id)->latest('id')->paginate(20)->withQueryString(),
            'instances' => $choices, 'instanceHasMore' => $instances->count() > 50, 'serviceSearch' => $serviceSearch]);
    }

    public function createTicket(Request $request, ManageTicketAction $action)
    {
        $ticket = $action->create($request->user(), $request->only(['subject', 'body', 'instance_id']));

        return redirect('/console/tickets/'.$ticket->id);
    }

    public function ticket(Request $request, Ticket $ticket)
    {
        abort_unless($ticket->user_id === $request->user()->id, 403);

        return $this->page('ticket', ['ticket' => $ticket, 'replies' => TicketReply::where('ticket_id', $ticket->id)->orderBy('id')->paginate(30)]);
    }

    public function updateTicket(Request $request, Ticket $ticket, ManageTicketAction $action)
    {
        $data = $request->validate(['operation' => ['required', 'string'], 'body' => ['nullable', 'string']]);
        $action->update($request->user(), $ticket, $data['operation'], $data['body'] ?? null);

        return redirect('/console/tickets/'.$ticket->id);
    }

    public function downloads()
    {
        return $this->page('downloads', ['resources' => DownloadResource::where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->paginate(20, ['id', 'category', 'title', 'version', 'description', 'file_size', 'sha256', 'access_level', 'download_count'])]);
    }

    public function download(Request $request, DownloadResource $resource, AcceptDownloadAction $action)
    {
        $resource = $action->execute($resource, $request->user());
        if ($resource->storage_type === 'external_url') {
            return redirect()->away($resource->external_url)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }

        return Storage::disk('downloads')->download($resource->file_path, basename($resource->file_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function page(string $section, array $data)
    {
        return response()->view('sales.operations', ['section' => $section] + $data)->header('Cache-Control', 'private, no-store');
    }
}
