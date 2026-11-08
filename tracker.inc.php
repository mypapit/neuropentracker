<?php
/*
 * Neuropentracker
 * Summary: Provides shared database, response, IP, and access-list helpers.
 * Copyright (c) 2026 Neuropentracker contributors.
 * License: BSD-2-Clause.
 * Requires PHP 8.3 or 8.4 (tested on 8.3.36 and 8.4.27).
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

    /*
     * Refuse a privileged or passwordless account. The shipped default used to
     * be root with an empty password, which is a superuser one guess away on a
     * host where MySQL is not bound to loopback. Failing here is louder and
     * safer than connecting successfully.
     */
    if (!tracker_config('db_allow_privileged', false)) {
        if ((string) $user === 'root' || (string) $pass === '') {
            tracker_failure('database account is privileged or passwordless; see config.inc.php');
        }
    }

    $db = @new mysqli($server, $user, $pass, $name, $port, $socket);
    if ($db->connect_errno) {
        tracker_failure('database unavailable');
    }

    @$db->set_charset('binary');
    return $db;
}

/*
 * How many peer rows the tracker will hold before refusing new ones.
 *
 * The MEMORY engine has no eviction: once max_heap_table_size is hit every
 * INSERT fails and the tracker serves nothing but "database error" until an
 * operator empties the table. MAX_ROWS in the schema is not a reliable ceiling
 * either — MariaDB ignores it when the heap limit is lower, which is exactly
 * the case it was meant to protect.
 *
 * So the cap is enforced here, below the heap ceiling, where it can produce a
 * comprehensible failure instead of a silent brick.
 */
function tracker_peer_capacity(): int
{
    return max(1, (int) tracker_config('max_peers', 40000));
}

/*
 * True when there is no room for another peer.
 *
 * COUNT(*) on a MEMORY table is a constant-time row count (measured 0.026 ms at
 * 52k rows), so this is cheap enough to run on every announce.
 */
function tracker_peer_table_full(): bool
{
    $table = tracker_table_name();
    $count = tracker_scalar("SELECT COUNT(*) FROM $table");

    return $count >= tracker_peer_capacity();
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

/*
 * Parse an IPv4 literal. Returns an unsigned int, or false.
 *
 * Deliberately does NOT fall back to gethostbyname(): a tracker was never
 * meant to resolve attacker-supplied names, and doing so allowed any anonymous
 * client to (a) make the server do a blocking DNS lookup per request and
 * (b) use the resolve/no-resolve distinction as an internal hostname oracle,
 * with the resolved internal address then published in the peer list.
 */
function tracker_resolve_ip(string $host)
{
    $host = trim($host);
    if ($host === '' || strpos($host, "\0") !== false) {
        return false;
    }

    // ip2long() accepts shorthands such as "127.1"; require a dotted quad so
    // that access-list text and stored addresses cannot disagree.
    if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host)) {
        return false;
    }

    $ip = ip2long($host);
    if ($ip === false) {
        return false;
    }

    return (int) sprintf('%u', $ip);
}

/*
 * The address this announce is allowed to claim, and the address access lists
 * are evaluated against.
 *
 * The socket address (REMOTE_ADDR) is authoritative. A ?ip= override is honoured
 * only when $trust_forwarded_ip is explicitly enabled in config.inc.php.
 *
 * Trust is deliberately NOT inferred from the source address being private or
 * loopback. Behind a reverse proxy on the same host, REMOTE_ADDR is 127.0.0.1
 * for every client, so "it came from loopback" would have re-opened the bypass
 * for every proxied deployment. An explicit opt-in cannot be fooled that way.
 *
 * Returns array(socketIp:int, claimedIp:int).
 */
function tracker_announce_addresses(): array
{
    $socketRaw = tracker_client_ip();
    $socketIp = tracker_resolve_ip($socketRaw);

    if ($socketIp === false) {
        tracker_failure('unable to determine client address');
    }

    $claimedIp = $socketIp;

    if (!isset($_GET['ip']) || !is_string($_GET['ip']) || $_GET['ip'] === '') {
        return array($socketIp, $claimedIp);
    }

    /*
     * Validate the claim whether or not we intend to honour it. Silently
     * accepting garbage hides probing and misconfiguration behind a normal
     * response, and "0" used to be stored and published as 0.0.0.0.
     */
    $claimed = tracker_resolve_ip((string) $_GET['ip']);
    if ($claimed === false || $claimed === 0) {
        tracker_failure('invalid ip parameter');
    }

    if (tracker_config('trust_forwarded_ip', false)) {
        $claimedIp = $claimed;
    }

    return array($socketIp, $claimedIp);
}

/*
 * Emit hardening headers. Called from each endpoint. There is no session and
 * no script on the tracker endpoints, so a strict CSP is safe there; the
 * dashboard embeds its stylesheet inline and needs style-src 'unsafe-inline'.
 */
function tracker_security_headers(bool $allowInlineStyle = false): void
{
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        $style = $allowInlineStyle ? "'unsafe-inline'" : "'none'";
        header("Content-Security-Policy: default-src 'none'; style-src $style; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    }

    // Do not advertise the PHP version.
    header_remove('X-Powered-By');
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
    $maximum = (int) tracker_config('max_announce_interval', 1800);

    if ($maximum < $minimum) {
        $maximum = $minimum;
    }

    $computed = (int) (($numPeers * $announceRate / ($maxRate * $maxRate)) * 60);

    /*
     * Clamp at both ends. Both operands are attacker-influenced: a client can
     * register thousands of peers to drive $numPeers up, which previously let
     * the advertised interval climb past 24 hours. Once clients honour a
     * day-long interval the swarm stops refreshing and quietly empties.
     */
    return (int) min(max($computed, $minimum), $maximum);
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

    /*
     * An empty key is a misconfiguration, not "no check required". Failing
     * closed here is what stops a default install from publishing the peer
     * list to the internet.
     */
    if ($key === '') {
        return false;
    }

    $presented = '';

    // Preferred: a header, so the secret never reaches an access log.
    if (isset($_SERVER['HTTP_X_DASHBOARD_KEY'])) {
        $presented = (string) $_SERVER['HTTP_X_DASHBOARD_KEY'];
    } elseif (isset($_GET['key']) && is_string($_GET['key'])) {
        $presented = (string) $_GET['key'];
    }

    return $presented !== '' && hash_equals($key, $presented);
}

/*
 * Reduce a peer address to its network for display, so the dashboard shows
 * swarm shape without handing out a list of identifiable downloaders.
 */
function tracker_redact_ip(int $ip): string
{
    return long2ip($ip & 0xFFFFFF00) . '/24';
}

function tracker_hex(string $binary): string
{
    return bin2hex($binary);
}
