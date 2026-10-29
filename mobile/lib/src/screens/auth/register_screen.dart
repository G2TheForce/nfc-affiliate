import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../state/session.dart';
import '../../ui/widgets.dart';

/// Pops with `true` once the account exists and is signed in.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  bool _privacy = false;
  ApiException? _error;

  @override
  void dispose() {
    for (final c in [_name, _email, _phone, _password]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    if (!_privacy) {
      setState(
        () => _error = ApiException(422, 'Please accept the privacy notice.'),
      );
      return;
    }
    setState(() => _error = null);
    try {
      await context.read<Session>().register(
        name: _name.text,
        email: _email.text,
        phone: normalisePhone(_phone.text),
        password: _password.text,
      );
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Create an account')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            TextFormField(
              controller: _name,
              textCapitalization: TextCapitalization.words,
              decoration: InputDecoration(
                labelText: 'Full name',
                errorText: _error?.field('name'),
              ),
              validator: (v) =>
                  (v == null || v.trim().length < 2) ? 'Enter your name' : null,
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              decoration: InputDecoration(
                labelText: 'Email',
                errorText: _error?.field('email'),
              ),
              validator: (v) => (v == null || !v.contains('@'))
                  ? 'Enter a valid email address'
                  : null,
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              decoration: InputDecoration(
                labelText: 'Mobile number (optional)',
                hintText: '98XXXXXXXX',
                errorText: _error?.field('phone'),
              ),
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _password,
              obscureText: true,
              decoration: InputDecoration(
                labelText: 'Password',
                helperText: '12 or more characters with upper and lower case letters and a number',
                errorText: _error?.field('password'),
              ),
              validator: (v) => (v == null || v.length < 12)
                  ? 'Use at least 12 characters'
                  : null,
            ),
            const SizedBox(height: 8),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              value: _privacy,
              onChanged: (v) => setState(() => _privacy = v ?? false),
              controlAffinity: ListTileControlAffinity.leading,
              title: const Text(
                'I have read the privacy notice and agree to my details being used to run my account.',
              ),
            ),
            if (_error != null &&
                (_error!.fieldErrors.isEmpty ||
                    _error!.field('accept_privacy_notice') != null))
              Text(
                _error!.display,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            const SizedBox(height: 16),
            BusyButton(label: 'Create account', onPressed: _submit),
            const SizedBox(height: 12),
            const Text(
              'We will email you a link to confirm your address. You need to confirm it before using the affiliate area or your tap card.',
            ),
          ],
        ),
      ),
    );
  }
}

/// Accepts the local way of typing a Nepali mobile number (98XXXXXXXX) and
/// returns E.164 (+97798XXXXXXXX). The server also accepts this, but sending
/// E.164 keeps validation messages consistent.
String? normalisePhone(String input) {
  var v = input.replaceAll(RegExp(r'[\s\-().]'), '');
  if (v.isEmpty) return null;
  if (v.startsWith('00')) v = '+${v.substring(2)}';
  if (RegExp(r'^9[678]\d{8}$').hasMatch(v)) v = '+977$v';
  return v;
}
