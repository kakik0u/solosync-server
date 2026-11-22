CREATE TABLE IF NOT EXISTS projection_revision (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  revision INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT ck_projection_revision_singleton CHECK (id=1)
) ENGINE=InnoDB;

INSERT INTO projection_revision(id,revision) VALUES (1,0)
  ON DUPLICATE KEY UPDATE id=id;

CREATE TABLE IF NOT EXISTS projection_guilds (
  id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projection_categories (
  id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
  guild_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  name VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  INDEX idx_projection_categories_guild (guild_id,sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projection_channel_details (
  channel_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
  category_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  topic TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projection_tags (
  id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'general'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS projection_message_tags (
  message_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  tag_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'manual',
  PRIMARY KEY (message_id,tag_id),
  INDEX idx_projection_message_tags_tag (tag_id,message_id)
) ENGINE=InnoDB;
