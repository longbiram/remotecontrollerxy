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
      id: json['id'] as String? ?? '',
      episodeNumber: (json['episodeNumber'] as num?)?.toInt() ?? 1,
      title: json['title'] as String? ?? '',
      duration: json['duration'] as String? ?? '',
      description: json['description'] as String? ?? '',
      thumbnail: json['thumbnail'] as String? ?? '',
      streamUrl: json['streamUrl'] as String? ?? '',
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
    final epList = (json['episodes'] as List<dynamic>?)
            ?.map((e) => Episode.fromJson(e as Map<String, dynamic>))
            .toList() ??
        const [];
    return Season(
      seasonNumber: (json['seasonNumber'] as num?)?.toInt() ?? 1,
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

  factory ContentItem.fromJson(Map<String, dynamic> json) {
    final rawType = json['type'] as String? ?? 'bollywood';
    final parsedType = ContentType.values.firstWhere(
      (t) => t.name.toLowerCase() == rawType.toLowerCase(),
      orElse: () => ContentType.bollywood,
    );

    final seasonList = (json['seasons'] as List<dynamic>?)
            ?.map((s) => Season.fromJson(s as Map<String, dynamic>))
            .toList() ??
        const [];

    final isPrem = json['isPremium'] as bool? ??
        (json['is_premium'] == 1 ||
            json['is_premium'] == '1' ||
            json['is_premium'] == true);

    final rawCreatedAt = json['createdAt'] ?? json['created_at'];
    DateTime? parsedCreatedAt;
    if (rawCreatedAt != null && rawCreatedAt.toString().isNotEmpty) {
      parsedCreatedAt = DateTime.tryParse(rawCreatedAt.toString());
    }

    return ContentItem(
      id: json['id'] as String? ?? '',
      title: json['title'] as String? ?? '',
      type: parsedType,
      posterUrl: json['posterUrl'] as String? ?? '',
      bannerUrl: json['bannerUrl'] as String? ?? '',
      rating: (json['rating'] as num?)?.toDouble() ?? 0.0,
      year: json['year'] as String? ?? '',
      duration: json['duration'] as String? ?? '',
      synopsis: json['synopsis'] as String? ?? '',
      genres: (json['genres'] as List<dynamic>?)
              ?.map((e) => e.toString())
              .toList() ??
          const [],
      languages: (json['languages'] as List<dynamic>?)
              ?.map((e) => e.toString())
              .toList() ??
          const [],
      availableQualities: (json['availableQualities'] as List<dynamic>?)
              ?.map((e) => e.toString())
              .toList() ??
          const [],
      seasons: seasonList,
      streamUrl: json['streamUrl'] as String? ?? '',
      isPromoted: json['isPromoted'] as bool? ?? false,
      isTrending: json['isTrending'] as bool? ?? false,
      isPremium: isPrem,
      channelCategory: json['channelCategory'] as String? ?? '',
      views: (json['views'] as num?)?.toInt() ?? 0,
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
