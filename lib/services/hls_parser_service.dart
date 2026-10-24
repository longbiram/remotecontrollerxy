import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../models/player_config.dart';
import '../models/content_item.dart';

class HlsStreamInfo {
  final List<PlayerResolution> resolutions;
  final List<AudioTrack> audioTracks;
  final String rawManifest;
  final Uri? baseUri;

  const HlsStreamInfo({
    required this.resolutions,
    required this.audioTracks,
    this.rawManifest = '',
    this.baseUri,
  });
}

class HlsParserService {
  /// Parses an .m3u8 HLS master playlist URL to detect available adaptive resolutions
  /// and multilingual audio tracks. Falls back gracefully to ContentItem metadata.
  static Future<HlsStreamInfo> parsePlaylist({
    required String streamUrl,
    required ContentItem item,
  }) async {
    if (streamUrl.toLowerCase().contains('.m3u8') && streamUrl.startsWith('http')) {
      try {
        final uri = Uri.parse(streamUrl);
        final response = await http.get(uri).timeout(const Duration(seconds: 3));

        if (response.statusCode == 200 && response.body.contains('#EXTM3U')) {
          return parseManifestContent(
            content: response.body,
            baseUri: uri,
            item: item,
            streamUrl: streamUrl,
          );
        }
      } catch (e) {
        debugPrint('HlsParserService warning: could not inspect m3u8 playlist ($e). Using metadata fallbacks.');
      }
    }

    return parseManifestContent(
      content: '',
      baseUri: Uri.parse(streamUrl.isNotEmpty && streamUrl.startsWith('http') ? streamUrl : 'https://example.com/master.m3u8'),
      item: item,
      streamUrl: streamUrl,
    );
  }

  /// Parses an m3u8 manifest content string directly, resolving variant and audio URLs against baseUri.
  static HlsStreamInfo parseManifestContent({
    required String content,
    required Uri baseUri,
    required ContentItem item,
    String? streamUrl,
  }) {
    final List<PlayerResolution> parsedResolutions = [];
    final List<AudioTrack> parsedAudioTracks = [];

    // Always include Auto (Adaptive) as the first and default option
    parsedResolutions.add(PlayerResolution(
      label: 'Auto (Adaptive - Recommended)',
      resolution: 'Adaptive',
      bitrate: 'Auto-Optimized',
      streamUrl: streamUrl ?? baseUri.toString(),
    ));

    if (content.contains('#EXTM3U')) {
      final lines = content.split('\n');

      for (int i = 0; i < lines.length; i++) {
        final line = lines[i].trim();

        // 1. Detect Audio Tracks: #EXT-X-MEDIA:TYPE=AUDIO,...
        if (line.startsWith('#EXT-X-MEDIA:') && line.contains('TYPE=AUDIO')) {
          final track = _parseAudioTrackLine(line, baseUri);
          if (track != null && !parsedAudioTracks.any((t) => t.code == track.code)) {
            parsedAudioTracks.add(track);
          }
        }

        // 2. Detect Video Stream Variants: #EXT-X-STREAM-INF:...
        if (line.startsWith('#EXT-X-STREAM-INF:')) {
          String variantUri = '';
          // The variant stream URI is on the next non-empty non-comment line
          for (int j = i + 1; j < lines.length; j++) {
            final nextLine = lines[j].trim();
            if (nextLine.isNotEmpty && !nextLine.startsWith('#')) {
              variantUri = nextLine;
              break;
            }
          }

          final res = _parseStreamInfLine(line, variantUri, baseUri);
          if (res != null && !parsedResolutions.any((r) => r.resolution == res.resolution)) {
            parsedResolutions.add(res);
          }
        }
      }
    }

    // Fallback for Resolutions if only Auto was found or playlist didn't provide variants
    if (parsedResolutions.length <= 1) {
      if (item.availableQualities.isNotEmpty) {
        for (final q in item.availableQualities) {
          final resInfo = _resolutionForQuality(q);
          if (!parsedResolutions.any((r) => r.label.contains(q))) {
            parsedResolutions.add(resInfo);
          }
        }
      } else {
        // Standard HLS resolution profiles
        parsedResolutions.addAll(const [
          PlayerResolution(label: '1080p Full HD', resolution: '1920x1080', bitrate: '5.2 Mbps'),
          PlayerResolution(label: '720p HD', resolution: '1280x720', bitrate: '2.8 Mbps'),
          PlayerResolution(label: '480p SD', resolution: '854x480', bitrate: '1.2 Mbps'),
          PlayerResolution(label: '360p Data Saver', resolution: '640x360', bitrate: '600 Kbps'),
        ]);
      }
    }

    // Fallback for Audio Tracks if none extracted from m3u8 playlist tags
    if (parsedAudioTracks.isEmpty) {
      if (item.languages.isNotEmpty) {
        parsedAudioTracks.addAll(AudioTrack.fromLanguages(item.languages));
      } else {
        parsedAudioTracks.addAll(AudioTrack.defaults);
      }
    }

    return HlsStreamInfo(
      resolutions: parsedResolutions,
      audioTracks: parsedAudioTracks,
      rawManifest: content,
      baseUri: baseUri,
    );
  }

