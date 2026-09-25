CREATE TABLE IF NOT EXISTS sync_instance (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  group_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  group_metadata LONGTEXT NULL,
  generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
  head_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
  used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 1073741824,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT ck_sync_instance_singleton CHECK (id=1),
  CONSTRAINT ck_sync_instance_group CHECK ((group_id IS NULL AND group_metadata IS NULL) OR (group_id IS NOT NULL AND group_metadata IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sync_devices (
  device_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  display_name VARCHAR(160) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_seen_at DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sync_credentials (
  token_hash BINARY(32) NOT NULL PRIMARY KEY,
  device_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_used_at DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  INDEX idx_sync_credentials_device (device_id,revoked_at),
  CONSTRAINT fk_sync_credentials_device FOREIGN KEY (device_id) REFERENCES sync_devices(device_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sync_connection_secrets (
  secret_hash BINARY(32) NOT NULL PRIMARY KEY,
  secret_id CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  label VARCHAR(120) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_used_at DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sync_packs (
  pack_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  sequence BIGINT UNSIGNED NOT NULL UNIQUE,
  digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  byte_length INT UNSIGNED NOT NULL,
  uploader_device_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  bytes MEDIUMBLOB NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_sync_packs_sequence (sequence),
  CONSTRAINT fk_sync_packs_device FOREIGN KEY (uploader_device_id) REFERENCES sync_devices(device_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plugin_outbox (
  event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload LONGTEXT NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(1000) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  delivered_at DATETIME(6) NULL,
  INDEX idx_plugin_outbox_pending (delivered_at,event_id)
) ENGINE=InnoDB;
