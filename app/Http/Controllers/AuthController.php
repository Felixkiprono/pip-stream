<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Pipstream\Accounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public static function present(?User $u, Accounts $accounts): ?array
    {
        if (! $u) {
            return null;
        }

        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role,
            'onboarding' => $accounts->onboarding($u), 'plan' => $accounts->plan($u), 'beta' => config('pipstream.beta')];
    }

    public function me(Request $r, Accounts $accounts)
    {
        if ($r->user() && $r->user()->status === 'suspended') { // suspended: sign out
            Auth::guard('web')->logout();
            $r->session()->invalidate();

            return ['user' => null];
        }

        return ['user' => self::present($r->user(), $accounts)];
    }

    public function register(Request $r, Accounts $accounts)
    {
        $data = $r->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
        ]);
        $user = User::create($data + ['role' => 'customer']);
        $accounts->onboard($user);
        Auth::login($user, remember: true);
        $r->session()->regenerate();

        return ['user' => self::present($user, $accounts)];
    }

    public function login(Request $r, Accounts $accounts)
    {
        $data = $r->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt(['email' => strtolower($data['email']), 'password' => $data['password'], 'status' => 'active'], remember: true)) {
            throw ValidationException::withMessages(['email' => 'That email and password don\'t match.']);
        }
        $r->session()->regenerate();

        return ['user' => self::present($r->user(), $accounts)];
    }

    public function logout(Request $r)
    {
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return ['ok' => true];
    }
}
