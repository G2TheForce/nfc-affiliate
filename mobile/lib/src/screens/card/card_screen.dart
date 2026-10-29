import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/models.dart';
import '../../config.dart';
import '../../state/session.dart';
import '../../ui/theme.dart';
import '../../ui/widgets.dart';
import '../affiliate/affiliate_home.dart' show VerifyEmailNotice;
import 'card_editor_screen.dart';

/// The person's tap profile: the page their NFC card opens.
class CardScreen extends StatelessWidget {
  const CardScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final verified = context.select<Session, bool>(
      (s) => s.user?.emailVerified ?? false,
    );
    return Scaffold(
      appBar: AppBar(title: const Text('Tap card')),
      body: !verified
          ? const VerifyEmailNotice()
          : LoadView<(CardProfile?, CardStats?)>(
              load: () async {
                final api = apiOf(context);
                final profile = await api.cardProfile();
                return (
                  profile,
                  profile == null ? null : await api.cardStats(),
                );
              },
              builder: (context, data, reload) {
                final (profile, stats) = data;
                Future<void> edit() async {
                  final saved = await Navigator.of(context).push<bool>(
                    MaterialPageRoute(
                      builder: (_) => CardEditorScreen(initial: profile),
                    ),
                  );
                  if (saved == true) await reload();
                }

                if (profile == null) {
                  return PageList(
                    children: [
                      EmptyState(
                        icon: Icons.contactless_outlined,
                        title: 'Set up your tap profile',
                        body: 'This is the page people see when they tap your NFC card or scan its QR code: your name, phone, socials and a button to save your contact.',
                        action: FilledButton(
                          onPressed: edit,
                          child: const Text('Create my profile'),
                        ),
                      ),
                    ],
                  );
                }
                final url = AppConfig.resolveUrl(profile.publicUrl);
                return PageList(
                  children: [
                    CardPreview(profile: profile),
                    const SizedBox(height: 12),
                    if (!profile.isPublished)
                      const Padding(
                        padding: EdgeInsets.only(bottom: 8),
                        child: Text(
                          'Your profile is hidden. Turn on "Published" so taps open it.',
                        ),
                      ),
                    LinkBox(
                      title: 'Your tap link',
                      url: url,
                      shareMessage: 'My contact card:',
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'This is the link your NFC card opens. Changing your handle changes the link, so keep it once your card is set up.',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: edit,
                      icon: const Icon(Icons.edit_outlined),
                      label: const Text('Edit profile'),
                    ),
                    if (stats != null) ...[
                      const SectionTitle('Last 30 days'),
                      StatGrid(
                        tiles: [
                          StatTile(
                            label: 'Taps and visits',
                            value:
                                '${stats.daily.fold<int>(0, (a, d) => a + d.taps)}',
                            hint: '${stats.tapsTotal} all time',
                          ),
                          StatTile(
                            label: 'Contact saves',
                            value:
                                '${stats.daily.fold<int>(0, (a, d) => a + d.saves)}',
                            hint: '${stats.savesTotal} all time',
                          ),
                        ],
                      ),
                    ],
                  ],
                );
              },
            ),
    );
  }
}

class CardPreview extends StatelessWidget {
  const CardPreview({super.key, required this.profile});
  final CardProfile profile;

  @override
  Widget build(BuildContext context) {
    final palette =
        (Brand.cardThemes[profile.theme] ?? Brand.cardThemes['aurora']!).$2;
    final initials = profile.displayName
        .trim()
        .split(RegExp(r'\s+'))
        .where((w) => w.isNotEmpty)
        .take(2)
        .map((w) => w[0].toUpperCase())
        .join();
    final sub = [
      profile.headline,
      profile.company,
    ].whereType<String>().where((s) => s.isNotEmpty).join(' · ');
    return AspectRatio(
      aspectRatio: 1.6,
      child: Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(20),
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [palette[0], palette[1], palette[0]],
            stops: const [0, 0.65, 1],
          ),
          boxShadow: [
            BoxShadow(
              color: palette[1].withValues(alpha: 0.35),
              blurRadius: 24,
              offset: const Offset(0, 10),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 22,
                  backgroundColor: palette[2],
                  child: Text(
                    initials,
                    style: TextStyle(
                      color: palette[0],
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                const Spacer(),
                Icon(Icons.contactless, color: palette[2], size: 30),
              ],
            ),
            const Spacer(),
            Text(
              profile.displayName,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 22,
                fontWeight: FontWeight.w700,
              ),
            ),
            if (sub.isNotEmpty)
              Text(
                sub,
                style: TextStyle(color: Colors.white.withValues(alpha: 0.8)),
              ),
            const SizedBox(height: 6),
            Text(
              '/p/${profile.handle}',
              style: TextStyle(color: palette[2], fontWeight: FontWeight.w600),
            ),
          ],
        ),
      ),
    );
  }
}
