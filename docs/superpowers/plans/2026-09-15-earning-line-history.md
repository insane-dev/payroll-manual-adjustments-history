# Earning Line History Implementation Plan

> Historical Event Sourcing implementation plan from the original branch; the no-ES branch supersedes its storage and domain approach. See Technical Decisions for current behavior. Historical implementation plan. The subsequent module/layer restructuring supersedes the original file paths below; see [Technical Decisions](../../technical-decisions.md#module-and-layer-structure) for the current layout.

> Execute inline with the executing-plans workflow. The user approved the architecture, vocabulary, and continuation to implementation.

**Goal:** Implement the assignment's eight-step scenario with permanent manual precedence and a durable correction history.

**Architecture:** One Earning Line aggregate records immutable domain events. Command handlers load and save it through a repository backed by a SQLite Event Store. A synchronous query builds Adjustment History from the restored aggregate.

**Tech Stack:** PHP 8.4+, moneyphp/money, nesbot/carbon, ramsey/uuid, SQLite, PHPUnit, Docker Compose, Makefile.

**Spec:** [Technical Decisions](../../technical-decisions.md), [Ubiquitous Language](../../ubiquitous-language.md), [Assignment](../../task-source/alcor-code-assignment.md).

## Global Constraints

- Documentation uses Title Case domain terms; code types use PascalCase.
- Monetary amounts are integer minor-unit strings represented by MoneyPHP Money values.
- Saved adjustments are immutable; a compensating adjustment never unlocks automatic updates.
- Use explicit constructor dependency injection and small concrete handlers, without a framework or command bus.
- Record immutable UTC timestamps and UUID identifiers.
- Run tests on PHP 8.4 through Docker Compose; commit dependency resolution in composer.lock.

## File Boundaries

- `src/Domain/`: EarningLine, ManualAdjustment, three typed identifiers, and three immutable event types.
- `src/Application/`: commands/handlers, GetAdjustmentHistory/handler, AdjustmentHistory, Clock, EventStore, EarningLineRepository, and application exceptions.
- `src/Infrastructure/`: CarbonClock, EventSerializer, SqliteEventStore, schema.sql.
- `tests/Domain/`: business rules and reconstitution.
- `tests/Infrastructure/`: event round trips, file-backed persistence, version conflicts, atomicity, append-only protection.
- `tests/Application/`: real command/query flows with SQLite and a deterministic clock.
- `bin/demo.php`: composition root and the assignment scenario.
- `composer.json`, `phpunit.xml.dist`, `Dockerfile`, `compose.yaml`, `Makefile`: reproducible execution.
- `README.md`: usage, architecture, assumptions, limitations, and interview trade-offs.

## Task 1: Domain and Runtime

**Interfaces:** `EarningLine::create(EarningLineId, Money, CarbonImmutable): self`; `updateSystemAmount(Money, CarbonImmutable): void`; `addManualAdjustment(ManualAdjustment): void`; `reconstitute(array): self`; `recordedEvents(): array`; `markEventsCommitted(): void`; read methods for id, version, System Amount, Current Amount, and adjustments.

- [x] Add Composer configuration and the PHP 8.4 container with bcmath and pdo_sqlite.
- [x] Write the scenario test with literal expected minor-unit values: 100000, 105000, 100445, 100445, 110455, 110445, 110425, 110445. Run it and observe missing domain behavior.
- [x] Implement the three typed UUID identifiers, immutable ManualAdjustment and events, and EarningLine.
- [x] Add focused tests for compensation retaining the freeze, blank/zero adjustments, currency mismatch, duplicate adjustment identifiers, and reconstitution preserving history without new events. Observe failures before implementing each missing rule.
- [x] Run the domain test suite and commit `feat: model earning lines with immutable manual adjustments`.

Example contract:

```php
$line = EarningLine::create($id, Money::USD('100000'), $now);
$line->updateSystemAmount(Money::USD('105000'), $now);
$line->addManualAdjustment(new ManualAdjustment($adjustmentId, Money::USD('-4555'), 'Declined benefit', $authorId, $now));
self::assertSame('100445', $line->currentAmount()->getAmount());
```

## Task 2: Durable Event Storage

**Interfaces:** `EventStore::load(EarningLineId): array`; `append(EarningLineId, int $expectedVersion, array $events): void`; `EarningLineRepository::get(EarningLineId): EarningLine`; `save(EarningLine): void`.

- [x] Write SQLite tests for cross-connection persistence, two readers racing to save, rollback of a partially inserted batch, and direct UPDATE/DELETE rejection. Run to observe missing storage behavior.
- [x] Implement an explicit JSON serializer with stable versioned event names and minor-unit strings.
- [x] Add a schema with `(stream_id, version)` primary key and UPDATE/DELETE rejection triggers.
- [x] Append within BEGIN IMMEDIATE, verify expected version under the write lock, and roll back the entire batch on failure. Convert only version conflicts into a dedicated application exception.
- [x] Clear pending events only after a successful append. Preserve them when persistence fails.
- [x] Run storage and domain tests and commit `feat: persist earning line events atomically in sqlite`.

Concurrency contract:

```php
$first = $repository->get($id);
$stale = $repository->get($id);
$first->addManualAdjustment($adjustment);
$repository->save($first);
$stale->updateSystemAmount(Money::USD('900000'), $now);
$this->expectException(ConcurrentStreamWrite::class);
$repository->save($stale);
```

## Task 3: Commands, Query, and Demo

**Interfaces:** typed immutable CreateEarningLine, UpdateSystemAmount, AddManualAdjustment, GetAdjustmentHistory messages; one `handle()` method per handler; `Clock::now(): CarbonImmutable`; AdjustmentHistory contains id, System Amount, immutable adjustments, Current Amount.

- [x] Write an application test that executes the complete scenario through real handlers and a SQLite repository. Assert a reloaded Adjustment History has five adjustments and Current Amount `110445`.
- [x] Implement handlers using constructor injection; generate timestamps through Clock. Callers provide identifiers to make duplicate attempts detectable.
- [x] Implement the synchronous history query, preserving record order and author/comment/time metadata.
- [x] Add a CLI composition root and demo printing all eight steps and the final history. Give every run a new Earning Line Id in the same persistent database.
- [x] Run application tests and the CLI demo and commit `feat: expose earning line commands and audit history query`.

## Task 4: Delivery and Verification

- [x] Document Makefile commands, local Composer alternatives, architecture, and the exact example output in README.
- [x] State assumptions: nonzero adjustments, fixed currency per line, negative totals allowed, author identity supplied by caller, immutable saved records protected through API and SQLite triggers, privileged DB owners can still change the database.
- [x] Explain synchronous replay, explicit conflict handling, duplicate adjustment rejection rather than transparent idempotency, and no UI/authentication/external salary calculation.
- [x] Run `docker compose run --rm php composer validate --strict`, `make test`, `make demo`, PHP syntax checks, and `git diff --check`.
- [x] Review invariant enforcement, transaction boundaries, event serialization, and documentation; fix concrete findings with regression tests.
- [ ] Commit documentation and report verification results. Publishing requires a configured GitHub destination; the repository currently has no remote.

## Verification Results

- Docker build completed successfully; runtime PHP 8.4.25.
- `make check`: Composer validation, all PHP syntax checks, and 27 PHPUnit tests / 103 assertions passed.
- `composer install` verified the lock file on PHP 8.4.
- CLI produced the expected USD 1104.45 result; acceptance tests read the persisted history in a separate process.
- Independent read-only review found no actionable correctness issues.
- GitHub publication is pending the destination repository.
