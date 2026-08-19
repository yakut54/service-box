import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/auth_state.dart';
import '../account_screen.dart';
import '../phone_login_screen.dart';

/// Иконка входа/профиля в шапке — если байер не вошёл, открывает вход
/// по телефону; если уже вошёл, открывает профиль (см. AccountScreen).
class AccountButton extends StatelessWidget {
  const AccountButton({super.key});

  @override
  Widget build(BuildContext context) {
    final isLoggedIn = context.watch<AuthState>().isLoggedIn;

    return IconButton(
      icon: Icon(
        isLoggedIn ? Icons.person_rounded : Icons.person_outline_rounded,
      ),
      onPressed: () => Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) =>
              isLoggedIn ? const AccountScreen() : const PhoneLoginScreen(),
        ),
      ),
    );
  }
}
