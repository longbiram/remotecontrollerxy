import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';
import '../models/subscription_plan.dart';
import '../models/user_subscription.dart';

class SubscriptionStatusResult {
  final bool hasActiveSubscription;
  final UserSubscription? subscription;
  final List<dynamic> history;

  const SubscriptionStatusResult({
    required this.hasActiveSubscription,
    this.subscription,
    this.history = const [],
  });
}

class SubscriptionService {
  static const List<SubscriptionPlan> defaultMockPlans = [
    SubscriptionPlan(
      id: 1,
      name: 'Monthly VIP Pass',
      price: 199.0,
      currency: 'INR',
      durationDays: 30,
      description: 'Stream all latest movies, web series & Live TV for 30 days',
      features: [
        'All VIP Movies & Web Series',
        'Full HD 1080p High Bitrate',
        'Live Sports & TV Channels',
        'Ad-Free Clean Experience',
        'Smart TV Chromecast Support',
      ],
      isFeatured: false,
    ),
    SubscriptionPlan(
      id: 2,
      name: 'Quarterly Cinephile',
      price: 499.0,
      currency: 'INR',
      durationDays: 90,
      description: 'Most Popular! 3 full months of unlimited entertainment',
      features: [
        'Everything in Monthly VIP',
        '4K Ultra HD & Dolby Atmos',
        'Priority High-Speed Streaming',
        'Download for Offline Viewing',
        'Cancel Subscription Anytime',
      ],
      isFeatured: true,
    ),
    SubscriptionPlan(
      id: 3,
      name: 'Annual Ultra VIP',
      price: 1499.0,
      currency: 'INR',
      durationDays: 365,
      description: 'Save 37% with full year access to the entire VIP catalog',
      features: [
        'Full 12 Months Access',
        'VIP Early Premiere Access',
        'Multi-Screen Simultaneous Cast',
        'Ultra HD Video Clarity',
        'Dedicated 24/7 VIP Assistance',
      ],
      isFeatured: false,
    ),
  ];

  // Local state cache for offline testing
  static UserSubscription? _offlineSubscription;

