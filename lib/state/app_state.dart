import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/content_item.dart';
import '../models/home_category.dart';
import '../models/watch_progress.dart';
import '../models/cast_device.dart';
import '../models/app_user.dart';
import '../models/subscription_plan.dart';
import '../models/user_subscription.dart';
import '../data/mock_content.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/subscription_service.dart';

class AppState extends ChangeNotifier {
  ContentType? _selectedCategory;
  static const String _kFavouritesKey = 'pikashow_saved_favourites';
  static const String _kHistoryKey = 'pikashow_watch_history';

  final Set<String> _favouriteIds = {'prom_01', 'series_01'};
  final Map<String, ContentItem> _favouritesMap = {};
  final List<ContentItem> _watchHistory = [];
  String _searchQuery = '';
  CastDevice? _connectedCastDevice;

  List<ContentItem> _catalogItems = List.from(MockContent.allContent);
  List<HomeCategory> _homeCategories = List.from(HomeCategory.defaultCategories);
  List<WatchProgress> _continueWatching = [];
  bool _isLoading = false;
  bool _isBackendConnected = false;

  // Auth State
  AppUser? _currentUser;
  bool _isGuest = false;
  bool _authInitialized = false;

  // Subscription State
  UserSubscription? _currentSubscription;
  bool _hasActiveSubscription = false;
  List<SubscriptionPlan> _plans = List.from(SubscriptionService.defaultMockPlans);

  AppState() {
    initAuth();
    initWatchProgress();
    initFavourites();
    initHistory();
    refreshCatalog();
    refreshSubscription();
  }

  ContentType? get selectedCategory => _selectedCategory;
  Set<String> get favouriteIds => Set.unmodifiable(_favouriteIds);
  List<ContentItem> get watchHistory => List.unmodifiable(_watchHistory);
  List<WatchProgress> get continueWatching => List.unmodifiable(_continueWatching);
  String get searchQuery => _searchQuery;
  CastDevice? get connectedCastDevice => _connectedCastDevice;
  bool get isCasting => _connectedCastDevice != null;
  bool get isLoading => _isLoading;
  bool get isBackendConnected => _isBackendConnected;
  List<ContentItem> get allContent => List.unmodifiable(_catalogItems);
  List<HomeCategory> get homeCategories => List.unmodifiable(_homeCategories);

  // Auth Getters
  AppUser? get currentUser => _currentUser;
  bool get isAuthenticated => _currentUser != null || _isGuest;
  bool get isGuest => _isGuest;
  bool get hasActiveUser => _currentUser != null;
  bool get authInitialized => _authInitialized;

  // Subscription Getters
  bool get hasActiveSubscription => _hasActiveSubscription || (_currentSubscription?.isActive ?? false);
  UserSubscription? get currentSubscription => _currentSubscription;
  List<SubscriptionPlan> get availablePlans => List.unmodifiable(_plans);

  /// For testing purposes only
  @visibleForTesting
  void setTestUser(AppUser? user) {
    _currentUser = user;
    _isGuest = false;
    _authInitialized = true;
    notifyListeners();
  }

  /// Load persisted user session on startup
  Future<void> initAuth() async {
    try {
      final user = await AuthService.getStoredUser();
      final isGuest = await AuthService.isGuestSession();
      if (!_authInitialized || _currentUser == null) {
        _currentUser = user;
        _isGuest = isGuest;
      }
      if (user != null) {
        await refreshSubscription();
      }
    } catch (_) {
    } finally {
      _authInitialized = true;
      notifyListeners();
    }
  }

  /// Sign in with email and password
  Future<AuthResult> login(String email, String password) async {
    final result = await AuthService.login(email: email, password: password);
    if (result.success && result.user != null) {
      _currentUser = result.user;
      _isGuest = false;
      notifyListeners();
    }
    return result;
  }

  /// Register new user account
  Future<AuthResult> register(String name, String email, String password) async {
    final result = await AuthService.register(name: name, email: email, password: password);
    if (result.success && result.user != null) {
      _currentUser = result.user;
      _isGuest = false;
      notifyListeners();
    }
    return result;
  }

