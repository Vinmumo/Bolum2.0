<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CompanyMember
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()->companies()->whereKey($request->route('company')->id)->exists(), 403, 'You are not a member of this company.');

        return $next($request);
    }
}
