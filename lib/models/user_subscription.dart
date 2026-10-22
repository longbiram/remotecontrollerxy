class UserSubscription {
  final int id;
  final int planId;
  final String planName;
  final double amount;
  final String currency;
  final int durationDays;
  final String status;
  final String paymentLink;
  final String paymentNotes;
  final String transactionId;
  final DateTime? activatedAt;
  final DateTime? expiresAt;
  final int daysLeft;
  final DateTime? createdAt;

  const UserSubscription({
    required this.id,
    required this.planId,
    required this.planName,
    required this.amount,
    this.currency = 'INR',
    required this.durationDays,
    required this.status,
    this.paymentLink = '',
    this.paymentNotes = '',
    this.transactionId = '',
    this.activatedAt,
    this.expiresAt,
    this.daysLeft = 0,
    this.createdAt,
  });

  bool get isActive => status == 'active';
  bool get isPendingApproval => status == 'pending_approval';
  bool get isPaymentLinkSent => status == 'payment_link_sent';
  bool get isPaymentSubmitted => status == 'payment_submitted';
  bool get isExpired => status == 'expired';
  bool get isCancelled => status == 'cancelled';

  factory UserSubscription.fromJson(Map<String, dynamic> json) {
    DateTime? parseDate(dynamic val) {
      if (val == null) return null;
      try {
        return DateTime.parse(val.toString());
      } catch (_) {
        return null;
      }
    }

    return UserSubscription(
      id: (json['id'] as num?)?.toInt() ?? 0,
      planId: (json['planId'] ?? json['plan_id'] as num?)?.toInt() ?? 0,
      planName: json['planName'] ?? json['plan_name'] as String? ?? 'VIP Plan',
      amount: (json['amount'] as num?)?.toDouble() ?? 0.0,
      currency: json['currency'] as String? ?? 'INR',
      durationDays: (json['durationDays'] ?? json['duration_days'] as num?)?.toInt() ?? 30,
      status: json['status'] as String? ?? 'pending_approval',
      paymentLink: json['paymentLink'] ?? json['payment_link'] as String? ?? '',
      paymentNotes: json['paymentNotes'] ?? json['payment_notes'] as String? ?? '',
      transactionId: json['transactionId'] ?? json['transaction_id'] as String? ?? '',
      activatedAt: parseDate(json['activatedAt'] ?? json['activated_at']),
      expiresAt: parseDate(json['expiresAt'] ?? json['expires_at']),
      daysLeft: (json['daysLeft'] ?? json['days_left'] as num?)?.toInt() ?? 0,
      createdAt: parseDate(json['createdAt'] ?? json['created_at']),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'planId': planId,
      'planName': planName,
      'amount': amount,
      'currency': currency,
      'durationDays': durationDays,
      'status': status,
      'paymentLink': paymentLink,
      'paymentNotes': paymentNotes,
      'transactionId': transactionId,
      'activatedAt': activatedAt?.toIso8601String(),
      'expiresAt': expiresAt?.toIso8601String(),
      'daysLeft': daysLeft,
      'createdAt': createdAt?.toIso8601String(),
    };
  }
}
