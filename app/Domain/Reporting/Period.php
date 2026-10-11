<?php

namespace App\Domain\Reporting;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A reporting date range in the business timezone (ADR 0010). Days start at
 * midnight Nepal time; the range is inclusive and at most a year. Charts use
 * daily buckets up to 92 days and monthly buckets beyond that.
 */
final class Period
{
    public const MAX_DAYS = 366;

    private const DAILY_LIMIT_DAYS = 92;

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function fromInput(?string $from, ?string $to, int $defaultDays = 30): self
    {
        $tz = self::timezone();
        $end = $to ? CarbonImmutable::parse($to, $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();
        $start = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : $end->subDays($defaultDays - 1)->startOfDay();

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }
        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose a range of at most one year.']);
        }

        return new self($start, $end);
    }

    public static function between(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($from, $to);
    }

    public static function timezone(): string
    {
        return (string) config('affiliate.business_timezone');
    }

    /** UTC bounds for whereBetween on stored timestamps. */
    public function utc(): array
    {
        return [$this->from->utc(), $this->to->utc()];
    }

    public function grain(): string
    {
        return $this->from->diffInDays($this->to) < self::DAILY_LIMIT_DAYS ? 'day' : 'month';
    }

    /**
     * Every bucket key in the range, so charts show empty days as zero.
     *
     * @return list<string>
     */
    public function bucketKeys(): array
    {
        $keys = [];
        if ($this->grain() === 'day') {
            foreach (CarbonPeriod::create($this->from->startOfDay(), $this->to->startOfDay()) as $day) {
                $keys[] = $day->format('Y-m-d');
            }
        } else {
            foreach (CarbonPeriod::create($this->from->startOfMonth(), '1 month', $this->to->startOfMonth()) as $month) {
                $keys[] = $month->format('Y-m');
            }
        }

        return $keys;
    }

    /**
     * SQL expression turning a UTC timestamp column into the local bucket key.
     * The offset is taken at the start of the range; Asia/Kathmandu has no
     * daylight saving, so it is the same for every row.
     */
    public function bucketSql(string $column, ?string $grain = null): string
    {
        $offset = (int) round($this->from->utcOffset());
        $driver = DB::connection()->getDriverName();
        $format = ($grain ?? $this->grain()) === 'day' ? 'day' : 'month';

        return match ($driver) {
            'sqlite' => sprintf("strftime('%s', %s, '%+d minutes')", $format === 'day' ? '%Y-%m-%d' : '%Y-%m', $column, $offset),
            default => sprintf("DATE_FORMAT(DATE_ADD(%s, INTERVAL %d MINUTE), '%s')", $column, $offset, $format === 'day' ? '%Y-%m-%d' : '%Y-%m'),
        };
    }

    /**
     * @return array{from: string, to: string, grain: string, timezone: string}
     */
    public function describe(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'grain' => $this->grain(), 'timezone' => self::timezone()];
    }
}
