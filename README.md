# Neuropentracker

- Copyright (c) 2026 Neuropentracker contributors.
- License: BSD-2-Clause. See [LICENSE](LICENSE) for details.
- A small BitTorrent tracker for **PHP 8.3 and PHP 8.4** (tested on 8.3.36 and 8.4.27).

## License

This code is licensed under the BSD 2-Clause License. See [LICENSE](LICENSE) for
the full license text.

## Requirements

| Requirement | Notes |
|---|---|
| PHP 8.3 or 8.4 | Tested on 8.3.36 and 8.4.27. PHP 7.4 is no longer supported or tested. |
| PHP `mysqli` extension | Without it every request fails at the first database call. |
| MySQL or MariaDB | Verified against MariaDB 10.11. |
| Apache with `AllowOverride All` | Or nginx with the equivalent deny rules (see below). |
| A cron entry | `clean.php` is the only path that reclaims peer rows. |

PHP 7.4 was previously listed as supported. All PHP 7.4 target language features
are still valid in 8.x, and nothing in this tree used a construct removed in
8.4, but 7.4 is end-of-life and this repository no longer claims or tests it.
Everything here is written for the 8.x engine and verified on 8.3 and 8.4.

## Install

1. Create a database and execute [dbschema.sql](dbschema.sql).

2. Create a **dedicated, least-privilege** account. The tracker refuses to start
   with `root` or with an empty password, so a misconfigured host fails loudly
   instead of connecting as a superuser:

   ```sql
   CREATE USER 'neuropentracker'@'localhost' IDENTIFIED BY 'a-long-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON neuropentracker.peers
     TO 'neuropentracker'@'localhost';
   ```

3. Edit [config.inc.php](config.inc.php) and set the database values. Every
   setting is documented inline, including the security-relevant ones.

4. Confirm [.htaccess](.htaccess) is present and that the server honours it.
   Without it, `/announce` and `/scrape` are served as PHP **source**, and
   `config.inc.php` becomes readable. This is not optional on a public host.

5. Schedule the maintenance task. It is CLI-only and returns 404 over HTTP:

   ```text
   */10 * * * * php /path/to/neuropentracker/clean.php
   ```

6. Use `announce.php` on your server as the torrent announce URL.

### nginx

Apache is what `.htaccess` targets. For nginx, the deny rules need to live in the
server block:

```nginx
location ~ \.inc\.php$    { deny all; }
location ~ ^/(clean\.php|dbschema\.sql|readme\.txt|README\.md)$ { deny all; }
location ~ ^/\.git        { deny all; }
location = /announce      { try_files /announce.php =404; }
location = /scrape        { try_files /scrape.php =404; }
```

## Configuration

All settings live in [config.inc.php](config.inc.php). They fall into seven
groups; the security-relevant defaults are summarised here.

### Database

| Setting | Default | Notes |
|---|---|---|
| `$db_server` | `localhost` | Hostname, IP, or `localhost` for a unix socket. |
| `$db_port` | `3306` | Ignored when `$db_socket` is set. |
| `$db_socket` | `null` | Absolute socket path, or `null` for TCP. |
| `$db_user` | `neuropentracker` | **Must not be `root`.** |
| `$db_pass` | `CHANGE-ME-…` | **Must not be empty.** |
| `$db_db` | `neuropentracker` | Must match the database you created. |
| `$db_table` | `peers` | Validated against `[A-Za-z0-9_]`, then quoted. |
| `$db_allow_privileged` | `false` | Development escape hatch. Never enable on a reachable host. |

### Announce timing

| Setting | Default | Notes |
|---|---|---|
| `$min_announce_interval` | `900` | **Enforced.** A repeat announce inside this window is rejected. |
| `$max_announce_interval` | `1800` | Ceiling on the advertised interval. |
| `$max_announce_rate` | `500` | Target announces/minute, used only by the interval formula. |
| `$expire_factor` | `1.2` | Peer is dead after this multiple of the interval. Must be > 1. |
| `$scrape_factor` | `0.5` | Minimum scrape gap, as a multiple of the interval. |

