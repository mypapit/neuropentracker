<?php
/*
 * Neuropentracker
 * Summary: Deletes peer records that have been expired for more than three days.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 */

require_once __DIR__ . '/tracker.inc.php';

$table = tracker_table_name();
tracker_exec("DELETE FROM $table WHERE `expire_time` < NOW() - INTERVAL 3 DAY");
