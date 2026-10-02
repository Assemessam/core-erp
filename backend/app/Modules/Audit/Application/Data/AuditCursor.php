<?php

namespace App\Modules\Audit\Application\Data;

use App\Modules\Audit\Application\Exceptions\AuditQueryInvalid;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final readonly class AuditCursor
{
    public const int MAX_LENGTH = 256;

    public function __construct(public string $createdAt, public string $id) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'v' => 1, 'created_at' => $this->createdAt, 'id' => $this->id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public static function decode(mixed $encoded): self
    {
        $invalid = AuditQueryInvalid::field('cursor', 'The cursor is invalid.');
        if (! is_string($encoded) || strlen($encoded) > self::MAX_LENGTH
            || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            throw $invalid;
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($json === false || rtrim(strtr(base64_encode($json), '+/', '-_'), '=') !== $encoded) {
            throw $invalid;
        }
        try {
            $value = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $invalid;
        }
        if (! is_array($value) || count($value) !== 3
            || ! array_key_exists('v', $value) || ! array_key_exists('created_at', $value) || ! array_key_exists('id', $value)
            || $value['v'] !== 1 || ! is_string($value['created_at']) || ! is_string($value['id'])
            || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/iD', $value['id']) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value['created_at']) !== 1) {
            throw $invalid;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value['created_at'], new DateTimeZone('UTC'));
        if ($date === false || substr($value['created_at'], 0, 4) === '0000' || $date->format('Y-m-d\TH:i:s.u\Z') !== $value['created_at']) {
            throw $invalid;
        }

        return new self($value['created_at'], $value['id']);
    }
}
