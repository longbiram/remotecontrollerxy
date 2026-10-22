import 'package:flutter/material.dart';
import '../models/content_item.dart';
import '../theme/app_theme.dart';
import '../state/app_state.dart';
import '../widgets/content_shelf.dart';
import '../services/api_service.dart';
import 'player_screen.dart';
import 'profile_screen.dart';

class DetailScreen extends StatefulWidget {
  final ContentItem item;
  final AppState? state;

  const DetailScreen({super.key, required this.item, this.state});

  @override
  State<DetailScreen> createState() => _DetailScreenState();
}

class _DetailScreenState extends State<DetailScreen> {
  late ContentItem _item;
  int _selectedSeasonIndex = 0;
  List<ContentItem> _apiCatalog = [];

  @override
  void initState() {
    super.initState();
    _item = widget.item;
    _refreshDetail();
    if (widget.state == null || widget.state!.allContent.isEmpty) {
      _loadCatalog();
    }
  }

  Future<void> _loadCatalog() async {
    final catalog = await ApiService.fetchCatalog(fallbackToMock: false);
    if (catalog.isNotEmpty && mounted) {
      setState(() {
        _apiCatalog = catalog;
      });
    }
  }

  Future<void> _refreshDetail() async {
    final fresh = await ApiService.fetchDetail(widget.item.id);
    if (fresh != null && mounted) {
      setState(() {
        _item = fresh;
      });
    }
  }

