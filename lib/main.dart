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
import 'services/app_update_service.dart';
import 'widgets/app_update_dialog.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      systemNavigationBarColor: AppTheme.surface,
      systemNavigationBarIconBrightness: Brightness.light,
    ),
  );
  runApp(const PikaShowApp());
}

class PikaShowApp extends StatefulWidget {
  final bool showSplash;
  const PikaShowApp({super.key, this.showSplash = true});

  @override
  State<PikaShowApp> createState() => _PikaShowAppState();
}

final RouteObserver<ModalRoute<void>> routeObserver = RouteObserver<ModalRoute<void>>();

class _PikaShowAppState extends State<PikaShowApp> {
  final AppState _appState = AppState();

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _appState,
      builder: (context, _) {
        return MaterialApp(
          title: 'PikaShow',
          debugShowCheckedModeBanner: false,
          theme: AppTheme.darkTheme,
          navigatorObservers: [routeObserver],
          home: widget.showSplash
              ? SplashScreen(state: _appState)
              : (_appState.hasActiveUser
                  ? MainNavigation(state: _appState)
                  : LoginScreen(state: _appState)),
        );
      },
    );
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
