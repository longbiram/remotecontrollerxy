import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../state/app_state.dart';
import '../widgets/content_card.dart';
import '../services/app_update_service.dart';
import '../widgets/app_update_dialog.dart';
import 'login_screen.dart';

class LibraryScreen extends StatefulWidget {
  final AppState state;

  const LibraryScreen({super.key, required this.state});

  @override
  State<LibraryScreen> createState() => _LibraryScreenState();
}

class _LibraryScreenState extends State<LibraryScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _tabController.addListener(() {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  void _confirmClearFavourites(BuildContext context) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppTheme.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('Clear All Saved Titles?', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
        content: const Text(
          'Are you sure you want to remove all titles from your Saved Favourites list?',
          style: TextStyle(color: AppTheme.textSecondary, fontSize: 13, height: 1.4),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel', style: TextStyle(color: AppTheme.textMuted)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFFEF4444),
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () async {
              Navigator.pop(ctx);
              await widget.state.clearAllFavourites();
            },
            child: const Text('Clear All'),
          ),
        ],
      ),
    );
  }

  void _confirmClearHistory(BuildContext context) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppTheme.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('Clear Watch History?', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
        content: const Text(
          'Are you sure you want to clear your entire watch history?',
          style: TextStyle(color: AppTheme.textSecondary, fontSize: 13, height: 1.4),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel', style: TextStyle(color: AppTheme.textMuted)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFFEF4444),
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () async {
              Navigator.pop(ctx);
              await widget.state.clearWatchHistory();
            },
            child: const Text('Clear History'),
          ),
        ],
      ),
    );
  }

  void _showAccountDialog(BuildContext context) {
    final user = widget.state.currentUser;

    showModalBottomSheet(
      context: context,
      backgroundColor: AppTheme.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (context) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppTheme.cardBorder,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                const SizedBox(height: 20),
                // Avatar
                CircleAvatar(
                  radius: 36,
                  backgroundColor: AppTheme.primary.withValues(alpha: 0.2),
                  child: Text(
                    (user?.name.isNotEmpty == true ? user!.name[0] : 'U').toUpperCase(),
                    style: const TextStyle(
                      fontSize: 28,
                      fontWeight: FontWeight.bold,
                      color: AppTheme.primary,
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  user?.name ?? 'Guest User',
                  style: const TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                    color: Colors.white,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  user?.email ?? 'Browsing in guest mode',
                  style: const TextStyle(
                    fontSize: 13,
                    color: AppTheme.textSecondary,
                  ),
                ),
                const SizedBox(height: 16),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: AppTheme.primary.withValues(alpha: 0.3)),
                  ),
                  child: Text(
                    user != null ? 'VIP Member • Active Streaming' : 'Guest Account',
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: AppTheme.primary,
                    ),
                  ),
                ),
                const SizedBox(height: 24),
                // Check for updates button
                SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: () async {
                      Navigator.of(context).pop();
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(
                          content: Text('Checking for latest updates...'),
                          duration: Duration(seconds: 1),
                        ),
                      );
                      final updateInfo = await AppUpdateService.checkForUpdate();
                      if (!context.mounted) return;
                      if (updateInfo != null && updateInfo.hasUpdate) {
                        AppUpdateDialog.show(context, updateInfo);
                      } else {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(
                            content: Text(
                              updateInfo != null
                                  ? "You're already on the latest version (v${updateInfo.latestVersionName})!"
                                  : "Could not reach update server. Check your connection.",
                            ),
                            backgroundColor: updateInfo != null ? const Color(0xFF10B981) : Colors.redAccent,
                          ),
                        );
                      }
                    },
                    icon: const Icon(Icons.system_update_rounded, size: 18, color: AppTheme.secondary),
                    label: const Text('Check for Updates', style: TextStyle(color: Colors.white)),
                    style: OutlinedButton.styleFrom(
                      side: const BorderSide(color: AppTheme.cardBorder),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                ),
                const SizedBox(height: 10),
                // Action Button (Sign Out or Sign In)
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    onPressed: () async {
                      Navigator.of(context).pop();
                      await widget.state.logout();
                      if (!context.mounted) return;
                      Navigator.of(context).pushAndRemoveUntil(
                        MaterialPageRoute(
                          builder: (context) => LoginScreen(state: widget.state),
                        ),
                        (route) => false,
                      );
                    },
                    icon: Icon(
                      user != null ? Icons.logout_rounded : Icons.login_rounded,
                      size: 18,
                    ),
                    label: Text(user != null ? 'Sign Out' : 'Sign In with Account'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: user != null ? const Color(0xFFEF4444) : AppTheme.primary,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 10),
              ],
            ),
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.state,
      builder: (context, _) {
        final favourites = widget.state.favouriteItems;
        final history = widget.state.watchHistory;
        final user = widget.state.currentUser;

        return Scaffold(
          backgroundColor: AppTheme.background,
          appBar: AppBar(
            title: const Text('My Library'),
            actions: [
              if (favourites.isNotEmpty && _tabController.index == 0)
                IconButton(
                  tooltip: 'Clear All Saved',
                  icon: const Icon(Icons.delete_sweep_outlined, color: AppTheme.textMuted, size: 22),
                  onPressed: () => _confirmClearFavourites(context),
                ),
              if (history.isNotEmpty && _tabController.index == 1)
                IconButton(
                  tooltip: 'Clear History',
                  icon: const Icon(Icons.delete_sweep_outlined, color: AppTheme.textMuted, size: 22),
                  onPressed: () => _confirmClearHistory(context),
                ),
              IconButton(
                onPressed: () => _showAccountDialog(context),
                icon: CircleAvatar(
                  radius: 15,
                  backgroundColor: user != null ? AppTheme.primary : AppTheme.surfaceLight,
                  child: Text(
                    (user != null && user.name.isNotEmpty ? user.name[0] : 'G').toUpperCase(),
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                      color: Colors.white,
                    ),
                  ),
                ),
                tooltip: 'Account Profile',
              ),
              const SizedBox(width: 8),
            ],
            bottom: TabBar(
              controller: _tabController,
              indicatorColor: AppTheme.primary,
              indicatorWeight: 3,
              labelColor: Colors.white,
              unselectedLabelColor: AppTheme.textMuted,
              tabs: [
                Tab(text: 'Favourites (${favourites.length})'),
                Tab(text: 'History (${history.length})'),
              ],
            ),
          ),
          body: TabBarView(
            controller: _tabController,
            children: [
              // Favourites / Saved Tab
              favourites.isEmpty
                  ? Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Container(
                            padding: const EdgeInsets.all(20),
                            decoration: BoxDecoration(
                              color: AppTheme.surfaceLight.withValues(alpha: 0.5),
                              shape: BoxShape.circle,
                            ),
                            child: const Icon(Icons.bookmark_border_rounded, size: 54, color: AppTheme.textMuted),
                          ),
                          const SizedBox(height: 16),
                          const Text(
                            'No favourites saved yet',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          const SizedBox(height: 6),
                          const Text(
                            'Tap the bookmark icon on any movie or web series to save it here.',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: AppTheme.textMuted, fontSize: 13),
                          ),
                        ],
                      ),
                    )
                  : GridView.builder(
                      padding: const EdgeInsets.all(16),
                      physics: const BouncingScrollPhysics(),
                      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: 3,
                        childAspectRatio: 0.55,
                        crossAxisSpacing: 10,
                        mainAxisSpacing: 12,
                      ),
                      itemCount: favourites.length,
                      itemBuilder: (context, index) {
                        final item = favourites[index];
                        return Stack(
                          clipBehavior: Clip.none,
                          children: [
                            ContentCard(
                              item: item,
                              state: widget.state,
                              width: double.infinity,
                              height: 150,
                              margin: EdgeInsets.zero,
                            ),
                            // Quick Saved / Remove bookmark button overlay on card
                            Positioned(
                              top: 6,
                              left: 6,
                              child: Material(
                                color: Colors.transparent,
                                child: InkWell(
                                  borderRadius: BorderRadius.circular(16),
                                  onTap: () async {
                                    await widget.state.toggleFavourite(item);
                                    if (context.mounted) {
                                      ScaffoldMessenger.of(context).clearSnackBars();
                                      ScaffoldMessenger.of(context).showSnackBar(
                                        SnackBar(
                                          content: Text('Removed "${item.title}" from Saved'),
                                          backgroundColor: AppTheme.surfaceLight,
                                          behavior: SnackBarBehavior.floating,
                                          shape: RoundedRectangleBorder(
                                            borderRadius: BorderRadius.circular(10),
                                          ),
                                          duration: const Duration(seconds: 3),
                                          action: SnackBarAction(
                                            label: 'UNDO',
                                            textColor: AppTheme.primary,
                                            onPressed: () {
                                              widget.state.toggleFavourite(item);
                                            },
                                          ),
                                        ),
                                      );
                                    }
                                  },
                                  child: Container(
                                    padding: const EdgeInsets.all(5),
                                    decoration: BoxDecoration(
                                      color: Colors.black.withValues(alpha: 0.78),
                                      shape: BoxShape.circle,
                                      border: Border.all(
                                        color: AppTheme.primary.withValues(alpha: 0.6),
                                        width: 1.0,
                                      ),
                                      boxShadow: [
                                        BoxShadow(
                                          color: Colors.black.withValues(alpha: 0.4),
                                          blurRadius: 4,
                                          offset: const Offset(0, 1),
                                        ),
                                      ],
                                    ),
                                    child: const Icon(
                                      Icons.bookmark_rounded,
                                      color: AppTheme.primary,
                                      size: 15,
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ],
                        );
                      },
                    ),

              // History Tab
              history.isEmpty
                  ? Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Container(
                            padding: const EdgeInsets.all(20),
                            decoration: BoxDecoration(
                              color: AppTheme.surfaceLight.withValues(alpha: 0.5),
                              shape: BoxShape.circle,
                            ),
                            child: const Icon(Icons.history_rounded, size: 54, color: AppTheme.textMuted),
                          ),
                          const SizedBox(height: 16),
                          const Text(
                            'No watch history yet',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          const SizedBox(height: 6),
                          const Text(
                            'Titles you stream will appear here for quick access.',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: AppTheme.textMuted, fontSize: 13),
                          ),
                        ],
                      ),
                    )
                  : GridView.builder(
                      padding: const EdgeInsets.all(16),
                      physics: const BouncingScrollPhysics(),
                      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: 3,
                        childAspectRatio: 0.55,
                        crossAxisSpacing: 10,
                        mainAxisSpacing: 12,
                      ),
                      itemCount: history.length,
                      itemBuilder: (context, index) {
                        return ContentCard(
                          item: history[index],
                          state: widget.state,
                          width: double.infinity,
                          height: 150,
                          margin: EdgeInsets.zero,
                        );
                      },
                    ),
            ],
          ),
        );
      },
    );
  }
}
