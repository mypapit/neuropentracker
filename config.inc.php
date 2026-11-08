<?php
/*
 * Neuropentracker
 * Summary: Stores database, tracker, dashboard, and access-list settings.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 * Requires PHP 8.3 or 8.4 (tested on 8.3.36 and 8.4.27).
 *
 * ---------------------------------------------------------------------------
 * HOW THIS FILE IS READ
 * ---------------------------------------------------------------------------
 * Every value here is fetched through tracker_config() in tracker.inc.php,
 * which looks the name up in the global scope and falls back to a built-in
 * default if the variable is missing. Consequences worth knowing:
 *
 *   - Deleting a setting does not break anything; the default applies.
 *   - The defaults here are chosen to be safe on a public host. The ones that
 *     matter most are marked SECURITY.
 *   - Nothing here is validated at load time. A bad value is caught where it is
 *     used and reported as a tracker failure, so a typo surfaces as a client
 *     error message rather than a blank page.
 *
 * .htaccess denies this file, so it cannot be fetched over HTTP. That deny is
 * load-bearing: it is what stops a visitor from reading your database password
 * out of the file. If your server stops honouring .htaccess, fix that before
 * anything else.
 */


/* ===========================================================================
 * 1. DATABASE CONNECTION
 * ===========================================================================
 *
 * SECURITY: use a dedicated, least-privilege account. Do NOT use root, and do
 * not leave the password empty. tracker_db() refuses both and returns
 * "database account is privileged or passwordless" instead of connecting.
 *
 * That refusal is deliberate. The shipped default used to be root with an empty
 * password, which on a host where MySQL is not bound to loopback is a superuser
 * account one guess away.
 *
 * Create the account once:
 *
 *   CREATE USER 'neuropentracker'@'localhost' IDENTIFIED BY 'a-long-password';
 *   GRANT SELECT, INSERT, UPDATE, DELETE ON neuropentracker.peers
 *     TO 'neuropentracker'@'localhost';
 *
 * Only those four privileges are needed. The tracker never alters the schema,
 * never creates or drops tables, and never touches another database.
 */

// Hostname or IP of the database server. 'localhost' prefers a unix socket.
$db_server = 'localhost';

// TCP port. Ignored when $db_socket below is set.
$db_port = 3306;

// Absolute path to a unix socket, or null to connect over TCP.
// Example: '/run/mysqld/mysqld.sock'
$db_socket = null;

// Database account. Must not be 'root' unless $db_allow_privileged is true.
$db_user = 'neuropentracker';

// Database password. Must not be empty unless $db_allow_privileged is true.
$db_pass = 'CHANGE-ME-BEFORE-DEPLOYING';

// Database name. Must match the database created above.
$db_db = 'neuropentracker';

// Peer table name. Restricted to [A-Za-z0-9_] at runtime and then quoted, so it
// is safe from injection. There is no reason to change it.
$db_table = 'peers';

/*
 * SECURITY: escape hatch, development only.
 *
 * True permits a privileged (root) or passwordless DB account so a throwaway
 * local install does not need a dedicated user. Never enable it on a host
 * reachable from the internet: it re-opens the exact misconfiguration the guard
 * exists to prevent.
 */
$db_allow_privileged = false;


/* ===========================================================================
 * 2. ANNOUNCE TIMING
 * ===========================================================================
 *
 * A client announces to say it is alive; the tracker replies with an "interval"
 * — how many seconds to wait before announcing again. These settings compute and
 * bound that number.
 *
 * tracker_announce_interval() evaluates:
 *
 *   raw      = (live_peers * announces_last_minute / max_announce_rate^2) * 60
 *   interval = clamp(raw, min_announce_interval, max_announce_interval)
 *
 * With the defaults, the floor (900) holds until 7,500 live peers and the
 * ceiling (1800) takes over at 15,000. Between the two the value tracks load,
 * which is the point: a busy tracker asks its peers to check in less often.
 */

/*
 * SECURITY: minimum interval, in seconds. Enforced, not just advertised.
 *
 * An announce arriving sooner than this for the same (info_hash, ip, port) is
 * rejected with "announce too soon". Before this was enforced, one client could
 * issue hundreds of announces per second and exhaust the fixed-size peer table
 * in about four minutes.
 *
 * 900 (15 minutes) is the conventional BitTorrent value. Lowering it raises write
 * load linearly; do not go below a few hundred seconds on a public host.
 */
