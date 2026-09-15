# Technical Decisions

## Agreed Scope

This branch, `feat/earning-line-history-no-es`, explores a state-based DDD and CQRS model with SQLite. The Event Sourcing implementation remains in `feat/earning-line-history` for comparison.

- One Earning Line aggregate owns salary-line state and immutable Earning Line Adjustments.
- Adjustment Type is Initial, System or Manual. Initial records the starting amount; System and Manual store signed deltas; automatic recalculation commands accept absolute amounts.
- The first Manual Adjustment permanently freezes System Amount and prevents further System Adjustments.
- Commands and queries are separate; reads use the same repository without a separate projection.
- Explicit constructor injection, PHP 8.4+, CLI demonstration, and PHPUnit remain in scope.

The agreed vocabulary is in [Ubiquitous Language](ubiquitous-language.md); the original requirements are in [the assignment](task-source/alcor-code-assignment.md).

## State and History Storage

`earning_lines` stores `id`, `initial_amount`, `system_amount`, `current_amount`, `currency`, `manually_adjusted`, `version`, and `created_at`. This is authoritative current state, not a rebuildable event projection.

`earning_line_adjustments` stores `earning_line_id`, `id`, `sequence`, `type`, `amount`, `comment`, `author_id`, and `recorded_at`. Entries are immutable audit records, not domain events. Creation records an Initial Adjustment at sequence 1 with the original amount. Later automatic changes are System Adjustments. The Initial Amount field on the line mirrors the Initial entry. Current Amount equals the sum of all entries, with no extra baseline added.

Amounts use signed 64-bit INTEGER minor units. MoneyPHP still performs exact arithmetic; the mapper checks every amount and delta against −9223372036854775808…9223372036854775807 before casting to a PHP integer. An overflow rejects the save and rolls back all changes. Arbitrarily large domain amounts are therefore not necessarily persistable. No floating-point monetary conversion or SQL SUM is used.

UUIDs use 16-byte BLOB values instead of 36-character text. Adjustment Type uses integer codes: Initial = 0, System = 1, Manual = 2. UTC timestamps use signed INTEGER Unix epoch microseconds, preserving microsecond precision including dates before 1970. Currency is stored only on the line and supplied to adjustments when loading. PDO parameters are explicitly bound as integer, BLOB, text or NULL.

