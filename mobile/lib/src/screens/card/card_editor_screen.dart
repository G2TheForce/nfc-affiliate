import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/models.dart';
import '../../state/session.dart';
import '../../ui/theme.dart';
import '../../ui/widgets.dart';

/// Create or edit the tap profile. Pops `true` once saved.
class CardEditorScreen extends StatefulWidget {
  const CardEditorScreen({super.key, this.initial});
  final CardProfile? initial;

  static const socialLabels = <String, String>{
    'facebook': 'Facebook',
    'instagram': 'Instagram',
    'linkedin': 'LinkedIn',
    'tiktok': 'TikTok',
    'youtube': 'YouTube',
    'x': 'X (Twitter)',
  };

  @override
  State<CardEditorScreen> createState() => _CardEditorScreenState();
}

class _CardEditorScreenState extends State<CardEditorScreen> {
  late final CardProfile? _p = widget.initial;
  late final _user = context.read<Session>().user;

  late final _handle = TextEditingController(
    text: _p?.handle ?? _suggestHandle(_user?.name ?? ''),
  );
  late final _name = TextEditingController(
    text: _p?.displayName ?? _user?.name ?? '',
  );
  late final _headline = TextEditingController(text: _p?.headline ?? '');
  late final _company = TextEditingController(text: _p?.company ?? '');
  late final _bio = TextEditingController(text: _p?.bio ?? '');
  late final _phone = TextEditingController(
    text: _p == null ? _user?.phone ?? '' : _p.phone ?? '',
  );
  late final _whatsapp = TextEditingController(text: _p?.whatsapp ?? '');
  late final _email = TextEditingController(
    text: _p == null ? _user?.email ?? '' : _p.email ?? '',
  );
  late final _website = TextEditingController(text: _p?.website ?? '');
  late final _location = TextEditingController(text: _p?.location ?? '');
  late final Map<String, TextEditingController> _socials = {
    for (final n in CardProfile.socialNetworks)
      n: TextEditingController(text: _p?.socials[n] ?? ''),
  };
  late String _theme = _p?.theme ?? 'aurora';
  late bool _published = _p?.isPublished ?? true;
  ApiException? _error;

  List<TextEditingController> get _all => [
    _handle,
    _name,
    _headline,
    _company,
    _bio,
    _phone,
    _whatsapp,
    _email,
    _website,
    _location,
    ..._socials.values,
  ];

  @override
  void initState() {
    super.initState();
    // Keeps the link preview under the handle field up to date.
    _handle.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    for (final c in _all) {
      c.dispose();
    }
    super.dispose();
  }

  static String _suggestHandle(String name) => name
      .toLowerCase()
      .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
      .replaceAll(RegExp(r'^-+|-+$'), '')
      .replaceAll(RegExp(r'-{2,}'), '-');

  String? _v(TextEditingController c) =>
      c.text.trim().isEmpty ? null : c.text.trim();

  Future<void> _save() async {
    setState(() => _error = null);
    try {
      await apiOf(context).saveCardProfile({
        'handle': _handle.text.trim().toLowerCase(),
        'display_name': _name.text.trim(),
        'headline': _v(_headline),
        'company': _v(_company),
        'bio': _v(_bio),
        'phone': _v(_phone),
        'whatsapp': _v(_whatsapp),
        'email': _v(_email),
        'website': _v(_website),
        'location': _v(_location),
        'socials': {
          for (final e in _socials.entries)
            if (e.value.text.trim().isNotEmpty) e.key: e.value.text.trim(),
        },
        'theme': _theme,
        'is_published': _published,
      });
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e);
      if (mounted) showSnack(context, e.display);
    }
  }

  Widget _field(
    TextEditingController c,
    String label,
    String key, {
    String? hint,
    String? helper,
    TextInputType? type,
    int lines = 1,
  }) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: TextField(
      controller: c,
      keyboardType: type,
      maxLines: lines,
      decoration: InputDecoration(
        labelText: label,
        hintText: hint,
        helperText: helper,
        errorText: _error?.field(key),
        errorMaxLines: 3,
      ),
    ),
  );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_p == null ? 'Create tap profile' : 'Edit tap profile'),
        actions: [TextButton(onPressed: _save, child: const Text('Save'))],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _field(
            _handle,
            'Handle',
            'handle',
            helper:
                'Your link: /p/${_handle.text.isEmpty ? 'your-name' : _handle.text}. 3 to 30 lower-case letters, numbers or dashes.',
          ),
          _field(_name, 'Name on the card', 'display_name'),
          _field(_headline, 'Job title or tagline', 'headline'),
          _field(_company, 'Company', 'company'),
          _field(_bio, 'Short bio', 'bio', lines: 3),
          const SectionTitle('Contact'),
          _field(
            _phone,
            'Phone',
            'phone',
            type: TextInputType.phone,
            hint: '+977 98XXXXXXXX',
          ),
          _field(_whatsapp, 'WhatsApp', 'whatsapp', type: TextInputType.phone),
          _field(_email, 'Email', 'email', type: TextInputType.emailAddress),
          _field(
            _website,
            'Website',
            'website',
            type: TextInputType.url,
            hint: 'https://',
          ),
          _field(_location, 'Location', 'location', hint: 'Kathmandu'),
          const SectionTitle('Social links'),
          for (final e in CardEditorScreen.socialLabels.entries)
            _field(
              _socials[e.key]!,
              e.value,
              'socials.${e.key}',
              hint: '@username or full https link',
            ),
          const SectionTitle('Look'),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              for (final t in Brand.cardThemes.entries)
                ChoiceChip(
                  avatar: CircleAvatar(backgroundColor: t.value.$2[1]),
                  label: Text(t.value.$1),
                  selected: _theme == t.key,
                  onSelected: (_) => setState(() => _theme = t.key),
                ),
            ],
          ),
          const SizedBox(height: 12),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _published,
            onChanged: (v) => setState(() => _published = v),
            title: const Text('Published'),
            subtitle: const Text(
              'When off, nobody can open your profile page.',
            ),
          ),
          const SizedBox(height: 12),
          BusyButton(label: 'Save profile', onPressed: _save),
        ],
      ),
    );
  }
}
