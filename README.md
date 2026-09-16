# History of Manual Adjustments to an Earning Line

State-based PHP proof of concept for Alcor's payroll assignment. An Earning Line stores its salary state and an immutable history of Initial, System and Manual Adjustments. The first Manual Adjustment permanently prevents automatic recalculation from changing the line.

**PHP 8.4+ · MoneyPHP · Carbon · Ramsey UUID · MySQL 8.4 · PHPUnit · DDD + CQRS**

This is the `feat/earning-line-history-no-es` experiment. The Event Sourcing implementation remains in `feat/earning-line-history`. This variant uses ordinary state persistence with an append-only audit table.

## Run

With Docker Compose and Make:

```sh
make setup
make check
make demo
```

`make setup` builds PHP 8.4 and installs locked dependencies. `make check` validates Composer configuration, checks PHP syntax, runs PHPStan and PHP-CS-Fixer, and runs all test suites.

Compose starts MySQL 8.4 and waits for it to become healthy. Demo histories are stored in the `payroll` database on the `mysql-data` volume. Each run creates a new line. Copy its printed UUID to read it in another process:

```sh
docker compose run --rm php php bin/demo.php --history <earning-line-id>
```

`schema.sql` defines the current schema for fresh databases. This proof of concept does not include migrations for earlier schema versions.

The earlier SQLite experiment files in `var/` are preserved. MySQL uses a fresh schema; no automatic SQLite-to-MySQL migration is implemented.

With local 64-bit PHP 8.4+, MySQL 8.4 and Composer, configure connections before running:

```sh
export PAYROLL_DATABASE_DSN='mysql:host=127.0.0.1;port=3307;dbname=payroll;charset=utf8mb4'
export PAYROLL_DATABASE_USER=payroll
export PAYROLL_DATABASE_PASSWORD=payroll
export TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3307;charset=utf8mb4'
export TEST_MYSQL_USER=root
export TEST_MYSQL_PASSWORD=root
composer install
composer test
composer demo
php bin/demo.php --history <earning-line-id>
```

These credentials belong to the local Compose service; another MySQL server needs its own credentials. The test DSN must identify the server without `dbname`. Tests create randomly named `payroll_test_*` databases and drop only those databases afterward, so the test account needs CREATE/DROP DATABASE privileges. Unit tests need no running database. Required extensions include bcmath and pdo_mysql, plus PHPUnit's dom, mbstring, xml and xmlwriter requirements.

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
| `src/Earning/Infrastructure/Persist/Write` | MySQL repository and schema |
| `src/Earning/UI/Cli` | Scenario, arguments and formatted output |
| `src/Shared` | PSR-20-compatible Clock and shared MySQL connection factory |
| `bin/demo.php` | Configuration and dependency composition |

`earning_lines` stores current state. `earning_line_adjustments` stores every amount-producing record in sequence order, starting with exactly one Initial entry at sequence 1. Initial Amount on the state row mirrors this first entry and is not added to the history sum a second time. MoneyPHP performs exact arithmetic in minor units. Persistence accepts signed 64-bit integers, from −9223372036854775808 to 9223372036854775807 minor units. The mapper rejects amounts or individual deltas outside this range before converting them to PHP integers; a failed save rolls back the entire transaction. Domain arithmetic can represent larger values, but they cannot be persisted.

| Data | MySQL storage |
| --- | --- |
| Amounts | Signed `BIGINT` (8 bytes), in minor units |
| UUIDs | `BINARY(16)` (16 bytes) |
| Adjustment Type | `ENUM('initial', 'system', 'manual')` (1 byte) |
| Timestamps | Signed `BIGINT` (8 bytes), UTC Unix epoch microseconds |
| Manual flag | `TINYINT UNSIGNED` (1 byte) |
| Version, sequence | `INT UNSIGNED` (4 bytes) |
| Currency | `CHAR(3)` with ASCII binary collation; stored only on the line |
| Comment | `TEXT`, UTF-8 (`utf8mb4`) |

Both tables use InnoDB. The connection factory enables strict SQL mode, native prepared statements and integer fetching. Comments support full Unicode; MySQL TEXT limits them to 65,535 bytes, with oversized writes rejected transactionally.

`earning_lines` has primary key `(id)`. Adjustments have primary key `(earning_line_id, sequence)` to read one line's history in order, and unique index `(earning_line_id, id)` to reject duplicate adjustment identities within that line. A separate line-id index would duplicate the primary key's left prefix. There are no indexes on type, author or timestamps because current queries do not filter on them.

