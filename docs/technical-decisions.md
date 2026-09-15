# Technical Decisions

## Agreed Scope

A compact Domain-Driven Design, CQRS, and Event Sourcing proof of concept with SQLite persistence, PHPUnit tests, and a command-line demonstration of the assignment scenario.

- One Earning Line aggregate protects the business rules.
- Commands and queries are separate. A separate read database and asynchronous projections are unnecessary for this proof of concept.
- SQLite stores events with atomic appends and an expected stream version check to detect conflicting writes.
- Dependencies are passed explicitly through constructors.
- No graphical UI or application framework is required. CLI entry points belong to the UI layer.

The agreed domain vocabulary is defined in [Ubiquitous Language](ubiquitous-language.md). The original requirements are in [the assignment](task-source/alcor-code-assignment.md).

## Runtime and Dependencies

| Component | Choice | Purpose |
| --- | --- | --- |
| PHP | 8.4+ | Application runtime |
| Money | `moneyphp/money` | Exact monetary values and arithmetic without floating-point amounts |
| Date and Time | `nesbot/carbon` | Date and time handling; use immutable values for recorded timestamps |
| Identifiers | `ramsey/uuid` | UUID generation and validation |
| Persistence | SQLite | Local event persistence |
| Tests | PHPUnit | Business-rule and persistence verification |
| Local Environment | Docker Compose | Reproducible runtime and development commands |
| Command Shortcuts | Makefile | Convenient entry points for routine development tasks |

Exact dependency versions are recorded in `composer.lock`, with dependency resolution targeting PHP 8.4.

## Module and Layer Structure

Organize code by business module first, then by architectural layer. The current module is **Earning**, which owns Earning Lines and their Manual Adjustments. `Shared` contains reusable capabilities that have no dependency on any business module.

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
            └── Write/            # Aggregate repositories and event persistence
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

| Component | Location | Reason |
| --- | --- | --- |
| Clock contract | `Shared/Application/Clock.php` | Business-independent source of time |
| Carbon clock | `Shared/Infrastructure/Clock/CarbonClock.php` | Implementation of the shared clock contract |
| Earning Line, Manual Adjustment | `Earning/Domain/Entity/` | Both have identity; Earning Line is the aggregate root |
| Line, adjustment and author IDs | `Earning/Domain/Value/` | Immutable, module-specific Value Objects |
| Domain events | `Earning/Domain/Event/` | Facts belonging to the Earning module |
| Earning Line Repository | `Earning/Domain/Repository/EarningLineRepository.php` | Interface for loading and saving an aggregate |
| Command handlers | `Earning/Application/Command/Handler/` | Use-case orchestration |
| History query and handler | `Earning/Application/Query/` and `Query/Handler/` | Read use case |
| Adjustment History | `Earning/Application/Query/Result/AdjustmentHistory.php` | Read DTO, not a domain entity |
| Event Store contract | `Earning/Application/Port/EventStore.php` | Technical persistence port typed to this module's IDs and events |
| Event serializer | `Earning/Infrastructure/Mapper/EventSerializer.php` | Maps domain events to versioned JSON and back |
| Event-sourced repository, SQLite Event Store and schema | `Earning/Infrastructure/Persist/Write/` | Persistence of the authoritative aggregate event stream |
| Demo command | `Earning/UI/Cli/DemoCommand.php` | CLI arguments, scenario execution through handlers, and formatted history |

### CQRS and Persistence Folders

`Persist/Write` identifies persistence of the write model; it includes loading aggregates for command handling and replay. It does not mean that every method only performs SQL writes.

For this PoC, the history query also reads through the domain repository and builds its Result from the reconstituted aggregate. There is no separate read adapter yet, so `Persist/Read` is not created. Introduce it when a query needs its own SQL projection or read model. CQRS does not by itself require two databases, two stores, or duplicated mapping logic.

### Tests and Compatibility

Tests mirror the module and relevant layer beneath `tests/Earning/`, including CLI acceptance tests in `UI/`. Shared tests belong under `tests/Shared/` when a shared component has behavior requiring its own tests.

This restructuring changes PHP namespaces and code placement. It does not change stable event names, JSON payloads, the SQLite schema, monetary behavior, or existing CLI commands. Previously persisted histories remain readable.
