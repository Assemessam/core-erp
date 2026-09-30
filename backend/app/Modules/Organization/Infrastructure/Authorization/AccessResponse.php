<?php

namespace App\Modules\Organization\Infrastructure\Authorization;

use App\Modules\Organization\Application\Authorization\AccessDecision;
use App\Modules\Organization\Application\Authorization\AccessDenied;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use LogicException;

final class AccessResponse
{
    public static function fromDecision(AccessDecision $decision): Response
    {
        return match ($decision->outcome) {
            AccessDecision::ALLOWED => Response::allow(),
            AccessDecision::HIDDEN => Response::denyAsNotFound(),
            AccessDecision::FORBIDDEN => Response::deny($decision->message),
            default => throw new LogicException('Unknown organization access decision.'),
        };
    }

    public static function exception(AccessDenied $denied): AuthorizationException
    {
        $response = self::fromDecision($denied->decision);

        return (new AuthorizationException($response->message()))
            ->setResponse($response)->withStatus($response->status());
    }
}
