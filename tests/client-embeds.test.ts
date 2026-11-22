import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { cloneBlock, normalizeBlock, validateStoredBlocks } from '../../solocord/src/features/messageBlocks/validation';

test('PHP webhook blocks match the actual Solocord v1 validator and summaries', () => {
  const fixtures = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./embed-fixtures.php', import.meta.url))], { encoding: 'utf8' }));
  for (const fixture of fixtures) {
    const envelope = JSON.parse(fixture.blocksJson);
    assert.deepEqual(JSON.parse(validateStoredBlocks(fixture.blocksJson)), envelope, fixture.name);
    for (const block of envelope.blocks) assert.deepEqual(normalizeBlock(cloneBlock(block)), block, fixture.name);
  }
});
