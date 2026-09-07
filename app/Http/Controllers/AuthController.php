<?php

namespace App\Http\Controllers;

use App\Services\Credits\CreditCalculator;
use App\Services\CreationTelegramNotifier;
use App\Services\Security\SignupIpGuard;
use App\Services\Security\TurnstileVerifier;
use App\Services\Tokens\TokenLotLedger;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLoginForm(): RedirectResponse
    {
        return redirect('/?login');
    }

    public function login(Request $request, TurnstileVerifier $turnstile)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
            'cf-turnstile-response' => ['nullable', 'string'],
        ]);

        if (! $turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            $request->boolean('remember'),
        )) {
            $request->session()->regenerate();

            return redirect()->intended(route('home'));
        }

        return back()->withErrors([
            'email' => __('auth.failed'),
        ])->onlyInput('email');
    }

    public function showRegisterForm(): RedirectResponse
    {
        return redirect('/?register');
    }

    public function register(Request $request, TurnstileVerifier $turnstile, SignupIpGuard $signupIp)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'cf-turnstile-response' => ['nullable', 'string'],
        ]);

        if (! $turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            throw ValidationException::withMessages([
                'email' => $signupIp->opaqueMessage(),
            ]);
        }

        $signupIp->assertAllowed($request->ip());

        $starter = app(CreditCalculator::class)->starterTokens();
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'tokens' => $starter,
            'registration_ip' => $request->ip(),
        ]);

        if ($request->ip()) {
            $signupIp->remember($request->ip());
        }

        try {
            app(TokenLotLedger::class)->grantStarter($user, $starter);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            app(CreationTelegramNotifier::class)->notifyNewRegistration($user, 'email');
        } catch (\Throwable $e) {
            report($e);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect(route('home'))->with('welcome', ['tokens' => $starter]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
