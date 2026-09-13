<?php

namespace App\Http\Controllers\Api;

use App\Actions\Profile\ChangePasswordAction;
use App\Actions\Profile\UpdateProfileAction;
use App\Data\ChangePasswordData;
use App\Data\UpdateProfileData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return new UserResource($request->user());
    }

    public function update(UpdateProfileRequest $request, UpdateProfileAction $action)
    {
        return new UserResource($action->execute($request->user(), UpdateProfileData::fromValidated($request->validated())));
    }

    public function password(ChangePasswordRequest $request, ChangePasswordAction $action)
    {
        $action->execute($request->user(), ChangePasswordData::fromValidated($request->validated()));
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Password updated. Sign in again.', 'csrf_token' => $request->hasSession() ? csrf_token() : null]);
    }
}
