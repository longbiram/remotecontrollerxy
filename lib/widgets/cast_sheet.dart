import 'dart:async';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../models/cast_device.dart';
import '../services/cast_discovery_service.dart';
import '../state/app_state.dart';
import '../theme/app_theme.dart';

class CastSheet extends StatefulWidget {
  final AppState state;

  const CastSheet({super.key, required this.state});

  static void show(BuildContext context, AppState state) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (context) => CastSheet(state: state),
    );
  }

  @override
  State<CastSheet> createState() => _CastSheetState();
}

class _CastSheetState extends State<CastSheet> {
  final CastDiscoveryService _discoveryService = CastDiscoveryService.instance;
  StreamSubscription<List<CastDevice>>? _discoverySub;

  DiscoveryTech _selectedTech = DiscoveryTech.wifi;
  List<CastDevice> _devices = [];
  bool _isScanning = false;
  String? _connectingDeviceId;
  double _castVolume = 0.75;
  NetworkInfo? _networkInfo;

  final TextEditingController _ipController = TextEditingController();
  final TextEditingController _nameController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _initDiscovery();
  }

  Future<void> _initDiscovery() async {
    final net = await _discoveryService.getLocalNetworkInfo();
    if (mounted) {
      setState(() {
        _networkInfo = net;
      });
    }

    _discoverySub = _discoveryService.devicesStream.listen((devList) {
      if (mounted) {
        setState(() {
          _devices = devList;
          _isScanning = _discoveryService.isScanning;
        });
      }
    });

    _startScanning();
  }

  void _startScanning() {
    setState(() {
      _isScanning = true;
    });
    _discoveryService.startScan(tech: _selectedTech);
  }

  @override
  void dispose() {
    _discoverySub?.cancel();
    _discoveryService.stopScan();
    _ipController.dispose();
    _nameController.dispose();
    super.dispose();
  }

  void _handleSelectDevice(CastDevice device) {
    setState(() {
      _connectingDeviceId = device.id;
    });

    Timer(const Duration(milliseconds: 600), () {
      if (!mounted) return;
      widget.state.connectCastDevice(device);
      Navigator.pop(context);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: AppTheme.surfaceLight,
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          content: Row(
            children: [
              const Icon(Icons.cast_connected_rounded, color: AppTheme.secondary, size: 20),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Connected to ${device.name}',
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                ),
              ),
            ],
          ),
          duration: const Duration(seconds: 2),
        ),
      );
    });
  }

  void _handleDisconnect() {
    final devName = widget.state.connectedCastDevice?.name ?? 'Device';
    widget.state.disconnectCastDevice();
    Navigator.pop(context);
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        backgroundColor: AppTheme.surfaceLight,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        content: Text(
          'Disconnected from $devName',
          style: const TextStyle(color: AppTheme.textSecondary),
        ),
        duration: const Duration(seconds: 2),
      ),
    );
  }

  void _showAddManualIpDialog() {
    final defaultSub = _networkInfo?.subnetPrefix ?? '192.168.1';
    _ipController.text = '$defaultSub.';
    _nameController.text = '';

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppTheme.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: AppTheme.cardBorder),
        ),
        title: const Row(
          children: [
            Icon(Icons.add_to_queue_rounded, color: AppTheme.secondary, size: 22),
            SizedBox(width: 10),
            Text(
              'Add TV by IP Address',
              style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
            ),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Enter the local Wi-Fi IP of your Smart TV or Chromecast (e.g. from TV network settings):',
              style: TextStyle(color: AppTheme.textSecondary, fontSize: 13),
            ),
            const SizedBox(height: 14),
            TextField(
              controller: _ipController,
              keyboardType: TextInputType.number,
              style: const TextStyle(color: Colors.white),
              decoration: InputDecoration(
                labelText: 'TV IP Address',
                labelStyle: const TextStyle(color: AppTheme.textMuted),
                filled: true,
                fillColor: AppTheme.surfaceLight,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                prefixIcon: const Icon(Icons.lan_rounded, color: AppTheme.secondary, size: 18),
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _nameController,
              style: const TextStyle(color: Colors.white),
              decoration: InputDecoration(
                labelText: 'Device Name (Optional)',
                hintText: 'e.g. My Sony Bravia TV',
                hintStyle: const TextStyle(color: AppTheme.textMuted, fontSize: 12),
                labelStyle: const TextStyle(color: AppTheme.textMuted),
                filled: true,
                fillColor: AppTheme.surfaceLight,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                prefixIcon: const Icon(Icons.tv_rounded, color: AppTheme.textMuted, size: 18),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel', style: TextStyle(color: AppTheme.textMuted)),
          ),
          ElevatedButton(
            onPressed: () {
              final ip = _ipController.text.trim();
              if (ip.isNotEmpty) {
                final customDev = _discoveryService.addManualDevice(
                  ip,
                  customName: _nameController.text.trim(),
                );
                Navigator.pop(ctx);
                _handleSelectDevice(customDev);
              }
            },
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.primary,
              foregroundColor: Colors.white,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            child: const Text('Connect & Cast'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final connectedDevice = widget.state.connectedCastDevice;
    final isConnected = connectedDevice != null;

    return Container(
      decoration: BoxDecoration(
        color: AppTheme.surface,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
        border: Border.all(color: AppTheme.cardBorder, width: 1),
        boxShadow: const [
          BoxShadow(
            color: Colors.black54,
            blurRadius: 24,
            offset: Offset(0, -6),
          ),
        ],
      ),
      padding: const EdgeInsets.only(top: 12, bottom: 28, left: 20, right: 20),
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxHeight: MediaQuery.of(context).size.height * 0.88,
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Drag handle pill
              Center(
                child: Container(
                  width: 44,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppTheme.cardBorder,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              const SizedBox(height: 16),

              // Header Row
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: isConnected
                          ? AppTheme.secondary.withValues(alpha: 0.15)
                          : AppTheme.primary.withValues(alpha: 0.15),
                      shape: BoxShape.circle,
                    ),
                    child: Icon(
                      isConnected ? Icons.cast_connected_rounded : Icons.cast_rounded,
                      color: isConnected ? AppTheme.secondary : AppTheme.primary,
                      size: 24,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Cast to Smart TV',
                          style: GoogleFonts.outfit(
                            color: Colors.white,
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 2),
                        const Text(
                          'Chromecast, Android TV & DLNA',
                          style: TextStyle(
                            color: AppTheme.textSecondary,
                            fontSize: 12,
                          ),
                        ),
                      ],
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close_rounded, color: AppTheme.textMuted),
                    onPressed: () => Navigator.pop(context),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              const Divider(color: AppTheme.cardBorder, height: 1),
              const SizedBox(height: 16),

              // Technology Selector: Wi-Fi (SSDP/DIAL) vs Bluetooth (Wireless Sink)
              Container(
                padding: const EdgeInsets.all(4),
                decoration: BoxDecoration(
                  color: AppTheme.surfaceLight,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: AppTheme.cardBorder, width: 0.8),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: GestureDetector(
                        onTap: () {
                          if (_selectedTech != DiscoveryTech.wifi) {
                            setState(() {
                              _selectedTech = DiscoveryTech.wifi;
                            });
                            _startScanning();
                          }
                        },
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 200),
                          padding: const EdgeInsets.symmetric(vertical: 9),
                          decoration: BoxDecoration(
                            color: _selectedTech == DiscoveryTech.wifi
                                ? AppTheme.primary
                                : Colors.transparent,
                            borderRadius: BorderRadius.circular(10),
                            boxShadow: _selectedTech == DiscoveryTech.wifi
                                ? [
                                    BoxShadow(
                                      color: AppTheme.primary.withValues(alpha: 0.4),
                                      blurRadius: 8,
                                    ),
                                  ]
                                : null,
                          ),
                          child: const Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.wifi_rounded, size: 16, color: Colors.white),
                              SizedBox(width: 8),
                              Text(
                                'Wi-Fi / LAN Cast',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 13,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                    Expanded(
                      child: GestureDetector(
                        onTap: () {
                          if (_selectedTech != DiscoveryTech.bluetooth) {
                            setState(() {
                              _selectedTech = DiscoveryTech.bluetooth;
                            });
                            _startScanning();
                          }
                        },
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 200),
                          padding: const EdgeInsets.symmetric(vertical: 9),
                          decoration: BoxDecoration(
                            color: _selectedTech == DiscoveryTech.bluetooth
                                ? AppTheme.secondary
                                : Colors.transparent,
                            borderRadius: BorderRadius.circular(10),
                            boxShadow: _selectedTech == DiscoveryTech.bluetooth
                                ? [
                                    BoxShadow(
                                      color: AppTheme.secondary.withValues(alpha: 0.4),
                                      blurRadius: 8,
                                    ),
                                  ]
                                : null,
                          ),
                          child: const Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.bluetooth_rounded, size: 16, color: Colors.white),
                              SizedBox(width: 8),
                              Text(
                                'Bluetooth Cast',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 13,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 12),

              // Network Subnet & Status Info Chip
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: AppTheme.background.withValues(alpha: 0.6),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AppTheme.cardBorder.withValues(alpha: 0.5)),
                ),
                child: Row(
                  children: [
                    Icon(
                      _selectedTech == DiscoveryTech.wifi
                          ? Icons.router_rounded
                          : Icons.bluetooth_searching_rounded,
                      size: 15,
                      color: AppTheme.secondary,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        _selectedTech == DiscoveryTech.wifi
                            ? 'IP: ${_networkInfo?.ipAddress ?? "Scanning..."} • Subnet: ${_networkInfo?.subnetPrefix ?? "192.168.1"}.*'
                            : 'Bluetooth Adapter Active • Discovering Displays',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: AppTheme.textSecondary,
                          fontSize: 11,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ),
                    InkWell(
                      onTap: _startScanning,
                      borderRadius: BorderRadius.circular(6),
                      child: Padding(
                        padding: const EdgeInsets.all(4),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            if (_isScanning)
                              const SizedBox(
                                width: 12,
                                height: 12,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: AppTheme.primary,
                                ),
                              )
                            else
                              const Icon(Icons.refresh_rounded, size: 16, color: AppTheme.secondary),
                            const SizedBox(width: 4),
                            Text(
                              _isScanning ? 'Scanning...' : 'Rescan',
                              style: const TextStyle(color: AppTheme.secondary, fontSize: 11, fontWeight: FontWeight.bold),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 16),

              // Active Connection Card
              if (isConnected) ...[
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: AppTheme.secondary.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: AppTheme.secondary.withValues(alpha: 0.4), width: 1.5),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.all(10),
                            decoration: BoxDecoration(
                              color: AppTheme.secondary.withValues(alpha: 0.2),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Icon(connectedDevice.icon, color: AppTheme.secondary, size: 24),
                          ),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  children: [
                                    Expanded(
                                      child: Text(
                                        connectedDevice.name,
                                        style: const TextStyle(
                                          color: Colors.white,
                                          fontWeight: FontWeight.bold,
                                          fontSize: 16,
                                        ),
                                      ),
                                    ),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                      decoration: BoxDecoration(
                                        color: AppTheme.secondary.withValues(alpha: 0.25),
                                        borderRadius: BorderRadius.circular(6),
                                      ),
                                      child: const Text(
                                        'ACTIVE',
                                        style: TextStyle(
                                          color: AppTheme.secondary,
                                          fontSize: 9,
                                          fontWeight: FontWeight.bold,
                                          letterSpacing: 0.8,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 3),
                                Text(
                                  '${connectedDevice.protocol} • ${connectedDevice.ipAddress}',
                                  style: const TextStyle(
                                    color: AppTheme.textMuted,
                                    fontSize: 11,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),

                      const SizedBox(height: 14),

                      // Volume Slider
                      Row(
                        children: [
                          const Icon(Icons.volume_down_rounded, size: 18, color: AppTheme.textSecondary),
                          Expanded(
                            child: SliderTheme(
                              data: SliderTheme.of(context).copyWith(
                                activeTrackColor: AppTheme.secondary,
                                inactiveTrackColor: AppTheme.cardBorder,
                                thumbColor: AppTheme.secondary,
                                thumbShape: const RoundSliderThumbShape(enabledThumbRadius: 6),
                                trackHeight: 3,
                              ),
                              child: Slider(
                                value: _castVolume,
                                onChanged: (val) {
                                  setState(() => _castVolume = val);
                                },
                              ),
                            ),
                          ),
                          const Icon(Icons.volume_up_rounded, size: 18, color: AppTheme.textSecondary),
                        ],
                      ),

                      const SizedBox(height: 6),

                      // Disconnect Button
                      SizedBox(
                        width: double.infinity,
                        child: OutlinedButton.icon(
                          onPressed: _handleDisconnect,
                          style: OutlinedButton.styleFrom(
                            foregroundColor: AppTheme.primary,
                            side: const BorderSide(color: AppTheme.primary),
                            padding: const EdgeInsets.symmetric(vertical: 11),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                          ),
                          icon: const Icon(Icons.cast_connected_rounded, size: 16),
                          label: const Text('Disconnect from TV', style: TextStyle(fontWeight: FontWeight.bold)),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
              ],

              // Discovered Receivers Header
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Row(
                    children: [
                      Icon(
                        _selectedTech == DiscoveryTech.wifi
                            ? Icons.wifi_find_rounded
                            : Icons.bluetooth_audio_rounded,
                        color: AppTheme.secondary,
                        size: 16,
                      ),
                      const SizedBox(width: 8),
                      Text(
                        _selectedTech == DiscoveryTech.wifi
                            ? 'Discovered Wi-Fi Receivers'
                            : 'Discovered Bluetooth Sinks',
                        style: const TextStyle(
                          color: AppTheme.textPrimary,
                          fontSize: 13,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ],
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: AppTheme.surfaceLight,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: AppTheme.cardBorder, width: 0.6),
                    ),
                    child: Text(
                      '${_devices.length} found',
                      style: const TextStyle(color: AppTheme.secondary, fontSize: 11, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),

              // Discovered Devices List
              if (_devices.isEmpty)
                Container(
                  padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 16),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: AppTheme.surfaceLight,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AppTheme.cardBorder, width: 0.8),
                  ),
                  child: Column(
                    children: [
                      const SizedBox(
                        width: 24,
                        height: 24,
                        child: CircularProgressIndicator(
                          strokeWidth: 2.5,
                          color: AppTheme.primary,
                        ),
                      ),
                      const SizedBox(height: 12),
                      Text(
                        _selectedTech == DiscoveryTech.wifi
                            ? 'Scanning local Wi-Fi subnet for Chromecasts and Smart TVs...'
                            : 'Scanning nearby Bluetooth Smart TVs and wireless sinks...',
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: AppTheme.textSecondary, fontSize: 12),
                      ),
                    ],
                  ),
                )
              else
                ..._devices.map((device) {
                  final isConnecting = _connectingDeviceId == device.id;
                  final isCurrentActive = connectedDevice?.id == device.id;

                  return Container(
                    margin: const EdgeInsets.only(bottom: 8),
                    decoration: BoxDecoration(
                      color: isCurrentActive
                          ? AppTheme.secondary.withValues(alpha: 0.15)
                          : AppTheme.surfaceLight,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(
                        color: isCurrentActive
                            ? AppTheme.secondary.withValues(alpha: 0.5)
                            : AppTheme.cardBorder,
                        width: isCurrentActive ? 1.2 : 0.8,
                      ),
                    ),
                    child: ListTile(
                      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 2),
                      leading: Stack(
                        clipBehavior: Clip.none,
                        children: [
                          Container(
                            padding: const EdgeInsets.all(9),
                            decoration: BoxDecoration(
                              color: AppTheme.surface,
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: Icon(device.icon, color: Colors.white70, size: 22),
                          ),
                          Positioned(
                            bottom: -2,
                            right: -2,
                            child: Container(
                              padding: const EdgeInsets.all(2.5),
                              decoration: const BoxDecoration(
                                color: AppTheme.surface,
                                shape: BoxShape.circle,
                              ),
                              child: Icon(
                                device.discoveryTech == DiscoveryTech.wifi
                                    ? Icons.wifi_rounded
                                    : Icons.bluetooth_rounded,
                                size: 10,
                                color: AppTheme.secondary,
                              ),
                            ),
                          ),
                        ],
                      ),
                      title: Text(
                        device.name,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                          fontSize: 14,
                        ),
                      ),
                      subtitle: Row(
                        children: [
                          Text(
                            device.ipAddress,
                            style: const TextStyle(color: AppTheme.textMuted, fontSize: 11),
                          ),
                          const SizedBox(width: 6),
                          const Text('•', style: TextStyle(color: AppTheme.textMuted, fontSize: 10)),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(
                              device.protocol,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(color: AppTheme.secondary, fontSize: 11),
                            ),
                          ),
                        ],
                      ),
                      trailing: isConnecting
                          ? const SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: AppTheme.primary,
                              ),
                            )
                          : ElevatedButton(
                              onPressed: isCurrentActive ? null : () => _handleSelectDevice(device),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: isCurrentActive ? AppTheme.surfaceLight : AppTheme.surface,
                                foregroundColor: isCurrentActive ? AppTheme.textMuted : Colors.white,
                                elevation: 0,
                                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                                side: BorderSide(
                                  color: isCurrentActive ? Colors.transparent : AppTheme.cardBorder,
                                ),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                              ),
                              child: Text(
                                isCurrentActive ? 'Connected' : 'Cast',
                                style: TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.bold,
                                  color: isCurrentActive ? AppTheme.secondary : Colors.white,
                                ),
                              ),
                            ),
                    ),
                  );
                }),

              const SizedBox(height: 12),

              // Manual IP Connect Button
              Center(
                child: TextButton.icon(
                  onPressed: _showAddManualIpDialog,
                  icon: const Icon(Icons.add_link_rounded, color: AppTheme.secondary, size: 18),
                  label: const Text(
                    'Direct IP Connect / Add TV Manually',
                    style: TextStyle(
                      color: AppTheme.secondary,
                      fontSize: 13,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
