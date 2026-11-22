CREATE TABLE IF NOT EXISTS plugin_webhook_endpoints (
  endpoint_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  label VARCHAR(120) NOT NULL,
  channel_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  secret_hash BINARY(32) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_at DATETIME(6) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plugin_webhook_requests (
  endpoint_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_hash BINARY(32) NOT NULL,
  content_hash BINARY(32) NOT NULL,
  message_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  pack_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (endpoint_id,idempotency_hash),
  INDEX idx_webhook_requests_age (endpoint_id,created_at),
  CONSTRAINT fk_webhook_request_endpoint FOREIGN KEY (endpoint_id) REFERENCES plugin_webhook_endpoints(endpoint_id) ON DELETE CASCADE
) ENGINE=InnoDB;
