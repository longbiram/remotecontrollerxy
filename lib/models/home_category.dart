class HomeCategory {
  final int id;
  final String title;
  final String slug;
  final String subtitle;
  final String contentType;
  final String filterGenre;
  final String sortBy;
  final int displayOrder;
  final bool isEnabled;

  const HomeCategory({
    required this.id,
    required this.title,
    required this.slug,
    this.subtitle = '',
    required this.contentType,
    this.filterGenre = '',
    this.sortBy = 'latest',
    this.displayOrder = 0,
    this.isEnabled = true,
  });

  factory HomeCategory.fromJson(Map<String, dynamic> json) {
    return HomeCategory(
      id: (json['id'] as num?)?.toInt() ?? 0,
      title: json['title'] as String? ?? '',
      slug: json['slug'] as String? ?? '',
      subtitle: json['subtitle'] as String? ?? '',
      contentType: json['contentType'] as String? ?? 'all',
      filterGenre: json['filterGenre'] as String? ?? '',
      sortBy: json['sortBy'] as String? ?? 'latest',
      displayOrder: (json['displayOrder'] as num?)?.toInt() ?? 0,
      isEnabled: json['isEnabled'] == true || json['isEnabled'] == 1 || json['isEnabled'] == '1',
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'title': title,
      'slug': slug,
      'subtitle': subtitle,
      'contentType': contentType,
      'filterGenre': filterGenre,
      'sortBy': sortBy,
      'displayOrder': displayOrder,
      'isEnabled': isEnabled,
    };
  }

  HomeCategory copyWith({
    int? id,
    String? title,
    String? slug,
    String? subtitle,
    String? contentType,
    String? filterGenre,
    String? sortBy,
    int? displayOrder,
    bool? isEnabled,
  }) {
    return HomeCategory(
      id: id ?? this.id,
      title: title ?? this.title,
      slug: slug ?? this.slug,
      subtitle: subtitle ?? this.subtitle,
      contentType: contentType ?? this.contentType,
      filterGenre: filterGenre ?? this.filterGenre,
      sortBy: sortBy ?? this.sortBy,
      displayOrder: displayOrder ?? this.displayOrder,
      isEnabled: isEnabled ?? this.isEnabled,
    );
  }

  /// Compact chip label for the horizontal category selector
  String get chipLabel {
    switch (slug) {
      case 'movies':
        return 'Movies';
      case 'bollywood':
        return 'Bollywood';
      case 'hollywood':
        return 'Hollywood';
      case 'series':
        return 'Web Series';
      case 'liveTv':
        return 'Live TV';
      default:
        return title;
    }
  }

  bool get isLiveTv => contentType == 'liveTv' || slug == 'liveTv';

  static List<HomeCategory> get defaultCategories => const [
    HomeCategory(
      id: 1,
      title: 'Latest Movies',
      slug: 'movies',
      subtitle: 'Fresh releases & cinema blockbusters (Latest first)',
      contentType: 'movies',
      filterGenre: '',
      sortBy: 'latest',
      displayOrder: 1,
      isEnabled: true,
    ),
    HomeCategory(
      id: 2,
      title: 'Bollywood Blockbusters',
      slug: 'bollywood',
      subtitle: 'Trending Hindi cinema & premieres (Latest first)',
      contentType: 'bollywood',
      filterGenre: '',
      sortBy: 'latest',
      displayOrder: 2,
      isEnabled: true,
    ),
    HomeCategory(
      id: 3,
      title: 'Hollywood Hits',
      slug: 'hollywood',
      subtitle: 'English & Hindi Dubbed blockbusters (Latest first)',
      contentType: 'hollywood',
      filterGenre: '',
      sortBy: 'latest',
      displayOrder: 3,
      isEnabled: true,
    ),
    HomeCategory(
      id: 4,
      title: 'Binge-Worthy Web Series',
      slug: 'series',
      subtitle: 'Full seasons and latest episodes',
      contentType: 'series',
      filterGenre: '',
      sortBy: 'latest',
      displayOrder: 4,
      isEnabled: true,
    ),
    HomeCategory(
      id: 5,
      title: 'Live TV Channels',
      slug: 'liveTv',
      subtitle: 'Cricket tournaments, 24/7 news & entertainment',
      contentType: 'liveTv',
      filterGenre: '',
      sortBy: 'latest',
      displayOrder: 5,
      isEnabled: true,
    ),
  ];

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is HomeCategory &&
          runtimeType == other.runtimeType &&
          id == other.id &&
          slug == other.slug;

  @override
  int get hashCode => id.hashCode ^ slug.hashCode;
}
