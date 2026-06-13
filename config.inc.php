<?php
/*
 * Neuropentracker
 * Summary: Stores database, tracker, dashboard, and access-list settings.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 * Compatible with PHP 7.4 through PHP 8.3.
 */

// Database connection.
$db_server = 'localhost';
$db_port = 3306;
$db_socket = null;
$db_user = 'root';
$db_pass = '';
$db_db = 'neuropentracker';
$db_table = 'peers';

/*
 * Optional tracker identifier returned in announce responses as "tracker id".
 * Leave blank to omit it.
 */
$tracker_id = 'w00tw00t';

/*
 * Peers should wait at least this many seconds between announcements.
 */
$min_announce_interval = 900;

/*
 * Maximum desired announcements per minute for all peers combined.
 * The announce interval will be increased if necessary to achieve this.
 */
$max_announce_rate = 500;

/*
 * Consider a peer dead if it has not announced in a number of seconds equal
 * to this many times the calculated announce interval at the time of its last
 * announcement. Must be greater than 1; 1.2 is recommended.
 */
$expire_factor = 1.2;

/*
 * Peers should wait at least this many times the current calculated announce
 * interval between scrape requests.
 */
$scrape_factor = 0.5;

/*
 * Should we require a certain announce protocol?
 *   "standard" allows all protocols
 *   "no_peer_id" allows only no_peer_id and compact
 *   "compact" allows only compact
 */
$require_announce_protocol = 'standard';

/*
 * Dashboard access.
 * Leave $dashboard_key blank for no dashboard key check. Set a random string
 * and visit dashboard.php?key=that-string to restrict casual access.
 */
$dashboard_enabled = true;
$dashboard_key = '';

/*
 * IP access lists.
 * Entries support exact IPv4 addresses and CIDR ranges, for example:
 *   '198.51.100.7'
 *   '203.0.113.0/24'
 */
$ip_blacklist = array();
$ip_whitelist = array();

/*
 * Peer ID access lists.
 * Entries support:
 *   raw exact match: '-TR3000-abcdefghijkl'
 *   raw prefix:      'prefix:-TR3000-'
 *   hex exact:       'hex:2d5452333030302d6162636465666768696a6b6c'
 *   hex prefix:      'hexprefix:2d5452333030302d'
 */
$peer_id_blacklist = array();
$peer_id_whitelist = array();
