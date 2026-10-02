<?php

namespace App\Modules\Audit\Application\Queries;

use App\Modules\Audit\Application\Contracts\AuditEventReader;
use App\Modules\Audit\Application\Contracts\AuditHistoryAccess;
use App\Modules\Audit\Application\Data\AuditEventPage;
use App\Modules\Audit\Application\Validation\AuditQueryValidator;

final class ListAuditEvents
{
    public function __construct(
        private readonly AuditHistoryAccess $access,
        private readonly AuditQueryValidator $validator,
        private readonly AuditEventReader $reader,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(int $actorUserId, string $organizationId, array $input = []): AuditEventPage
    {
        $this->access->assertCanView($actorUserId, $organizationId);

        return $this->reader->read($organizationId, $this->validator->validate($input));
    }
}
