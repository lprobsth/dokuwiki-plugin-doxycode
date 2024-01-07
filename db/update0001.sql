-- Defines the Tasks table that is used to handle doxygen builds
CREATE TABLE Tasks (
    TaskID TEXT PRIMARY KEY,
    State INTEGER NOT NULL,
    Timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    Configuration TEXT -- This column will store the JSON-encoded settings
);

-- Defines the Jobs that hold individual code snippets
CREATE TABLE Jobs (
    JobID TEXT PRIMARY KEY,
    TaskID TEXT NOT NULL,
    Timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    -- Additional fields...
    Configuration TEXT, -- This column will store the JSON-encoded settings
    FOREIGN KEY (TaskID) REFERENCES Tasks(TaskID) ON DELETE CASCADE
);