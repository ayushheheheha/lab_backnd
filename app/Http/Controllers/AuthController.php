<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Mail\OtpMail;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otpService)
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $existingUser = User::where('email', $validated['email'])->first();

        if ($existingUser && $existingUser->email_verified_at) {
            return response()->json([
                'error' => 'Email already registered',
            ], 422);
        }

        if ($existingUser && ! $existingUser->email_verified_at) {
            $existingUser->update([
                'name' => $validated['name'],
                'password' => bcrypt($validated['password']),
            ]);

            $otp = $this->otpService->generate();
            $this->otpService->store($existingUser, $otp);
            Mail::to($existingUser->email)->send(new OtpMail($otp, $existingUser->name));

            return response()->json([
                'message' => 'Account exists but is unverified. OTP resent successfully.',
                'email' => $existingUser->email,
            ]);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'email_verified_at' => null,
        ]);

        $otp = $this->otpService->generate();
        $this->otpService->store($user, $otp);
        Mail::to($user->email)->send(new OtpMail($otp, $user->name));

        return response()->json([
            'message' => 'Registration successful. Please check your email for the OTP.',
            'email' => $user->email,
        ], 201);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if (! $this->otpService->verify($user, $validated['otp'])) {
            return response()->json(['error' => 'Invalid or expired OTP'], 422);
        }

        $user->update([
            'email_verified_at' => now(),
        ]);

        $this->otpService->clear($user);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'avatar' => $user->avatar,
            ],
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (! $user->email_verified_at) {
            return response()->json([
                'error' => 'Please verify your email first.',
                'needs_verification' => true,
                'email' => $user->email,
            ], 403);
        }

        if (! $user->is_active) {
            return response()->json([
                'error' => 'Your account has been deactivated.',
            ], 403);
        }

        if (! Hash::check($validated['password'], (string) $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'avatar' => $user->avatar,
            ],
        ]);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if ($user->email_verified_at) {
            return response()->json(['error' => 'Email already verified'], 422);
        }

        if ($user->otp_expires_at) {
            $lastSentAt = $user->otp_expires_at->copy()->subMinutes(10);
            if ($lastSentAt->greaterThan(now()->subSeconds(60))) {
                return response()->json(['error' => 'Please wait before requesting another OTP'], 429);
            }
        }

        $otp = $this->otpService->generate();
        $this->otpService->store($user, $otp);
        Mail::to($user->email)->send(new OtpMail($otp, $user->name));

        return response()->json(['message' => 'OTP resent successfully']);
    }

    public function googleRedirect(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function googleCallback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            $user = User::where('google_id', $googleUser->getId())
                ->orWhere('email', $googleUser->getEmail())
                ->first();

            if ($user) {
                if (! $user->google_id && $googleUser->getId()) {
                    $user->update([
                        'google_id' => $googleUser->getId(),
                        'avatar' => $googleUser->getAvatar(),
                    ]);
                }
            } else {
                $user = User::create([
                    'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? 'Google User',
                    'email' => $googleUser->getEmail(),
                    'password' => null,
                    'email_verified_at' => now(),
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                    'is_active' => true,
                ]);
            }

            $user->tokens()->delete();
            $token = $user->createToken('auth_token')->plainTextToken;

            $frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/');
            $encodedToken = urlencode($token);
            $encodedUser = urlencode(json_encode([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'avatar' => $user->avatar,
            ]));

            return redirect($frontendUrl.'/auth/callback?token='.$encodedToken.'&user='.$encodedUser);
        } catch (Throwable $e) {
            $frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/');

            return redirect($frontendUrl.'/auth/callback?error=google_auth_failed');
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'is_admin' => $user?->is_admin,
                'avatar' => $user?->avatar,
                'email_verified_at' => $user?->email_verified_at,
            ],
        ]);
    }
}