  /// Log out user and clear session
  Future<void> logout() async {
    await AuthService.clearSession();
    _currentUser = null;
    _isGuest = false;
    _currentSubscription = null;
    _hasActiveSubscription = false;
    notifyListeners();
  }

  /// Continue without logging in
  void continueAsGuest() {
    AuthService.setGuestMode(true);
    _isGuest = true;
    notifyListeners();
  }

  /// Refresh subscription status and plans
  Future<void> refreshSubscription() async {
    try {
      final fetchedPlans = await SubscriptionService.fetchPlans();
      if (fetchedPlans.isNotEmpty) {
        _plans = fetchedPlans;
      }

      final token = _currentUser?.token ?? (_isGuest ? 'guest_token' : 'guest_session');
      final result = await SubscriptionService.fetchUserSubscription(token);
      _currentSubscription = result.subscription;
      _hasActiveSubscription = result.hasActiveSubscription;
    } catch (e) {
      debugPrint('AppState.refreshSubscription error: $e');
    } finally {
      notifyListeners();
    }
  }

  /// Request a subscription plan
  Future<bool> selectPlan(SubscriptionPlan plan) async {
    if (_isGuest || _currentUser == null) {
      debugPrint('AppState.selectPlan: Guest users cannot subscribe to plans.');
      return false;
    }
    final token = _currentUser?.token;
    if (token == null || token.isEmpty) {
      return false;
    }
    final success = await SubscriptionService.subscribe(token: token, plan: plan);
    if (success) {
      await refreshSubscription();
    }
    return success;
  }

  /// Submit payment transaction ID
  Future<bool> submitPaymentTransaction(String transactionId) async {
    if (_isGuest || _currentUser == null) {
      debugPrint('AppState.submitPaymentTransaction: Guest users cannot submit transactions.');
      return false;
    }
    final token = _currentUser?.token;
    if (token == null || token.isEmpty) {
      return false;
    }
    final subId = _currentSubscription?.id ?? 0;
    final success = await SubscriptionService.submitTransactionId(
      token: token,
      subscriptionId: subId,
      transactionId: transactionId,
    );
    if (success) {
      await refreshSubscription();
    }
    return success;
  }

  /// Cancel current active or pending subscription
  Future<bool> cancelActiveSubscription() async {
    final token = _currentUser?.token ?? 'guest_token';
    final subId = _currentSubscription?.id;
    final success = await SubscriptionService.cancelSubscription(
      token: token,
      subscriptionId: subId,
    );
    if (success) {
      await refreshSubscription();
    }
    return success;
  }

