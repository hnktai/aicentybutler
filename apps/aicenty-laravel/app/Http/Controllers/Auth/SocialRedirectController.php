<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;

class SocialRedirectController extends Controller
{
    public function github(): RedirectResponse
    {
        return Socialite::driver('github')->redirect();
    }

    public function google(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function twitter(): RedirectResponse
    {
        return Socialite::driver('twitter')->redirect();
    }

    public function facebook(): RedirectResponse
    {
        return Socialite::driver('facebook')->redirect();
    }
}
