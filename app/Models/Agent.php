<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use BelongsToCompany;

    /** An agent that has not called in for this long is shown as offline. */
    public const ONLINE_WITHIN_MINUTES = 5;

    protected $guarded = [];

    protected $hidden = ['token_hash', 'company_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'actions' => 'array', 'locations' => 'array', 'source_roots' => 'array', 'last_seen_at' => 'datetime'];
    }

    public function isOnline(): bool
    {
        return $this->active && $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(self::ONLINE_WITHIN_MINUTES));
    }

    /** What the UI and API show: never the token hash. */
    public function summary(): array
    {
        return [
            'enrolled' => true, 'online' => $this->isOnline(), 'last_seen_at' => $this->last_seen_at, 'agent_version' => $this->agent_version,
            'os_info' => $this->os_info, 'actions' => $this->actions ?? new \stdClass, 'locations' => $this->locations ?? [], 'source_roots' => $this->source_roots ?? [],
        ];
    }

    /** Pick the advertised action for a command type: the one named $preferred, else the only one of that type. */
    public function actionFor(string $type, ?string $preferred = null): ?string
    {
        $want = $type === 'backup' ? 'command' : 'restore_test';
        $matching = collect($this->actions ?? [])->filter(fn ($a) => ($a['type'] ?? null) === $want)->keys();
        if ($preferred !== null) {
            return $matching->contains($preferred) ? $preferred : null;
        }

        return $matching->contains($type === 'backup' ? 'backup' : 'restore-test') ? ($type === 'backup' ? 'backup' : 'restore-test') : $matching->first();
    }
}
