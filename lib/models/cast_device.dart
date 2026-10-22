import 'package:flutter/material.dart';

enum DiscoveryTech {
  wifi('Wi-Fi Network', Icons.wifi_rounded),
  bluetooth('Bluetooth Wireless', Icons.bluetooth_rounded);

  final String label;
  final IconData icon;
  const DiscoveryTech(this.label, this.icon);
}

class CastDevice {
  final String id;
  final String name;
  final String type;
  final String ipAddress;
  final IconData icon;
  final DiscoveryTech discoveryTech;
  final String protocol;
  final int? signalDbm;
  final int? port;
  final bool isOnline;
  final DateTime? lastSeen;

  const CastDevice({
    required this.id,
    required this.name,
    required this.type,
    required this.ipAddress,
    required this.icon,
    this.discoveryTech = DiscoveryTech.wifi,
    this.protocol = 'Google Cast (Wi-Fi)',
    this.signalDbm,
    this.port,
    this.isOnline = true,
    this.lastSeen,
  });

  String get signalStrengthLabel {
    if (signalDbm == null) return 'Active';
    if (signalDbm! > -60) return 'Excellent (-${signalDbm!.abs()} dBm)';
    if (signalDbm! > -75) return 'Good (-${signalDbm!.abs()} dBm)';
    return 'Fair (-${signalDbm!.abs()} dBm)';
  }

  static const List<CastDevice> defaultDevices = [
    CastDevice(
      id: 'chromecast_living_room',
      name: 'Living Room TV',
      type: 'Chromecast with Google TV (4K)',
      ipAddress: '192.168.1.104',
      icon: Icons.tv_rounded,
      discoveryTech: DiscoveryTech.wifi,
      protocol: 'Google Cast (mDNS/DIAL)',
      signalDbm: -48,
      port: 8008,
    ),
    CastDevice(
      id: 'android_tv_bedroom',
      name: 'Bedroom Smart TV',
      type: 'Android TV OS • Mi Box 4K',
      ipAddress: '192.168.1.112',
      icon: Icons.connected_tv_rounded,
      discoveryTech: DiscoveryTech.wifi,
      protocol: 'Google Cast / DLNA',
      signalDbm: -62,
      port: 8008,
    ),
    CastDevice(
      id: 'fire_tv_lounge',
      name: 'Fire TV Stick 4K',
      type: 'Amazon Fire OS',
      ipAddress: '192.168.1.118',
      icon: Icons.cast_connected_rounded,
      discoveryTech: DiscoveryTech.wifi,
      protocol: 'DIAL / Miracast',
      signalDbm: -55,
      port: 8008,
    ),
    CastDevice(
      id: 'samsung_tv_hall',
      name: 'Samsung 65" Crystal UHD',
      type: 'Samsung Smart View (DLNA/Cast)',
      ipAddress: '192.168.1.125',
      icon: Icons.tv_rounded,
      discoveryTech: DiscoveryTech.wifi,
      protocol: 'Samsung Smart View',
      signalDbm: -68,
      port: 8001,
    ),
  ];
}
