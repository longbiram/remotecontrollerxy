import 'package:flutter_test/flutter_test.dart';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:pikashow_app/main.dart';
import 'package:pikashow_app/screens/splash_screen.dart';
import 'package:pikashow_app/screens/login_screen.dart';
import 'package:pikashow_app/screens/signup_screen.dart';
import 'package:pikashow_app/models/app_user.dart';
import 'package:pikashow_app/models/content_item.dart';
import 'package:pikashow_app/state/app_state.dart';
import 'package:pikashow_app/config/api_config.dart';
import 'package:pikashow_app/screens/home_screen.dart';
import 'package:pikashow_app/screens/profile_screen.dart';
import 'package:pikashow_app/screens/category_screen.dart';
import 'package:pikashow_app/widgets/content_card.dart';
import 'package:pikashow_app/theme/app_theme.dart';
import 'package:pikashow_app/data/mock_content.dart';
import 'package:pikashow_app/models/watch_progress.dart';
import 'package:pikashow_app/screens/detail_screen.dart';
import 'package:pikashow_app/screens/library_screen.dart';
import 'package:pikashow_app/services/hls_parser_service.dart';
import 'package:pikashow_app/widgets/content_shelf.dart';
import 'package:pikashow_app/widgets/continue_watching_shelf.dart';
import 'package:pikashow_app/models/app_update_info.dart';

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  testWidgets('Unauthenticated launch routes to LoginScreen and can switch to SignupScreen', (WidgetTester tester) async {
    final appState = AppState();

    await tester.pumpWidget(
      MaterialApp(
        home: LoginScreen(state: appState),
      ),
    );
    await tester.pumpAndSettle();

    // Verify Login Screen brand & input fields
    expect(find.text('Sign In'), findsAtLeastNWidgets(1));
    expect(find.text('Email Address'), findsOneWidget);
    expect(find.text('Password'), findsOneWidget);
    expect(find.text('Sign Up'), findsOneWidget);
    expect(find.text('Skip & Continue as Guest'), findsOneWidget);

    // Tap 'Sign Up' button to navigate to SignupScreen
    await tester.tap(find.text('Sign Up'));
    await tester.pumpAndSettle();

    // Verify SignupScreen elements
    expect(find.text('Create Account'), findsAtLeastNWidgets(1));
    expect(find.text('Full Name'), findsOneWidget);
    expect(find.text('Confirm Password'), findsOneWidget);
  });

  testWidgets('SplashScreen navigates to LoginScreen when unauthenticated', (WidgetTester tester) async {
    final appState = AppState();

    await tester.pumpWidget(
      MaterialApp(
        home: SplashScreen(
          state: appState,
          autoNavigate: false,
        ),
      ),
    );

    // Initial frame renders brand elements
    await tester.pump();
    expect(find.text('PIKA'), findsOneWidget);
    expect(find.text('SHOW'), findsOneWidget);
    expect(find.text('Skip'), findsOneWidget);

    // Tap Skip button on splash screen
    await tester.tap(find.text('Skip'));
    await tester.pumpAndSettle();

    // Unauthenticated user is sent to LoginScreen
    expect(find.text('Sign In'), findsAtLeastNWidgets(1));
    expect(find.text('Email Address'), findsOneWidget);
  });

  testWidgets('LoginScreen guest mode allows browsing MainNavigation', (WidgetTester tester) async {
    final appState = AppState();

    await tester.pumpWidget(
      MaterialApp(
        home: LoginScreen(state: appState),
      ),
    );
    await tester.pumpAndSettle();

    // Tap 'Skip & Continue as Guest'
    await tester.tap(find.text('Skip & Continue as Guest'));
    await tester.pumpAndSettle();

    // Verify MainNavigation is loaded
    expect(find.text('Home'), findsOneWidget);
    expect(find.text('Live TV'), findsAtLeastNWidgets(1));
    expect(find.text('Search'), findsOneWidget);
  });

  testWidgets('MainNavigation header displays brand and opens Chromecast sheet', (WidgetTester tester) async {
    final appState = AppState();
    appState.continueAsGuest();

    await tester.pumpWidget(
      MaterialApp(
        home: MainNavigation(state: appState),
      ),
    );
    await tester.pumpAndSettle();

    // Verify header brand elements
    expect(find.text('PIKA'), findsOneWidget);
    expect(find.text('SHOW'), findsOneWidget);

    // Verify Chromecast button exists in header and tap it
    final castFinder = find.byIcon(Icons.cast_rounded);
    expect(castFinder, findsOneWidget);
    await tester.tap(castFinder);
    await tester.pump(const Duration(milliseconds: 500));

    // Verify CastSheet opens with dynamic tabs
    expect(find.text('Cast to Smart TV'), findsOneWidget);
    expect(find.text('Chromecast, Android TV & DLNA'), findsOneWidget);
    expect(find.text('Wi-Fi / LAN Cast'), findsOneWidget);
    expect(find.text('Bluetooth Cast'), findsOneWidget);
  });

  testWidgets('LoginScreen form input validation works correctly', (WidgetTester tester) async {
    final appState = AppState();

    await tester.pumpWidget(
      MaterialApp(
        home: LoginScreen(state: appState),
      ),
    );
    await tester.pumpAndSettle();

    // Tap Sign In with empty fields
    await tester.tap(find.text('Sign In'));
    await tester.pumpAndSettle();

    // Expect validation errors
    expect(find.text('Please enter your email'), findsOneWidget);
    expect(find.text('Please enter your password'), findsOneWidget);
  });

  testWidgets('SignupScreen form validation flags mismatched passwords', (WidgetTester tester) async {
    final appState = AppState();

    await tester.pumpWidget(
      MaterialApp(
        home: SignupScreen(state: appState),
      ),
    );
    await tester.pumpAndSettle();

    // Fill fields with mismatched passwords
    await tester.enterText(find.byType(TextFormField).at(0), 'John Doe');
    await tester.enterText(find.byType(TextFormField).at(1), 'john@example.com');
    await tester.enterText(find.byType(TextFormField).at(2), 'password123');
    await tester.enterText(find.byType(TextFormField).at(3), 'different_pass');

    // Tap Create Account button
    await tester.tap(find.widgetWithText(ElevatedButton, 'Create Account'));
    await tester.pumpAndSettle();

    expect(find.text('Passwords do not match'), findsOneWidget);
  });

  test('AppUser model serializes and deserializes cleanly', () {
    final user = AppUser(
      id: 42,
      name: 'Pika Fan',
      email: 'fan@pikashow.app',
      avatar: 'https://example.com/avatar.jpg',
      token: 'test_token_abc',
      status: 'active',
      createdAt: DateTime(2026, 10, 19, 20, 0),
    );

    final json = user.toJson();
    expect(json['id'], equals(42));
    expect(json['name'], equals('Pika Fan'));
    expect(json['email'], equals('fan@pikashow.app'));

    final restored = AppUser.fromJson(json);
    expect(restored.id, equals(42));
    expect(restored.name, equals('Pika Fan'));
    expect(restored.email, equals('fan@pikashow.app'));
    expect(restored.token, equals('test_token_abc'));
  });

  test('ApiConfig de-obfuscates production Base URL and generates HMAC security headers', () {
    final url = ApiConfig.baseUrl;
    expect(url.startsWith('http'), isTrue);

    final secretKey = ApiConfig.appSecretKey;
    expect(secretKey.isNotEmpty, isTrue);

    final headers = ApiConfig.getSecureHeaders('/api/content.php');
    expect(headers['X-App-Key'], equals(secretKey));
    expect(headers['X-App-Time'], isNotNull);
    expect(headers['X-App-Signature'], isNotNull);
    expect(headers['X-App-Signature']!.length, equals(64)); // SHA-256 hex string length
  });

  testWidgets('HomeScreen header has Profile button next to Chromecast and opens ProfileScreen', (WidgetTester tester) async {
    final appState = AppState();
    appState.continueAsGuest();

    await tester.pumpWidget(
      MaterialApp(
        home: HomeScreen(
          state: appState,
          onOpenSearch: () {},
          onOpenLiveTv: () {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Verify both Chromecast button and Profile avatar exist
    expect(find.byIcon(Icons.cast_rounded), findsOneWidget);
    expect(find.byType(CircleAvatar), findsOneWidget);

    // Tap the Profile button
    await tester.tap(find.byType(CircleAvatar));
    await tester.pumpAndSettle();

    // Verify ProfileScreen opened with User Profile title, subscription section, and policies
    expect(find.text('User Profile'), findsOneWidget);
    expect(find.text('VIP Subscription'), findsOneWidget);
    expect(find.text('About Us'), findsOneWidget);
    expect(find.text('Terms and Conditions'), findsOneWidget);
    expect(find.text('Privacy Policy'), findsOneWidget);
    expect(find.text('Refund Policy'), findsOneWidget);
  });

  testWidgets('ProfileScreen displays available plans and opens policy modal', (WidgetTester tester) async {
    final appState = AppState();
    appState.continueAsGuest();

    await tester.pumpWidget(
      MaterialApp(
        home: ProfileScreen(state: appState),
      ),
    );
    await tester.pumpAndSettle();

    // Verify plans rendered
    expect(find.text('Monthly VIP Pass'), findsOneWidget);
    expect(find.text('Quarterly Cinephile'), findsOneWidget);
    expect(find.text('Annual Ultra VIP'), findsOneWidget);

    // Scroll to 'About Us' and tap to open policy sheet
    await tester.scrollUntilVisible(find.text('About Us'), 200);
    await tester.tap(find.text('About Us'));
    await tester.pumpAndSettle();

    // Verify modal opened with platform information
    expect(find.text('About Us'), findsAtLeastNWidgets(2)); // tile and modal header
    expect(find.textContaining('Welcome to PikaShow'), findsOneWidget);
  });

  testWidgets('ContentCard displays attractive PREMIUM badge when item.isPremium is true', (WidgetTester tester) async {
    final premiumItem = ContentItem(
      id: 'test_prem_1',
      title: 'Premium Test Movie',
      type: ContentType.hollywood,
      posterUrl: '',
      bannerUrl: '',
      streamUrl: 'https://example.com/stream.m3u8',
      rating: 8.8,
      duration: '2h 10m',
      year: '2026',
      synopsis: 'A premium blockbuster movie.',
      genres: ['Action', 'Sci-Fi'],
      languages: ['English', 'Hindi'],
      availableQualities: ['4K UHD', '1080p'],
      isPremium: true,
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ContentCard(item: premiumItem),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Verify quality text and PREMIUM badge are present
    expect(find.text('4K UHD'), findsOneWidget);
    expect(find.text('PREMIUM'), findsOneWidget);
    expect(find.byIcon(Icons.workspace_premium_rounded), findsOneWidget);
  });

  test('Subscription lifecycle: select plan, submit transaction ID, and cancel', () async {
    final appState = AppState();
    appState.setTestUser(const AppUser(id: 1, name: 'Test User', email: 'test@example.com', token: 'mock_token_123'));

    // Initial state: no active sub
    expect(appState.hasActiveSubscription, isFalse);
    expect(appState.availablePlans.isNotEmpty, isTrue);

    // 1. User selects a plan -> status changes to pending_approval
    final firstPlan = appState.availablePlans.first;
    final selectSuccess = await appState.selectPlan(firstPlan);
    expect(selectSuccess, isTrue);
    expect(appState.currentSubscription, isNotNull);
    expect(appState.currentSubscription!.status, equals('pending_approval'));

    // 2. User submits transaction ID -> status changes to payment_submitted
    final submitSuccess = await appState.submitPaymentTransaction('UPI-UTR-9988776655');
    expect(submitSuccess, isTrue);
    expect(appState.currentSubscription!.status, equals('payment_submitted'));
    expect(appState.currentSubscription!.transactionId, equals('UPI-UTR-9988776655'));

    // 3. User cancels subscription
    final cancelSuccess = await appState.cancelActiveSubscription();
    expect(cancelSuccess, isTrue);
    expect(appState.hasActiveSubscription, isFalse);
    expect(appState.currentSubscription == null || appState.currentSubscription!.isCancelled, isTrue);
  });

  testWidgets('HomeScreen displays Latest Movies shelf and separate Movies & Live TV category chips', (WidgetTester tester) async {
    final state = AppState();

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: HomeScreen(
          state: state,
          onOpenSearch: () {},
          onOpenLiveTv: () {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Verify category chips exist
    expect(find.text('All'), findsOneWidget);
    expect(find.text('Movies'), findsOneWidget);
    expect(find.text('Live TV'), findsOneWidget);
    expect(find.text('Bollywood'), findsOneWidget);
    expect(find.text('Hollywood'), findsOneWidget);
    expect(find.text('Web Series'), findsOneWidget);

    // Verify 'Latest Movies' shelf is visible on 'All'
    expect(find.text('Latest Movies'), findsOneWidget);

    // Tap on 'Movies' category chip
    await tester.tap(find.text('Movies'));
    await tester.pumpAndSettle();

    expect(state.homeCategory, equals('movies'));
    expect(find.text('All Latest Movies'), findsOneWidget);

    // Verify movies are sorted latest first (e.g. 2024 before 2023)
    final latest = state.latestMovies;
    expect(latest.isNotEmpty, isTrue);
    for (int i = 0; i < latest.length - 1; i++) {
      final y1 = int.tryParse(latest[i].year) ?? 0;
      final y2 = int.tryParse(latest[i + 1].year) ?? 0;
      expect(y1 >= y2, isTrue, reason: 'Expected movie $i year $y1 >= next movie year $y2');
    }

    // Tap on 'Live TV' category chip
    await tester.tap(find.text('Live TV'));
    await tester.pumpAndSettle();

    expect(state.homeCategory, equals('liveTv'));
    expect(find.text('Sports & Cricket Live'), findsOneWidget);
  });

  testWidgets('See All button on shelf navigates to CategoryScreen with titles and filter tools', (WidgetTester tester) async {
    final state = AppState();

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: HomeScreen(
          state: state,
          onOpenSearch: () {},
          onOpenLiveTv: () {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Verify 'See All' buttons exist on home shelves
    final seeAllButtons = find.text('See All');
    expect(seeAllButtons, findsAtLeastNWidgets(1));

    // Tap the first 'See All' button (Latest Movies shelf)
    await tester.tap(seeAllButtons.first);
    await tester.pumpAndSettle();

    // Verify CategoryScreen opens with title, title count badge, and search filter
    expect(find.byType(CategoryScreen), findsOneWidget);
    expect(find.text('Latest Movies'), findsAtLeastNWidgets(1));
    expect(find.textContaining('Titles'), findsOneWidget);
    expect(find.text('Latest First'), findsOneWidget);
    expect(find.text('Top Rated'), findsOneWidget);
    expect(find.text('Most Viewed'), findsOneWidget);

    // Navigate back to HomeScreen
    await tester.tap(find.byIcon(Icons.arrow_back_ios_new_rounded));
    await tester.pumpAndSettle();

    expect(find.byType(HomeScreen), findsOneWidget);
  });

  testWidgets('Category chips navigation switches views to Bollywood, Hollywood, Web Series, and All', (WidgetTester tester) async {
    final state = AppState();

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: HomeScreen(
          state: state,
          onOpenSearch: () {},
          onOpenLiveTv: () {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    // 1. Tap Bollywood chip
    await tester.tap(find.text('Bollywood'));
    await tester.pumpAndSettle();
    expect(state.homeCategory, equals('bollywood'));
    expect(find.text('Bollywood Releases (Latest First)'), findsOneWidget);

    // 2. Tap Hollywood chip
    await tester.tap(find.text('Hollywood'));
    await tester.pumpAndSettle();
    expect(state.homeCategory, equals('hollywood'));
    expect(find.text('Hollywood Hits (Latest First)'), findsOneWidget);

    // 3. Tap Web Series chip
    await tester.tap(find.text('Web Series'));
    await tester.pumpAndSettle();
    expect(state.homeCategory, equals('series'));
    expect(find.text('Binge-Worthy Web Series'), findsAtLeastNWidgets(1));

    // 4. Tap All chip
    await tester.tap(find.text('All'));
    await tester.pumpAndSettle();
    expect(state.homeCategory, equals('all'));
    expect(find.text('Latest Movies'), findsOneWidget);
  });

  testWidgets('CategoryScreen search and sorting filters and reorders items properly', (WidgetTester tester) async {
    final state = AppState();
    final items = state.latestMovies;

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: CategoryScreen(
          title: 'Latest Movies',
          subtitle: 'Fresh releases & cinema blockbusters',
          initialItems: items,
          state: state,
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Verify initial render
    expect(find.text('Latest Movies'), findsOneWidget);
    expect(find.text('${items.length} Titles'), findsOneWidget);

    // Test sorting by Top Rated
    await tester.tap(find.text('Top Rated'));
    await tester.pumpAndSettle();

    // Test in-category search filter
    final searchInput = find.byType(TextField);
    expect(searchInput, findsOneWidget);

    await tester.enterText(searchInput, items.first.title);
    await tester.pumpAndSettle();

    expect(find.text(items.first.title), findsAtLeastNWidgets(1));
  });

  test('WatchProgress model calculates progressPercentage, isCompleted and remainingFormatted accurately', () {
    final item = MockContent.allContent.first;

    // 1. Halfway watched (1800s / 3600s = 50%)
    final halfway = WatchProgress(
      contentId: item.id,
      item: item,
      positionSeconds: 1800,
      durationSeconds: 3600,
      updatedAt: DateTime.now(),
    );
    expect(halfway.progressPercentage, equals(0.5));
    expect((halfway.progressPercentage * 100).toInt(), equals(50));
    expect(halfway.isCompleted, isFalse);
    expect(halfway.remainingFormatted, equals('30m left'));

    // 2. Almost complete (6900s / 7200s = ~95.8%)
    final almostDone = WatchProgress(
      contentId: item.id,
      item: item,
      positionSeconds: 6900,
      durationSeconds: 7200,
      updatedAt: DateTime.now(),
    );
    expect(almostDone.isCompleted, isTrue);

    // 3. Serialization round-trip
    final json = halfway.toJson();
    final restored = WatchProgress.fromJson(json);
    expect(restored.contentId, equals(halfway.contentId));
    expect(restored.positionSeconds, equals(1800));
    expect(restored.durationSeconds, equals(3600));
  });

  testWidgets('HomeScreen displays ContinueWatchingShelf when user leaves content halfway and allows dismissal', (WidgetTester tester) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);

    final state = AppState();
    final movie = state.latestMovies.first;

    // Simulate leaving movie halfway through (1200 seconds in, 3600 duration)
    await state.saveWatchProgress(
      item: movie,
      position: const Duration(seconds: 1200),
      duration: const Duration(seconds: 3600),
    );

    expect(state.continueWatching.length, equals(1));
    expect(state.getSavedPosition(movie.id), equals(const Duration(seconds: 1200)));

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: HomeScreen(
          state: state,
          onOpenSearch: () {},
          onOpenLiveTv: () {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Verify Continue Watching shelf is present on Home Screen
    expect(find.byType(ContinueWatchingShelf), findsOneWidget);
    expect(find.text('Continue Watching'), findsOneWidget);
    expect(find.text(movie.title), findsAtLeastNWidgets(1));
    expect(find.text('33%'), findsOneWidget);
    expect(find.textContaining('40m left'), findsOneWidget);

    // Test dismiss button (X) on continue watching card
    final dismissBtn = find.byIcon(Icons.close_rounded);
    expect(dismissBtn, findsOneWidget);

    await tester.ensureVisible(dismissBtn);
    await tester.tap(dismissBtn);
    await tester.pumpAndSettle();

    // Verify progress is removed and shelf is no longer rendered
    expect(state.continueWatching.isEmpty, isTrue);
    expect(find.byType(ContinueWatchingShelf), findsNothing);
  });

  testWidgets('ContinueWatchingShelf displays web series episode information accurately', (WidgetTester tester) async {
    final state = AppState();
    final series = MockContent.allContent.firstWhere((i) => i.isSeries);
    const episode = Episode(
      id: 'ep_test_01',
      episodeNumber: 3,
      title: 'The Heist',
      duration: '40m',
      description: 'Test description',
      thumbnail: 'https://images.unsplash.com/photo-1578328819058-b69f3a3b0f6b?auto=format&fit=crop&w=600&q=80',
      streamUrl: 'https://vjs.zencdn.net/v/oceans.mp4',
    );

    // Simulate watching an episode of a web series (900s of 2400s -> 37%, 25m left)
    await state.saveWatchProgress(
      item: series,
      episode: episode,
      position: const Duration(seconds: 900),
      duration: const Duration(seconds: 2400),
    );

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: HomeScreen(
          state: state,
          onOpenSearch: () {},
          onOpenLiveTv: () {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Episode subtitle and percentage should be clearly visible
    expect(find.textContaining('Episode 3'), findsOneWidget);
    expect(find.textContaining('25m left'), findsOneWidget);
    expect(find.text('37%'), findsOneWidget);
  });

  test('HlsParserService parses master m3u8 playlist variants and audio tracks properly', () {
    const testM3u8 = '''#EXTM3U
#EXT-X-VERSION:3
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Hindi",DEFAULT=YES,LANGUAGE="hin",URI="audio/hin/prog_index.m3u8"
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="English",DEFAULT=NO,LANGUAGE="eng",URI="audio/eng/prog_index.m3u8"
#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="Tamil",DEFAULT=NO,LANGUAGE="tam",URI="audio/tam/prog_index.m3u8"
#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,AUDIO="audio"
variants/1080p.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=2500000,RESOLUTION=1280x720,AUDIO="audio"
variants/720p.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=1000000,RESOLUTION=854x480,AUDIO="audio"
variants/480p.m3u8
''';

    final baseUri = Uri.parse('https://cdn.example.com/stream/master.m3u8');
    final dummyItem = MockContent.allContent.first;

    final info = HlsParserService.parseManifestContent(
      content: testM3u8,
      baseUri: baseUri,
      item: dummyItem,
      streamUrl: 'https://cdn.example.com/stream/master.m3u8',
    );

    // 1. Resolutions check
    expect(info.resolutions.length, equals(4)); // Auto + 1080p + 720p + 480p
    expect(info.resolutions[0].isAuto, isTrue);
    expect(info.resolutions[0].streamUrl, equals('https://cdn.example.com/stream/master.m3u8'));

    expect(info.resolutions[1].label, equals('1080p Full HD'));
    expect(info.resolutions[1].resolution, equals('1920x1080'));
    expect(info.resolutions[1].streamUrl, equals('https://cdn.example.com/stream/variants/1080p.m3u8'));

    expect(info.resolutions[2].label, equals('720p HD'));
    expect(info.resolutions[2].resolution, equals('1280x720'));
    expect(info.resolutions[2].streamUrl, equals('https://cdn.example.com/stream/variants/720p.m3u8'));

    expect(info.resolutions[3].label, equals('480p SD'));
    expect(info.resolutions[3].resolution, equals('854x480'));
    expect(info.resolutions[3].streamUrl, equals('https://cdn.example.com/stream/variants/480p.m3u8'));

    // 2. Audio tracks check
    expect(info.audioTracks.length, equals(3));
    expect(info.audioTracks[0].code, equals('hin'));
    expect(info.audioTracks[0].name, equals('Hindi [Original]'));
    expect(info.audioTracks[0].isDefault, isTrue);
    expect(info.audioTracks[0].streamUrl, equals('https://cdn.example.com/stream/audio/hin/prog_index.m3u8'));

    expect(info.audioTracks[1].code, equals('eng'));
    expect(info.audioTracks[1].name, equals('English'));
    expect(info.audioTracks[1].isDefault, isFalse);

    expect(info.audioTracks[2].code, equals('tam'));
    expect(info.audioTracks[2].name, equals('Tamil'));
  });

  testWidgets('DetailScreen bookmark button toggles saved status, updates icon, and updates AppState', (WidgetTester tester) async {
    final state = AppState();
    final item = MockContent.allContent.firstWhere((i) => !state.isFavourite(i.id));

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: DetailScreen(item: item, state: state),
      ),
    );
    await tester.pumpAndSettle();

    // Verify initial unsaved state (outlined bookmark icon)
    expect(find.byIcon(Icons.bookmark_add_outlined), findsOneWidget);
    expect(find.byIcon(Icons.bookmark_rounded), findsNothing);
    expect(state.isFavourite(item.id), isFalse);

    // Tap the bookmark button to save
    await tester.tap(find.byIcon(Icons.bookmark_add_outlined));
    await tester.pumpAndSettle();

    // Verify saved state in AppState and UI
    expect(state.isFavourite(item.id), isTrue);
    expect(find.byIcon(Icons.bookmark_rounded), findsOneWidget);
    expect(find.textContaining('Saved "${item.title}" to Favourites'), findsOneWidget);

    // Tap again to unsave / remove
    await tester.tap(find.byIcon(Icons.bookmark_rounded));
    await tester.pumpAndSettle();

    // Verify unsaved state restored
    expect(state.isFavourite(item.id), isFalse);
    expect(find.byIcon(Icons.bookmark_add_outlined), findsOneWidget);
    expect(find.textContaining('Removed "${item.title}" from Saved Favourites'), findsOneWidget);
  });

  testWidgets('LibraryScreen displays saved favourites reactively, allows quick card removal and undo', (WidgetTester tester) async {
    final state = AppState();
    expect(state.favouriteItems.isNotEmpty, isTrue);
    final initialCount = state.favouriteItems.length;
    final firstItem = state.favouriteItems.first;

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: LibraryScreen(state: state),
      ),
    );
    await tester.pumpAndSettle();

    // Verify Tab count matches favourites count
    expect(find.text('Favourites ($initialCount)'), findsOneWidget);
    expect(find.text(firstItem.title), findsAtLeastNWidgets(1));

    // Tap the quick bookmark remove button on the first card
    final cardRemoveBtn = find.descendant(
      of: find.byType(GridView),
      matching: find.byIcon(Icons.bookmark_rounded),
    ).first;

    await tester.tap(cardRemoveBtn);
    await tester.pumpAndSettle();

    // Verify count decremented and item removed
    expect(state.isFavourite(firstItem.id), isFalse);
    expect(state.favouriteItems.length, equals(initialCount - 1));
    expect(find.text('Favourites (${initialCount - 1})'), findsOneWidget);
    expect(find.textContaining('Removed "${firstItem.title}" from Saved'), findsOneWidget);

    // Tap UNDO on SnackBar
    await tester.tap(find.text('UNDO'));
    await tester.pumpAndSettle();

    // Verify item is restored to favourites
    expect(state.isFavourite(firstItem.id), isTrue);
    expect(state.favouriteItems.length, equals(initialCount));
    expect(find.text('Favourites ($initialCount)'), findsOneWidget);
  });

  test('AppState favourites persistence stores and reloads from SharedPreferences', () async {
    final state1 = AppState();
    final customItem = MockContent.allContent.firstWhere((i) => !state1.isFavourite(i.id));

    await state1.toggleFavourite(customItem);
    expect(state1.isFavourite(customItem.id), isTrue);

    // Create a new AppState instance simulating app restart
    final state2 = AppState();
    await state2.initFavourites();

    expect(state2.isFavourite(customItem.id), isTrue);
    expect(state2.favouriteItems.any((i) => i.id == customItem.id), isTrue);
  });

  testWidgets('DetailScreen More Like This shelf shows recommendations matching genre, category, or audio languages', (WidgetTester tester) async {
    final state = AppState();
    final item = MockContent.allContent.firstWhere((i) => i.id == 'prom_01'); // Jawan: Extended Cut

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: DetailScreen(item: item, state: state),
      ),
    );
    await tester.pumpAndSettle();

    // Verify "More Like This" shelf is rendered with subtitle
    expect(find.text('More Like This'), findsOneWidget);
    expect(find.text('Similar titles by genre, category & audio languages'), findsOneWidget);

    // Verify related items in ContentShelf match genre, category, or audio languages
    final contentShelfFinder = find.widgetWithText(ContentShelf, 'More Like This');
    expect(contentShelfFinder, findsOneWidget);
    final shelf = tester.widget<ContentShelf>(contentShelfFinder);

    expect(shelf.items.isNotEmpty, isTrue);
    for (final candidate in shelf.items) {
      expect(candidate.id != item.id, isTrue);

      final sameCategory = candidate.type == item.type ||
          (item.isMovie && candidate.isMovie) ||
          (item.isSeries && candidate.isSeries) ||
          (item.isLive && candidate.isLive);

      final targetGenres = item.genres.map((g) => g.toLowerCase()).toSet();
      final sameGenre = candidate.genres.any((g) => targetGenres.contains(g.toLowerCase()));

      final targetLangs = item.languages.map((l) => l.toLowerCase()).toSet();
      final sameLanguage = candidate.languages.any((l) =>
          targetLangs.contains(l.toLowerCase()) ||
          targetLangs.any((t) => l.toLowerCase().contains(t) || t.contains(l.toLowerCase())));

      expect(
        sameCategory || sameGenre || sameLanguage,
        isTrue,
        reason: 'Item "${candidate.title}" must share category, genre, or audio language with "${item.title}"',
      );
    }
  });

  test('AppUpdateInfo correctly parses update payload and detects newer versions', () {
    final payload = {
      'status': 'success',
      'has_update': true,
      'latest_version_code': 2,
      'latest_version_name': '1.0.1',
      'apk_url': 'https://daksocial.in/pikashow/uploads/app-release.apk',
      'website_url': 'https://daksocial.in/pikashow',
      'force_update': false,
      'release_notes': '• Bug fixes\n• Video improvements',
    };

    final info = AppUpdateInfo.fromJson(payload, 1);
    expect(info.hasUpdate, isTrue);
    expect(info.latestVersionCode, 2);
    expect(info.latestVersionName, '1.0.1');
    expect(info.apkUrl, 'https://daksocial.in/pikashow/uploads/app-release.apk');
    expect(info.forceUpdate, isFalse);
    expect(info.releaseNotes.contains('Bug fixes'), isTrue);

    final noUpdatePayload = {
      'status': 'success',
      'has_update': false,
      'latest_version_code': 2,
      'latest_version_name': '1.0.1',
      'apk_url': 'https://daksocial.in/pikashow/uploads/app-release.apk',
      'force_update': false,
      'release_notes': 'None',
    };
    final infoUpToDate = AppUpdateInfo.fromJson(noUpdatePayload, 2);
    expect(infoUpToDate.hasUpdate, isFalse);
  });
}

