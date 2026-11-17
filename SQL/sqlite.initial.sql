-- Scheduled send queue for Roundcube (SQLite).
-- Load into the Roundcube database after Roundcube's own schema.
-- If you use db_prefix, prefix the table name below accordingly.
--
-- The content columns (headers, bcc, body) hold base64 with a "b64:" prefix:
-- PDO's SQLite driver builds a single-quoted literal, so a NUL byte would end
-- the value and truncate the message. Queue.php does the encoding.

CREATE TABLE IF NOT EXISTS "scheduled_send_queue" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "user_id" integer NOT NULL
    REFERENCES "users" ("user_id") ON DELETE CASCADE ON UPDATE CASCADE,
  "username" varchar(128) NOT NULL,
  "status" varchar(16) NOT NULL DEFAULT 'pending',
  "send_at" datetime NOT NULL,
  "timezone" varchar(64) NOT NULL,
  "envelope_from" varchar(255) NOT NULL,
  "recipients" text NOT NULL,
  "message_id" varchar(255) NOT NULL,
  "subject" varchar(255) NOT NULL DEFAULT '',
  "headers" text NOT NULL,
  "bcc" text DEFAULT NULL,
  "body" text NOT NULL,
  "size" integer NOT NULL DEFAULT 0,
  "sent_mailbox" varchar(255) DEFAULT NULL,
  "flag_mailbox" varchar(255) DEFAULT NULL,
  "flag_message_id" varchar(255) DEFAULT NULL,
  "flag_name" varchar(16) DEFAULT NULL,
  "attempts" integer NOT NULL DEFAULT 0,
  "last_error" text DEFAULT NULL,
  "lease_until" datetime DEFAULT NULL,
  "created_at" datetime NOT NULL,
  "updated_at" datetime NOT NULL,
  "submitted_at" datetime DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS "scheduled_send_due_idx" ON "scheduled_send_queue" ("status", "send_at");
CREATE INDEX IF NOT EXISTS "scheduled_send_user_idx" ON "scheduled_send_queue" ("user_id", "send_at");
