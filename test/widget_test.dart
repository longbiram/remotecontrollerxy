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
import 'package:pikashow_app/services/auth_service.dart';
import 'package:pikashow_app/widgets/content_shelf.dart';
import 'package:pikashow_app/widgets/continue_watching_shelf.dart';
import 'package:pikashow_app/models/app_update_info.dart';
import 'package:pikashow_app/widgets/player_dialogs.dart';
import 'package:pikashow_app/models/player_config.dart';
import 'package:pikashow_app/services/local_hls_server.dart';

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

  test('HlsParserService parses BunnyCDN shaka-packager master playlist properly', () {
    const bunnyM3u8 = '''#EXTM3U
## Generated with https://github.com/shaka-project/shaka-packager version v3.9.3-0a8ba4f-release
#EXT-X-INDEPENDENT-SEGMENTS
#EXT-X-MEDIA:TYPE=AUDIO,URI="audio_1/audio.m3u8",GROUP-ID="audio",LANGUAGE="hi",NAME="Hindi",DEFAULT=YES,AUTOSELECT=YES,CHANNELS="2"
#EXT-X-MEDIA:TYPE=AUDIO,URI="audio_2/audio.m3u8",GROUP-ID="audio",LANGUAGE="en",NAME="English",DEFAULT=NO,AUTOSELECT=YES,CHANNELS="2"
#EXT-X-MEDIA:TYPE=AUDIO,URI="audio_3/audio.m3u8",GROUP-ID="audio",LANGUAGE="ja",NAME="Japanese",DEFAULT=NO,AUTOSELECT=YES,CHANNELS="2"
#EXT-X-STREAM-INF:BANDWIDTH=1202977,AVERAGE-BANDWIDTH=539980,CODECS="avc1.640015,mp4a.40.2",RESOLUTION=426x240,FRAME-RATE=23.981,VIDEO-RANGE=SDR,AUDIO="audio",CLOSED-CAPTIONS=NONE
240p/video.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=1518251,AVERAGE-BANDWIDTH=748074,CODECS="avc1.64001e,mp4a.40.2",RESOLUTION=640x360,FRAME-RATE=23.981,VIDEO-RANGE=SDR,AUDIO="audio",CLOSED-CAPTIONS=NONE
360p/video.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=2450300,AVERAGE-BANDWIDTH=996021,CODECS="avc1.64001e,mp4a.40.2",RESOLUTION=853x480,FRAME-RATE=23.981,VIDEO-RANGE=SDR,AUDIO="audio",CLOSED-CAPTIONS=NONE
480p/video.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=4588354,AVERAGE-BANDWIDTH=1532424,CODECS="avc1.64001f,mp4a.40.2",RESOLUTION=1280x720,FRAME-RATE=23.981,VIDEO-RANGE=SDR,AUDIO="audio",CLOSED-CAPTIONS=NONE
720p/video.m3u8
''';

    final baseUri = Uri.parse('https://vz-6d958ab0-306.b-cdn.net/31940f13-0917-4532-a64a-2e10fc1736d7/playlist.m3u8');
    final dummyItem = MockContent.allContent.first;

    final info = HlsParserService.parseManifestContent(
      content: bunnyM3u8,
      baseUri: baseUri,
      item: dummyItem,
      streamUrl: baseUri.toString(),
    );

    // Auto + 4 profiles (240p, 360p, 480p, 720p)
    expect(info.resolutions.length, equals(5));
    expect(info.resolutions[0].isAuto, isTrue);
    expect(info.resolutions[4].label, equals('720p HD'));
    expect(info.resolutions[4].streamUrl, equals('https://vz-6d958ab0-306.b-cdn.net/31940f13-0917-4532-a64a-2e10fc1736d7/720p/video.m3u8'));

    // Audio tracks: Hindi (default), English, Japanese
    expect(info.audioTracks.length, equals(3));
    expect(info.audioTracks[0].name, equals('Hindi [Original]'));
    expect(info.audioTracks[0].isDefault, isTrue);
    expect(info.audioTracks[1].name, equals('English'));
    expect(info.audioTracks[2].name, equals('Japanese'));
  });

  test('HlsParserService.buildCustomPlaylist and LocalHlsServer dynamically configure audio and resolution', () async {
    const bunnyM3u8 = '''#EXTM3U
#EXT-X-INDEPENDENT-SEGMENTS
#EXT-X-MEDIA:TYPE=AUDIO,URI="audio_1/audio.m3u8",GROUP-ID="audio",LANGUAGE="hi",NAME="Hindi",DEFAULT=YES,AUTOSELECT=YES,CHANNELS="2"
#EXT-X-MEDIA:TYPE=AUDIO,URI="audio_2/audio.m3u8",GROUP-ID="audio",LANGUAGE="en",NAME="English",DEFAULT=NO,AUTOSELECT=YES,CHANNELS="2"
#EXT-X-MEDIA:TYPE=AUDIO,URI="audio_3/audio.m3u8",GROUP-ID="audio",LANGUAGE="ja",NAME="Japanese",DEFAULT=NO,AUTOSELECT=YES,CHANNELS="2"
#EXT-X-STREAM-INF:BANDWIDTH=1202977,RESOLUTION=426x240,AUDIO="audio"
240p/video.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=4588354,RESOLUTION=1280x720,AUDIO="audio"
720p/video.m3u8
''';

    final baseUri = Uri.parse('https://vz-6d958ab0-306.b-cdn.net/31940f13-0917-4532-a64a-2e10fc1736d7/playlist.m3u8');
    const targetAudio = AudioTrack(code: 'en', name: 'English');
    const targetResolution = PlayerResolution(label: '720p HD', resolution: '1280x720', bitrate: '4.6 Mbps');

    final customized = HlsParserService.buildCustomPlaylist(
      rawManifest: bunnyM3u8,
      baseUri: baseUri,
      selectedAudio: targetAudio,
      selectedResolution: targetResolution,
    );

    // English must be marked DEFAULT=YES
    expect(customized, contains('LANGUAGE="en",NAME="English",DEFAULT=YES,AUTOSELECT=YES'));
    // Hindi and Japanese must NOT be DEFAULT=YES
    expect(customized, contains('LANGUAGE="hi",NAME="Hindi",DEFAULT=NO,AUTOSELECT=NO'));
    expect(customized, contains('LANGUAGE="ja",NAME="Japanese",DEFAULT=NO,AUTOSELECT=NO'));
    // Relative URIs must be converted to absolute URLs
    expect(customized, contains('https://vz-6d958ab0-306.b-cdn.net/31940f13-0917-4532-a64a-2e10fc1736d7/audio_2/audio.m3u8'));
    // Only 720p stream variant must be included, 240p must be filtered out
    expect(customized, contains('1280x720'));
    expect(customized, contains('https://vz-6d958ab0-306.b-cdn.net/31940f13-0917-4532-a64a-2e10fc1736d7/720p/video.m3u8'));
    expect(customized, isNot(contains('426x240')));

    // Test serving via LocalHlsServer
    final localUrl = await LocalHlsServer.serveManifest(customized);
    expect(localUrl.startsWith('http://127.0.0.1:'), isTrue);
    expect(localUrl.endsWith('.m3u8'), isTrue);
    await LocalHlsServer.stop();
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
    await state.toggleFavourite(state.latestMovies.first);
    await state.toggleFavourite(state.latestMovies.last);
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

  testWidgets('User session persists across app close / restart and routes to MainNavigation', (WidgetTester tester) async {
    // 1. Simulate user logging in and session being saved to disk
    final user = const AppUser(
      id: 88,
      name: 'Persistent User',
      email: 'persistent@example.com',
      token: 'persisted_prod_token_123',
    );
    await AuthService.saveUserSession(user, user.token);

    // 2. Simulate fresh app launch (app was closed and reopened)
    final freshAppState = AppState();
    await freshAppState.initAuth();

    expect(freshAppState.isAuthenticated, isTrue);
    expect(freshAppState.currentUser?.email, 'persistent@example.com');

    // 3. Mount SplashScreen with the fresh AppState
    await tester.pumpWidget(
      MaterialApp(
        home: SplashScreen(
          state: freshAppState,
          autoNavigate: false,
        ),
      ),
    );
    await tester.pump();

    // 4. Tap Skip
    await tester.tap(find.text('Skip'));
    await tester.pumpAndSettle();

    // 5. User MUST land on MainNavigation, NOT LoginScreen
    expect(find.text('Home'), findsOneWidget);
    expect(find.text('Live TV'), findsAtLeastNWidgets(1));
    expect(find.text('Sign In'), findsNothing);
  });

  testWidgets('Guest session persists across app close / restart and routes to MainNavigation', (WidgetTester tester) async {
    // 1. Simulate user continuing as guest previously
    await AuthService.setGuestMode(true);

    // 2. Simulate fresh app launch
    final freshAppState = AppState();
    await freshAppState.initAuth();

    expect(freshAppState.isAuthenticated, isTrue);
    expect(freshAppState.isGuest, isTrue);

    // 3. Mount SplashScreen with fresh AppState
    await tester.pumpWidget(
      MaterialApp(
        home: SplashScreen(
          state: freshAppState,
          autoNavigate: false,
        ),
      ),
    );
    await tester.pump();

    // 4. Tap Skip
    await tester.tap(find.text('Skip'));
    await tester.pumpAndSettle();

    // 5. Guest MUST land on MainNavigation, NOT LoginScreen
    expect(find.text('Home'), findsOneWidget);
    expect(find.text('Live TV'), findsAtLeastNWidgets(1));
    expect(find.text('Sign In'), findsNothing);
  });

  testWidgets('Continue watching persists across app restart and renders on HomeScreen', (WidgetTester tester) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);

    // 1. First session: User logs in and watches a movie halfway
    final user = const AppUser(
      id: 99,
      name: 'Movie Watcher',
      email: 'watcher@example.com',
      token: 'watcher_token_999',
    );
    await AuthService.saveUserSession(user, user.token);

    final session1 = AppState();
    await session1.initAuth();
    await session1.initWatchProgress();

    final testMovie = session1.latestMovies.first;
    await session1.saveWatchProgress(
      item: testMovie,
      position: const Duration(seconds: 1500),
      duration: const Duration(seconds: 3600),
    );

    expect(session1.continueWatching.length, equals(1));
    expect(session1.getSavedPosition(testMovie.id), equals(const Duration(seconds: 1500)));

    // 2. App closes completely and reopens (Session 2 cold start)
    final session2 = AppState();
    await session2.initAuth();
    await session2.initWatchProgress();

    expect(session2.isAuthenticated, isTrue);
    expect(session2.currentUser?.id, equals(99));
    expect(session2.continueWatching.length, equals(1));
    expect(session2.continueWatching.first.contentId, equals(testMovie.id));
    expect(session2.continueWatching.first.positionSeconds, equals(1500));
    expect(session2.continueWatching.first.progressPercentage, closeTo(0.416, 0.01));

    // 3. Render HomeScreen with session2 and verify shelf displays
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: HomeScreen(
          state: session2,
          onOpenSearch: () {},
          onOpenLiveTv: () {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(ContinueWatchingShelf), findsOneWidget);
    expect(find.text('Continue Watching'), findsOneWidget);
    expect(find.text(testMovie.title), findsAtLeastNWidgets(1));
    expect(find.text('41%'), findsOneWidget);
  });

  testWidgets('User-specific continue watching data is isolated between accounts', (WidgetTester tester) async {
    // 1. User A logs in and saves progress for Movie 1
    final userA = const AppUser(id: 101, name: 'User A', email: 'a@example.com', token: 'token_a');
    await AuthService.saveUserSession(userA, userA.token);

    final appA = AppState();
    await appA.initAuth();
    await appA.initWatchProgress();

    final movieA = appA.latestMovies.first;
    await appA.saveWatchProgress(
      item: movieA,
      position: const Duration(minutes: 20),
      duration: const Duration(minutes: 100),
    );
    expect(appA.continueWatching.length, equals(1));
    expect(appA.continueWatching.first.contentId, equals(movieA.id));

    // 2. User A logs out
    await appA.logout();
    expect(appA.continueWatching.isEmpty, isTrue);

    // 3. User B logs in (new user, fresh history)
    final userB = const AppUser(id: 102, name: 'User B', email: 'b@example.com', token: 'token_b');
    await AuthService.saveUserSession(userB, userB.token);

    final appB = AppState();
    await appB.initAuth();
    await appB.initWatchProgress();

    // User B should NOT see User A's continue watching items
    expect(appB.continueWatching.isEmpty, isTrue);

    // User B saves progress for Movie 2
    final movieB = appB.latestMovies.last;
    await appB.saveWatchProgress(
      item: movieB,
      position: const Duration(minutes: 45),
      duration: const Duration(minutes: 90),
    );
    expect(appB.continueWatching.length, equals(1));
    expect(appB.continueWatching.first.contentId, equals(movieB.id));

    // 4. User A logs back in
    await AuthService.saveUserSession(userA, userA.token);
    final appAReturns = AppState();
    await appAReturns.initAuth();
    await appAReturns.initWatchProgress();

    // User A's continue watching must have Movie A, NOT Movie B
    expect(appAReturns.continueWatching.length, equals(1));
    expect(appAReturns.continueWatching.first.contentId, equals(movieA.id));
  });

  testWidgets('Favourites and Watch History persist across app restarts and render in LibraryScreen', (WidgetTester tester) async {
    // 1. User logs in
    final user = const AppUser(id: 301, name: 'Library User', email: 'lib@example.com', token: 'token_301');
    await AuthService.saveUserSession(user, user.token);

    final app1 = AppState();
    await app1.initAuth();

    final testMovie = app1.latestMovies.first;
    final testHistoryMovie = app1.latestMovies.last;

    // Save one favorite and add one to watch history
    await app1.toggleFavourite(testMovie);
    await app1.addToHistory(testHistoryMovie);

    expect(app1.isFavourite(testMovie.id), isTrue);
    expect(app1.favouriteItems.length, equals(1));
    expect(app1.watchHistory.length, equals(1));

    // 2. Restart app (Session 2 cold start)
    final app2 = AppState();
    await app2.initAuth();

    expect(app2.isFavourite(testMovie.id), isTrue);
    expect(app2.favouriteItems.length, equals(1));
    expect(app2.favouriteItems.first.id, equals(testMovie.id));
    expect(app2.watchHistory.length, equals(1));
    expect(app2.watchHistory.first.id, equals(testHistoryMovie.id));

    // 3. Render LibraryScreen with app2
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: LibraryScreen(state: app2),
      ),
    );
    await tester.pumpAndSettle();

    // Verify Favourites tab shows 1 item
    expect(find.text('Favourites (1)'), findsOneWidget);
    expect(find.text(testMovie.title), findsAtLeastNWidgets(1));

    // Switch to History tab
    await tester.tap(find.text('History (1)'));
    await tester.pumpAndSettle();

    // Verify History tab displays the watched movie
    expect(find.text(testHistoryMovie.title), findsAtLeastNWidgets(1));
  });

  test('PlayerResolution, AudioTrack, and PlaybackSpeed equality and toString work correctly', () {
    const res1 = PlayerResolution(label: '1080p Full HD', resolution: '1920x1080', bitrate: '5.2 Mbps');
    const res2 = PlayerResolution(label: '1080p Full HD', resolution: '1920x1080', bitrate: '5.2 Mbps');
    const res3 = PlayerResolution(label: '720p HD', resolution: '1280x720', bitrate: '2.8 Mbps');

    expect(res1, equals(res2));
    expect(res1.hashCode, equals(res2.hashCode));
    expect(res1 == res3, isFalse);
    expect(res1.toString(), contains('1080p Full HD'));

    const track1 = AudioTrack(code: 'hin', name: 'Hindi [Original]');
    const track2 = AudioTrack(code: 'hin', name: 'Hindi [Original]');
    const track3 = AudioTrack(code: 'eng', name: 'English');

    expect(track1, equals(track2));
    expect(track1.hashCode, equals(track2.hashCode));
    expect(track1 == track3, isFalse);
    expect(track1.toString(), contains('Hindi'));

    const speed1 = PlaybackSpeed(speed: 1.25, label: '1.25x');
    const speed2 = PlaybackSpeed(speed: 1.25, label: '1.25x');
    const speed3 = PlaybackSpeed(speed: 1.5, label: '1.5x');

    expect(speed1, equals(speed2));
    expect(speed1 == speed3, isFalse);
  });

  testWidgets('PlayerDialogs.showResolutionSelector dismisses modal and calls onSelected without popping parent screen', (WidgetTester tester) async {
    PlayerResolution? selected;

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: Builder(
          builder: (context) {
            return Scaffold(
              body: Center(
                child: ElevatedButton(
                  onPressed: () {
                    PlayerDialogs.showResolutionSelector(
                      context: context,
                      selected: PlayerResolution.defaults[0],
                      resolutions: PlayerResolution.defaults,
                      onSelected: (res) {
                        selected = res;
                      },
                    );
                  },
                  child: const Text('Open Quality Sheet'),
                ),
              ),
            );
          },
        ),
      ),
    );

    await tester.tap(find.text('Open Quality Sheet'));
    await tester.pumpAndSettle();

    // Verify modal sheet is open
    expect(find.text('Video Quality & Adaptive Profile'), findsOneWidget);
    expect(find.text('720p HD'), findsOneWidget);

    // Tap 720p HD
    await tester.tap(find.text('720p HD'));
    await tester.pumpAndSettle();

    // Modal sheet must be dismissed
    expect(find.text('Video Quality & Adaptive Profile'), findsNothing);
    // Parent screen must still be intact
    expect(find.text('Open Quality Sheet'), findsOneWidget);
    // onSelected must receive 720p HD
    expect(selected, isNotNull);
    expect(selected!.resolution, equals('1280x720'));
  });

  testWidgets('PlayerDialogs.showAudioSelector dismisses modal and calls onSelected without popping parent screen', (WidgetTester tester) async {
    AudioTrack? selected;

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.darkTheme,
        home: Builder(
          builder: (context) {
            return Scaffold(
              body: Center(
                child: ElevatedButton(
                  onPressed: () {
                    PlayerDialogs.showAudioSelector(
                      context: context,
                      selected: AudioTrack.defaults[0],
                      tracks: AudioTrack.defaults,
                      onSelected: (trk) {
                        selected = trk;
                      },
                    );
                  },
                  child: const Text('Open Audio Sheet'),
                ),
              ),
            );
          },
        ),
      ),
    );

    await tester.tap(find.text('Open Audio Sheet'));
    await tester.pumpAndSettle();

    // Verify modal sheet is open
    expect(find.text('Audio & Multi-Language Track'), findsOneWidget);
    expect(find.text('English (5.1 Surround)'), findsOneWidget);

    // Tap English
    await tester.tap(find.text('English (5.1 Surround)'));
    await tester.pumpAndSettle();

    // Modal sheet must be dismissed
    expect(find.text('Audio & Multi-Language Track'), findsNothing);
    // Parent screen must still be intact
    expect(find.text('Open Audio Sheet'), findsOneWidget);
    // onSelected must receive English
    expect(selected, isNotNull);
    expect(selected!.code, equals('eng'));
  });
}