Both tables are STRICT and WITHOUT ROWID. STRICT prevents a monetary overflow from silently becoming REAL; WITHOUT ROWID avoids a hidden row identifier alongside the existing primary key. SQLite INTEGER is already signed and uses variable-length storage, so declaring TINYINT or BIGINT would not save space. See [SQLite storage classes](https://www.sqlite.org/datatype3.html). Line state is restored directly; no event replay, Event Store, event serializer, or domain event classes remain.

Exactly one Initial entry is required at the start: a CHECK constraint reserves sequence 1 for Initial and uniqueness prevents duplicates. The aggregate creates the Initial entry and the repository appends subsequent entries in contiguous order. Initial entry and line creation are persisted in the same transaction.

A repository save acquires `BEGIN IMMEDIATE`, compares the persisted version, saves the line state and appends pending adjustments in one transaction. Any failure rolls back both tables and retains pending adjustments for retry. Stale writers raise `ConcurrentStreamWrite`; callers reload and reconsider their command. Reads use a database snapshot transaction so the state and history cannot come from different committed versions.

Foreign keys prevent orphan adjustments. Unique keys protect line-scoped adjustment IDs and sequence order. There are no triggers. The domain enforces immutable adjustments and permanent manual precedence; the repository only inserts pending adjustments and never updates or deletes saved history. Direct SQL mutation is outside this application-level guarantee. CHECK constraints validate storage structure, including type codes, nonzero System/Manual amounts, Manual metadata presence and the Initial sequence.

Initial and System comments/authors are optional; automatic recalculations currently leave them absent. Manual adjustments require nonzero amounts, nonblank comments and an author. An unchanged system amount creates no zero adjustment. Initial entries may be zero, preserving support for a zero salary calculation. Currency is fixed per line; negative values are allowed.

The default database is `var/payroll-no-es-compact.sqlite`. Previous experiment databases (`var/payroll-no-es-initial.sqlite`, `var/payroll-no-es.sqlite` and the ES `var/payroll.sqlite`) are preserved. The compact schema requires a fresh database; no automatic migration is implemented. A migration would need to validate signed 64-bit ranges, convert UUIDs to binary, timestamps to epoch microseconds and types to integer codes, and verify each adjustment currency matches its line before removing the duplicate column.

## Runtime and Dependencies

| Component         | Choice           | Purpose                                                              |
|-------------------|------------------|----------------------------------------------------------------------|
| PHP               | 64-bit 8.4+      | Application runtime                                                  |
| Money             | `moneyphp/money` | Exact monetary values and arithmetic without floating-point amounts  |
| Date and Time     | `nesbot/carbon`  | Date and time handling; use immutable values for recorded timestamps |
| Identifiers       | `ramsey/uuid`    | UUID generation and validation                                       |
| Persistence       | SQLite 3.37+     | Local state and adjustment persistence                                              |
| Tests             | PHPUnit          | Business-rule and persistence verification                           |
| Local Environment | Docker Compose   | Reproducible runtime and development commands                        |
| Command Shortcuts | Makefile         | Convenient entry points for routine development tasks                |

Exact dependency versions are recorded in `composer.lock`, with dependency resolution targeting PHP 8.4.

## Module and Layer Structure

### Naming and Enum Conventions

- Domain terms use Title Case in documentation; class, interface and enum type names use PascalCase.
- Methods and properties use camelCase.
- Dependency properties describe the domain capability they provide. Aggregate repositories use plural domain names, such as `$earningLines` for `EarningLineRepository`; mappers identify the mapped concept, such as `$earningLineMapper`. Use the same names when wiring dependencies and in tests. Small, established names such as `$clock` and `$connection` remain appropriate when their purpose is clear.
- Enum case names use UPPERCASE; compound names use UPPER_SNAKE_CASE. For example: `EarningLineAdjustmentType::MANUAL` and `EarningLineAdjustmentType::SYSTEM`.
- Backed values are a separate storage/API contract and need not be uppercase. Domain backed values remain `initial`, `system` and `manual`. The infrastructure mapper translates them to stable database codes `0`, `1` and `2`; these codes must not be reassigned to different meanings.
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

| Component | Location |
| --- | --- |
| Clock and implementation | `Shared/Application/Clock.php`, `Shared/Infrastructure/Clock/CarbonClock.php` |
| Earning Line and Earning Line Adjustment | `Earning/Domain/Entity/` |
| Identifiers and Adjustment Type enum | `Earning/Domain/Value/` |
| Repository interface | `Earning/Domain/Repository/EarningLineRepository.php` |
| Commands and handlers | `Earning/Application/Command/` and `Command/Handler/` |
| Queries, handlers and result DTOs | `Earning/Application/Query/` |
| State/history mapper | `Earning/Infrastructure/Mapper/EarningLineMapper.php` |
| SQLite repository and schema | `Earning/Infrastructure/Persist/Write/` |
| CLI command | `Earning/UI/Cli/DemoCommand.php` |

`Persist/Write` includes loading and saving the authoritative aggregate state. The history query uses the same repository. Add `Persist/Read` only if a dedicated query adapter is needed; CQRS does not require two databases or duplicated persistence implementations.

### Tests and Compatibility

Tests are organized by test category first, then by module and layer. Each category has its own PHPUnit suite:

| Category    | Location                                                  | Boundary tested                                                                            |
|-------------|-----------------------------------------------------------|--------------------------------------------------------------------------------------------|
| Unit        | `tests/Unit/Earning/Domain/Entity/`                       | Domain rules and typed adjustment arithmetic in memory, without database, filesystem or subprocess access |
| Integration | `tests/Integration/Earning/Application/`                  | Command/query handlers working together with the real SQLite repository                    |
| Integration | `tests/Integration/Earning/Infrastructure/Persist/Write/` | State/history mapping, SQLite transactions, concurrency, compact types, integer bounds and repository history preservation        |
| Acceptance  | `tests/Acceptance/Earning/UI/`                            | The complete CLI scenario and persisted history retrieval through separate processes       |

Use `make test-unit`, `make test-integration`, or `make test-acceptance` to run an individual category. `make test` runs all three. Local Composer equivalents are `composer test:unit`, `composer test:integration`, and `composer test:acceptance`.

Classify tests by the boundary they exercise, rather than the layer of the class under test. The application workflow is an Integration test because it uses actual persistence; the CLI test is Acceptance because it observes the executable's behavior from outside the application. Shared tests follow the same convention under `tests/<Category>/Shared/` when needed.

The CLI commands and assignment's expected sums remain the same. This branch changes both storage schema and history semantics: Initial and System Adjustments are explicit audit entries. Use the separate default database or explicitly supply a new path when switching branches.


## Continuous Integration and Code Quality

GitHub Actions checks every push and pull request on PHP 8.4, matching the Docker runtime and Composer platform. A three-job matrix runs PHPStan, PHP-CS-Fixer and all PHPUnit suites independently so a failure in one check does not hide another result. Jobs have read-only repository permissions, a ten-minute timeout and cancellation of superseded runs. Dependencies are installed from the lockfile, with Composer download caching.

PHPStan level 8 covers application code, the CLI composition root and tests. Its PHPUnit extension understands assertions and test contracts. There is no baseline or ignored-error list. PHPDoc refines types that PHP cannot express directly, such as numeric strings, ordered lists and data-provider tuples. Repository reads are marked impure because committed database state can change between calls.

PHP-CS-Fixer enables PSR-12 followed by PER Coding Style 2.0, with PER rules taking precedence where the sets overlap. It also requires strict type declarations, unused-import removal and alphabetical imports. `multiline_promoted_properties` uses `minimum_number_of_parameters: 1` so every promoted constructor property appears on its own line, including a single promoted property. CI uses dry-run mode; developers apply changes explicitly with `make cs-fix` or `composer cs-fix`. Tool versions are locked as development dependencies so local and CI checks use the same releases. `make check` includes both tools alongside syntax, Composer validation and tests.
