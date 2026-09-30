<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\RenameOrganization;
use App\Modules\Organization\Application\Queries\ListOrganizations;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Presentation\Http\Requests\StoreOrganizationRequest;
use App\Modules\Organization\Presentation\Http\Requests\UpdateOrganizationRequest;
use App\Modules\Organization\Presentation\Http\Resources\OrganizationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    public function index(Request $request, ListOrganizations $list): AnonymousResourceCollection
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return OrganizationResource::collection($list->handle($actor->id));
    }

    public function store(StoreOrganizationRequest $request, CreateOrganization $create): OrganizationResource
    {
        $owner = $request->user();
        assert($owner instanceof User);
        $organization = $create->handle($owner, $request->validated('name'));

        return new OrganizationResource($organization);
    }

    public function show(Organization $organization): OrganizationResource
    {
        Gate::authorize('view', $organization);

        return new OrganizationResource($organization);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization, RenameOrganization $rename): OrganizationResource
    {
        return new OrganizationResource($rename->handle($organization, $request->validated('name')));
    }
}
