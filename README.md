# History of Manual Adjustments to an Earning Line

A PHP proof of concept for the Alcor payroll assignment: an Earning Line accepts automatic updates until its first Manual Adjustment. Every adjustment is retained, and that first adjustment permanently freezes the System Amount.

**PHP 8.4+ · MoneyPHP · Carbon · Ramsey UUID · SQLite · PHPUnit**

## Run

Prerequisites: Docker with Docker Compose, and Make. No local PHP or database server is required.

```sh
make setup
make test
make demo
```

`make setup` builds the PHP 8.4 image and installs the locked Composer dependencies. `make check` runs Composer validation, PHP syntax checks, and all tests. `make shell` opens a container shell.

The demo writes to `var/payroll.sqlite` in the repository. Each run creates a new Earning Line; previous runs remain available. Copy the printed Earning Line Id to inspect its history in a separate process:

```sh
docker compose run --rm php php bin/demo.php --history <earning-line-id>
```

Tests use disposable SQLite databases and do not modify the demo database.

### Without Docker

Use PHP 8.4+ and Composer, with `bcmath`, `pdo_sqlite`, and the extensions required by PHPUnit (including `dom`, `mbstring`, `xml`, and `xmlwriter`).

```sh
composer install
composer test
composer demo
php bin/demo.php --history <earning-line-id>
```

Set `PAYROLL_DATABASE` to use another database file. For example:

```sh
PAYROLL_DATABASE=/tmp/alcor-demo.sqlite php bin/demo.php
```

For Docker, pass it explicitly: `docker compose run --rm -e PAYROLL_DATABASE=/app/var/other.sqlite php php bin/demo.php`.

## Expected Scenario

| Step | Event | Current Amount |
| --- | --- | --- |
| 1 | System calculation | USD 1000.00 |
| 2 | System update | USD 1050.00 |
| 3 | Manual Adjustment: −45.55 | USD 1004.45 |
| 4 | Attempted system update, ignored | USD 1004.45 |
| 5 | Manual Adjustment: +100.10 | USD 1104.55 |
| 6 | Manual Adjustment: −0.10 | USD 1104.45 |
| 7 | Manual Adjustment: −0.20 | USD 1104.25 |
| 8 | Compensating Adjustment: +0.20 | **USD 1104.45** |

The final Adjustment History contains the frozen System Amount of **USD 1050.00**, all five adjustments, and the Current Amount. Each adjustment includes its UUID, signed amount, original comment, author UUID, and UTC timestamp with microseconds.

The example's comments and signs are reproduced as supplied. The model applies signed deltas; it does not interpret whether a particular benefit or deduction should increase or decrease pay.

## Domain Model

The [Ubiquitous Language](docs/ubiquitous-language.md) defines the agreed names. Domain terms use Title Case in documentation; corresponding code types use PascalCase. PHP methods and properties use conventional camelCase.

`EarningLine` is the aggregate: the boundary that owns and enforces the business rules. A `ManualAdjustment` is an immutable record belonging to that line.

```text
Current Amount = System Amount + sum of all Adjustment Amounts
```

Before the first Manual Adjustment, `UpdateSystemAmount` replaces the System Amount with a completed external calculation. The aggregate does not implement a salary calculation engine.

After the first Manual Adjustment, all automatic updates are ignored. This rule depends on the existence of adjustments, never their sum. Fully compensating an earlier adjustment therefore cannot restore automatic updates.

There are no edit or delete operations for adjustments. A correction to a mistake is another ordinary `AddManualAdjustment` command.

### Commands, Events, and Query

| Command | Event |
| --- | --- |
| `CreateEarningLine` | `EarningLineCreated` |
| `UpdateSystemAmount` | `SystemAmountUpdated` |
| `AddManualAdjustment` | `ManualAdjustmentAdded` |

An ignored automatic update produces no event. Updating an unlocked line to the same System Amount also produces no event.

`GetAdjustmentHistory` returns a readonly `AdjustmentHistory` containing the System Amount, all adjustments in recorded order, and Current Amount. The full Event Stream additionally contains accepted system updates preceding the first Manual Adjustment.

## Architecture

| Location | Responsibility |
| --- | --- |
| `src/Earning/Domain` | Entities/aggregate, Value Objects, events, and repository interfaces |
| `src/Earning/Application` | Commands/handlers, queries/handlers/results, ports, and application exceptions |
| `src/Earning/Infrastructure/Mapper` | Explicit domain-event/JSON mapping |
| `src/Earning/Infrastructure/Persist/Write` | Event-sourced repository implementation, SQLite Event Store, and schema |
| `src/Earning/UI/Cli` | CLI scenario, input handling, and history output |
| `src/Shared` | Business-independent Clock contract and Carbon implementation |
| `bin/demo.php` | Configuration and composition root with explicit constructor injection |
| `tests/Unit`, `tests/Integration`, `tests/Acceptance` | Tests organized by category, then module and layer |

