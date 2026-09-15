# History of Manual Adjustments to an Earning Line

State-based PHP proof of concept for Alcor's payroll assignment. An Earning Line stores its salary state and an immutable history of Initial, System and Manual Adjustments. The first Manual Adjustment permanently prevents automatic recalculation from changing the line.

**PHP 8.4+ · MoneyPHP · Carbon · Ramsey UUID · SQLite · PHPUnit · DDD + CQRS**

This is the `feat/earning-line-history-no-es` experiment. The Event Sourcing implementation remains in `feat/earning-line-history`. This variant uses ordinary state persistence with an append-only audit table.

## Run

With Docker Compose and Make:

```sh
make setup
make check
make demo
```

`make setup` builds PHP 8.4 and installs locked dependencies. `make check` validates Composer configuration, checks PHP syntax, runs PHPStan and PHP-CS-Fixer, and runs all test suites.

Each demo creates a new line in `var/payroll-no-es-compact.sqlite`. Previous histories remain available. Copy the printed line UUID to read it in another process:

```sh
 docker compose run --rm php php bin/demo.php --history <earning-line-id>
```

The earlier databases `var/payroll.sqlite` (ES), `var/payroll-no-es.sqlite` (before Initial entries) and `var/payroll-no-es-initial.sqlite` (before compact storage) are kept separate. This schema requires a fresh database; there is no automatic migration between the experimental schemas. Set `PAYROLL_DATABASE` to select another new database; Docker requires passing it with `-e PAYROLL_DATABASE=/app/var/other.sqlite`.

With local 64-bit PHP 8.4+, SQLite 3.37+ and Composer:

```sh
composer install
composer test
composer demo
php bin/demo.php --history <earning-line-id>
```

Required extensions include bcmath and pdo_sqlite, plus PHPUnit's dom, mbstring, xml and xmlwriter requirements. Tests use disposable databases and do not change demo data.

## Model

`EarningLine` is the aggregate root. It owns Initial Amount, System Amount, Current Amount, manual precedence, version and its adjustment history.

`EarningLineAdjustment` is an immutable entity with UUID, Type, signed Amount, Comment, Author Id and UTC Recorded At. Its type is an `EarningLineAdjustmentType` enum:

| Type | Meaning | Rules |
| --- | --- | --- |
| Initial | Original calculation, as the first and only Initial entry | Created with the line; may be zero; does not freeze automatic updates |
| System | Difference between a new automatic calculation and the previous System Amount | Allowed only before the first Manual; comment and author optional |
| Manual | Signed correction entered by a specialist | Nonzero amount, mandatory nonblank comment and author; permanently freezes System Amount |

`UpdateSystemAmount` receives an absolute amount. Updating USD 1000.00 to USD 1050.00 records a System Adjustment of +USD 50.00. Repeating the same amount creates no adjustment. After any Manual Adjustment, automatic updates are ignored and no System Adjustment is recorded.

```text
Current Amount = sum of all Initial, System and Manual Adjustment Amounts
System Amount  = sum of Initial and System Adjustment Amounts
```

Compensating a mistake creates another Manual Adjustment. Existing records are never edited or deleted. A net manual sum of zero does not restore automatic updates.

## Assignment Scenario

| Step | Action | Current Amount |
| --- | --- | --- |
| 1 | Initial calculation | USD 1000.00 |
| 2 | System Adjustment +50.00 | USD 1050.00 |
| 3 | Manual Adjustment −45.55 | USD 1004.45 |
| 4 | Automatic recalculation ignored | USD 1004.45 |
| 5 | Manual Adjustment +100.10 | USD 1104.55 |
| 6 | Manual Adjustment −0.10 | USD 1104.45 |
| 7 | Manual Adjustment −0.20 | USD 1104.25 |
| 8 | Compensating Manual Adjustment +0.20 | **USD 1104.45** |

History contains seven records: one Initial Adjustment of USD 1000.00, one System Adjustment, five Manual Adjustments, frozen System Amount USD 1050.00 and Current Amount USD 1104.45. Manual numbering is separate from System numbering so the comment about adjustment #4 remains understandable. The original signs and comments are reproduced as supplied; the model does not interpret payroll formulas or benefit semantics.

## Persistence and Architecture

| Location | Responsibility |
| --- | --- |
| `src/Earning/Domain/Entity` | Aggregate state and immutable adjustments |
| `src/Earning/Domain/Value` | Typed identifiers and Adjustment Type |
| `src/Earning/Domain/Repository` | Repository interface |
| `src/Earning/Application/Command/Handler` | Use-case orchestration |
| `src/Earning/Application/Query` | History query, handler and result DTO |
| `src/Earning/Infrastructure/Mapper` | Mapping stored rows to domain state |
| `src/Earning/Infrastructure/Persist/Write` | SQLite repository and schema |
| `src/Earning/UI/Cli` | Scenario, arguments and formatted output |
| `src/Shared` | Clock contract and Carbon implementation |
| `bin/demo.php` | Configuration and dependency composition |

