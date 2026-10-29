import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../state/session.dart';
import '../../ui/widgets.dart';
import '../auth/register_screen.dart' show normalisePhone;

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  late final _session = context.read<Session>();
  late final _name = TextEditingController(text: _session.user?.name ?? '');
  late final _phone = TextEditingController(text: _session.user?.phone ?? '');
  ApiException? _error;

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _error = null);
    try {
      final user = await _session.api.updateMe(
        name: _name.text.trim(),
        phone: normalisePhone(_phone.text),
      );
      _session.replaceUser(user);
      if (mounted) {
        showSnack(context, 'Saved');
        Navigator.of(context).pop();
      }
    } on ApiException catch (e) {
      setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Your details')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TextField(
            controller: _name,
            decoration: InputDecoration(
              labelText: 'Name',
              errorText: _error?.field('name'),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _phone,
            keyboardType: TextInputType.phone,
            decoration: InputDecoration(
              labelText: 'Mobile number',
              hintText: '98XXXXXXXX',
              errorText: _error?.field('phone'),
            ),
          ),
          const SizedBox(height: 12),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('Email'),
            subtitle: Text(
              '${_session.user?.email ?? ''}\nContact support to change your email address.',
            ),
          ),
          if (_error != null && _error!.fieldErrors.isEmpty) ...[
            const SizedBox(height: 8),
            Text(
              _error!.message,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
          const SizedBox(height: 20),
          BusyButton(label: 'Save', onPressed: _save),
        ],
      ),
    );
  }
}

class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _current = TextEditingController();
  final _next = TextEditingController();
  ApiException? _error;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _error = null);
    try {
      await apiOf(context).changePassword(_current.text, _next.text);
      if (mounted) {
        showSnack(
          context,
          'Password changed. Other devices have been signed out.',
        );
        Navigator.of(context).pop();
      }
    } on ApiException catch (e) {
      setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Change password')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TextField(
            controller: _current,
            obscureText: true,
            decoration: InputDecoration(
              labelText: 'Current password',
              errorText: _error?.field('current_password'),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _next,
            obscureText: true,
            decoration: InputDecoration(
              labelText: 'New password',
              helperText: '12 or more characters with upper and lower case letters and a number',
              errorText: _error?.field('password'),
              errorMaxLines: 3,
            ),
          ),
          if (_error != null && _error!.fieldErrors.isEmpty) ...[
            const SizedBox(height: 8),
            Text(
              _error!.message,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
          const SizedBox(height: 20),
          BusyButton(label: 'Change password', onPressed: _save),
        ],
      ),
    );
  }
}
