<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\UsageService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, UsageService $usage)
    {
        $workspace = $request->user()->currentWorkspace();

        $since = now()->subDays(30);
        $real = Conversation::real()->select('id');

        return view('dashboard', [
            'workspace' => $workspace,
            'bots' => Bot::withCount(['sources', 'conversations'])->orderBy('name')->get(),
            'usage' => $usage->summary($workspace),
            'plan' => $workspace->planModel(),
            'stats' => [
                'conversations' => Conversation::real()->where('created_at', '>=', $since)->count(),
                'messages' => Message::whereIn('conversation_id', $real)->where('role', Message::USER)->where('created_at', '>=', $since)->count(),
                'waiting' => Conversation::real()->where('status', Conversation::NEEDS_HUMAN)->count(),
                'unanswered' => Message::whereIn('conversation_id', $real)->where('role', Message::ASSISTANT)->where('meta->grounded', false)->where('created_at', '>=', $since)->count(),
            ],
        ]);
    }
}