  Future<void> _handleToggleFavourite(ContentItem item) async {
    final state = widget.state;
    if (state == null) return;

    final wasSaved = state.isFavourite(item.id);
    await state.toggleFavourite(item);
    if (!mounted) return;

    ScaffoldMessenger.of(context).clearSnackBars();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Row(
          children: [
            Icon(
              wasSaved ? Icons.bookmark_remove_rounded : Icons.bookmark_added_rounded,
              color: wasSaved ? Colors.white70 : AppTheme.primary,
              size: 20,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                wasSaved
                    ? 'Removed "${item.title}" from Saved Favourites'
                    : 'Saved "${item.title}" to Favourites',
                style: const TextStyle(fontWeight: FontWeight.w500),
              ),
            ),
          ],
        ),
        backgroundColor: AppTheme.surfaceLight,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        duration: const Duration(seconds: 2),
      ),
    );
  }

  void _checkAndPlay({Episode? episode}) {
    final isPremium = _item.isPremium;
    final hasVip = widget.state?.hasActiveSubscription ?? false;

    if (isPremium && !hasVip) {
      _showVipPaywallDialog();
      return;
    }

    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (context) => PlayerScreen(
          item: _item,
          initialEpisode: episode,
          state: widget.state,
        ),
      ),
    );
  }

  void _showVipPaywallDialog() {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppTheme.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Row(
          children: [
            Icon(Icons.workspace_premium_rounded, color: AppTheme.accentGold, size: 24),
            SizedBox(width: 8),
            Text(
              'VIP Subscription Required',
              style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold),
            ),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '"${_item.title}" is exclusive VIP content.',
              style: const TextStyle(color: AppTheme.secondary, fontWeight: FontWeight.bold, fontSize: 14),
            ),
            const SizedBox(height: 10),
            const Text(
              'Only active subscribers can stream this premium title. Upgrade now to stream in 4K Ultra HD with no ads!',
              style: TextStyle(color: AppTheme.textSecondary, fontSize: 13, height: 1.4),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel', style: TextStyle(color: AppTheme.textMuted)),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.accentGold,
              foregroundColor: Colors.black,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () {
              Navigator.pop(ctx);
              if (widget.state != null) {
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (context) => ProfileScreen(state: widget.state!),
                  ),
                );
              }
            },
            icon: const Icon(Icons.workspace_premium_rounded, size: 16),
            label: const Text('View VIP Plans', style: TextStyle(fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );
  }

  List<ContentItem> _getMoreLikeThis(ContentItem currentItem) {
    // Collect catalog items from real sources (AppState or backend API)
    final allCandidates = <ContentItem>[];
    final seenIds = <String>{currentItem.id};

    if (widget.state != null && widget.state!.allContent.isNotEmpty) {
      for (final item in widget.state!.allContent) {
        if (seenIds.add(item.id)) {
          allCandidates.add(item);
        }
      }
    }
    if (_apiCatalog.isNotEmpty) {
      for (final item in _apiCatalog) {
        if (seenIds.add(item.id)) {
          allCandidates.add(item);
        }
      }
    }

    final targetGenres = currentItem.genres
        .map((g) => g.trim().toLowerCase())
        .where((g) => g.isNotEmpty)
        .toSet();

    final targetLangs = currentItem.languages
        .map((l) => l.trim().toLowerCase())
        .where((l) => l.isNotEmpty)
        .toSet();

    final targetChannelCategory = currentItem.channelCategory.trim().toLowerCase();

    bool langMatches(String candidateLang) {
      final cLang = candidateLang.trim().toLowerCase();
      if (targetLangs.contains(cLang)) return true;
      for (final tLang in targetLangs) {
        if (cLang.contains(tLang) || tLang.contains(cLang)) return true;
      }
      return false;
    }

    final scored = <({ContentItem item, int score})>[];

    for (final candidate in allCandidates) {
      // 1. Same Category match:
      // - Same explicit ContentType (e.g. both bollywood, both series, etc.)
      // - Same broader category (both are movies, or both are series, or both are liveTv)
      // - Or for liveTv, same channelCategory (e.g. Sports, News, Entertainment)
      final bool exactTypeMatch = candidate.type == currentItem.type;
      final bool broadCategoryMatch = (currentItem.isMovie && candidate.isMovie) ||
          (currentItem.isSeries && candidate.isSeries) ||
          (currentItem.isLive && candidate.isLive);
      final bool channelCategoryMatch = targetChannelCategory.isNotEmpty &&
          candidate.channelCategory.trim().toLowerCase() == targetChannelCategory;

      final bool categoryMatch = exactTypeMatch || broadCategoryMatch || channelCategoryMatch;

      // 2. Same Genre match:
      final int matchingGenresCount = candidate.genres
          .where((g) => targetGenres.contains(g.trim().toLowerCase()))
          .length;
      final bool genreMatch = matchingGenresCount > 0;

      // 3. Same Audio Language match:
      final int matchingLangsCount = candidate.languages
          .where((l) => langMatches(l))
          .length;
      final bool languageMatch = matchingLangsCount > 0;

      // Filter: Must be from the same genres, Category or Audio Languages
      if (!categoryMatch && !genreMatch && !languageMatch) {
        continue;
      }

      // Compute relevance score:
      int score = 0;
      if (exactTypeMatch) {
        score += 5;
      } else if (broadCategoryMatch) {
        score += 3;
      }
      if (channelCategoryMatch) {
        score += 4;
      }
      score += matchingGenresCount * 4;
      score += matchingLangsCount * 3;

      scored.add((item: candidate, score: score));
    }

    // Sort by highest relevance score first, then by rating as tie-breaker
    scored.sort((a, b) {
      final scoreDiff = b.score.compareTo(a.score);
      if (scoreDiff != 0) return scoreDiff;
      return b.item.rating.compareTo(a.item.rating);
    });

    return scored.map((s) => s.item).take(10).toList();
  }

  @override
  Widget build(BuildContext context) {
    final item = _item;
    // Find related items by same genres, category or audio languages
    final related = _getMoreLikeThis(item);

    return Scaffold(
      backgroundColor: AppTheme.background,
      body: CustomScrollView(
        physics: const BouncingScrollPhysics(),
        slivers: [
          // Collapsible Backdrop App Bar
          SliverAppBar(
            expandedHeight: 320,
            pinned: true,
            backgroundColor: AppTheme.background,
            leading: IconButton(
              icon: Container(
                padding: const EdgeInsets.all(6),
                decoration: BoxDecoration(
                  color: Colors.black54,
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.arrow_back, color: Colors.white, size: 20),
              ),
              onPressed: () => Navigator.pop(context),
            ),
            flexibleSpace: FlexibleSpaceBar(
              background: Stack(
                fit: StackFit.expand,
                children: [
                  Image.network(
                    item.bannerUrl,
                    fit: BoxFit.cover,
                    errorBuilder: (context, error, stackTrace) => Container(
                      color: AppTheme.surface,
                      child: const Center(
                        child: Icon(Icons.movie, size: 64, color: AppTheme.textMuted),
                      ),
                    ),
                  ),
                  Container(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                        colors: [
                          Colors.transparent,
                          AppTheme.background.withValues(alpha: 0.3),
                          AppTheme.background.withValues(alpha: 0.9),
                          AppTheme.background,
                        ],
                        stops: const [0.0, 0.5, 0.85, 1.0],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),

          // Main Details Content
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Title and Type Pill
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            if (item.isPremium) ...[
                              Container(
                                margin: const EdgeInsets.only(bottom: 8),
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(
                                  gradient: const LinearGradient(
                                    colors: [Color(0xFFFFB800), Color(0xFFFF8C00)],
                                  ),
                                  borderRadius: BorderRadius.circular(6),
                                  boxShadow: [
                                    BoxShadow(
                                      color: const Color(0xFFFF8C00).withValues(alpha: 0.4),
                                      blurRadius: 8,
                                      offset: const Offset(0, 2),
                                    ),
                                  ],
                                ),
                                child: const Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Icon(Icons.workspace_premium_rounded, size: 14, color: Colors.black),
                                    SizedBox(width: 4),
                                    Text(
                                      'PREMIUM VIP',
                                      style: TextStyle(
                                        color: Colors.black,
                                        fontSize: 11,
                                        fontWeight: FontWeight.w900,
                                        letterSpacing: 0.8,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                            Text(
                              item.title,
                              style: const TextStyle(
                                color: AppTheme.textPrimary,
                                fontSize: 26,
                                fontWeight: FontWeight.bold,
                                letterSpacing: -0.5,
                              ),
                            ),
                            const SizedBox(height: 6),
                            Row(
                              children: [
                                Text(
                                  item.year,
                                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                                ),
                                const SizedBox(width: 8),
                                const Text('•', style: TextStyle(color: AppTheme.textMuted)),
                                const SizedBox(width: 8),
                                Text(
                                  item.duration,
                                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                                ),
                                const SizedBox(width: 8),
                                const Text('•', style: TextStyle(color: AppTheme.textMuted)),
                                const SizedBox(width: 8),
                                Row(
                                  children: [
                                    const Icon(Icons.star_rounded, color: AppTheme.accentGold, size: 16),
                                    const SizedBox(width: 2),
                                    Text(
                                      item.rating.toString(),
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 13,
                                        fontWeight: FontWeight.bold,
                                      ),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),

                  // Genre Chips
                  Wrap(
                    spacing: 8,
                    runSpacing: 6,
                    children: item.genres.map((g) {
                      return Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: AppTheme.surfaceLight,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: AppTheme.cardBorder, width: 0.5),
                        ),
                        child: Text(
                          g,
                          style: const TextStyle(color: AppTheme.textSecondary, fontSize: 11),
                        ),
                      );
                    }).toList(),
                  ),
                  const SizedBox(height: 20),

                  // Action Buttons Row
                  Row(
                    children: [
                      Expanded(
                        child: ElevatedButton.icon(
                          onPressed: () => _checkAndPlay(),
                          icon: const Icon(Icons.play_arrow_rounded, color: Colors.white, size: 24),
                          label: Text(
                            item.isLive ? 'Watch Live Stream' : 'Play',
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.bold,
                              fontSize: 15,
                            ),
                          ),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppTheme.primary,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(10),
                            ),
                            elevation: 6,
                            shadowColor: AppTheme.primaryGlow,
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      // My List / Bookmark / Saved Button
                      widget.state != null
                          ? ListenableBuilder(
                              listenable: widget.state!,
                              builder: (context, _) {
                                final isSaved = widget.state!.isFavourite(item.id);
                                return Container(
                                  decoration: BoxDecoration(
                                    color: isSaved
                                        ? AppTheme.primary.withValues(alpha: 0.18)
                                        : AppTheme.surfaceLight,
                                    borderRadius: BorderRadius.circular(10),
                                    border: Border.all(
                                      color: isSaved ? AppTheme.primary : AppTheme.cardBorder,
                                      width: isSaved ? 1.5 : 1.0,
                                    ),
                                  ),
                                  child: IconButton(
                                    tooltip: isSaved ? 'Remove from Saved' : 'Save to Favourites',
                                    icon: Icon(
                                      isSaved ? Icons.bookmark_rounded : Icons.bookmark_add_outlined,
                                      color: isSaved ? AppTheme.primary : Colors.white,
                                    ),
                                    onPressed: () => _handleToggleFavourite(item),
                                  ),
                                );
                              },
                            )
                          : Container(
                              decoration: BoxDecoration(
                                color: AppTheme.surfaceLight,
                                borderRadius: BorderRadius.circular(10),
                                border: Border.all(color: AppTheme.cardBorder),
                              ),
                              child: IconButton(
                                tooltip: 'Save to Favourites',
                                icon: const Icon(Icons.bookmark_add_outlined, color: Colors.white),
                                onPressed: () {
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    SnackBar(
                                      content: Text('Saved "${item.title}" to Favourites'),
                                      backgroundColor: AppTheme.surfaceLight,
                                      duration: const Duration(seconds: 2),
                                    ),
                                  );
                                },
                              ),
                            ),
                      const SizedBox(width: 12),
                      // Download Button
                      Container(
                        decoration: BoxDecoration(
                          color: AppTheme.surfaceLight,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: AppTheme.cardBorder),
                        ),
                        child: IconButton(
                          icon: const Icon(Icons.download_rounded, color: Colors.white),
                          onPressed: () {
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(
                                content: Text('Download started in 1080p Full HD...'),
                                backgroundColor: AppTheme.surfaceLight,
                                duration: Duration(seconds: 2),
                              ),
                            );
                          },
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 24),

                  // Synopsis Header & Body
                  const Text(
                    'Synopsis',
                    style: TextStyle(
                      color: AppTheme.textPrimary,
                      fontSize: 16,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    item.synopsis,
                    style: const TextStyle(
                      color: AppTheme.textSecondary,
                      fontSize: 14,
                      height: 1.5,
                    ),
                  ),
                  const SizedBox(height: 18),

                  // Audio Languages & Quality tags
                  Container(
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: AppTheme.surface,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: AppTheme.cardBorder, width: 0.5),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            const Icon(Icons.language, color: AppTheme.secondary, size: 16),
                            const SizedBox(width: 6),
                            const Text(
                              'Languages: ',
                              style: TextStyle(color: AppTheme.textPrimary, fontWeight: FontWeight.bold, fontSize: 12),
                            ),
                            Expanded(
                              child: Text(
                                item.languages.join(', '),
                                style: const TextStyle(color: AppTheme.textSecondary, fontSize: 12),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            const Icon(Icons.video_settings, color: AppTheme.secondary, size: 16),
                            const SizedBox(width: 6),
                            const Text(
                              'Qualities: ',
                              style: TextStyle(color: AppTheme.textPrimary, fontWeight: FontWeight.bold, fontSize: 12),
                            ),
                            Expanded(
                              child: Text(
                                item.availableQualities.join(' • '),
                                style: const TextStyle(color: AppTheme.textSecondary, fontSize: 12),
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 24),

                  // Season and Episode List (if Series)
                  if (item.isSeries && item.seasons.isNotEmpty) ...[
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Episodes',
                          style: TextStyle(
                            color: AppTheme.textPrimary,
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        if (item.seasons.length > 1)
                          DropdownButton<int>(
                            value: _selectedSeasonIndex,
                            dropdownColor: AppTheme.surface,
                            underline: const SizedBox.shrink(),
                            icon: const Icon(Icons.arrow_drop_down, color: AppTheme.secondary),
                            items: List.generate(
                              item.seasons.length,
                              (idx) => DropdownMenuItem(
                                value: idx,
                                child: Text(
                                  item.seasons[idx].title,
                                  style: const TextStyle(color: Colors.white, fontSize: 13),
                                ),
                              ),
                            ),
                            onChanged: (val) {
                              if (val != null) {
                                setState(() {
                                  _selectedSeasonIndex = val;
                                });
                              }
                            },
                          ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    ...item.seasons[_selectedSeasonIndex].episodes.map((ep) {
                      return Container(
                        margin: const EdgeInsets.only(bottom: 12),
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: AppTheme.surface,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: AppTheme.cardBorder, width: 0.5),
                        ),
                        child: InkWell(
                          onTap: () => _checkAndPlay(episode: ep),
                          child: Row(
                            children: [
                              ClipRRect(
                                borderRadius: BorderRadius.circular(8),
                                child: Stack(
                                  alignment: Alignment.center,
                                  children: [
                                    Image.network(
                                      ep.thumbnail,
                                      width: 100,
                                      height: 60,
                                      fit: BoxFit.cover,
                                      errorBuilder: (c, e, s) => Container(
                                        width: 100,
                                        height: 60,
                                        color: AppTheme.surfaceLight,
                                        child: const Icon(Icons.movie, color: AppTheme.textMuted),
                                      ),
                                    ),
                                    Container(
                                      padding: const EdgeInsets.all(4),
                                      decoration: const BoxDecoration(
                                        color: Colors.black54,
                                        shape: BoxShape.circle,
                                      ),
                                      child: const Icon(Icons.play_arrow, color: Colors.white, size: 20),
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      '${ep.episodeNumber}. ${ep.title}',
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        color: AppTheme.textPrimary,
                                        fontWeight: FontWeight.bold,
                                        fontSize: 14,
                                      ),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      ep.duration,
                                      style: const TextStyle(color: AppTheme.textMuted, fontSize: 11),
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      ep.description,
                                      maxLines: 2,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        color: AppTheme.textSecondary,
                                        fontSize: 12,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    }),
                    const SizedBox(height: 24),
                  ],

                  // Related Recommendations Shelf
                  if (related.isNotEmpty) ...[
                    ContentShelf(
                      title: 'More Like This',
                      subtitle: 'Similar titles by genre, category & audio languages',
                      items: related,
                      state: widget.state,
                    ),
                    const SizedBox(height: 32),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
