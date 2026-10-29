import 'package:flutter/material.dart';

import '../../api/format.dart';
import '../../api/models.dart';
import '../../ui/paged_list.dart';
import '../../ui/widgets.dart';
import 'payment_screen.dart';

/// Orders placed while signed in. Guest orders are not listed.
class OrdersScreen extends StatelessWidget {
  const OrdersScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('My orders')),
      body: PagedList<Order>(
        load: (page) => apiOf(context).myOrders(page: page),
        empty: const EmptyState(
          icon: Icons.receipt_long_outlined,
          title: 'No orders yet',
          body: 'Orders you place while signed in show up here.',
        ),
        itemBuilder: (context, order) => Card(
          child: ListTile(
            title: Text(order.number),
            subtitle: Text(
              '${date(order.placedAt)}  ·  ${money(order.totalMinor, order.currency)}',
            ),
            trailing: StatusChip(order.statusLabel, status: order.status),
            onTap: () => Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => OrderDetailScreen(orderId: order.id),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class OrderDetailScreen extends StatelessWidget {
  const OrderDetailScreen({super.key, required this.orderId});
  final String orderId;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Order')),
      body: LoadView<Order>(
        load: () => apiOf(context).myOrder(orderId),
        builder: (context, order, reload) {
          final theme = Theme.of(context);
          return PageList(
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      order.number,
                      style: theme.textTheme.titleLarge,
                    ),
                  ),
                  StatusChip(order.statusLabel, status: order.status),
                ],
              ),
              const SizedBox(height: 4),
              Text('Placed ${dateTime(order.placedAt)}'),
              if (order.awaitingPayment) ...[
                const SizedBox(height: 16),
                FilledButton(
                  onPressed: () async {
                    await Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => PaymentScreen(order: order),
                      ),
                    );
                    await reload();
                  },
                  child: const Text('Pay now'),
                ),
              ],
              const SectionTitle('Delivery'),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        order.fulfilmentLabel,
                        style: theme.textTheme.titleSmall,
                      ),
                      if (order.carrier != null)
                        Text(
                          'Courier: ${order.carrier}${order.trackingNumber != null ? ' (${order.trackingNumber})' : ''}',
                        ),
                      if (order.shippedAt != null)
                        Text('Shipped ${date(order.shippedAt)}'),
                      if (order.deliveredAt != null)
                        Text('Delivered ${date(order.deliveredAt)}'),
                      const SizedBox(height: 6),
                      Text(
                        '${order.customerName}\n${order.shippingAddress}, ${order.shippingCity}',
                      ),
                    ],
                  ),
                ),
              ),
              const SectionTitle('Items'),
              Card(
                child: Column(
                  children: [
                    for (final item in order.items)
                      ListTile(
                        title: Text(item.name),
                        subtitle: Text(
                          '${item.quantity} × ${money(item.unitPriceMinor, order.currency)}',
                        ),
                        trailing: Text(
                          money(item.lineTotalMinor, order.currency),
                        ),
                      ),
                    const Divider(height: 1),
                    ListTile(
                      title: const Text(
                        'Total',
                        style: TextStyle(fontWeight: FontWeight.w700),
                      ),
                      subtitle: order.discountMinor > 0
                          ? Text(
                              'Includes ${money(order.discountMinor, order.currency)} discount',
                            )
                          : null,
                      trailing: Text(
                        money(order.totalMinor, order.currency),
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
