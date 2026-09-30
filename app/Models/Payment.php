<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paiement enregistre par l'equipe (Mobile Money, virement, especes) : il ouvre ou prolonge un abonnement. */
class Payment extends Model
{
    use BelongsToWorkspace;

    public const METHODS = [
        'orange_money' => 'Orange Money',
        'moov_money' => 'Moov Money',
        'coris_money' => 'Coris Money',
        'wave' => 'Wave',
        'virement' => 'Virement bancaire',
        'especes' => 'Espèces',
        'autre' => 'Autre',
    ];

    protected $fillable = [
        'workspace_id', 'plan', 'amount', 'currency', 'method', 'reference', 'period_months',
        'period_start', 'period_end', 'paid_at', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime'];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    public function formattedAmount(): string
    {
        $symbol = $this->currency === 'XOF' ? 'FCFA' : $this->currency;

        return number_format($this->amount, 0, ',', "\u{202F}").' '.$symbol;
    }
}
