# MySQL Storage Implementation Plan

**Goal:** Replace SQLite with MySQL 8.4/InnoDB while preserving payroll rules, exact amounts and append-only history through the application API.

**Architecture:** Keep the aggregate and mapper. Use native PDO MySQL, atomic optimistic state updates and transactional adjustment inserts. Separate schema installation from repository construction because MySQL DDL implicitly commits.

**Constraints:** PHP 8.4+, signed 64-bit amounts, UUIDv7 generation and BINARY(16) storage, no triggers or foreign keys, domain-specific dependency names, one promoted property per line. No conversion or deletion of previous SQLite data.

- [x] Replace the schema with InnoDB tables: signed BIGINT amounts/time, BINARY(16) identifiers, ENUM type and TINYINT UNSIGNED flag and INT UNSIGNED version/sequence. Cluster adjustments by line/sequence and enforce line/adjustment identity uniqueness.
- [x] Replace the SQLite repository with MysqlEarningLineRepository. Use version-conditional UPDATE, translate parent duplicate inserts to ConcurrentEarningLineWrite and load state/history in REPEATABLE READ snapshots. Roll back all failed writes and retain pending changes.
- [x] Add shared PDO connection configuration and explicit Earning schema setup. Wire the CLI without storage logic in UI.
- [x] Port tests to disposable MySQL databases. Preserve boundary, stale-writer, precision, failure/retry and CLI tests; test index selection and storage constraints.
- [x] Add MySQL to Compose and CI, install pdo_mysql, and update Composer/Make commands.
- [x] Run formatting, complete make check, actionlint and the CLI scenario. Document compact types, indexes, transactions, test isolation and UUIDv7's clustered-index locality.

**Suggested commit:** `feat: replace SQLite persistence with optimized MySQL storage`

**Verification:** Docker make check passed (40 tests, 153 assertions); PHPStan and PHP-CS-Fixer clean; actionlint passed; MySQL CLI scenario returned USD 1104.45. Read-only review found no actionable correctness issues.
