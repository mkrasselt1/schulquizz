<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'mcpUrl' => url('/mcp'),
            // Verbundene KI-Assistenten (MCP/OAuth) – je Client einmal.
            'connectedApps' => $request->user()->tokens()
                ->where('revoked', false)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->with('client')
                ->latest()
                ->get()
                ->groupBy('client_id')
                ->map(fn ($tokens) => [
                    'client_id' => (string) $tokens->first()->client_id,
                    'name' => $tokens->first()->client?->name ?? 'KI-Assistent',
                    'since' => $tokens->last()->created_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    /**
     * Zugriff eines KI-Assistenten widerrufen (alle Tokens dieses Clients).
     */
    public function revokeApp(Request $request, string $client): RedirectResponse
    {
        $tokens = $request->user()->tokens()->where('client_id', $client)->get();
        foreach ($tokens as $token) {
            $token->revoke();
            \Laravel\Passport\RefreshToken::where('access_token_id', $token->id)->update(['revoked' => true]);
        }

        return Redirect::route('profile.edit');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
