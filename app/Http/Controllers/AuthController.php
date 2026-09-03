<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Services\Credits\CreditCalculator;
use App\Models\User;
use App\Services\CreationTelegramNotifier;
use App\Services\Tokens\TokenLotLedger;

class AuthController extends Controller
{
    public function showLoginForm(): RedirectResponse
    {
        return redirect('/?login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended(route('home'));
        }

        return back()->withErrors([
            'email' => __('auth.failed'),
        ])->onlyInput('email');
    }

    public function showRegisterForm(): RedirectResponse
    {
        return redirect('/?login');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $starter = app(CreditCalculator::class)->starterTokens();
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'tokens' => $starter,
        ]);

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
