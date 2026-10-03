<?php

namespace App\Modules\Notification\Infrastructure\Persistence;

use App\Modules\Notification\Application\Content\NotificationTextRenderer;
use App\Modules\Notification\Application\Contracts\NotificationOrganizationAccess;
use App\Modules\Notification\Application\Contracts\NotificationPublisher;
use App\Modules\Notification\Application\Data\NotificationDraft;
use App\Modules\Notification\Application\Exceptions\NotificationWriteFailed;
use App\Modules\Notification\Application\Validation\NotificationPayloadValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DatabaseNotificationPublisher implements NotificationPublisher
{
    public function __construct(
        private readonly NotificationOrganizationAccess $access,
        private readonly NotificationPayloadValidator $validator,
        private readonly NotificationTextRenderer $renderer,
    ) {}

    public function publish(#[\SensitiveParameter] NotificationDraft $notification): void
    {
        $this->validator->validateRecipient($notification);

        try {
            // Resolve the same default connection per call; the business caller owns its transaction.
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'pgsql') {
                throw new NotificationWriteFailed('unsupported_connection');
            }
            if ($connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()) {
                throw new NotificationWriteFailed('transaction_required');
            }
            try {
                $context = $this->access->resolveActiveMembership($notification->recipientUserId, $notification->organizationId);
            } catch (Throwable) {
                // Do not retain another adapter's exception or let it expose its input/SQL bindings.
                throw new NotificationWriteFailed('recipient_ineligible');
            }
            $this->validator->validateMembershipContext($notification, $context);
            $this->validator->validate($notification);
            try {
                $text = $this->renderer->render($notification->type, $notification->payloadVersion, $notification->payload);
            } catch (Throwable) {
                throw new NotificationWriteFailed('rendering_failed');
            }
            $connection->table('organization_notifications')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $context->organizationId,
                'recipient_user_id' => $context->userId,
                'recipient_membership_id' => $context->membershipId,
                'type' => $notification->type->value,
                'payload_version' => $notification->payloadVersion,
                'payload' => json_encode($notification->payload, JSON_THROW_ON_ERROR),
                'title' => $text->title,
                'body' => $text->body,
                'target_type' => $notification->target?->type->value,
                'target_id' => $notification->target?->id,
            ]);
        } catch (NotificationWriteFailed $failure) {
            throw $failure;
        } catch (Throwable) {
            // QueryException and PostgreSQL failing-row details contain bindings; retain neither.
            throw new NotificationWriteFailed('persistence_failed');
        }
    }
}
