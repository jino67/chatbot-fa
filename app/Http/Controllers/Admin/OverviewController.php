<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Llm\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\ChannelRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Payment;
use App\Models\PlanRequest;
use App\Models\Workspace;

class OverviewController extends Controller
{
    public function __invoke(ProviderRegistry $providers)
    {
        $monthStart = now()->startOfMonth();

        return view('admin.overview', [
            'stats' => [
                'workspaces' => Workspace::count(),
                'paying' => Workspace::where('plan', '!=', 'free')->where('is_suspended', false)->count(),
                'bots' => Bot::withoutGlobalScopes()->count(),
                'conversations' => Conversation::withoutGlobalScopes()->where('channel', '!=', 'playground')->where('created_at', '>=', now()->subDays(7))->count(),
                'messages' => Message::withoutGlobalScopes()->where('role', Message::ASSISTANT)->where('meta->llm', true)->where('created_at', '>=', $monthStart)->count(),
                'channels' => Channel::withoutGlobalScopes()->where('status', Channel::ACTIVE)->count(),
                'revenue' => Payment::withoutGlobalScopes()->where('paid_at', '>=', $monthStart)->where('currency', 'XOF')->sum('amount'),
            ],
            'pendingChannels' => ChannelRequest::withoutGlobalScopes()
                ->with(['bot' => fn ($q) => $q->withoutGlobalScopes(), 'workspace'])
                ->whereIn('status', [ChannelRequest::REQUESTED, ChannelRequest::IN_PROGRESS])
                ->latest()->limit(8)->get(),
            'pendingPlans' => PlanRequest::withoutGlobalScopes()->with('workspace')->where('status', PlanRequest::REQUESTED)->latest()->limit(8)->get(),
            'expiring' => Workspace::whereNotNull('plan_ends_at')->where('plan', '!=', 'free')
                ->where('plan_ends_at', '<=', now()->addDays((int) config('platform.billing.reminder_days')))
                ->orderBy('plan_ends_at')->limit(8)->get(),
            'aiProblems' => $providers->problems(),
            'aiActive' => $providers->active(),
            'aiOffline' => $providers->isOffline(),
        ]);
    }
}
