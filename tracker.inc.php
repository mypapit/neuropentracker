<?php
/*
 * Neuropentracker
 * Summary: Provides shared database, response, IP, and access-list helpers.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 * Compatible with PHP 7.4 through PHP 8.3.
 */

require_once __DIR__ . '/bencoding.inc.php';
require_once __DIR__ . '/config.inc.php';

function tracker_config(string $name, $default = null)
{
    return array_key_exists($name, $GLOBALS) ? $GLOBALS[$name] : $default;
}

function tracker_failure(string $reason): void
{
    header('Content-Type: text/plain');
    exit(bencode(array('failure reason' => $reason)));
}

function tracker_db(): mysqli
{
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }

    mysqli_report(MYSQLI_REPORT_OFF);

    $server = tracker_config('db_server', 'localhost');
    $user = tracker_config('db_user', '');
    $pass = tracker_config('db_pass', '');
    $name = tracker_config('db_db', '');
    $port = (int) tracker_config('db_port', ini_get('mysqli.default_port') ?: 3306);
    $socket = tracker_config('db_socket', null);

    $db = @new mysqli($server, $user, $pass, $name, $port, $socket);
    if ($db->connect_errno) {
        tracker_failure('database unavailable');
    }

    @$db->set_charset('binary');
    return $db;
}

function tracker_table_name(): string
{
    $table = (string) tracker_config('db_table', 'peers');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        tracker_failure('invalid database table name');
    }

    return '`' . $table . '`';
}

function tracker_query(string $sql): mysqli_result
{
    $result = @tracker_db()->query($sql);
    if (!$result instanceof mysqli_result) {
        tracker_failure('database error');
    }

    return $result;
}

function tracker_exec(string $sql): void
{
    if (@tracker_db()->query($sql) === false) {
        tracker_failure('database error');
    }
}

function tracker_scalar(string $sql): int
{
    $result = tracker_query($sql);
    $row = $result->fetch_row();
    $result->free();

    return (int) ($row[0] ?? 0);
}

function tracker_stmt(string $sql, string $types = '', array $params = array()): mysqli_stmt
{
    $stmt = @tracker_db()->prepare($sql);
    if (!$stmt instanceof mysqli_stmt) {
        tracker_failure('database error');
    }

    if ($types !== '') {
        $refs = array();
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }

        if (!@$stmt->bind_param($types, ...$refs)) {
            tracker_failure('database error');
        }
    }

    if (!@$stmt->execute()) {
        tracker_failure('database error');
    }

    return $stmt;
}

function tracker_stmt_result(mysqli_stmt $stmt): mysqli_result
{
    $result = @$stmt->get_result();
    if (!$result instanceof mysqli_result) {
        tracker_failure('database error');
    }

    return $result;
}

function tracker_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function tracker_resolve_ip(string $host)
{
    $ip = ip2long($host);
    if ($ip === false) {
        $resolved = gethostbyname($host);
        $ip = ip2long($resolved);
    }

    if ($ip === false) {
        return false;
    }

    return (int) sprintf('%u', $ip);
}

function tracker_ip_rule_matches(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '') {
        return false;
    }

    if (strpos($rule, '/') !== false) {
        list($network, $prefix) = explode('/', $rule, 2);
        $prefix = (int) $prefix;
        if ($prefix < 0 || $prefix > 32) {
            return false;
        }

        $ipInt = tracker_resolve_ip($ip);
        $networkInt = tracker_resolve_ip($network);
        if ($ipInt === false || $networkInt === false) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
        return (($ipInt & $mask) === ($networkInt & $mask));
    }

    $ruleIp = tracker_resolve_ip($rule);
    $ipInt = tracker_resolve_ip($ip);

    return $ruleIp !== false && $ipInt !== false && $ruleIp === $ipInt;
}

function tracker_ip_list_matches(string $ip, array $rules): bool
{
    foreach ($rules as $rule) {
        if (tracker_ip_rule_matches($ip, (string) $rule)) {
            return true;
        }
    }

    return false;
}

function tracker_peer_id_rule_matches(string $peerId, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '') {
        return false;
    }

    $peerHex = strtolower(bin2hex($peerId));
    if (strpos($rule, 'hexprefix:') === 0) {
        return strpos($peerHex, strtolower(substr($rule, 10))) === 0;
    }

    if (strpos($rule, 'hex:') === 0) {
        return hash_equals($peerHex, strtolower(substr($rule, 4)));
    }

    if (strpos($rule, 'prefix:') === 0) {
        return strpos($peerId, substr($rule, 7)) === 0;
    }

    return hash_equals($peerId, $rule);
}

function tracker_peer_id_list_matches(string $peerId, array $rules): bool
{
    foreach ($rules as $rule) {
        if (tracker_peer_id_rule_matches($peerId, (string) $rule)) {
            return true;
        }
    }

    return false;
}

function tracker_access_allowed(string $ip, string $peerId, ?string &$reason = null): bool
{
    $ipBlacklist = tracker_config('ip_blacklist', array());
    $ipWhitelist = tracker_config('ip_whitelist', array());
    $peerBlacklist = tracker_config('peer_id_blacklist', array());
    $peerWhitelist = tracker_config('peer_id_whitelist', array());

    if (!is_array($ipBlacklist) || !is_array($ipWhitelist) || !is_array($peerBlacklist) || !is_array($peerWhitelist)) {
        $reason = 'tracker access list configuration is invalid';
        return false;
    }

    if (tracker_ip_list_matches($ip, $ipBlacklist)) {
        $reason = 'ip address is blacklisted';
        return false;
    }

    if (count($ipWhitelist) > 0 && !tracker_ip_list_matches($ip, $ipWhitelist)) {
        $reason = 'ip address is not whitelisted';
        return false;
    }

    if (tracker_peer_id_list_matches($peerId, $peerBlacklist)) {
        $reason = 'peer id is blacklisted';
        return false;
    }

    if (count($peerWhitelist) > 0 && !tracker_peer_id_list_matches($peerId, $peerWhitelist)) {
        $reason = 'peer id is not whitelisted';
        return false;
    }

    return true;
}

function tracker_announce_interval(): int
{
    $table = tracker_table_name();
    $numPeers = tracker_scalar("SELECT COUNT(*) FROM $table WHERE `expire_time` > NOW()");
    $announceRate = tracker_scalar("SELECT COUNT(*) FROM $table WHERE `update_time` > NOW() - INTERVAL 1 MINUTE");
    $maxRate = max(1, (int) tracker_config('max_announce_rate', 500));
    $minimum = (int) tracker_config('min_announce_interval', 900);

    return (int) max(($numPeers * $announceRate / ($maxRate * $maxRate)) * 60, $minimum);
}

function tracker_response_with_id(array $payload): array
{
    $trackerId = (string) tracker_config('tracker_id', '');
    if ($trackerId !== '') {
        $payload['tracker id'] = $trackerId;
    }

    return $payload;
}

function tracker_info_hashes_from_query(): array
{
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $hashes = array();

    foreach (explode('&', $query) as $part) {
        if ($part === '') {
            continue;
        }

        $pair = explode('=', $part, 2);
        $key = urldecode($pair[0]);
        if ($key !== 'info_hash') {
            continue;
        }

        $hashes[] = urldecode($pair[1] ?? '');
    }

    return $hashes;
}

function tracker_dashboard_allowed(): bool
{
    $key = (string) tracker_config('dashboard_key', '');
    return $key === '' || hash_equals($key, (string) ($_GET['key'] ?? ''));
}

function tracker_hex(string $binary): string
{
    return bin2hex($binary);
}
