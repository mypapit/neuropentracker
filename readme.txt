====================
 Neuropentracker
 PHP 7.4 / PHP 8.3 refresh
====================

Summary: Documents Neuropentracker installation, configuration, and operation.
Copyright (c) 2026 Neuropentracker contributors.
License: BSD-2-Clause. See LICENSE for details.

Neuropentracker is a small BitTorrent tracker. You need a web server that
supports PHP 7.4 or newer, the mysqli extension, and a MySQL or MariaDB
database.

Note: This project is partly generated using OpenAI Codex.

License
-------

This code is licensed under the BSD 2-Clause License. See LICENSE for the full
license text.

Install
-------

1. Create a database, then execute dbschema.sql.
2. Edit config.inc.php and set your database connection values.
3. Use announce.php on your server as the torrent announce URL.

The extensionless announce and scrape wrappers are still present. If your
server honors the included .htaccess file, /announce and /scrape will route to
the PHP endpoints.

Dashboard
---------

Open dashboard.php to view tracker statistics, active torrents, recent peers,
and access-list counts. JSON output is available with:

  dashboard.php?format=json

Set $dashboard_key in config.inc.php to require a key:

  dashboard.php?key=your-key
  dashboard.php?key=your-key&format=json

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
