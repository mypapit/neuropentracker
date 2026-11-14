# Neuropentracker

Copyright (c) 2026 Neuropentracker contributors.

A BitTorrent tracker for PHP 8.3 and PHP 8.4, tested on 8.3.36 and 8.4.27.

## License

This code is licensed under the BSD 2-Clause License. See [LICENSE](LICENSE) for
the full license text.

## Requirements

| Requirement | Notes |
|---|---|
| PHP 8.3 or 8.4 | Tested on 8.3.36 and 8.4.27. PHP 7.4 is no longer supported or tested. |
| PHP `mysqli` extension | Without it every request fails at the first database call. |
| MySQL or MariaDB | Verified against MariaDB 10.11. |
| Apache with `AllowOverride All` | Or nginx with the deny rules in the nginx section. |
| A cron entry | Only `clean.php` reclaims expired peer rows. |

PHP 7.4 was listed as supported in earlier versions, and the code uses no syntax
removed in 8.4. Because that release is end-of-life, this repository no longer
tests PHP 7.4.

## Install

1. Create a database. Then run [dbschema.sql](dbschema.sql).

2. Create a dedicated, least-privilege account. The tracker refuses to start as
   `root` or with an empty password:

   ```sql
   CREATE USER 'neuropentracker'@'localhost' IDENTIFIED BY 'a-long-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON neuropentracker.peers
     TO 'neuropentracker'@'localhost';
   ```

3. Edit [config.inc.php](config.inc.php) and set the database values. Every
   setting is documented inline, including the security-relevant ones.

4. Make sure that [.htaccess](.htaccess) is present and that the server honors
   it. Without it, Apache serves `/announce` and `/scrape` as PHP source and
   leaves `config.inc.php` readable. This is required on a public host.

5. Schedule the maintenance task in cron. It is CLI-only and returns 404 over
   HTTP:

   ```text
   */10 * * * * php /path/to/neuropentracker/clean.php
   ```

6. Use `announce.php` on your server as the torrent announce URL.

### nginx

Because `.htaccess` applies only to Apache, put the deny rules in the nginx
server block:

```nginx
location ~ \.inc\.php$    { deny all; }
location ~ ^/(clean\.php|dbschema\.sql|readme\.txt|README\.md)$ { deny all; }
location ~ ^/\.git        { deny all; }
location = /announce      { try_files /announce.php =404; }
location = /scrape        { try_files /scrape.php =404; }
```

## Settings

All settings live in [config.inc.php](config.inc.php) and fall into seven
groups. The tables below give the security-relevant defaults.

### Database

| Setting | Default | Notes |
|---|---|---|
| `$db_server` | `localhost` | Hostname, IP, or `localhost` for a unix socket. |
| `$db_port` | `3306` | Not used with `$db_socket`. |
| `$db_socket` | `null` | Absolute socket path, or `null` for TCP. |
| `$db_user` | `neuropentracker` | **Must not be `root`.** |
| `$db_pass` | `CHANGE-ME-…` | **Must not be empty.** |
| `$db_db` | `neuropentracker` | Must match the database you created. |
| `$db_table` | `peers` | The tracker allows only `[A-Za-z0-9_]` and quotes the name. |
| `$db_allow_privileged` | `false` | For development only. Do not enable this on a public host. |

### Announce timing

| Setting | Default | Notes |
|---|---|---|
| `$min_announce_interval` | `900` | **Enforced.** The tracker refuses a repeat announce inside this window. |
| `$max_announce_interval` | `1800` | Ceiling on the advertised interval. |
| `$max_announce_rate` | `500` | Target announces per minute. Only the interval formula uses this value. |
| `$expire_factor` | `1.2` | A peer is dead after this multiple of the interval. Use a value greater than 1. |
| `$scrape_factor` | `0.5` | Minimum scrape gap, as a multiple of the interval. |

The advertised interval is computed from live swarm size and announce rate, then
clamped into `[min, max]`:

```text
raw      = (live_peers × announces_last_minute ÷ max_announce_rate²) × 60
interval = clamp(raw, min_announce_interval, max_announce_interval)
```

With the defaults, the floor of 900 holds until 7,500 live peers. From there the
interval tracks the load until the 1,800 ceiling takes over at 15,000. The
tracker enforces the floor, so a repeat announce for the same
`(info_hash, ip, port)` inside that window returns `announce too soon`. The
ceiling stops an attacker from advertising a long interval until the swarm stops
refreshing.

### Peer capacity

| Setting | Default | Notes |
|---|---|---|
| `$max_peers` | `40000` | The tracker refuses new peers at this row count. |

The peer table uses the MySQL `MEMORY` engine, which has no eviction policy. Once
it reaches `max_heap_table_size`, every `INSERT` fails and the tracker answers
`database error` until an operator empties the table by hand. On a 16 MB heap
that point comes at 85,020 rows.

The application enforces `$max_peers` below that heap limit. At the cap, new
peers get `tracker is at peer capacity`, while repeat announces and scrapes keep
working. Because `clean.php` reclaims space, the tracker recovers without a
restart.

`dbschema.sql` also sets `MAX_ROWS=50000` as a backstop, but MariaDB ignores it
when the heap limit is lower. The application cap is the effective limit. Before
you raise `$max_peers`, read `SHOW VARIABLES LIKE 'max_heap_table_size'`.

### Protocol

| Setting | Default | Values |
|---|---|---|
| `$tracker_id` | `w00tw00t` | Returned as `tracker id` (not a secret). A blank value omits it. |
| `$require_announce_protocol` | `standard` | `standard`, `no_peer_id`, or `compact`. |

### Dashboard

Because the dashboard publishes peer addresses, it is closed by default.

