import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/format.dart';
import '../../api/models.dart';
import '../../state/session.dart';
import '../../ui/widgets.dart';

class PayoutsTab extends StatelessWidget {
  const PayoutsTab({super.key});

  @override
  Widget build(BuildContext context) {
    final canRequest = context.select<Session, bool>(
      (s) => s.user?.canRequestPayouts ?? false,
    );
    return LoadView<(PayoutSummary, List<Payout>)>(
      load: () async {
        final api = apiOf(context);
        final results = await Future.wait([api.payoutSummary(), api.payouts()]);
        return (
          results[0] as PayoutSummary,
          (results[1] as Paginated<Payout>).items,
        );
      },
      builder: (context, data, reload) {
        final (s, payouts) = data;
        final theme = Theme.of(context);
        return PageList(
          children: [
            StatGrid(
              tiles: [
                StatTile(
                  label: 'Available',
                  value: money(s.availableMinor, s.currency),
                  highlight: true,
                  hint: 'Minimum ${money(s.minimumMinor, s.currency)}',
                ),
                StatTile(
                  label: 'Paid so far',
                  value: money(s.paidMinor, s.currency),
                ),
              ],
            ),
            if (canRequest) ...[
              if (s.openPayout == null) ...[
                BusyButton(
                  label:
                      'Request payout of ${money(s.availableMinor, s.currency)}',
                  icon: Icons.account_balance_outlined,
                  onPressed: s.canRequest
                      ? () async {
                          final ok = await _confirm(context, s);
                          if (!ok || !context.mounted) return;
                          try {
                            await apiOf(context).requestPayout();
                            if (context.mounted) {
                              showSnack(
                                context,
                                'Payout requested. We will let you know when it is paid.',
                              );
                            }
                            await reload();
                          } catch (e) {
                            if (context.mounted) {
                              showSnack(context, errorText(e));
                            }
                          }
                        }
                      : null,
                ),
                if (s.blocker != null) ...[
                  const SizedBox(height: 6),
                  Text(
                    s.blocker!,
                    style: theme.textTheme.bodySmall,
                    textAlign: TextAlign.center,
                  ),
                ],
              ],
            ],
            const SectionTitle('Paid to'),
            Card(
              child: ListTile(
                leading: const Icon(Icons.account_balance_wallet_outlined),
                title: Text(s.method?.masked ?? 'Not set yet'),
                subtitle: Text(
                  s.method == null
                      ? 'Add your bank or wallet details to get paid'
                      : s.method!.accountName,
                ),
                trailing: canRequest
                    ? TextButton(
                        onPressed: () => _editMethod(context, s.method, reload),
                        child: Text(s.method == null ? 'Add' : 'Change'),
                      )
                    : null,
              ),
            ),
            const SectionTitle('Payout requests'),
            if (payouts.isEmpty)
              const EmptyState(
                icon: Icons.receipt_outlined,
                title: 'No payouts yet',
              ),
            for (final p in payouts)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                money(p.amountMinor, p.currency),
                                style: theme.textTheme.titleMedium?.copyWith(
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                            StatusChip(p.statusLabel, status: p.status),
                          ],
                        ),
                        Text(
                          'Requested ${date(p.requestedAt)} · ${p.methodLabel} ${p.destination ?? ''}',
                          style: theme.textTheme.bodySmall,
                        ),
                        if (p.paidAt != null)
                          Text(
                            'Paid ${date(p.paidAt)}${p.paymentReference != null ? ' · Ref ${p.paymentReference}' : ''}',
                          ),
                        if (p.reason != null)
                          Text(
                            p.reason!,
                            style: TextStyle(color: theme.colorScheme.error),
                          ),
                        if (p.canCancel)
                          Align(
                            alignment: Alignment.centerRight,
                            child: TextButton(
                              onPressed: () async {
                                try {
                                  await apiOf(context).cancelPayout(p.id);
                                  await reload();
                                } catch (e) {
                                  if (context.mounted) {
                                    showSnack(context, errorText(e));
                                  }
                                }
                              },
                              child: const Text('Cancel request'),
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  Future<bool> _confirm(BuildContext context, PayoutSummary s) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Request a payout?'),
          content: Text(
            'We will send ${money(s.availableMinor, s.currency)} to ${s.method?.masked ?? 'your payout account'} once it is approved.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Not now'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Request'),
            ),
          ],
        ),
      ) ??
      false;

  Future<void> _editMethod(
    BuildContext context,
    PayoutMethod? current,
    Future<void> Function() reload,
  ) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => PayoutMethodSheet(current: current),
    );
    if (saved == true) await reload();
  }
}

class PayoutMethodSheet extends StatefulWidget {
  const PayoutMethodSheet({super.key, this.current});
  final PayoutMethod? current;

