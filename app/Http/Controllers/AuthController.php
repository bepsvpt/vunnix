<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    /**
     * Redirect the user to GitLab for OAuth authentication.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('gitlab')
            ->scopes(['read_user', 'read_api'])
            ->redirect();
    }

    /**
     * Handle the GitLab OAuth callback.
     *
     * Creates or updates the user with GitLab profile data,
     * stores OAuth tokens for later API use (T8 membership sync),
     * and establishes a Laravel session.
     */
    public function callback(): RedirectResponse
    {
        $gitlabUser = Socialite::driver('gitlab')->user();

        $user = User::updateOrCreate(
            ['gitlab_id' => $gitlabUser->getId()],
            [
                'name' => $gitlabUser->getName(),
                'email' => $gitlabUser->getEmail(),
                'username' => $gitlabUser->getNickname(),
                'avatar_url' => $gitlabUser->getAvatar(),
                'oauth_provider' => 'gitlab',
                'oauth_token' => $gitlabUser->token,
                'oauth_refresh_token' => $gitlabUser->refreshToken,
                'oauth_token_expires_at' => $gitlabUser->expiresIn
                    ? now()->addSeconds($gitlabUser->expiresIn)
                    : null,
            ],
        );

        auth()->login($user, remember: true);

        try {
            app(AuditLogService::class)->logAuthEvent(
                userId: $user->id,
                action: 'login',
                ipAddress: request()->ip(),
                userAgent: request()->userAgent(),
            );
        } catch (Throwable) {
            // Audit logging should never break auth flow
        }

        $user->syncMemberships();

        session()->regenerate();

        return redirect()->intended('/');
    }

    /**
     * Log the user out and invalidate their session.
     */
    public function logout(Request $request): RedirectResponse
    {
        $userId = auth()->id();
        if ($userId) {
            try {
                app(AuditLogService::class)->logAuthEvent(
                    userId: $userId,
                    action: 'logout',
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            } catch (Throwable) {
                // Audit logging should never break auth flow
            }
        }

        auth()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
