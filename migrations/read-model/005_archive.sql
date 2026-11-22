ALTER TABLE projection_messages ADD COLUMN archived_at VARCHAR(40) NULL;
ALTER TABLE projection_channels ADD COLUMN archived_at VARCHAR(40) NULL;
CREATE INDEX projection_messages_archive ON projection_messages(channel_id,archived_at,timestamp_ms);
