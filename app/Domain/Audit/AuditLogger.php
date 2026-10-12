<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    /** Keys whose values are never written to the audit log. */
    private const REDACTED_KEYS = '/pass(word)?|token|secret|signature|api[_-]?key|account_number|card/i';

    public function __construct(private readonly ?Request $request = null) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $action, ?Model $subject = null, array $properties = [], ?User $actor = null): AuditLog
    {
        $actor ??= $this->request?->user();

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            // Not every model has a public_id (categories use their numeric id).
            'subject_id' => $subject ? (string) ($subject->getAttributes()['public_id'] ?? $subject->getKey()) : null,
            'properties' => $properties === [] ? null : $this->redact($properties),
            'ip_hash' => $this->hashIp($this->request?->ip()),
            'user_agent' => $this->request?->userAgent() ? Str::limit($this->request->userAgent(), 250, '') : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::REDACTED_KEYS, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    /**
     * IPs are stored as a keyed hash: enough to correlate abuse from the same
     * address, not enough to recover the address itself.
     */
    public function hashIp(?string $ip): ?string
    {
        return $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
    }
}
