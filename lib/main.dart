import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'theme/app_theme.dart';
import 'state/app_state.dart';
import 'screens/home_screen.dart';
import 'screens/live_tv_screen.dart';
import 'screens/search_screen.dart';
import 'screens/library_screen.dart';
import 'screens/splash_screen.dart';
import 'screens/login_screen.dart';
import 'services/auth_service.dart';
import 'services/app_update_service.dart';
import 'widgets/app_update_dialog.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      systemNavigationBarColor: AppTheme.surface,
      systemNavigationBarIconBrightness: Brightness.light,
    ),
  );

  final appState = AppState();
  // Restore persisted session, continue watching, favourites, and history from disk on cold start
  await appState.initAuth();
  await appState.initWatchProgress();
  await appState.initFavourites();
  await appState.initHistory();

  runApp(PikaShowApp(appState: appState));
}


class PikaShowApp extends StatefulWidget {
  final bool showSplash;
  final AppState? appState;

  const PikaShowApp({
    super.key,
    this.showSplash = true,
    this.appState,
  });

  @override
  State<PikaShowApp> createState() => _PikaShowAppState();
}

final RouteObserver<ModalRoute<void>> routeObserver = RouteObserver<ModalRoute<void>>();

class _PikaShowAppState extends State<PikaShowApp> {
  late final AppState _appState;

  @override
  void initState() {
    super.initState();
    _appState = widget.appState ?? AppState();
  }

  @override
  Widget build(BuildContext context) {
    // MaterialApp is built ONCE — no ListenableBuilder here.
    // Auth-based routing is handled inside AuthGate/SplashScreen so that
    // subsequent notifyListeners() calls (e.g. subscription refresh) do NOT
    // accidentally reset the root home widget and drop the user back to login.
    return MaterialApp(
      title: 'PikaShow',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.darkTheme,
      navigatorObservers: [routeObserver],
      home: widget.showSplash
          ? SplashScreen(state: _appState)
          : _AuthGate(appState: _appState),
    );
  }
}

/// Decides the initial route after splash is skipped (test / deep-link cases).
/// Listens to [AppState] only until auth is initialized, then stays put.
class _AuthGate extends StatefulWidget {
  final AppState appState;
  const _AuthGate({required this.appState});

  @override
  State<_AuthGate> createState() => _AuthGateState();
}

class _AuthGateState extends State<_AuthGate> {
  bool _routed = false;

  @override
  void initState() {
    super.initState();
    _tryRoute();
    widget.appState.addListener(_tryRoute);
  }

  Future<void> _tryRoute() async {
    if (_routed || !widget.appState.authInitialized) return;
    _routed = true;
    widget.appState.removeListener(_tryRoute);

    if (!widget.appState.isAuthenticated) {
      final storedUser = await AuthService.getStoredUser();
      if (storedUser != null) {
        widget.appState.restoreUserSession(storedUser);
      } else {
        final isGuest = await AuthService.isGuestSession();
        final hasSession = await AuthService.hasActiveSession();
        if (isGuest || hasSession) {
          widget.appState.continueAsGuest(clearData: false);
        }
      }
    }

    // After first frame so Navigator is ready
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final target = widget.appState.isAuthenticated
          ? MainNavigation(state: widget.appState)
          : LoginScreen(state: widget.appState);
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => target),
      );
    });
  }

  @override
  void dispose() {
    widget.appState.removeListener(_tryRoute);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Show a blank dark screen briefly while auth resolves
    return const Scaffold(backgroundColor: Color(0xFF0A0D18));
  }
}

class MainNavigation extends StatefulWidget {
  final AppState state;
  final bool autoCheckUpdate;

  const MainNavigation({super.key, required this.state, this.autoCheckUpdate = true});

  @override
  State<MainNavigation> createState() => _MainNavigationState();
}

class _MainNavigationState extends State<MainNavigation> {
  int _currentIndex = 0;
  Timer? _updateTimer;

  @override
  void initState() {
    super.initState();
    if (widget.autoCheckUpdate) {
      _updateTimer = Timer(const Duration(milliseconds: 1600), () {
        if (mounted) {
          _checkForUpdates();
        }
      });
    }
  }

  @override
  void dispose() {
    _updateTimer?.cancel();
    _updateTimer = null;
    super.dispose();
  }

  Future<void> _checkForUpdates() async {
    try {
      final updateInfo = await AppUpdateService.checkForUpdate();
      if (!mounted || updateInfo == null || !updateInfo.hasUpdate) return;
      AppUpdateDialog.show(context, updateInfo);
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final List<Widget> pages = [
      HomeScreen(
        state: widget.state,
        onOpenSearch: () => setState(() => _currentIndex = 2),
        onOpenLiveTv: () => setState(() => _currentIndex = 1),
      ),
      LiveTvScreen(state: widget.state),
      SearchScreen(state: widget.state),
      LibraryScreen(state: widget.state),
    ];

    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: pages,
      ),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _currentIndex,
        onTap: (index) {
          setState(() {
            _currentIndex = index;
          });
        },
        items: const [
          BottomNavigationBarItem(
            icon: Icon(Icons.home_filled),
            activeIcon: Icon(Icons.home_filled, color: AppTheme.primary),
            label: 'Home',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.live_tv_rounded),
            activeIcon: Icon(Icons.live_tv_rounded, color: AppTheme.primary),
            label: 'Live TV',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.search_rounded),
            activeIcon: Icon(Icons.search_rounded, color: AppTheme.primary),
            label: 'Search',
          ),
          BottomNavigationBarItem(
            icon: Icon(Icons.bookmark_outline_rounded),
            activeIcon: Icon(Icons.bookmark_rounded, color: AppTheme.primary),
            label: 'My List',
          ),
        ],
      ),
    );
  }
}
