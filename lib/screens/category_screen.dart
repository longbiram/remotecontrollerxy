import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../theme/app_theme.dart';
import '../state/app_state.dart';
import '../models/content_item.dart';
import '../widgets/content_card.dart';

class CategoryScreen extends StatefulWidget {
  final String title;
  final String? subtitle;
  final List<ContentItem> initialItems;
  final AppState? state;

  const CategoryScreen({
    super.key,
    required this.title,
    this.subtitle,
    required this.initialItems,
    this.state,
  });

  @override
  State<CategoryScreen> createState() => _CategoryScreenState();
}

class _CategoryScreenState extends State<CategoryScreen> {
  final TextEditingController _searchController = TextEditingController();
  String _activeSort = 'latest'; // 'latest', 'rating', 'views', 'alpha'
  String _selectedGenre = 'All';

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  List<String> get _availableGenres {
    final Set<String> genres = {'All'};
    for (final item in widget.initialItems) {
      genres.addAll(item.genres);
    }
    return genres.toList();
  }

  List<ContentItem> get _filteredAndSortedItems {
    List<ContentItem> items = List.from(widget.initialItems);

    // Filter by genre
    if (_selectedGenre != 'All') {
      final sg = _selectedGenre.toLowerCase();
      items = items.where((i) => i.genres.any((g) => g.toLowerCase() == sg)).toList();
    }

    // Filter by search query
    final query = _searchController.text.trim().toLowerCase();
    if (query.isNotEmpty) {
      items = items.where((i) =>
        i.title.toLowerCase().contains(query) ||
        i.genres.any((g) => g.toLowerCase().contains(query)) ||
        i.languages.any((l) => l.toLowerCase().contains(query))
      ).toList();
    }

    // Sort
    switch (_activeSort) {
      case 'rating':
        items.sort((a, b) => b.rating.compareTo(a.rating));
        break;
      case 'views':
        items.sort((a, b) => b.viewCount.compareTo(a.viewCount));
        break;
      case 'alpha':
        items.sort((a, b) => a.title.toLowerCase().compareTo(b.title.toLowerCase()));
        break;
      case 'latest':
      default:
        items.sort(ContentItem.compareLatestFirst);
        break;
    }

    return items;
  }

