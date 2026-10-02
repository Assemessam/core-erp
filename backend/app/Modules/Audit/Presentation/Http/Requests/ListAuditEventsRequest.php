<?php

namespace App\Modules\Audit\Presentation\Http\Requests;

use App\Modules\Audit\Application\Contracts\AuditHistoryAccess;
use App\Modules\Audit\Application\Exceptions\AuditQueryInvalid;
use App\Modules\Audit\Application\Validation\AuditQueryValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListAuditEventsRequest extends FormRequest
{
    public function actorUserId(): int
    {
        $actor = $this->user();
        assert($actor !== null);

        return (int) $actor->getAuthIdentifier();
    }

    public function authorize(AuditHistoryAccess $access): bool
    {
        $organizationId = $this->route('organization');
        assert(is_string($organizationId));
        $access->assertCanView($this->actorUserId(), $organizationId);

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
                $this->container->make(AuditQueryValidator::class)->validate($this->query->all());
            } catch (AuditQueryInvalid $failure) {
                foreach ($failure->errors as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }
}
