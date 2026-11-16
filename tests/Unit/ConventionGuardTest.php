<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Convention guard
|--------------------------------------------------------------------------
|
| Architecture that only exists in a document is architecture that will be
| violated. These cases scan the source and fail on the specific mistakes that
| break the money, driver and config invariants — the ones that are invisible
| in a green unit test but expensive in production.
|
| See docs/domain-invariants.md and docs/agent-guardrails.md.
|
*/

/**
 * @return array<string, string> relative path => source with comments and strings removed
 */
function appSources(): array
{
    $sources = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $relative = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getRealPath());
        $sources[$relative] = php_strip_whitespace($file->getRealPath());
    }

    ksort($sources);

    return $sources;
}

it('keeps all money arithmetic inside App\Support\Money', function (): void {
    $offenders = [];

    foreach (appSources() as $path => $source) {
        if ($path === 'Support'.DIRECTORY_SEPARATOR.'Money.php') {
            continue;
        }

        if (preg_match('/\b(bcadd|bcsub|bcmul|bcdiv|bcmod|bcpow|bccomp)\s*\(/', $source)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'bcmath must not be called outside App\Support\Money (INV-M1).');
});

it('never formats money as a float', function (): void {
    $offenders = [];

    foreach (appSources() as $path => $source) {
        // number_format() silently turns a fixed-point string into a float and
        // rounds twice. It has no legitimate use for money in this codebase.
        if (preg_match('/\bnumber_format\s*\(/', $source)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'number_format() is forbidden: use App\Support\Money (INV-M3).');
});

it('does not cast money columns to float', function (): void {
    $offenders = [];

    foreach (appSources() as $path => $source) {
        if (preg_match_all(
            "/['\"](credit|frozen_credit|affiliate_balance|total_amount|credit_applied|gateway_amount|discount_amount|recurring_amount|amount|balance_after|upstream_expected_cost)['\"]\s*=>\s*['\"](float|double|real)['\"]/",
            $source,
        )) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Money columns must cast to string, never float (INV-M1).');
});

it('never branches on a driver name string', function (): void {
    $offenders = [];

    foreach (appSources() as $path => $source) {
        if (preg_match(
            '/(===?|!==?)\s*[\'"](zjmf_api|proxmox|sub2api|redemption|kyc_zjmf_legacy)[\'"]/',
            $source,
        ) || preg_match(
            '/[\'"](zjmf_api|proxmox|sub2api|redemption|kyc_zjmf_legacy)[\'"]\s*(===?|!==?)/',
            $source,
        )) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Branch on DriverType enum values or capability interfaces, not raw strings (charter §2).');
});

it('reads environment variables only from config/', function (): void {
    $offenders = [];

    foreach (appSources() as $path => $source) {
        if (preg_match('/\benv\s*\(/', $source)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'env() is only valid in config/. Use SettingService for runtime settings (charter §3.6).');
});

it('leaves no debug helpers in application code', function (): void {
    $offenders = [];

    foreach (appSources() as $path => $source) {
        if (preg_match('/\b(dd|dump|var_dump|print_r|ray|error_log)\s*\(/', $source)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Debug helpers must not be committed.');
});

it('keeps every enum string-backed', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path('Enums'))->name('*.php') as $file) {
        $source = php_strip_whitespace($file->getRealPath());

        if (! str_contains($source, 'enum ') || ! preg_match('/enum\s+\w+\s*:\s*string/', $source)) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([], 'Enums persist to the database and must be string-backed.');
});

it('keeps data transfer objects readonly', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path('Data'))->name('*.php') as $file) {
        $source = php_strip_whitespace($file->getRealPath());

        if (str_contains($source, 'enum ')) {
            continue;
        }

        if (! str_contains($source, 'readonly class')) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([], 'DTOs in App\\Data must be readonly (and never mutated by drivers).');
});

it('keeps every contract an interface and every driver resolved by capability', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path('Contracts'))->name('*.php') as $file) {
        if (! str_contains(php_strip_whitespace($file->getRealPath()), 'interface ')) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([], 'app/Contracts may only contain interfaces.');
});

it('keeps jobs in app/Jobs and actions in app/Actions', function (): void {
    $violations = [];

    if (is_dir(app_path('Actions'))) {
        foreach (Finder::create()->files()->in(app_path('Actions'))->name('*.php') as $file) {
            if (str_ends_with($file->getFilenameWithoutExtension(), 'Job')) {
                $violations[] = 'app/Actions/'.$file->getFilename();
            }
        }
    }

    if (is_dir(app_path('Jobs'))) {
        foreach (Finder::create()->files()->in(app_path('Jobs'))->name('*.php') as $file) {
            if (str_ends_with($file->getFilenameWithoutExtension(), 'Action')) {
                $violations[] = 'app/Jobs/'.$file->getFilename();
            }
        }
    }

    expect($violations)->toBe([], 'A batch process is a Command, a queued unit is a Job, a transaction is an Action.');
});

it('declares strict types in every application file', function (): void {
    $offenders = [];

    foreach (appSources() as $path => $source) {
        if (! str_contains($source, 'declare(strict_types=1)')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Every file under app/ must declare strict_types=1.');
});
