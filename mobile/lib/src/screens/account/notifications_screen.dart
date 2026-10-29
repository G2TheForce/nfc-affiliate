import 'package:flutter/material.dart';

import '../../api/format.dart';
import '../../api/models.dart';
import '../../ui/paged_list.dart';
import '../../ui/widgets.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  final _list = GlobalKey<PagedListState<AppNotification>>();
  final Set<String> _readHere = {};

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          TextButton(
            onPressed: () async {
              try {
                await apiOf(context).markAllNotificationsRead();
                await _list.currentState?.reload();
              } catch (e) {
                if (context.mounted) showSnack(context, errorText(e));
              }
            },
            child: const Text('Mark all read'),
          ),
        ],
      ),
      body: PagedList<AppNotification>(
        key: _list,
        load: (page) async =>
            (await apiOf(context).notifications(page: page)).$1,
        empty: const EmptyState(
          icon: Icons.notifications_none,
          title: 'No notifications',
          body: 'Order, commission and payout updates appear here.',
        ),
        itemBuilder: (context, n) {
          final unread = !n.read && !_readHere.contains(n.id);
          return Card(
            child: ListTile(
              leading: Icon(
                unread ? Icons.circle : Icons.circle_outlined,
                size: 12,
                color: Theme.of(context).colorScheme.primary,
              ),
              title: Text(
                n.title,
                style: TextStyle(
                  fontWeight: unread ? FontWeight.w700 : FontWeight.normal,
                ),
              ),
              subtitle: Text('${n.body}\n${dateTime(n.createdAt)}'),
              isThreeLine: true,
              onTap: unread
                  ? () async {
                      setState(() => _readHere.add(n.id));
                      try {
                        await apiOf(context).markNotificationRead(n.id);
                      } catch (_) {
                        // Stays unread on the server; shown read here until reload.
                      }
                    }
                  : null,
            ),
          );
        },
      ),
    );
  }
}
