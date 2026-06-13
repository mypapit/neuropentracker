#
# Neuropentracker
# Summary: Creates the in-memory peer table used by the tracker endpoints.
# Copyright (c) 2026 Neuropentracker contributors.
# License: BSD-2-Clause.
# Compatible with current MySQL and MariaDB versions.
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
) ENGINE=MEMORY DEFAULT CHARSET=binary;
