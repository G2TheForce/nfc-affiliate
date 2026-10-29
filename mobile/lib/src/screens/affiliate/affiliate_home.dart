import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/format.dart';
import '../../api/models.dart';
import '../../state/session.dart';
import '../../ui/widgets.dart';
import 'earnings_tab.dart';
import 'join_screen.dart';
import 'links_tab.dart';
import 'materials_tab.dart';
import 'overview_tab.dart';
import 'payouts_tab.dart';

/// The affiliate tab. Shows the portal to approved members and, to everyone
/// else, the step they are at: confirm email, apply, wait, or accept terms.
class AffiliateHome extends StatefulWidget {
  const AffiliateHome({super.key});

  @override
  State<AffiliateHome> createState() => _AffiliateHomeState();
}

class _AffiliateHomeState extends State<AffiliateHome> {
  Future<AffiliateProfile?> _load() async {
    final session = context.read<Session>();
    final profile = await session.api.affiliateProfile();
    // Approval grants the portal permission, so refresh what the app knows.
    if (profile != null &&
        profile.isApproved &&
        !(session.user?.canUseAffiliatePortal ?? false)) {
      await session.refreshUser();
    }
    return profile;
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<Session>().user;
    if (user == null) return const SizedBox.shrink();
    if (!user.emailVerified) {
      return Scaffold(
        appBar: AppBar(title: const Text('Affiliate')),
        body: const VerifyEmailNotice(),
      );
    }

    return LoadView<AffiliateProfile?>(
      load: _load,
      builder: (context, profile, reload) {
        if (profile != null &&
            profile.isApproved &&
            user.canUseAffiliatePortal) {
          if (profile.needsTermsAcceptance) {
            return Scaffold(
              appBar: AppBar(title: const Text('Affiliate')),
              body: _AcceptTerms(onDone: reload),
            );
          }
          return AffiliatePortal(profile: profile);
        }
        return Scaffold(
          appBar: AppBar(title: const Text('Affiliate')),
          body: PageList(children: [_status(context, profile, reload)]),
        );
      },
    );
  }

  Widget _status(
    BuildContext context,
    AffiliateProfile? profile,
    Future<void> Function() reload,
  ) {
    Future<void> apply() async {
      final done = await Navigator.of(context)
          .push<bool>(MaterialPageRoute(builder: (_) => const JoinScreen()));
      if (done == true) await reload();
    }

    if (profile == null) {
      return EmptyState(
        icon: Icons.handshake_outlined,
        title: 'Earn by recommending our cards',
        body: 'Join the affiliate programme to get your own referral link and earn commission on every order it brings in.',
        action: FilledButton(
          onPressed: apply,
          child: const Text('Apply to join'),
        ),
      );
    }
    return switch (profile.status) {
      'pending' => const EmptyState(
        icon: Icons.hourglass_top_outlined,
        title: 'Your application is being reviewed',
        body: 'We will email you when it is approved. This usually takes one or two working days.',
      ),
      'rejected' => EmptyState(
        icon: Icons.info_outline,
        title: 'Your application was not approved',
        body: [
          if (profile.latestReason != null) profile.latestReason!,
          if (profile.canReapplyAt != null)
            'You can apply again from ${date(profile.canReapplyAt)}.',
        ].join('\n\n'),
        action:
            (profile.canReapplyAt == null ||
                profile.canReapplyAt!.isBefore(DateTime.now()))
            ? FilledButton(onPressed: apply, child: const Text('Apply again'))
            : null,
      ),
      _ => EmptyState(
        icon: Icons.block,
        title: 'Membership ${profile.statusLabel.toLowerCase()}',
        body:
            profile.latestReason ??
            'Please contact support if you have questions.',
      ),
    };
  }
}

class _AcceptTerms extends StatelessWidget {
  const _AcceptTerms({required this.onDone});
  final Future<void> Function() onDone;

  @override
  Widget build(BuildContext context) {
    return LoadView<Terms?>(
      load: () => apiOf(context).currentTerms(),
      builder: (context, terms, _) => PageList(
        children: [
          Text(
            'The affiliate terms have changed',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 8),
          const Text(
            'Please read and accept the new terms to keep using your affiliate account.',
          ),
          const SizedBox(height: 16),
          if (terms != null) ...[
            Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      terms.title,
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 8),
                    Text(terms.body),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),
            BusyButton(
              label: 'I accept these terms',
              onPressed: () async {
                try {
                  await apiOf(context).acceptTerms(terms.version);
                  await onDone();
                } catch (e) {
                  if (context.mounted) showSnack(context, errorText(e));
                }
              },
            ),
          ],
        ],
      ),
    );
  }
}

class VerifyEmailNotice extends StatelessWidget {
  const VerifyEmailNotice({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    return PageList(
      children: [
        EmptyState(
          icon: Icons.mark_email_unread_outlined,
          title: 'Confirm your email first',
          body:
              'We sent a link to ${session.user?.email}. Open it, then come back and tap "I have confirmed".',
          action: Column(
            children: [
              BusyButton(
                label: 'I have confirmed',
                onPressed: () async {
                  try {
                    await session.refreshUser();
                    if (context.mounted &&
                        !(session.user?.emailVerified ?? false)) {
                      showSnack(
                        context,
                        'Not confirmed yet. Check your inbox and spam folder.',
                      );
                    }
                  } catch (e) {
                    if (context.mounted) showSnack(context, errorText(e));
                  }
                },
              ),
              const SizedBox(height: 8),
              BusyButton(
                label: 'Send the link again',
                outlined: true,
                onPressed: () async {
                  try {
                    await session.api.resendVerification();
                    if (context.mounted) {
                      showSnack(context, 'A new link is on its way.');
                    }
                  } on ApiException catch (e) {
                    if (context.mounted) showSnack(context, e.display);
                  }
                },
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class AffiliatePortal extends StatelessWidget {
  const AffiliatePortal({super.key, required this.profile});
  final AffiliateProfile profile;

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 5,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Affiliate'),
          bottom: const TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            tabs: [
              Tab(text: 'Overview'),
              Tab(text: 'Links'),
              Tab(text: 'Earnings'),
              Tab(text: 'Payouts'),
              Tab(text: 'Materials'),
            ],
          ),
        ),
        body: TabBarView(
          children: [
            OverviewTab(profile: profile),
            const LinksTab(),
            const EarningsTab(),
            const PayoutsTab(),
            const MaterialsTab(),
          ],
        ),
      ),
    );
  }
}
