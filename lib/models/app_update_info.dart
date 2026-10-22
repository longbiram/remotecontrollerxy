class AppUpdateInfo {
  final bool hasUpdate;
  final int currentVersionCode;
  final int latestVersionCode;
  final String latestVersionName;
  final String apkUrl;
  final String websiteUrl;
  final bool forceUpdate;
  final String releaseNotes;

  const AppUpdateInfo({
    required this.hasUpdate,
    required this.currentVersionCode,
    required this.latestVersionCode,
    required this.latestVersionName,
    required this.apkUrl,
    required this.websiteUrl,
    required this.forceUpdate,
    required this.releaseNotes,
  });

  factory AppUpdateInfo.fromJson(Map<String, dynamic> json, int localVersionCode) {
    final serverHasUpdate = json['has_update'] == true;
    final latestCode = (json['latest_version_code'] is int)
        ? json['latest_version_code'] as int
        : int.tryParse(json['latest_version_code']?.toString() ?? '1') ?? 1;

    final force = json['force_update'] == true ||
        json['force_update'] == 1 ||
        json['force_update'] == '1';

    return AppUpdateInfo(
      hasUpdate: serverHasUpdate || (latestCode > localVersionCode),
      currentVersionCode: localVersionCode,
      latestVersionCode: latestCode,
      latestVersionName: json['latest_version_name']?.toString() ?? '1.0.0',
      apkUrl: json['apk_url']?.toString() ?? '',
      websiteUrl: json['website_url']?.toString() ?? '',
      forceUpdate: force,
      releaseNotes: json['release_notes']?.toString() ?? 'Bug fixes and performance improvements.',
    );
  }
}