$min_announce_interval = 900;

/*
 * SECURITY: maximum interval, in seconds.
 *
 * The raw formula is quadratic in values an unauthenticated client can inflate.
 * Register enough peers and the advertised interval climbs without bound; I
 * measured a real tracker advertising ~20 hours after a few tens of thousands of
 * injected peers. A client told to come back tomorrow stops refreshing, so the
 * swarm empties while the statistics still look healthy.
 *
 * Keep this well below the expire window below, or peers are declared dead
 * before they are due to check in.
 */
$max_announce_interval = 1800;

/*
 * Target ceiling for announces per minute across all peers. Used only inside the
 * interval formula; it is not itself a rate limiter. Raising it makes the
 * computed interval smaller (more frequent announces).
 */
$max_announce_rate = 500;

/*
 * A peer is considered dead after this many times the announce interval that was
 * in force at its last announce. 1.2 allows a 20% grace window. Must be greater
 * than 1. A value of 1.0 drops a client that is one second late, which causes
 * visible flapping in the swarm.
 */
$expire_factor = 1.2;

/*
 * Minimum gap between scrape requests, as a multiple of the current announce
 * interval. Advertised to clients in the scrape response; not enforced
 * server-side. 0.5 means "scrape at most twice as often as you announce".
 */
$scrape_factor = 0.5;


/* ===========================================================================
 * 3. PEER TABLE CAPACITY
 * ===========================================================================
 */

/*
 * SECURITY: hard capacity, in rows.
 *
 * The peer table is a MySQL MEMORY table. It has no eviction policy: on reaching
 * max_heap_table_size every INSERT fails and the tracker serves nothing but
 * "database error" until an operator empties it by hand.
 *
 * The tracker therefore refuses *new* peers once this many rows exist. That
 * degrades cleanly: repeat announces from known peers keep working, scrapes keep
 * working, and a new client gets "tracker is at peer capacity" instead of an
 * opaque failure. clean.php reclaims space, so the state is recoverable without
 * a restart or a manual TRUNCATE.
 *
 * MAX_ROWS in dbschema.sql is a backstop only. MariaDB ignores it whenever the
 * heap limit is lower, which is exactly the case it was meant to guard, so this
 * application-level cap is the control that actually holds.
 *
 * Size it against your heap. A 16 MB max_heap_table_size holds roughly 85,000 of
 * these rows, so 40,000 leaves comfortable headroom. Before raising it, check:
 *
 *   SHOW VARIABLES LIKE 'max_heap_table_size';
 */
$max_peers = 40000;


/* ===========================================================================
 * 4. PROTOCOL
 * ===========================================================================
 */

/*
 * Optional identifier returned in every announce response as "tracker id". Some
 * clients echo it back; otherwise cosmetic. Leave blank to omit it.
 *
 * It is not a secret — every client that announces receives it.
 */
$tracker_id = 'w00tw00t';

/*
 * Which announce response formats to accept.
 *
 *   'standard'   accept all formats. Broadest compatibility, largest responses.
 *   'no_peer_id' accept only compact and no_peer_id. Refuses the verbose
 *                dictionary form, which repeats each peer's 20-byte peer id.
 *   'compact'    accept only the compact form (6 bytes per peer).
 *
 * 'compact' is the smallest and is supported by every client in current use.
 * 'standard' is the safe default for an unknown client population.
 */
$require_announce_protocol = 'standard';


/* ===========================================================================
 * 5. DASHBOARD
 * ===========================================================================
 *
 * The dashboard reports swarm statistics. It also publishes peer addresses, so
 * it is closed by default and needs both settings below to open.
 */

/*
 * SECURITY: master switch. False means the endpoint returns 404 and does not
 * exist as far as a visitor is concerned. Leave it false unless you need it.
 */
$dashboard_enabled = false;

/*
 * SECURITY: shared secret. Required whenever $dashboard_enabled is true.
 *
 * An empty key with the dashboard enabled is treated as a misconfiguration and
 * refused — it is NOT read as "no check required". That inversion was the
 * original bug: the shipped default was enabled + empty, so every fresh install
 * published its peer list to the internet.
 *
 * Generate one with:
 *   php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
 *
 * Send it in a header:
 *   curl -H 'X-Dashboard-Key: <key>' https://tracker.example/dashboard.php
 *
 * The ?key= query-string form still works, but a URL is written verbatim into
 * access logs, browser history and Referer headers, so the header is better.
 *
 * This is a shared secret, not an identity: no users, no sessions, no per-key
 * revocation. Rotating it means editing this file.
 */
