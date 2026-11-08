====================
 Neuropentracker
 PHP 8.3 / PHP 8.4
====================

Summary: Documents Neuropentracker installation, configuration, and operation.
Copyright (c) 2026 Neuropentracker contributors.
License: BSD-2-Clause. See LICENSE for details.

Neuropentracker is a small BitTorrent tracker. You need a web server that
supports PHP 8.3 or 8.4, the mysqli extension, and a MySQL or MariaDB database.

Tested on PHP 8.3.36 and PHP 8.4.27 against MariaDB 10.11. PHP 7.4 is no longer
supported or tested: it is end-of-life, and while nothing in this tree used a
construct removed in 8.4, the code is written for the 8.x engine and verified
only on 8.3 and 8.4.

Note: This project is partly generated using OpenAI Codex.

License
-------

This code is licensed under the BSD 2-Clause License. See LICENSE for the full
license text.

Install
-------

1. Create a database, then execute dbschema.sql.
2. Create a dedicated least-privilege account. Do not use root, and do not
   leave the password empty: the tracker refuses to start with either.

     CREATE USER 'neuropentracker'@'localhost' IDENTIFIED BY 'a-long-password';
     GRANT SELECT, INSERT, UPDATE, DELETE ON neuropentracker.peers
       TO 'neuropentracker'@'localhost';

3. Edit config.inc.php and set your database connection values.
4. Keep .htaccess in place and make sure the web server honours it. Without it
   /announce and /scrape are served as PHP source rather than executed.
5. Schedule the maintenance task. It is CLI-only and returns 404 over HTTP:

     */10 * * * * php /path/to/neuropentracker/clean.php

6. Use announce.php on your server as the torrent announce URL.

The extensionless announce and scrape wrappers are routed into the PHP handler
by the shipped .htaccess file, so /announce and /scrape work as documented.

Announce interval and capacity
-----------------------------

$min_announce_interval is enforced, not merely advertised: a repeat announce for
the same (info_hash, ip, port) inside that window is rejected. The advertised
interval is computed from swarm size and clamped between $min_announce_interval
and $max_announce_interval, so an attacker cannot inflate it until the swarm
stops refreshing.

The peer table is a fixed-size MEMORY table with no eviction. Rather than let it
reach the heap ceiling, where every INSERT fails and the tracker serves nothing
but "database error", the tracker refuses NEW peers at $max_peers (default
40000) with a clear message. Repeat announces and scrapes keep working, and
clean.php reclaims space so the state recovers without a restart.

MAX_ROWS in dbschema.sql is a backstop only: MariaDB ignores it whenever the heap
limit is lower, which is the case it was meant to guard. $max_peers is the
control that holds.

Dashboard
---------

The dashboard publishes peer addresses, so it is closed by default. To open it,
set both values in config.inc.php:

  $dashboard_enabled = true;
  $dashboard_key     = 'a-long-random-value';

An empty key with the dashboard enabled is refused. Peer addresses are shown as
/24 networks and peer IDs are not shown. JSON output is available with:

  dashboard.php?format=json

Prefer sending the key as a header so it never reaches an access log:

  curl -H 'X-Dashboard-Key: your-key' https://tracker.example/dashboard.php

The query-string form still works:

  dashboard.php?key=your-key
  dashboard.php?key=your-key&format=json

Scrape
------

scrape.php requires at least one info_hash and processes at most 50 per request.
The parameterless scrape-all form is disabled.

Client IP address
-----------------

The socket address is authoritative. A ?ip= parameter is ignored unless you set
$trust_forwarded_ip = true in config.inc.php, which is appropriate only when a
reverse proxy on the same host supplies the client address. Trust is not
inferred from the request arriving over loopback, because behind a same-host
proxy every request does. With the default, ?ip= cannot satisfy an IP whitelist,
dodge an IP blacklist, or inject peers into a swarm. Hostnames are not resolved.

Tracker identifier
------------------

Set $tracker_id in config.inc.php to return a BitTorrent "tracker id" value in
announce responses.

IP and peer ID access control
-----------------------------

Configure these arrays in config.inc.php:

  $ip_blacklist
  $ip_whitelist
  $peer_id_blacklist
  $peer_id_whitelist

IP rules support exact IPv4 addresses and CIDR ranges:

  '198.51.100.7'
  '203.0.113.0/24'

Peer ID rules support raw exact, raw prefix, hex exact, and hex prefix:

  '-TR3000-abcdefghijkl'
  'prefix:-TR3000-'
  'hex:2d5452333030302d6162636465666768696a6b6c'
  'hexprefix:2d5452333030302d'

Blacklist rules always deny matching announces. Whitelist arrays are optional;
when a whitelist array contains entries, announces must match that whitelist.
