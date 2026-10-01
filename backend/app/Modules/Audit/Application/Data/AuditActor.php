<?php

namespace App\Modules\Audit\Application\Data;

use App\Modules\Audit\Application\Exceptions\AuditWriteFailed;
use App\Modules\Audit\Application\Vocabulary\AuditActorType;

final readonly class AuditActor
{
    public function __construct(public AuditActorType $type, public ?int $userId)
    {
        if (($type === AuditActorType::User && ($userId === null || $userId <= 0))
            || ($type === AuditActorType::System && $userId !== null)) {
            throw new AuditWriteFailed('invalid_actor');
        }
    }

    public static function user(int $userId): self
    {
        return new self(AuditActorType::User, $userId);
    }

    public static function system(): self
    {
        return new self(AuditActorType::System, null);
    }
}
