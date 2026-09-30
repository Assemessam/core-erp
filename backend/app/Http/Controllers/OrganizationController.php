<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrganization;
use App\Actions\RenameOrganization;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use App\Queries\ListOrganizations;
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
