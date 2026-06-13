<?php
/*
 * Neuropentracker
 * Summary: Displays read-only tracker statistics and recent peer activity.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 * Compatible with PHP 7.4 through PHP 8.3.
 */

require_once __DIR__ . '/tracker.inc.php';

if (!tracker_config('dashboard_enabled', true)) {
    http_response_code(404);
    exit('Dashboard disabled');
}

if (!tracker_dashboard_allowed()) {
    http_response_code(403);
    exit('Dashboard key required');
}

function dashboard_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dashboard_bytes($value): string
{
    $value = (float) $value;
    $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
    $unit = 0;

    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
}

function dashboard_config_count(string $name): int
{
    $value = tracker_config($name, array());
    return is_array($value) ? count($value) : 0;
}

$table = tracker_table_name();
$announceInterval = tracker_announce_interval();
$scrapeInterval = (int) ceil($announceInterval * (float) tracker_config('scrape_factor', 0.5));

$stats = array(
    'active_peers' => tracker_scalar("SELECT COUNT(*) FROM $table WHERE `expire_time` > NOW()"),
    'total_peers' => tracker_scalar("SELECT COUNT(*) FROM $table"),
    'active_torrents' => tracker_scalar("SELECT COUNT(DISTINCT `info_hash`) FROM $table WHERE `expire_time` > NOW()"),
    'seeders' => tracker_scalar("SELECT COUNT(*) FROM $table WHERE `left` = 0 AND `expire_time` > NOW()"),
    'leechers' => tracker_scalar("SELECT COUNT(*) FROM $table WHERE `left` > 0 AND `expire_time` > NOW()"),
    'announces_last_minute' => tracker_scalar("SELECT COUNT(*) FROM $table WHERE `update_time` > NOW() - INTERVAL 1 MINUTE"),
    'expired_peers' => tracker_scalar("SELECT COUNT(*) FROM $table WHERE `expire_time` <= NOW()"),
    'announce_interval' => $announceInterval,
    'scrape_interval' => $scrapeInterval,
    'tracker_id' => (string) tracker_config('tracker_id', ''),
);

$torrents = array();
$result = tracker_query(
    "SELECT
        `info_hash`,
        COUNT(*) AS peers,
        SUM(CASE WHEN `left` = 0 THEN 1 ELSE 0 END) AS seeders,
        SUM(CASE WHEN `left` > 0 THEN 1 ELSE 0 END) AS leechers,
        SUM(`uploaded`) AS uploaded,
        SUM(`downloaded`) AS downloaded,
        SUM(`left`) AS remaining,
        MAX(`update_time`) AS last_announce
       FROM $table
      WHERE `expire_time` > NOW()
      GROUP BY `info_hash`
      ORDER BY peers DESC, last_announce DESC
      LIMIT 50"
);
while ($row = $result->fetch_assoc()) {
    $torrents[] = $row;
}
$result->free();

$peers = array();
$result = tracker_query(
    "SELECT `info_hash`, `ip`, `port`, `peer_id`, `uploaded`, `downloaded`, `left`, `update_time`, `expire_time`
       FROM $table
      WHERE `expire_time` > NOW()
      ORDER BY `update_time` DESC
      LIMIT 100"
);
while ($row = $result->fetch_assoc()) {
    $peers[] = $row;
}
$result->free();

