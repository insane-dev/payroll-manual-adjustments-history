CREATE TABLE IF NOT EXISTS earning_line_events (
    stream_id TEXT NOT NULL,
    version INTEGER NOT NULL CHECK (version > 0),
    event_type TEXT NOT NULL,
    payload TEXT NOT NULL CHECK (json_valid(payload)),
    PRIMARY KEY (stream_id, version)
);

CREATE TRIGGER IF NOT EXISTS earning_line_events_no_update
BEFORE UPDATE ON earning_line_events
BEGIN
    SELECT RAISE(ABORT, 'Saved events cannot be updated');
END;

CREATE TRIGGER IF NOT EXISTS earning_line_events_no_delete
BEFORE DELETE ON earning_line_events
BEGIN
    SELECT RAISE(ABORT, 'Saved events cannot be deleted');
END;

-- REPLACE can bypass DELETE triggers unless recursive triggers are enabled on the writer's connection.
CREATE TRIGGER IF NOT EXISTS earning_line_events_no_replace
BEFORE INSERT ON earning_line_events
WHEN EXISTS (
    SELECT 1 FROM earning_line_events WHERE stream_id = NEW.stream_id AND version = NEW.version
)
BEGIN
    SELECT RAISE(ABORT, 'Saved events cannot be replaced');
END;
