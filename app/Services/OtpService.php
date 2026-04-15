<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function store(User $user, string $otp): void
    {
        $user->update([
            'otp' => bcrypt($otp),
            'otp_expires_at' => now()->addMinutes(10),
        ]);
    }

    public function verify(User $user, string $otp): bool
    {
        if (! $user->otp || ! $user->otp_expires_at) {
            return false;
        }

        if (now()->isAfter($user->otp_expires_at)) {
            return false;
        }

        return Hash::check($otp, $user->otp);
    }

    public function clear(User $user): void
    {
        $user->update([
            'otp' => null,
            'otp_expires_at' => null,
        ]);
    }
}
