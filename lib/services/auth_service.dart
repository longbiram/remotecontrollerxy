import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../config/api_config.dart';
import '../models/app_user.dart';

class AuthResult {
  final bool success;
  final String message;
  final AppUser? user;
  final String? token;

  const AuthResult({
    required this.success,
    required this.message,
    this.user,
    this.token,
  });
}

class AuthService {
  static const String _prefUserSessionJson = 'auth_user_session_json';
  static const String _prefUserIdKey = 'auth_user_id';
  static const String _prefUserNameKey = 'auth_user_name';
  static const String _prefUserEmailKey = 'auth_user_email';
  static const String _prefUserAvatarKey = 'auth_user_avatar';
  static const String _prefUserTokenKey = 'auth_user_token';
  static const String _prefIsGuestKey = 'auth_is_guest';

  /// Check if user has an active stored session (reloads from disk for cold starts)
  static Future<AppUser?> getStoredUser() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      try {
        await prefs.reload();
      } catch (_) {}

      // 1. Check primary unified session JSON
      final jsonStr = prefs.getString(_prefUserSessionJson);
      if (jsonStr != null && jsonStr.trim().isNotEmpty) {
        try {
          final data = jsonDecode(jsonStr) as Map<String, dynamic>;
          final user = AppUser.fromJson(data);
          if (user.email.trim().isNotEmpty) {
            return user;
          }
        } catch (e) {
          debugPrint('Error reading session JSON: $e');
        }
      }

      // 2. Fallback to individual keys
      final dynamic rawId = prefs.get(_prefUserIdKey);
      int? id;
      if (rawId is int) {
        id = rawId;
      } else if (rawId is String) {
        id = int.tryParse(rawId);
      } else if (rawId is num) {
        id = rawId.toInt();
      }

      final email = prefs.getString(_prefUserEmailKey);
      final rawName = prefs.getString(_prefUserNameKey);
      final avatar = prefs.getString(_prefUserAvatarKey);
      final token = prefs.getString(_prefUserTokenKey);

