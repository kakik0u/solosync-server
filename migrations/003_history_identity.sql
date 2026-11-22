CREATE TABLE IF NOT EXISTS sync_history_identity (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  history_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT ck_sync_history_identity_singleton CHECK (id=1)
) ENGINE=InnoDB;
