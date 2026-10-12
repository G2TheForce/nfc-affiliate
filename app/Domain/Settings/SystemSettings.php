<?php

namespace App\Domain\Settings;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies the Settings screen's overrides on top of config/affiliate.php.
 * Overrides are cached; saving clears the cache and asks queue workers to
 * restart after their current job, so every process picks up the change.
 */
class SystemSettings
{
    private const CACHE_KEY = 'system_settings.v1';

    /**
     * The .env defaults are kept in config under this key. `config:cache`
     * boots the app (and so runs apply()) before writing the cache, so the
     * cached affiliate.* values may already hold overrides; this key keeps
     * the true defaults next to them.
     */
    private const DEFAULTS_KEY = 'system_settings_defaults';

    // No constructor injection: this class is built at boot, before the
    // request exists, and the audit logger must see the signed-in user.
    private function audit(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    /** Called at boot. Quietly does nothing before the table exists (fresh installs, tests). */
    public function apply(): void
    {
        $defaults = config(self::DEFAULTS_KEY);
        if (! is_array($defaults)) {
            $defaults = [];
            foreach (array_keys(SettingsRegistry::definitions()) as $key) {
                $defaults[$key] = config(SettingsRegistry::configKey($key));
            }
            config([self::DEFAULTS_KEY => $defaults]);
        }

        $overrides = $this->overrides();
        foreach ($defaults as $key => $default) {
            // Setting the default too undoes an override baked into a config cache.
            config([SettingsRegistry::configKey($key) => array_key_exists($key, $overrides) ? $overrides[$key] : $default]);
        }
    }

    public function default(string $key): mixed
    {
        $defaults = config(self::DEFAULTS_KEY);

        return is_array($defaults) && array_key_exists($key, $defaults) ? $defaults[$key] : config(SettingsRegistry::configKey($key));
    }

    /** @return array<string, mixed> */
    public function overrides(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
            $rows = DB::table('system_settings')->pluck('value', 'key')->all();
        } catch (Throwable) {
            // No cache or settings table yet (fresh install, migrations running).
            return [];
        }

        $known = SettingsRegistry::definitions();
        $overrides = [];
        foreach ($rows as $key => $json) {
            if (isset($known[$key])) {
                $overrides[$key] = SettingsRegistry::cast($key, json_decode((string) $json, true));
            }
        }
        Cache::forever(self::CACHE_KEY, $overrides);

        return $overrides;
    }

    /**
     * @param  array<string, mixed>  $values  registry key => new value
     * @return list<string> the keys that changed
     */
    public function update(array $values, User $actor): array
    {
        $changed = [];

        DB::transaction(function () use ($values, $actor, &$changed) {
            foreach ($values as $key => $value) {
                $value = SettingsRegistry::cast($key, $value);
                $current = config(SettingsRegistry::configKey($key));
                if ($current === $value) {
                    continue;
                }
                DB::table('system_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => json_encode($value), 'updated_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()],
                );
                config([SettingsRegistry::configKey($key) => $value]);
                $this->audit()->log('setting.changed', null, ['key' => $key, 'from' => $current, 'to' => $value], $actor);
                $changed[] = $key;
            }
        });

        if ($changed !== []) {
            Cache::forget(self::CACHE_KEY);
            // Same signal as `php artisan queue:restart`.
            Cache::forever('illuminate:queue:restart', now()->getTimestamp());
        }

        return $changed;
    }

    /** Removes an override so the .env default applies again. */
    public function reset(string $key, User $actor): void
    {
        $row = DB::table('system_settings')->where('key', $key)->first();
        if (! $row) {
            return;
        }
        DB::transaction(function () use ($key, $row, $actor) {
            DB::table('system_settings')->where('key', $key)->delete();
            $this->audit()->log('setting.reset', null, ['key' => $key, 'from' => json_decode((string) $row->value, true), 'to' => $this->default($key)], $actor);
        });
        config([SettingsRegistry::configKey($key) => $this->default($key)]);
        Cache::forget(self::CACHE_KEY);
        Cache::forever('illuminate:queue:restart', now()->getTimestamp());
    }
}
