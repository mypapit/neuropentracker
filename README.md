# Neuropentracker

Summary: Documents Neuropentracker installation, configuration, and operation.

Copyright (c) 2026 Neuropentracker contributors.

License: BSD-2-Clause. See [LICENSE](LICENSE) for details.

Neuropentracker is a small BitTorrent tracker refreshed for PHP 7.4 through PHP 8.3. It uses the `mysqli` extension and a MySQL or MariaDB database.

Note: This project is partly generated using OpenAI Codex.

## License

This code is licensed under the BSD 2-Clause License. See [LICENSE](LICENSE) for the full license text.

## Requirements

- PHP 7.4 or newer
- PHP `mysqli` extension
- MySQL or MariaDB
- A web server capable of running PHP

## Install

1. Create a database, then execute [dbschema.sql](dbschema.sql).
2. Edit [config.inc.php](config.inc.php) and set your database connection values.
3. Use `announce.php` on your server as the torrent announce URL.

The extensionless `announce` and `scrape` wrappers are still present. If your server honors the included [.htaccess](.htaccess) file, `/announce` and `/scrape` route to the PHP endpoints.

## Dashboard

Open [dashboard.php](dashboard.php) to view tracker statistics, active torrents, recent peers, and access-list counts.

JSON output is available at:

```text
dashboard.php?format=json
```

Set `$dashboard_key` in [config.inc.php](config.inc.php) to require a key:

```text
dashboard.php?key=your-key
dashboard.php?key=your-key&format=json
```

## Tracker Identifier

Set `$tracker_id` in [config.inc.php](config.inc.php) to return a BitTorrent `tracker id` value in announce responses.

## IP And Peer ID Access Control

Configure these arrays in [config.inc.php](config.inc.php):

```php
$ip_blacklist = array();
$ip_whitelist = array();
$peer_id_blacklist = array();
$peer_id_whitelist = array();
```

IP rules support exact IPv4 addresses and CIDR ranges:

```php
'198.51.100.7'
'203.0.113.0/24'
```

Peer ID rules support raw exact, raw prefix, hex exact, and hex prefix:

```php
'-TR3000-abcdefghijkl'
'prefix:-TR3000-'
'hex:2d5452333030302d6162636465666768696a6b6c'
'hexprefix:2d5452333030302d'
```

Blacklist rules always deny matching announces. Whitelist arrays are optional; when a whitelist array contains entries, announces must match that whitelist.

## Main Files

- [.htaccess](.htaccess): Configures Apache defaults for the tracker directory and extensionless wrappers.
- [announce](announce): Extensionless wrapper that loads the announce endpoint.
- [announce.php](announce.php): Handles BitTorrent announce requests and returns peer lists.
- [bencoding.inc.php](bencoding.inc.php): Encodes and decodes BitTorrent bencoded values.
- [clean.php](clean.php): Deletes peer records that have been expired for more than three days.
- [config.inc.php](config.inc.php): Stores database, tracker, dashboard, and access-list settings.
- [dashboard.php](dashboard.php): Displays read-only tracker statistics and recent peer activity.
- [dbschema.sql](dbschema.sql): Creates the in-memory peer table used by the tracker endpoints.
- [scrape](scrape): Extensionless wrapper that loads the scrape endpoint.
- [scrape.php](scrape.php): Handles BitTorrent scrape requests and returns torrent statistics.
- [tracker.inc.php](tracker.inc.php): Provides shared database, response, IP, and access-list helpers.
