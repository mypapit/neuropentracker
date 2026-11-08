<?php
/*
 * Neuropentracker
 * Summary: Handles BitTorrent announce requests and returns peer lists.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 * Requires PHP 8.3 or 8.4 (tested on 8.3.36 and 8.4.27).
 */

require_once __DIR__ . '/tracker.inc.php';

header('Content-Type: text/plain');
tracker_security_headers();

$event = $_GET['event'] ?? '';
$validEvent = $event === '' || in_array($event, array('started', 'completed', 'stopped'), true);
$infoHash = is_string($_GET['info_hash'] ?? null) ? (string) $_GET['info_hash'] : '';
$peerId = is_string($_GET['peer_id'] ?? null) ? (string) $_GET['peer_id'] : '';
$port = $_GET['port'] ?? null;
$uploaded = $_GET['uploaded'] ?? null;
$downloaded = $_GET['downloaded'] ?? null;
$left = $_GET['left'] ?? null;

/*
 * Byte counters land in BIGINT UNSIGNED. Bound them by length here so an absurd
 * value is reported as a bad request instead of surfacing to the client as a
 * generic "database error".
 */
$counterOk = function ($value): bool {
    if (!is_string($value) && !is_int($value)) {
        return false;
    }
    $value = (string) $value;

    return ctype_digit($value) && strlen($value) <= 20;
};

if (
    !$validEvent ||
    strlen($infoHash) !== 20 ||
    strlen($peerId) !== 20 ||
    !is_string($port) ||
    !ctype_digit($port) ||
    (int) $port < 1 ||
    (int) $port > 65535 ||
    !$counterOk($uploaded) ||
    !$counterOk($downloaded) ||
    !$counterOk($left)
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

/*
 * Resolve addresses. The socket address is authoritative for access control; a
 * ?ip= claim is honoured only when the request came from a trusted proxy.
 */
list($socketIp, $claimedIp) = tracker_announce_addresses();

if ($claimedIp === 0) {
    tracker_failure('invalid ip parameter');
}

$accessReason = null;
if (!tracker_access_allowed(long2ip($socketIp), $peerId, $accessReason)) {
    tracker_failure($accessReason ?? 'access denied');
}

$ip = $claimedIp;
$announceInterval = tracker_announce_interval();
$table = tracker_table_name();

/*
 * Refuse a new peer when the tracker is at capacity, with a message that tells
 * the operator what to do. Without this the MEMORY table hits its heap ceiling
 * and every announce — new or repeating — fails with a bare "database error".
 *
 * A `stopped` event is exempt: it removes a row and is how space is reclaimed.
 * A repeat announce for an existing peer is also exempt, so the swarm does not
 * vanish the moment the cap is reached.
 */
$existingRow = null;
$lookup = tracker_stmt(
    "SELECT `port`
       FROM $table
      WHERE `info_hash` = ?
        AND `ip` = ?
        AND `port` = ?
      LIMIT 1",
    'sii',
    array($infoHash, $ip, (int) $port)
);
$existingRow = tracker_stmt_result($lookup)->fetch_assoc();
$lookup->close();

if ($existingRow === null && $event !== 'stopped' && tracker_peer_table_full()) {
    tracker_failure('tracker is at peer capacity; try again later');
}

/*
 * Enforce the announce interval. The tracker advertises this value in every
 * response; before this check it was never enforced, so one client could issue
 * hundreds of announces per second and exhaust the fixed-size peer table.
 *
 * The comparison is done in SQL deliberately. PHP's timezone and MySQL's can
 * differ (UTC versus SYSTEM here), so computing the delta in PHP from a
 * DATETIME string produced an offset that was wrong by the timezone gap, and
 * the guard silently never fired.
 */
if ($existingRow !== null && $event !== 'stopped') {
    $tooSoon = tracker_stmt(
        "SELECT `update_time` < NOW() - INTERVAL ? SECOND AS too_soon
           FROM $table
          WHERE `info_hash` = ?
            AND `ip` = ?
            AND `port` = ?
          LIMIT 1",
        'isii',
        array(max(1, $announceInterval - 60), $infoHash, $ip, (int) $port)
    );
    $tooSoonRow = tracker_stmt_result($tooSoon)->fetch_assoc();
    $tooSoon->close();

    if ($tooSoonRow && (int) $tooSoonRow['too_soon'] === 0) {
        tracker_failure('announce too soon; wait ' . $announceInterval . ' seconds');
    }
}

/*
 * A stop must not be stored. Writing an immediately-expired row left the entry
 * in the table for the whole clean-up retention window, making "stopped" the
 * cheapest possible way to consume a fixed-size table.
 */
if ($event === 'stopped') {
    tracker_stmt(
        "DELETE FROM $table WHERE `info_hash` = ? AND `ip` = ? AND `port` = ?",
        'sii',
        array($infoHash, $ip, (int) $port)
    );

    exit(bencode(tracker_response_with_id(array(
        'interval' => $announceInterval,
        'peers' => array(),
    ))));
}

$expireSeconds = (int) ceil($announceInterval * (float) tracker_config('expire_factor', 1.2));

$stmt = tracker_stmt(
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
$stmt->close();

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

/*
 * $_GET throughout. $_REQUEST also carries cookies on hosts configured with
 * request_order=GPC, which let a cookie silently switch the response encoding.
 */
if (!empty($_GET['compact'])) {
    $peers = '';
    while ($row = $result->fetch_assoc()) {
        $peers .= pack('Nn', (int) $row['ip'], (int) $row['port']);
    }
} elseif (!empty($_GET['no_peer_id'])) {
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
