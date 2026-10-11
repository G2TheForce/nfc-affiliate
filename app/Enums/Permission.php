<?php

namespace App\Enums;

/**
 * Every server-side permission in the system. Permissions for later phases
 * are declared now so the matrix is reviewed once and tested from day one.
 */
enum Permission: string
{
    // Staff area
    case AdminAccess = 'admin.access';
    case AdminsManage = 'admins.manage';
    case SettingsManage = 'settings.manage';
    case IntegrationsManage = 'integrations.manage';
    case AuditLogsView = 'audit_logs.view';

    // Affiliate management
    case AffiliatesView = 'affiliates.view';
    case AffiliatesReview = 'affiliates.review';
    case AffiliatesSuspend = 'affiliates.suspend';
    case CommissionRulesManage = 'commission_rules.manage';
    case CampaignsManage = 'campaigns.manage';
    case MarketingAssetsManage = 'marketing_assets.manage';

    // Store
    case CatalogManage = 'catalog.manage';

    // Orders and attribution
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case AttributionView = 'attribution.view';
    case AttributionManual = 'attribution.manual';

    // Finance
    case CommissionsView = 'commissions.view';
    case CommissionsAdjust = 'commissions.adjust';
    case PayoutsView = 'payouts.view';
    case PayoutsReview = 'payouts.review';
    case PayoutsMarkPaid = 'payouts.mark_paid';
    case ReconciliationView = 'reconciliation.view';

    // Reporting and risk
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';
    case FraudReview = 'fraud.review';

    // Affiliate self-service (always scoped to the member's own records)
    case AffiliatePortal = 'affiliate.portal';
    case AffiliatePayoutsRequest = 'affiliate.payouts.request';

    // Customer self-service
    case CustomerPortal = 'customer.portal';

    public function description(): string
    {
        return match ($this) {
            self::AdminAccess => 'Open the staff area',
            self::AdminsManage => 'Create and manage staff accounts and roles',
            self::SettingsManage => 'Change system and programme settings',
            self::IntegrationsManage => 'Configure payment, email and other integrations',
            self::AuditLogsView => 'Read the audit log',
            self::AffiliatesView => 'View affiliate profiles and applications',
            self::AffiliatesReview => 'Approve or reject affiliate applications',
            self::AffiliatesSuspend => 'Suspend, deactivate or reinstate affiliates',
            self::CommissionRulesManage => 'Create and change commission rules',
            self::CampaignsManage => 'Create and change campaigns and coupons',
            self::MarketingAssetsManage => 'Upload and manage marketing assets',
            self::CatalogManage => 'Create and change products and categories',
            self::OrdersView => 'View orders and masked customer details',
            self::OrdersManage => 'Cancel unpaid orders (reason required)',
            self::AttributionView => 'View order attribution',
            self::AttributionManual => 'Manually attribute an order (reason required)',
            self::CommissionsView => 'View commissions and the ledger',
            self::CommissionsAdjust => 'Create compensating commission adjustments',
            self::PayoutsView => 'View payout requests',
            self::PayoutsReview => 'Approve or reject payout requests',
            self::PayoutsMarkPaid => 'Confirm a payout as paid with a reference',
            self::ReconciliationView => 'View reconciliation results',
            self::ReportsView => 'View reports',
            self::ReportsExport => 'Export reports (audited)',
            self::FraudReview => 'Review and resolve fraud flags',
            self::AffiliatePortal => 'Use the affiliate dashboard (own records only)',
            self::AffiliatePayoutsRequest => 'Request a payout of own available balance',
            self::CustomerPortal => 'Use the customer account area',
        };
    }
}
