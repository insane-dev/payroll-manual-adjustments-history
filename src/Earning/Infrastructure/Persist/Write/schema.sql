CREATE TABLE IF NOT EXISTS earning_lines (
    id BLOB NOT NULL PRIMARY KEY CHECK (length(id) = 16),
    initial_amount INTEGER NOT NULL,
    system_amount INTEGER NOT NULL,
    current_amount INTEGER NOT NULL,
    currency TEXT NOT NULL CHECK (length(currency) = 3),
    manually_adjusted INTEGER NOT NULL CHECK (manually_adjusted IN (0, 1)),
    version INTEGER NOT NULL CHECK (version > 0),
    created_at INTEGER NOT NULL
) STRICT, WITHOUT ROWID;

CREATE TABLE IF NOT EXISTS earning_line_adjustments (
    earning_line_id BLOB NOT NULL REFERENCES earning_lines(id),
    id BLOB NOT NULL CHECK (length(id) = 16),
    sequence INTEGER NOT NULL CHECK (sequence > 0),
    type INTEGER NOT NULL CHECK (type IN (0, 1, 2)),
    amount INTEGER NOT NULL CHECK (type = 0 OR amount <> 0),
    comment TEXT,
    author_id BLOB CHECK (author_id IS NULL OR length(author_id) = 16),
    recorded_at INTEGER NOT NULL,
    CHECK (type <> 2 OR (comment IS NOT NULL AND author_id IS NOT NULL)),
    CHECK ((type = 0) = (sequence = 1)),
    PRIMARY KEY (earning_line_id, id),
    UNIQUE (earning_line_id, sequence)
) STRICT, WITHOUT ROWID;
