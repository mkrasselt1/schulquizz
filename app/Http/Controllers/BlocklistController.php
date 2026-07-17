<?php

namespace App\Http\Controllers;

use App\Models\BlocklistTerm;
use App\Services\ModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BlocklistController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Moderation/Index', [
            'terms' => Auth::user()->load('blocklistTerms')->blocklistTerms
                ->map(fn ($t) => ['id' => $t->id, 'term' => $t->term, 'match_type' => $t->match_type])
                ->values(),
            'baseCount' => count(ModerationService::BASE_TERMS),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'term' => ['required', 'string', 'max:60'],
            'match_type' => ['required', 'in:substring,exact'],
        ]);

        Auth::user()->blocklistTerms()->create($data);

        return back()->with('status', 'term-added');
    }

    public function destroy(BlocklistTerm $term): RedirectResponse
    {
        abort_unless($term->user_id === Auth::id(), 403);
        $term->delete();

        return back()->with('status', 'term-removed');
    }
}