`earning_lines` stores current state. `earning_line_adjustments` stores every amount-producing record in sequence order, starting with exactly one Initial entry at sequence 1. Initial Amount on the state row mirrors this first entry and is not added to the history sum a second time. MoneyPHP performs exact arithmetic in minor units. Persistence accepts signed 64-bit integers, from −9223372036854775808 to 9223372036854775807 minor units. The mapper rejects amounts or individual deltas outside this range before converting them to PHP integers; a failed save rolls back the entire transaction. Domain arithmetic can represent larger values, but they cannot be persisted.

| Data | SQLite storage |
| --- | --- |
| Amounts | Signed `INTEGER`, in minor units |
| UUIDs | 16-byte `BLOB` |
| Adjustment Type | `INTEGER`: Initial = 0, System = 1, Manual = 2 |
| Timestamps | `INTEGER`, UTC Unix epoch microseconds |
| Manual flag, version, sequence | `INTEGER` |
| Currency, comment | `TEXT`; currency stored only on the line |

Both tables use `STRICT` type enforcement and `WITHOUT ROWID` to avoid an extra hidden row identifier.

Saving uses one write transaction: check the persisted version under `BEGIN IMMEDIATE`, save state, insert pending adjustments, commit. On failure, both changes roll back and the aggregate retains pending adjustments. A concurrent stale writer receives `ConcurrentStreamWrite` and must reload before retrying. If a Manual Adjustment wins a race, a reloaded automatic update sees the freeze and is ignored.

Reads load state and history inside one snapshot transaction. Domain state is restored directly from rows; no domain events are replayed. CQRS separates commands and queries while using the same repository and database.

There are no database triggers. Immutable domain entities and the repository enforce append-only history and permanent manual precedence through the application API. Foreign keys, CHECK constraints and unique keys validate row structure, ownership and ordering. Direct SQL updates or deletes are not protected by the application-level immutability guarantee.

The state-based design directly meets the business case with fewer ES-specific concepts. Its trade-off is maintaining state and audit consistency transactionally, rather than deriving state from an event stream. Adding full replay or asynchronous projections would require a deliberate new design.

## Tests

| Suite | Boundary | Docker | Local |
| --- | --- | --- | --- |
| Unit | In-memory business rules and typed adjustment arithmetic | `make test-unit` | `composer test:unit` |
| Integration | SQLite state/history persistence and handlers | `make test-integration` | `composer test:integration` |
| Acceptance | CLI scenario and history read in separate processes | `make test-acceptance` | `composer test:acceptance` |

`make test` runs all suites. Tests cover all eight steps, positive and negative System deltas, permanent precedence after net-zero compensation and reload, money precision, mandatory manual metadata, duplicate IDs, fixed currency, both race orders, initial-entry creation (including zero), rollback of line creation if the Initial insert fails, full rollback/retry, repository history preservation, compact storage types, signed 64-bit boundaries, overflow rollback, microsecond timestamp round-trips and durable CLI history.

## CI and Code Quality

GitHub Actions runs on every push and pull request, with a manual trigger available. Three independent PHP 8.4 jobs run PHPStan, PHP-CS-Fixer in dry-run mode, and PHPUnit (Unit, Integration and Acceptance). Each job installs dependencies from `composer.lock`; Composer downloads are cached.

PHPStan uses level 8 for `src`, `bin` and `tests`, with the PHPUnit extension and no baseline. PHP-CS-Fixer applies PSR-12, PER Coding Style 2.0, strict types and sorted imports; its configuration also covers itself. PER Coding Style rules take precedence where the sets overlap.

| Check | Docker | Local |
| --- | --- | --- |
| Static analysis | `make analyse` | `composer analyse` |
| Style check | `make cs-check` | `composer cs-check` |
| Apply style fixes | `make cs-fix` | `composer cs-fix` |
| All checks | `make check` | `composer validate --strict` then `composer check` |

The workflow is in `.github/workflows/ci.yml` and activates when pushed to GitHub.

## Assumptions

- Each line has one currency. `Money::USD('10010')` means USD 100.10. Source calculation, rounding and currency conversion are outside the exercise.
- Negative amounts/totals are allowed; System and Manual adjustments must be nonzero. An Initial entry may be zero.
- Caller supplies line/manual adjustment/author UUIDs. Automatic adjustment UUIDs are generated when accepting a new calculation. Authentication and source provenance are outside scope.
- Manual comments are preserved verbatim and require non-whitespace UTF-8 text. System metadata can be absent.
- Duplicate adjustment IDs within a line are rejected, not treated as transparent idempotent success.
- Version protects state writes; sequence defines audit order independently of timestamps. Timestamps describe recording time, not effective payroll date.
- Schema initializes automatically. Deployments would need migrations and explicit conversion from the ES version.
- History is loaded with the aggregate for this small PoC; large histories may warrant pagination or a dedicated read adapter.
- Errors surface as exceptions; CLI prints stderr and returns a nonzero exit code. SQLite waits up to five seconds for lock contention.

See [Ubiquitous Language](docs/ubiquitous-language.md), [Technical Decisions](docs/technical-decisions.md), and [Original Assignment](docs/task-source/alcor-code-assignment.md).

AI assistance was used for design, code, tests, documentation and review. Executable checks validate the resulting behavior.
