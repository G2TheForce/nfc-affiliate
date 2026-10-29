import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../api/store_api.dart';
import '../../state/cart.dart';
import '../../state/session.dart';
import '../../ui/widgets.dart';
import '../auth/login_screen.dart';
import '../auth/register_screen.dart' show normalisePhone;
import 'payment_screen.dart';

class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key});

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  final _form = GlobalKey<FormState>();
  late final _user = context.read<Session>().user;
  late final _name = TextEditingController(text: _user?.name ?? '');
  late final _email = TextEditingController(text: _user?.email ?? '');
  late final _phone = TextEditingController(text: _user?.phone ?? '');
  final _city = TextEditingController(text: 'Kathmandu');
  final _address = TextEditingController();
  final _referral = TextEditingController();

  /// One key per checkout attempt, kept while this screen is open, so a
  /// retry after a dropped connection cannot create a second order.
  final _idempotencyKey = newIdempotencyKey();
  ApiException? _error;

  @override
  void dispose() {
    for (final c in [_name, _email, _phone, _city, _address, _referral]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _placeOrder() async {
    if (!_form.currentState!.validate()) return;
    final cart = context.read<Cart>();
    final signedIn = context.read<Session>().signedIn;
    setState(() => _error = null);
    try {
      final (order, accessToken) = await apiOf(context).checkout({
        'items': cart.items,
        if (cart.couponCode != null) 'coupon_code': cart.couponCode,
        'customer_name': _name.text.trim(),
        if (!signedIn) 'customer_email': _email.text.trim(),
        'customer_phone': normalisePhone(_phone.text),
        'shipping_city': _city.text.trim(),
        'shipping_address': _address.text.trim(),
        if (_referral.text.trim().isNotEmpty)
          'referral_code': _referral.text.trim(),
      }, _idempotencyKey);
      cart.clear();
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(
          builder: (_) => PaymentScreen(order: order, accessToken: accessToken),
        ),
        (route) => route.isFirst,
      );
    } on ApiException catch (e) {
      setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final signedIn = context.select<Session, bool>((s) => s.signedIn);
    final otherErrors = _error?.fieldErrors.keys
        .where(
          (k) => !{
            'customer_name',
            'customer_email',
            'customer_phone',
            'shipping_city',
            'shipping_address',
            'referral_code',
          }.contains(k),
        )
        .toList();

    return Scaffold(
      appBar: AppBar(title: const Text('Delivery details')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (!signedIn)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.person_outline),
                  title: const Text('Have an account?'),
                  subtitle: const Text(
                    'Sign in to track this order in the app.',
                  ),
                  trailing: TextButton(
                    onPressed: () async {
                      final ok = await LoginScreen.open(context);
                      if (ok == true && context.mounted) {
                        final u = context.read<Session>().user!;
                        setState(() {
                          if (_name.text.isEmpty) _name.text = u.name;
                          if (_phone.text.isEmpty) _phone.text = u.phone ?? '';
                          _email.text = u.email;
                        });
                      }
                    },
                    child: const Text('Sign in'),
                  ),
                ),
              ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _name,
              textCapitalization: TextCapitalization.words,
              decoration: InputDecoration(
                labelText: 'Full name',
                errorText: _error?.field('customer_name'),
              ),
              validator: (v) => (v == null || v.trim().length < 2)
                  ? 'Enter the name for delivery'
                  : null,
            ),
            const SizedBox(height: 12),
            if (!signedIn) ...[
              TextFormField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                decoration: InputDecoration(
                  labelText: 'Email',
                  helperText: 'We send the order confirmation here',
                  errorText: _error?.field('customer_email'),
                ),
                validator: (v) => (v == null || !v.contains('@'))
                    ? 'Enter a valid email'
                    : null,
              ),
              const SizedBox(height: 12),
            ],
            TextFormField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: InputDecoration(
                labelText: 'Mobile number',
                hintText: '98XXXXXXXX',
                errorText: _error?.field('customer_phone'),
              ),
              validator: (v) => (normalisePhone(v ?? '') == null)
                  ? 'Enter a mobile number for the courier'
                  : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _city,
              decoration: InputDecoration(
                labelText: 'City',
                errorText: _error?.field('shipping_city'),
              ),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'Enter the city' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _address,
              maxLines: 2,
              decoration: InputDecoration(
                labelText: 'Address',
                hintText: 'Tole, ward, landmark',
                errorText: _error?.field('shipping_address'),
              ),
              validator: (v) => (v == null || v.trim().isEmpty)
                  ? 'Enter the delivery address'
                  : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _referral,
              textCapitalization: TextCapitalization.characters,
              decoration: InputDecoration(
                labelText: 'Referral code (optional)',
                helperText: 'If someone recommended us, enter their code',
                errorText: _error?.field('referral_code'),
              ),
            ),
            if (_error != null &&
                (otherErrors!.isNotEmpty || _error!.fieldErrors.isEmpty)) ...[
              const SizedBox(height: 12),
              Text(
                otherErrors.isNotEmpty
                    ? _error!.field(otherErrors.first)!
                    : _error!.message,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            const SizedBox(height: 20),
            BusyButton(label: 'Place order', onPressed: _placeOrder),
            const SizedBox(height: 8),
            Text(
              'You choose how to pay on the next screen.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
          ],
        ),
      ),
    );
  }
}