See [Module and Layer Structure](docs/technical-decisions.md#module-and-layer-structure) for the full convention and dependency rules. Optional folders are added only when needed. `Persist/Read` will hold a dedicated read adapter if one is introduced; this PoC reads history through the aggregate repository.

The repository loads the line's events and reconstitutes its state. Commands ask the aggregate to make a change, then append its pending events. Pending events are cleared only after a successful save. Reconstitution applies historical facts without generating new events.

CQRS separates command and query responsibilities. Queries synchronously reconstitute the aggregate and return a read DTO; there is no separate read database or asynchronous projection. This keeps reads current and avoids maintaining the amount calculation in two places.

### Why Event Sourcing?

The business requirement is an immutable sequence of corrections. A small Event Sourcing implementation makes those facts the source of truth and lets the same history reproduce the current value. It also makes the permanent freeze straightforward to restore.

A conventional object model with an append-only adjustment table would satisfy the assignment too. Event Sourcing was chosen to demonstrate the approach used by Alcor, while limiting the implementation to one aggregate and three event types. No event bus, generic aggregate framework, snapshots, or background workers are needed for this scope.

### Persistence and Concurrent Writes

Events use explicit, versioned names such as `manual-adjustment-added.v1` and JSON payloads. Amounts are serialized as integer minor-unit **strings**, preserving values beyond PHP's native integer range. PHP object serialization and floating-point arithmetic are not used.

The SQLite table uses `(stream_id, version)` as its primary key. Each append:

1. Acquires a write lock with `BEGIN IMMEDIATE`.
2. Checks that the saved version matches the version the caller loaded.
3. Appends the entire batch in order, then commits.
4. Rolls back the batch on any failure.

A stale writer receives `ConcurrentStreamWrite`. The caller must reload and reconsider the command; the repository does not silently retry it. If a Manual Adjustment wins a race against an automatic update, the retried automatic update sees the frozen line and is ignored. If the automatic update wins, a retried Manual Adjustment applies to the newly accepted System Amount.

SQLite triggers reject UPDATE, DELETE, and replacement of existing events. The explicit replacement guard matters because SQLite's [REPLACE behavior](https://www.sqlite.org/lang_conflict.html) can delete existing rows without firing DELETE triggers when recursive triggers are disabled. This guard does not depend on connection-specific settings.

## Assumptions and Limits

- Each line has one currency. All subsequent amounts must use that currency; the demo uses USD. Frozen automatic updates are ignored altogether, including their proposed currency.
- Amounts are provided in integer minor units: `Money::USD('10010')` means USD 100.10. Rounding source calculations and currency conversion are outside the exercise.
- A Manual Adjustment must be nonzero and have a nonblank UTF-8 comment. Valid comments are preserved verbatim. Negative System Amounts and Current Amounts are allowed because the assignment specifies no lower bound.
- Callers supply line, adjustment, and author UUIDs. Author identity is assumed to come from an authenticated caller; this PoC does not implement authentication or authorization.
- Reusing a Manual Adjustment Id on the same line is rejected, even if the payload matches. This prevents double application but is not a transparent idempotent-success protocol. IDs are scoped to an Earning Line for this check.
- Timestamps come from an injected Clock and describe recording time, not an effective payroll date. Stream version defines order, including when timestamps are equal or a system clock moves backward.
- Unknown lines, invalid adjustments, and write conflicts raise exceptions. CLI errors are printed to stderr with a nonzero exit code. SQLite lock contention waits up to five seconds before surfacing the database error.
- SQLite schema initialization is automatic for this PoC. Schema migrations and event upcasting would be needed when evolving a deployed system; unknown event types fail explicitly.
- Read cost grows with a line's event count. Snapshots or a separate projection can be added if measured volume warrants them.
- Database triggers protect normal SQL writes, not a privileged owner who can remove triggers or replace the database file. Production audit guarantees also require controlled database access and backups.

## Tests

| Suite | What it tests | Docker command | Local command |
| --- | --- | --- | --- |
| Unit | Domain rules and replay in memory | `make test-unit` | `composer test:unit` |
| Integration | Handlers, repositories and real SQLite persistence | `make test-integration` | `composer test:integration` |
| Acceptance | CLI behavior and history retrieval in separate processes | `make test-acceptance` | `composer test:acceptance` |

`make test` or `composer test` runs all suites. Within each category, directories and namespaces mirror the module and layer being tested.

The test suite covers:

- All eight assignment steps and the full final history.
- Permanent manual precedence, including net-zero compensation and event reconstitution.
- Exact decimal results and amounts beyond native integer range.
- Blank comments, zero adjustments, currency mismatches, and duplicate adjustment IDs.
- UTC timestamps, comment preservation, author identity, and history order.
- Persistence across SQLite connections and separate CLI processes.
- Both orders of the automatic-update/manual-adjustment race.
- Whole-batch rollback and preservation of pending events after a failed save.
- SQL UPDATE, DELETE, and REPLACE rejection.

Tests use real domain objects and SQLite. The application test injects a deterministic Clock; it does not mock the persistence layer.

## Notes

- [Original assignment](docs/task-source/alcor-code-assignment.md)
- [Ubiquitous Language](docs/ubiquitous-language.md)
- [Technical Decisions](docs/technical-decisions.md)
- [Implementation Plan](docs/superpowers/plans/2026-09-15-earning-line-history.md)

AI assistance was used for design discussion, implementation, tests, documentation, and code review. The executable tests and documented trade-offs are the basis for validating the result.