  /// Generates a customized HLS master playlist with:
  /// 1. Absolute URLs for all audio media groups and variant streams.
  /// 2. The selected audio track marked as DEFAULT=YES and AUTOSELECT=YES.
  /// 3. The selected resolution locked (or all variants included if Auto).
  static String buildCustomPlaylist({
    required String rawManifest,
    required Uri baseUri,
    AudioTrack? selectedAudio,
    PlayerResolution? selectedResolution,
  }) {
    if (!rawManifest.contains('#EXTM3U')) return rawManifest;

    final lines = rawManifest.split('\n');
    final buffer = StringBuffer();

    for (int i = 0; i < lines.length; i++) {
      final line = lines[i].trim();

      // 1. Process #EXT-X-MEDIA:TYPE=AUDIO
      if (line.startsWith('#EXT-X-MEDIA:') && line.contains('TYPE=AUDIO')) {
        var processedLine = line;

        // Resolve URI="..." to absolute URL
        final uriMatch = RegExp(r'URI="([^"]+)"').firstMatch(processedLine);
        if (uriMatch != null) {
          final relUri = uriMatch.group(1)!;
          if (!relUri.startsWith('http://') && !relUri.startsWith('https://')) {
            final absUrl = baseUri.resolve(relUri).toString();
            processedLine = processedLine.replaceAll('URI="$relUri"', 'URI="$absUrl"');
          }
        }

        // Determine if this is the selected audio track
        final nameMatch = RegExp(r'NAME="([^"]+)"').firstMatch(processedLine);
        final langMatch = RegExp(r'LANGUAGE="([^"]+)"').firstMatch(processedLine);
        final trackName = nameMatch?.group(1);
        final trackLang = langMatch?.group(1);

        bool isTarget = false;
        if (selectedAudio != null) {
          if (trackLang != null && trackLang.toLowerCase() == selectedAudio.code.toLowerCase()) {
            isTarget = true;
          } else if (trackName != null &&
              selectedAudio.name.toLowerCase().contains(trackName.toLowerCase())) {
            isTarget = true;
          }
        }

        if (isTarget) {
          processedLine = processedLine.replaceAll('DEFAULT=NO', 'DEFAULT=YES');
          processedLine = processedLine.replaceAll('AUTOSELECT=NO', 'AUTOSELECT=YES');
          if (!processedLine.contains('DEFAULT=')) {
            processedLine += ',DEFAULT=YES';
          }
          if (!processedLine.contains('AUTOSELECT=')) {
            processedLine += ',AUTOSELECT=YES';
          }
        } else if (selectedAudio != null) {
          processedLine = processedLine.replaceAll('DEFAULT=YES', 'DEFAULT=NO');
          processedLine = processedLine.replaceAll('AUTOSELECT=YES', 'AUTOSELECT=NO');
        }

        buffer.writeln(processedLine);
        continue;
      }

      // 2. Process #EXT-X-STREAM-INF
      if (line.startsWith('#EXT-X-STREAM-INF:')) {
        // Find next non-empty, non-comment line containing the variant URI
        String variantUri = '';
        int variantIndex = -1;
        for (int j = i + 1; j < lines.length; j++) {
          final nextLine = lines[j].trim();
          if (nextLine.isNotEmpty && !nextLine.startsWith('#')) {
            variantUri = nextLine;
            variantIndex = j;
            break;
          }
        }

        // Determine if this resolution variant should be included
        bool includeVariant = true;
        if (selectedResolution != null && !selectedResolution.isAuto) {
          final resMatch = RegExp(r'RESOLUTION=(\d+x\d+)').firstMatch(line);
          final resStr = resMatch?.group(1);
          if (resStr != null && selectedResolution.resolution.contains(resStr)) {
            includeVariant = true;
          } else if (selectedResolution.streamUrl != null &&
              variantUri.isNotEmpty &&
              selectedResolution.streamUrl!.contains(variantUri)) {
            includeVariant = true;
          } else {
            includeVariant = false;
          }
        }

        if (includeVariant) {
          buffer.writeln(line);
          if (variantUri.isNotEmpty) {
            final absVariantUrl = (!variantUri.startsWith('http://') && !variantUri.startsWith('https://'))
                ? baseUri.resolve(variantUri).toString()
                : variantUri;
            buffer.writeln(absVariantUrl);
          }
        }

        // Advance loop past the variant URI line if found
        if (variantIndex != -1) {
          i = variantIndex;
        }
        continue;
      }

      buffer.writeln(line);
    }

    return buffer.toString();
  }

