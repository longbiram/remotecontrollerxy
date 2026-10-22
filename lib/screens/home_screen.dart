import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../theme/app_theme.dart';
import '../state/app_state.dart';
import '../widgets/hero_banner.dart';
import '../widgets/content_shelf.dart';
import '../widgets/continue_watching_shelf.dart';
import '../widgets/cast_sheet.dart';
import 'profile_screen.dart';
import 'category_screen.dart';

class HomeScreen extends StatelessWidget {
  final AppState state;
  final VoidCallback onOpenSearch;
  final VoidCallback onOpenLiveTv;

  const HomeScreen({
    super.key,
    required this.state,
    required this.onOpenSearch,
    required this.onOpenLiveTv,
  });

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: state,
      builder: (context, _) {
        final isCasting = state.isCasting;

        return Scaffold(
          backgroundColor: AppTheme.background,
          body: RefreshIndicator(
            color: AppTheme.primary,
            backgroundColor: AppTheme.surface,
            onRefresh: () => state.refreshCatalog(),
            child: CustomScrollView(
              physics: const AlwaysScrollableScrollPhysics(
                parent: BouncingScrollPhysics(),
              ),
              slivers: [
                // Fixed / Pinned Custom App Bar
                SliverAppBar(
                  pinned: true,
                  floating: false,
                  backgroundColor: AppTheme.background.withValues(alpha: 0.96),
                  elevation: 4,
                  shadowColor: Colors.black45,
                  titleSpacing: 16,
                  title: Row(
                    children: [
                      // Official PikaShow brand logo icon
                      Container(
                        width: 34,
                        height: 34,
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(9),
                          border: Border.all(
                            color: AppTheme.primary.withValues(alpha: 0.6),
                            width: 1.2,
                          ),
                          boxShadow: [
                            BoxShadow(
                              color: AppTheme.primary.withValues(alpha: 0.35),
                              blurRadius: 8,
                              offset: const Offset(0, 2),
                            ),
                          ],
                        ),
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(8),
                          child: Image.asset(
                            'assets/images/pikashow_logo.png',
                            fit: BoxFit.cover,
                            errorBuilder: (context, error, stackTrace) => Container(
                              color: AppTheme.primary,
                              child: const Icon(
                                Icons.play_arrow_rounded,
                                size: 22,
                                color: Colors.white,
                              ),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Text(
                        'PIKA',
                        style: GoogleFonts.outfit(
                          color: AppTheme.textPrimary,
                          fontWeight: FontWeight.w900,
                          fontSize: 21,
                          letterSpacing: 1.5,
                        ),
                      ),
                      ShaderMask(
                        shaderCallback: (bounds) => const LinearGradient(
                          colors: [
                            AppTheme.primary,
                            Color(0xFFFF3B30),
                            AppTheme.accentGold,
                          ],
                        ).createShader(bounds),
                        child: Text(
                          'SHOW',
                          style: GoogleFonts.outfit(
                            color: Colors.white,
                            fontWeight: FontWeight.w900,
                            fontSize: 21,
                            letterSpacing: 1.5,
                          ),
                        ),
                      ),
                    ],
                  ),
                  actions: [
                    // Search Action
                    IconButton(
                      icon: const Icon(Icons.search_rounded, color: Colors.white, size: 24),
                      tooltip: 'Search Movies & TV',
                      onPressed: onOpenSearch,
                    ),

                    // Chromecast Action (interactive with live connection indicator)
                    IconButton(
                      icon: Stack(
                        alignment: Alignment.topRight,
                        children: [
                          Icon(
                            isCasting ? Icons.cast_connected_rounded : Icons.cast_rounded,
                            color: isCasting ? AppTheme.secondary : Colors.white70,
                            size: 24,
                          ),
                          if (isCasting)
                            Container(
                              width: 7,
                              height: 7,
                              decoration: const BoxDecoration(
                                color: AppTheme.secondary,
                                shape: BoxShape.circle,
                              ),
                            ),
                        ],
                      ),
                      tooltip: isCasting
                          ? 'Connected: ${state.connectedCastDevice?.name}'
                          : 'Cast to Smart TV / Chromecast',
                      onPressed: () => CastSheet.show(context, state),
                    ),

                    // Profile Action (next to Chromecast icon)
                    GestureDetector(
                      onTap: () {
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (context) => ProfileScreen(state: state),
                          ),
                        );
                      },
                      child: Container(
                        margin: const EdgeInsets.only(right: 14, left: 4),
                        padding: const EdgeInsets.all(2),
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          gradient: state.hasActiveSubscription
                              ? const LinearGradient(
                                  colors: [Color(0xFFFFB800), Color(0xFFFF8C00)],
                                )
                              : LinearGradient(
                                  colors: [
                                    AppTheme.primary.withValues(alpha: 0.8),
                                    AppTheme.secondary.withValues(alpha: 0.8),
                                  ],
                                ),
                          boxShadow: [
                            BoxShadow(
                              color: (state.hasActiveSubscription
                                      ? const Color(0xFFFFB800)
                                      : AppTheme.primary)
                                  .withValues(alpha: 0.4),
                              blurRadius: 6,
                              offset: const Offset(0, 1),
                            ),
                          ],
                        ),
                        child: CircleAvatar(
                          radius: 15,
                          backgroundColor: AppTheme.surfaceLight,
                          child: state.hasActiveSubscription
                              ? const Icon(
                                  Icons.workspace_premium_rounded,
                                  size: 18,
                                  color: Color(0xFFFFB800),
                                )
                              : Text(
                                  (state.currentUser?.name.isNotEmpty == true)
                                      ? state.currentUser!.name[0].toUpperCase()
                                      : (state.isGuest ? 'G' : 'U'),
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 12,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                        ),
                      ),
                    ),
                  ],
                  bottom: PreferredSize(
                    preferredSize: const Size.fromHeight(48),
                    child: Container(
                      height: 44,
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: ListView(
                        scrollDirection: Axis.horizontal,
                        physics: const BouncingScrollPhysics(),
                        children: [
                          _buildCategoryChip(
                            label: 'All',
                            isSelected: state.homeCategory == 'all',
                            onTap: () => state.setHomeCategory('all'),
                          ),
                          const SizedBox(width: 8),
                          ...state.homeCategories.map((cat) {
                            return Padding(
                              padding: const EdgeInsets.only(right: 8),
                              child: _buildCategoryChip(
                                label: cat.chipLabel,
                                icon: cat.slug == 'movies' || cat.contentType == 'movies'
                                    ? Icons.movie_filter_rounded
                                    : (cat.contentType == 'series' ? Icons.video_collection_rounded : null),
                                isLive: cat.isLiveTv,
                                isSelected: state.homeCategory == cat.slug,
                                onTap: () => state.setHomeCategory(cat.slug),
                              ),
                            );
                          }),
                        ],
                      ),
                    ),
                  ),
                ),

                // Hero Billboard Banner
                if (state.homeCategory == 'all') ...[
                  SliverToBoxAdapter(
                    child: HeroBanner(items: state.promotedItems, state: state),
                  ),
                ] else if (state.homeCategory == 'movies') ...[
                  SliverToBoxAdapter(
                    child: HeroBanner(
                      items: state.latestMovies.take(4).toList(),
                      state: state,
                    ),
                  ),
                ] else if (state.homeCategory == 'bollywood') ...[
                  SliverToBoxAdapter(
                    child: HeroBanner(
                      items: state.bollywoodMovies.take(4).toList(),
                      state: state,
                    ),
                  ),
                ] else if (state.homeCategory == 'hollywood') ...[
                  SliverToBoxAdapter(
                    child: HeroBanner(
                      items: state.hollywoodMovies.take(4).toList(),
                      state: state,
                    ),
                  ),
                ] else if (state.homeCategory == 'series') ...[
                  SliverToBoxAdapter(
                    child: HeroBanner(
                      items: state.seriesItems.take(4).toList(),
                      state: state,
                    ),
                  ),
                ] else if (state.homeCategory != 'liveTv') ...[
                  Builder(
                    builder: (context) {
                      final cat = state.getHomeCategoryBySlug(state.homeCategory);
                      if (cat != null) {
                        final catItems = state.getItemsForCategory(cat);
                        if (catItems.isNotEmpty) {
                          return SliverToBoxAdapter(
                            child: HeroBanner(
                              items: catItems.take(4).toList(),
                              state: state,
                            ),
                          );
                        }
                      }
                      return const SliverToBoxAdapter(child: SizedBox.shrink());
                    },
                  ),
                ],

                // Shelves Content
                SliverToBoxAdapter(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // Resume Watching / Continue Watching Shelf (if user has unfinished content)
                      if (state.continueWatching.isNotEmpty) ...[
                        ContinueWatchingShelf(
                          items: state.continueWatching,
                          state: state,
                          onRemove: (progress) => state.removeWatchProgress(
                            progress.contentId,
                            episodeId: progress.episode?.id,
                          ),
                        ),
                      ],

                      // 1. ALL VIEW: dynamic shelves ordered by admin placing (display_order)
                      if (state.homeCategory == 'all') ...[
                        ...state.homeCategories.map((cat) {
                          if (cat.isLiveTv) {
                            return ContentShelf(
                              title: cat.title,
                              subtitle: cat.subtitle.isNotEmpty
                                  ? cat.subtitle
                                  : 'Cricket, News & 24/7 Entertainment',
                              items: state.liveTvItems,
                              onSeeAll: onOpenLiveTv,
                              state: state,
                            );
                          }
                          final catItems = state.getItemsForCategory(cat);
                          return ContentShelf(
                            title: cat.title,
                            subtitle: cat.subtitle.isNotEmpty ? cat.subtitle : null,
                            items: catItems,
                            onSeeAll: () {
                              Navigator.push(
                                context,
                                MaterialPageRoute(
                                  builder: (context) => CategoryScreen(
                                    title: cat.title,
                                    subtitle: cat.subtitle,
                                    initialItems: catItems,
                                    state: state,
                                  ),
                                ),
                              );
                            },
                            state: state,
                          );
                        }),
                      ],

                      // 2. SEPARATE MOVIES CATEGORY VIEW: Showing all movie data with latest ones first
                      if (state.homeCategory == 'movies') ...[
                        ContentShelf(
                          title: 'All Latest Movies',
                          subtitle: 'All blockbuster titles sorted newest release first',
                          items: state.latestMovies,
                          onSeeAll: () => Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (context) => CategoryScreen(
                                title: 'All Latest Movies',
                                subtitle: 'All blockbuster titles sorted newest release first',
                                initialItems: state.latestMovies,
                                state: state,
                              ),
                            ),
                          ),
                          state: state,
                        ),
                        ContentShelf(
                          title: 'Latest Bollywood Movies',
                          subtitle: 'Hindi action, drama and thrillers (Latest first)',
                          items: state.bollywoodMovies,
                          onSeeAll: () => Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (context) => CategoryScreen(
                                title: 'Latest Bollywood Movies',
                                subtitle: 'Hindi action, drama and thrillers (Latest first)',
                                initialItems: state.bollywoodMovies,
                                state: state,
                              ),
                            ),
                          ),
                          state: state,
                        ),
                        ContentShelf(
                          title: 'Latest Hollywood Movies',
                          subtitle: 'English and Hindi Dubbed premieres (Latest first)',
                          items: state.hollywoodMovies,
                          onSeeAll: () => Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (context) => CategoryScreen(
                                title: 'Latest Hollywood Movies',
                                subtitle: 'English and Hindi Dubbed premieres (Latest first)',
                                initialItems: state.hollywoodMovies,
                                state: state,
                              ),
                            ),
                          ),
                          state: state,
                        ),
                      ],

