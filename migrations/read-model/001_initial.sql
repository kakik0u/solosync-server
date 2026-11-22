CREATE TABLE IF NOT EXISTS projection_state (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  group_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
  sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'building',
  last_error VARCHAR(1000) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT ck_projection_singleton CHECK (id=1)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projection_record_versions (
  table_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  row_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  operation_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  origin_device_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  executed_at_ms BIGINT NOT NULL,
  logical_clock BIGINT NOT NULL,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (table_name,row_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projection_channels (
  id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  guild_id VARCHAR(255) NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projection_profiles (
  id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projection_messages (
  id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
  channel_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  content MEDIUMTEXT NOT NULL,
  timestamp VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  timestamp_ms BIGINT NOT NULL,
  edited_timestamp VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  user_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  INDEX idx_projection_messages_channel (channel_id,timestamp_ms,id)
) ENGINE=InnoDB;

INSERT INTO projection_state(id) VALUES (1) ON DUPLICATE KEY UPDATE id=id;
