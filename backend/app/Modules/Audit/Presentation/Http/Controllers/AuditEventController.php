<?php

namespace App\Modules\Audit\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\Queries\ListAuditEvents;
use App\Modules\Audit\Presentation\Http\Requests\ListAuditEventsRequest;
use App\Modules\Audit\Presentation\Http\Resources\AuditEventResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditEventController extends Controller
{
    public function __invoke(ListAuditEventsRequest $request, string $organization, ListAuditEvents $list): AnonymousResourceCollection
    {
        $page = $list->handle($request->actorUserId(), $organization, $request->query->all());

        return AuditEventResource::collection($page->events)->additional(['meta' => [
            'next_cursor' => $page->nextCursor, 'has_more' => $page->hasMore, 'per_page' => $page->perPage,
        ]]);
    }
}
