import 'package:flutter/material.dart';

import '../../api/format.dart';
import '../../api/models.dart';
import '../../ui/paged_list.dart';
import '../../ui/widgets.dart';

enum _View { commissions, history, rates }

class EarningsTab extends StatefulWidget {
  const EarningsTab({super.key});

  @override
  State<EarningsTab> createState() => _EarningsTabState();
}

class _EarningsTabState extends State<EarningsTab> {
  _View _view = _View.commissions;
  String? _status;

  static const _statuses = <String?, String>{
    null: 'All',
    'pending': 'Pending',
    'approved': 'Approved',
    'available': 'Available',
    'paid': 'Paid',
    'reversed': 'Reversed',
  };

  @override
  Widget build(BuildContext context) {
    final header = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _Summary(),
        const SizedBox(height: 12),
        SegmentedButton<_View>(
          segments: const [
            ButtonSegment(value: _View.commissions, label: Text('Orders')),
            ButtonSegment(value: _View.history, label: Text('History')),
            ButtonSegment(value: _View.rates, label: Text('Rates')),
          ],
          selected: {_view},
          onSelectionChanged: (s) => setState(() => _view = s.first),
        ),
        if (_view == _View.commissions) ...[
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                for (final e in _statuses.entries)
                  Padding(
                    padding: const EdgeInsets.only(right: 6),
                    child: ChoiceChip(
                      label: Text(e.value),
                      selected: _status == e.key,
                      onSelected: (_) => setState(() => _status = e.key),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ],
    );

    return switch (_view) {
      _View.commissions => PagedList<Commission>(
        key: ValueKey('commissions-$_status'),
        header: header,
        load: (page) => apiOf(context).commissions(page: page, status: _status),
        empty: const EmptyState(
          icon: Icons.payments_outlined,
          title: 'No commissions here yet',
        ),
        itemBuilder: (context, c) => _CommissionTile(c),
      ),
      _View.history => PagedList<LedgerEntry>(
        key: const ValueKey('ledger'),
        header: header,
        load: (page) => apiOf(context).ledger(page: page),
        empty: const EmptyState(
          icon: Icons.history,
          title: 'Nothing has happened yet',
        ),
        itemBuilder: (context, e) => Card(
          child: ListTile(
            title: Text(e.label),
            subtitle: Text(dateTime(e.createdAt)),
            trailing: Text(
              money(e.amountMinor, e.currency),
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
          ),
        ),
      ),
      _View.rates => LoadView<List<ProductRate>>(
        load: () => apiOf(context).productRates(),
        builder: (context, rates, _) => PageList(
          children: [
            header,
            const SizedBox(height: 8),
            Card(
              child: Column(
                children: [
                  for (final r in rates)
                    ListTile(
                      title: Text(r.name),
                      trailing: Text(
                        r.earns
                            ? '${money(r.perUnitMinor, r.currency)} each'
                            : 'No commission',
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'Commission is worked out on what the customer paid after discounts, not on delivery.',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    };
  }
}

class _Summary extends StatefulWidget {
  @override
  State<_Summary> createState() => _SummaryState();
}

class _SummaryState extends State<_Summary> {
  late final Future<EarningsSummary> _future = apiOf(context).earningsSummary();

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<EarningsSummary>(
      future: _future,
      builder: (context, snap) {
        final s = snap.data;
        if (s == null) return const SizedBox(height: 4);
        final b = s.balances;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            StatGrid(
              tiles: [
                StatTile(
                  label: 'Available',
                  value: money(b.available, s.currency),
                  highlight: true,
                ),
                StatTile(
                  label: 'Pending',
                  value: money(b.pending + b.approved, s.currency),
                ),
              ],
            ),
            Text(
              'New commission stays pending for ${s.holdingPeriodDays} days in case of returns.'
              '${s.nextEligibleAt != null ? ' The next one clears on ${date(s.nextEligibleAt)}.' : ''}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        );
      },
    );
  }
}

class _CommissionTile extends StatelessWidget {
  const _CommissionTile(this.c);
  final Commission c;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${c.productName} × ${c.quantity}',
                    style: theme.textTheme.titleSmall,
                  ),
                  Text(
                    'Order ${c.orderNumber} · ${date(c.createdAt)}',
                    style: theme.textTheme.bodySmall,
                  ),
                  if (c.status == 'pending' && c.eligibleAt != null)
                    Text(
                      c.onHold
                          ? 'On hold for review'
                          : 'Clears ${date(c.eligibleAt)}',
                      style: theme.textTheme.bodySmall,
                    ),
                ],
              ),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  money(c.netMinor, c.currency),
                  style: theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                StatusChip(c.statusLabel, status: c.status),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
