-- This file is automatically generated using maintenance/generateSchemaChangeSql.php.
-- Source: extensions/CheckUser/schema/abstractSchemaChanges/patch-cu_log-drop-cul_reason_id_default.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
ALTER TABLE  /*_*/cu_log
CHANGE  cul_reason_id cul_reason_id BIGINT UNSIGNED NOT NULL,
CHANGE  cul_reason_plaintext_id cul_reason_plaintext_id BIGINT UNSIGNED NOT NULL;