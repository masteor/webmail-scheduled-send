<?php
/**
 * Proves the SQLite port is a no-op on MySQL/MariaDB: with db_provider = mysql
 * the Queue must store content verbatim (no "b64:" prefix, no decoding), so
 * existing installations behave exactly as before.
 *
 * No MySQL server is needed: a stub db object records the parameters that
 * reach the driver, which is where the encoding decision happens.
 *
 * Usage: php tests/queue_mysql_noop_check.php
 */

define('INSTALL_PATH', getenv('ROUNDCUBE_PATH') ? rtrim(getenv('ROUNDCUBE_PATH'), '/') . '/' : '/var/www/roundcubemail/');
require INSTALL_PATH . 'program/include/clisetup.php';

require dirname(__DIR__) . '/lib/autoload.php';

use ScheduledSend\Queue;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $fails++;
    }
}

/** Records what would be sent to the database. */
class RecordingDb extends rcube_db
{
    public $captured = [];
    public $lastInsertId = 7;

    public function __construct()
    {
        // Skip rcube_db::__construct(): it wants a live Roundcube config.
        $this->db_provider = 'mysql';
    }

    public function table_name($table, $quoted = false)
    {
        return $quoted ? "\"$table\"" : $table;
    }

    public function quote_identifier($str)
    {
        return "\"$str\"";
    }

    public function array2list($arr, $type = null)
    {
        return implode(',', array_map(function ($v) { return "'" . $v . "'"; }, (array) $arr));
    }

    public function quote($input, $type = null)
    {
        return "'" . str_replace("'", "''", (string) $input) . "'";
    }

    public function query($sql, ...$params)
    {
        $this->captured[] = ['sql' => $sql, 'params' => $params];
        return true;
    }

    public function is_error($result = null)
    {
        return null;
    }

    public function fetch_assoc($result = null)
    {
        return false;
    }

    public function affected_rows($result = null)
    {
        return 1;
    }

    public function insert_id($table = '')
    {
        return $this->lastInsertId;
    }
}

// A body with a NUL byte and 8-bit content: the exact payload that SQLite
// truncated before the fix.
$binary = '';
for ($i = 0; $i < 256; $i++) {
    $binary .= chr($i);
}
$body    = "Content-Type: application/octet-stream\r\n\r\n" . $binary;
$headers = "From: a@example.test\r\nSubject: prüfung\r\n";

$db = new RecordingDb();
$queue = new Queue($db);

$id = $queue->add([
    'user_id'       => 1,
    'username'      => 'a@example.test',
    'send_at'       => Queue::datetime(new DateTimeImmutable('+1 hour')),
    'timezone'      => 'UTC',
    'envelope_from' => 'a@example.test',
    'recipients'    => json_encode(['b@example.test']),
    'message_id'    => '<mysql-check@example.test>',
    'subject'       => 'prüfung',
    'headers'       => $headers,
    'bcc'           => null,
    'body'          => $body,
    'size'          => strlen($headers) + strlen($body),
    'sent_mailbox'  => 'Sent',
]);

$insert = null;
foreach ($db->captured as $q) {
    if (stripos($q['sql'], 'INSERT') === 0) {
        // Queue::query() passes the values as a single array argument.
        $insert = $q['params'][0] ?? $q['params'];
    }
}
check('add() issues an INSERT', $insert !== null);
check('add() returns the driver insert id', $id === 7, "id=$id");

// Column order in the INSERT tells which value is which.
preg_match('/\(([^)]*)\)\s*VALUES/i', $db->captured[0]['sql'], $m);
$columns   = array_map(function ($c) { return trim($c, "\" `"); }, explode(',', $m[1]));
$bodyIndex = array_search('body', $columns, true);
$storedBody = $bodyIndex === false ? null : $insert[$bodyIndex];

check('body is stored verbatim on MySQL (no b64: prefix)',
    $storedBody === $body && strncmp((string) $storedBody, 'b64:', 4) !== 0,
    'len ' . strlen((string) $storedBody) . '/' . strlen($body));
check('headers are stored verbatim on MySQL',
    ($insert[array_search('headers', $columns, true)] ?? null) === $headers);

// Reads must not try to decode on MySQL either: a body that happens to start
// with "b64:" is content, not an encoded value.
$b64Looking = "b64:this is literal message content, not base64";
$db2 = new class extends RecordingDb {
    public $row;
    public function fetch_assoc($result = null) { return $this->row; }
    public function limitquery($query, $offset, $limit, ...$params) { return true; }
};
$db2->row = [
    'id' => 1, 'headers' => $headers, 'bcc' => null, 'body' => $b64Looking,
];
$row = (new Queue($db2))->find(1);
check('find() does not decode a "b64:"-looking body on MySQL',
    ($row['body'] ?? null) === $b64Looking);

echo $fails === 0 ? "\nMYSQL PATH UNCHANGED\n" : "\n$fails CHECK(S) FAILED\n";
exit($fails === 0 ? 0 : 1);
