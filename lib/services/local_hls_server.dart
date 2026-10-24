import 'dart:async';
import 'dart:io';
import 'package:flutter/foundation.dart';

/// Lightweight embedded localhost HTTP server for serving dynamically
/// customized HLS master playlists (for multi-audio track switching and
/// specific video resolution rendition locking).
class LocalHlsServer {
  static HttpServer? _server;
  static int? _port;
  static String? _currentManifest;

  /// Starts the embedded loopback server if not running, updates the active
  /// custom manifest content, and returns a local streaming URL.
  static Future<String> serveManifest(String m3u8Content) async {
    _currentManifest = m3u8Content;

    if (_server == null) {
      try {
        _server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
        _port = _server!.port;

        _server!.listen((HttpRequest request) {
          try {
            final response = request.response;
            response.headers
              ..set(HttpHeaders.contentTypeHeader, 'application/vnd.apple.mpegurl')
              ..set(HttpHeaders.cacheControlHeader, 'no-cache, no-store, must-revalidate')
              ..set('Access-Control-Allow-Origin', '*');

            response.write(_currentManifest ?? '');
            response.close();
          } catch (e) {
            debugPrint('LocalHlsServer request error: $e');
          }
        });
      } catch (e) {
        debugPrint('LocalHlsServer bind error: $e');
        return '';
      }
    }

    final timestamp = DateTime.now().millisecondsSinceEpoch;
    return 'http://127.0.0.1:$_port/master_$timestamp.m3u8';
  }

  /// Stop and release server resources
  static Future<void> stop() async {
    try {
      await _server?.close(force: true);
    } catch (_) {}
    _server = null;
    _port = null;
    _currentManifest = null;
  }
}
