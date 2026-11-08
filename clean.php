<?php
/*
 * Neuropentracker
 * Summary: Reclaims peer records that are no longer live.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 */

/*
 * CLI only.
 *
 * This endpoint used to be reachable over HTTP with no authentication, so any
 * crawler, browser prefetcher, or <img src> on any page could trigger a DELETE.
 * It is maintenance work: run it from cron, not from the web.
 */
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('Content-Type: text/plain');
        http_response_code(404);
    }
    exit('not found');
}

require_once __DIR__ . '/tracker.inc.php';

$table = tracker_table_name();

/*
 * Reclaim anything already expired.
 *
 * The old cut-off was three days past expiry, chosen when a "stopped" announce
 * wrote an immediately-expired row. That is no longer stored (announce.php now
 * deletes on stop), and the three-day window meant the fixed-size MEMORY table
 * could stay full for three days after an abusive burst. Expired means dead.
 */
tracker_exec("DELETE FROM $table WHERE `expire_time` < NOW()");