                      // 3. SEPARATE LIVE TV CATEGORY VIEW: Channels, Sports, News, Entertainment
                      if (state.homeCategory == 'liveTv') ...[
                        _buildLiveTvHeroHeader(context),
                        ContentShelf(
                          title: 'Sports & Cricket Live',
                          subtitle: 'Live tournament streams in 1080p 60fps',
                          items: state.liveTvItems.where((i) =>
                            i.channelCategory.toLowerCase() == 'sports' ||
                            i.genres.any((g) => g.toLowerCase().contains('cricket') || g.toLowerCase().contains('sport'))
                          ).toList(),
                          onSeeAll: onOpenLiveTv,
                          state: state,
                        ),
                        ContentShelf(
                          title: '24/7 Breaking News',
                          subtitle: 'Live newsroom coverage & world headlines',
                          items: state.liveTvItems.where((i) =>
                            i.channelCategory.toLowerCase() == 'news' ||
                            i.genres.any((g) => g.toLowerCase().contains('news'))
                          ).toList(),
                          onSeeAll: onOpenLiveTv,
                          state: state,
                        ),
                        ContentShelf(
                          title: 'Entertainment & Movies Live',
                          subtitle: 'Non-stop movie broadcasts & entertainment channels',
                          items: state.liveTvItems.where((i) =>
                            i.channelCategory.toLowerCase() == 'entertainment' ||
                            i.genres.any((g) => g.toLowerCase().contains('entertainment') || g.toLowerCase().contains('movie'))
                          ).toList(),
                          onSeeAll: onOpenLiveTv,
                          state: state,
                        ),
                        if (state.liveTvItems.isEmpty)
                          const Padding(
                            padding: EdgeInsets.all(32),
                            child: Center(
                              child: Text(
                                'No Live TV channels configured yet.',
                                style: TextStyle(color: AppTheme.textMuted),
                              ),
                            ),
                          ),
                      ],

