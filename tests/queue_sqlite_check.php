<?php
/**
 * Standalone check of ScheduledSend\Queue against a real SQLite database.
 * Creates a throwaway DB in /tmp; never touches the live Roundcube database.
 *
 * Usage: sudo -n php queue_sqlite_check.php
 */

define('INSTALL_PATH', '/var/www/roundcubemail/');
require INSTALL_PATH . 'program/include/clisetup.php';
require __DIR__ . '/lib/autoload.php';

use ScheduledSend\Queue;
use ScheduledSend\QueueInterface;

$dbFile = '/tmp/scheduled_send_check_' . getmypid() . '.db';
@unlink($dbFile);

$db = rcube_db::factory('sqlite:///' . $dbFile, '', false);
$db->db_connect('w');

$schema = file_get_contents(__DIR__ . '/SQL/sqlite.initial.sql');
// The driver initializes the full Roundcube schema (including users) when the
// file is empty, so only the queue table has to be added here.
$schema = preg_replace('/^\s*--.*$/m', '', $schema);
foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
    $db->query($stmt);
}

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $fails++;
    }
}

// A MIME-ish payload with every byte value and an embedded NUL, like 8-bit
// attachments and headers produce.
$binary = '';
for ($i = 0; $i < 256; $i++) {
    $binary .= chr($i);
}
$body   = "Content-Type: text/plain\r\n\r\n" . $binary . "\r\n";
$header = "From: tester@example.test\r\nTo: other@example.test\r\nSubject: prüfung 日本語\r\n";

$queue = new Queue($db);

// The schema has a foreign key to users, so the job needs an owner.
$db->query('INSERT INTO users (user_id, username, mail_host, created) VALUES (1, ?, ?, ?)',
    ['tester@example.test', 'localhost', '2026-01-01 00:00:00']);

$id = $queue->add([
    'user_id'       => 1,
    'username'      => 'tester@example.test',
    'send_at'       => Queue::datetime(new DateTimeImmutable('+1 hour')),
    'timezone'      => 'Asia/Yekaterinburg',
    'envelope_from' => 'tester@example.test',
    'recipients'    => json_encode(['other@example.test']),
    'message_id'    => '<check@example.test>',
    'subject'       => 'prüfung',
    'headers'       => $header,
    'bcc'           => null,
    'body'          => $body,
    'size'          => strlen($header) + strlen($body),
    'sent_mailbox'  => 'Sent',
]);

check('add() returns a positive id', $id > 0, "id=$id");

$row = $queue->find($id);
check('find() returns the row', is_array($row));
check('headers survive byte-for-byte', ($row['headers'] ?? null) === $header,
    'len ' . strlen((string) ($row['headers'] ?? '')) . '/' . strlen($header));
check('body survives byte-for-byte (NUL + 8-bit)', ($row['body'] ?? null) === $body,
    'len ' . strlen((string) ($row['body'] ?? '')) . '/' . strlen($body));

$byMessageId = $queue->findByMessageId(1, '<check@example.test>', [QueueInterface::PENDING]);
check('findByMessageId() decodes content too', ($byMessageId['body'] ?? null) === $body);

$usage = $queue->usage(1);
check('usage() counts the job', $usage['count'] === 1, json_encode($usage));
check('usage() sums bytes', $usage['bytes'] === strlen($header) + strlen($body), json_encode($usage));

check('dueIds() sees the future job only when due',
    $queue->dueIds(new DateTimeImmutable(), 10) === []
    && $queue->dueIds(new DateTimeImmutable('+2 hours'), 10) === [$id]);

check('transition() claims the job',
    $queue->transition($id, QueueInterface::PENDING, QueueInterface::SUBMITTING, ['lease_until' => Queue::datetime(new DateTimeImmutable('+5 minutes'))]));
check('transition() is refused twice', !$queue->transition($id, QueueInterface::PENDING, QueueInterface::SUBMITTING));

$db->query('UPDATE ' . $db->table_name('scheduled_send_queue', true) . ' SET `updated_at` = ?', [Queue::datetime(new DateTimeImmutable('-30 days'))]);
check('purge() removes finished jobs', $queue->purge(new DateTimeImmutable('-7 days')) === 0, 'job still submitting');

$rows = $queue->listForUser(1);
check('listForUser() returns the job', count($rows) === 1);
check('listForUser() never exposes content',
    !array_key_exists('body', $rows[0]) && !array_key_exists('headers', $rows[0]));

check('recipients round-trip as JSON', json_decode($rows[0]['recipients'], true) === ['other@example.test']);
check('subject keeps UTF-8', $rows[0]['subject'] === 'prüfung');

unset($db);
@unlink($dbFile);

echo $fails === 0 ? "\nALL CHECKS PASSED\n" : "\n$fails CHECK(S) FAILED\n";
exit($fails === 0 ? 0 : 1);
