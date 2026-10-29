import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/models.dart';
import '../../state/session.dart';
import '../../ui/widgets.dart';

/// The affiliate application. Pops `true` once submitted.
class JoinScreen extends StatefulWidget {
  const JoinScreen({super.key});

  static const channels = <String, String>{
    'facebook': 'Facebook',
    'instagram': 'Instagram',
    'tiktok': 'TikTok',
    'youtube': 'YouTube',
    'whatsapp': 'WhatsApp',
    'viber': 'Viber',
    'telegram': 'Telegram',
    'website': 'Website or blog',
    'email': 'Email',
    'offline': 'In person',
    'other': 'Other',
  };

  @override
  State<JoinScreen> createState() => _JoinScreenState();
}

class _JoinScreenState extends State<JoinScreen> {
  final _form = GlobalKey<FormState>();
  late final _name = TextEditingController(
    text: context.read<Session>().user?.name ?? '',
  );
  final _city = TextEditingController(text: 'Kathmandu');
  final _website = TextEditingController();
  final _audience = TextEditingController();
  final Set<String> _channels = {};
  bool _accept = false;
  ApiException? _error;

  @override
  void dispose() {
    for (final c in [_name, _city, _website, _audience]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit(Terms terms) async {
    if (!_form.currentState!.validate()) return;
    if (_channels.isEmpty) {
      setState(
        () => _error = ApiException(
          422,
          'Pick at least one way you will promote us.',
        ),
      );
      return;
    }
    if (!_accept) {
      setState(
        () => _error = ApiException(422, 'Please accept the affiliate terms.'),
      );
      return;
    }
    setState(() => _error = null);
    try {
      await apiOf(context).applyAsAffiliate({
        'display_name': _name.text.trim(),
        'country_code': 'NP',
        'city': _city.text.trim(),
        'promotion_channels': _channels.toList(),
        if (_website.text.trim().isNotEmpty)
          'website_urls': [_website.text.trim()],
        if (_audience.text.trim().isNotEmpty)
          'audience_description': _audience.text.trim(),
        'terms_version': terms.version,
        'accept_terms': true,
      });
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Join the affiliate programme')),
      body: LoadView<Terms?>(
        load: () => apiOf(context).currentTerms(),
        builder: (context, terms, _) {
          if (terms == null) {
            return const PageList(
              children: [
                EmptyState(
                  icon: Icons.event_busy,
                  title: 'Applications are not open yet',
                  body: 'Please check back soon.',
                ),
              ],
            );
          }
          final theme = Theme.of(context);
          return Form(
            key: _form,
            child: PageList(
              children: [
                TextFormField(
                  controller: _name,
                  decoration: InputDecoration(
                    labelText: 'Name shown to customers',
                    helperText: 'Your name or your page/business name',
                    errorText: _error?.field('display_name'),
                  ),
                  validator: (v) => (v == null || v.trim().length < 2)
                      ? 'Enter a name'
                      : null,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _city,
                  decoration: InputDecoration(
                    labelText: 'City',
                    errorText: _error?.field('city'),
                  ),
                ),
                const SectionTitle('Where will you promote us?'),
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  children: [
                    for (final e in JoinScreen.channels.entries)
                      FilterChip(
                        label: Text(e.value),
                        selected: _channels.contains(e.key),
                        onSelected: (on) => setState(
                          () => on
                              ? _channels.add(e.key)
                              : _channels.remove(e.key),
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _website,
                  keyboardType: TextInputType.url,
                  decoration: InputDecoration(
                    labelText: 'Page or website link (optional)',
                    hintText: 'https://',
                    errorText: _error?.field('website_urls.0'),
                  ),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _audience,
                  maxLines: 3,
                  decoration: InputDecoration(
                    labelText: 'Tell us about your audience (optional)',
                    errorText: _error?.field('audience_description'),
                  ),
                ),
                const SectionTitle('Affiliate terms'),
                Card(
                  child: ExpansionTile(
                    title: Text(terms.title),
                    subtitle: Text('Version ${terms.version}'),
                    childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                    children: [Text(terms.body)],
                  ),
                ),
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  value: _accept,
                  onChanged: (v) => setState(() => _accept = v ?? false),
                  title: const Text(
                    'I have read and accept the affiliate terms',
                  ),
                ),
                if (_error != null)
                  Text(
                    _error!.display,
                    style: TextStyle(color: theme.colorScheme.error),
                  ),
                const SizedBox(height: 12),
                BusyButton(
                  label: 'Send application',
                  onPressed: () => _submit(terms),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
