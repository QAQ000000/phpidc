# Agent Guardrails — Rules for Coding Agents

> **Read this before your first edit.** This project will be implemented by multiple AI coding agents in sequence. This file exists because the most expensive failure mode is not a wrong answer — it is an agent quietly weakening the constraints that make wrong answers detectable.
>
> Precedence: `CLAUDE.md` > `docs/domain-invariants.md` > this file > the driver specs.

---

## 1. The five things you must never do

### 1.1 Never make a test pass by weakening it

The suite is the specification. If a test fails, one of two things is true: **the code is wrong**, or **the test encodes a rule you were about to break**. In both cases the test wins.

Forbidden, without exception:

| Forbidden | Why |
|---|---|
| Deleting a test case | Removes the only proof the rule holds |
| Changing an expected value to match actual output | Enshrines the bug as the contract |
| Adding `->skip()`, `->todo()`, `markTestSkipped()` | Makes red look like green |
| Weakening a matcher (`toBe` → `toBeTruthy`, exact → partial) | Silently accepts wrong values |
| Editing `tests/Specs/FulfillmentSpecsTest.php` | `SpecIntegrityTest` asserts its exact case count |
| Adding `@phpstan-ignore`, `@phpstan-ignore-next-line`, or a baseline entry | Hides a real type error |
| Adding `ignoreErrors` to `phpstan.neon` for a pattern that matches a real error | Same, one level up |
| Wrapping a call in `try/catch` that swallows the failure | Turns a crash into corrupt data |

If a test genuinely encodes a wrong rule, **stop and say so**. Do not "fix" it. A human decides.

**Approved correction record (2026-11-17):** the human approved C01–C09 in `docs/contract-clarifications.md`. That specific revision permits the listed specification setup/call/type corrections and Money input-test correction while preserving all 17 case names, strict assertions and business guarantees. It is not an implementation or a count reduction, and is not continuing permission to edit other specifications. After that revision, the corrected cases are again moved unchanged when implemented.

### 1.2 Never bypass a failing guard by disabling the guard

`tests/Unit/ConventionGuardTest.php` scans the source for the specific mistakes that break the money, driver and config invariants. If it fails, fix the source. Do not:
- rename a variable to dodge a regex
- split a call across lines to dodge a pattern
- move the offending code into a file the guard does not scan
- delete the guard case

That is not passing the check; it is defeating it. The guard exists because these mistakes are invisible in a green unit test and expensive in production.

### 1.3 Never invent a schema or contract change

The migration, the enums, the DTOs and the contracts are the agreed interface. Changing them requires a human decision, because six other files and four documents depend on them.

- Need a new column? Say so. Do not add it silently.
- Need a new `func`, status, or transaction type? Add it to the enum **and** state why in your report.
- Need a different DTO field? Propose it. Do not add an optional field and leave the old one dead.

### 1.4 Never write money logic outside `App\Support\Money`

`ConventionGuardTest` fails on any `bc*()` call or `number_format()` outside that class, and on any money column cast to `float`. This is deliberate.

- No `float`. Not in a cast, not in a calculation, not in a comparison.
- No sign for direction. Direction is `TransactionType`.
- Round exactly once, at price production (INV-M3).

### 1.5 Never branch on a driver name

`if ($instance->driver_type === 'zjmf_api')` is forbidden anywhere outside `DriverManager`. Use `DriverType` enum comparisons or, preferably, capability interfaces:

```php
// Correct
if ($driver instanceof RenewableResource) { ... }
if ($driver instanceof LiveQuotable) { ... }

// Forbidden
if ($instance->driver_type === 'zjmf_api') { ... }
```

---

## 2. How to find out what to build

In order, and do not skip step 2:

1. `docs/domain-invariants.md` — the normative rules, with stable `INV-*` IDs.
2. `tests/Specs/FulfillmentSpecsTest.php` — the behaviour that must exist, written as executable assertions. This is the closest thing to a definition of done.
3. `CLAUDE.md` §2 — where the file belongs and what it may depend on.
4. `docs/zjmf-api-spec.md` / `docs/external-drivers-spec.md` — wire protocol only.

If the spec and the invariants disagree, the invariant wins and the spec is wrong — report it, do not code to the spec.

---

## 3. The work loop

```bash
./vendor/bin/pest                 # must be green before you start
./vendor/bin/phpstan analyse      # must be 0 errors before you start
./vendor/bin/pint --test          # must be clean before you start
```

Then:

1. Pick one spec case from `tests/Specs/`.
2. Implement it: Action + driver + model support as the case requires.
3. Make it pass. `./vendor/bin/pest --filter='the case name'`.
4. Move the case from `tests/Specs/FulfillmentSpecsTest.php` to `tests/Feature/` unchanged.
5. Decrement `EXPECTED_SPEC_CASES` in `tests/Unit/SpecIntegrityTest.php` by exactly one.
6. Run all three commands. All green.
7. Report what you did, what you did not do, and anything you had to decide.

**One spec case per commit.** A commit that implements three cases cannot be reviewed, and the count check cannot tell which one was real.

---

## 4. Red flags that mean stop

Stop and report, do not proceed, if:

- A test fails and you cannot explain *why* it fails.
- Making a test pass requires changing a rule in `docs/domain-invariants.md`.
- You need a schema change the migration does not have.
- You are tempted to write `try { ... } catch (\Throwable $e) { }`.
- You are about to touch `tests/Specs/` other than moving a case out.
- You are about to run `php artisan migrate:fresh` on a database with data.
- The task requires an upstream API call whose response shape is unverified (Sub2API remote mode, legacy KYC callback). Those are marked "Verification Note" in the driver spec for a reason.

---

## 5. Concurrency rules that are easy to get wrong

These are the mistakes that produce green tests and corrupt money in production.

**Lock before you read a balance.** Reading `users.credit` and then writing it is a lost-update bug. Use `lockForUpdate()` or a conditional `UPDATE ... WHERE credit >= ?` and assert `affected === 1` (INV-W3).

**Respect the lock order.** `users → invoices → payment_attempts → instances → redemption_codes → fulfillment_tasks` (INV-L1). Reverse order deadlocks.

**Never hold a transaction across an HTTP call.** Lock, validate, commit a `pending` task, then do I/O in the job (INV-L3).

**Compare against `gateway_amount`, not `total_amount`.** The wallet portion was already settled (INV-P1). Comparing against the total double-charges it.

**Every balance movement needs a unique `reference_key`.** Without it, a retry writes a second ledger row (INV-W2).

**Every billable event needs an idempotency key.** Renewal invoices carry `billing_period_key`; fulfillment tasks carry `idempotency_key` (INV-K1, INV-K2). A scheduler without `withoutOverlapping()` + `onOneServer()` is a defect (INV-K3).

---

## 6. Reporting template

End every implementation report with:

```
IMPLEMENTED:   <spec case names you moved>
NOT DONE:      <what remains, and why>
DECISIONS:     <anything you had to choose, with the rule you applied>
CONCERNS:      <anything that looks wrong in the docs, or unverified upstream>
GATES:         pest <n> passed | phpstan <n> errors | pint <clean|fail>
SPEC CASES:    <remaining count>
```

`DECISIONS` and `CONCERNS` are the important ones. An empty `CONCERNS` on a task that touched money or upstream calls is usually a lie.
