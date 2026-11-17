<?php

namespace ScheduledSend;

/**
 * Queue stored in the Roundcube database (table scheduled_send_queue).
 */
class Queue implements QueueInterface
{
    private const COLUMNS = [
        'user_id', 'username', 'status', 'send_at', 'timezone', 'envelope_from', 'recipients',
        'message_id', 'subject', 'headers', 'bcc', 'body', 'size', 'sent_mailbox',
        'flag_mailbox', 'flag_message_id', 'flag_name', 'attempts', 'last_error',
        'lease_until', 'created_at', 'updated_at', 'submitted_at',
    ];

    private const LIST_COLUMNS = [
        'id', 'status', 'send_at', 'timezone', 'recipients', 'subject', 'size',
        'attempts', 'last_error', 'created_at', 'updated_at', 'submitted_at',
    ];

    /** Statuses that purge() may delete. */
    private const FINISHED = [self::DONE, self::CANCELLED, self::FAILED];

    /** Columns whose content may contain arbitrary bytes (8-bit MIME, NUL). */
    private const BINARY_COLUMNS = ['headers', 'bcc', 'body'];

    /** @var \rcube_db */
    private $db;

    /** @var string */
    private $table;

    /** @var bool Store binary columns base64-encoded (SQLite) */
    private $base64Content;

    public function __construct(\rcube_db $db)
    {
        $this->db    = $db;
        $this->table = $db->table_name('scheduled_send_queue', true);

        // PDO's SQLite driver builds a single-quoted literal, so a NUL byte ends the
        // value: content columns would be truncated silently. MySQL/MariaDB use real
        // blob columns and keep the bytes as they are.
        $this->base64Content = $db->db_provider === 'sqlite';
    }

    public static function datetime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function add(array $job): int
    {
        $now = self::datetime(new \DateTimeImmutable());
        $job += ['status' => self::PENDING, 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now];
        $job  = $this->filter($job);

        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_map([$this->db, 'quote_identifier'], array_keys($job))),
            implode(', ', array_fill(0, count($job), '?'))
        );

        $this->query($sql, array_values($this->encodeContent($job)));

        return (int) $this->db->insert_id('scheduled_send_queue');
    }

    public function find(int $id, ?int $userId = null): ?array
    {
        $sql    = "SELECT * FROM {$this->table} WHERE `id` = ?";
        $params = [$id];

        if ($userId !== null) {
            $sql     .= ' AND `user_id` = ?';
            $params[] = $userId;
        }

        $row = $this->db->fetch_assoc($this->query($sql, $params));

        return $row ? $this->decodeContent($row) : null;
    }

    public function listForUser(int $userId): array
    {
        $columns = implode(', ', array_map([$this->db, 'quote_identifier'], self::LIST_COLUMNS));
        $result  = $this->query("SELECT $columns FROM {$this->table} WHERE `user_id` = ? ORDER BY `send_at` DESC", [$userId]);
        $rows    = [];

        while ($row = $this->db->fetch_assoc($result)) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function usage(int $userId): array
    {
        $sql = sprintf('SELECT COUNT(*) AS `count`, COALESCE(SUM(`size`), 0) AS `bytes` FROM %s WHERE `user_id` = ? AND `status` IN (%s)',
            $this->table, $this->db->array2list(self::HOLDING_CONTENT));

        $row = $this->db->fetch_assoc($this->query($sql, [$userId]));

        return ['count' => (int) $row['count'], 'bytes' => (int) $row['bytes']];
    }

    public function findByMessageId(int $userId, string $messageId, array $statuses): ?array
    {
        $sql = sprintf('SELECT * FROM %s WHERE `user_id` = ? AND `message_id` = ? AND `status` IN (%s) ORDER BY `id` DESC',
            $this->table, $this->db->array2list($statuses));

        $row = $this->db->fetch_assoc($this->db->limitquery($sql, 0, 1, $userId, $messageId));

        return $row ? $this->decodeContent($row) : null;
    }

    public function transition(int $id, string $from, string $to, array $set = [], ?int $userId = null): bool
    {
        $set = $this->filter(['status' => $to, 'updated_at' => self::datetime(new \DateTimeImmutable())] + $set);
        $set = $this->encodeContent($set);

        $assignments = implode(', ', array_map(function ($column) {
            return $this->db->quote_identifier($column) . ' = ?';
        }, array_keys($set)));

        $sql    = "UPDATE {$this->table} SET $assignments WHERE `id` = ? AND `status` = ?";
        $params = array_merge(array_values($set), [$id, $from]);

        if ($userId !== null) {
            $sql     .= ' AND `user_id` = ?';
            $params[] = $userId;
        }

        return $this->db->affected_rows($this->query($sql, $params)) === 1;
    }

    public function dueIds(\DateTimeImmutable $now, int $limit): array
    {
        $sql    = "SELECT `id` FROM {$this->table} WHERE `status` = ? AND `send_at` <= ? ORDER BY `send_at`, `id`";
        $result = $this->db->limitquery($sql, 0, $limit, self::PENDING, self::datetime($now));

        return $this->ids($result);
    }

    public function expiredLeaseIds(string $status, \DateTimeImmutable $now): array
    {
        $sql = "SELECT `id` FROM {$this->table} WHERE `status` = ? AND `lease_until` < ? ORDER BY `id`";

        return $this->ids($this->query($sql, [$status, self::datetime($now)]));
    }

    public function purge(\DateTimeImmutable $before): int
    {
        $sql = sprintf('DELETE FROM %s WHERE `status` IN (%s) AND `updated_at` < ?',
            $this->table, $this->db->array2list(self::FINISHED));

        return (int) $this->db->affected_rows($this->query($sql, [self::datetime($before)]));
    }

    private function ids($result): array
    {
        $ids = [];

        while ($row = $this->db->fetch_assoc($result)) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    private function filter(array $columns): array
    {
        $unknown = array_diff(array_keys($columns), self::COLUMNS);

        if (!empty($unknown)) {
            throw new \InvalidArgumentException('Unknown queue columns: ' . implode(', ', $unknown));
        }

        return $columns;
    }

    /**
     * Encode content columns for storage. Only rows that are read back as whole
     * messages (find/findByMessageId) need decoding, so no row is marked: the
     * base64 is recognised by its own shape when the job is read.
     */
    private function encodeContent(array $row): array
    {
        if (!$this->base64Content) {
            return $row;
        }

        foreach (self::BINARY_COLUMNS as $column) {
            if (isset($row[$column]) && is_string($row[$column]) && $row[$column] !== '') {
                $row[$column] = 'b64:' . base64_encode($row[$column]);
            }
        }

        return $row;
    }

    /**
     * Reverse of encodeContent(). A no-op where content is stored verbatim, so
     * a message that happens to start with "b64:" is never mangled.
     */
    private function decodeContent(array $row): array
    {
        if (!$this->base64Content) {
            return $row;
        }

        foreach (self::BINARY_COLUMNS as $column) {
            if (isset($row[$column]) && is_string($row[$column])
                && strncmp($row[$column], 'b64:', 4) === 0
            ) {
                $decoded = base64_decode(substr($row[$column], 4), true);

                if ($decoded !== false) {
                    $row[$column] = $decoded;
                }
            }
        }

        return $row;
    }

    private function query(string $sql, array $params)
    {
        $result = $this->db->query($sql, $params);

        if ($error = $this->db->is_error($result)) {
            throw new \RuntimeException("Queue query failed: $error");
        }

        return $result;
    }
}
