import 'dart:async';
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
import '../services/watch_progress_service.dart';
import '../services/library_service.dart';

class AppState extends ChangeNotifier {
  ContentType? _selectedCategory;
  static const String _kFavouritesKey = 'pikashow_saved_favourites';
  static const String _kHistoryKey = 'pikashow_watch_history';

  final Set<String> _favouriteIds = {};
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
  Future<void>? _initAuthFuture;

  // Subscription State
  UserSubscription? _currentSubscription;
  bool _hasActiveSubscription = false;
  List<SubscriptionPlan> _plans = List.from(SubscriptionService.defaultMockPlans);

  AppState() {
    _initDefaults();
    // Start restoring session from local storage immediately on creation
    initAuth();
    refreshCatalog();
  }

  void _initDefaults() {
    // No artificial mock seeding — user starts clean and data is loaded from backend/storage
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

  /// Restore a user session from storage or external auth check
  void restoreUserSession(AppUser user) {
    _currentUser = user;
    _isGuest = false;
    _authInitialized = true;
    unawaited(AuthService.saveUserSession(user, user.token));
    notifyListeners();
  }

  /// Load persisted user session on startup (idempotent, returns shared Future)
  Future<void> initAuth() {
    _initAuthFuture ??= _doInitAuth();
    return _initAuthFuture!;
  }

  Future<void> _doInitAuth() async {
    try {
      final user = await AuthService.getStoredUser();
      final isGuest = await AuthService.isGuestSession();
      final hasSession = await AuthService.hasActiveSession();

      if (!_authInitialized || _currentUser == null) {
        _currentUser = user;
        _isGuest = (user == null) && (isGuest || hasSession);
      }
      if (user != null) {
        unawaited(AuthService.setGuestMode(false));
      } else if (_isGuest) {
        unawaited(AuthService.setGuestMode(true));
      }
      _authInitialized = true;
      notifyListeners();

      // Ensure continue watching, favourites, and history are loaded for this session
      await initWatchProgress(force: true);
      await initFavourites(force: true);
      await initHistory(force: true);

      // If user session is active, verify with backend & sync in background
      if (user != null && user.token != null && user.token!.isNotEmpty) {
        unawaited(_syncUserWithBackend(user.token!));
      } else if (_isGuest) {
        unawaited(refreshSubscription());
      }
    } catch (e) {
      debugPrint('AppState.initAuth error: $e');
    } finally {
      _authInitialized = true;
      notifyListeners();
    }
  }

  Future<void> _syncUserWithBackend(String token) async {
    try {
      final verifiedUser = await AuthService.verifySession(token);
      if (verifiedUser != null) {
        _currentUser = verifiedUser;
        notifyListeners();
      }

      // Fetch and merge watch progress, favourites, and history from backend
      await syncWatchProgressWithBackend();
      await syncFavouritesWithBackend();
      await syncHistoryWithBackend();

      // Refresh subscription plans & active subscription
      await refreshSubscription();
    } catch (e) {
      debugPrint('_syncUserWithBackend error: $e');
    }
  }

  /// Sign in with email and password
  Future<AuthResult> login(String email, String password) async {
    final result = await AuthService.login(email: email, password: password);
    if (result.success && result.user != null) {
      _currentUser = result.user;
      _isGuest = false;
      notifyListeners();

      // Immediately load user's progress, favourites, & history and sync with backend
      await initWatchProgress(force: true);
      await initFavourites(force: true);
      await initHistory(force: true);
      unawaited(syncWatchProgressWithBackend());
      unawaited(syncFavouritesWithBackend());
      unawaited(syncHistoryWithBackend());

      // Fetch subscription so header badge shows correct plan right after login
      await refreshSubscription();
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
      await initWatchProgress(force: true);
      await initFavourites(force: true);
      await initHistory(force: true);
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
    _continueWatching.clear();
    _favouritesMap.clear();
    _favouriteIds.clear();
    _watchHistory.clear();
    notifyListeners();
    // Reload guest / default progress if any
    await initWatchProgress(force: true);
    await initFavourites(force: true);
    await initHistory(force: true);
  }

  /// Continue without logging in
  void continueAsGuest({bool clearData = true}) {
    AuthService.setGuestMode(true);
    _isGuest = true;
    _currentUser = null;
    if (clearData) {
      _continueWatching.clear();
      _favouritesMap.clear();
      _favouriteIds.clear();
      _watchHistory.clear();
    }
    notifyListeners();
    initWatchProgress(force: true);
    initFavourites(force: true);
    initHistory(force: true);
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

  String get _userFavouritesKey => _currentUser != null
      ? '${_kFavouritesKey}_user_${_currentUser!.id}'
      : _kFavouritesKey;

  String get _userHistoryKey => _currentUser != null
      ? '${_kHistoryKey}_user_${_currentUser!.id}'
      : _kHistoryKey;

  Future<void>? _initFavouritesFuture;
  Future<void>? _initHistoryFuture;

  /// Load persisted saved favourites from SharedPreferences
  Future<void> initFavourites({bool force = false}) {
    if (force || _initFavouritesFuture == null) {
      _initFavouritesFuture = _doInitFavourites();
    }
    return _initFavouritesFuture!;
  }

  Future<void> _doInitFavourites() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      List<String>? rawList;

      if (_currentUser != null) {
        if (prefs.containsKey(_userFavouritesKey)) {
          rawList = prefs.getStringList(_userFavouritesKey);
        } else {
          rawList = prefs.getStringList(_kFavouritesKey);
          if (rawList != null && rawList.isNotEmpty) {
            await prefs.setStringList(_userFavouritesKey, rawList);
          }
        }
      } else {
        rawList = prefs.getStringList(_kFavouritesKey);
      }

      final Map<String, ContentItem> loadedMap = {};
      final Set<String> loadedIds = {};

      if (rawList != null && rawList.isNotEmpty) {
        for (final itemStr in rawList) {
          try {
            final json = jsonDecode(itemStr) as Map<String, dynamic>;
            final item = ContentItem.fromJson(json);
            loadedMap[item.id] = item;
            loadedIds.add(item.id);
          } catch (_) {}
        }
      }

      _favouritesMap.clear();
      _favouriteIds.clear();
      _favouritesMap.addAll(loadedMap);
      _favouriteIds.addAll(loadedIds);
      notifyListeners();
    } catch (e) {
      debugPrint('initFavourites error: $e');
    }
  }

  Future<void> _persistFavourites() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final list = favouriteItems.map((item) => jsonEncode(item.toJson())).toList();
      if (_currentUser != null) {
        await prefs.setStringList(_userFavouritesKey, list);
      } else {
        await prefs.setStringList(_kFavouritesKey, list);
      }
    } catch (e) {
      debugPrint('_persistFavourites error: $e');
    }
  }

  /// Sync favourites with backend
  Future<void> syncFavouritesWithBackend() async {
    final token = _currentUser?.token;
    if (token == null || token.isEmpty || token == 'guest_token') return;

    try {
      final serverItems = await LibraryService.fetchFavourites(token);
      if (serverItems.isNotEmpty) {
        for (final s in serverItems) {
          _favouritesMap[s.id] = s;
          _favouriteIds.add(s.id);
        }
        notifyListeners();
        await _persistFavourites();
      } else if (_favouritesMap.isNotEmpty) {
        for (final item in _favouritesMap.values) {
          unawaited(LibraryService.saveFavourite(token: token, item: item));
        }
      }
    } catch (e) {
      debugPrint('syncFavouritesWithBackend error: $e');
    }
  }

  /// Toggle favourite status of an item (can accept either ContentItem or String id)
  Future<void> toggleFavourite(dynamic itemOrId) async {
    if (_initFavouritesFuture != null) {
      await _initFavouritesFuture;
    }

    ContentItem? item;
    String id;

    if (itemOrId is ContentItem) {
      item = itemOrId;
      id = item.id;
    } else if (itemOrId is String) {
      id = itemOrId;
      item = _favouritesMap[id] ??
          _catalogItems.where((i) => i.id == id).firstOrNull;
    } else {
      return;
    }

    final token = _currentUser?.token;
    final hasUserToken = token != null && token.isNotEmpty && token != 'guest_token';

    if (_favouriteIds.contains(id)) {
      _favouriteIds.remove(id);
      _favouritesMap.remove(id);
      notifyListeners();
      await _persistFavourites();
      if (hasUserToken) {
        unawaited(LibraryService.removeFavourite(token: token, contentId: id));
      }
    } else {
      _favouriteIds.add(id);
      if (item != null) {
        _favouritesMap[id] = item;
      }
      notifyListeners();
      await _persistFavourites();
      if (hasUserToken && item != null) {
        unawaited(LibraryService.saveFavourite(token: token, item: item));
      }
    }
  }

  /// Remove single item from favourites
  Future<void> removeFavourite(String id) async {
    if (_favouriteIds.remove(id)) {
      _favouritesMap.remove(id);
      notifyListeners();
      await _persistFavourites();
      final token = _currentUser?.token;
      if (token != null && token.isNotEmpty && token != 'guest_token') {
        unawaited(LibraryService.removeFavourite(token: token, contentId: id));
      }
    }
  }

  /// Clear all saved favourites
  Future<void> clearAllFavourites() async {
    _favouriteIds.clear();
    _favouritesMap.clear();
    notifyListeners();
    await _persistFavourites();
    final token = _currentUser?.token;
    if (token != null && token.isNotEmpty && token != 'guest_token') {
      unawaited(LibraryService.clearAllFavourites(token));
    }
  }

  /// Load persisted watch history
  Future<void> initHistory({bool force = false}) {
    if (force || _initHistoryFuture == null) {
      _initHistoryFuture = _doInitHistory();
    }
    return _initHistoryFuture!;
  }

  Future<void> _doInitHistory() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      List<String>? rawList;

      if (_currentUser != null) {
        if (prefs.containsKey(_userHistoryKey)) {
          rawList = prefs.getStringList(_userHistoryKey);
        } else {
          rawList = prefs.getStringList(_kHistoryKey);
          if (rawList != null && rawList.isNotEmpty) {
            await prefs.setStringList(_userHistoryKey, rawList);
          }
        }
      } else {
        rawList = prefs.getStringList(_kHistoryKey);
      }

      final List<ContentItem> loaded = [];
      if (rawList != null && rawList.isNotEmpty) {
        for (final itemStr in rawList) {
          try {
            final json = jsonDecode(itemStr) as Map<String, dynamic>;
            loaded.add(ContentItem.fromJson(json));
          } catch (_) {}
        }
      }
      _watchHistory.clear();
      _watchHistory.addAll(loaded);
      notifyListeners();
    } catch (e) {
      debugPrint('initHistory error: $e');
    }
  }

  Future<void> _persistHistory() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final list = _watchHistory.map((item) => jsonEncode(item.toJson())).toList();
      if (_currentUser != null) {
        await prefs.setStringList(_userHistoryKey, list);
      } else {
        await prefs.setStringList(_kHistoryKey, list);
      }
    } catch (e) {
      debugPrint('_persistHistory error: $e');
    }
  }

  /// Sync watch history with backend
  Future<void> syncHistoryWithBackend() async {
    final token = _currentUser?.token;
    if (token == null || token.isEmpty || token == 'guest_token') return;

    try {
      final serverItems = await LibraryService.fetchHistory(token);
      if (serverItems.isNotEmpty) {
        final Map<String, ContentItem> merged = {};
        for (final s in serverItems) {
          merged[s.id] = s;
        }
        for (final l in _watchHistory) {
          if (!merged.containsKey(l.id)) {
            merged[l.id] = l;
          }
        }
        _watchHistory.clear();
        _watchHistory.addAll(merged.values.take(40));
        notifyListeners();
        await _persistHistory();
      } else if (_watchHistory.isNotEmpty) {
        for (final item in _watchHistory.take(15)) {
          unawaited(LibraryService.recordHistory(token: token, item: item));
        }
      }
    } catch (e) {
      debugPrint('syncHistoryWithBackend error: $e');
    }
  }

  Future<void> addToHistory(ContentItem item) async {
    if (_initHistoryFuture != null) {
      await _initHistoryFuture;
    }

    _watchHistory.removeWhere((i) => i.id == item.id);
    _watchHistory.insert(0, item);
    if (_watchHistory.length > 40) {
      _watchHistory.removeLast();
    }
    notifyListeners();
    _persistHistory();

    final token = _currentUser?.token;
    if (token != null && token.isNotEmpty && token != 'guest_token') {
      unawaited(LibraryService.recordHistory(token: token, item: item));
    }
  }

  Future<void> clearWatchHistory() async {
    _watchHistory.clear();
    notifyListeners();
    await _persistHistory();

    final token = _currentUser?.token;
    if (token != null && token.isNotEmpty && token != 'guest_token') {
      unawaited(LibraryService.clearHistory(token));
    }
  }

  static const String _kContinueWatchingKey = 'pikashow_continue_watching';
  Future<void>? _initWatchProgressFuture;

  String get _userWatchProgressKey => _currentUser != null
      ? '${_kContinueWatchingKey}_user_${_currentUser!.id}'
      : _kContinueWatchingKey;

  /// Load persisted continue watching items from SharedPreferences
  Future<void> initWatchProgress({bool force = false}) {
    if (force || _initWatchProgressFuture == null) {
      _initWatchProgressFuture = _doInitWatchProgress();
    }
    return _initWatchProgressFuture!;
  }

  Future<void> _doInitWatchProgress() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      List<String>? rawList;

      if (_currentUser != null) {
        if (prefs.containsKey(_userWatchProgressKey)) {
          rawList = prefs.getStringList(_userWatchProgressKey);
        } else {
          // One-time legacy migration from un-scoped key if user-specific key not yet set
          rawList = prefs.getStringList(_kContinueWatchingKey);
          if (rawList != null && rawList.isNotEmpty) {
            await prefs.setStringList(_userWatchProgressKey, rawList);
          }
        }
      } else {
        rawList = prefs.getStringList(_kContinueWatchingKey);
      }

      final List<WatchProgress> loaded = [];
      if (rawList != null && rawList.isNotEmpty) {
        for (final itemStr in rawList) {
          try {
            final json = jsonDecode(itemStr) as Map<String, dynamic>;
            loaded.add(WatchProgress.fromJson(json));
          } catch (e) {
            debugPrint('Failed to parse WatchProgress from storage: $e');
          }
        }
      }

      // If in-memory items were added, merge them so recent items are not dropped
      if (_continueWatching.isNotEmpty) {
        final Map<String, WatchProgress> merged = {};
        for (final p in loaded) {
          final key = '${p.contentId}_${p.episode?.id ?? "main"}';
          merged[key] = p;
        }
        for (final p in _continueWatching) {
          final key = '${p.contentId}_${p.episode?.id ?? "main"}';
          merged[key] = p;
        }
        final sorted = merged.values.toList()
          ..sort((a, b) => b.updatedAt.compareTo(a.updatedAt));
        _continueWatching = sorted.take(25).toList();
      } else {
        _continueWatching = loaded;
      }
      notifyListeners();
    } catch (e) {
      debugPrint('initWatchProgress error: $e');
    }
  }

  /// Synchronize continue watching items with backend
  Future<void> syncWatchProgressWithBackend() async {
    final token = _currentUser?.token;
    if (token == null || token.isEmpty || token == 'guest_token') return;

    try {
      final serverItems = await WatchProgressService.fetchProgress(token);
      if (serverItems.isNotEmpty) {
        // Merge server items with local items (taking most recently updated)
        final Map<String, WatchProgress> merged = {};

        // Start with server items
        for (final s in serverItems) {
          final key = '${s.contentId}_${s.episode?.id ?? "main"}';
          merged[key] = s;
        }

        // Merge with local items if local has newer timestamp
        for (final l in _continueWatching) {
          final key = '${l.contentId}_${l.episode?.id ?? "main"}';
          if (!merged.containsKey(key) || l.updatedAt.isAfter(merged[key]!.updatedAt)) {
            merged[key] = l;
          }
        }

        final sorted = merged.values.toList()
          ..sort((a, b) => b.updatedAt.compareTo(a.updatedAt));

        _continueWatching = sorted.take(25).toList();
        notifyListeners();

        // Persist merged list locally
        await _persistContinueWatching();
      } else if (_continueWatching.isNotEmpty) {
        // Server has no records yet, sync our local progress up to the server
        for (final item in _continueWatching.take(10)) {
          unawaited(WatchProgressService.saveProgress(token: token, progress: item));
        }
      }
    } catch (e) {
      debugPrint('syncWatchProgressWithBackend error: $e');
    }
  }

  Future<void> _persistContinueWatching() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final strList = _continueWatching.map((p) => jsonEncode(p.toJson())).toList();
      if (_currentUser != null) {
        await prefs.setStringList(_userWatchProgressKey, strList);
      } else {
        await prefs.setStringList(_kContinueWatchingKey, strList);
      }
    } catch (e) {
      debugPrint('_persistContinueWatching error: $e');
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

    if (_initWatchProgressFuture != null) {
      await _initWatchProgressFuture;
    }

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
    await addToHistory(item);

    notifyListeners();

    // Persist to local storage immediately
    await _persistContinueWatching();

    // Sync to backend if user is logged in
    final token = _currentUser?.token;
    if (token != null && token.isNotEmpty && token != 'guest_token') {
      unawaited(WatchProgressService.saveProgress(token: token, progress: progress));
    }
  }

  /// Dismiss/remove item from Continue Watching
  Future<void> removeWatchProgress(String contentId, {String? episodeId}) async {
    _continueWatching.removeWhere(
      (p) => p.contentId == contentId && (episodeId == null || p.episode?.id == episodeId),
    );
    notifyListeners();

    await _persistContinueWatching();

    final token = _currentUser?.token;
    if (token != null && token.isNotEmpty && token != 'guest_token') {
      unawaited(WatchProgressService.removeProgress(
        token: token,
        contentId: contentId,
        episodeId: episodeId,
      ));
    }
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
        final item = _catalogItems.where((i) => i.id == id).firstOrNull;
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