  @override
  Widget build(BuildContext context) {
    final displayItems = _filteredAndSortedItems;
    final genres = _availableGenres;

    return Scaffold(
      backgroundColor: AppTheme.background,
      appBar: AppBar(
        backgroundColor: AppTheme.background.withValues(alpha: 0.95),
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white, size: 20),
          onPressed: () => Navigator.pop(context),
        ),
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              widget.title,
              style: GoogleFonts.outfit(
                color: Colors.white,
                fontWeight: FontWeight.bold,
                fontSize: 18,
              ),
            ),
            if (widget.subtitle != null && widget.subtitle!.isNotEmpty)
              Text(
                widget.subtitle!,
                style: const TextStyle(
                  color: AppTheme.textMuted,
                  fontSize: 11,
                  fontWeight: FontWeight.normal,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
          ],
        ),
        actions: [
          Container(
            margin: const EdgeInsets.only(right: 16),
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: AppTheme.surfaceLight,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppTheme.cardBorder, width: 0.8),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.layers_rounded, color: AppTheme.primary, size: 14),
                const SizedBox(width: 4),
                Text(
                  '${displayItems.length} Titles',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          // In-category search bar
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 6),
            child: Container(
              height: 42,
              decoration: BoxDecoration(
                color: AppTheme.surface,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppTheme.cardBorder, width: 0.8),
              ),
              child: TextField(
                controller: _searchController,
                style: const TextStyle(color: Colors.white, fontSize: 13),
                decoration: InputDecoration(
                  hintText: 'Filter in ${widget.title}...',
                  hintStyle: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                  prefixIcon: const Icon(Icons.search_rounded, color: AppTheme.textMuted, size: 18),
                  suffixIcon: _searchController.text.isNotEmpty
                      ? IconButton(
                          icon: const Icon(Icons.clear, color: AppTheme.textMuted, size: 16),
                          onPressed: () {
                            _searchController.clear();
                            setState(() {});
                          },
                        )
                      : null,
                  border: InputBorder.none,
                  contentPadding: const EdgeInsets.symmetric(vertical: 10),
                ),
                onChanged: (_) => setState(() {}),
              ),
            ),
          ),

          // Sorting and Genre Filter Bar
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            physics: const BouncingScrollPhysics(),
            child: Row(
              children: [
                // Sort pill: Latest
                _buildSortChip('Latest First', 'latest', Icons.schedule_rounded),
                const SizedBox(width: 6),
                // Sort pill: Rating
                _buildSortChip('Top Rated', 'rating', Icons.star_rounded),
                const SizedBox(width: 6),
                // Sort pill: Views
                _buildSortChip('Most Viewed', 'views', Icons.trending_up_rounded),
                const SizedBox(width: 6),
                // Sort pill: A-Z
                _buildSortChip('A - Z', 'alpha', Icons.sort_by_alpha_rounded),

                if (genres.length > 2) ...[
                  Container(
                    height: 20,
                    width: 1,
                    margin: const EdgeInsets.symmetric(horizontal: 10),
                    color: AppTheme.cardBorder,
                  ),
                  // Genre chips
                  ...genres.map((g) => Padding(
                    padding: const EdgeInsets.only(right: 6),
                    child: _buildGenreChip(g),
                  )),
                ],
              ],
            ),
          ),

          const SizedBox(height: 4),

          // Content Grid
          Expanded(
            child: displayItems.isEmpty
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(32),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Container(
                            width: 64,
                            height: 64,
                            decoration: BoxDecoration(
                              color: AppTheme.surfaceLight,
                              shape: BoxShape.circle,
                            ),
                            child: const Icon(
                              Icons.movie_filter_outlined,
                              size: 32,
                              color: AppTheme.textMuted,
                            ),
                          ),
                          const SizedBox(height: 16),
                          Text(
                            _searchController.text.isNotEmpty
                                ? 'No titles matched "${_searchController.text}"'
                                : 'No content available in this category.',
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                              color: AppTheme.textMuted,
                              fontSize: 14,
                            ),
                          ),
                          if (_searchController.text.isNotEmpty || _selectedGenre != 'All') ...[
                            const SizedBox(height: 12),
                            TextButton.icon(
                              onPressed: () {
                                _searchController.clear();
                                _selectedGenre = 'All';
                                setState(() {});
                              },
                              icon: const Icon(Icons.refresh_rounded, size: 16),
                              label: const Text('Reset Filters'),
                              style: TextButton.styleFrom(
                                foregroundColor: AppTheme.primary,
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  )
                : LayoutBuilder(
                    builder: (context, constraints) {
                      final crossAxisCount = constraints.maxWidth > 700 ? 5 : 3;
                      final availableWidth = constraints.maxWidth - 32 - (crossAxisCount - 1) * 10;
                      final cardWidth = availableWidth / crossAxisCount;
                      final cardHeight = cardWidth * 1.45;

                      return GridView.builder(
                        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                        physics: const BouncingScrollPhysics(),
                        gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: crossAxisCount,
                          childAspectRatio: cardWidth / (cardHeight + 54),
                          crossAxisSpacing: 10,
                          mainAxisSpacing: 14,
                        ),
                        itemCount: displayItems.length,
                        itemBuilder: (context, index) {
                          return ContentCard(
                            item: displayItems[index],
                            state: widget.state,
                            width: double.infinity,
                            height: cardHeight,
                            margin: EdgeInsets.zero,
                          );
                        },
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }

  Widget _buildSortChip(String label, String value, IconData icon) {
    final isSelected = _activeSort == value;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: () => setState(() => _activeSort = value),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: isSelected ? AppTheme.primary : AppTheme.surface,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: isSelected ? AppTheme.primary : AppTheme.cardBorder,
            width: 0.8,
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              icon,
              size: 13,
              color: isSelected ? Colors.white : AppTheme.textMuted,
            ),
            const SizedBox(width: 4),
            Text(
              label,
              style: TextStyle(
                color: isSelected ? Colors.white : AppTheme.textSecondary,
                fontSize: 11,
                fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildGenreChip(String genre) {
    final isSelected = _selectedGenre == genre;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: () => setState(() => _selectedGenre = genre),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: isSelected ? AppTheme.secondary.withValues(alpha: 0.25) : AppTheme.surface,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: isSelected ? AppTheme.secondary : AppTheme.cardBorder,
            width: 0.8,
          ),
        ),
        child: Text(
          genre,
          style: TextStyle(
            color: isSelected ? AppTheme.secondary : AppTheme.textMuted,
            fontSize: 11,
            fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
          ),
        ),
      ),
    );
  }
}
