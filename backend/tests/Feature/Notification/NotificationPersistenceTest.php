<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Notification\Application\Content\NotificationTextRenderer;
use App\Modules\Notification\Application\Contracts\NotificationOrganizationAccess;
use App\Modules\Notification\Application\Contracts\NotificationPublisher;
use App\Modules\Notification\Application\Data\NotificationMembershipContext;
use App\Modules\Notification\Application\Data\NotificationText;
use App\Modules\Notification\Application\Exceptions\NotificationWriteFailed;
use App\Modules\Notification\Application\Vocabulary\NotificationType;
use App\Modules\Notification\Infrastructure\Persistence\DatabaseNotificationPublisher;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Infrastructure\Notification\OrganizationNotificationAccess;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationFixtures;
use Tests\Support\OrganizationFixtures;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = OrganizationFixtures::unaudited($this->owner->id, 'Notification fixture');
    $this->recipient = User::factory()->create();
    $this->membership = $this->organization->memberships()->create(['user_id' => $this->recipient->id]);
    $this->draft = NotificationFixtures::draft([
        'organizationId' => $this->organization->id, 'recipientUserId' => $this->recipient->id,
    ]);
});

it('binds the publisher and access adapter and persists the trusted recipient membership independently of payload membership', function () {
    expect(app(NotificationPublisher::class))->toBeInstanceOf(DatabaseNotificationPublisher::class);
    expect(app(NotificationOrganizationAccess::class))->toBeInstanceOf(OrganizationNotificationAccess::class);
    $context = app(NotificationOrganizationAccess::class)->resolveActiveMembership($this->recipient->id, $this->organization->id);
    expect($context->organizationId)->toBe($this->organization->id);
    expect($context->userId)->toBe($this->recipient->id);
    expect($context->membershipId)->toBe($this->membership->id);
    $this->actingAs($this->owner); // Ambient actor cannot select the recipient or membership era.
    $payload = array_replace($this->draft->payload, ['membership_id' => '9223372036854775807']);
    $level = DB::transactionLevel();
    app(NotificationPublisher::class)->publish(NotificationFixtures::draft([
        'organizationId' => $this->organization->id, 'recipientUserId' => $this->recipient->id, 'payload' => $payload,
    ]));
    expect(DB::transactionLevel())->toBe($level);
    expect(DB::connection()->getPdo()->inTransaction())->toBeTrue();
    $row = DB::table('organization_notifications')->where('organization_id', $this->organization->id)->sole();
    expect($row->id)->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/');
    expect($row->recipient_user_id)->toBe($this->recipient->id);
    expect($row->recipient_membership_id)->toBe($this->membership->id);
    expect(json_decode($row->payload, true))->toBe($payload);
    expect($row->type)->toBe('organization.invitation_accepted');
    expect($row->payload_version)->toBe(1);
    expect($row->title)->toBe('Invitation accepted');
    expect($row->body)->toBe('User #29 accepted an invitation and joined the organization.');
    expect($row->target_type)->toBe('organization.users');
    expect($row->target_id)->toBeNull();
    expect($row->read_at)->toBeNull();
    expect($row->created_at)->not->toBeNull();
    expect(array_keys((array) $row))->not->toContain('updated_at', 'deleted_at', 'metadata', 'email_status');
});

it('generates distinct IDs and permits an informational notification with no target', function () {
    app(NotificationPublisher::class)->publish($this->draft);
    app(NotificationPublisher::class)->publish(NotificationFixtures::draft([
        'organizationId' => $this->organization->id, 'recipientUserId' => $this->recipient->id, 'target' => null,
    ]));
    $rows = DB::table('organization_notifications')->where('organization_id', $this->organization->id)->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('id')->unique())->toHaveCount(2);
    $withoutTarget = $rows->firstWhere('target_type', null);
    expect($withoutTarget->target_id)->toBeNull();
});

