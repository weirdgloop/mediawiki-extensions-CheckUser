-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/CheckUser/schema/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/cu_changes (
  cuc_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  cuc_namespace INTEGER DEFAULT 0 NOT NULL,
  cuc_title BLOB DEFAULT '' NOT NULL,
  cuc_actor BIGINT UNSIGNED NOT NULL,
  cuc_actiontext BLOB DEFAULT '' NOT NULL,
  cuc_comment_id BIGINT UNSIGNED NOT NULL,
  cuc_minor SMALLINT DEFAULT 0 NOT NULL,
  cuc_page_id INTEGER UNSIGNED DEFAULT 0 NOT NULL,
  cuc_this_oldid INTEGER UNSIGNED DEFAULT 0 NOT NULL,
  cuc_last_oldid INTEGER UNSIGNED DEFAULT 0 NOT NULL,
  cuc_type SMALLINT UNSIGNED DEFAULT 0 NOT NULL,
  cuc_timestamp BLOB NOT NULL,
  cuc_ip VARCHAR(255) DEFAULT '',
  cuc_ip_hex VARCHAR(255) DEFAULT NULL,
  cuc_xff BLOB DEFAULT '',
  cuc_xff_hex VARCHAR(255) DEFAULT NULL,
  cuc_agent BLOB DEFAULT NULL,
  cuc_private BLOB DEFAULT NULL,
  cuc_only_for_read_old SMALLINT DEFAULT 0 NOT NULL
);

CREATE INDEX cuc_ip_hex_time ON /*_*/cu_changes (cuc_ip_hex, cuc_timestamp);

CREATE INDEX cuc_xff_hex_time ON /*_*/cu_changes (cuc_xff_hex, cuc_timestamp);

CREATE INDEX cuc_timestamp ON /*_*/cu_changes (cuc_timestamp);

CREATE INDEX cuc_actor_ip_time ON /*_*/cu_changes (cuc_actor, cuc_ip, cuc_timestamp);


CREATE TABLE /*_*/cu_log_event (
  cule_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  cule_log_id INTEGER UNSIGNED DEFAULT 0 NOT NULL,
  cule_actor BIGINT UNSIGNED NOT NULL,
  cule_timestamp BLOB NOT NULL,
  cule_ip VARCHAR(255) DEFAULT '',
  cule_ip_hex VARCHAR(255) DEFAULT NULL,
  cule_xff BLOB DEFAULT '',
  cule_xff_hex VARCHAR(255) DEFAULT NULL,
  cule_agent BLOB DEFAULT NULL
);

CREATE INDEX cule_ip_hex_time ON /*_*/cu_log_event (cule_ip_hex, cule_timestamp);

CREATE INDEX cule_xff_hex_time ON /*_*/cu_log_event (cule_xff_hex, cule_timestamp);

CREATE INDEX cule_timestamp ON /*_*/cu_log_event (cule_timestamp);

CREATE INDEX cule_actor_ip_time ON /*_*/cu_log_event (
  cule_actor, cule_ip, cule_timestamp
);


CREATE TABLE /*_*/cu_private_event (
  cupe_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  cupe_namespace INTEGER DEFAULT 0 NOT NULL,
  cupe_title BLOB DEFAULT '' NOT NULL,
  cupe_actor BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  cupe_log_type BLOB DEFAULT '' NOT NULL,
  cupe_log_action BLOB DEFAULT '' NOT NULL,
  cupe_params BLOB NOT NULL,
  cupe_comment_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  cupe_page INTEGER UNSIGNED DEFAULT 0 NOT NULL,
  cupe_timestamp BLOB NOT NULL,
  cupe_ip VARCHAR(255) DEFAULT '',
  cupe_ip_hex VARCHAR(255) DEFAULT NULL,
  cupe_xff BLOB DEFAULT '',
  cupe_xff_hex VARCHAR(255) DEFAULT NULL,
  cupe_agent BLOB DEFAULT NULL,
  cupe_private BLOB DEFAULT NULL
);

CREATE INDEX cupe_ip_hex_time ON /*_*/cu_private_event (cupe_ip_hex, cupe_timestamp);

CREATE INDEX cupe_xff_hex_time ON /*_*/cu_private_event (cupe_xff_hex, cupe_timestamp);

CREATE INDEX cupe_timestamp ON /*_*/cu_private_event (cupe_timestamp);

CREATE INDEX cupe_actor_ip_time ON /*_*/cu_private_event (
  cupe_actor, cupe_ip, cupe_timestamp
);


CREATE TABLE /*_*/cu_useragent_clienthints (
  uach_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  uach_name VARCHAR(32) NOT NULL,
  uach_value VARCHAR(255) NOT NULL
);

CREATE UNIQUE INDEX uach_name_value ON /*_*/cu_useragent_clienthints (uach_name, uach_value);


CREATE TABLE /*_*/cu_useragent_clienthints_map (
  uachm_uach_id INTEGER UNSIGNED NOT NULL,
  uachm_reference_id INTEGER UNSIGNED NOT NULL,
  uachm_reference_type SMALLINT DEFAULT 0 NOT NULL,
  PRIMARY KEY(
    uachm_uach_id, uachm_reference_type,
    uachm_reference_id
  )
);

CREATE INDEX uachm_reference_id ON /*_*/cu_useragent_clienthints_map (uachm_reference_id);


CREATE TABLE /*_*/cu_log (
  cul_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  cul_timestamp BLOB NOT NULL, cul_actor BIGINT UNSIGNED NOT NULL,
  cul_reason_id BIGINT UNSIGNED NOT NULL,
  cul_reason_plaintext_id BIGINT UNSIGNED NOT NULL,
  cul_result_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  cul_result_plaintext_id BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  cul_type BLOB NOT NULL, cul_target_id INTEGER UNSIGNED DEFAULT 0 NOT NULL,
  cul_target_text BLOB NOT NULL, cul_target_hex BLOB DEFAULT '' NOT NULL,
  cul_range_start BLOB DEFAULT '' NOT NULL,
  cul_range_end BLOB DEFAULT '' NOT NULL
);

CREATE INDEX cul_actor_time ON /*_*/cu_log (cul_actor, cul_timestamp);

CREATE INDEX cul_type_target ON /*_*/cu_log (
  cul_type, cul_target_id, cul_timestamp
);

CREATE INDEX cul_target_hex ON /*_*/cu_log (cul_target_hex, cul_timestamp);

CREATE INDEX cul_range_start ON /*_*/cu_log (cul_range_start, cul_timestamp);

CREATE INDEX cul_timestamp ON /*_*/cu_log (cul_timestamp);
