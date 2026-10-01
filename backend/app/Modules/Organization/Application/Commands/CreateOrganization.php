<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Organization\Application\Auditing\OrganizationAuditEntries;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function __construct(private readonly AuditRecorder $audit, private readonly OrganizationAuditEntries $entries) {}

    public function handle(int $ownerUserId, string $name): Organization
    {
        return DB::transaction(function () use ($ownerUserId, $name): Organization {
            $organization = Organization::query()->create([
                'name' => $name,
                'owner_user_id' => $ownerUserId,
            ]);
            $membership = $organization->memberships()->create(['user_id' => $ownerUserId]);
            $this->audit->record($this->entries->organizationCreated($organization->id, $ownerUserId, $organization->name, $membership->id));

            return $organization;
        });
    }
}
