# Technical Decisions

## Agreed Scope

This branch, explores a state-based DDD and CQRS model with MySQL 8.4 and InnoDB.

- One Earning Line aggregate owns salary-line state and immutable Earning Line Adjustments.
- Adjustment Type is Initial, System or Manual. Initial records the starting amount; System and Manual store signed deltas; automatic recalculation commands accept absolute amounts.
- The first Manual Adjustment permanently freezes System Amount and prevents further System Adjustments.
- Commands and queries are separate; reads use the same repository without a separate projection.
- Explicit constructor injection, PHP 8.4+, CLI demonstration, and PHPUnit remain in scope.

The agreed vocabulary is in [Ubiquitous Language](ubiquitous-language.md); the original requirements are in [the assignment](task-source/alcor-code-assignment.md).

## State and History Storage

`earning_lines` stores `id`, `initial_amount`, `system_amount`, `current_amount`, `currency`, `manually_adjusted`, `version`, and `created_at`. This is authoritative current state, not a rebuildable event projection.

`earning_line_adjustments` stores `earning_line_id`, `id`, `sequence`, `type`, `amount`, `comment`, `author_id`, and `recorded_at`. Entries are immutable audit records, not domain events. Creation records an Initial Adjustment at sequence 1 with the original amount. Later automatic changes are System Adjustments. The Initial Amount field on the line mirrors the Initial entry. Current Amount equals the sum of all entries, with no extra baseline added.

Amounts use signed 64-bit BIGINT minor units. MoneyPHP still performs exact arithmetic; the mapper checks every amount and delta against −9223372036854775808…9223372036854775807 before casting to a PHP integer. An overflow rejects the save and rolls back all changes. Arbitrarily large domain amounts are therefore not necessarily persistable. No floating-point monetary conversion or SQL SUM is used.

UUIDs use `BINARY(16)` instead of 36-character text. Adjustment Type uses `ENUM('initial', 'system', 'manual')`, matching the domain enum's string-backed values. With three members, MySQL stores it in one byte, the same size as TINYINT. PDO returns the named value, so the mapper writes `->value` and reads through `EarningLineAdjustmentType::from()` without maintaining a numeric code mapping. The manual flag remains TINYINT UNSIGNED. Version and sequence use INT UNSIGNED (1…4,294,967,295). Amounts and UTC epoch-microsecond timestamps use signed BIGINT, preserving exact amounts and microseconds including dates before 1970. Currency uses ASCII `CHAR(3)` with binary collation and is stored only on the line; the mapper supplies it to adjustments. Comments use utf8mb4 TEXT, supporting full Unicode up to MySQL's 65,535-byte TEXT limit. Strict SQL mode rejects oversized values instead of truncating them.

The shared connection factory enables native PDO prepared statements, disables stringify-fetches, enables strict SQL mode and sets the InnoDB lock wait timeout to five seconds. Parameters are explicitly bound as integer, binary, text or NULL. Line state is restored directly; no event replay, Event Store, event serializer or domain event classes remain.

MySQL ENUM enforces the allowed value set under strict SQL mode. The column uses ASCII binary collation to match domain values exactly. Constraints compare named values such as `type = 'initial'`, not MySQL's internal 1-based ordinals. History continues ordering by sequence rather than ENUM declaration order. Extending or renaming a member requires a schema migration alongside the PHP change. See [MySQL ENUM](https://dev.mysql.com/doc/refman/8.4/en/enum.html).

### Indexes and UUIDv7 Primary Keys

| Table                    | Index                                              | Purpose                                                                                          |
|--------------------------|----------------------------------------------------|--------------------------------------------------------------------------------------------------|
| earning_lines            | PRIMARY (id)                                       | Clustered UUIDv7 key for state lookup and version-conditional writes                             |
| earning_line_adjustments | PRIMARY (earning_line_id, sequence)                | Cluster one line's history in audit order; serve line-id filtering and ORDER BY without filesort |
| earning_line_adjustments | UNIQUE uq_line_adjustment_id (earning_line_id, id) | Keep adjustment IDs unique within a line                                                         |

