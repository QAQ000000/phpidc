<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ValidateCheckoutAction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function store(Request $request, ValidateCheckoutAction $validateCheckout): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $input = $request->validate(['product_id' => ['required', 'integer']]);
        $validateCheckout->execute($user, (int) $input['product_id'], $request->only(['hostname', 'password']));

        // This case implements the validation gate; settlement follows separately.
        return response()->json(['message' => 'Checkout settlement is not implemented yet.'], 501);
    }
}
