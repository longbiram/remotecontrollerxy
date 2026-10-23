import 'dart:convert';

enum ContentType {
  bollywood,
  hollywood,
  series,
  liveTv,
}


extension ContentTypeExtension on ContentType {
  String get displayName {
    switch (this) {
      case ContentType.bollywood:
        return 'Bollywood';
      case ContentType.hollywood:
        return 'Hollywood';
      case ContentType.series:
        return 'Web Series';
      case ContentType.liveTv:
        return 'Live TV';
    }
  }
}

class Episode {
  final String id;
  final int episodeNumber;
  final String title;
  final String duration;
  final String description;
  final String thumbnail;
  final String streamUrl;

  const Episode({
    required this.id,
    required this.episodeNumber,
    required this.title,
    required this.duration,
    required this.description,
    required this.thumbnail,
    required this.streamUrl,
  });

  factory Episode.fromJson(Map<String, dynamic> json) {
    return Episode(
      id: json['id']?.toString() ?? '',
      episodeNumber: ((json['episodeNumber'] ?? json['episode_number']) as num?)?.toInt() ?? 1,
      title: json['title'] as String? ?? '',
      duration: json['duration']?.toString() ?? '',
      description: json['description'] as String? ?? '',
      thumbnail: json['thumbnail'] as String? ?? '',
      streamUrl: (json['streamUrl'] ?? json['stream_url']) as String? ?? '',
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'episodeNumber': episodeNumber,
      'title': title,
      'duration': duration,
      'description': description,
      'thumbnail': thumbnail,
      'streamUrl': streamUrl,
    };
  }
}

class Season {
  final int seasonNumber;
  final String title;
  final List<Episode> episodes;

  const Season({
    required this.seasonNumber,
    required this.title,
    required this.episodes,
  });

  factory Season.fromJson(Map<String, dynamic> json) {
    List<Episode> epList = const [];
    final rawEps = json['episodes'];
    if (rawEps is List) {
      epList = rawEps
          .whereType<Map>()
          .map((e) => Episode.fromJson(Map<String, dynamic>.from(e)))
          .toList();
    } else if (rawEps is String && rawEps.isNotEmpty) {
      try {
        final decoded = jsonDecode(rawEps);
        if (decoded is List) {
          epList = decoded
              .whereType<Map>()
              .map((e) => Episode.fromJson(Map<String, dynamic>.from(e)))
              .toList();
        }
      } catch (_) {}
    }
    return Season(
      seasonNumber: ((json['seasonNumber'] ?? json['season_number']) as num?)?.toInt() ?? 1,
      title: json['title'] as String? ?? '',
      episodes: epList,
    );
  }


  Map<String, dynamic> toJson() {
    return {
      'seasonNumber': seasonNumber,
      'title': title,
      'episodes': episodes.map((e) => e.toJson()).toList(),
    };
  }
}

class ContentItem {
  final String id;
  final String title;
  final ContentType type;
  final String posterUrl;
  final String bannerUrl;
  final double rating;
  final String year;
  final String duration;
  final String synopsis;
  final List<String> genres;
  final List<String> languages;
  final List<String> availableQualities;
  final List<Season> seasons;
  final String streamUrl;
  final bool isPromoted;
  final bool isTrending;
  final bool isPremium;
  final String channelCategory; // For Live TV (Sports, News, Entertainment)
  final int views;
  final DateTime? createdAt;

  const ContentItem({
    required this.id,
    required this.title,
    required this.type,
    required this.posterUrl,
    required this.bannerUrl,
    required this.rating,
    required this.year,
    required this.duration,
    required this.synopsis,
    required this.genres,
    required this.languages,
    required this.availableQualities,
    this.seasons = const [],
    required this.streamUrl,
    this.isPromoted = false,
    this.isTrending = false,
    this.isPremium = false,
    this.channelCategory = '',
    this.views = 0,
    this.createdAt,
  });

  /// Factory for a safe placeholder item when only an ID or partial data is known
  factory ContentItem.empty({required String id, String title = 'Unknown Title'}) {
    return ContentItem(
      id: id,
      title: title,
      type: ContentType.bollywood,
      posterUrl: '',
      bannerUrl: '',
      rating: 0.0,
      year: '',
      duration: '',
      synopsis: '',
      genres: const [],
      languages: const [],
      availableQualities: const [],
      seasons: const [],
      streamUrl: '',
    );
  }

