class PlayerResolution {
  final String label;
  final String resolution;
  final String bitrate;
  final String? streamUrl;

  const PlayerResolution({
    required this.label,
    required this.resolution,
    required this.bitrate,
    this.streamUrl,
  });

  bool get isAuto =>
      resolution.toLowerCase() == 'adaptive' ||
      label.toLowerCase().contains('auto');

  static const List<PlayerResolution> defaults = [
    PlayerResolution(
      label: 'Auto (Adaptive - Recommended)',
      resolution: 'Adaptive',
      bitrate: 'Auto',
    ),
    PlayerResolution(
      label: '1080p Full HD',
      resolution: '1920x1080',
      bitrate: '5.2 Mbps',
    ),
    PlayerResolution(
      label: '720p HD',
      resolution: '1280x720',
      bitrate: '2.8 Mbps',
    ),
    PlayerResolution(
      label: '480p SD',
      resolution: '854x480',
      bitrate: '1.2 Mbps',
    ),
    PlayerResolution(
      label: '360p Data Saver',
      resolution: '640x360',
      bitrate: '600 Kbps',
    ),
  ];
}

class AudioTrack {
  final String code;
  final String name;
  final String? streamUrl;
  final bool isDefault;

  const AudioTrack({
    required this.code,
    required this.name,
    this.streamUrl,
    this.isDefault = false,
  });

  static const List<AudioTrack> defaults = [
    AudioTrack(
      code: 'hin',
      name: 'Hindi [Original] (Dolby Atmos)',
      isDefault: true,
    ),
    AudioTrack(code: 'eng', name: 'English (5.1 Surround)'),
    AudioTrack(code: 'tam', name: 'Tamil Dubbed'),
    AudioTrack(code: 'tel', name: 'Telugu Dubbed'),
  ];

  static List<AudioTrack> fromLanguages(List<String> languages) {
    if (languages.isEmpty) return defaults;
    return languages.asMap().entries.map((entry) {
      final index = entry.key;
      final lang = entry.value.trim();
      final isFirst = index == 0;
      final code = lang.length >= 3 ? lang.substring(0, 3).toLowerCase() : lang.toLowerCase();
      final suffix = isFirst ? ' [Original]' : ' Dubbed';
      return AudioTrack(
        code: code,
        name: '$lang$suffix',
        isDefault: isFirst,
      );
    }).toList();
  }
}

class PlaybackSpeed {
  final double speed;
  final String label;

  const PlaybackSpeed({required this.speed, required this.label});

  static const List<PlaybackSpeed> defaults = [
    PlaybackSpeed(speed: 0.5, label: '0.5x (Slow)'),
    PlaybackSpeed(speed: 0.75, label: '0.75x'),
    PlaybackSpeed(speed: 1.0, label: '1.0x (Normal)'),
    PlaybackSpeed(speed: 1.25, label: '1.25x'),
    PlaybackSpeed(speed: 1.5, label: '1.5x'),
    PlaybackSpeed(speed: 2.0, label: '2.0x (Fast)'),
  ];
}
