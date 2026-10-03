<?php

namespace App\Modules\Notification\Presentation\Http\Requests;

use App\Modules\Notification\Application\Exceptions\NotificationQueryInvalid;
use App\Modules\Notification\Application\Operations\ResolveNotificationMembership;
use App\Modules\Notification\Application\Validation\NotificationQueryValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListNotificationsRequest extends FormRequest
{
    public function actorUserId(): int
    {
        $actor = $this->user();
        assert($actor !== null);

        return (int) $actor->getAuthIdentifier();
    }

    public function authorize(ResolveNotificationMembership $membership): bool
    {
        $organizationId = $this->route('organization');
        assert(is_string($organizationId));
        $membership->handle($this->actorUserId(), $organizationId);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            try {
                $this->container->make(NotificationQueryValidator::class)->validate($this->query->all());
            } catch (NotificationQueryInvalid $failure) {
                foreach ($failure->errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }
}
