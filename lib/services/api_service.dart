import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';
import '../models/content_item.dart';
import '../models/home_category.dart';
import '../data/mock_content.dart';

class ApiService {
  /// Base URL is obfuscated and dynamically resolved via ApiConfig
  static String get baseUrl => ApiConfig.baseUrl;

  /// Custom override (e.g. for testing with LAN IP)
  static set baseUrl(String url) {
    ApiConfig.setCustomBaseUrl(url);
  }

  /// Check if the PHP backend is running and installed
  static Future<bool> isServerAvailable() async {
    try {
      const endpoint = '/api/health.php';
      final uri = Uri.parse('$baseUrl$endpoint');
      final headers = ApiConfig.getSecureHeaders(endpoint);

      final res = await http.get(uri, headers: headers).timeout(const Duration(seconds: 3));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body);
        return data['installed'] == true;
      }
      return false;
    } catch (_) {
      return false;
    }
  }

  /// Fetch full catalog or filtered items from PHP backend
  /// Transmits encrypted HMAC signatures and headers
  /// Falls back to MockContent if backend is unreachable and fallbackToMock is true
  static Future<List<ContentItem>> fetchCatalog({
    ContentType? type,
    bool? trending,
    bool? promoted,
    String? search,
    bool fallbackToMock = true,
  }) async {
    const endpoint = '/api/content.php';
    try {
      final queryParams = <String, String>{};
      if (type != null) queryParams['type'] = type.name;
      if (trending == true) queryParams['trending'] = '1';
      if (promoted == true) queryParams['promoted'] = '1';
      if (search != null && search.trim().isNotEmpty) {
        queryParams['search'] = search.trim();
      }

      final uri = Uri.parse('$baseUrl$endpoint')
          .replace(queryParameters: queryParams.isNotEmpty ? queryParams : null);

      final headers = ApiConfig.getSecureHeaders(endpoint);
      final response = await http.get(uri, headers: headers).timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final Map<String, dynamic> body = jsonDecode(response.body);
        if (body['status'] == 'success' && body['data'] is List) {
          final List rawItems = body['data'];
          final items = rawItems
              .map((json) => ContentItem.fromJson(json as Map<String, dynamic>))
              .toList();

          if (items.isNotEmpty) {
            return items;
          }
        }
      }
    } catch (e) {
      debugPrint('ApiService.fetchCatalog warning: $e. Falling back to local catalog.');
    }

    if (!fallbackToMock) {
      return const [];
    }

    // Fallback to local catalog if server is not running
    List<ContentItem> fallback = MockContent.allContent;
    if (type != null) {
      fallback = fallback.where((i) => i.type == type).toList();
    }
    if (trending == true) {
      fallback = fallback.where((i) => i.isTrending).toList();
    }
    if (promoted == true) {
      fallback = fallback.where((i) => i.isPromoted).toList();
    }
    if (search != null && search.trim().isNotEmpty) {
      final q = search.toLowerCase();
      fallback = fallback.where((i) =>
          i.title.toLowerCase().contains(q) ||
          i.genres.any((g) => g.toLowerCase().contains(q))).toList();
    }
    return fallback;
  }

  /// Fetch single item detail with full season and episode lists
  static Future<ContentItem?> fetchDetail(String id) async {
    const endpoint = '/api/detail.php';
    try {
      final uri = Uri.parse('$baseUrl$endpoint?id=${Uri.encodeComponent(id)}');
      final headers = ApiConfig.getSecureHeaders(endpoint);

      final response = await http.get(uri, headers: headers).timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final Map<String, dynamic> body = jsonDecode(response.body);
        if (body['status'] == 'success' && body['data'] != null) {
          return ContentItem.fromJson(body['data'] as Map<String, dynamic>);
        }
      }
    } catch (e) {
      debugPrint('ApiService.fetchDetail warning: $e');
    }

    // Fallback to local item
    try {
      return MockContent.allContent.firstWhere((i) => i.id == id);
    } catch (_) {
      return null;
    }
  }

  /// Fetch dynamic home categories configured by admin panel
  /// Returns categories ordered by displayOrder, falls back to defaultCategories
  static Future<List<HomeCategory>> fetchHomeCategories() async {
    const endpoint = '/api/categories.php';
    try {
      final uri = Uri.parse('$baseUrl$endpoint');
      final headers = ApiConfig.getSecureHeaders(endpoint);
      final response = await http.get(uri, headers: headers).timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final Map<String, dynamic> body = jsonDecode(response.body);
        if (body['status'] == 'success' && body['homeCategories'] is List) {
          final List rawCats = body['homeCategories'];
          final cats = rawCats
              .map((json) => HomeCategory.fromJson(json as Map<String, dynamic>))
              .toList();

          if (cats.isNotEmpty) {
            cats.sort((a, b) => a.displayOrder.compareTo(b.displayOrder));
            return cats;
          }
        }
      }
    } catch (e) {
      debugPrint('ApiService.fetchHomeCategories warning: $e');
    }

    return HomeCategory.defaultCategories;
  }
}