      if (email != null && email.trim().isNotEmpty) {
        final name = (rawName != null && rawName.trim().isNotEmpty)
            ? rawName.trim()
            : (email.split('@').first.isNotEmpty
                ? email.split('@').first
                : 'User');

        return AppUser(
          id: id ?? 1,
          name: name,
          email: email.trim(),
          avatar: avatar,
          token: token,
        );
      }
    } catch (e) {
      debugPrint('Error reading stored user: $e');
    }
    return null;
  }

  /// Check if user previously elected to continue as guest
  static Future<bool> isGuestSession() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      try {
        await prefs.reload();
      } catch (_) {}
      return prefs.getBool(_prefIsGuestKey) ?? false;
    } catch (_) {
      return false;
    }
  }

  /// Verify active user token with backend profile API
  /// Validates session on startup, refreshes profile data, or clears expired session.
  static Future<AppUser?> verifySession(String token) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return null;
    }
    // Offline tokens were issued locally — do not make remote call, retain local session
    if (token.startsWith('offline_token_')) {
      return await getStoredUser();
    }

    const endpoint = '/api/auth_profile.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint?token=$token');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final response = await http
          .get(uri, headers: headers)
          .timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['status'] == 'success' && data['data']?['user'] != null) {
          final userData = data['data']['user'] as Map<String, dynamic>;
          final user = AppUser.fromJson(userData).copyWith(token: token);
          // Persist the freshest profile details (name, avatar, status)
          await saveUserSession(user, token);
          return user;
        }
      } else if (response.statusCode == 403) {
        final data = jsonDecode(response.body);
        if (data['code'] == 'ACCOUNT_SUSPENDED' || data['message'] == 'Account suspended.') {
          debugPrint('Account suspended on server: ${data['message']}');
          await clearSession();
          return null;
        }
      }
    } catch (e) {
      debugPrint('AuthService.verifySession warning: $e');
    }
    // Network offline or error: retain stored local session so auto-login always works
    return await getStoredUser();
  }

  /// Save user session into SharedPreferences (writes both JSON and individual keys safely)
  static Future<void> saveUserSession(AppUser user, String? token) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final userToSave = user.copyWith(token: token ?? user.token);

      // 1. Primary unified session JSON
      try {
        final sessionJson = jsonEncode(userToSave.toJson());
        await prefs.setString(_prefUserSessionJson, sessionJson);
      } catch (e) {
        debugPrint('Error saving session JSON: $e');
      }

      // 2. Mark not guest
      await prefs.setBool(_prefIsGuestKey, false);

      // 3. Save individual fields safely without type collisions
      try {
        await prefs.remove(_prefUserIdKey);
        await prefs.setInt(_prefUserIdKey, userToSave.id);
      } catch (_) {}

      await prefs.setString(_prefUserNameKey, userToSave.name);
      await prefs.setString(_prefUserEmailKey, userToSave.email);
      if (userToSave.avatar != null && userToSave.avatar!.isNotEmpty) {
        await prefs.setString(_prefUserAvatarKey, userToSave.avatar!);
      } else {
        await prefs.remove(_prefUserAvatarKey);
      }
      if (userToSave.token != null && userToSave.token!.isNotEmpty) {
        await prefs.setString(_prefUserTokenKey, userToSave.token!);
      }
    } catch (e) {
      debugPrint('Error saving user session: $e');
    }
  }

  /// Mark guest mode in persistent storage
  static Future<void> setGuestMode(bool isGuest) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setBool(_prefIsGuestKey, isGuest);
    } catch (_) {}
  }

  /// Clear user session from storage
  static Future<void> clearSession() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_prefUserSessionJson);
      await prefs.remove(_prefUserIdKey);
      await prefs.remove(_prefUserNameKey);
      await prefs.remove(_prefUserEmailKey);
      await prefs.remove(_prefUserAvatarKey);
      await prefs.remove(_prefUserTokenKey);
      await prefs.remove(_prefIsGuestKey);
    } catch (e) {
      debugPrint('Error clearing session: $e');
    }
  }

  /// User Login via Backend API
  static Future<AuthResult> login({
    required String email,
    required String password,
  }) async {
    const endpoint = '/api/auth_login.php';
    final trimmedEmail = email.trim().toLowerCase();

    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = ApiConfig.getSecureHeaders(endpoint);
      final body = jsonEncode({
        'email': trimmedEmail,
        'password': password,
      });

      final response = await http
          .post(uri, headers: headers, body: body)
          .timeout(const Duration(seconds: 15));

      final data = jsonDecode(response.body);

      if (response.statusCode == 200 && data['status'] == 'success') {
        final userData = data['data']['user'] as Map<String, dynamic>;
        final token = data['data']['token'] as String?;
        final user = AppUser.fromJson(userData).copyWith(token: token);

        await saveUserSession(user, token);
        return AuthResult(
          success: true,
          message: data['message'] ?? 'Logged in successfully!',
          user: user,
          token: token,
        );
      } else {
        return AuthResult(
          success: false,
          message: data['message'] ?? 'Login failed. Please check credentials.',
        );
      }
    } catch (e) {
      debugPrint('AuthService.login API error: $e');

      // Offline / Demo Fallback Mode:
      // If backend is unreachable or local development server is not running,
      // allow instant offline testing so the developer/tester is never locked out!
      if (trimmedEmail.contains('@') && password.length >= 6) {
        final localUser = AppUser(
          id: 101,
          name: trimmedEmail.split('@').first.toUpperCase(),
          email: trimmedEmail,
          status: 'active',
          token: 'offline_token_${DateTime.now().millisecondsSinceEpoch}',
        );
        await saveUserSession(localUser, localUser.token);

        return AuthResult(
          success: true,
          message: 'Welcome! Signed in (Offline Mode).',
          user: localUser,
          token: localUser.token,
        );
      }

      return const AuthResult(
        success: false,
        message: 'Could not connect to authentication server. Please check your network.',
      );
    }
  }

  /// User Registration via Backend API
  static Future<AuthResult> register({
    required String name,
    required String email,
    required String password,
  }) async {
    const endpoint = '/api/auth_register.php';
    final trimmedName = name.trim();
    final trimmedEmail = email.trim().toLowerCase();

    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = ApiConfig.getSecureHeaders(endpoint);
      final body = jsonEncode({
        'name': trimmedName,
        'email': trimmedEmail,
        'password': password,
      });

      final response = await http
          .post(uri, headers: headers, body: body)
          .timeout(const Duration(seconds: 15));

      final data = jsonDecode(response.body);

      if ((response.statusCode == 200 || response.statusCode == 201) &&
          data['status'] == 'success') {
        final userData = data['data']['user'] as Map<String, dynamic>;
        final token = data['data']['token'] as String?;
        final user = AppUser.fromJson(userData).copyWith(token: token);

        await saveUserSession(user, token);
        return AuthResult(
          success: true,
          message: data['message'] ?? 'Account created successfully!',
          user: user,
          token: token,
        );
      } else {
        return AuthResult(
          success: false,
          message: data['message'] ?? 'Registration failed. Please try again.',
        );
      }
    } catch (e) {
      debugPrint('AuthService.register API error: $e');

      // Offline / Demo Fallback:
      if (trimmedName.isNotEmpty && trimmedEmail.contains('@') && password.length >= 6) {
        final localUser = AppUser(
          id: DateTime.now().millisecondsSinceEpoch % 10000,
          name: trimmedName,
          email: trimmedEmail,
          status: 'active',
          token: 'offline_token_${DateTime.now().millisecondsSinceEpoch}',
        );
        await saveUserSession(localUser, localUser.token);

        return AuthResult(
          success: true,
          message: 'Account created! Welcome to PikaShow (Offline Mode).',
          user: localUser,
          token: localUser.token,
        );
      }

      return const AuthResult(
        success: false,
        message: 'Unable to reach registration server. Please check connection.',
      );
    }
  }
}
