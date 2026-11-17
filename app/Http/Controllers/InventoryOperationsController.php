<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ImportRedemptionCodesAction;
use App\Actions\RetryFulfillmentTaskAction;
use App\Models\FulfillmentTask;
use App\Models\Product;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InventoryOperationsController extends Controller
{
    public function import(Request $request, ImportRedemptionCodesAction $import): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('inventory.view');
        Gate::forUser($actor)->authorize('inventory.import');
        $data = $request->validate(['product_id' => ['required', 'integer'], 'codes' => ['required', 'string', 'max:500000'], 'reason' => ['required', 'string', 'max:1000']]);
        $codes = array_values(array_filter(preg_split('/\r\n|\r|\n/', $data['codes']), fn (string $code) => trim($code) !== ''));
        try {
            $count = $import->execute(Product::findOrFail($data['product_id']), $codes, $data['reason'], $actor);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return redirect('/admin/inventory/cards?product_id='.$data['product_id'])->with('inventory_status', '新增 '.$count.' 张，重复 '.(count($codes) - $count).' 张。');
    }

    public function retry(Request $request, FulfillmentTask $task, RetryFulfillmentTaskAction $retry): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('inventory.view');
        Gate::forUser($actor)->authorize('inventory.retry');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'confirm' => ['accepted']]);
        try {
            $retry->execute($task, $data['reason'], $actor);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }

        return redirect('/admin/fulfillment')->with('inventory_status', '已确认重试任务 #'.$task->id.'，等待队列处理。');
    }
}
