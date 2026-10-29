import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'config.dart';
import 'screens/account/account_screen.dart';
import 'screens/affiliate/affiliate_home.dart';
import 'screens/card/card_screen.dart';
import 'screens/shop/shop_screen.dart';
import 'state/session.dart';
import 'ui/theme.dart';

class NfcApp extends StatelessWidget {
  const NfcApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: buildTheme(Brightness.light),
      darkTheme: buildTheme(Brightness.dark),
      home: const _Root(),
    );
  }
}

class _Root extends StatelessWidget {
  const _Root();

  @override
  Widget build(BuildContext context) {
    final restoring = context.select<Session, bool>((s) => s.restoring);
    if (restoring) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    return const HomeShell();
  }
}

/// Bottom tabs. Guests see Shop and Account; signed-in people also get the
/// affiliate area and their tap card.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;
  bool? _wasSignedIn;

  @override
  Widget build(BuildContext context) {
    // Rebuild the tabs when the signed-in person changes, so no screen keeps
    // another account's data.
    final userId = context.select<Session, String?>((s) => s.user?.id);
    final signedIn = userId != null;

    final tabs = <(NavigationDestination, Widget)>[
      (
        const NavigationDestination(
          icon: Icon(Icons.storefront_outlined),
          selectedIcon: Icon(Icons.storefront),
          label: 'Shop',
        ),
        const ShopScreen(),
      ),
      if (signedIn)
        (
          const NavigationDestination(
            icon: Icon(Icons.insights_outlined),
            selectedIcon: Icon(Icons.insights),
            label: 'Affiliate',
          ),
          const AffiliateHome(),
        ),
      if (signedIn)
        (
          const NavigationDestination(
            icon: Icon(Icons.contactless_outlined),
            selectedIcon: Icon(Icons.contactless),
            label: 'Tap card',
          ),
          const CardScreen(),
        ),
      (
        const NavigationDestination(
          icon: Icon(Icons.person_outline),
          selectedIcon: Icon(Icons.person),
          label: 'Account',
        ),
        const AccountScreen(),
      ),
    ];
    // After signing in or out, land on Account rather than whichever tab
    // now happens to sit at the old position.
    if (_wasSignedIn != null && _wasSignedIn != signedIn) {
      _index = tabs.length - 1;
    }
    _wasSignedIn = signedIn;
    final index = _index.clamp(0, tabs.length - 1);

    return Scaffold(
      body: KeyedSubtree(
        key: ValueKey(userId),
        child: IndexedStack(
          index: index,
          children: [for (final t in tabs) t.$2],
        ),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [for (final t in tabs) t.$1],
      ),
    );
  }
}