UUIDv7's time prefix groups newly created line keys near one another in InnoDB's clustered B-tree, giving better insertion locality than random UUIDv4 keys. Binary storage keeps each UUID to 16 bytes instead of 36 text characters. This is an index-layout benefit, not a benchmarked performance claim; sequence remains the audit order. See [Technical Decisions](docs/technical-decisions.md) for the rationale.

Saving uses one write transaction: insert a new line or atomically update an existing line with `WHERE id = :id AND version = :expected_version`, insert pending adjustments, commit. A zero-row update signals a stale writer; a duplicate line insert signals a conflicting creation. InnoDB locks the affected line while writing, allowing unrelated lines to be written independently. On failure, both changes roll back and the aggregate retains pending adjustments. A concurrent stale writer receives `ConcurrentEarningLineWrite` and must reload before retrying. If a Manual Adjustment wins a race, a reloaded automatic update sees the freeze and is ignored.

Reads load state and history inside one explicit REPEATABLE READ snapshot transaction. Domain state is restored directly from rows; no domain events are replayed. CQRS separates commands and queries while using the same repository and database.

There are no database triggers. Immutable domain entities and the repository enforce append-only history and permanent manual precedence through the application API. There are no foreign keys. CHECK constraints and unique keys validate row structure, identity and ordering. The aggregate owns its adjustments, and the repository saves the parent state before inserting them in the same transaction. Direct SQL updates or deletes are not protected by the application-level immutability guarantee.

The state-based design directly meets the business case with fewer ES-specific concepts. Its trade-off is maintaining state and audit consistency transactionally, rather than deriving state from an event stream. Adding full replay or asynchronous projections would require a deliberate new design.

## Tests

| Suite | Boundary | Docker | Local |
| --- | --- | --- | --- |
| Unit | In-memory business rules and typed adjustment arithmetic | `make test-unit` | `composer test:unit` |
| Integration | MySQL state/history persistence and handlers | `make test-integration` | `composer test:integration` |
| Acceptance | CLI scenario and history read in separate processes | `make test-acceptance` | `composer test:acceptance` |

`make test` runs all suites. Tests cover all eight steps, positive and negative System deltas, permanent precedence after net-zero compensation and reload, money precision, clustered-index history access, strict database overflow rejection, line-scoped adjustment identity, mandatory manual metadata, duplicate IDs, fixed currency, both race orders, initial-entry creation (including zero), rollback of line creation if the Initial insert fails, full rollback/retry, repository history preservation, compact storage types, signed 64-bit boundaries, overflow rollback, microsecond timestamp round-trips and durable CLI history.

## CI and Code Quality

GitHub Actions runs on every push and pull request, with a manual trigger available. Three independent PHP 8.4 jobs run PHPStan, PHP-CS-Fixer in dry-run mode, and PHPUnit (Unit, Integration and Acceptance). Each job installs dependencies from `composer.lock`; Composer downloads are cached. CI provides MySQL 8.4 for database-backed tests.

PHPStan uses level 8 for `src`, `bin` and `tests`, with the PHPUnit extension and no baseline. PHP-CS-Fixer applies PSR-12, PER Coding Style 2.0, strict types and sorted imports; its configuration also covers itself. PER Coding Style rules take precedence where the sets overlap. Every promoted constructor property is placed on its own line, even in constructors with a single property.

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
- New identifiers use UUIDv7 via Ramsey UUID. Caller supplies line/manual adjustment/author UUIDs; automatic adjustment UUIDs are generated when accepting a new calculation. Valid UUIDs of other versions remain accepted for existing data and external identities. UUID timestamps do not replace Recorded At or sequence ordering. Authentication and source provenance are outside scope.
- Manual comments are preserved verbatim and require non-whitespace UTF-8 text. System metadata can be absent.
- Duplicate adjustment IDs within a line are rejected, not treated as transparent idempotent success.
- Version protects state writes; sequence defines audit order independently of timestamps. Timestamps describe recording time, not effective payroll date.
- The composition root initializes the schema before creating the repository, outside data transactions. Schema upgrades and conversions from previous experiments require explicit migrations.
- History is loaded with the aggregate for this small PoC; large histories may warrant pagination or a dedicated read adapter.
- Errors surface as exceptions; CLI prints stderr and returns a nonzero exit code. InnoDB waits up to five seconds for row-lock contention. Deadlocks and lock timeouts surface as database exceptions; callers reload before deciding whether to retry.

See [Ubiquitous Language](docs/ubiquitous-language.md), [Technical Decisions](docs/technical-decisions.md), and [Original Assignment](docs/task-source/alcor-code-assignment.md).

AI assistance was used for design, code, tests, documentation and review. Executable checks validate the resulting behavior.
