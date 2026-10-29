import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../api/format.dart';
import '../../api/models.dart';
import '../../ui/widgets.dart';

class OverviewTab extends StatelessWidget {
  const OverviewTab({super.key, required this.profile});
  final AffiliateProfile profile;

  @override
  Widget build(BuildContext context) {
    return LoadView<(Dashboard, ReferralLinks)>(
      load: () async {
        final api = apiOf(context);
        final results = await Future.wait([api.dashboard(), api.links()]);
        return (results[0] as Dashboard, results[1] as ReferralLinks);
      },
      builder: (context, data, _) {
        final (d, links) = data;
        final c = d.currency;
        final theme = Theme.of(context);
        return PageList(
          children: [
            Text(
              'Hi ${profile.displayName}',
              style: theme.textTheme.titleLarge,
            ),
            Text(
              'Your code: ${profile.referralCode}',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 12),
            LinkBox(
              title: 'Your referral link',
              url: links.mainUrl,
              shareMessage: 'Get your smart NFC card here:',
            ),
            const SectionTitle('Balance'),
            StatGrid(
              tiles: [
                StatTile(
                  label: 'Available to withdraw',
                  value: money(d.balances.available, c),
                  highlight: true,
                ),
                StatTile(
                  label: 'Pending',
                  value: money(d.balances.pending + d.balances.approved, c),
                  hint: 'Not yet available',
                ),
                StatTile(
                  label: 'In a payout request',
                  value: money(d.balances.reserved, c),
                ),
                StatTile(
                  label: 'Paid to you',
                  value: money(d.balances.paid, c),
                ),
              ],
            ),
            const SectionTitle('Last 30 days'),
            StatGrid(
              tiles: [
                StatTile(label: 'Clicks', value: '${d.clicks}'),
                StatTile(
                  label: 'Orders',
                  value: '${d.attributedOrders}',
                  hint: '${d.paidOrders} paid',
                ),
                StatTile(label: 'Sales', value: money(d.revenueMinor, c)),
                StatTile(
                  label: 'Earned',
                  value: money(d.earnedMinor, c),
                  hint: d.conversionRate == null
                      ? null
                      : '${d.conversionRate}% of clicks ordered',
                ),
              ],
            ),
            const SectionTitle('Earnings per day'),
            Card(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 16, 12, 8),
                child: SizedBox(
                  height: 140,
                  child: _Bars(
                    values: [for (final s in d.series) s.earnedMinor],
                    labels: [for (final s in d.series) s.bucket],
                  ),
                ),
              ),
            ),
          ],
        );
      },
    );
  }
}

/// A small bar chart; no chart package needed for one series.
class _Bars extends StatelessWidget {
  const _Bars({required this.values, required this.labels});
  final List<int> values;
  final List<String> labels;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final maxValue = values.fold<int>(0, math.max);
    if (maxValue == 0) {
      return Center(
        child: Text(
          'No earnings in this period yet',
          style: TextStyle(color: scheme.onSurfaceVariant),
        ),
      );
    }
    return Column(
      children: [
        Expanded(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              for (var i = 0; i < values.length; i++)
                Expanded(
                  child: Tooltip(
                    message: '${labels[i]}: ${money(values[i])}',
                    child: Container(
                      margin: const EdgeInsets.symmetric(horizontal: 1),
                      height: math.max(2, 110 * values[i] / maxValue),
                      decoration: BoxDecoration(
                        color: values[i] == 0
                            ? scheme.outlineVariant
                            : scheme.primary,
                        borderRadius: const BorderRadius.vertical(
                          top: Radius.circular(3),
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 6),
        Row(
          children: [
            Text(
              labels.isEmpty ? '' : labels.first,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const Spacer(),
            Text(
              labels.isEmpty ? '' : labels.last,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ],
    );
  }
}
