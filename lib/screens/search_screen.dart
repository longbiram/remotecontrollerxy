import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../state/app_state.dart';
import '../widgets/content_card.dart';

class SearchScreen extends StatefulWidget {
  final AppState state;

  const SearchScreen({super.key, required this.state});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final TextEditingController _controller = TextEditingController();
  final List<String> _quickTags = ['Action', 'Comedy', 'Thriller', 'Sci-Fi', 'Sports', 'Drama', 'Hindi'];

  @override
  void initState() {
    super.initState();
    _controller.text = widget.state.searchQuery;
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final results = widget.state.searchResults;

    return Scaffold(
      backgroundColor: AppTheme.background,
      appBar: AppBar(
        title: TextField(
          controller: _controller,
          autofocus: false,
          style: const TextStyle(color: Colors.white, fontSize: 16),
          decoration: InputDecoration(
            hintText: 'Search movies, web series, channels...',
            hintStyle: const TextStyle(color: AppTheme.textMuted, fontSize: 14),
            border: InputBorder.none,
            suffixIcon: _controller.text.isNotEmpty
                ? IconButton(
                    icon: const Icon(Icons.clear, color: AppTheme.textMuted, size: 20),
                    onPressed: () {
                      _controller.clear();
                      widget.state.setSearchQuery('');
                      setState(() {});
                    },
                  )
                : const Icon(Icons.search, color: AppTheme.textMuted),
          ),
          onChanged: (val) {
            widget.state.setSearchQuery(val);
            setState(() {});
          },
        ),
      ),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Quick tag pills
          Container(
            height: 42,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: _quickTags.length,
              separatorBuilder: (c, i) => const SizedBox(width: 8),
              itemBuilder: (context, index) {
                final tag = _quickTags[index];
                return ActionChip(
                  label: Text(tag),
                  backgroundColor: AppTheme.surfaceLight,
                  side: const BorderSide(color: AppTheme.cardBorder, width: 0.5),
                  labelStyle: const TextStyle(color: AppTheme.textSecondary, fontSize: 11),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  onPressed: () {
                    _controller.text = tag;
                    widget.state.setSearchQuery(tag);
                    setState(() {});
                  },
                );
              },
            ),
          ),
          const SizedBox(height: 12),

          // Search Results
          Expanded(
            child: _controller.text.isEmpty
                ? const Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.search_rounded, size: 64, color: AppTheme.surfaceLight),
                        SizedBox(height: 12),
                        Text(
                          'Search your favourite titles',
                          style: TextStyle(color: AppTheme.textMuted, fontSize: 14),
                        ),
                      ],
                    ),
                  )
                : results.isEmpty
                    ? Center(
                        child: Text(
                          'No results found for "${_controller.text}"',
                          style: const TextStyle(color: AppTheme.textMuted),
                        ),
                      )
                    : GridView.builder(
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: 3,
                          childAspectRatio: 0.55,
                          crossAxisSpacing: 10,
                          mainAxisSpacing: 12,
                        ),
                        itemCount: results.length,
                        itemBuilder: (context, index) {
                          return ContentCard(
                            item: results[index],
                            state: widget.state,
                            width: double.infinity,
                            height: 150,
                          );
                        },
                      ),
          ),
        ],
      ),
    );
  }
}
