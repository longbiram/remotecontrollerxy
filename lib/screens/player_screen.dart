import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:video_player/video_player.dart';
import '../models/content_item.dart';
import '../models/player_config.dart';
import '../services/hls_parser_service.dart';
import '../state/app_state.dart';
import '../theme/app_theme.dart';
import '../widgets/player_dialogs.dart';
import '../widgets/cast_sheet.dart';
import '../main.dart';
import 'profile_screen.dart';

enum VideoAspectMode {
  fit('Fit (16:9 Original)', Icons.fit_screen_rounded),
  fill('Fill Screen (Zoom)', Icons.zoom_out_map_rounded),
  stretch('Stretch to Screen', Icons.aspect_ratio_rounded);

  final String label;
  final IconData icon;
  const VideoAspectMode(this.label, this.icon);
}

class PlayerScreen extends StatefulWidget {
  final ContentItem item;
  final Episode? initialEpisode;
  final Duration? resumePosition;
  final AppState? state;

  const PlayerScreen({
    super.key,
    required this.item,
    this.initialEpisode,
    this.resumePosition,
    this.state,
  });

  @override
  State<PlayerScreen> createState() => _PlayerScreenState();
}

class _PlayerScreenState extends State<PlayerScreen>
    with WidgetsBindingObserver, RouteAware {
  VideoPlayerController? _controller;
  bool _isInitialized = false;
  bool _isInitializing = true;
  bool _hasError = false;
  String _errorMessage = '';

  bool _isPlaying = true;
  bool _showControls = true;
  bool _isLocked = false;
  bool _isMuted = false;
  bool _isFullScreen = false;
  bool _wasPlayingBeforeInactive = false;
  bool _wasPlayingBeforeRouteCovered = false;
  VideoAspectMode _aspectMode = VideoAspectMode.fit;

  Duration _position = Duration.zero;
  Duration _duration = Duration.zero;
  bool _isDraggingSlider = false;
  double _dragValue = 0.0;

  Timer? _hideTimer;
  Timer? _hudTimer;
  String? _hudMessage;
  IconData? _hudIcon;

  late Episode? _currentEpisode;

  List<PlayerResolution> _availableResolutions = List.from(PlayerResolution.defaults);
  List<AudioTrack> _availableAudioTracks = List.from(AudioTrack.defaults);
  PlayerResolution _selectedResolution = PlayerResolution.defaults[0];
  AudioTrack _selectedAudio = AudioTrack.defaults[0];
  PlaybackSpeed _selectedSpeed = PlaybackSpeed.defaults[2]; // 1.0x
  DateTime _lastProgressSaveTime = DateTime.now();
  UniqueKey _playerKey = UniqueKey();

  /// The original master playlist URL captured when playback first starts.
  /// Used for ALL controller reinitializations (resolution / audio switches)
  /// because HLS variant URLs (EXT-X-STREAM-INF) are video-only and carry no
  /// audio. Only the master playlist knows how to combine video+audio tracks.
  String? _masterStreamUrl;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _currentEpisode = widget.initialEpisode ??
        (widget.item.isSeries &&
                widget.item.seasons.isNotEmpty &&
                widget.item.seasons.first.episodes.isNotEmpty
            ? widget.item.seasons.first.episodes.first
            : null);

    _initPlayer();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final modalRoute = ModalRoute.of(context);
    if (modalRoute != null) {
      routeObserver.subscribe(this, modalRoute);
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState lifecycleState) {
    super.didChangeAppLifecycleState(lifecycleState);
    if (lifecycleState == AppLifecycleState.paused ||
        lifecycleState == AppLifecycleState.inactive ||
        lifecycleState == AppLifecycleState.hidden) {
      if (_controller != null && _isInitialized) {
        // Save progress BEFORE pausing so position is captured accurately.
        // This ensures continue-watching data survives if Android kills the process.
        _saveCurrentProgress();
        _wasPlayingBeforeInactive = _controller!.value.isPlaying;
        _controller!.pause();
        _controller!.setVolume(0.0);
        if (mounted) {
          setState(() => _isPlaying = false);
        }
      }
    } else if (lifecycleState == AppLifecycleState.resumed) {
      if (_isFullScreen) {
        SystemChrome.setPreferredOrientations([
          DeviceOrientation.landscapeLeft,
          DeviceOrientation.landscapeRight,
        ]);
        SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
      }
      if (_controller != null && _isInitialized && _wasPlayingBeforeInactive) {
        if (!_isMuted) {
          _controller!.setVolume(1.0);
        }
        _controller!.play();
        if (mounted) {
          setState(() => _isPlaying = true);
        }
        _startControlsTimer();
      }
    }
  }

  @override
  void didPushNext() {
    if (_controller != null && _isInitialized) {
      _wasPlayingBeforeRouteCovered = _controller!.value.isPlaying;
      _controller!.pause();
      _controller!.setVolume(0.0);
      if (mounted) {
        setState(() => _isPlaying = false);
      }
    }
  }

  @override
  void didPopNext() {
    if (_isFullScreen) {
      SystemChrome.setPreferredOrientations([
        DeviceOrientation.landscapeLeft,
        DeviceOrientation.landscapeRight,
      ]);
      SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
    }
    if (_controller != null && _isInitialized && _wasPlayingBeforeRouteCovered) {
      if (!_isMuted) {
        _controller!.setVolume(1.0);
      }
      _controller!.play();
      if (mounted) {
        setState(() => _isPlaying = true);
      }
      _startControlsTimer();
    }
  }

  @override
  void didPop() {
    _restoreDefaultOrientation();
    _stopPlaybackImmediately();
  }

  void _restoreDefaultOrientation() {
    if (_isFullScreen) {
      _isFullScreen = false;
      SystemChrome.setPreferredOrientations([
        DeviceOrientation.portraitUp,
        DeviceOrientation.portraitDown,
      ]);
      SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    }
  }

  void _saveCurrentProgress() {
    if (_controller != null && _isInitialized && !widget.item.isLive) {
      final pos = _controller!.value.position;
      final dur = _controller!.value.duration;
      if (pos.inSeconds > 5 && dur.inSeconds > 0) {
        widget.state?.saveWatchProgress(
          item: widget.item,
          episode: _currentEpisode,
          position: pos,
          duration: dur,
        );
      }
    }
  }

  void _stopPlaybackImmediately() {
    _saveCurrentProgress();
    _hideTimer?.cancel();
    _hudTimer?.cancel();
    _restoreDefaultOrientation();
    try {
      if (_controller != null) {
        _controller!.pause();
        _controller!.setVolume(0.0);
      }
    } catch (e) {
      debugPrint('Error stopping playback: $e');
    }
  }

  String get _activeStreamUrl {
    if (_currentEpisode != null && _currentEpisode!.streamUrl.isNotEmpty) {
      return _currentEpisode!.streamUrl;
    }
    return widget.item.streamUrl;
  }

  Future<void> _initPlayer() async {
    setState(() {
      _isInitializing = true;
      _hasError = false;
      _errorMessage = '';
    });

    // Check if premium content and not active VIP
    if (widget.item.isPremium && !(widget.state?.hasActiveSubscription ?? false)) {
      setState(() {
        _isInitializing = false;
        _hasError = true;
        _errorMessage = 'VIP Subscription Required. Upgrade your account to stream "${widget.item.title}".';
      });
      return;
    }

    final url = _activeStreamUrl;
    // Cache the master URL immediately so resolution/audio switches always
    // reload from here — never from a video-only HLS variant URL.
    _masterStreamUrl = url;

    // Parse m3u8 playlist in background for adaptive resolutions and audio tracks
    HlsParserService.parsePlaylist(
      streamUrl: url,
      item: widget.item,
    ).then((info) {
      if (mounted) {
        setState(() {
          _availableResolutions = info.resolutions;
          _availableAudioTracks = info.audioTracks;
          if (!_availableResolutions.any((r) => r.label == _selectedResolution.label)) {
            _selectedResolution = _availableResolutions.first;
          }
          if (!_availableAudioTracks.any((t) => t.code == _selectedAudio.code)) {
            _selectedAudio = _availableAudioTracks.firstWhere(
              (t) => t.isDefault,
              orElse: () => _availableAudioTracks.first,
            );
          }
        });
      }
    }).catchError((_) {});

    try {
      final oldController = _controller;
      _controller = null;
      if (oldController != null) {
        try {
          oldController.pause();
          oldController.setVolume(0.0);
        } catch (_) {}
        oldController.removeListener(_onControllerUpdate);
        await oldController.dispose();
      }

      final uri = Uri.parse(url);
      final controller = VideoPlayerController.networkUrl(uri);
      _controller = controller;

      await controller.initialize();
      controller.addListener(_onControllerUpdate);
      await controller.setPlaybackSpeed(_selectedSpeed.speed);
      await controller.setVolume(_isMuted ? 0.0 : 1.0);

      // Resume watching if saved progress exists or resumePosition is provided
      final resumePos = widget.resumePosition ??
          widget.state?.getSavedPosition(
            widget.item.id,
            episodeId: _currentEpisode?.id,
          );
      if (resumePos != null && resumePos.inSeconds > 5) {
        await controller.seekTo(resumePos);
        _showFeedbackToast(
          icon: Icons.replay_rounded,
          message: 'Resumed at ${_formatDuration(resumePos)}',
        );
      }

      await controller.play();

      if (mounted) {
        setState(() {
          _isInitialized = true;
          _isInitializing = false;
          _isPlaying = true;
          _duration = controller.value.duration;
          _position = controller.value.position;
          _playerKey = UniqueKey();
        });
        _startControlsTimer();
      }
    } catch (e) {
      debugPrint('Video Player error: $e');
      if (mounted) {
        setState(() {
          _isInitializing = false;
          _hasError = true;
          _errorMessage = 'Unable to stream content. Tap below to retry.';
        });
      }
    }
  }

  void _onControllerUpdate() {
    if (!mounted || _controller == null) return;

    final val = _controller!.value;
    if (val.hasError && !_hasError) {
      setState(() {
        _hasError = true;
        _errorMessage = val.errorDescription ?? 'Playback error encountered.';
      });
      return;
    }

    if (!_isDraggingSlider) {
      setState(() {
        _isPlaying = val.isPlaying;
        _position = val.position;
        _duration = val.duration;
      });

      // Throttled watch progress save every 4 seconds
      final now = DateTime.now();
      if (now.difference(_lastProgressSaveTime).inSeconds >= 4) {
        _lastProgressSaveTime = now;
        _saveCurrentProgress();
      }
    }
  }

  void _startControlsTimer() {
    _hideTimer?.cancel();
    _hideTimer = Timer(const Duration(seconds: 4), () {
      if (mounted && _isPlaying && !_hasError) {
        setState(() {
          _showControls = false;
        });
      }
    });
  }

  void _toggleControls() {
    setState(() {
      _showControls = !_showControls;
      if (_showControls) {
        _startControlsTimer();
      }
    });
  }

  void _togglePlayPause() {
    if (_controller == null || !_isInitialized) return;

    if (_controller!.value.isPlaying) {
      _controller!.pause();
      setState(() => _isPlaying = false);
      _saveCurrentProgress();
    } else {
      _controller!.play();
      setState(() => _isPlaying = true);
    }
    _startControlsTimer();
  }

  void _seekBy(int seconds) {
    if (_controller == null || !_isInitialized) return;
    final newPos = _position + Duration(seconds: seconds);
    final clamped = Duration(
      milliseconds: newPos.inMilliseconds.clamp(0, _duration.inMilliseconds),
    );
    _controller!.seekTo(clamped);
    _saveCurrentProgress();
    _showFeedbackToast(
      icon: seconds > 0 ? Icons.forward_10_rounded : Icons.replay_10_rounded,
      message: seconds > 0 ? '+10s' : '-10s',
    );
    _startControlsTimer();
  }


  void _toggleMute() {
    setState(() {
      _isMuted = !_isMuted;
    });
    _controller?.setVolume(_isMuted ? 0.0 : 1.0);
    _showFeedbackToast(
      icon: _isMuted ? Icons.volume_off_rounded : Icons.volume_up_rounded,
      message: _isMuted ? 'Muted' : 'Unmuted (100%)',
    );
    _startControlsTimer();
  }

  void _cycleAspectMode() {
    setState(() {
      switch (_aspectMode) {
        case VideoAspectMode.fit:
          _aspectMode = VideoAspectMode.fill;
          break;
        case VideoAspectMode.fill:
          _aspectMode = VideoAspectMode.stretch;
          break;
        case VideoAspectMode.stretch:
          _aspectMode = VideoAspectMode.fit;
          break;
      }
    });
    _showFeedbackToast(
      icon: _aspectMode.icon,
      message: _aspectMode.label,
    );
    _startControlsTimer();
  }

  Future<void> _toggleFullScreen() async {
    final willBeFullScreen = !_isFullScreen;
    setState(() {
      _isFullScreen = willBeFullScreen;
    });

    _showFeedbackToast(
      icon: willBeFullScreen ? Icons.fullscreen_rounded : Icons.fullscreen_exit_rounded,
      message: willBeFullScreen ? 'Landscape Full Screen' : 'Portrait Mode',
    );

    if (willBeFullScreen) {
      // Force landscape mode irrespective of phone rotation lock
      await SystemChrome.setPreferredOrientations([
        DeviceOrientation.landscapeLeft,
        DeviceOrientation.landscapeRight,
      ]);
      await SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
    } else {
      // Restore portrait mode
      await SystemChrome.setPreferredOrientations([
        DeviceOrientation.portraitUp,
        DeviceOrientation.portraitDown,
      ]);
      await SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    }
    _startControlsTimer();
  }

  void _showFeedbackToast({required IconData icon, required String message}) {
    _hudTimer?.cancel();
    setState(() {
      _hudIcon = icon;
      _hudMessage = message;
    });
    _hudTimer = Timer(const Duration(milliseconds: 1600), () {
      if (mounted) {
        setState(() {
          _hudMessage = null;
          _hudIcon = null;
        });
      }
    });
  }

  String _formatDuration(Duration duration) {
    final int totalSec = duration.inSeconds;
    final int hours = totalSec ~/ 3600;
    final int minutes = (totalSec % 3600) ~/ 60;
    final int secs = totalSec % 60;
    if (hours > 0) {
      return '$hours:${minutes.toString().padLeft(2, '0')}:${secs.toString().padLeft(2, '0')}';
    }
    return '${minutes.toString().padLeft(2, '0')}:${secs.toString().padLeft(2, '0')}';
  }

  Future<void> _changeResolution(PlayerResolution res) async {
    if (res.label == _selectedResolution.label &&
        res.streamUrl == _selectedResolution.streamUrl) {
      return;
    }

    if (_controller == null || !_isInitialized) {
      setState(() => _selectedResolution = res);
      return;
    }

    // ALWAYS reinitialize from the master playlist URL, never from a variant
    // stream URL. HLS EXT-X-STREAM-INF variant URLs are video-only segments —
    // audio is in separate EXT-X-MEDIA groups tied to the master. Loading a
    // variant URL directly drops all audio tracks (no sound bug).
    // ExoPlayer's ABR engine handles adaptive quality from the master URL.
    final masterUrl = _masterStreamUrl ?? _activeStreamUrl;

    final currentPos = _controller!.value.position;
    final wasPlaying = _controller!.value.isPlaying;

    // ── Mark as switching BEFORE any async gap ───────────────────────────────
    // This prevents Flutter from rendering VideoPlayer(disposedController)
    // between the dispose() call and the new controller being ready, which
    // caused the black-screen-with-audio bug.
    setState(() {
      _selectedResolution = res;
      _isInitialized = false;
      _isInitializing = true;
    });

    _showFeedbackToast(
      icon: Icons.high_quality_rounded,
      message: 'Quality: ${res.label}',
    );

    try {
      final oldController = _controller!;
      _controller = null;
      oldController.removeListener(_onControllerUpdate);
      await oldController.pause();
      await oldController.dispose();

      final newController = VideoPlayerController.networkUrl(Uri.parse(masterUrl));
      _controller = newController;
      await newController.initialize();
      newController.addListener(_onControllerUpdate);
      await newController.setPlaybackSpeed(_selectedSpeed.speed);
      await newController.setVolume(_isMuted ? 0.0 : 1.0);
      await newController.seekTo(currentPos);
      if (wasPlaying) {
        await newController.play();
      }

      if (mounted) {
        setState(() {
          _isInitialized = true;
          _isInitializing = false;
          _position = currentPos;
          _isPlaying = wasPlaying;
          _playerKey = UniqueKey();
        });
      }
    } catch (e) {
      debugPrint('_changeResolution error: $e');
      if (mounted) {
        setState(() {
          _isInitializing = false;
          _hasError = true;
          _errorMessage = 'Failed to switch quality. Tap to retry.';
        });
      }
    }
  }

  Future<void> _changeAudioTrack(AudioTrack track) async {
    if (track.code == _selectedAudio.code &&
        track.name == _selectedAudio.name &&
        track.streamUrl == _selectedAudio.streamUrl) {
      return;
    }

    if (_controller == null || !_isInitialized) {
      setState(() => _selectedAudio = track);
      return;
    }

    // Determine the URL to load for this audio track.
    // For dedicated audio stream URLs use them directly.
    // Otherwise always fall back to the MASTER playlist URL so ExoPlayer can
    // combine video + audio tracks correctly. Using _activeStreamUrl could
    // race with episode changes; _masterStreamUrl is captured once at init.
    final targetUrl = (track.streamUrl != null && track.streamUrl!.isNotEmpty)
        ? track.streamUrl!
        : (_masterStreamUrl ?? _activeStreamUrl);

    final currentPos = _controller!.value.position;
    final wasPlaying = _controller!.value.isPlaying;

    // ── Mark as switching BEFORE any async gap ───────────────────────────────
    // Prevents VideoPlayer(disposedController) from rendering while the new
    // controller is initializing, which caused the black-screen-with-audio bug.
    setState(() {
      _selectedAudio = track;
      _isInitialized = false;
      _isInitializing = true;
    });

    _showFeedbackToast(
      icon: Icons.audiotrack_rounded,
      message: 'Audio: ${track.name}',
    );

    try {
      final oldController = _controller!;
      _controller = null;
      oldController.removeListener(_onControllerUpdate);
      await oldController.pause();
      await oldController.dispose();

      final newController = VideoPlayerController.networkUrl(Uri.parse(targetUrl));
      _controller = newController;
      await newController.initialize();
      newController.addListener(_onControllerUpdate);
      await newController.setPlaybackSpeed(_selectedSpeed.speed);
      await newController.setVolume(_isMuted ? 0.0 : 1.0);
      await newController.seekTo(currentPos);
      if (wasPlaying) {
        await newController.play();
      }

      if (mounted) {
        setState(() {
          _isInitialized = true;
          _isInitializing = false;
          _position = currentPos;
          _isPlaying = wasPlaying;
          _playerKey = UniqueKey();
        });
      }
    } catch (e) {
      debugPrint('_changeAudioTrack error: $e');
      if (mounted) {
        setState(() {
          _isInitializing = false;
          _hasError = true;
          _errorMessage = 'Failed to switch audio track. Tap to retry.';
        });
      }
    }
  }

  Widget _buildVideoSurface() {
    final double videoAspect = _controller!.value.aspectRatio > 0
        ? _controller!.value.aspectRatio
        : (16 / 9);

    final double videoW = _controller!.value.size.width > 0
        ? _controller!.value.size.width
        : 1920.0;
    final double videoH = _controller!.value.size.height > 0
        ? _controller!.value.size.height
        : 1080.0;

    // _playerKey is placed directly on VideoPlayer so that Flutter fully
    // destroys and recreates the platform texture view when the controller
    // changes. Putting the key only on a wrapper widget was insufficient —
    // Flutter could reuse the VideoPlayer element at the same tree position,
    // leaving the GPU texture detached which caused the black-screen bug.
    switch (_aspectMode) {
      case VideoAspectMode.fit:
        return Center(
          child: AspectRatio(
            aspectRatio: videoAspect,
            child: VideoPlayer(_controller!, key: _playerKey),
          ),
        );

      case VideoAspectMode.fill:
        return ClipRect(
          child: SizedBox.expand(
            child: FittedBox(
              fit: BoxFit.cover,
              alignment: Alignment.center,
              child: SizedBox(
                width: videoW,
                height: videoH,
                child: VideoPlayer(_controller!, key: _playerKey),
              ),
            ),
          ),
        );

      case VideoAspectMode.stretch:
        return ClipRect(
          child: SizedBox.expand(
            child: FittedBox(
              fit: BoxFit.fill,
              alignment: Alignment.center,
              child: SizedBox(
                width: videoW,
                height: videoH,
                child: VideoPlayer(_controller!, key: _playerKey),
              ),
            ),
          ),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final title = _currentEpisode != null
        ? '${widget.item.title} • E${_currentEpisode!.episodeNumber}: ${_currentEpisode!.title}'
        : widget.item.title;

    final isLandscape = MediaQuery.of(context).orientation == Orientation.landscape;

    return PopScope(
      canPop: !_isFullScreen,
      onPopInvokedWithResult: (didPop, result) {
        if (didPop) {
          _stopPlaybackImmediately();
        } else if (_isFullScreen) {
          _toggleFullScreen();
        }
      },
      child: Scaffold(
        resizeToAvoidBottomInset: false,
        backgroundColor: Colors.black,
        body: GestureDetector(
          onTap: _toggleControls,
          behavior: HitTestBehavior.opaque,
          child: Stack(
            fit: StackFit.expand,
            children: [
              // 1. Video Surface or Placeholder Backdrop
              if (_isInitialized && _controller != null)
                _buildVideoSurface()
              else
                // Background poster while loading or if error
                Image.network(
                  widget.item.bannerUrl,
                  fit: BoxFit.cover,
                  errorBuilder: (context, error, stackTrace) => Container(
                    color: const Color(0xFF0A0C13),
                    child: const Center(
                      child: Icon(Icons.movie, size: 80, color: AppTheme.textMuted),
                    ),
                  ),
                ),

              // 2. Loading / Buffering Indicator
              if (_isInitializing || (_isInitialized && _controller?.value.isBuffering == true))
                Center(
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: 0.75),
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: AppTheme.cardBorder.withValues(alpha: 0.6)),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2.5,
                            color: AppTheme.primary,
                          ),
                        ),
                        const SizedBox(width: 14),
                        Text(
                          _isInitializing ? 'Connecting to stream...' : 'Buffering...',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),

              // 3. Error Overlay with Retry Button
              if (_hasError)
                Center(
                  child: SingleChildScrollView(
                    padding: EdgeInsets.symmetric(
                      horizontal: 24,
                      vertical: isLandscape ? 12 : 24,
                    ),
                    child: Container(
                      constraints: const BoxConstraints(maxWidth: 480),
                      padding: EdgeInsets.symmetric(
                        horizontal: 24,
                        vertical: isLandscape ? 14 : 24,
                      ),
                      decoration: BoxDecoration(
                        color: AppTheme.surface.withValues(alpha: 0.95),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: AppTheme.primary.withValues(alpha: 0.4)),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withValues(alpha: 0.8),
                            blurRadius: 24,
                          ),
                        ],
                      ),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          if (widget.item.isPremium && !(widget.state?.hasActiveSubscription ?? false)) ...[
                            Icon(
                              Icons.workspace_premium_rounded,
                              size: isLandscape ? 40 : 54,
                              color: AppTheme.accentGold,
                            ),
                            SizedBox(height: isLandscape ? 8 : 14),
                            const Text(
                              'VIP Content Locked',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 18,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              _errorMessage,
                              textAlign: TextAlign.center,
                              maxLines: isLandscape ? 3 : 6,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(color: AppTheme.textSecondary, fontSize: 13),
                            ),
                            SizedBox(height: isLandscape ? 12 : 20),
                            Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                OutlinedButton(
                                  onPressed: () => Navigator.pop(context),
                                  style: OutlinedButton.styleFrom(
                                    side: const BorderSide(color: AppTheme.cardBorder),
                                    foregroundColor: Colors.white70,
                                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                  ),
                                  child: const Text('Go Back'),
                                ),
                                if (widget.state != null) ...[
                                  const SizedBox(width: 12),
                                  ElevatedButton.icon(
                                    onPressed: () {
                                      _restoreDefaultOrientation();
                                      Navigator.push(
                                        context,
                                        MaterialPageRoute(
                                          builder: (context) => ProfileScreen(state: widget.state!),
                                        ),
                                      );
                                    },
                                    style: ElevatedButton.styleFrom(
                                      backgroundColor: AppTheme.accentGold,
                                      foregroundColor: Colors.black,
                                      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                    ),
                                    icon: const Icon(Icons.workspace_premium_rounded, size: 18),
                                    label: const Text('Upgrade to VIP', style: TextStyle(fontWeight: FontWeight.bold)),
                                  ),
                                ],
                              ],
                            ),
                          ] else ...[
                            Icon(
                              Icons.error_outline_rounded,
                              size: isLandscape ? 36 : 48,
                              color: AppTheme.primary,
                            ),
                            SizedBox(height: isLandscape ? 8 : 14),
                            const Text(
                              'Playback Error',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 18,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              _errorMessage,
                              textAlign: TextAlign.center,
                              maxLines: isLandscape ? 3 : 6,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(color: AppTheme.textSecondary, fontSize: 13),
                            ),
                            SizedBox(height: isLandscape ? 12 : 20),
                            ElevatedButton.icon(
                              onPressed: _initPlayer,
                              style: ElevatedButton.styleFrom(
                                backgroundColor: AppTheme.primary,
                                foregroundColor: Colors.white,
                                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              ),
                              icon: const Icon(Icons.refresh_rounded, size: 18),
                              label: const Text('Retry Connection', style: TextStyle(fontWeight: FontWeight.bold)),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                ),

            // 4. Subtle Controls Dimming Overlay
            AnimatedOpacity(
              opacity: _showControls ? 1.0 : 0.0,
              duration: const Duration(milliseconds: 250),
              child: Container(
                color: Colors.black.withValues(alpha: 0.45),
              ),
            ),

            // 5. On-Screen Feedback HUD Toast (Aspect Ratio & Mute indicator)
            if (_hudMessage != null && _hudIcon != null)
              Center(
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                  decoration: BoxDecoration(
                    color: Colors.black.withValues(alpha: 0.85),
                    borderRadius: BorderRadius.circular(24),
                    border: Border.all(
                      color: AppTheme.primary.withValues(alpha: 0.5),
                      width: 1,
                    ),
                    boxShadow: const [
                      BoxShadow(
                        color: Colors.black87,
                        blurRadius: 16,
                      ),
                    ],
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(_hudIcon, color: AppTheme.primary, size: 22),
                      const SizedBox(width: 10),
                      Text(
                        _hudMessage!,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 14,
                          fontWeight: FontWeight.bold,
                          letterSpacing: 0.3,
                        ),
                      ),
                    ],
                  ),
                ),
              ),

            // 6. Controls Layer (Top Bar, Center Play, Bottom Progress)
            if (_showControls) ...[
              // Top Action Bar
              Positioned(
                top: 0,
                left: 0,
                right: 0,
                child: Container(
                  padding: EdgeInsets.only(
                    top: isLandscape ? 12 : 38,
                    left: isLandscape ? 24 : 12,
                    right: isLandscape ? 20 : 8,
                    bottom: 12,
                  ),
                  decoration: const BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [Colors.black87, Colors.transparent],
                    ),
                  ),
                  child: Row(
                    children: [
                      IconButton(
                        padding: const EdgeInsets.all(6),
                        constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                        icon: const Icon(Icons.arrow_back_rounded, color: Colors.white, size: 24),
                        onPressed: () {
                          if (_isFullScreen) {
                            _toggleFullScreen();
                          } else {
                            _stopPlaybackImmediately();
                            Navigator.of(context).pop();
                          }
                        },
                      ),
                      const SizedBox(width: 4),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(
                              title,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.bold,
                                fontSize: 15,
                              ),
                            ),
                            if (widget.item.isLive)
                              FittedBox(
                                fit: BoxFit.scaleDown,
                                alignment: Alignment.centerLeft,
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Container(
                                      width: 7,
                                      height: 7,
                                      decoration: const BoxDecoration(
                                        color: Colors.red,
                                        shape: BoxShape.circle,
                                      ),
                                    ),
                                    const SizedBox(width: 5),
                                    const Text(
                                      'LIVE BROADCAST',
                                      style: TextStyle(
                                        color: AppTheme.primary,
                                        fontSize: 10,
                                        fontWeight: FontWeight.bold,
                                        letterSpacing: 0.8,
                                      ),
                                    ),
                                  ],
                                ),
                              )
                            else
                              Text(
                                '${widget.item.year} • ${_selectedResolution.label}',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(color: AppTheme.textMuted, fontSize: 11),
                              ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 4),

                      // Fit / Aspect Ratio Toggle
                      IconButton(
                        padding: const EdgeInsets.all(6),
                        constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                        icon: Icon(
                          _aspectMode.icon,
                          color: _aspectMode == VideoAspectMode.fit
                              ? Colors.white70
                              : AppTheme.secondary,
                          size: 22,
                        ),
                        tooltip: _aspectMode.label,
                        onPressed: _cycleAspectMode,
                      ),

                      // Chromecast Cast Button
                      IconButton(
                        padding: const EdgeInsets.all(6),
                        constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                        icon: Icon(
                          widget.state?.isCasting == true
                              ? Icons.cast_connected_rounded
                              : Icons.cast_rounded,
                          color: widget.state?.isCasting == true
                              ? AppTheme.secondary
                              : Colors.white70,
                          size: 22,
                        ),
                        tooltip: 'Cast to TV / Chromecast',
                        onPressed: () {
                          CastSheet.show(context, widget.state ?? AppState());
                        },
                      ),

                      // Mute / Unmute Button with distinct icons & states
                      IconButton(
                        padding: const EdgeInsets.all(6),
                        constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                        icon: Icon(
                          _isMuted ? Icons.volume_off_rounded : Icons.volume_up_rounded,
                          color: _isMuted ? AppTheme.primary : Colors.white,
                          size: 22,
                        ),
                        tooltip: _isMuted ? 'Unmute Audio' : 'Mute Audio',
                        onPressed: _toggleMute,
                      ),

                      // Lock Button
                      IconButton(
                        padding: const EdgeInsets.all(6),
                        constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                        icon: Icon(
                          _isLocked ? Icons.lock : Icons.lock_open,
                          color: _isLocked ? AppTheme.primary : Colors.white70,
                          size: 22,
                        ),
                        tooltip: _isLocked ? 'Unlock' : 'Lock',
                        onPressed: () {
                          setState(() {
                            _isLocked = !_isLocked;
                          });
                        },
                      ),
                    ],
                  ),
                ),
              ),

              // Center Transport Controls
              if (!_isLocked)
                Center(
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      // Replay 10s
                      IconButton(
                        iconSize: 46,
                        icon: const Icon(Icons.replay_10_rounded, color: Colors.white),
                        onPressed: () => _seekBy(-10),
                      ),
                      const SizedBox(width: 32),

                      // Play / Pause Hero Button
                      Container(
                        decoration: BoxDecoration(
                          color: AppTheme.primary.withValues(alpha: 0.95),
                          shape: BoxShape.circle,
                          boxShadow: const [
                            BoxShadow(
                              color: AppTheme.primaryGlow,
                              blurRadius: 24,
                              spreadRadius: 3,
                            ),
                          ],
                        ),
                        child: IconButton(
                          iconSize: 56,
                          icon: Icon(
                            _isPlaying ? Icons.pause_rounded : Icons.play_arrow_rounded,
                            color: Colors.white,
                          ),
                          onPressed: _togglePlayPause,
                        ),
                      ),
                      const SizedBox(width: 32),

                      // Forward 10s
                      IconButton(
                        iconSize: 46,
                        icon: const Icon(Icons.forward_10_rounded, color: Colors.white),
                        onPressed: () => _seekBy(10),
                      ),
                    ],
                  ),
                ),

              // Bottom Control Bar
              if (!_isLocked)
                Positioned(
                  bottom: 0,
                  left: 0,
                  right: 0,
                  child: Container(
                    padding: EdgeInsets.only(
                      top: 16,
                      left: isLandscape ? 24 : 20,
                      right: isLandscape ? 24 : 20,
                      bottom: isLandscape ? 16 : 32,
                    ),
                    decoration: const BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.bottomCenter,
                        end: Alignment.topCenter,
                        colors: [Colors.black87, Colors.transparent],
                      ),
                    ),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        // Scrubbing Bar for VOD
                        if (!widget.item.isLive) ...[
                          Row(
                            children: [
                              Text(
                                _formatDuration(_isDraggingSlider
                                    ? Duration(milliseconds: _dragValue.toInt())
                                    : _position),
                                style: const TextStyle(color: Colors.white70, fontSize: 12),
                              ),
                              Expanded(
                                child: SliderTheme(
                                  data: SliderTheme.of(context).copyWith(
                                    activeTrackColor: AppTheme.primary,
                                    inactiveTrackColor: Colors.white24,
                                    thumbColor: AppTheme.primary,
                                    thumbShape: const RoundSliderThumbShape(enabledThumbRadius: 6),
                                    overlayColor: AppTheme.primaryGlow,
                                    overlayShape: const RoundSliderOverlayShape(overlayRadius: 14),
                                    trackHeight: 3,
                                  ),
                                  child: Slider(
                                    value: (_isDraggingSlider
                                            ? _dragValue
                                            : _position.inMilliseconds.toDouble())
                                        .clamp(0.0, _duration.inMilliseconds.toDouble() > 0
                                            ? _duration.inMilliseconds.toDouble()
                                            : 1.0),
                                    min: 0.0,
                                    max: _duration.inMilliseconds.toDouble() > 0
                                        ? _duration.inMilliseconds.toDouble()
                                        : 1.0,
                                    onChangeStart: (val) {
                                      _isDraggingSlider = true;
                                      _dragValue = val;
                                      _hideTimer?.cancel();
                                    },
                                    onChanged: (val) {
                                      setState(() {
                                        _dragValue = val;
                                      });
                                    },
                                    onChangeEnd: (val) {
                                      _isDraggingSlider = false;
                                      final target = Duration(milliseconds: val.toInt());
                                      _controller?.seekTo(target);
                                      _startControlsTimer();
                                    },
                                  ),
                                ),
                              ),
                              Text(
                                _formatDuration(_duration),
                                style: const TextStyle(color: Colors.white70, fontSize: 12),
                              ),
                              const SizedBox(width: 8),
                              IconButton(
                                padding: EdgeInsets.zero,
                                constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                                icon: Icon(
                                  _isFullScreen
                                      ? Icons.fullscreen_exit_rounded
                                      : Icons.fullscreen_rounded,
                                  color: Colors.white,
                                  size: 26,
                                ),
                                tooltip: _isFullScreen ? 'Exit Full Screen' : 'Full Screen (Landscape)',
                                onPressed: _toggleFullScreen,
                              ),
                            ],
                          ),
                          const SizedBox(height: 10),
                        ],

                        // Quick Settings Pills
                        FittedBox(
                          fit: BoxFit.scaleDown,
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                            children: [
                              // Quality Selector
                              TextButton.icon(
                                onPressed: () {
                                  PlayerDialogs.showResolutionSelector(
                                    context: context,
                                    selected: _selectedResolution,
                                    resolutions: _availableResolutions,
                                    onSelected: _changeResolution,
                                  );
                                },
                                icon: const Icon(Icons.hd_rounded, color: AppTheme.secondary, size: 18),
                                label: Text(
                                  _selectedResolution.isAuto ? 'Auto' : _selectedResolution.resolution,
                                  style: const TextStyle(color: Colors.white, fontSize: 12),
                                ),
                              ),

                              // Audio Selector
                              TextButton.icon(
                                onPressed: () {
                                  PlayerDialogs.showAudioSelector(
                                    context: context,
                                    selected: _selectedAudio,
                                    tracks: _availableAudioTracks,
                                    onSelected: _changeAudioTrack,
                                  );
                                },
                                icon: const Icon(Icons.audiotrack_rounded, color: AppTheme.secondary, size: 18),
                                label: Text(
                                  _selectedAudio.name.split(' ').first,
                                  style: const TextStyle(color: Colors.white, fontSize: 12),
                                ),
                              ),

                              // Speed Selector
                              TextButton.icon(
                                onPressed: () {
                                  PlayerDialogs.showSpeedSelector(
                                    context: context,
                                    selected: _selectedSpeed,
                                    onSelected: (spd) {
                                      setState(() {
                                        _selectedSpeed = spd;
                                        _controller?.setPlaybackSpeed(spd.speed);
                                      });
                                    },
                                  );
                                },
                                icon: const Icon(Icons.speed_rounded, color: AppTheme.secondary, size: 18),
                                label: Text(
                                  '${_selectedSpeed.speed}x',
                                  style: const TextStyle(color: Colors.white, fontSize: 12),
                                ),
                              ),

                              // Full Screen Toggle Pill
                              TextButton.icon(
                                onPressed: _toggleFullScreen,
                                icon: Icon(
                                  _isFullScreen
                                      ? Icons.fullscreen_exit_rounded
                                      : Icons.fullscreen_rounded,
                                  color: _isFullScreen ? AppTheme.primary : AppTheme.secondary,
                                  size: 18,
                                ),
                                label: Text(
                                  _isFullScreen ? 'Exit Full' : 'Full Screen',
                                  style: TextStyle(
                                    color: _isFullScreen ? AppTheme.primary : Colors.white,
                                    fontSize: 12,
                                    fontWeight: _isFullScreen ? FontWeight.bold : FontWeight.normal,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          ],
        ),
      ),
    ),
  );
}

  @override
  void dispose() {

    WidgetsBinding.instance.removeObserver(this);
    routeObserver.unsubscribe(this);
    _saveCurrentProgress();
    _hideTimer?.cancel();
    _hudTimer?.cancel();
    _restoreDefaultOrientation();
    if (_controller != null) {
      _controller!.removeListener(_onControllerUpdate);
      _controller!.pause();
      _controller!.dispose();
      _controller = null;
    }
    super.dispose();
  }
}

