<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $company = Company::create(['name' => $request->validated('company_name')]);
            $user->companies()->attach($company, ['role' => 'owner']);
            app(CreditService::class)->topUp($company, $user, 10, 'welcome');

            return $user;
        });

        return response()->json(['data' => $this->session($user)], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are incorrect.']]);
        }

        return response()->json(['data' => $this->session($user)]);
    }

    private function session(User $user): array
    {
        return ['user' => $user->only(['id', 'name', 'email', 'avatar', 'created_at', 'is_admin']), 'companies' => $user->companies()->get(['companies.id', 'companies.name']), 'token' => $user->createToken('api', ['*'], now()->addHours(8))->plainTextToken];
    }

    public function me(Request $request)
    {
        return response()->json(['data' => ['user' => $request->user()->only(['id', 'name', 'email', 'avatar', 'created_at', 'is_admin']), 'companies' => $request->user()->companies()->get(['companies.id', 'companies.name'])]]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 409, 'Use the session logout endpoint for browser sessions.');
        $token->delete();

        return response()->noContent();
    }
}
