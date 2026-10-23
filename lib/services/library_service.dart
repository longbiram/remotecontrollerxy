import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';
import '../models/content_item.dart';

class LibraryService {
  // ── FAVOURITES ────────────────────────────────────────────────────────────

  /// Fetch user's saved favourites from backend API
  static Future<List<ContentItem>> fetchFavourites(String token) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return const [];
    }
    const endpoint = '/api/favourites.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint?token=$token');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final response = await http
          .get(uri, headers: headers)
          .timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final body = jsonDecode(response.body);
        if (body['status'] == 'success' && body['favourites'] is List) {
          final List list = body['favourites'];
          final List<ContentItem> items = [];
          for (final raw in list) {
            try {
              if (raw is Map && raw['item'] is Map) {
                items.add(ContentItem.fromJson(Map<String, dynamic>.from(raw['item'])));
              }
            } catch (e) {
              debugPrint('Error parsing Favourite item: $e');
            }
          }
          return items;
        }
      }
    } catch (e) {
      debugPrint('LibraryService.fetchFavourites warning: $e');
    }
    return const [];
  }

  /// Save favourite item to backend API
  static Future<bool> saveFavourite({
    required String token,
    required ContentItem item,
  }) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return false;
    }
    const endpoint = '/api/favourites.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint?token=$token');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final body = jsonEncode({
        'content_id': item.id,
        'item': item.toJson(),
      });

      final response = await http
          .post(uri, headers: headers, body: body)
          .timeout(const Duration(seconds: 4));

      if (response.statusCode == 200 || response.statusCode == 201) {
        final data = jsonDecode(response.body);
        return data['status'] == 'success';
      }
    } catch (e) {
      debugPrint('LibraryService.saveFavourite warning: $e');
    }
    return false;
  }

  /// Remove single favourite item from backend API
  static Future<bool> removeFavourite({
    required String token,
    required String contentId,
  }) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return false;
    }
    const endpoint = '/api/favourites.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint?content_id=$contentId&token=$token');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final response = await http
          .delete(uri, headers: headers)
          .timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return data['status'] == 'success';
      }
    } catch (e) {
      debugPrint('LibraryService.removeFavourite warning: $e');
    }
    return false;
  }

  /// Clear all favourites from backend API
  static Future<bool> clearAllFavourites(String token) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return false;
    }
    const endpoint = '/api/favourites.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint?all=1&token=$token');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final response = await http
          .delete(uri, headers: headers)
          .timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return data['status'] == 'success';
      }
    } catch (e) {
      debugPrint('LibraryService.clearAllFavourites warning: $e');
    }
    return false;
  }

  // ── WATCH HISTORY ─────────────────────────────────────────────────────────

  /// Fetch user's watch history from backend API
  static Future<List<ContentItem>> fetchHistory(String token) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return const [];
    }
    const endpoint = '/api/history.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint?token=$token');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final response = await http
          .get(uri, headers: headers)
          .timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final body = jsonDecode(response.body);
        if (body['status'] == 'success' && body['history'] is List) {
          final List list = body['history'];
          final List<ContentItem> items = [];
          for (final raw in list) {
            try {
              if (raw is Map && raw['item'] is Map) {
                items.add(ContentItem.fromJson(Map<String, dynamic>.from(raw['item'])));
              }
            } catch (e) {
              debugPrint('Error parsing History item: $e');
            }
          }
          return items;
        }
      }
    } catch (e) {
      debugPrint('LibraryService.fetchHistory warning: $e');
    }
    return const [];
  }

  /// Record an item in watch history on backend API
  static Future<bool> recordHistory({
    required String token,
    required ContentItem item,
  }) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return false;
    }
    const endpoint = '/api/history.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint?token=$token');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final body = jsonEncode({
        'content_id': item.id,
        'item': item.toJson(),
      });

      final response = await http
          .post(uri, headers: headers, body: body)
          .timeout(const Duration(seconds: 4));

      if (response.statusCode == 200 || response.statusCode == 201) {
        final data = jsonDecode(response.body);
        return data['status'] == 'success';
      }
    } catch (e) {
      debugPrint('LibraryService.recordHistory warning: $e');
    }
    return false;
  }

  /// Clear user's watch history on backend API
  static Future<bool> clearHistory(String token) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return false;
    }
    const endpoint = '/api/history.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint?all=1&token=$token');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final response = await http
          .delete(uri, headers: headers)
          .timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return data['status'] == 'success';
      }
    } catch (e) {
      debugPrint('LibraryService.clearHistory warning: $e');
    }
    return false;
  }
}