The adjustment primary key covers line-id filtering as its left prefix, so no separate earning_line_id index is needed. No current query filters on type, author or timestamps, so those columns are not indexed. Compared with clustering adjustments on `(earning_line_id, id)`, the 20-byte line/sequence clustered key is smaller than a 32-byte pair of UUIDs and matches the ordered history access pattern. InnoDB includes primary-key columns in secondary indexes, making a compact clustered key useful. See [InnoDB clustered and secondary indexes](https://dev.mysql.com/doc/refman/8.4/en/innodb-index-types.html).

New identifiers use UUIDv7 via `Uuid::uuid7()`. Its most significant bits contain generation time in milliseconds. New line keys therefore tend to land in nearby B-tree pages rather than the scattered positions produced by random UUIDv4 keys. This improves insertion locality and can reduce random page access and page splitting compared with UUIDv4; the PoC does not claim a measured throughput gain. BINARY(16) also avoids text parsing/collation and reduces index width compared with CHAR(36). UUIDv7 does not guarantee global insertion order across writers or clock changes. See [Ramsey UUIDv7](https://uuid.ramsey.dev/en/stable/rfc4122/version7.html).

The actual UUID generation time is independent of Recorded At. Sequence remains the authoritative audit order. Value Objects accept valid UUIDs of other versions for existing data and external identities. Store Ramsey's bytes in their standard order: UUIDv7 is already time-ordered and does not need MySQL's UUIDv1-oriented `UUID_TO_BIN(..., 1)` byte swapping.

Exactly one Initial entry is required at the start: a CHECK constraint reserves sequence 1 for Initial and uniqueness prevents duplicates. The aggregate creates the Initial entry and the repository appends subsequent entries in contiguous order. Initial entry and line creation are persisted in the same transaction.

### SQL Comments and Access Patterns

`schema.sql` includes comments explaining compact types, index choices and the boundary between SQL constraints and domain rules. MySQL column and table `COMMENT` metadata records amount semantics, timestamp units, adjustment types and ownership, so these descriptions remain visible through `SHOW FULL COLUMNS` and `SHOW CREATE TABLE` after initialization.

| Operation                                        | Predicate or ordering                            | Index used                                                               |
|--------------------------------------------------|--------------------------------------------------|--------------------------------------------------------------------------|
| Read Earning Line state                          | `WHERE id = :id`                                 | `earning_lines.PRIMARY`                                                  |
| Update state with concurrency protection         | `WHERE id = :id AND version = :expected_version` | `earning_lines.PRIMARY`, with version checked on the single matching row |
| Read full adjustment history                     | `WHERE earning_line_id = :id ORDER BY sequence`  | `earning_line_adjustments.PRIMARY`, without a separate sorting pass      |
| Reject a duplicate adjustment identity on insert | `(earning_line_id, id)` uniqueness               | `uq_line_adjustment_id`                                                  |

An additional `(id, version)` index would duplicate the unique line lookup without reducing the number of candidate rows. SQL constraints validate identity, sequence structure and metadata presence. Ownership is enforced through the aggregate and repository. The domain enforces nonblank comments and permanent manual precedence, while the repository appends history transactionally. Schema comments do not introduce triggers or additional mutation protection. `CREATE TABLE IF NOT EXISTS` adds comments to fresh schemas only, so an existing MySQL database needs an explicit schema migration to update its metadata.

A repository save starts an InnoDB transaction and either inserts a new line or performs a conditional UPDATE by primary key and expected version. Zero affected rows raise ConcurrentEarningLineWrite; a duplicate parent primary key is translated to the same application exception. This removes the select-then-write race and locks only the affected line instead of serializing all database writes. Pending adjustments are appended before committing. Any failure rolls back both tables and retains pending adjustments for retry. Stale writers raise `ConcurrentEarningLineWrite`; callers reload and reconsider their command. Reads explicitly use REPEATABLE READ so state and history come from one database snapshot, even if the server default isolation differs. Deadlocks and lock timeouts roll back the save and remain database exceptions; retry policy belongs to the caller.

There are no foreign keys. The aggregate owns its adjustments and the repository writes their parent state before appending them in the same transaction. Direct SQL can create orphan adjustments, so database-level referential integrity is outside this application API guarantee. Unique keys protect line-scoped adjustment IDs and sequence order. There are no triggers. The domain enforces immutable adjustments and permanent manual precedence; the repository only inserts pending adjustments and never updates or deletes saved history. Direct SQL mutation is outside this application-level guarantee. CHECK constraints validate storage structure, including nonzero System/Manual amounts, Manual metadata presence and the Initial sequence.

Initial and System comments/authors are optional; automatic recalculations currently leave them absent. Manual adjustments require nonzero amounts, nonblank comments and an author. An unchanged system amount creates no zero adjustment. Initial entries may be zero, preserving support for a zero salary calculation. Currency is fixed per line; negative values are allowed.

The local default database is MySQL `payroll`, persisted in the Compose `mysql-data` volume. Existing SQLite files in `var/` remain untouched. MySQL starts with a fresh schema; no automatic data migration is implemented. Schema installation is explicit through `MysqlSchema` in the composition root or test fixture, outside repository operations: MySQL DDL implicitly commits and cannot be part of a salary-state transaction. Deployments need versioned migrations for later schema changes. This proof of concept keeps only the current `schema.sql` for fresh databases, without migration scripts for earlier schema versions.

## Runtime and Dependencies

| Component         | Choice           | Purpose                                                              |
|-------------------|------------------|----------------------------------------------------------------------|
| PHP               | 64-bit 8.4+      | Application runtime                                                  |
| Money             | `moneyphp/money` | Exact monetary values and arithmetic without floating-point amounts  |
| Date and Time     | `nesbot/carbon`  | Date and time handling; use immutable values for recorded timestamps |
| Clock Contract    | `psr/clock`      | PSR-20 interoperability for reading the current time                  |
| Identifiers       | `ramsey/uuid`    | UUID generation and validation                                       |
| Persistence       | MySQL 8.4/InnoDB | Local state and adjustment persistence                                              |
| Tests             | PHPUnit          | Business-rule and persistence verification                           |
| Local Environment | Docker Compose   | Reproducible runtime and development commands                        |
| Command Shortcuts | Makefile         | Convenient entry points for routine development tasks                |

Exact dependency versions are recorded in `composer.lock`, with dependency resolution targeting PHP 8.4.

`App\Shared\Application\Clock` extends PSR-20's `Psr\Clock\ClockInterface`. Its `now()` method narrows the return type from `DateTimeImmutable` to `CarbonImmutable`, which is a compatible subtype. `CarbonClock` returns the current time in UTC; handlers retain the application contract and Carbon-specific timestamp operations. These clocks can also be passed to consumers of the standard PSR-20 interface. A general PSR-20 implementation returning only `DateTimeImmutable` would need an adapter to satisfy the narrower application contract. See [PSR-20](https://www.php-fig.org/psr/psr-20/).

## Module and Layer Structure

### Naming and Enum Conventions

- Domain terms use Title Case in documentation; class, interface and enum type names use PascalCase.
- Methods and properties use camelCase.
- Dependency properties describe the domain capability they provide. Aggregate repositories use plural domain names, such as `$earningLines` for `EarningLineRepository`; mappers identify the mapped concept, such as `$earningLineMapper`. Use the same names when wiring dependencies and in tests. Small, established names such as `$clock` and `$connection` remain appropriate when their purpose is clear.
- Enum case names use UPPERCASE; compound names use UPPER_SNAKE_CASE. For example: `EarningLineAdjustmentType::MANUAL` and `EarningLineAdjustmentType::SYSTEM`.
- Backed values are a separate storage/API contract and need not be uppercase. Domain backed values remain `initial`, `system` and `manual`. MySQL ENUM stores these same named values, so no numeric storage mapping is needed. New types require coordinated PHP enum and SQL schema changes.
- Enums may expose small, side-effect-free convenience methods that improve domain readability, such as `isManual()` and `isSystem()`. Use these predicates for semantic checks rather than repeating case comparisons. Keep persistence and use-case orchestration outside enums.

Organize code by business module first, then by architectural layer. The current module is **Earning**, which owns Earning Lines and their typed Earning Line Adjustments. `Shared` contains reusable capabilities that have no dependency on any business module.

Use this structure as a placement convention. Create a directory only when there is a real implementation to put in it; the convention does not require empty folders or placeholder classes.

```text
src/
├── Shared/
│   ├── Application/              # Reusable application contracts, such as Clock
│   ├── Domain/                   # Shared domain concepts, only when genuinely shared
│   ├── Infrastructure/           # Implementations of shared technical capabilities
│   └── UI/                       # Reusable transport/presentation components
└── <ModuleName>/
    ├── UI/
    │   ├── Cli/                  # CLI commands, input handling and output
    │   └── Http/                 # HTTP controllers/adapters, if introduced
    ├── Application/
    │   ├── Command/
    │   │   └── Handler/
    │   ├── Query/
    │   │   ├── Handler/
    │   │   └── Result/           # Read DTOs returned by queries
    │   ├── Port/                 # Module-specific technical contracts
    │   └── Exception/            # Application/use-case failures
    ├── Domain/
    │   ├── Event/
    │   ├── Entity/               # Entities and aggregate roots
    │   ├── Value/                # Domain Value Objects
    │   ├── Service/              # Domain behavior that belongs to no entity
    │   ├── Repository/           # Aggregate repository interfaces
    │   └── Exception/            # Custom domain failures, when needed
    └── Infrastructure/
        ├── Mapper/               # Mapping between domain values and storage formats
        └── Persist/
            ├── Read/             # Query-specific read adapters, when needed
            └── Write/            # Aggregate state and adjustment persistence
```

The tree is a convention for all modules, not a list of currently implemented components. Namespaces follow directory paths under the existing `App\` PSR-4 root.

### Dependency Rules

1. **Domain** owns business rules and repository contracts. It does not import Application, Infrastructure, or UI. The approved MoneyPHP, Carbon, and UUID value libraries may be used directly.
2. **Application** orchestrates use cases through domain objects and interfaces. Commands and queries are data; handlers perform the work. Handlers depend on repository contracts, never concrete persistence adapters.
3. **Infrastructure** implements contracts and maps stored data. It may depend on Domain and Application; neither depends on Infrastructure.
4. **UI** translates transport input into application messages and presents results. UI commands do not open database connections or construct persistence adapters.
5. **Composition roots**, such as `bin/demo.php`, load configuration and wire concrete dependencies into handlers and UI adapters. They contain no payroll workflow or output formatting.
6. **Shared** never imports a business module. Module-specific code stays in its module, even when it is technically reusable. Extract it only when it represents a reusable, business-independent capability or an explicitly shared domain concept.
7. Modules do not access another module's Infrastructure or aggregate internals. Future cross-module use cases should use explicit application contracts or integration events.

### Current Placement

| Component                                | Location                                                                      |
|------------------------------------------|-------------------------------------------------------------------------------|
| Shared MySQL connection factory          | `Shared/Infrastructure/Persist/MysqlConnectionFactory.php`                    |
| Clock and implementation                 | `Shared/Application/Clock.php`, `Shared/Infrastructure/Clock/CarbonClock.php` |
| Earning Line and Earning Line Adjustment | `Earning/Domain/Entity/`                                                      |
| Identifiers and Adjustment Type enum     | `Earning/Domain/Value/`                                                       |
| Repository interface                     | `Earning/Domain/Repository/EarningLineRepository.php`                         |
| Commands and handlers                    | `Earning/Application/Command/` and `Command/Handler/`                         |
| Queries, handlers and result DTOs        | `Earning/Application/Query/`, `Query/Handler/` and `Query/Result/`            |
| State/history mapper                     | `Earning/Infrastructure/Mapper/EarningLineMapper.php`                         |
| MySQL repository and schema              | `Earning/Infrastructure/Persist/Write/`                                       |
| CLI command                              | `Earning/UI/Cli/DemoCommand.php`                                              |

`Persist/Write` includes loading and saving the authoritative aggregate state. The history query uses the same repository. Add `Persist/Read` only if a dedicated query adapter is needed; CQRS does not require two databases or duplicated persistence implementations.

### Tests and Compatibility

Tests are organized by test category first, then by module and layer. Each category has its own PHPUnit suite:

| Category    | Location                                                  | Boundary tested                                                                                                            |
|-------------|-----------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------|
| Unit        | `tests/Unit/Earning/Domain/Entity/`                       | Domain rules and typed adjustment arithmetic in memory, without database, filesystem or subprocess access                  |
| Integration | `tests/Integration/Earning/Application/`                  | Command/query handlers working together with the real MySQL repository                                                     |
| Integration | `tests/Integration/Earning/Infrastructure/Persist/Write/` | State/history mapping, InnoDB transactions, concurrency, compact types, integer bounds and repository history preservation |
| Acceptance  | `tests/Acceptance/Earning/UI/`                            | The complete CLI scenario and persisted history retrieval through separate processes                                       |

Use `make test-unit`, `make test-integration`, or `make test-acceptance` to run an individual category. `make test` runs all three. Local Composer equivalents are `composer test:unit`, `composer test:integration`, and `composer test:acceptance`.

Classify tests by the boundary they exercise, rather than the layer of the class under test. The application workflow is an Integration test because it uses actual persistence; the CLI test is Acceptance because it observes the executable's behavior from outside the application. Shared tests follow the same convention under `tests/<Category>/Shared/` when needed. Reusable test fixtures live in `tests/Shared/`; MysqlTestDatabase creates an isolated database, while Earning tests initialize their own module schema.

The CLI commands and assignment's expected sums remain the same. This branch changes both storage schema and history semantics: Initial and System Adjustments are explicit audit entries. Connection settings use PAYROLL_DATABASE_DSN/USER/PASSWORD; earlier SQLite branches use their existing files. Integration and Acceptance tests each create and drop an isolated random MySQL database through TEST_MYSQL_DSN/USER/PASSWORD. The test account requires CREATE/DROP DATABASE privileges. Database-backed suites fail if MySQL is unavailable; they are not silently skipped.


## Continuous Integration and Code Quality

GitHub Actions checks every push and pull request on PHP 8.4, matching the Docker runtime and Composer platform. A three-job matrix runs PHPStan, PHP-CS-Fixer and all PHPUnit suites independently so a failure in one check does not hide another result. Jobs have read-only repository permissions, a ten-minute timeout and cancellation of superseded runs. Dependencies are installed from the lockfile, with Composer download caching. MySQL 8.4 is available as a healthy service for database-backed tests; pdo_mysql replaces pdo_sqlite.

PHPStan level 8 covers application code, the CLI composition root and tests. Its PHPUnit extension understands assertions and test contracts. There is no baseline or ignored-error list. PHPDoc refines types that PHP cannot express directly, such as numeric strings, ordered lists and data-provider tuples. Repository reads are marked impure because committed database state can change between calls.

PHP-CS-Fixer enables PSR-12 followed by PER Coding Style 2.0, with PER rules taking precedence where the sets overlap. It also requires strict type declarations, unused-import removal and alphabetical imports. `multiline_promoted_properties` uses `minimum_number_of_parameters: 1` so every promoted constructor property appears on its own line, including a single promoted property. CI uses dry-run mode; developers apply changes explicitly with `make cs-fix` or `composer cs-fix`. Tool versions are locked as development dependencies so local and CI checks use the same releases. `make check` includes both tools alongside syntax, Composer validation and tests.
