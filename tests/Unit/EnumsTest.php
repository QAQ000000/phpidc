<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome;
use App\Enums\ProvisionCommand;
use App\Enums\TransactionType;

it('mirrors all 18 ZJMF billing cycles', function (): void {
    expect(BillingCycle::cases())->toHaveCount(18)
        ->and(BillingCycle::FOURLY->value)->toBe('fourly')
        ->and(BillingCycle::from('triennially'))->toBe(BillingCycle::TRIENNIALLY);
})->group('phase0');

it('maps each cycle to its upstream price column and excludes non-renewable cycles', function (): void {
    expect(BillingCycle::MONTHLY->priceKey())->toBe('msetupfee')
        ->and(BillingCycle::FOURLY->priceKey())->toBe('foursetupfee')
        ->and(BillingCycle::FREE->priceKey())->toBeNull()
        ->and(BillingCycle::ONETIME->isRenewable())->toBeFalse()
        ->and(BillingCycle::ONTRIAL->isRenewable())->toBeFalse()
        ->and(BillingCycle::MONTHLY->isRenewable())->toBeTrue()
        ->and(BillingCycle::ANNUALLY->months())->toBe(12);
})->group('phase0');

it('exposes all 12 provision commands', function (): void {
    expect(ProvisionCommand::values())->toHaveCount(12)
        ->and(ProvisionCommand::RESCUE_SYSTEM->value)->toBe('rescueSystem')
        ->and(ProvisionCommand::CRACK_PASSWORD->value)->toBe('crack_pass')
        ->and(ProvisionCommand::REINSTALL->isDestructive())->toBeTrue()
        ->and(ProvisionCommand::STATUS->isDestructive())->toBeFalse();
})->group('phase0');

it('constrains instance transitions to the documented lifecycle', function (): void {
    expect(InstanceStatus::ACTIVE->canTransitionTo(InstanceStatus::SUSPENDED))->toBeTrue()
        ->and(InstanceStatus::SUSPENDED->canTransitionTo(InstanceStatus::ACTIVE))->toBeTrue()
        ->and(InstanceStatus::DELETED->canTransitionTo(InstanceStatus::ACTIVE))->toBeFalse()
        ->and(InstanceStatus::PENDING->canTransitionTo(InstanceStatus::SUSPENDED))->toBeFalse()
        ->and(InstanceStatus::CANCELLED->isTerminal())->toBeTrue();
})->group('phase0');

it('splits driver identities into self-hosted and upstream-proxied', function (): void {
    expect(DriverType::ZJMF_API->isUpstreamProxied())->toBeTrue()
        ->and(DriverType::ZJMF_API->isSelfHosted())->toBeFalse()
        ->and(DriverType::REDEMPTION->isSelfHosted())->toBeTrue()
        ->and(DriverType::PROXMOX->isFulfillment())->toBeTrue();
})->group('phase0');

it('keeps the ledger vocabulary complete for freeze, settle and affiliate flows', function (): void {
    expect(TransactionType::CREDIT_SETTLE->isCredit())->toBeFalse()
        ->and(TransactionType::CREDIT_IN->isCredit())->toBeTrue()
        ->and(TransactionType::REFUND_WALLET->isCredit())->toBeTrue()
        ->and(TransactionType::AFFILIATE_COMMISSION->isAffiliateBook())->toBeTrue()
        ->and(TransactionType::AFFILIATE_PAYOUT->isAffiliateBook())->toBeTrue();
})->group('phase0');

it('requires a period key only for billable recurring events', function (): void {
    expect(InvoiceType::RENEW->requiresPeriodKey())->toBeTrue()
        ->and(InvoiceType::UPGRADE->requiresPeriodKey())->toBeTrue()
        ->and(InvoiceType::NEW_ORDER->requiresPeriodKey())->toBeFalse()
        ->and(InvoiceType::NEW_ORDER->createsFulfillmentTask())->toBeTrue()
        ->and(InvoiceType::WALLET_TOPUP->createsFulfillmentTask())->toBeFalse();
})->group('phase0');

it('alerts on every non-happy callback outcome', function (): void {
    expect(PaymentCallbackOutcome::PROCESSED_PAID->requiresAlert())->toBeFalse()
        ->and(PaymentCallbackOutcome::IGNORED_DUPLICATE->requiresAlert())->toBeFalse()
        ->and(PaymentCallbackOutcome::PROCESSED_LATE_CREDIT->requiresAlert())->toBeTrue()
        ->and(PaymentCallbackOutcome::REJECTED_SHORT_AMOUNT->requiresAlert())->toBeTrue()
        ->and(PaymentCallbackOutcome::UNKNOWN_INVOICE->requiresAlert())->toBeTrue()
        ->and(PaymentCallbackOutcome::REJECTED_SHORT_AMOUNT->isAccepted())->toBeFalse();
})->group('phase0');

it('treats stale tasks as terminal so hot swaps cannot double-execute', function (): void {
    expect(FulfillmentTaskStatus::STALE->isTerminal())->toBeTrue()
        ->and(FulfillmentTaskStatus::RUNNING->isOpen())->toBeTrue()
        ->and(FulfillmentTaskStatus::SUCCESS->isTerminal())->toBeTrue();
})->group('phase0');

it('marks only unsettled invoices as eligible for an unfreeze', function (): void {
    expect(InvoiceStatus::UNPAID->canReleaseFrozenCredit())->toBeTrue()
        ->and(InvoiceStatus::PAID->canReleaseFrozenCredit())->toBeFalse()
        ->and(InvoiceStatus::EXPIRED->canReleaseFrozenCredit())->toBeFalse()
        ->and(InvoiceStatus::PAID->isSettled())->toBeTrue();
})->group('phase0');
