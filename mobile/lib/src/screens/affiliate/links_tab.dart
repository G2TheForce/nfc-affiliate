import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/format.dart';
import '../../api/models.dart';
import '../../ui/widgets.dart';

class LinksTab extends StatefulWidget {
  const LinksTab({super.key});

  @override
  State<LinksTab> createState() => _LinksTabState();
}

class _LinksTabState extends State<LinksTab> {
  Future<(ReferralLinks, List<Campaign>)> _load() async {
    final api = apiOf(context);
    final results = await Future.wait([api.links(), api.campaigns()]);
    return (results[0] as ReferralLinks, results[1] as List<Campaign>);
  }

  @override
  Widget build(BuildContext context) {
    return LoadView<(ReferralLinks, List<Campaign>)>(
      load: _load,
      builder: (context, data, reload) {
        final (links, campaigns) = data;
        final theme = Theme.of(context);
        return Scaffold(
          floatingActionButton: links.canCreate
              ? FloatingActionButton.extended(
                  icon: const Icon(Icons.add_link),
                  label: const Text('New link'),
                  onPressed: () async {
                    final created = await showModalBottomSheet<bool>(
                      context: context,
                      isScrollControlled: true,
                      showDragHandle: true,
                      builder: (_) => NewLinkSheet(campaigns: campaigns),
                    );
                    if (created == true) await reload();
                  },
                )
              : null,
          body: PageList(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
            children: [
              LinkBox(
                title: 'Main link',
                url: links.mainUrl,
                shareMessage: 'Get your smart NFC card here:',
              ),
              const SizedBox(height: 6),
              Text(
                'Anyone who orders within ${links.windowDays} days of tapping one of your links counts as your referral. '
                'Customers can also type your code ${links.referralCode} at checkout.',
                style: theme.textTheme.bodySmall,
              ),
              if (campaigns.isNotEmpty) ...[
                const SectionTitle('Running campaigns'),
                for (final c in campaigns)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Card(
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(c.name, style: theme.textTheme.titleSmall),
                            if (c.commissionText != null)
                              Text(
                                c.commissionText!,
                                style: TextStyle(
                                  color: theme.colorScheme.primary,
                                ),
                              ),
                            if (c.description != null) ...[
                              const SizedBox(height: 4),
                              Text(c.description!),
                            ],
                            if (c.endsAt != null)
                              Text(
                                'Ends ${date(c.endsAt)}',
                                style: theme.textTheme.bodySmall,
                              ),
                            if (c.couponCodes.isNotEmpty)
                              Wrap(
                                spacing: 8,
                                children: [
                                  for (final code in c.couponCodes)
                                    ActionChip(
                                      avatar: const Icon(
                                        Icons.local_offer_outlined,
                                        size: 16,
                                      ),
                                      label: Text(code),
                                      onPressed: () => copyText(
                                        context,
                                        code,
                                        label: 'Coupon $code copied',
                                      ),
                                    ),
                                ],
                              ),
                          ],
                        ),
                      ),
                    ),
                  ),
              ],
              SectionTitle('Your links (${links.links.length})'),
              if (links.links.isEmpty)
                const EmptyState(
                  icon: Icons.link,
                  title: 'No extra links yet',
                  body: 'Make a separate link for each place you post, like your Instagram bio or a Facebook group, to see which one works best.',
                ),
              for (final link in links.links)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: _LinkCard(link: link, onChanged: reload),
                ),
            ],
          ),
        );
      },
    );
  }
}

