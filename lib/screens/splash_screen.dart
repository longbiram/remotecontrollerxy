import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../state/app_state.dart';
import '../theme/app_theme.dart';
import '../main.dart';
import 'login_screen.dart';

class SplashScreen extends StatefulWidget {
  final AppState state;
  final bool autoNavigate;
  final Duration duration;
  final VoidCallback? onFinish;

  const SplashScreen({
    super.key,
    required this.state,
    this.autoNavigate = true,
    this.duration = const Duration(milliseconds: 2600),
    this.onFinish,
  });

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with TickerProviderStateMixin {
  late final AnimationController _entranceController;
  late final AnimationController _pulseController;
  late final AnimationController _progressController;

  late final Animation<double> _logoScale;
  late final Animation<double> _logoFade;
  late final Animation<double> _titleFade;
  late final Animation<Offset> _titleSlide;
  late final Animation<double> _badgesFade;
  late final Animation<double> _pulseAnimation;

  Timer? _navTimer;
  Timer? _stepTimer1;
  Timer? _stepTimer2;
  Timer? _stepTimer3;

  String _loadingStatus = 'Initializing streaming engine...';
  bool _navigated = false;

  @override
  void initState() {
    super.initState();

    // 1. Entrance animation sequence
    _entranceController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
    );

    _logoScale = Tween<double>(begin: 0.65, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.0, 0.7, curve: Curves.easeOutBack),
      ),
    );

    _logoFade = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.0, 0.5, curve: Curves.easeIn),
      ),
    );

    _titleSlide = Tween<Offset>(
      begin: const Offset(0.0, 0.3),
      end: Offset.zero,
    ).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.4, 0.85, curve: Curves.easeOutCubic),
      ),
    );

    _titleFade = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.4, 0.85, curve: Curves.easeIn),
      ),
    );

    _badgesFade = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _entranceController,
        curve: const Interval(0.7, 1.0, curve: Curves.easeIn),
      ),
    );

    // 2. Continuous ambient pulse animation
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2000),
    )..repeat(reverse: true);

    _pulseAnimation = Tween<double>(begin: 0.85, end: 1.15).animate(
      CurvedAnimation(parent: _pulseController, curve: Curves.easeInOut),
    );

    // 3. Progress bar animation
    _progressController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2200),
    );

    _entranceController.forward();
    _progressController.forward();

    // Staged realistic loading status messages
    _stepTimer1 = Timer(const Duration(milliseconds: 700), () {
      if (mounted) {
        setState(() {
          _loadingStatus = 'Loading movie & TV catalogs...';
        });
      }
    });

    _stepTimer2 = Timer(const Duration(milliseconds: 1400), () {
      if (mounted) {
        setState(() {
          _loadingStatus = 'Optimizing Ultra HD streaming...';
        });
      }
    });

    _stepTimer3 = Timer(const Duration(milliseconds: 2100), () {
      if (mounted) {
        setState(() {
          _loadingStatus = 'Ready to stream';
        });
      }
    });

    if (widget.autoNavigate) {
      _navTimer = Timer(widget.duration, () {
        _proceedToHome();
      });
    }
  }

  void _proceedToHome() {
    if (!mounted || _navigated) return;
    _navigated = true;

    if (widget.onFinish != null) {
      widget.onFinish!();
      return;
    }

    // Direct user to LoginScreen if not signed in, or MainNavigation if authenticated
    final Widget targetPage = widget.state.hasActiveUser
        ? MainNavigation(state: widget.state)
        : LoginScreen(state: widget.state);

    Navigator.of(context).pushReplacement(
      PageRouteBuilder(
        transitionDuration: const Duration(milliseconds: 600),
        pageBuilder: (context, animation, secondaryAnimation) => targetPage,
        transitionsBuilder: (context, animation, secondaryAnimation, child) {
          final fade = CurvedAnimation(
            parent: animation,
            curve: Curves.easeInOut,
          );
          final scale = Tween<double>(begin: 0.96, end: 1.0).animate(
            CurvedAnimation(parent: animation, curve: Curves.easeOutCubic),
          );

          return FadeTransition(
            opacity: fade,
            child: ScaleTransition(scale: scale, child: child),
          );
        },
      ),
    );
  }

  @override
  void dispose() {
    _navTimer?.cancel();
    _stepTimer1?.cancel();
    _stepTimer2?.cancel();
    _stepTimer3?.cancel();
    _entranceController.dispose();
    _pulseController.dispose();
    _progressController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: AppTheme.background,
      body: Stack(
        children: [
          // Ambient cinematic background glow effects
          Positioned(
            top: -size.width * 0.4,
            left: -size.width * 0.2,
            width: size.width * 1.4,
            height: size.width * 1.4,
            child: AnimatedBuilder(
              animation: _pulseAnimation,
              builder: (context, _) {
                return Container(
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: RadialGradient(
                      colors: [
                        AppTheme.primary.withValues(
                          alpha: 0.18 * _pulseAnimation.value,
                        ),
                        AppTheme.primary.withValues(
                          alpha: 0.05 * _pulseAnimation.value,
                        ),
                        Colors.transparent,
                      ],
                      stops: const [0.0, 0.5, 1.0],
                    ),
                  ),
                );
              },
            ),
          ),
          Positioned(
            bottom: -size.width * 0.3,
            right: -size.width * 0.2,
            width: size.width * 1.2,
            height: size.width * 1.2,
            child: Container(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: [
                    AppTheme.secondary.withValues(alpha: 0.08),
                    Colors.transparent,
                  ],
                  stops: const [0.0, 0.7],
                ),
              ),
            ),
          ),

          // Skip button at top right
          SafeArea(
            child: Align(
              alignment: Alignment.topRight,
              child: Padding(
                padding: const EdgeInsets.only(top: 12.0, right: 16.0),
                child: TextButton.icon(
                  onPressed: _proceedToHome,
                  style: TextButton.styleFrom(
                    foregroundColor: AppTheme.textSecondary,
                    backgroundColor: AppTheme.surface.withValues(alpha: 0.6),
                    padding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 8,
                    ),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(20),
                      side: BorderSide(
                        color: AppTheme.cardBorder.withValues(alpha: 0.6),
                      ),
                    ),
                  ),
                  icon: const Icon(Icons.arrow_forward_rounded, size: 16),
                  label: const Text(
                    'Skip',
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                  ),
                ),
              ),
            ),
          ),

          // Central content
          Center(
            child: SingleChildScrollView(
              physics: const NeverScrollableScrollPhysics(),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 32.0),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    // Branded Animated Logo
                    AnimatedBuilder(
                      animation: Listenable.merge([
                        _logoScale,
                        _logoFade,
                        _pulseAnimation,
                      ]),
                      builder: (context, child) {
                        return Transform.scale(
                          scale: _logoScale.value,
                          child: Opacity(
                            opacity: _logoFade.value,
                            child: _buildBrandedLogo(),
                          ),
                        );
                      },
                    ),

                    const SizedBox(height: 36),

                    // Title & Tagline with slide + fade
                    SlideTransition(
                      position: _titleSlide,
                      child: FadeTransition(
                        opacity: _titleFade,
                        child: Column(
                          children: [
                            // Main App Name
                            Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              crossAxisAlignment: CrossAxisAlignment.center,
                              children: [
                                Text(
                                  'PIKA',
                                  style: GoogleFonts.outfit(
                                    fontSize: 42,
                                    fontWeight: FontWeight.w900,
                                    letterSpacing: 2.0,
                                    color: AppTheme.textPrimary,
                                    shadows: [
                                      Shadow(
                                        color: Colors.white.withValues(
                                          alpha: 0.3,
                                        ),
                                        blurRadius: 16,
                                      ),
                                    ],
                                  ),
                                ),
                                ShaderMask(
                                  shaderCallback: (bounds) {
                                    return const LinearGradient(
                                      colors: [
                                        AppTheme.primary,
                                        Color(0xFFFF3B30),
                                        AppTheme.accentGold,
                                      ],
                                      begin: Alignment.topLeft,
                                      end: Alignment.bottomRight,
                                    ).createShader(bounds);
                                  },
                                  child: Text(
                                    'SHOW',
                                    style: GoogleFonts.outfit(
                                      fontSize: 42,
                                      fontWeight: FontWeight.w900,
                                      letterSpacing: 2.0,
                                      color: Colors.white,
                                    ),
                                  ),
                                ),
                              ],
                            ),

                            const SizedBox(height: 8),

                            // Subtitle Tagline
                            Text(
                              'CINEMA • WEB SERIES • LIVE TV',
                              textAlign: TextAlign.center,
                              style: GoogleFonts.outfit(
                                fontSize: 13,
                                fontWeight: FontWeight.w600,
                                letterSpacing: 3.5,
                                color: AppTheme.textSecondary,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),

                    const SizedBox(height: 28),

                    // Feature Chips (4K UHD, Dolby, Live)
                    FadeTransition(
                      opacity: _badgesFade,
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          _buildFeatureBadge(
                            icon: Icons.hd_rounded,
                            label: '4K Ultra HD',
                            color: AppTheme.secondary,
                          ),
                          const SizedBox(width: 8),
                          _buildFeatureBadge(
                            icon: Icons.surround_sound_rounded,
                            label: 'Dolby Atmos',
                            color: AppTheme.accentGold,
                          ),
                          const SizedBox(width: 8),
                          _buildFeatureBadge(
                            icon: Icons.live_tv_rounded,
                            label: 'Live Stream',
                            color: AppTheme.primary,
                          ),
                        ],
                      ),
                    ),

                    const SizedBox(height: 54),

                    // Loading Status & Animated Progress Bar
                    FadeTransition(
                      opacity: _badgesFade,
                      child: Column(
                        children: [
                          // Status Text
                          AnimatedSwitcher(
                            duration: const Duration(milliseconds: 300),
                            child: Text(
                              _loadingStatus,
                              key: ValueKey<String>(_loadingStatus),
                              style: GoogleFonts.outfit(
                                fontSize: 13,
                                fontWeight: FontWeight.w500,
                                color: AppTheme.textMuted,
                                letterSpacing: 0.5,
                              ),
                            ),
                          ),

                          const SizedBox(height: 14),

                          // Animated Gradient Progress Bar
                          SizedBox(
                            width: 200,
                            height: 4,
                            child: AnimatedBuilder(
                              animation: _progressController,
                              builder: (context, _) {
                                return ClipRRect(
                                  borderRadius: BorderRadius.circular(2),
                                  child: Stack(
                                    children: [
                                      // Track
                                      Container(
                                        color: AppTheme.surfaceLight,
                                      ),
                                      // Active bar
                                      FractionallySizedBox(
                                        widthFactor: _progressController.value,
                                        alignment: Alignment.centerLeft,
                                        child: Container(
                                          decoration: BoxDecoration(
                                            gradient: const LinearGradient(
                                              colors: [
                                                AppTheme.primary,
                                                Color(0xFFFF5252),
                                                AppTheme.accentGold,
                                              ],
                                            ),
                                            boxShadow: [
                                              BoxShadow(
                                                color: AppTheme.primary
                                                    .withValues(alpha: 0.8),
                                                blurRadius: 8,
                                                spreadRadius: 1,
                                              ),
                                            ],
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                );
                              },
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),

          // Bottom Version Badge
          Positioned(
            left: 0,
            right: 0,
            bottom: 24,
            child: SafeArea(
              child: Center(
                child: FadeTransition(
                  opacity: _badgesFade,
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 10,
                          vertical: 4,
                        ),
                        decoration: BoxDecoration(
                          color: AppTheme.surface.withValues(alpha: 0.6),
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(
                            color: AppTheme.cardBorder.withValues(alpha: 0.4),
                          ),
                        ),
                        child: Text(
                          'v1.0.0 • UltraStream Edition',
                          style: GoogleFonts.outfit(
                            fontSize: 11,
                            fontWeight: FontWeight.w500,
                            color: AppTheme.textMuted,
                            letterSpacing: 0.8,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildBrandedLogo() {
    return Stack(
      alignment: Alignment.center,
      children: [
        // Outer pulsing ring
        Container(
          width: 140,
          height: 140,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            boxShadow: [
              BoxShadow(
                color: AppTheme.primary.withValues(
                  alpha: 0.45 * _pulseAnimation.value,
                ),
                blurRadius: 40 * _pulseAnimation.value,
                spreadRadius: 10 * _pulseAnimation.value,
              ),
            ],
          ),
        ),

        // Glowing backdrop container with official PikaShow logo
        Container(
          width: 112,
          height: 112,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(28),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.7),
                offset: const Offset(0, 10),
                blurRadius: 20,
              ),
              BoxShadow(
                color: AppTheme.primary.withValues(alpha: 0.4),
                blurRadius: 20,
                spreadRadius: 2,
              ),
            ],
          ),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(28),
            child: Image.asset(
              'assets/images/pikashow_logo.png',
              width: 112,
              height: 112,
              fit: BoxFit.cover,
              errorBuilder: (context, error, stackTrace) {
                return Container(
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(28),
                    gradient: const LinearGradient(
                      colors: [Color(0xFF252A3F), Color(0xFF141724)],
                    ),
                  ),
                  child: const Center(
                    child: Icon(
                      Icons.play_arrow_rounded,
                      size: 54,
                      color: AppTheme.primary,
                    ),
                  ),
                );
              },
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildFeatureBadge({
    required IconData icon,
    required String label,
    required Color color,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: AppTheme.surface.withValues(alpha: 0.8),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(
          color: color.withValues(alpha: 0.3),
          width: 1,
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 12, color: color),
          const SizedBox(width: 4),
          Text(
            label,
            style: GoogleFonts.outfit(
              fontSize: 10,
              fontWeight: FontWeight.w600,
              color: AppTheme.textSecondary,
              letterSpacing: 0.3,
            ),
          ),
        ],
      ),
    );
  }
}
