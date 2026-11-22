<?php
declare(strict_types=1);

// Plugins run after the sync transaction commits. They receive plaintext
// operation payloads and should use eventId as an idempotency key.
return static function (array $event): void {
    // Example: inspect $event['type'] and $event['payload'].
};
