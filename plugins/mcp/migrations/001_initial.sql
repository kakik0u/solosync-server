CREATE TABLE IF NOT EXISTS plugin_mcp_tokens (
  token_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  kind VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  secret_hash BINARY(32) NOT NULL UNIQUE,
  scope_json TEXT NOT NULL,
  last_seen DATETIME(6) NULL,
  rate_window BIGINT NOT NULL DEFAULT 0,
  rate_count INT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_at DATETIME(6) NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS plugin_mcp_requests (
  request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  token_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  worker_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  group_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  generation BIGINT UNSIGNED NOT NULL,
  name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  arguments_json TEXT NOT NULL,
  state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  result_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  expires_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_mcp_pending (worker_id,state,expires_at),
  INDEX idx_mcp_expiry (expires_at)
) ENGINE=InnoDB;
