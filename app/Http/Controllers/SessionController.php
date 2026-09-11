<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function store(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are incorrect.']]);
        }
        $request->session()->regenerate();

        return response()->json(['message' => 'Signed in.', 'csrf_token' => csrf_token()]);
    }

    public function register(RegisterRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $company = Company::create(['name' => $request->validated('company_name')]);
            $company->users()->attach($user, ['role' => 'owner']);
            app(CreditService::class)->topUp($company, $user, 10, 'welcome');

            return $user;
        });
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
