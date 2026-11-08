#
# Neuropentracker
# Summary: Creates the in-memory peer table used by the tracker endpoints.
# Copyright (c) 2026 Neuropentracker contributors.
# License: BSD-2-Clause.
# Compatible with current MySQL and MariaDB versions.
#
# MAX_ROWS is a deliberate safety valve. The MEMORY engine has no eviction
# policy: once the table reaches max_heap_table_size every INSERT fails with
# "The table 'peers' is full" and the tracker serves nothing but errors until
# an operator empties it. An explicit row cap fails earlier, at a size the
# configured heap is guaranteed to have room for, so a burst degrades instead
# of bricking the process. Keep clean.php on a cron schedule alongside it.
#

DROP TABLE IF EXISTS `peers`;
CREATE TABLE `peers` (
  `info_hash` binary(20) NOT NULL,
  `ip` int unsigned NOT NULL,
  `port` smallint unsigned NOT NULL,
  `peer_id` binary(20) NOT NULL,
  `uploaded` bigint unsigned NOT NULL default 0,
  `downloaded` bigint unsigned NOT NULL default 0,
  `left` bigint unsigned NOT NULL default 0,
  `update_time` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
  `expire_time` timestamp NOT NULL default CURRENT_TIMESTAMP,
  PRIMARY KEY (`info_hash`, `ip`, `port`),
  KEY `expire_time` (`expire_time`),
  KEY `update_time` (`update_time`),
  KEY `info_hash_expire` (`info_hash`, `expire_time`),
  KEY `peer_id` (`peer_id`)
) ENGINE=MEMORY MAX_ROWS=50000 DEFAULT CHARSET=binary;