  /// Fetch latest content and dynamic home categories from the PHP backend, or keep mock content if offline
  Future<void> refreshCatalog() async {
    _isLoading = true;
    notifyListeners();

    try {
      final items = await ApiService.fetchCatalog();
      if (items.isNotEmpty) {
        _catalogItems = items;
      }
      final cats = await ApiService.fetchHomeCategories();
      if (cats.isNotEmpty) {
        _homeCategories = cats;
      }
      _isBackendConnected = await ApiService.isServerAvailable();
    } catch (_) {
      // Keep local catalog and categories
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  void connectCastDevice(CastDevice device) {
    _connectedCastDevice = device;
    notifyListeners();
  }

  void disconnectCastDevice() {
    _connectedCastDevice = null;
    notifyListeners();
  }

  String _homeCategory = 'all';

  String get homeCategory => _homeCategory;

  void setCategory(ContentType? category) {
    if (_selectedCategory == category) {
      _selectedCategory = null;
      _homeCategory = 'all';
    } else {
      _selectedCategory = category;
      _homeCategory = category?.name ?? 'all';
    }
    notifyListeners();
  }

  void setHomeCategory(String categoryKey) {
    _homeCategory = categoryKey;
    switch (categoryKey) {
      case 'bollywood':
        _selectedCategory = ContentType.bollywood;
        break;
      case 'hollywood':
        _selectedCategory = ContentType.hollywood;
        break;
      case 'series':
        _selectedCategory = ContentType.series;
        break;
      case 'liveTv':
        _selectedCategory = ContentType.liveTv;
        break;
      case 'movies':
        _selectedCategory = null;
        break;
      default:
        final matched = getHomeCategoryBySlug(categoryKey);
        if (matched != null) {
          switch (matched.contentType) {
            case 'bollywood':
              _selectedCategory = ContentType.bollywood;
              break;
            case 'hollywood':
              _selectedCategory = ContentType.hollywood;
              break;
            case 'series':
              _selectedCategory = ContentType.series;
              break;
            case 'liveTv':
              _selectedCategory = ContentType.liveTv;
              break;
            default:
              _selectedCategory = null;
              break;
          }
        } else {
          _selectedCategory = null;
        }
        break;
    }
    notifyListeners();
  }

  HomeCategory? getHomeCategoryBySlug(String slug) {
    try {
      return _homeCategories.firstWhere((c) => c.slug == slug);
    } catch (_) {
      return null;
    }
  }

  List<ContentItem> getItemsForCategory(HomeCategory category) {
    List<ContentItem> items = [];

    switch (category.contentType) {
      case 'movies':
        items = _catalogItems.where((i) => i.isMovie).toList();
        break;
      case 'bollywood':
        items = _catalogItems.where((i) => i.type == ContentType.bollywood).toList();
        break;
      case 'hollywood':
        items = _catalogItems.where((i) => i.type == ContentType.hollywood).toList();
        break;
      case 'series':
        items = _catalogItems.where((i) => i.type == ContentType.series).toList();
        break;
      case 'liveTv':
        items = _catalogItems.where((i) => i.type == ContentType.liveTv).toList();
        break;
      case 'genre':
        items = List.from(_catalogItems);
        break;
      case 'all':
      default:
        items = List.from(_catalogItems);
        break;
    }

    if (category.filterGenre.trim().isNotEmpty) {
      final fg = category.filterGenre.trim().toLowerCase();
      items = items.where((i) => i.genres.any((g) => g.toLowerCase() == fg)).toList();
    }

    switch (category.sortBy) {
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

  void setSearchQuery(String query) {
    _searchQuery = query;
    notifyListeners();
  }

  bool isFavourite(String id) => _favouriteIds.contains(id);

  /// Load persisted saved favourites from SharedPreferences
  Future<void> initFavourites() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final rawList = prefs.getStringList(_kFavouritesKey);
      if (rawList != null) {
        _favouritesMap.clear();
        _favouriteIds.clear();
        for (final itemStr in rawList) {
          try {
            final json = jsonDecode(itemStr) as Map<String, dynamic>;
            final item = ContentItem.fromJson(json);
            _favouritesMap[item.id] = item;
            _favouriteIds.add(item.id);
          } catch (_) {}
        }
      } else {
        // First run defaults: populate with mock content
        final defaultIds = ['prom_01', 'series_01'];
        for (final id in defaultIds) {
          final found = MockContent.allContent.where((i) => i.id == id).firstOrNull;
          if (found != null) {
            _favouritesMap[found.id] = found;
            _favouriteIds.add(found.id);
          }
        }
        await _persistFavourites();
      }
    } catch (e) {
      debugPrint('initFavourites error: $e');
    } finally {
      notifyListeners();
    }
  }

  Future<void> _persistFavourites() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final list = favouriteItems.map((item) => jsonEncode(item.toJson())).toList();
      await prefs.setStringList(_kFavouritesKey, list);
    } catch (e) {
      debugPrint('_persistFavourites error: $e');
    }
  }

