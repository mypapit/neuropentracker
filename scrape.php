<?php
/*
 * Neuropentracker
 * Summary: Handles BitTorrent scrape requests and returns torrent statistics.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 * Requires PHP 8.3 or 8.4 (tested on 8.3.36 and 8.4.27).
 */

require_once __DIR__ . '/tracker.inc.php';

header('Content-Type: text/plain');
tracker_security_headers();

$table = tracker_table_name();
$announceInterval = tracker_announce_interval();
$scrapeInterval = (int) ceil($announceInterval * (float) tracker_config('scrape_factor', 0.5));

$hashes = tracker_info_hashes_from_query();

/*
 * Refuse the bulk listing.
 *
 * BEP-48 allows a parameterless scrape to return every known torrent, and this
 * endpoint used to do exactly that: one 36-byte request expanded to a 1.4 MB
 * response backed by one SELECT per live hash. That is a clean amplification
 * and worker-pool exhaustion primitive, and it also let anyone enumerate the
 * entire catalog without ever announcing.
 */
if (count($hashes) === 0) {
    tracker_failure('info_hash required; bulk scrape-all is disabled');
}

/*
 * Cap the request. The query-string parser reads QUERY_STRING directly, which
 * bypasses PHP's max_input_vars, so this is the only bound on how many hashes
 * one request can name.
 */
$hashes = array_slice($hashes, 0, 50);

$files = array();
foreach ($hashes as $hash) {
    if (strlen($hash) !== 20) {
        continue;
    }

    $stmt = tracker_stmt(
        "SELECT
            SUM(CASE WHEN `left` = 0 AND `expire_time` > NOW() THEN 1 ELSE 0 END) AS complete,
            SUM(CASE WHEN `left` > 0 AND `expire_time` > NOW() THEN 1 ELSE 0 END) AS incomplete,
            COUNT(DISTINCT CASE WHEN `left` = 0 THEN CONCAT(`ip`, ':', `port`) END) AS downloaded
           FROM $table
          WHERE `info_hash` = ?",
        's',
        array($hash)
    );
    $result = tracker_stmt_result($stmt);
    $row = $result->fetch_assoc() ?: array();

    $files[$hash] = array(
        'complete' => (int) ($row['complete'] ?? 0),
        'incomplete' => (int) ($row['incomplete'] ?? 0),
        'downloaded' => (int) ($row['downloaded'] ?? 0),
    );

    $result->free();
    $stmt->close();
}

exit(bencode(array(
    'files' => $files,
    'flags' => array('min_request_interval' => $scrapeInterval),
)));
