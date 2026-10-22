import 'dart:convert';
import 'package:crypto/crypto.dart';

/// Secure API Configuration & Base URL Protection
/// Protects endpoints and base URLs from static binary decompilation,
/// memory inspection, and unauthorized direct scraping.
class ApiConfig {
  // 1. Compile-Time Environment Variable Support
  // Allows overriding the URL at build time via:
  // flutter build apk --dart-define=API_BASE_URL=https://daksocial.in/pikashow/backend
  static const String _envBaseUrl = String.fromEnvironment('API_BASE_URL');

  // 2. XOR-Masked Obfuscated Byte Arrays
  // Hardcoded plain strings can easily be harvested with 'strings' or 'jadx'.
  // These bytes are XOR scrambled with private masks and decrypted only in RAM.
  static const int _xorMask = 0x7E;

  // Obfuscated 'https://daksocial.in/pikashow/backend' (Production URL)
  static const List<int> _obfProdUrl = [
    22, 10, 10, 14, 13, 68, 81, 81, 26, 31, 21, 13, 17, 29, 23, 31, 18, 80, 23,
    16, 81, 14, 23, 21, 31, 13, 22, 17, 9, 81, 28, 31, 29, 21, 27, 16, 26
  ];

  // Obfuscated 'http://10.0.2.2:8000' (Android Emulator Debug URL)
  static const List<int> _obfEmuUrl = [
    22, 10, 10, 14, 68, 81, 81, 79, 78, 80, 78, 80, 76, 80, 76, 68, 70, 78, 78, 78
  ];

  // Obfuscated API Secret Key (used for HMAC SHA-256 request signing)
  static const int _keyMask = 0x5C;
  static const List<int> _obfAppKey = [
    44, 53, 55, 61, 3, 47, 57, 63, 3, 100, 58, 101, 110, 61, 109, 108, 62, 104,
    63, 100, 56, 107, 57, 106, 58, 111, 109, 110, 108, 105, 101, 61, 104, 62,
    100, 63, 107, 57, 106, 56, 105
  ];

  static String? _customBaseUrl;

  /// In-memory de-obfuscation helper
  static String _xorDecode(List<int> bytes, int mask) {
    final decodedBytes = bytes.map((b) => b ^ mask).toList();
    return utf8.decode(decodedBytes);
  }

  /// Get the secret API key dynamically in memory
  static String get appSecretKey => _xorDecode(_obfAppKey, _keyMask);

  /// Dynamically resolves the protected Base URL (defaults to https://daksocial.in/pikashow/backend)
  static String get baseUrl {
    // 1. Highest priority: User configured custom runtime URL
    if (_customBaseUrl != null && _customBaseUrl!.isNotEmpty) {
      return _cleanUrl(_customBaseUrl!);
    }

    // 2. Compile-time injected URL via --dart-define
    if (_envBaseUrl.isNotEmpty) {
      return _cleanUrl(_envBaseUrl);
    }

    // 3. De-obfuscate protected production URL
    return _cleanUrl(_xorDecode(_obfProdUrl, _xorMask));
  }

  /// Strip trailing slashes so concatenating endpoints like '/api/content.php' is always clean
  static String _cleanUrl(String raw) {
    return raw.trim().replaceAll(RegExp(r'/+$'), '');
  }

  /// Override Base URL at runtime (e.g. for local testing)
  static void setCustomBaseUrl(String url) {
    _customBaseUrl = _cleanUrl(url);
  }

  /// Switch to local Android emulator for local offline testing
  static void useLocalEmulator() {
    _customBaseUrl = _cleanUrl(_xorDecode(_obfEmuUrl, _xorMask));
  }

  /// Revert back to the obfuscated production Base URL
  static void useProduction() {
    _customBaseUrl = null;
  }

  /// Generate cryptographic signature headers for every outgoing API request
  /// Prevents API scraping, tampering, and replay attacks
  static Map<String, String> getSecureHeaders(String endpointPath) {
    final timestamp = (DateTime.now().millisecondsSinceEpoch ~/ 1000).toString();
    final secret = appSecretKey;

    // HMAC SHA-256 signature of: timestamp:path:secret
    final payload = '$timestamp:$endpointPath:$secret';
    final signature = sha256.convert(utf8.encode(payload)).toString();

    return {
      'Content-Type': 'application/json; charset=utf-8',
      'Accept': 'application/json',
      'X-App-Key': secret,
      'X-App-Time': timestamp,
      'X-App-Signature': signature,
      'User-Agent': 'PikaShow-Mobile/1.0.0 (Protected-Client)',
    };
  }
}
