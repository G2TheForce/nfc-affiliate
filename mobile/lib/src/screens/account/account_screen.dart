import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../config.dart';
import '../../state/session.dart';
import '../../ui/widgets.dart';
import '../auth/login_screen.dart';
import '../auth/register_screen.dart';
import '../shop/orders_screen.dart';
import 'notifications_screen.dart';
import 'profile_screen.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Account')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (user == null) ...[
            const EmptyState(
              icon: Icons.person_outline,
              title: 'Sign in to your account',
              body: 'Track your orders, run your affiliate account and edit your tap card.',
            ),
            FilledButton(
              onPressed: () => LoginScreen.open(context),
              child: const Text('Sign in'),
            ),
            const SizedBox(height: 10),
            OutlinedButton(
              onPressed: () => Navigator.of(
                context,
              ).push(MaterialPageRoute(builder: (_) => const RegisterScreen())),
              child: const Text('Create an account'),
            ),
          ] else ...[
            Card(
              child: ListTile(
                leading: CircleAvatar(child: Text(initialOf(user.name))),
                title: Text(user.name),
                subtitle: Text(user.email),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const ProfileScreen()),
                ),
              ),
            ),
            if (!user.emailVerified) ...[
              const SizedBox(height: 10),
              Card(
                color: theme.colorScheme.tertiaryContainer,
                child: ListTile(
                  leading: const Icon(Icons.mark_email_unread_outlined),
                  title: const Text('Confirm your email'),
                  subtitle: const Text(
                    'Open the link we emailed you to unlock the affiliate area and your tap card.',
                  ),
                  onTap: () async {
                    try {
                      await session.api.resendVerification();
                      if (context.mounted) {
                        showSnack(context, 'A new link is on its way.');
                      }
                    } catch (e) {
                      if (context.mounted) showSnack(context, errorText(e));
                    }
                  },
                ),
              ),
            ],
            const SizedBox(height: 10),
            Card(
              child: Column(
                children: [
                  ListTile(
                    leading: const Icon(Icons.receipt_long_outlined),
                    title: const Text('My orders'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => const OrdersScreen()),
                    ),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.notifications_outlined),
                    title: const Text('Notifications'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => const NotificationsScreen(),
                      ),
                    ),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.lock_outline),
                    title: const Text('Change password'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => const ChangePasswordScreen(),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              icon: const Icon(Icons.logout),
              label: const Text('Sign out'),
              onPressed: () async {
                await session.logout();
                if (context.mounted) showSnack(context, 'Signed out');
              },
            ),
          ],
          const SizedBox(height: 24),
          Text(
            '${AppConfig.appName} · ${AppConfig.apiBaseUrl}',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

/// First letter or digit of a name, for an avatar ("[DEV] Asha" -> "D").
String initialOf(String name) {
  final match = RegExp(r'[A-Za-z0-9]').firstMatch(name);
  return match == null ? '?' : match.group(0)!.toUpperCase();
}