  /// Fetch active subscription plans from backend API
  static Future<List<SubscriptionPlan>> fetchPlans() async {
    const endpoint = '/api/plans.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = ApiConfig.getSecureHeaders(endpoint);

      final response = await http.get(uri, headers: headers).timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['status'] == 'success' && data['data'] is List) {
          final List list = data['data'];
          final plans = list.map((j) => SubscriptionPlan.fromJson(j as Map<String, dynamic>)).toList();
          if (plans.isNotEmpty) {
            return plans;
          }
        }
      }
    } catch (e) {
      debugPrint('SubscriptionService.fetchPlans warning: $e. Using starter plans.');
    }
    return defaultMockPlans;
  }

  /// Fetch user subscription state from backend
  static Future<SubscriptionStatusResult> fetchUserSubscription(String token) async {
    const endpoint = '/api/subscription.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final response = await http.get(uri, headers: headers).timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final body = jsonDecode(response.body);
        if (body['status'] == 'success' && body['data'] != null) {
          final data = body['data'];
          final hasActive = data['hasActiveSubscription'] as bool? ?? false;
          UserSubscription? sub;
          if (data['subscription'] != null) {
            sub = UserSubscription.fromJson(data['subscription'] as Map<String, dynamic>);
          }
          final history = data['history'] as List<dynamic>? ?? [];

          return SubscriptionStatusResult(
            hasActiveSubscription: hasActive,
            subscription: sub,
            history: history,
          );
        }
      }
    } catch (e) {
      debugPrint('SubscriptionService.fetchUserSubscription warning: $e');
    }

    // Offline / Demo mode cache
    return SubscriptionStatusResult(
      hasActiveSubscription: _offlineSubscription?.isActive ?? false,
      subscription: _offlineSubscription,
      history: const [],
    );
  }

  /// Request a subscription plan
  static Future<bool> subscribe({
    required String token,
    required SubscriptionPlan plan,
  }) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      debugPrint('SubscriptionService.subscribe: Guest users are not allowed to subscribe.');
      return false;
    }

    const endpoint = '/api/subscription.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final body = jsonEncode({
        'action': 'subscribe',
        'plan_id': plan.id,
      });

      final response = await http.post(uri, headers: headers, body: body).timeout(const Duration(seconds: 5));

      if (response.statusCode == 200 || response.statusCode == 201) {
        final data = jsonDecode(response.body);
        return data['status'] == 'success';
      }
    } catch (e) {
      debugPrint('SubscriptionService.subscribe error: $e');
    }

    // Offline fallback: set pending subscription
    _offlineSubscription = UserSubscription(
      id: DateTime.now().millisecondsSinceEpoch % 10000,
      planId: plan.id,
      planName: plan.name,
      amount: plan.price,
      currency: plan.currency,
      durationDays: plan.durationDays,
      status: 'pending_approval',
      createdAt: DateTime.now(),
    );
    return true;
  }

  /// Submit Transaction ID after payment
  static Future<bool> submitTransactionId({
    required String token,
    required int subscriptionId,
    required String transactionId,
  }) async {
    if (token.isEmpty || token == 'guest_token' || token == 'guest_session') {
      debugPrint('SubscriptionService.submitTransactionId: Guest users are not allowed.');
      return false;
    }

    const endpoint = '/api/subscription.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final body = jsonEncode({
        'action': 'submit_transaction',
        'subscription_id': subscriptionId,
        'transaction_id': transactionId.trim(),
      });

      final response = await http.post(uri, headers: headers, body: body).timeout(const Duration(seconds: 5));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return data['status'] == 'success';
      }
    } catch (e) {
      debugPrint('SubscriptionService.submitTransactionId error: $e');
    }

    // Offline fallback
    if (_offlineSubscription != null) {
      _offlineSubscription = UserSubscription(
        id: _offlineSubscription!.id,
        planId: _offlineSubscription!.planId,
        planName: _offlineSubscription!.planName,
        amount: _offlineSubscription!.amount,
        currency: _offlineSubscription!.currency,
        durationDays: _offlineSubscription!.durationDays,
        status: 'payment_submitted',
        paymentLink: _offlineSubscription!.paymentLink,
        paymentNotes: _offlineSubscription!.paymentNotes,
        transactionId: transactionId.trim(),
        createdAt: _offlineSubscription!.createdAt,
      );
    }
    return true;
  }

  /// Cancel Subscription
  static Future<bool> cancelSubscription({
    required String token,
    int? subscriptionId,
  }) async {
    const endpoint = '/api/subscription.php';
    try {
      final uri = Uri.parse('${ApiConfig.baseUrl}$endpoint');
      final headers = {
        ...ApiConfig.getSecureHeaders(endpoint),
        'Authorization': 'Bearer $token',
        'X-User-Token': token,
      };

      final body = jsonEncode({
        'action': 'cancel',
        'subscription_id': subscriptionId ?? 0,
      });

      final response = await http.post(uri, headers: headers, body: body).timeout(const Duration(seconds: 5));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        return data['status'] == 'success';
      }
    } catch (e) {
      debugPrint('SubscriptionService.cancelSubscription error: $e');
    }

    _offlineSubscription = null;
    return true;
  }

  /// Set offline demo subscription status directly (useful for tests or demoing VIP mode)
  static void setMockActiveSubscription(SubscriptionPlan plan) {
    _offlineSubscription = UserSubscription(
      id: 999,
      planId: plan.id,
      planName: plan.name,
      amount: plan.price,
      currency: plan.currency,
      durationDays: plan.durationDays,
      status: 'active',
      activatedAt: DateTime.now(),
      expiresAt: DateTime.now().add(Duration(days: plan.durationDays)),
      daysLeft: plan.durationDays,
      createdAt: DateTime.now(),
    );
  }
}