The advertised interval is computed from live swarm size and announce rate, then
clamped into `[min, max]`:

```text
raw      = (live_peers × announces_last_minute ÷ max_announce_rate²) × 60
interval = clamp(raw, min_announce_interval, max_announce_interval)
```

With the defaults the floor of 900 holds until 7,500 live peers and the 1,800
ceiling takes over at 15,000; between the two the interval tracks load. Both
bounds matter: the floor is what makes the interval enforceable, and the ceiling
is what stops an attacker inflating the interval until the swarm stops
refreshing.

The minimum is not merely advertised — a repeat announce for the same
`(info_hash, ip, port)` inside that window is refused with `announce too soon`.

### Peer capacity

| Setting | Default | Notes |
|---|---|---|
| `$max_peers` | `40000` | New peers are refused at this row count. |

The peer table is a MySQL `MEMORY` table with no eviction policy. On reaching
`max_heap_table_size` every `INSERT` fails and the tracker serves nothing but
`database error` until somebody empties it by hand — measured at 85,020 rows on
a 16 MB heap.

`$max_peers` is therefore enforced in the application, below that cliff. At the
cap, *new* peers get `tracker is at peer capacity`, while repeat announces and
scrapes keep working, and `clean.php` reclaims space so the state is recoverable
without a restart.

`dbschema.sql` also sets `MAX_ROWS=50000`, but that is a backstop only: MariaDB
ignores it whenever the heap limit is lower, which is precisely the case it was
meant to guard. The application cap is the control that holds. If you raise
`$max_peers`, check `SHOW VARIABLES LIKE 'max_heap_table_size'` first.

### Protocol

| Setting | Default | Values |
|---|---|---|
| `$tracker_id` | `w00tw00t` | Returned as `tracker id`. Not a secret. Blank omits it. |
| `$require_announce_protocol` | `standard` | `standard`, `no_peer_id`, or `compact`. |

### Dashboard

The dashboard publishes peer addresses, so it is **closed by default**.

| Setting | Default | Notes |
|---|---|---|
| `$dashboard_enabled` | `false` | `false` returns 404; the endpoint appears not to exist. |
| `$dashboard_key` | `''` | Required when enabled. Empty-plus-enabled is refused, not treated as "no check". |

To open it, set both:

```php
$dashboard_enabled = true;
$dashboard_key     = 'a-long-random-value';
```

Generate a key with:

```text
php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
```

Prefer the header over the query string, because a URL is written verbatim into
access logs, browser history and `Referer` headers:

```text
curl -H 'X-Dashboard-Key: your-key' https://tracker.example/dashboard.php?format=json
```

The `?key=` form still works:

```text
dashboard.php?key=your-key
dashboard.php?key=your-key&format=json
```

Peer addresses are shown as `/24` networks and peer IDs are not shown at all.
The key is a shared secret, not an identity: no users, no sessions, no per-key
revocation.

### Client IP address

| Setting | Default | Notes |
|---|---|---|
| `$trust_forwarded_ip` | `false` | Honour `?ip=` only behind a same-host reverse proxy. |

The socket address (`REMOTE_ADDR`) is authoritative. A `?ip=` parameter is
**validated and then ignored** unless `$trust_forwarded_ip` is true.

This matters because `?ip=` is attacker-controlled from any public client. When
it was honoured, a single extra parameter let anyone satisfy an IP whitelist,
dodge an IP blacklist, or inject arbitrary `IP:port` pairs into any swarm —
turning the tracker into a reflector aimed at a host of their choosing.

Trust is deliberately **not** inferred from the request arriving over loopback:
behind a same-host proxy every request arrives as `127.0.0.1`, so that inference
would reopen the bypass for exactly the deployments most likely to run one.
It is an explicit opt-in, not a heuristic.

Hostnames are never resolved. IPv4 literals only.

### Access lists

