<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\HandlePaymentCallbackAction;
use App\Services\DriverManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentCallbackController extends Controller
{
    public function __invoke(Request $request, string $gateway, DriverManager $drivers, HandlePaymentCallbackAction $handle): Response
    {
        abort_unless(file_exists(storage_path('installed.lock')), 503);
        $payment = $drivers->paymentForCallback($gateway, $request)->verifyCallback($request);
        $ack = $handle->execute($payment);

        return response($ack, 200)->header('Content-Type', 'text/plain');
    }
}
