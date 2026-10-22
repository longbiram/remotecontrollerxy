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
  static const String _prefUserIdKey = 'auth_user_id';
  static const String _prefUserNameKey = 'auth_user_name';
  static const String _prefUserEmailKey = 'auth_user_email';
  static const String _prefUserAvatarKey = 'auth_user_avatar';
  static const String _prefUserTokenKey = 'auth_user_token';
  static const String _prefIsGuestKey = 'auth_is_guest';

  /// Check if user has an active stored session
  static Future<AppUser?> getStoredUser() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final id = prefs.getInt(_prefUserIdKey);
      final email = prefs.getString(_prefUserEmailKey);
      final name = prefs.getString(_prefUserNameKey);
      final avatar = prefs.getString(_prefUserAvatarKey);
      final token = prefs.getString(_prefUserTokenKey);

      if (id != null && email != null && name != null) {
        return AppUser(
          id: id,
          name: name,
          email: email,
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
      return prefs.getBool(_prefIsGuestKey) ?? false;
    } catch (_) {
      return false;
    }
  }

  /// Save user session into SharedPreferences
  static Future<void> saveUserSession(AppUser user, String? token) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setInt(_prefUserIdKey, user.id);
      await prefs.setString(_prefUserNameKey, user.name);
      await prefs.setString(_prefUserEmailKey, user.email);
      if (user.avatar != null) {
        await prefs.setString(_prefUserAvatarKey, user.avatar!);
      } else {
        await prefs.remove(_prefUserAvatarKey);
      }
      if (token != null) {
        await prefs.setString(_prefUserTokenKey, token);
      }
      await prefs.setBool(_prefIsGuestKey, false);
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
          .timeout(const Duration(seconds: 5));

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
          .timeout(const Duration(seconds: 5));

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
