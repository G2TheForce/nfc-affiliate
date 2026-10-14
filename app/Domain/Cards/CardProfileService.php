<?php

namespace App\Domain\Cards;

use App\Domain\Audit\AuditLogger;
use App\Domain\Referrals\BotDetector;
use App\Models\CardProfile;
use App\Models\CardProfileDailyStat;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Saves tap profiles and counts taps and contact saves. Counts skip bots
 * and repeat visits from the same device within half an hour, and never
 * store who visited.
 */
class CardProfileService
{
    /** Handles that would read as the store or clash with app paths. */
    public const RESERVED = [
        'admin', 'account', 'api', 'app', 'shop', 'store', 'cart', 'checkout', 'login', 'register', 'logout',
        'support', 'help', 'staff', 'affiliates', 'affiliate', 'ref', 'media', 'p', 'card', 'cards', 'nfc',
        'settings', 'profile', 'official', 'team', 'billing', 'payments', 'esewa', 'khalti', 'root', 'null',
    ];

    public function __construct(private readonly AuditLogger $audit, private readonly BotDetector $bots) {}

    /** @param array<string, mixed> $data validated input */
    public function save(User $user, array $data): CardProfile
    {
        return DB::transaction(function () use ($user, $data) {
            $profile = $user->cardProfile()->firstOrNew();
            $created = ! $profile->exists;
            $profile->fill($data);
            $profile->socials = $this->cleanSocials($data['socials'] ?? $profile->socials ?? []);
            $changed = array_keys($profile->getDirty());
            $profile->save();
            $user->setRelation('cardProfile', $profile);

            if ($created || in_array('handle', $changed, true) || in_array('is_published', $changed, true)) {
                $this->audit->log($created ? 'card_profile.created' : 'card_profile.updated', $profile, [
                    'handle' => $profile->handle,
                    'is_published' => $profile->is_published,
                ]);
            }

            return $profile;
        });
    }

    public function recordTap(CardProfile $profile, Request $request): bool
    {
        return $this->count($profile, $request, 'taps');
    }

    public function recordSave(CardProfile $profile, Request $request): bool
    {
        return $this->count($profile, $request, 'saves');
    }

    /**
     * Daily taps and saves for the last $days days, oldest first, with zero rows filled in.
     *
     * @return list<array{day: string, taps: int, saves: int}>
     */
    public function daily(CardProfile $profile, int $days = 30): array
    {
        $tz = (string) config('affiliate.business_timezone');
        $from = now($tz)->subDays($days - 1)->toDateString();
        $rows = CardProfileDailyStat::query()
            ->where('card_profile_id', $profile->id)
            ->where('day', '>=', $from)
            ->get()
            ->keyBy(fn (CardProfileDailyStat $s) => substr((string) $s->day, 0, 10));

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now($tz)->subDays($i)->toDateString();
            $row = $rows->get($day);
            $out[] = ['day' => $day, 'taps' => (int) $row?->taps, 'saves' => (int) $row?->saves];
        }

        return $out;
    }

    private function count(CardProfile $profile, Request $request, string $column): bool
    {
        if ($this->bots->isBot($request)) {
            return false;
        }
        // One count per device per half hour; the key is a keyed hash, never stored.
        $key = 'card-'.$column.':'.$profile->id.':'.hash_hmac('sha256', $request->ip().'|'.$request->userAgent(), (string) config('app.key'));
        if (! Cache::add($key, 1, now()->addMinutes(30))) {
            return false;
        }

        $day = now((string) config('affiliate.business_timezone'))->toDateString();
        // createOrFirst survives two first taps of the day racing; increments are atomic.
        CardProfileDailyStat::query()->createOrFirst(['card_profile_id' => $profile->id, 'day' => $day], ['taps' => 0, 'saves' => 0]);
        CardProfileDailyStat::query()->where('card_profile_id', $profile->id)->where('day', $day)->increment($column);
        CardProfile::query()->whereKey($profile->id)->increment($column.'_total');

        return true;
    }

    /**
     * @param  array<string, mixed>  $socials
     * @return array<string, string>
     */
    private function cleanSocials(array $socials): array
    {
        $out = [];
        foreach (CardProfile::SOCIALS as $network) {
            $value = trim((string) ($socials[$network] ?? ''));
            if ($value !== '') {
                $out[$network] = $value;
            }
        }

        return $out;
    }
}
