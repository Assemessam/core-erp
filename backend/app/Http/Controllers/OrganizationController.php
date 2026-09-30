<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrganization;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            Organization::query()->whereHas('memberships', fn ($query) => $query->where('user_id', auth()->id()))
                ->orderBy('name')->get(),
        );
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

    public function update(UpdateOrganizationRequest $request, Organization $organization): OrganizationResource
    {
        $organization->update($request->validated());

        return new OrganizationResource($organization);
    }
}
