import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../state/session.dart';
import '../../ui/widgets.dart';
import 'register_screen.dart';

/// Pushed as a route; pops with `true` once signed in.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  static Future<bool?> open(BuildContext context) =>
      Navigator.of(context)
          .push<bool>(MaterialPageRoute(builder: (_) => const LoginScreen()));

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _hide = true;
  ApiException? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _error = null);
    try {
      await context.read<Session>().login(_email.text, _password.text);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Sign in')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text(
              'Welcome back',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 4),
            const Text('Sign in to see your orders, earnings and tap card.'),
            const SizedBox(height: 24),
            TextFormField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              autofillHints: const [AutofillHints.email],
              textInputAction: TextInputAction.next,
              decoration: InputDecoration(
                labelText: 'Email',
                errorText: _error?.field('email'),
              ),
              validator: (v) => (v == null || !v.contains('@'))
                  ? 'Enter your email address'
                  : null,
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _password,
              obscureText: _hide,
              autofillHints: const [AutofillHints.password],
              onFieldSubmitted: (_) => _submit(),
              decoration: InputDecoration(
                labelText: 'Password',
                errorText: _error?.field('password'),
                suffixIcon: IconButton(
                  icon: Icon(
                    _hide
                        ? Icons.visibility_outlined
                        : Icons.visibility_off_outlined,
                  ),
                  onPressed: () => setState(() => _hide = !_hide),
                ),
              ),
              validator: (v) =>
                  (v == null || v.isEmpty) ? 'Enter your password' : null,
            ),
            if (_error != null && _error!.fieldErrors.isEmpty) ...[
              const SizedBox(height: 12),
              Text(
                _error!.message,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            const SizedBox(height: 20),
            BusyButton(label: 'Sign in', onPressed: _submit),
            const SizedBox(height: 8),
            TextButton(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => ForgotPasswordScreen(email: _email.text),
                ),
              ),
              child: const Text('Forgot your password?'),
            ),
            const Divider(height: 32),
            OutlinedButton(
              onPressed: () async {
                final ok = await Navigator.of(context).push<bool>(
                  MaterialPageRoute(builder: (_) => const RegisterScreen()),
                );
                if (ok == true && context.mounted) {
                  Navigator.of(context).pop(true);
                }
              },
              child: const Text('Create an account'),
            ),
          ],
        ),
      ),
    );
  }
}

class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key, this.email = ''});
  final String email;

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  late final _email = TextEditingController(text: widget.email);
  bool _sent = false;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() => _error = null);
    try {
      await apiOf(context).forgotPassword(_email.text.trim());
      setState(() => _sent = true);
    } catch (e) {
      setState(() => _error = errorText(e));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Reset password')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          if (_sent)
            const EmptyState(
              icon: Icons.mark_email_read_outlined,
              title: 'Check your email',
              body: 'If an account uses that address, we have sent a link to choose a new password. The link opens in your browser.',
            )
          else ...[
            const Text(
              'Enter your email and we will send you a link to choose a new password.',
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              decoration: InputDecoration(
                labelText: 'Email',
                errorText: _error,
              ),
            ),
            const SizedBox(height: 16),
            BusyButton(label: 'Send reset link', onPressed: _send),
          ],
        ],
      ),
    );
  }
}
