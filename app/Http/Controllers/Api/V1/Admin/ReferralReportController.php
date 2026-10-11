<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Referrals\ReferralReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReferralReportController extends Controller
{
    public function summary(Request $request, ReferralReport $report): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $range = ReferralReport::range($data['from'] ?? null, $data['to'] ?? null);

        if ($range['from']->diffInDays($range['to']) > 366) {
            throw ValidationException::withMessages(['from' => 'Choose a range of at most one year.']);
        }

        return ['data' => [
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'currency' => config('affiliate.currency.default'),
            ...$report->programme($range['from'], $range['to']),
        ]];
    }
}
