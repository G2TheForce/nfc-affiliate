<?php

namespace App\Domain\Reporting;

use App\Domain\Reporting\Reports\AffiliatePerformance;
use App\Domain\Reporting\Reports\AffiliateRegistrations;
use App\Domain\Reporting\Reports\AffiliateRetention;
use App\Domain\Reporting\Reports\CampaignPerformance;
use App\Domain\Reporting\Reports\CommissionAccruals;
use App\Domain\Reporting\Reports\CommissionReversals;
use App\Domain\Reporting\Reports\FraudReview;
use App\Domain\Reporting\Reports\MyCommissions;
use App\Domain\Reporting\Reports\MyOrders;
use App\Domain\Reporting\Reports\MyPayouts;
use App\Domain\Reporting\Reports\MyStatement;
use App\Domain\Reporting\Reports\PayoutLiability;
use App\Domain\Reporting\Reports\Payouts;
use App\Domain\Reporting\Reports\ProductPerformance;
use App\Domain\Reporting\Reports\Refunds;
use App\Models\User;

class ReportRegistry
{
    public function __construct(private readonly Metrics $metrics) {}

    /**
     * @return array<string, Report>
     */
    public function staff(): array
    {
        return $this->keyed([
            new AffiliateRegistrations,
            new AffiliatePerformance($this->metrics),
            new AffiliateRetention,
            new ProductPerformance($this->metrics),
            new CampaignPerformance($this->metrics),
            new CommissionAccruals($this->metrics),
            new CommissionReversals,
            new PayoutLiability,
            new Payouts,
            new Payouts(outstanding: true),
            new Refunds,
            new FraudReview,
        ]);
    }

    /**
     * @return array<string, Report>
     */
    public function affiliate(): array
    {
        return $this->keyed([new MyCommissions($this->metrics), new MyOrders, new MyPayouts, new MyStatement]);
    }

    /**
     * @return array<string, Report>
     */
    public function staffFor(User $user): array
    {
        return array_filter($this->staff(), fn (Report $r) => $user->can($r->permission()->value));
    }

    /**
     * @param  list<Report>  $reports
     * @return array<string, Report>
     */
    private function keyed(array $reports): array
    {
        return collect($reports)->keyBy(fn (Report $r) => $r->key())->all();
    }
}
