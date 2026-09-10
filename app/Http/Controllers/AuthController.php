<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $company = Company::create(['name' => $request->validated('company_name')]);
            $user->companies()->attach($company, ['role' => 'owner']);

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
        return ['user' => $user->only(['id', 'name', 'email', 'is_admin']), 'companies' => $user->companies()->get(['companies.id', 'companies.name']), 'token' => $user->createToken('api', ['*'], now()->addHours(8))->plainTextToken];
    }

    public function me(Request $request)
    {
        return response()->json(['data' => ['user' => $request->user()->only(['id', 'name', 'email', 'is_admin']), 'companies' => $request->user()->companies()->get(['companies.id', 'companies.name'])]]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
