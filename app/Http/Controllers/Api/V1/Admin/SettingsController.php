<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SystemSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Programme settings (settings.manage). */
class SettingsController extends Controller
{
    public function __construct(private readonly SystemSettings $settings) {}

    public function index(): array
    {
        $rows = DB::table('system_settings')->get()->keyBy('key');
        $people = User::query()->whereIn('id', $rows->pluck('updated_by')->filter()->unique())->pluck('name', 'id');

        $data = [];
        foreach (SettingsRegistry::definitions() as $key => $d) {
            $row = $rows->get($key);
            $data[] = [
                'key' => $key,
                'group' => $d['group'],
                'label' => $d['label'],
                'help' => $d['help'],
                'type' => $d['type'],
                'min' => $d['min'] ?? null,
                'max' => $d['max'] ?? null,
                'choices' => isset($d['choices']) ? collect($d['choices'])->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values() : null,
                'value' => config(SettingsRegistry::configKey($key)),
                'default' => $this->settings->default($key),
                'overridden' => $row !== null,
                'updated_at' => $row ? Carbon::parse($row->updated_at)->toIso8601String() : null,
                'updated_by' => $row && $row->updated_by ? ($people[$row->updated_by] ?? null) : null,
            ];
        }

        return ['data' => $data, 'currency' => config('affiliate.currency.default')];
    }

    public function update(Request $request): array
    {
        $request->validate([
            'settings' => ['required', 'array', 'min:1', 'max:50'],
            'settings.*.key' => ['required', 'string', Rule::in(array_keys(SettingsRegistry::definitions()))],
            'settings.*.value' => ['present'],
        ]);

        $values = [];
        $errors = [];
        foreach ($request->input('settings') as $i => $item) {
            $d = SettingsRegistry::definitions()[$item['key']];
            $range = in_array($d['type'], ['int', 'money'], true)
                ? ($d['type'] === 'money'
                    ? 'Enter an amount from NPR '.number_format($d['min'] / 100).' to NPR '.number_format($d['max'] / 100).'.'
                    : "Enter a whole number from {$d['min']} to {$d['max']}.")
                : 'Choose one of the options.';
            $v = Validator::make(['value' => $item['value']], ['value' => SettingsRegistry::rulesFor($item['key'])], [
                'value.*' => $range,
            ]);
            if ($v->fails()) {
                $errors["settings.{$i}.value"] = $v->errors()->get('value');
            }
            $values[$item['key']] = $item['value'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $changed = $this->settings->update($values, $request->user());

        return $this->index() + ['changed' => $changed];
    }

    public function reset(Request $request, string $key): array
    {
        abort_unless(array_key_exists($key, SettingsRegistry::definitions()), 404);
        $this->settings->reset($key, $request->user());

        return $this->index();
    }
}