it('requires active membership even for an explicit owner', function () {
    $ownerMembership = $this->organization->memberships()->where('user_id', $this->owner->id)->sole();
    // Deferred owner constraint permits this temporary fixture state only inside the outer rollback.
    $ownerMembership->update(['status' => 'suspended']);
    try {
        app(NotificationPublisher::class)->publish(NotificationFixtures::draft([
            'organizationId' => $this->organization->id, 'recipientUserId' => $this->owner->id,
        ]));
        test()->fail('Expected owner membership eligibility rejection.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe('recipient_ineligible');
    }
    $ownerMembership->update(['status' => 'active']);
    expect(DB::table('organization_notifications')->where('organization_id', $this->organization->id)->count())->toBe(0);
});

it('rejects suspended removed nonmember and cross-tenant recipients without inserting', function (string $kind) {
    $organizationId = $this->organization->id;
    $recipientId = $this->recipient->id;
    if ($kind === 'suspended') {
        $this->membership->update(['status' => 'suspended']);
    } elseif ($kind === 'removed') {
        $this->membership->delete();
    } elseif ($kind === 'nonmember') {
        $recipientId = User::factory()->create()->id;
    } else {
        $organizationId = OrganizationFixtures::unaudited($this->owner->id, 'Other scope')->id;
    }
    try {
        app(NotificationPublisher::class)->publish(NotificationFixtures::draft([
            'organizationId' => $organizationId, 'recipientUserId' => $recipientId,
        ]));
        test()->fail('Expected recipient eligibility rejection.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe('recipient_ineligible');
        expect($failure->getPrevious())->toBeNull();
    }
    expect(DB::table('organization_notifications')->count())->toBe(0);
})->with(['suspended', 'removed', 'nonmember', 'cross-tenant']);

it('rejects an adapter context inconsistent with the requested recipient or tenant', function (string $kind) {
    app()->instance(NotificationOrganizationAccess::class, new class($kind) implements NotificationOrganizationAccess
    {
        public function __construct(private string $kind) {}

        public function resolveActiveMembership(int $userId, string $organizationId): NotificationMembershipContext
        {
            return new NotificationMembershipContext(
                $this->kind === 'tenant' ? NotificationFixtures::ORGANIZATION_ID : $organizationId,
                $this->kind === 'user' ? $userId + 1 : $userId,
                $this->kind === 'membership' ? 0 : 47,
            );
        }
    });
    try {
        app(NotificationPublisher::class)->publish($this->draft);
        test()->fail('Expected inconsistent trusted context rejection.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe('invalid_membership_context');
    }
    expect(DB::table('organization_notifications')->count())->toBe(0);
})->with(['tenant', 'user', 'membership']);

it('rolls back successful publication and earlier caller writes on subsequent failure', function () {
    expect(fn () => DB::transaction(function (): void {
        DB::table('organizations')->where('id', $this->organization->id)->update(['name' => 'Changed']);
        app(NotificationPublisher::class)->publish($this->draft);
        expect(DB::table('organization_notifications')->where('organization_id', $this->organization->id)->count())->toBe(1);
        throw new RuntimeException('Workflow rejected');
    }))->toThrow(RuntimeException::class, 'Workflow rejected');
    expect($this->organization->fresh()->name)->toBe('Notification fixture');
    expect(DB::table('organization_notifications')->where('organization_id', $this->organization->id)->count())->toBe(0);
});

it('propagates unsafe payload failure and rolls back earlier caller writes', function () {
    $payload = $this->draft->payload;
    $payload['invitation_token_hash'] = 'never-persist-this';
    expect(fn () => DB::transaction(function () use ($payload): void {
        DB::table('organizations')->where('id', $this->organization->id)->update(['name' => 'Changed']);
        app(NotificationPublisher::class)->publish(NotificationFixtures::draft([
            'organizationId' => $this->organization->id, 'recipientUserId' => $this->recipient->id, 'payload' => $payload,
        ]));
    }))->toThrow(NotificationWriteFailed::class);
    expect($this->organization->fresh()->name)->toBe('Notification fixture');
    expect(DB::table('organization_notifications')->count())->toBe(0);
});

it('replaces rendering failures with safe exceptions and rolls back the caller transaction', function () {
    app()->instance(NotificationTextRenderer::class, new class extends NotificationTextRenderer
    {
        public function render(NotificationType $type, int $payloadVersion, #[SensitiveParameter] array $payload): NotificationText
        {
            throw new RuntimeException('sensitive-renderer-internals');
        }
    });
    try {
        DB::transaction(function (): void {
            DB::table('organizations')->where('id', $this->organization->id)->update(['name' => 'Changed']);
            app(NotificationPublisher::class)->publish($this->draft);
        });
        test()->fail('Expected rendering failure.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe('rendering_failed');
        expect($failure->getPrevious())->toBeNull();
        expect(str_contains((string) $failure, 'sensitive-renderer-internals'))->toBeFalse();
    }
    expect($this->organization->fresh()->name)->toBe('Notification fixture');
    expect(DB::table('organization_notifications')->count())->toBe(0);
});

it('sanitizes real insert errors and argument traces without keeping SQL bindings or snapshots', function () {
    DB::statement('ALTER TABLE organization_notifications ADD CONSTRAINT notifications_test_failure CHECK (false)');
    app()->instance(NotificationTextRenderer::class, new class extends NotificationTextRenderer
    {
        public function render(NotificationType $type, int $payloadVersion, #[SensitiveParameter] array $payload): NotificationText
        {
            return new NotificationText('Invitation accepted', 'sensitive-snapshot-marker');
        }
    });
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    try {
        DB::transaction(function (): void {
            DB::table('organizations')->where('id', $this->organization->id)->update(['name' => 'Changed']);
            app(NotificationPublisher::class)->publish($this->draft);
        });
        test()->fail('Expected real PostgreSQL insert failure.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe('persistence_failed');
        expect($failure->getMessage())->toBe('Notification publication failed.');
        expect($failure->getPrevious())->toBeNull();
        foreach (['sensitive-snapshot-marker', 'insert into', 'notifications_test_failure', NotificationFixtures::INVITATION_ID] as $sensitive) {
            expect(str_contains((string) $failure, $sensitive))->toBeFalse();
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
    expect($this->organization->fresh()->name)->toBe('Notification fixture');
    expect(DB::table('organization_notifications')->count())->toBe(0);
});

it('adds no notification producer to existing organization creation', function () {
    app(CreateOrganization::class)->handle($this->owner->id, 'No notification instrumentation');
    expect(DB::table('organization_notifications')->count())->toBe(0);
});