  static PlayerResolution? _parseStreamInfLine(String tagLine, String rawUri, Uri baseUri) {
    try {
      final resMatch = RegExp(r'RESOLUTION=(\d+x\d+)').firstMatch(tagLine);
      final bwMatch = RegExp(r'BANDWIDTH=(\d+)').firstMatch(tagLine);

      final resolutionStr = resMatch != null ? resMatch.group(1)! : '';
      final bandwidth = bwMatch != null ? int.tryParse(bwMatch.group(1)!) ?? 0 : 0;

      String label;
      if (resolutionStr.isNotEmpty) {
        final parts = resolutionStr.split('x');
        final height = parts.length > 1 ? int.tryParse(parts[1]) ?? 0 : 0;
        if (height >= 1080) {
          label = '1080p Full HD';
        } else if (height >= 720) {
          label = '720p HD';
        } else if (height >= 480) {
          label = '480p SD';
        } else {
          label = '${height}p Data Saver';
        }
      } else {
        label = 'Auto Variant';
      }

      String bitrateStr = '';
      if (bandwidth > 0) {
        if (bandwidth >= 1000000) {
          bitrateStr = '${(bandwidth / 1000000).toStringAsFixed(1)} Mbps';
        } else {
          bitrateStr = '${(bandwidth / 1000).round()} Kbps';
        }
      } else {
        bitrateStr = 'Auto';
      }

      String resolvedUrl = '';
      if (rawUri.isNotEmpty) {
        resolvedUrl = baseUri.resolve(rawUri).toString();
      }

      return PlayerResolution(
        label: label,
        resolution: resolutionStr.isNotEmpty ? resolutionStr : label,
        bitrate: bitrateStr,
        streamUrl: resolvedUrl.isNotEmpty ? resolvedUrl : null,
      );
    } catch (_) {
      return null;
    }
  }

  static AudioTrack? _parseAudioTrackLine(String tagLine, Uri baseUri) {
    try {
      final nameMatch = RegExp(r'NAME="([^"]+)"').firstMatch(tagLine);
      final langMatch = RegExp(r'LANGUAGE="([^"]+)"').firstMatch(tagLine);
      final isDefMatch = RegExp(r'DEFAULT=(YES|NO)').firstMatch(tagLine);
      final uriMatch = RegExp(r'URI="([^"]+)"').firstMatch(tagLine);

      final name = nameMatch != null ? nameMatch.group(1)! : '';
      final lang = langMatch != null ? langMatch.group(1)! : (name.length >= 3 ? name.substring(0, 3).toLowerCase() : 'und');
      final isDefault = isDefMatch != null && isDefMatch.group(1) == 'YES';
      final rawUri = uriMatch != null ? uriMatch.group(1)! : '';

      String? resolvedUrl;
      if (rawUri.isNotEmpty) {
        resolvedUrl = baseUri.resolve(rawUri).toString();
      }

      if (name.isEmpty) return null;

      return AudioTrack(
        code: lang,
        name: isDefault ? '$name [Original]' : name,
        streamUrl: resolvedUrl,
        isDefault: isDefault,
      );
    } catch (_) {
      return null;
    }
  }

  static PlayerResolution _resolutionForQuality(String quality) {
    switch (quality.toUpperCase()) {
      case '4K UHD':
      case '4K':
        return const PlayerResolution(label: '4K Ultra HD', resolution: '3840x2160', bitrate: '14.5 Mbps');
      case '1080P 60FPS':
      case '1080P':
        return const PlayerResolution(label: '1080p Full HD', resolution: '1920x1080', bitrate: '5.2 Mbps');
      case '720P':
        return const PlayerResolution(label: '720p HD', resolution: '1280x720', bitrate: '2.8 Mbps');
      case '480P':
        return const PlayerResolution(label: '480p SD', resolution: '854x480', bitrate: '1.2 Mbps');
      default:
        return PlayerResolution(label: quality, resolution: quality, bitrate: 'Adaptive');
    }
  }
}