$dashboard_key = '';

/*
 * The dashboard reduces peer addresses to their /24 network and omits peer IDs,
 * describing swarm shape without handing out a list of identifiable
 * downloaders. There is no setting to widen that. If you need full addresses,
 * edit dashboard.php — and consider what you are publishing before you do.
 */


/* ===========================================================================
 * 6. CLIENT IP ADDRESS
 * ===========================================================================
 */

/*
 * SECURITY: leave this false unless you run a reverse proxy on this host.
 *
 * A peer is identified by REMOTE_ADDR, the address of the socket that connected.
 * BitTorrent clients may also send an ?ip= parameter. From a public client that
 * value is attacker-controlled, and this tracker used to accept it, which meant
 * anyone could:
 *
 *   - satisfy an IP whitelist by claiming a whitelisted address,
 *   - dodge an IP blacklist by claiming a clean one,
 *   - inject arbitrary IP:port pairs into any swarm, turning the tracker into a
 *     reflector that points downloaders at a host and port of their choosing.
 *
 * When false, ?ip= is validated and then ignored; access control always uses the
 * socket address. When true, ?ip= is honoured.
 *
 * Trust is deliberately NOT inferred from the request arriving over loopback.
 * Behind a same-host reverse proxy every request arrives as 127.0.0.1, so that
 * inference would reopen the bypass for exactly the deployments most likely to
 * run a proxy. This is an explicit decision, not a heuristic.
 *
 * Hostnames are never resolved. A tracker takes IPv4 literals; a name lookup per
 * announce is both a blocking operation and an internal-host oracle.
 */
$trust_forwarded_ip = false;


/* ===========================================================================
 * 7. ACCESS LISTS
 * ===========================================================================
 *
 * tracker_access_allowed() checks these in order:
 *
 *   1. IP blacklist       — a match denies.
 *   2. IP whitelist       — if non-empty, a non-match denies.
 *   3. peer ID blacklist  — a match denies.
 *   4. peer ID whitelist  — if non-empty, a non-match denies.
 *
 * An empty whitelist means "no whitelist", not "deny everyone". An empty
 * blacklist means "deny nobody". All four are therefore safe to leave empty,
 * which is the default.
 */

/*
 * SECURITY: IP lists are evaluated against the socket address, never against an
 * ?ip= claim (see $trust_forwarded_ip above). These are the controls worth
 * relying on.
 *
 * Entries are exact IPv4 addresses or CIDR ranges:
 *
 *   '198.51.100.7'      one address
 *   '203.0.113.0/24'    a /24
 *   '10.0.0.0/8'        a /8
 *
 * A rule that cannot be parsed never matches, so a typo silently does nothing.
 * Enable the dashboard and check its "Blocked/Allowed IP rules" counters to
 * confirm your rules were loaded.
 *
 * Example — a private tracker admitting one network only:
 *
 *   $ip_whitelist = array('192.0.2.0/24');
 */
$ip_blacklist = array();
$ip_whitelist = array();

/*
 * Peer ID lists. A peer id is a 20-byte value chosen by the client.
 *
 * SECURITY: this is NOT a security boundary. A determined client changes one
 * byte and is through. Use these to exclude a specific misbehaving or buggy
 * client, not to control who may use the tracker. The IP lists are the control
 * that holds.
 *
 * Entries support four forms:
 *
 *   raw exact:    '-TR3000-abcdefghijkl'
 *   raw prefix:   'prefix:-TR3000-'
 *   hex exact:    'hex:2d5452333030302d6162636465666768696a6b6c'
 *   hex prefix:   'hexprefix:2d5452333030302d'
 *
 * The hex forms exist because a peer id is binary: it may contain a NUL or a
 * newline, which cannot be written literally into a PHP string reliably. Hex is
 * unambiguous. Exact comparisons use hash_equals(), so there is no timing leak.
 */
$peer_id_blacklist = array();
$peer_id_whitelist = array();
