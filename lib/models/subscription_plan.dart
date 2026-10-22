class SubscriptionPlan {
  final int id;
  final String name;
  final double price;
  final String currency;
  final int durationDays;
  final String description;
  final List<String> features;
  final bool isFeatured;
  final String status;

  const SubscriptionPlan({
    required this.id,
    required this.name,
    required this.price,
    this.currency = 'INR',
    required this.durationDays,
    this.description = '',
    this.features = const [],
    this.isFeatured = false,
    this.status = 'active',
  });

  factory SubscriptionPlan.fromJson(Map<String, dynamic> json) {
    final rawFeatures = json['features'];
    List<String> parsedFeatures = [];
    if (rawFeatures is List) {
      parsedFeatures = rawFeatures.map((e) => e.toString()).toList();
    } else if (rawFeatures is String && rawFeatures.isNotEmpty) {
      parsedFeatures = rawFeatures.split('\n').map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
    }

    return SubscriptionPlan(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name'] as String? ?? 'VIP Plan',
      price: (json['price'] as num?)?.toDouble() ?? 0.0,
      currency: json['currency'] as String? ?? 'INR',
      durationDays: (json['durationDays'] ?? json['duration_days'] as num?)?.toInt() ?? 30,
      description: json['description'] as String? ?? '',
      features: parsedFeatures,
      isFeatured: json['isFeatured'] as bool? ?? (json['is_featured'] == 1 || json['is_featured'] == true),
      status: json['status'] as String? ?? 'active',
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'price': price,
      'currency': currency,
      'durationDays': durationDays,
      'description': description,
      'features': features,
      'isFeatured': isFeatured,
      'status': status,
    };
  }
}
