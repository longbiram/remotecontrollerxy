import 'package:flutter/material.dart';
import '../models/player_config.dart';
import '../theme/app_theme.dart';

class PlayerDialogs {
  static Future<void> showResolutionSelector({
    required BuildContext context,
    required PlayerResolution selected,
    List<PlayerResolution>? resolutions,
    required ValueChanged<PlayerResolution> onSelected,
  }) async {
    final list = (resolutions != null && resolutions.isNotEmpty)
        ? resolutions
        : PlayerResolution.defaults;

    final chosen = await showModalBottomSheet<PlayerResolution>(
      context: context,
      backgroundColor: AppTheme.surface,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetContext) {
        return SafeArea(
          child: ConstrainedBox(
            constraints: BoxConstraints(
              maxHeight: MediaQuery.of(sheetContext).size.height * 0.85,
            ),
            child: SingleChildScrollView(
              padding: const EdgeInsets.symmetric(vertical: 16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 20, vertical: 8),
                    child: Row(
                      children: [
                        Icon(Icons.high_quality_rounded, color: AppTheme.secondary, size: 22),
                        SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            'Video Quality & Adaptive Profile',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: AppTheme.textPrimary,
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const Divider(color: AppTheme.cardBorder),
                  ...list.map((res) {
                    final isCurrent = res.label == selected.label ||
                        (res.isAuto && selected.isAuto);
                    return ListTile(
                      leading: Icon(
                        isCurrent ? Icons.radio_button_checked : Icons.radio_button_off,
                        color: isCurrent ? AppTheme.primary : AppTheme.textMuted,
                      ),
                      title: Row(
                        children: [
                          Expanded(
                            child: Text(
                              res.label,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                color: isCurrent ? AppTheme.textPrimary : AppTheme.textSecondary,
                                fontWeight: isCurrent ? FontWeight.bold : FontWeight.normal,
                              ),
                            ),
                          ),
                          if (res.isAuto) ...[
                            const SizedBox(width: 8),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1.5),
                              decoration: BoxDecoration(
                                color: AppTheme.primary.withValues(alpha: 0.2),
                                borderRadius: BorderRadius.circular(4),
                                border: Border.all(color: AppTheme.primary, width: 0.5),
                              ),
                              child: const Text(
                                'HLS ADAPTIVE',
                                style: TextStyle(
                                  color: AppTheme.primary,
                                  fontSize: 8.5,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                            ),
                          ],
                        ],
                      ),
                      subtitle: Text(
                        '${res.resolution} • Bitrate: ${res.bitrate}',
                        style: const TextStyle(color: AppTheme.textMuted, fontSize: 12),
                      ),
                      onTap: () {
                        Navigator.of(sheetContext).pop(res);
                      },
                    );
                  }),
                ],
              ),
            ),
          ),
        );
      },
    );

    if (chosen != null) {
      onSelected(chosen);
    }
  }

  static Future<void> showAudioSelector({
    required BuildContext context,
    required AudioTrack selected,
    List<AudioTrack>? tracks,
    required ValueChanged<AudioTrack> onSelected,
  }) async {
    final list = (tracks != null && tracks.isNotEmpty)
        ? tracks
        : AudioTrack.defaults;

    final chosen = await showModalBottomSheet<AudioTrack>(
      context: context,
      backgroundColor: AppTheme.surface,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetContext) {
        return SafeArea(
          child: ConstrainedBox(
            constraints: BoxConstraints(
              maxHeight: MediaQuery.of(sheetContext).size.height * 0.85,
            ),
            child: SingleChildScrollView(
              padding: const EdgeInsets.symmetric(vertical: 16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 20, vertical: 8),
                    child: Row(
                      children: [
                        Icon(Icons.audiotrack_rounded, color: AppTheme.secondary, size: 22),
                        SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            'Audio & Multi-Language Track',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: AppTheme.textPrimary,
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const Divider(color: AppTheme.cardBorder),
                  ...list.map((track) {
                    final isCurrent = track.code == selected.code ||
                        track.name == selected.name;
                    return ListTile(
                      leading: Icon(
                        isCurrent ? Icons.check_circle_rounded : Icons.circle_outlined,
                        color: isCurrent ? AppTheme.primary : AppTheme.textMuted,
                      ),
                      title: Text(
                        track.name,
                        style: TextStyle(
                          color: isCurrent ? AppTheme.textPrimary : AppTheme.textSecondary,
                          fontWeight: isCurrent ? FontWeight.bold : FontWeight.normal,
                        ),
                      ),
                      subtitle: track.isDefault
                          ? const Text(
                              'Default Primary Stream Track',
                              style: TextStyle(color: AppTheme.textMuted, fontSize: 11),
                            )
                          : null,
                      onTap: () {
                        Navigator.of(sheetContext).pop(track);
                      },
                    );
                  }),
                ],
              ),
            ),
          ),
        );
      },
    );

    if (chosen != null) {
      onSelected(chosen);
    }
  }

  static Future<void> showSpeedSelector({
    required BuildContext context,
    required PlaybackSpeed selected,
    required ValueChanged<PlaybackSpeed> onSelected,
  }) async {
    final chosen = await showModalBottomSheet<PlaybackSpeed>(
      context: context,
      backgroundColor: AppTheme.surface,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetContext) {
        return SafeArea(
          child: ConstrainedBox(
            constraints: BoxConstraints(
              maxHeight: MediaQuery.of(sheetContext).size.height * 0.85,
            ),
            child: SingleChildScrollView(
              padding: const EdgeInsets.symmetric(vertical: 16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 20, vertical: 8),
                    child: Row(
                      children: [
                        Icon(Icons.speed_rounded, color: AppTheme.secondary, size: 22),
                        SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            'Playback Speed',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: AppTheme.textPrimary,
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const Divider(color: AppTheme.cardBorder),
                  ...PlaybackSpeed.defaults.map((speed) {
                    final isCurrent = (speed.speed - selected.speed).abs() < 0.01;
                    return ListTile(
                      leading: Icon(
                        isCurrent ? Icons.radio_button_checked : Icons.radio_button_off,
                        color: isCurrent ? AppTheme.primary : AppTheme.textMuted,
                      ),
                      title: Text(
                        speed.label,
                        style: TextStyle(
                          color: isCurrent ? AppTheme.textPrimary : AppTheme.textSecondary,
                          fontWeight: isCurrent ? FontWeight.bold : FontWeight.normal,
                        ),
                      ),
                      onTap: () {
                        Navigator.of(sheetContext).pop(speed);
                      },
                    );
                  }),
                ],
              ),
            ),
          ),
        );
      },
    );

    if (chosen != null) {
      onSelected(chosen);
    }
  }
}
