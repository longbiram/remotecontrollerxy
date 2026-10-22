import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:ota_update/ota_update.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import '../config/api_config.dart';
import '../models/app_update_info.dart';

class AppUpdateService {
  static StreamSubscription<OtaEvent>? _otaSubscription;

  /// Check backend API for new releases
  static Future<AppUpdateInfo?> checkForUpdate() async {
    try {
      final packageInfo = await PackageInfo.fromPlatform();
      final currentBuildNumber = int.tryParse(packageInfo.buildNumber) ?? 1;
      final currentVersionName = packageInfo.version;

      final baseUrl = ApiConfig.baseUrl;
      final endpoint = '/api/check_update.php';
      final uri = Uri.parse('$baseUrl$endpoint').replace(queryParameters: {
        'version_code': currentBuildNumber.toString(),
        'version_name': currentVersionName,
        'platform': defaultTargetPlatform.name.toLowerCase(),
      });

      final headers = ApiConfig.getSecureHeaders(endpoint);
      final response = await http.get(uri, headers: headers).timeout(
        const Duration(seconds: 4),
      );

      if (response.statusCode == 200) {
        final Map<String, dynamic> data = jsonDecode(response.body);
        if (data['status'] == 'success') {
          return AppUpdateInfo.fromJson(data, currentBuildNumber);
        }
      }
    } catch (e) {
      debugPrint('AppUpdateService.checkForUpdate error: $e');
    }
    return null;
  }

  /// Start OTA APK download and trigger installation intent
  static Stream<OtaEvent> startOtaDownload(String apkUrl) {
    cancelOngoingDownload();
    return OtaUpdate().execute(
      apkUrl,
      destinationFilename: 'pikashow_latest.apk',
    );
  }

  /// Cancel active OTA download
  static void cancelOngoingDownload() {
    _otaSubscription?.cancel();
    _otaSubscription = null;
  }

  /// Fallback to launching the download in external browser
  static Future<bool> openInBrowser(String url) async {
    try {
      final uri = Uri.tryParse(url);
      if (uri != null) {
        return await launchUrl(uri, mode: LaunchMode.externalApplication);
      }
    } catch (e) {
      debugPrint('Failed to open browser URL $url: $e');
    }
    return false;
  }
}
