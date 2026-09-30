<?php

namespace App\Modules\Organization\Application\Authorization;

final readonly class AccessDecision
{
    public const string ALLOWED = 'allowed';

    public const string HIDDEN = 'hidden';

    public const string FORBIDDEN = 'forbidden';

    private function __construct(public string $outcome, public ?string $message = null) {}

    public static function allowed(): self
    {
        return new self(self::ALLOWED);
    }

    public static function hidden(): self
    {
        return new self(self::HIDDEN);
    }

    public static function forbidden(string $message): self
    {
        return new self(self::FORBIDDEN, $message);
    }

    public function requireAllowed(): void
    {
        if ($this->outcome !== self::ALLOWED) {
            throw new AccessDenied($this);
        }
    }
}