  /// Toggle favourite status of an item (can accept either ContentItem or String id)
  Future<void> toggleFavourite(dynamic itemOrId) async {
    ContentItem? item;
    String id;

    if (itemOrId is ContentItem) {
      item = itemOrId;
      id = item.id;
    } else if (itemOrId is String) {
      id = itemOrId;
      item = _favouritesMap[id] ??
          _catalogItems.where((i) => i.id == id).firstOrNull ??
          MockContent.allContent.where((i) => i.id == id).firstOrNull;
    } else {
      return;
    }

    if (_favouriteIds.contains(id)) {
      _favouriteIds.remove(id);
      _favouritesMap.remove(id);
    } else {
      _favouriteIds.add(id);
      if (item != null) {
        _favouritesMap[id] = item;
      }
    }

    notifyListeners();
    await _persistFavourites();
  }

  /// Remove single item from favourites
  Future<void> removeFavourite(String id) async {
    if (_favouriteIds.remove(id)) {
      _favouritesMap.remove(id);
      notifyListeners();
      await _persistFavourites();
    }
  }

  /// Clear all saved favourites
  Future<void> clearAllFavourites() async {
    _favouriteIds.clear();
    _favouritesMap.clear();
    notifyListeners();
    await _persistFavourites();
  }

  /// Load persisted watch history
  Future<void> initHistory() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final rawList = prefs.getStringList(_kHistoryKey);
      if (rawList != null && rawList.isNotEmpty) {
        final List<ContentItem> loaded = [];
        for (final itemStr in rawList) {
          try {
            final json = jsonDecode(itemStr) as Map<String, dynamic>;
            loaded.add(ContentItem.fromJson(json));
          } catch (_) {}
        }
        _watchHistory.clear();
        _watchHistory.addAll(loaded);
        notifyListeners();
      }
    } catch (e) {
      debugPrint('initHistory error: $e');
    }
  }

  Future<void> _persistHistory() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final list = _watchHistory.map((item) => jsonEncode(item.toJson())).toList();
      await prefs.setStringList(_kHistoryKey, list);
    } catch (e) {
      debugPrint('_persistHistory error: $e');
    }
  }

  void addToHistory(ContentItem item) {
    _watchHistory.removeWhere((i) => i.id == item.id);
    _watchHistory.insert(0, item);
    if (_watchHistory.length > 30) {
      _watchHistory.removeLast();
    }
    notifyListeners();
    _persistHistory();
  }

  Future<void> clearWatchHistory() async {
    _watchHistory.clear();
    notifyListeners();
    await _persistHistory();
  }

  static const String _kContinueWatchingKey = 'pikashow_continue_watching';

  /// Load persisted continue watching items from SharedPreferences
  Future<void> initWatchProgress() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final rawList = prefs.getStringList(_kContinueWatchingKey);
      if (rawList != null && rawList.isNotEmpty) {
        final List<WatchProgress> loaded = [];
        for (final itemStr in rawList) {
          try {
            final json = jsonDecode(itemStr) as Map<String, dynamic>;
            loaded.add(WatchProgress.fromJson(json));
          } catch (_) {}
        }
        _continueWatching = loaded;
        notifyListeners();
      }
    } catch (e) {
      debugPrint('initWatchProgress error: $e');
    }
  }

  /// Get saved watch progress for a movie or episode
  WatchProgress? getWatchProgress(String contentId, {String? episodeId}) {
    try {
      return _continueWatching.firstWhere(
        (p) => p.contentId == contentId && (episodeId == null || p.episode?.id == episodeId),
      );
    } catch (_) {
      return null;
    }
  }

  /// Get resume position if valid and unfinished
  Duration? getSavedPosition(String contentId, {String? episodeId}) {
    final p = getWatchProgress(contentId, episodeId: episodeId);
    if (p != null && p.positionSeconds > 5 && !p.isCompleted) {
      return Duration(seconds: p.positionSeconds);
    }
    return null;
  }

  /// Save or update watch progress when user pauses or leaves video halfway
  Future<void> saveWatchProgress({
    required ContentItem item,
    Episode? episode,
    required Duration position,
    required Duration duration,
  }) async {
    // Live TV streams do not have fixed durations / resume positions
    if (item.isLive || duration.inSeconds <= 0) return;

    // Ignore tiny accidental clicks under 5 seconds
    if (position.inSeconds < 5) return;

    final progress = WatchProgress(
      contentId: item.id,
      item: item,
      episode: episode,
      positionSeconds: position.inSeconds,
      durationSeconds: duration.inSeconds,
      updatedAt: DateTime.now(),
    );

    // If watched >= 95%, remove from continue watching (completed)
    if (progress.isCompleted) {
      await removeWatchProgress(item.id, episodeId: episode?.id);
      return;
    }

    // Remove any previous record for this item/episode
    _continueWatching.removeWhere(
      (p) => p.contentId == item.id && (episode == null || p.episode?.id == episode.id),
    );

    // Insert at front (most recent)
    _continueWatching.insert(0, progress);
    if (_continueWatching.length > 25) {
      _continueWatching.removeLast();
    }

    // Also add to watch history
    addToHistory(item);

    notifyListeners();

    // Persist to local storage
    try {
      final prefs = await SharedPreferences.getInstance();
      final strList = _continueWatching.map((p) => jsonEncode(p.toJson())).toList();
      await prefs.setStringList(_kContinueWatchingKey, strList);
    } catch (e) {
      debugPrint('saveWatchProgress error: $e');
    }
  }

  /// Dismiss/remove item from Continue Watching
  Future<void> removeWatchProgress(String contentId, {String? episodeId}) async {
    _continueWatching.removeWhere(
      (p) => p.contentId == contentId && (episodeId == null || p.episode?.id == episodeId),
    );
    notifyListeners();

    try {
      final prefs = await SharedPreferences.getInstance();
      final strList = _continueWatching.map((p) => jsonEncode(p.toJson())).toList();
      await prefs.setStringList(_kContinueWatchingKey, strList);
    } catch (_) {}
  }

  List<ContentItem> get promotedItems =>
      _catalogItems.where((item) => item.isPromoted).toList();

  List<ContentItem> get trendingItems =>
      _catalogItems.where((item) => item.isTrending).toList();

  /// All movies (Bollywood and Hollywood combined) sorted latest one first
  List<ContentItem> get latestMovies {
    final list = _catalogItems.where((item) => item.isMovie).toList();
    list.sort(ContentItem.compareLatestFirst);
    return list;
  }

  /// Bollywood movies sorted latest one first
  List<ContentItem> get bollywoodMovies {
    final list = _catalogItems.where((item) => item.type == ContentType.bollywood).toList();
    list.sort(ContentItem.compareLatestFirst);
    return list;
  }

  /// Hollywood movies sorted latest one first
  List<ContentItem> get hollywoodMovies {
    final list = _catalogItems.where((item) => item.type == ContentType.hollywood).toList();
    list.sort(ContentItem.compareLatestFirst);
    return list;
  }

  /// Web series sorted latest first
  List<ContentItem> get seriesItems {
    final list = _catalogItems.where((item) => item.type == ContentType.series).toList();
    list.sort(ContentItem.compareLatestFirst);
    return list;
  }

  List<ContentItem> get liveTvItems =>
      _catalogItems.where((item) => item.type == ContentType.liveTv).toList();

  List<ContentItem> get favouriteItems {
    final List<ContentItem> list = [];
    final Set<String> added = {};

    for (final item in _favouritesMap.values) {
      if (added.add(item.id)) {
        list.add(item);
      }
    }

    for (final id in _favouriteIds) {
      if (added.add(id)) {
        final item = _catalogItems.where((i) => i.id == id).firstOrNull ??
            MockContent.allContent.where((i) => i.id == id).firstOrNull;
        if (item != null) {
          list.add(item);
          _favouritesMap[id] = item;
        }
      }
    }
    return list;
  }

  List<ContentItem> get searchResults {
    if (_searchQuery.trim().isEmpty) return [];
    final q = _searchQuery.toLowerCase();
    return _catalogItems.where((item) {
      final inTitle = item.title.toLowerCase().contains(q);
      final inGenre = item.genres.any((g) => g.toLowerCase().contains(q));
      final inLang = item.languages.any((l) => l.toLowerCase().contains(q));
      return inTitle || inGenre || inLang;
    }).toList();
  }
}
