<?php

namespace App\Http\Responses;

use Illuminate\Http\Response;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): SymfonyResponse
    {
        return $request->wantsJson()
            ? new Response(status: 204)
            : redirect()->intended((string) config('fortify.home'));
    }
}
