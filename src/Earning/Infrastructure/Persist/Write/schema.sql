-- Store canonical UUID bytes without UUIDv1 byte swapping.
-- Signed BIGINT preserves exact minor units and permits negative amounts.
CREATE TABLE IF NOT EXISTS earning_lines (
    id BINARY(16) NOT NULL COMMENT 'Earning Line UUID, generated as UUIDv7',
    initial_amount BIGINT NOT NULL COMMENT 'Original calculation in minor units, mirrors the Initial Adjustment',
    system_amount BIGINT NOT NULL COMMENT 'Latest automatic amount in minor units, frozen after the first Manual Adjustment',
    current_amount BIGINT NOT NULL COMMENT 'Sum of all Initial, System and Manual Adjustment amounts in minor units',
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Three-letter currency code shared by all line adjustments',
    manually_adjusted TINYINT UNSIGNED NOT NULL COMMENT 'Permanent manual precedence flag: 0 = automatic, 1 = manually adjusted',
    version INT UNSIGNED NOT NULL COMMENT 'Aggregate version used for optimistic concurrency checks',
    created_at BIGINT NOT NULL COMMENT 'UTC Unix epoch microseconds, including negative pre-1970 timestamps',
    -- UUIDv7 groups recent line keys near each other in the clustered index.
    -- Lookup by id already finds one row, so an extra (id, version) index is unnecessary.
    PRIMARY KEY (id),
    CHECK (CHAR_LENGTH(currency) = 3),
    CHECK (manually_adjusted IN (0, 1)),
    CHECK (version > 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_as_cs
COMMENT = 'Authoritative current Earning Line state';

-- The application maintains line ownership, appends history and enforces the manual freeze.
CREATE TABLE IF NOT EXISTS earning_line_adjustments (
    earning_line_id BINARY(16) NOT NULL COMMENT 'Owning Earning Line UUID',
    sequence INT UNSIGNED NOT NULL COMMENT 'Audit order within the line, starting at 1 independently of timestamps',
    id BINARY(16) NOT NULL COMMENT 'Adjustment UUID, unique within its owning line',
    type ENUM('initial', 'system', 'manual') CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Adjustment Type matching the domain enum backed value',
    amount BIGINT NOT NULL COMMENT 'Initial absolute amount or signed System/Manual delta, in line currency minor units',
    comment TEXT COMMENT 'Manual explanation preserved verbatim, optional for Initial/System',
    author_id BINARY(16) COMMENT 'Author UUID required for Manual, optional for Initial/System',
    recorded_at BIGINT NOT NULL COMMENT 'UTC Unix epoch microseconds when the adjustment was recorded',
    -- A 20-byte clustered key serves WHERE earning_line_id and ORDER BY sequence.
    PRIMARY KEY (earning_line_id, sequence),
    UNIQUE KEY uq_line_adjustment_id (earning_line_id, id),
    CHECK (sequence > 0),
    CONSTRAINT chk_adjustment_amount CHECK (type = 'initial' OR amount <> 0),
    -- SQL checks metadata presence, while the domain also rejects blank comments.
    CONSTRAINT chk_manual_metadata CHECK (type <> 'manual' OR (comment IS NOT NULL AND author_id IS NOT NULL)),
    -- Only Initial may occupy sequence 1, and Initial cannot appear later.
    CONSTRAINT chk_initial_sequence CHECK ((type = 'initial') = (sequence = 1))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_as_cs
COMMENT = 'Earning Line adjustment history appended by the application';