  @override
  State<PayoutMethodSheet> createState() => _PayoutMethodSheetState();
}

class _PayoutMethodSheetState extends State<PayoutMethodSheet> {
  late String _type = widget.current?.type ?? 'esewa';
  late final _accountName = TextEditingController(
    text: widget.current?.accountName ?? '',
  );
  final _bank = TextEditingController();
  final _branch = TextEditingController();
  final _accountNumber = TextEditingController();
  final _wallet = TextEditingController();
  final _password = TextEditingController();
  ApiException? _error;

  @override
  void dispose() {
    for (final c in [
      _accountName,
      _bank,
      _branch,
      _accountNumber,
      _wallet,
      _password,
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _error = null);
    final bank = _type == 'bank_transfer';
    try {
      await apiOf(context).savePayoutMethod({
        'type': _type,
        'account_name': _accountName.text.trim(),
        if (bank) ...{
          'bank_name': _bank.text.trim(),
          'branch': _branch.text.trim(),
          'account_number': _accountNumber.text.trim(),
        } else
          'wallet_id': _wallet.text.trim(),
        'password': _password.text,
      });
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bank = _type == 'bank_transfer';
    return Padding(
      padding: EdgeInsets.fromLTRB(
        16,
        0,
        16,
        MediaQuery.viewInsetsOf(context).bottom + 16,
      ),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Where should we pay you?',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 12),
            SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'esewa', label: Text('eSewa')),
                ButtonSegment(value: 'khalti', label: Text('Khalti')),
                ButtonSegment(value: 'bank_transfer', label: Text('Bank')),
              ],
              selected: {_type},
              onSelectionChanged: (s) => setState(() => _type = s.first),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _accountName,
              decoration: InputDecoration(
                labelText: 'Account holder name',
                errorText: _error?.field('account_name'),
              ),
            ),
            const SizedBox(height: 12),
            if (bank) ...[
              TextField(
                controller: _bank,
                decoration: InputDecoration(
                  labelText: 'Bank',
                  errorText: _error?.field('bank_name'),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _branch,
                decoration: InputDecoration(
                  labelText: 'Branch (optional)',
                  errorText: _error?.field('branch'),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _accountNumber,
                decoration: InputDecoration(
                  labelText: 'Account number',
                  errorText: _error?.field('account_number'),
                ),
              ),
            ] else
              TextField(
                controller: _wallet,
                keyboardType: TextInputType.phone,
                decoration: InputDecoration(
                  labelText: 'Wallet mobile number',
                  hintText: '98XXXXXXXX',
                  errorText: _error?.field('wallet_id'),
                ),
              ),
            const SizedBox(height: 12),
            TextField(
              controller: _password,
              obscureText: true,
              decoration: InputDecoration(
                labelText: 'Your password',
                helperText: 'Needed to change where your money goes',
                errorText: _error?.field('password'),
              ),
            ),
            if (_error != null && _error!.fieldErrors.isEmpty) ...[
              const SizedBox(height: 8),
              Text(
                _error!.message,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            const SizedBox(height: 16),
            BusyButton(label: 'Save payout details', onPressed: _save),
          ],
        ),
      ),
    );
  }
}
