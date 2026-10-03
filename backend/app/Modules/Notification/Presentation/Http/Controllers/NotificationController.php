<?php

namespace App\Modules\Notification\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Application\Commands\MarkAllNotificationsRead;
use App\Modules\Notification\Application\Commands\MarkNotificationRead;
use App\Modules\Notification\Application\Queries\GetUnreadNotificationCount;
use App\Modules\Notification\Application\Queries\ListNotifications;
use App\Modules\Notification\Presentation\Http\Requests\ListNotificationsRequest;
use App\Modules\Notification\Presentation\Http\Resources\NotificationResource;
use App\Modules\Notification\Presentation\Http\Resources\UnreadNotificationCountResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class NotificationController extends Controller
{
    public function index(ListNotificationsRequest $request, string $organization, ListNotifications $list): AnonymousResourceCollection
    {
        $page = $list->handle($request->actorUserId(), $organization, $request->query->all());

        return NotificationResource::collection($page->notifications)->additional(['meta' => [
            'next_cursor' => $page->nextCursor, 'has_more' => $page->hasMore, 'per_page' => $page->perPage,
        ]]);
    }

    public function unreadCount(Request $request, string $organization, GetUnreadNotificationCount $count): UnreadNotificationCountResource
    {
        return new UnreadNotificationCountResource($count->handle($this->actorUserId($request), $organization));
    }

    public function read(Request $request, string $organization, string $notification, MarkNotificationRead $mark): Response
    {
        $mark->handle($this->actorUserId($request), $organization, $notification);

        return response()->noContent();
    }

    public function readAll(Request $request, string $organization, MarkAllNotificationsRead $mark): Response
    {
        $mark->handle($this->actorUserId($request), $organization);

        return response()->noContent();
    }

    private function actorUserId(Request $request): int
    {
        $actor = $request->user();
        assert($actor !== null);

        return (int) $actor->getAuthIdentifier();
    }
}
