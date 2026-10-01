<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\UserAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'sessions' => $this->sessions($request),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /** « Mot de passe oublié » depuis le profil : le lien de réinitialisation part vers l'adresse du compte. */
    public function sendPasswordLink(Request $request): RedirectResponse
    {
        $status = Password::sendResetLink(['email' => $request->user()->email]);

        return back()->with('status', $status === Password::RESET_LINK_SENT ? 'password-link-sent' : 'password-link-throttled');
    }

    /** Ferme toutes les sessions de la personne sauf celle-ci, et invalide les cookies « rester connecté ». */
    public function logoutOthers(Request $request): RedirectResponse
    {
        $request->validateWithBag('logoutOthers', ['password' => ['required', 'current_password']]);

        $user = $request->user();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        return back()->with('status', 'sessions-closed');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Appareils connectés : seulement avec les sessions en base (le pilote « database », celui de la production).
     *
     * @return Collection<int,array{current:bool,label:string,mobile:bool,ip:?string,last:Carbon}>
     */
    private function sessions(Request $request): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->limit(10)
            ->get()
            ->map(function ($row) use ($request) {
                $device = UserAgent::describe($row->user_agent);

                return [
                    'current' => $row->id === $request->session()->getId(),
                    'label' => $device['label'],
                    'mobile' => $device['mobile'],
                    'ip' => $row->ip_address,
                    'last' => Carbon::createFromTimestamp($row->last_activity),
                ];
            });
    }
}
