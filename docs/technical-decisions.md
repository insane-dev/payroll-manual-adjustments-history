# Technical Decisions

## Agreed Scope

A compact Domain-Driven Design, CQRS, and Event Sourcing proof of concept with SQLite persistence, PHPUnit tests, and a command-line demonstration of the assignment scenario.

- One Earning Line aggregate protects the business rules.
- Commands and queries are separate. A separate read database and asynchronous projections are unnecessary for this proof of concept.
- SQLite stores events with atomic appends and an expected stream version check to detect conflicting writes.
- Dependencies are passed explicitly through constructors.
- No UI or application framework is required.

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

Exact dependency versions will be selected for PHP 8.4 compatibility during implementation and recorded in the Composer lock file.
