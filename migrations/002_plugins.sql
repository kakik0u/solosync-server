CREATE TABLE IF NOT EXISTS plugin_installations (
  plugin_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  enabled_at DATETIME(6) NULL,
  installed_version VARCHAR(64) NULL,
  last_error VARCHAR(1000) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plugin_settings (
  plugin_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  schema_version INT UNSIGNED NOT NULL DEFAULT 1,
  settings_json LONGTEXT NOT NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_plugin_settings_installation FOREIGN KEY (plugin_id) REFERENCES plugin_installations(plugin_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plugin_schema_migrations (
  plugin_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (plugin_id,version)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plugin_deliveries (
  event_id BIGINT UNSIGNED NOT NULL,
  plugin_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME(6) NULL,
  last_error VARCHAR(1000) NULL,
  delivered_at DATETIME(6) NULL,
  PRIMARY KEY (event_id,plugin_id),
  INDEX idx_plugin_deliveries_due (status,next_attempt_at,event_id),
  CONSTRAINT fk_plugin_deliveries_event FOREIGN KEY (event_id) REFERENCES plugin_outbox(event_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS update_check_cache (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  latest_tag VARCHAR(64) NULL,
  release_url VARCHAR(512) NULL,
  etag VARCHAR(255) NULL,
  checked_at DATETIME(6) NULL,
  last_error VARCHAR(1000) NULL,
  CONSTRAINT ck_update_check_singleton CHECK (id=1)
) ENGINE=InnoDB;
