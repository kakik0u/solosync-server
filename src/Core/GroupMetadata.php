<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class GroupMetadata
{
    /** @param array<string,mixed> $value @return array{formatVersion:int,groupId:string,groupKey:string,createdAtMs:int,relayUrl?:string} */
    public static function validate(array $value): array
    {
        if (($value['formatVersion'] ?? null) !== 1
            || !is_string($value['groupId'] ?? null) || !preg_match('/^[0-9a-f]{64}$/D', $value['groupId'])
            || !is_string($value['groupKey'] ?? null)
            || !preg_match('/^[A-Za-z0-9._:-]{6,128}$/D', $value['groupKey'])
            || !is_int($value['createdAtMs'] ?? null) || $value['createdAtMs'] < 0) {
            throw new ApiException(400, 'INVALID_GROUP_METADATA', 'Invalid Solosync Group metadata');
        }
        $derived = hash('sha256', "Solosync/v2/group\0" . $value['groupKey']);
        if (!hash_equals($derived, $value['groupId'])) {
            throw new ApiException(400, 'INVALID_GROUP_METADATA', 'Group ID does not match the Group key');
        }
        $result = [
            'formatVersion' => 1,
            'groupId' => $value['groupId'],
            'groupKey' => $value['groupKey'],
            'createdAtMs' => $value['createdAtMs'],
        ];
        if (array_key_exists('relayUrl', $value)) {
            if (!is_string($value['relayUrl']) || strlen($value['relayUrl']) > 2048) {
                throw new ApiException(400, 'INVALID_GROUP_METADATA', 'Invalid relay URL');
            }
            $result['relayUrl'] = $value['relayUrl'];
        }
        return $result;
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    public static function sameIdentity(array $a, array $b): bool
    {
        return isset($a['groupId'], $a['groupKey'], $b['groupId'], $b['groupKey'])
            && is_string($a['groupId']) && is_string($a['groupKey'])
            && is_string($b['groupId']) && is_string($b['groupKey'])
            && hash_equals($a['groupId'], $b['groupId'])
            && hash_equals($a['groupKey'], $b['groupKey']);
    }
}
