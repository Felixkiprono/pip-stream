<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Pipstream\Accounts;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
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
            'founding' => $u->founding_number, 'onboarding' => $accounts->onboarding($u), 'plan' => $accounts->plan($u), 'beta' => config('pipstream.beta')];
    }

    public function me(Request $r, Accounts $accounts)
    {
        if ($r->user() && $r->user()->status === 'suspended') { // suspended: sign out
            Auth::guard('web')->logout();
            $r->session()->invalidate();

            return ['user' => null];
        }
        if ($r->user()) {
            $accounts->ensureFounding($r->user());
        }

        return ['user' => self::present($r->user(), $accounts)];
    }

    /** Public: how many founding (free-for-life) spots are left, for the landing page. */
    public function founding()
    {
        $slots = (int) config('pipstream.founding_slots');

        return ['slots' => $slots, 'left' => max(0, $slots - User::whereNotNull('founding_number')->count())];
    }

    public function register(Request $r, Accounts $accounts)
    {
        $data = $r->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
        ]);
        $data['email'] = strtolower($data['email']);
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

    /** "Forgot password": always the same answer, so it can't be used to find out who has an account. */
    public function forgot(Request $r)
    {
        $email = strtolower($r->validate(['email' => ['required', 'email']])['email']);
        $status = PasswordBroker::sendResetLink(['email' => $email, 'status' => 'active']);
        if ($status === PasswordBroker::RESET_THROTTLED) {
            throw ValidationException::withMessages(['email' => 'We just sent a link. Check your inbox, or try again in a minute.']);
        }

        return ['ok' => true, 'message' => 'If that email has a PipStream account, a reset link is on its way.'];
    }

    public function reset(Request $r, Accounts $accounts)
    {
        $data = $r->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', Password::min(8)],
        ]);
        $data['email'] = strtolower($data['email']);
        $status = PasswordBroker::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired. Ask for a new one.']);
        }
        $user = User::where('email', $data['email'])->first();
        if ($user && $user->status === 'active') {
            Auth::login($user, remember: true);
            $r->session()->regenerate();
        }

        return ['user' => self::present(Auth::user(), $accounts)];
    }

    public function logout(Request $r)
    {
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return ['ok' => true];
    }
}