| Setting | Default | Notes |
|---|---|---|
| `$ip_blacklist` | `array()` | A match denies. |
| `$ip_whitelist` | `array()` | If non-empty, a non-match denies. |
| `$peer_id_blacklist` | `array()` | A match denies. |
| `$peer_id_whitelist` | `array()` | If non-empty, a non-match denies. |

Checked in that order. An empty whitelist means "no whitelist", not "deny
everyone", so all four are safe to leave empty.

IP rules support exact addresses and CIDR ranges:

```php
$ip_blacklist = array('198.51.100.7');
$ip_whitelist = array('203.0.113.0/24');
```

Peer ID rules support raw exact, raw prefix, hex exact, and hex prefix:

```php
'-TR3000-abcdefghijkl'
'prefix:-TR3000-'
'hex:2d5452333030302d6162636465666768696a6b6c'
'hexprefix:2d5452333030302d'
```

The hex forms exist because a peer ID is binary and may contain bytes that cannot
be written literally into a PHP string.

**A peer ID is chosen by the client**, so peer ID lists exclude a specific
misbehaving client; they are not a security boundary — a determined client
changes one byte. IP lists are enforced against the socket address and are the
control worth relying on.

## Scrape

`scrape.php` requires at least one `info_hash` and processes at most 50 per
request. The parameterless "scrape everything" form is disabled: it expanded a
36-byte request into a multi-megabyte response backed by one query per live
torrent, and it let anyone enumerate the entire catalog without ever announcing.

## Endpoints

| Path | Method | Purpose |
|---|---|---|
| `announce.php` / `/announce` | GET | BitTorrent announce. |
| `scrape.php` / `/scrape` | GET | BitTorrent scrape. Requires `info_hash`. |
| `dashboard.php` | GET | Statistics. Closed by default. |
| `clean.php` | CLI only | Reclaims expired peers. Returns 404 over HTTP. |

`/announce` and `/scrape` are routed to the `.php` files by `.htaccess`. Without
that file they are served as source.

## Hardening summary

What the shipped defaults already do, so you know what you can rely on:

- Every SQL statement is a prepared statement; the table name is allow-listed
  and quoted.
- The dashboard is closed, and fails closed on an empty key.
- Peer addresses are redacted to `/24`; peer IDs are not published.
- `?ip=` cannot influence access control or stored peer addresses.
- Announce frequency is enforced, and the advertised interval is clamped.
- New peers are refused at a known capacity instead of bricking the database.
- `clean.php` is CLI-only; `config.inc.php`, `dbschema.sql` and the shared
  `.inc.php` files are denied by `.htaccess`.
- Security headers (`nosniff`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, CSP) are sent, and `X-Powered-By` is removed.
- The database account is required to be unprivileged and to have a password.

Sizing and tuning guidance lives beside each setting in
[config.inc.php](config.inc.php).

## Main Files

- [.htaccess](.htaccess): Denies shared includes, config and database files, sets security headers, and routes the extensionless wrappers into PHP.
- [.gitignore](.gitignore): Keeps local config, backups and dumps out of the repository.
- [announce](announce): Extensionless wrapper that loads the announce endpoint.
- [announce.php](announce.php): Handles BitTorrent announce requests and returns peer lists.
- [bencoding.inc.php](bencoding.inc.php): Encodes and decodes BitTorrent bencoded values.
- [clean.php](clean.php): CLI-only task that reclaims expired peer records.
- [config.inc.php](config.inc.php): Stores database, tracker, dashboard, and access-list settings.
- [dashboard.php](dashboard.php): Displays read-only tracker statistics and recent peer activity.
- [dbschema.sql](dbschema.sql): Creates the in-memory peer table used by the tracker endpoints.
- [scrape](scrape): Extensionless wrapper that loads the scrape endpoint.
- [scrape.php](scrape.php): Handles BitTorrent scrape requests and returns torrent statistics.
- [tracker.inc.php](tracker.inc.php): Provides shared database, response, IP, and access-list helpers.
