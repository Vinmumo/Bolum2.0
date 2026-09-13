<?php

namespace App\Http\Controllers;

use App\Actions\Auth\RegisterUserAction;
use App\Data\LoginData;
use App\Data\RegisterUserData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function store(Request $request)
    {
        $credentials = LoginData::from($request)->toArray();
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are incorrect.']]);
        }
        $request->session()->regenerate();

        return response()->json(['message' => 'Signed in.', 'csrf_token' => csrf_token()]);
    }

    public function register(Request $request, RegisterUserAction $action)
    {
        $user = $action->execute(RegisterUserData::from($request));
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['message' => 'Workspace created.', 'csrf_token' => csrf_token()], 201);
    }

    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.', 'csrf_token' => csrf_token()]);
    }
}
