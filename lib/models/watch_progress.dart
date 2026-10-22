import 'content_item.dart';

class WatchProgress {
  final String contentId;
  final ContentItem item;
  final Episode? episode;
  final int positionSeconds;
  final int durationSeconds;
  final DateTime updatedAt;

  const WatchProgress({
    required this.contentId,
    required this.item,
    this.episode,
    required this.positionSeconds,
    required this.durationSeconds,
    required this.updatedAt,
  });

  /// Fraction of video watched between 0.0 and 1.0
  double get progressPercentage {
    if (durationSeconds <= 0) return 0.0;
    final frac = positionSeconds / durationSeconds;
    return frac.clamp(0.0, 1.0);
  }

  /// Whether video is considered fully watched (>= 95%)
  bool get isCompleted => progressPercentage >= 0.95;

  String get positionFormatted => _formatDuration(Duration(seconds: positionSeconds));
  String get durationFormatted => _formatDuration(Duration(seconds: durationSeconds));

  String get remainingFormatted {
    final remainingSec = (durationSeconds - positionSeconds).clamp(0, durationSeconds);
    final d = Duration(seconds: remainingSec);
    if (d.inHours > 0) {
      return '${d.inHours}h ${d.inMinutes.remainder(60)}m left';
    }
    return '${d.inMinutes}m left';
  }

  String get displayTitle {
    if (episode != null) {
      return '${item.title} • E${episode!.episodeNumber}: ${episode!.title}';
    }
    return item.title;
  }

  String get displaySubtitle {
    if (episode != null) {
      return 'Episode ${episode!.episodeNumber} • $remainingFormatted';
    }
    return '${item.year} • $remainingFormatted';
  }

  static String _formatDuration(Duration d) {
    final hours = d.inHours;
    final minutes = d.inMinutes.remainder(60);
    final seconds = d.inSeconds.remainder(60);
    if (hours > 0) {
      return '$hours:${minutes.toString().padLeft(2, '0')}:${seconds.toString().padLeft(2, '0')}';
    }
    return '${minutes.toString().padLeft(2, '0')}:${seconds.toString().padLeft(2, '0')}';
  }

  Map<String, dynamic> toJson() => {
    'contentId': contentId,
    'item': item.toJson(),
    'episode': episode?.toJson(),
    'positionSeconds': positionSeconds,
    'durationSeconds': durationSeconds,
    'updatedAt': updatedAt.toIso8601String(),
  };

  factory WatchProgress.fromJson(Map<String, dynamic> json) {
    return WatchProgress(
      contentId: json['contentId'] as String? ?? '',
      item: ContentItem.fromJson(json['item'] as Map<String, dynamic>),
      episode: json['episode'] != null
          ? Episode.fromJson(json['episode'] as Map<String, dynamic>)
          : null,
      positionSeconds: (json['positionSeconds'] as num?)?.toInt() ?? 0,
      durationSeconds: (json['durationSeconds'] as num?)?.toInt() ?? 0,
      updatedAt: DateTime.tryParse(json['updatedAt'] as String? ?? '') ?? DateTime.now(),
    );
  }
}