class _LinkCard extends StatelessWidget {
  const _LinkCard({required this.link, required this.onChanged});
  final ReferralLink link;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final details = [
      if (link.productName != null) link.productName!,
      if (link.campaignName != null) link.campaignName!,
    ].join(' · ');
    return Opacity(
      opacity: link.isActive ? 1 : 0.6,
      child: Card(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 10, 4, 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(link.name, style: theme.textTheme.titleSmall),
                  ),
                  if (!link.isActive) const StatusChip('Off', status: 'off'),
                  PopupMenuButton<String>(
                    onSelected: (action) async {
                      try {
                        await apiOf(context)
                            .setLinkActive(link.id, action == 'on');
                        await onChanged();
                      } catch (e) {
                        if (context.mounted) showSnack(context, errorText(e));
                      }
                    },
                    itemBuilder: (_) => [
                      link.isActive
                          ? const PopupMenuItem(
                              value: 'off',
                              child: Text('Turn off'),
                            )
                          : const PopupMenuItem(
                              value: 'on',
                              child: Text('Turn on'),
                            ),
                    ],
                  ),
                ],
              ),
              if (details.isNotEmpty)
                Text(details, style: theme.textTheme.bodySmall),
              Text(
                link.url,
                style: TextStyle(color: theme.colorScheme.primary),
              ),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  IconButton(
                    tooltip: 'Copy',
                    icon: const Icon(Icons.copy_rounded),
                    onPressed: () =>
                        copyText(context, link.url, label: 'Link copied'),
                  ),
                  IconButton(
                    tooltip: 'QR code',
                    icon: const Icon(Icons.qr_code_2_rounded),
                    onPressed: () =>
                        showQr(context, link.url, title: link.name),
                  ),
                  IconButton(
                    tooltip: 'Share',
                    icon: const Icon(Icons.share_rounded),
                    onPressed: () => shareText(link.url),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class NewLinkSheet extends StatefulWidget {
  const NewLinkSheet({super.key, required this.campaigns});
  final List<Campaign> campaigns;

  @override
  State<NewLinkSheet> createState() => _NewLinkSheetState();
}

class _NewLinkSheetState extends State<NewLinkSheet> {
  final _name = TextEditingController();
  final _slug = TextEditingController();
  final _source = TextEditingController();
  bool _slugEdited = false;
  String? _productId;
  String? _campaignId;
  late final Future<List<Product>> _products = apiOf(context)
      .products()
      .then((p) => p.items);
  ApiException? _error;

  @override
  void dispose() {
    _name.dispose();
    _slug.dispose();
    _source.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _error = null);
    try {
      await apiOf(context).createLink({
        'name': _name.text.trim(),
        'slug': _slug.text.trim(),
        'product_id': _productId,
        'campaign_id': _campaignId,
        if (_source.text.trim().isNotEmpty) 'utm_source': _source.text.trim(),
      });
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
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
              'New referral link',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _name,
              autofocus: true,
              decoration: InputDecoration(
                labelText: 'Name',
                hintText: 'Instagram bio',
                errorText: _error?.field('name'),
              ),
              onChanged: (v) {
                if (!_slugEdited) _slug.text = slugify(v);
              },
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _slug,
              decoration: InputDecoration(
                labelText: 'Link ending',
                helperText: 'Lower-case letters, numbers and dashes. Cannot be changed later.',
                errorText: _error?.field('slug'),
              ),
              onChanged: (_) => _slugEdited = true,
            ),
            const SizedBox(height: 12),
            FutureBuilder<List<Product>>(
              future: _products,
              builder: (context, snap) => DropdownButtonFormField<String?>(
                initialValue: _productId,
                isExpanded: true,
                decoration: InputDecoration(
                  labelText: 'Opens product (optional)',
                  errorText: _error?.field('product_id'),
                ),
                items: [
                  const DropdownMenuItem(value: null, child: Text('The shop')),
                  for (final p in snap.data ?? const <Product>[])
                    DropdownMenuItem(
                      value: p.id,
                      child: Text(p.name, overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: (v) => setState(() => _productId = v),
              ),
            ),
            if (widget.campaigns.isNotEmpty) ...[
              const SizedBox(height: 12),
              DropdownButtonFormField<String?>(
                initialValue: _campaignId,
                isExpanded: true,
                decoration: InputDecoration(
                  labelText: 'Campaign (optional)',
                  errorText: _error?.field('campaign_id'),
                ),
                items: [
                  const DropdownMenuItem(value: null, child: Text('None')),
                  for (final c in widget.campaigns)
                    DropdownMenuItem(value: c.id, child: Text(c.name)),
                ],
                onChanged: (v) => setState(() => _campaignId = v),
              ),
            ],
            const SizedBox(height: 12),
            TextField(
              controller: _source,
              decoration: InputDecoration(
                labelText: 'Source tag (optional)',
                hintText: 'instagram',
                errorText: _error?.field('utm_source'),
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
            BusyButton(label: 'Create link', onPressed: _save),
          ],
        ),
      ),
    );
  }
}

/// "Insta Bio!" -> "insta-bio", at most 60 characters, as the API requires.
String slugify(String input) {
  final slug = input
      .toLowerCase()
      .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
      .replaceAll(RegExp(r'^-+|-+$'), '');
  return slug.length <= 60
      ? slug
      : slug.substring(0, 60).replaceAll(RegExp(r'-+$'), '');
}