| Setting | Default | Notes |
|---|---|---|
| `$dashboard_enabled` | `false` | `false` returns 404. The endpoint then appears not to exist. |
| `$dashboard_key` | `''` | Required when enabled. The tracker refuses an empty key. |

To open the dashboard, set both values:

```php
$dashboard_enabled = true;
$dashboard_key     = 'a-long-random-value';
```

Generate a key with this command:

```text
php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
```

Prefer the header over the query string, because a URL is written verbatim into
access logs, browser history, and `Referer` headers:

```text
curl -H 'X-Dashboard-Key: your-key' https://tracker.example/dashboard.php?format=json
```

The `?key=` form also works:

```text
dashboard.php?key=your-key
dashboard.php?key=your-key&format=json
```

The dashboard shows peer addresses as `/24` networks and omits peer IDs. Because
the key is a shared secret, there are no user accounts or sessions, and one key
cannot be revoked on its own.

### Client IP address

| Setting | Default | Notes |
|---|---|---|
| `$trust_forwarded_ip` | `false` | Set this to `true` only behind a same-host reverse proxy. |

The socket address (`REMOTE_ADDR`) is authoritative. The tracker validates `?ip=`
and ignores the value unless `$trust_forwarded_ip` is true.

Any public client can set `?ip=`. When the tracker honored that parameter, one
extra parameter could satisfy an IP whitelist, dodge an IP blacklist, or inject
arbitrary `IP:port` pairs into any swarm. Those injected pairs made the tracker a
reflector aimed at a host the client chose.

The tracker does not infer trust from the remote address. Behind a same-host
proxy every request arrives as `127.0.0.1`, so such an inference reopens the
bypass for the deployments most likely to run one. `$trust_forwarded_ip` is an
explicit opt-in.

The tracker does not resolve hostnames and accepts only IPv4 literals.

### Access lists

| Setting | Default | Notes |
|---|---|---|
| `$ip_blacklist` | `array()` | A match denies. |
| `$ip_whitelist` | `array()` | When non-empty, a non-match denies. |
| `$peer_id_blacklist` | `array()` | A match denies. |
| `$peer_id_whitelist` | `array()` | When non-empty, a non-match denies. |

The tracker applies the lists in that order. An empty whitelist means "no
whitelist", so all four are safe to leave empty.

IP rules accept exact addresses and CIDR ranges:

```php
$ip_blacklist = array('198.51.100.7');
$ip_whitelist = array('203.0.113.0/24');
```

Peer ID rules accept raw exact, raw prefix, hex exact, and hex prefix forms:

```php
'-TR3000-abcdefghijkl'
'prefix:-TR3000-'
'hex:2d5452333030302d6162636465666768696a6b6c'
'hexprefix:2d5452333030302d'
```

Hex forms exist because a peer ID is binary and can contain bytes that a PHP
string cannot represent literally.

Because the client chooses its own peer ID, a peer ID list can exclude one
misbehaving client but cannot be a security boundary: one changed byte gives the
client a new ID. IP lists are applied to the socket address, which the client
cannot change.

## Scrape

`scrape.php` requires at least one `info_hash` and processes at most 50 per
request. The parameterless "scrape everything" form is disabled: a 36-byte
request expanded into a multi-megabyte response backed by one query per live
torrent, and the form let anyone enumerate the entire catalog without an
announce.

## Endpoints

| Path | Method | Purpose |
|---|---|---|
| `announce.php` / `/announce` | GET | BitTorrent announce. |
| `scrape.php` / `/scrape` | GET | BitTorrent scrape. Requires `info_hash`. |
| `dashboard.php` | GET | Statistics. Closed by default. |
| `clean.php` | CLI only | Reclaims expired peers. Returns 404 over HTTP. |

`.htaccess` routes `/announce` and `/scrape` to the `.php` files, and without it
Apache serves them as source.

## Hardening summary

The shipped defaults already provide this protection:

- The code prepares every SQL statement, with the table name allow-listed and quoted.
- The dashboard is closed and fails closed on an empty key.
- The dashboard redacts peer addresses to `/24` and does not publish peer IDs.
- `?ip=` cannot influence access control or stored peer addresses.
- The tracker enforces announce frequency and clamps the advertised interval.
- The tracker refuses new peers at a known capacity, which keeps the table below the heap limit.
- `clean.php` is CLI-only. `.htaccess` denies `config.inc.php`, `dbschema.sql`, and the shared `.inc.php` files.
- The tracker sends security headers (`nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, CSP) and removes `X-Powered-By`.
- The database account must be unprivileged and must have a password.

Each setting in [config.inc.php](config.inc.php) has sizing and tuning notes.

## Files

- [.htaccess](.htaccess): Denies the shared includes, config, and database files, sets the security headers, and routes the extensionless wrappers into PHP.
- [.gitignore](.gitignore): Keeps local config, backups, and dumps out of the repository.
- [announce](announce): Extensionless wrapper that loads the announce endpoint.
- [announce.php](announce.php): Handles BitTorrent announce requests and returns peer lists.
- [bencoding.inc.php](bencoding.inc.php): Encodes and decodes BitTorrent bencoded values.
- [clean.php](clean.php): CLI-only task that reclaims expired peer records.
- [config.inc.php](config.inc.php): Stores database, tracker, dashboard, and access-list settings.
- [dashboard.php](dashboard.php): Shows read-only tracker statistics and recent peer activity.
- [dbschema.sql](dbschema.sql): Creates the in-memory peer table that the tracker endpoints use.
- [scrape](scrape): Extensionless wrapper that loads the scrape endpoint.
- [scrape.php](scrape.php): Handles BitTorrent scrape requests and returns torrent statistics.
- [tracker.inc.php](tracker.inc.php): Provides shared database, response, IP, and access-list helpers.