  factory ContentItem.fromJson(Map<String, dynamic> json) {
    final rawType = (json['type'] as String? ?? 'bollywood').toLowerCase();
    final parsedType = ContentType.values.firstWhere(
      (t) => t.name.toLowerCase() == rawType,
      orElse: () => ContentType.bollywood,
    );

    List<Season> seasonList = const [];
    final rawSeasons = json['seasons'];
    if (rawSeasons is List) {
      seasonList = rawSeasons
          .whereType<Map>()
          .map((s) => Season.fromJson(Map<String, dynamic>.from(s)))
          .toList();
    } else if (rawSeasons is String && rawSeasons.isNotEmpty) {
      try {
        final decoded = jsonDecode(rawSeasons);
        if (decoded is List) {
          seasonList = decoded
              .whereType<Map>()
              .map((s) => Season.fromJson(Map<String, dynamic>.from(s)))
              .toList();
        }
      } catch (_) {}
    }

    final isPrem = json['isPremium'] == true ||
        json['is_premium'] == 1 ||
        json['is_premium'] == '1' ||
        json['is_premium'] == true;

    List<String> parseStringList(dynamic raw) {
      if (raw == null) return const [];
      if (raw is List) {
        return raw.map((e) => e.toString()).toList();
      }
      if (raw is String && raw.isNotEmpty) {
        try {
          final decoded = jsonDecode(raw);
          if (decoded is List) {
            return decoded.map((e) => e.toString()).toList();
          }
        } catch (_) {}
        return [raw];
      }
      return const [];
    }

    final rawCreatedAt = json['createdAt'] ?? json['created_at'];
    DateTime? parsedCreatedAt;
    if (rawCreatedAt != null && rawCreatedAt.toString().isNotEmpty) {
      parsedCreatedAt = DateTime.tryParse(rawCreatedAt.toString());
    }

    return ContentItem(
      id: json['id']?.toString() ?? '',
      title: json['title'] as String? ?? '',
      type: parsedType,
      posterUrl: (json['posterUrl'] ?? json['poster_url']) as String? ?? '',
      bannerUrl: (json['bannerUrl'] ?? json['banner_url']) as String? ?? '',
      rating: ((json['rating'] as num?)?.toDouble()) ??
          (double.tryParse(json['rating']?.toString() ?? '') ?? 0.0),
      year: json['year']?.toString() ?? '',
      duration: json['duration']?.toString() ?? '',
      synopsis: json['synopsis'] as String? ?? '',
      genres: parseStringList(json['genres']),
      languages: parseStringList(json['languages']),
      availableQualities: parseStringList(json['availableQualities'] ?? json['available_qualities']),
      seasons: seasonList,
      streamUrl: (json['streamUrl'] ?? json['stream_url']) as String? ?? '',
      isPromoted: json['isPromoted'] == true || json['is_promoted'] == 1 || json['is_promoted'] == '1',
      isTrending: json['isTrending'] == true || json['is_trending'] == 1 || json['is_trending'] == '1',
      isPremium: isPrem,
      channelCategory: (json['channelCategory'] ?? json['channel_category']) as String? ?? '',
      views: ((json['views'] as num?)?.toInt()) ??
          (int.tryParse(json['views']?.toString() ?? '') ?? 0),
      createdAt: parsedCreatedAt,
    );
  }


  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'title': title,
      'type': type.name,
      'posterUrl': posterUrl,
      'bannerUrl': bannerUrl,
      'rating': rating,
      'year': year,
      'duration': duration,
      'synopsis': synopsis,
      'genres': genres,
      'languages': languages,
      'availableQualities': availableQualities,
      'seasons': seasons.map((s) => s.toJson()).toList(),
      'streamUrl': streamUrl,
      'isPromoted': isPromoted,
      'isTrending': isTrending,
      'isPremium': isPremium,
      'is_premium': isPremium ? 1 : 0,
      'channelCategory': channelCategory,
      'views': views,
      'createdAt': createdAt?.toIso8601String(),
    };
  }

  int get viewCount => views;
  bool get isSeries => type == ContentType.series;
  bool get isLive => type == ContentType.liveTv;
  bool get isMovie => type == ContentType.bollywood || type == ContentType.hollywood;

  /// Comparator to sort content items latest one first
  static int compareLatestFirst(ContentItem a, ContentItem b) {
    if (a.createdAt != null && b.createdAt != null) {
      final cmp = b.createdAt!.compareTo(a.createdAt!);
      if (cmp != 0) return cmp;
    }
    final yearA = int.tryParse(a.year) ?? 0;
    final yearB = int.tryParse(b.year) ?? 0;
    if (yearA != yearB) {
      return yearB.compareTo(yearA);
    }
    return b.id.compareTo(a.id);
  }

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ContentItem && runtimeType == other.runtimeType && id == other.id;

  @override
  int get hashCode => id.hashCode;
}
