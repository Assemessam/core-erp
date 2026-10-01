<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Organization\Application\Auditing\OrganizationAuditEntries;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Illuminate\Support\Facades\DB;

/** Callers validate input and supply the trusted actor identity explicitly. */
class RenameOrganization
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly AuditRecorder $audit,
        private readonly OrganizationAuditEntries $entries,
    ) {}

    public function handle(int $actorUserId, Organization $organization, string $name): Organization
    {
        $persisted = DB::transaction(function () use ($actorUserId, $organization, $name): Organization {
            $this->access->update($actorUserId, $organization->id)->requireAllowed();
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $beforeName = $locked->name;
            if ($beforeName !== $name) {
                $locked->update(['name' => $name]);
                $this->audit->record($this->entries->organizationRenamed($locked->id, $actorUserId, $beforeName, $locked->name));
            }

            return $locked;
        });
        // Keep the caller's instance, without saving its stale or dirty attributes.
        // Synchronize only after the transaction succeeds, including on a no-op.
        $organization->setRawAttributes($persisted->getAttributes(), true);

        return $organization;
    }
}
