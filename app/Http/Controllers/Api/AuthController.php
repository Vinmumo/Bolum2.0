<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\AuthenticateUserAction;
use App\Actions\Auth\IssueApiTokenAction;
use App\Actions\Auth\RegisterUserAction;
use App\Actions\Auth\RevokeApiTokenAction;
use App\Data\LoginData;
use App\Data\RegisterUserData;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthSessionResource;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(Request $request, RegisterUserAction $register, IssueApiTokenAction $tokens)
    {
        $user = $register->execute(RegisterUserData::from($request));

        return (new AuthSessionResource($user->load('companies'), $tokens->execute($user)))->response()->setStatusCode(201);
    }

    public function login(Request $request, AuthenticateUserAction $authenticate, IssueApiTokenAction $tokens)
    {
        $user = $authenticate->execute(LoginData::from($request));

        return new AuthSessionResource($user->load('companies'), $tokens->execute($user));
    }

    public function me(Request $request)
    {
        return new AuthSessionResource($request->user()->load('companies'));
    }

    public function logout(Request $request, RevokeApiTokenAction $action)
    {
        $action->execute($request->user());

        return response()->noContent();
    }
}
