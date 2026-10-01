<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Services\UsageService;
use App\Support\BotDraft;
use App\Support\Onboarding;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, UsageService $usage)
    {
        $workspace = $request->user()->currentWorkspace();

        $since = now()->subDays(30);
        $real = Conversation::real()->select('id');

        $bots = Bot::withCount(['sources', 'conversations'])->orderBy('name')->get();

        // La mise en route du premier assistant qui n'est pas encore au bout (une seule carte, pour ne pas surcharger).
        $onboarding = null;
        foreach ($bots as $bot) {
            $progress = Onboarding::for($bot, $request->user());
            if ($progress['done'] < $progress['total']) {
                $onboarding = ['bot' => $bot, 'progress' => $progress];
                break;
            }
        }

        return view('dashboard', [
            'workspace' => $workspace,
            'bots' => $bots,
            'draft' => BotDraft::get($request->user(), $workspace),
            'onboarding' => $onboarding,
            'usage' => $usage->summary($workspace),
            'plan' => $workspace->planModel(),
            'alertsChosen' => $workspace->alertSettings()['chosen'],
            'stats' => [
                'conversations' => Conversation::real()->where('created_at', '>=', $since)->count(),
                'messages' => Message::whereIn('conversation_id', $real)->where('role', Message::USER)->where('created_at', '>=', $since)->count(),
                'waiting' => Conversation::real()->where('status', Conversation::NEEDS_HUMAN)->count(),
                'leads' => Lead::where('status', Lead::NEW)->count(),
                'unanswered' => Message::whereIn('conversation_id', $real)->where('role', Message::ASSISTANT)->where('meta->grounded', false)->where('created_at', '>=', $since)->count(),
            ],
        ]);
    }
}
