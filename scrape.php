<?php
/*
 * Neuropentracker
 * Summary: Handles BitTorrent scrape requests and returns torrent statistics.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 * Compatible with PHP 7.4 through PHP 8.3.
 */

require_once __DIR__ . '/tracker.inc.php';

header('Content-Type: text/plain');

$table = tracker_table_name();
$announceInterval = tracker_announce_interval();
$scrapeInterval = (int) ceil($announceInterval * (float) tracker_config('scrape_factor', 0.5));

$hashes = tracker_info_hashes_from_query();
if (count($hashes) === 0) {
    $result = tracker_query("SELECT DISTINCT `info_hash` FROM $table WHERE `expire_time` > NOW()");
    while ($row = $result->fetch_row()) {
        $hashes[] = $row[0];
    }
    $result->free();
}

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
