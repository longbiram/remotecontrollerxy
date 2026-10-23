import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';
import '../models/watch_progress.dart';

class WatchProgressService {
  /// Fetch user's continue watching items from backend API
  static Future<List<WatchProgress>> fetchProgress(String token) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return const [];
    }
    const endpoint = '/api/watch_progress.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
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
        if (body['status'] == 'success' && body['data'] is List) {
          final List list = body['data'];
          final List<WatchProgress> items = [];
          for (final raw in list) {
            try {
              if (raw is Map) {
                items.add(WatchProgress.fromJson(Map<String, dynamic>.from(raw)));
              }
            } catch (e) {
              debugPrint('Error parsing WatchProgress item: $e');
            }
          }
          return items;
        }
      }
    } catch (e) {
      debugPrint('WatchProgressService.fetchProgress warning: $e');
    }
    return const [];
  }

  /// Sync/save watch progress to backend API
  static Future<bool> saveProgress({
    required String token,
    required WatchProgress progress,
  }) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return false;
    }
    const endpoint = '/api/watch_progress.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final body = jsonEncode({
        'content_id': progress.contentId,
        'episode_id': progress.episode?.id,
        'position_seconds': progress.positionSeconds,
        'duration_seconds': progress.durationSeconds,
        'item': progress.item.toJson(),
        'episode': progress.episode?.toJson(),
      });

      final response = await http
          .post(uri, headers: headers, body: body)
          .timeout(const Duration(seconds: 4));

      if (response.statusCode == 200 || response.statusCode == 201) {
        final data = jsonDecode(response.body);
        return data['status'] == 'success';
      }
    } catch (e) {
      debugPrint('WatchProgressService.saveProgress warning: $e');
    }
    return false;
  }

  /// Remove item from continue watching on backend
  static Future<bool> removeProgress({
    required String token,
    required String contentId,
    String? episodeId,
  }) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      return false;
    }
    const endpoint = '/api/watch_progress.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final body = jsonEncode({
        'action': 'delete',
        'content_id': contentId,
        'episode_id': episodeId,
      });

      final response = await http
          .post(uri, headers: headers, body: body)
          .timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return data['status'] == 'success';
      }
    } catch (e) {
      debugPrint('WatchProgressService.removeProgress warning: $e');
    }
    return false;
  }
}
