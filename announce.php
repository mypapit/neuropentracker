<?php
/*
 * Neuropentracker
 * Summary: Handles BitTorrent announce requests and returns peer lists.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 * Compatible with PHP 7.4 through PHP 8.3.
 */

require_once __DIR__ . '/tracker.inc.php';

header('Content-Type: text/plain');

$event = $_GET['event'] ?? '';
$validEvent = $event === '' || in_array($event, array('started', 'completed', 'stopped'), true);
$infoHash = (string) ($_GET['info_hash'] ?? '');
$peerId = (string) ($_GET['peer_id'] ?? '');
$port = $_GET['port'] ?? null;
$uploaded = $_GET['uploaded'] ?? null;
$downloaded = $_GET['downloaded'] ?? null;
$left = $_GET['left'] ?? null;

if (
    strlen($infoHash) !== 20 ||
    strlen($peerId) !== 20 ||
    $port === null ||
    !ctype_digit((string) $port) ||
    (int) $port < 1 ||
    (int) $port > 65535 ||
    $uploaded === null ||
    !ctype_digit((string) $uploaded) ||
    $downloaded === null ||
    !ctype_digit((string) $downloaded) ||
    $left === null ||
    !ctype_digit((string) $left) ||
    !$validEvent
) {
    tracker_failure('invalid request (see http://bitconjurer.org/BitTorrent/protocol.html)');
}

$requireProtocol = (string) tracker_config('require_announce_protocol', 'standard');
if ($requireProtocol === 'no_peer_id') {
    if (empty($_GET['compact']) && empty($_GET['no_peer_id'])) {
        tracker_failure('standard announces not allowed; use no_peer_id or compact option');
    }
} elseif ($requireProtocol === 'compact') {
    if (empty($_GET['compact'])) {
        tracker_failure('tracker requires use of compact option');
    }
}

$requestedIp = (string) ($_GET['ip'] ?? tracker_client_ip());
$ip = tracker_resolve_ip($requestedIp);
if ($ip === false) {
    tracker_failure('unable to resolve host name ' . $requestedIp);
}

$accessReason = null;
if (!tracker_access_allowed(long2ip($ip), $peerId, $accessReason)) {
    tracker_failure($accessReason ?? 'access denied');
}

$announceInterval = tracker_announce_interval();
$expireSeconds = $event === 'stopped' ? 0 : (int) ceil($announceInterval * (float) tracker_config('expire_factor', 1.2));
$table = tracker_table_name();

tracker_stmt(
    "REPLACE INTO $table
        (`info_hash`, `ip`, `port`, `peer_id`, `uploaded`, `downloaded`, `left`, `expire_time`)
     VALUES
        (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))",
    'siissssi',
    array(
        $infoHash,
        $ip,
        (int) $port,
        $peerId,
        (string) $uploaded,
        (string) $downloaded,
        (string) $left,
        $expireSeconds,
    )
);

$numwant = isset($_GET['numwant']) && ctype_digit((string) $_GET['numwant']) ? (int) $_GET['numwant'] : 50;
$numwant = max(0, min($numwant, 200));

$stmt = tracker_stmt(
    "SELECT `ip`, `port`, `peer_id`
       FROM $table
      WHERE `info_hash` = ?
        AND `expire_time` > NOW()
      ORDER BY RAND()
      LIMIT ?",
    'si',
    array($infoHash, $numwant)
);
$result = tracker_stmt_result($stmt);

if (!empty($_REQUEST['compact'])) {
    $peers = '';
    while ($row = $result->fetch_assoc()) {
        $peers .= pack('Nn', (int) $row['ip'], (int) $row['port']);
    }
} elseif (!empty($_REQUEST['no_peer_id'])) {
    $peers = array();
    while ($row = $result->fetch_assoc()) {
        $peers[] = array('ip' => long2ip((int) $row['ip']), 'port' => (int) $row['port']);
    }
} else {
    $peers = array();
    while ($row = $result->fetch_assoc()) {
        $peers[] = array(
            'ip' => long2ip((int) $row['ip']),
            'port' => (int) $row['port'],
            'peer id' => $row['peer_id'],
        );
    }
}

$result->free();
$stmt->close();

exit(bencode(tracker_response_with_id(array(
    'interval' => $announceInterval,
    'peers' => $peers,
))));