                      // 4. BOLLYWOOD ONLY VIEW
                      if (state.homeCategory == 'bollywood') ...[
                        ContentShelf(
                          title: 'Bollywood Releases (Latest First)',
                          subtitle: 'Latest releases in 4K & Full HD',
                          items: state.bollywoodMovies,
                          onSeeAll: () => Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (context) => CategoryScreen(
                                title: 'Bollywood Releases',
                                subtitle: 'Latest releases in 4K & Full HD',
                                initialItems: state.bollywoodMovies,
                                state: state,
                              ),
                            ),
                          ),
                          state: state,
                        ),
                        ContentShelf(
                          title: 'Action & Thrillers',
                          subtitle: 'High-octane blockbusters',
                          items: state.bollywoodMovies.where((i) =>
                            i.genres.any((g) => g.toLowerCase().contains('action') || g.toLowerCase().contains('thriller'))
                          ).toList(),
                          onSeeAll: () {
                            final filtered = state.bollywoodMovies.where((i) =>
                              i.genres.any((g) => g.toLowerCase().contains('action') || g.toLowerCase().contains('thriller'))
                            ).toList();
                            Navigator.push(
                              context,
                              MaterialPageRoute(
                                builder: (context) => CategoryScreen(
                                  title: 'Bollywood Action & Thrillers',
                                  subtitle: 'High-octane blockbusters',
                                  initialItems: filtered,
                                  state: state,
                                ),
                              ),
                            );
                          },
                          state: state,
                        ),
                      ],

                      // 5. HOLLYWOOD ONLY VIEW
                      if (state.homeCategory == 'hollywood') ...[
                        ContentShelf(
                          title: 'Hollywood Hits (Latest First)',
                          subtitle: 'English & Hindi Dubbed releases',
                          items: state.hollywoodMovies,
                          onSeeAll: () => Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (context) => CategoryScreen(
                                title: 'Hollywood Hits',
                                subtitle: 'English & Hindi Dubbed releases',
                                initialItems: state.hollywoodMovies,
                                state: state,
                              ),
                            ),
                          ),
                          state: state,
                        ),
                        ContentShelf(
                          title: 'Sci-Fi & Action',
                          subtitle: 'Epic visual spectacles',
                          items: state.hollywoodMovies.where((i) =>
                            i.genres.any((g) => g.toLowerCase().contains('sci-fi') || g.toLowerCase().contains('action'))
                          ).toList(),
                          onSeeAll: () {
                            final filtered = state.hollywoodMovies.where((i) =>
                              i.genres.any((g) => g.toLowerCase().contains('sci-fi') || g.toLowerCase().contains('action'))
                            ).toList();
                            Navigator.push(
                              context,
                              MaterialPageRoute(
                                builder: (context) => CategoryScreen(
                                  title: 'Hollywood Sci-Fi & Action',
                                  subtitle: 'Epic visual spectacles',
                                  initialItems: filtered,
                                  state: state,
                                ),
                              ),
                            );
                          },
                          state: state,
                        ),
                      ],

                      // 6. SERIES ONLY VIEW
                      if (state.homeCategory == 'series') ...[
                        ContentShelf(
                          title: 'Binge-Worthy Web Series',
                          subtitle: 'Full seasons and latest episodes',
                          items: state.seriesItems,
                          onSeeAll: () => Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (context) => CategoryScreen(
                                title: 'Binge-Worthy Web Series',
                                subtitle: 'Full seasons and latest episodes',
                                initialItems: state.seriesItems,
                                state: state,
                              ),
                            ),
                          ),
                          state: state,
                        ),
                      ],

                      // 7. CUSTOM CATEGORIES VIEW (Configured by admin in backend)
                      if (state.homeCategory != 'all' &&
                          state.homeCategory != 'movies' &&
                          state.homeCategory != 'liveTv' &&
                          state.homeCategory != 'bollywood' &&
                          state.homeCategory != 'hollywood' &&
                          state.homeCategory != 'series') ...[
                        Builder(
                          builder: (context) {
                            final cat = state.getHomeCategoryBySlug(state.homeCategory);
                            if (cat == null) return const SizedBox.shrink();
                            final catItems = state.getItemsForCategory(cat);
                            if (catItems.isEmpty) {
                              return Padding(
                                padding: const EdgeInsets.all(40),
                                child: Center(
                                  child: Column(
                                    children: [
                                      const Icon(Icons.movie_filter_outlined, size: 48, color: AppTheme.textMuted),
                                      const SizedBox(height: 12),
                                      Text(
                                        'No titles currently available in ${cat.title}.',
                                        style: const TextStyle(color: AppTheme.textMuted),
                                      ),
                                    ],
                                  ),
                                ),
                              );
                            }

                            return Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                ContentShelf(
                                  title: cat.title,
                                  subtitle: cat.subtitle.isNotEmpty
                                      ? cat.subtitle
                                      : 'All titles in ${cat.title}',
                                  items: catItems,
                                  onSeeAll: () => Navigator.push(
                                    context,
                                    MaterialPageRoute(
                                      builder: (context) => CategoryScreen(
                                        title: cat.title,
                                        subtitle: cat.subtitle,
                                        initialItems: catItems,
                                        state: state,
                                      ),
                                    ),
                                  ),
                                  state: state,
                                ),
                              ],
                            );
                          },
                        ),
                      ],

                      const SizedBox(height: 40),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildLiveTvHeroHeader(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 16, 16, 8),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(18),
        gradient: const LinearGradient(
          colors: [
            Color(0xFF1F1216),
            AppTheme.surface,
            Color(0xFF141926),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(
          color: AppTheme.primary.withValues(alpha: 0.35),
          width: 1.2,
        ),
        boxShadow: [
          BoxShadow(
            color: AppTheme.primary.withValues(alpha: 0.15),
            blurRadius: 16,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      width: 8,
                      height: 8,
                      decoration: const BoxDecoration(
                        color: Color(0xFFFF3B30),
                        shape: BoxShape.circle,
                        boxShadow: [
                          BoxShadow(
                            color: Color(0xFFFF3B30),
                            blurRadius: 6,
                            spreadRadius: 1,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    const Text(
                      'LIVE BROADCASTS',
                      style: TextStyle(
                        color: Color(0xFFFF3B30),
                        fontSize: 11,
                        fontWeight: FontWeight.w900,
                        letterSpacing: 1.2,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                const Text(
                  'Live TV Channels',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(height: 4),
                const Text(
                  'Stream cricket tournaments, 24/7 breaking news and entertainment with low-latency CDN feeds.',
                  style: TextStyle(
                    color: AppTheme.textMuted,
                    fontSize: 12,
                    height: 1.4,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 14),
          ElevatedButton.icon(
            onPressed: onOpenLiveTv,
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.primary,
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
              elevation: 4,
            ),
            icon: const Icon(Icons.tv_rounded, size: 16),
            label: const Text(
              'TV Guide',
              style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildCategoryChip({
    required String label,
    required bool isSelected,
    required VoidCallback onTap,
    IconData? icon,
    bool isLive = false,
  }) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: isSelected ? AppTheme.primary : AppTheme.surface,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(
            color: isSelected ? AppTheme.primary : AppTheme.cardBorder,
            width: 1,
          ),
          boxShadow: isSelected
              ? const [
                  BoxShadow(
                    color: AppTheme.primaryGlow,
                    blurRadius: 8,
                    offset: Offset(0, 2),
                  ),
                ]
              : null,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (isLive) ...[
              Container(
                width: 6,
                height: 6,
                margin: const EdgeInsets.only(right: 6),
                decoration: BoxDecoration(
                  color: isSelected ? Colors.white : const Color(0xFFFF3B30),
                  shape: BoxShape.circle,
                ),
              ),
            ] else if (icon != null) ...[
              Icon(
                icon,
                size: 14,
                color: isSelected ? Colors.white : AppTheme.textSecondary,
              ),
              const SizedBox(width: 5),
            ],
            Text(
              label,
              style: TextStyle(
                color: isSelected ? Colors.white : AppTheme.textSecondary,
                fontSize: 12,
                fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
