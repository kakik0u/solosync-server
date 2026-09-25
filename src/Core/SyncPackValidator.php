<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class SyncPackValidator
{
    /** @return array<string,mixed> */
    public static function decode(string $bytes, string $expectedPackId, string $expectedGroupId): array
    {
        try { $pack = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiException(400, 'INVALID_PACK', 'Pack is not valid JSON'); }
        if (!is_array($pack)
            || ($pack['formatVersion'] ?? null) !== 1
            || !is_string($pack['groupId'] ?? null) || !hash_equals($expectedGroupId, $pack['groupId'])
            || !is_string($pack['packId'] ?? null) || !hash_equals($expectedPackId, $pack['packId'])
            || !is_string($pack['uploaderDeviceId'] ?? null) || $pack['uploaderDeviceId'] === ''
            || !is_int($pack['createdAtMs'] ?? null)
            || !is_int($pack['fromLocalCursor'] ?? null)
            || !is_int($pack['toLocalCursor'] ?? null)
            || $pack['fromLocalCursor'] < 0 || $pack['toLocalCursor'] < $pack['fromLocalCursor']
            || !is_array($pack['operations'] ?? null)) {
            throw new ApiException(400, 'INVALID_PACK', 'Invalid Solosync persistent pack');
        }
        foreach ($pack['operations'] as $operation) {
            if (!is_array($operation)
                || !is_string($operation['operationId'] ?? null) || $operation['operationId'] === ''
                || !is_string($operation['originDeviceId'] ?? null) || $operation['originDeviceId'] === ''
                || !is_string($operation['table'] ?? null) || $operation['table'] === ''
                || !is_string($operation['rowId'] ?? null) || $operation['rowId'] === ''
                || !in_array($operation['operation'] ?? null, ['UPSERT', 'DELETE'], true)
                || !is_int($operation['executedAtMs'] ?? null)
                || !is_int($operation['logicalClock'] ?? null)) {
                throw new ApiException(400, 'INVALID_PACK', 'Invalid operation in persistent pack');
            }
        }
        return $pack;
    }
}