$access = array(
    'ip_blacklist' => dashboard_config_count('ip_blacklist'),
    'ip_whitelist' => dashboard_config_count('ip_whitelist'),
    'peer_id_blacklist' => dashboard_config_count('peer_id_blacklist'),
    'peer_id_whitelist' => dashboard_config_count('peer_id_whitelist'),
);

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    echo json_encode(array(
        'stats' => $stats,
        'access_lists' => $access,
        'torrents' => array_map(function ($row) {
            $row['info_hash_hex'] = tracker_hex($row['info_hash']);
            unset($row['info_hash']);
            return $row;
        }, $torrents),
        'peers' => array_map(function ($row) {
            $row['info_hash_hex'] = tracker_hex($row['info_hash']);
            $row['peer_id_hex'] = tracker_hex($row['peer_id']);
            $row['ip'] = long2ip((int) $row['ip']);
            unset($row['info_hash'], $row['peer_id']);
            return $row;
        }, $peers),
    ), JSON_PRETTY_PRINT);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Neuropentracker Dashboard</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7f9;
            --panel: #ffffff;
            --ink: #1f2933;
            --muted: #637083;
            --line: #d9dee7;
            --accent: #0f766e;
            --accent-soft: #d9f4ef;
            --warn: #9a3412;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--bg);
            color: var(--ink);
        }
        header {
            padding: 24px clamp(16px, 4vw, 48px) 18px;
            border-bottom: 1px solid var(--line);
            background: var(--panel);
        }
        h1 {
            margin: 0 0 6px;
            font-size: 28px;
            font-weight: 700;
        }
        main {
            padding: 24px clamp(16px, 4vw, 48px) 48px;
        }
        .muted { color: var(--muted); }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        .stat {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
        }
        .stat strong {
            display: block;
            font-size: 26px;
            margin-bottom: 4px;
        }
        section {
            margin-top: 26px;
        }
        h2 {
            font-size: 18px;
            margin: 0 0 12px;
        }
        .table-wrap {
            overflow-x: auto;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }
        th, td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            white-space: nowrap;
            font-size: 14px;
        }
        th {
            color: var(--muted);
            font-weight: 700;
            background: #fbfcfd;
        }
        tr:last-child td {
            border-bottom: 0;
        }
        code {
            font-family: Consolas, Monaco, monospace;
            font-size: 13px;
        }
        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
        }
        .empty {
            padding: 18px;
            color: var(--muted);
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        footer {
            padding: 0 clamp(16px, 4vw, 48px) 28px;
            color: var(--muted);
            font-size: 13px;
        }
    </style>
</head>
<body>
<header>
    <h1>Neuropentracker Dashboard</h1>
    <div class="muted">
        Tracker ID:
        <strong><?php echo $stats['tracker_id'] === '' ? 'not set' : dashboard_e($stats['tracker_id']); ?></strong>
    </div>
</header>
<main>
    <div class="stats">
        <div class="stat"><strong><?php echo dashboard_e($stats['active_peers']); ?></strong><span class="muted">Active peers</span></div>
        <div class="stat"><strong><?php echo dashboard_e($stats['active_torrents']); ?></strong><span class="muted">Active torrents</span></div>
        <div class="stat"><strong><?php echo dashboard_e($stats['seeders']); ?></strong><span class="muted">Seeders</span></div>
        <div class="stat"><strong><?php echo dashboard_e($stats['leechers']); ?></strong><span class="muted">Leechers</span></div>
        <div class="stat"><strong><?php echo dashboard_e($stats['announces_last_minute']); ?></strong><span class="muted">Announces in 1 min</span></div>
        <div class="stat"><strong><?php echo dashboard_e($stats['expired_peers']); ?></strong><span class="muted">Expired peers</span></div>
        <div class="stat"><strong><?php echo dashboard_e($stats['announce_interval']); ?>s</strong><span class="muted">Announce interval</span></div>
        <div class="stat"><strong><?php echo dashboard_e($stats['scrape_interval']); ?>s</strong><span class="muted">Scrape interval</span></div>
    </div>

    <section>
        <h2>Access Lists</h2>
        <div class="stats">
            <div class="stat"><strong><?php echo dashboard_e($access['ip_blacklist']); ?></strong><span class="muted">Blocked IP rules</span></div>
            <div class="stat"><strong><?php echo dashboard_e($access['ip_whitelist']); ?></strong><span class="muted">Allowed IP rules</span></div>
            <div class="stat"><strong><?php echo dashboard_e($access['peer_id_blacklist']); ?></strong><span class="muted">Blocked peer ID rules</span></div>
            <div class="stat"><strong><?php echo dashboard_e($access['peer_id_whitelist']); ?></strong><span class="muted">Allowed peer ID rules</span></div>
        </div>
    </section>

    <section>
        <h2>Active Torrents</h2>
        <?php if (count($torrents) === 0): ?>
            <div class="empty">No active torrents.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Info Hash</th>
                        <th>Peers</th>
                        <th>Seeders</th>
                        <th>Leechers</th>
                        <th>Uploaded</th>
                        <th>Downloaded</th>
                        <th>Remaining</th>
                        <th>Last Announce</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($torrents as $torrent): ?>
                        <tr>
                            <td><code><?php echo dashboard_e(tracker_hex($torrent['info_hash'])); ?></code></td>
                            <td><span class="pill"><?php echo dashboard_e($torrent['peers']); ?></span></td>
                            <td><?php echo dashboard_e($torrent['seeders']); ?></td>
                            <td><?php echo dashboard_e($torrent['leechers']); ?></td>
                            <td><?php echo dashboard_e(dashboard_bytes($torrent['uploaded'])); ?></td>
                            <td><?php echo dashboard_e(dashboard_bytes($torrent['downloaded'])); ?></td>
                            <td><?php echo dashboard_e(dashboard_bytes($torrent['remaining'])); ?></td>
                            <td><?php echo dashboard_e($torrent['last_announce']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section>
        <h2>Recent Active Peers</h2>
        <?php if (count($peers) === 0): ?>
            <div class="empty">No active peers.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>IP</th>
                        <th>Port</th>
                        <th>Peer ID</th>
                        <th>Info Hash</th>
                        <th>Downloaded</th>
                        <th>Left</th>
                        <th>Updated</th>
                        <th>Expires</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($peers as $peer): ?>
                        <tr>
                            <td><?php echo dashboard_e(long2ip((int) $peer['ip'])); ?></td>
                            <td><?php echo dashboard_e($peer['port']); ?></td>
                            <td><code><?php echo dashboard_e(tracker_hex($peer['peer_id'])); ?></code></td>
                            <td><code><?php echo dashboard_e(tracker_hex($peer['info_hash'])); ?></code></td>
                            <td><?php echo dashboard_e(dashboard_bytes($peer['downloaded'])); ?></td>
                            <td><?php echo dashboard_e(dashboard_bytes($peer['left'])); ?></td>
                            <td><?php echo dashboard_e($peer['update_time']); ?></td>
                            <td><?php echo dashboard_e($peer['expire_time']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<footer>
    JSON view is available with <code>?format=json</code><?php echo tracker_config('dashboard_key', '') === '' ? '.' : ' and the same dashboard key.'; ?>
</footer>
</body>
</html>
