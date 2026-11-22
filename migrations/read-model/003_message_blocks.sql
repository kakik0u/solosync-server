-- Do not inherit a utf8mb3 database default: both body and Embed JSON can
-- contain four-byte characters and are replayable from the original packs.
ALTER TABLE projection_messages
  MODIFY COLUMN content MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  ADD COLUMN blocks_json MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL;
