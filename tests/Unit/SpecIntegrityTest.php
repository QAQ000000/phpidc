<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Specification integrity
|--------------------------------------------------------------------------
|
| tests/Specs/FulfillmentSpecsTest.php states behaviour that is not implemented
| yet. Its value is entirely in its exactness, so deleting or weakening a case
| must fail loudly rather than quietly shrink the contract.
|
| Moving a case from Specs to Feature is the only sanctioned way to lower the
| number: implement it, move it, then update EXPECTED_SPEC_CASES here.
|
| This file is named *Test.php so it runs in the default suite — the guard must
| fire even when nobody is looking at the specs.
|
*/

const SPEC_PATH = 'tests/Specs/FulfillmentSpecsTest.php';

const EXPECTED_SPEC_CASES = 12;

it('keeps every specification case in place', function (): void {
    $path = base_path(SPEC_PATH);

    expect(file_exists($path))->toBeTrue(SPEC_PATH.' was deleted.');

    $cases = preg_match_all('/^it\(/m', (string) file_get_contents($path));

    expect($cases)->toBe(
        EXPECTED_SPEC_CASES,
        sprintf(
            'The specification count changed (%d, expected %d). '
            .'Deleting or weakening a spec is forbidden — implement it and move it to '
            .'tests/Feature instead, then update EXPECTED_SPEC_CASES.',
            $cases,
            EXPECTED_SPEC_CASES,
        ),
    );
});

it('does not silently skip specification cases', function (): void {
    $source = (string) file_get_contents(base_path(SPEC_PATH));

    expect($source)->not->toContain('->skip(')
        ->and($source)->not->toContain('->todo(')
        ->and($source)->not->toContain('markTestSkipped');
});

it('keeps specification cases out of the default test run', function (): void {
    $source = (string) file_get_contents(base_path(SPEC_PATH));
    $pestConfig = (string) file_get_contents(base_path('tests/Pest.php'));

    // The group must be applied by the harness, not by each case, so a newly
    // added spec cannot accidentally join the default run and turn it red.
    expect($pestConfig)->toContain('->group(\'spec\')->in(\'Specs\')');
    expect($source)->not->toContain("->group('spec')");
});
