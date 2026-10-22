import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import '../theme/app_theme.dart';
import '../state/app_state.dart';
import '../models/subscription_plan.dart';
import '../models/user_subscription.dart';
import 'login_screen.dart';

class ProfileScreen extends StatefulWidget {
  final AppState state;

  const ProfileScreen({super.key, required this.state});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final TextEditingController _txController = TextEditingController();
  bool _isSubmittingTx = false;
  bool _isRefreshing = false;
  String? _txError;

  @override
  void dispose() {
    _txController.dispose();
    super.dispose();
  }

  Future<void> _refreshStatus() async {
    setState(() => _isRefreshing = true);
    await widget.state.refreshSubscription();
    if (mounted) {
      setState(() => _isRefreshing = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Subscription status refreshed'),
          duration: Duration(seconds: 2),
          backgroundColor: AppTheme.surfaceLight,
        ),
      );
    }
  }

  Future<void> _handlePlanSelection(SubscriptionPlan plan) async {
    // Guest users are NOT allowed to subscribe to plans: require login
    if (widget.state.isGuest || widget.state.currentUser == null) {
      final shouldLogin = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppTheme.surface,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Row(
            children: [
              Icon(Icons.lock_outline_rounded, color: AppTheme.accentGold, size: 24),
              SizedBox(width: 8),
              Text(
                'Sign In Required',
                style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
              ),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Guest users are not allowed to subscribe to plans.',
                style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14),
              ),
              const SizedBox(height: 8),
              Text(
                'Please sign in or create an account to subscribe to ${plan.name} and unlock VIP access.',
                style: const TextStyle(color: AppTheme.textSecondary, fontSize: 13, height: 1.4),
              ),
              const SizedBox(height: 12),
              const Text(
                'Your account securely links your VIP subscription, payment approval, and viewing privileges across devices.',
                style: TextStyle(color: AppTheme.textMuted, fontSize: 12, height: 1.3),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Cancel', style: TextStyle(color: AppTheme.textMuted)),
            ),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppTheme.primary,
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
              onPressed: () => Navigator.pop(ctx, true),
              icon: const Icon(Icons.login_rounded, size: 18, color: Colors.white),
              label: const Text('Sign In Now', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            ),
          ],
        ),
      );

      if (shouldLogin == true && mounted) {
        Navigator.of(context).push(
          MaterialPageRoute(builder: (context) => LoginScreen(state: widget.state)),
        );
      }
      return;
    }

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppTheme.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            const Icon(Icons.workspace_premium_rounded, color: AppTheme.accentGold, size: 24),
            const SizedBox(width: 8),
            Text(
              'Select ${plan.name}',
              style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
            ),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Price: ₹${plan.price.toStringAsFixed(0)} for ${plan.durationDays} Days',
              style: const TextStyle(color: AppTheme.secondary, fontWeight: FontWeight.bold, fontSize: 15),
            ),
            const SizedBox(height: 10),
            const Text(
              'Once selected, your request will be sent to the admin. The admin will review it and provide your official payment link.',
              style: TextStyle(color: AppTheme.textSecondary, fontSize: 13),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel', style: TextStyle(color: AppTheme.textMuted)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.primary,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Confirm Request', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );

    if (confirmed == true && mounted) {
      final success = await widget.state.selectPlan(plan);
      if (mounted) {
        if (success) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Requested ${plan.name}! Waiting for admin approval.'),
              backgroundColor: const Color(0xFF1B5E20),
            ),
          );
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Could not request plan. Please try again.'),
              backgroundColor: Colors.redAccent,
            ),
          );
        }
      }
    }
  }

  Future<void> _handleSubmitTx() async {
    if (widget.state.isGuest || widget.state.currentUser == null) {
      Navigator.of(context).push(
        MaterialPageRoute(builder: (context) => LoginScreen(state: widget.state)),
      );
      return;
    }

    final txId = _txController.text.trim();
    if (txId.isEmpty) {
      setState(() => _txError = 'Please enter your Transaction or UTR ID');
      return;
    }

    setState(() {
      _isSubmittingTx = true;
      _txError = null;
    });

    final success = await widget.state.submitPaymentTransaction(txId);

    if (mounted) {
      setState(() => _isSubmittingTx = false);
      if (success) {
        _txController.clear();
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Transaction ID submitted! Admin is verifying your payment.'),
            backgroundColor: Color(0xFF1B5E20),
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Failed to submit transaction. Please try again.'),
            backgroundColor: Colors.redAccent,
          ),
        );
      }
    }
  }

  Future<void> _handleCancelSubscription() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppTheme.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('Cancel Subscription?', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        content: const Text(
          'Are you sure you want to cancel your VIP subscription? You can select another plan at any time.',
          style: TextStyle(color: AppTheme.textSecondary, fontSize: 13),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Keep VIP', style: TextStyle(color: AppTheme.secondary)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.red.shade900,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Yes, Cancel', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );

    if (confirmed == true && mounted) {
      final success = await widget.state.cancelActiveSubscription();
      if (mounted) {
        if (success) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Subscription cancelled successfully.'),
              backgroundColor: AppTheme.surfaceLight,
            ),
          );
        }
      }
    }
  }

  Future<void> _handleLogout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppTheme.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('Sign Out', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
        content: const Text(
          'Are you sure you want to sign out from PikaShow?',
          style: TextStyle(color: AppTheme.textSecondary, fontSize: 13),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel', style: TextStyle(color: AppTheme.textMuted)),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: AppTheme.primary,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Sign Out', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );

    if (confirmed == true && mounted) {
      await widget.state.logout();
      if (mounted) {
        Navigator.of(context).pushAndRemoveUntil(
          MaterialPageRoute(builder: (context) => LoginScreen(state: widget.state)),
          (route) => false,
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = widget.state.currentUser;
    final isGuest = widget.state.isGuest;
    final sub = widget.state.currentSubscription;
    final isVip = widget.state.hasActiveSubscription;

    return Scaffold(
      backgroundColor: AppTheme.background,
      appBar: AppBar(
        title: Text(
          'User Profile',
          style: GoogleFonts.outfit(
            color: Colors.white,
            fontWeight: FontWeight.bold,
            fontSize: 20,
          ),
        ),
        backgroundColor: AppTheme.background,
        elevation: 0,
        actions: [
          IconButton(
            icon: _isRefreshing
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2, color: AppTheme.secondary),
                  )
                : const Icon(Icons.refresh_rounded, color: Colors.white),
            tooltip: 'Refresh Status',
            onPressed: _isRefreshing ? null : _refreshStatus,
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: RefreshIndicator(
        color: AppTheme.primary,
        backgroundColor: AppTheme.surface,
        onRefresh: _refreshStatus,
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(parent: BouncingScrollPhysics()),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // 1. User Header Card
              _buildUserHeader(user, isGuest, isVip),
              const SizedBox(height: 20),

              // 2. Subscription Management Section
              _buildSubscriptionSection(sub, isVip),
              const SizedBox(height: 24),

              // 3. Information & Legal Policies
              _buildPoliciesSection(),
              const SizedBox(height: 24),

              // 4. Account Action (Logout / Login)
              _buildAccountAction(isGuest),
              const SizedBox(height: 32),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildUserHeader(dynamic user, bool isGuest, bool isVip) {
    final name = isGuest ? 'Guest User' : (user?.name ?? 'PikaShow User');
    final email = isGuest ? 'Browsing in Guest Mode' : (user?.email ?? 'No email');
    final initial = name.isNotEmpty ? name[0].toUpperCase() : 'U';

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppTheme.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: isVip ? AppTheme.accentGold.withValues(alpha: 0.5) : AppTheme.cardBorder,
          width: isVip ? 1.5 : 1.0,
        ),
        boxShadow: [
          if (isVip)
            BoxShadow(
              color: AppTheme.accentGold.withValues(alpha: 0.15),
              blurRadius: 16,
              offset: const Offset(0, 4),
            ),
        ],
      ),
      child: Row(
        children: [
          // Avatar
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: isVip
                  ? const LinearGradient(
                      colors: [Color(0xFFFFB800), Color(0xFFFF8C00)],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    )
                  : const LinearGradient(
                      colors: [AppTheme.primary, Color(0xFF9C27B0)],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
              boxShadow: [
                BoxShadow(
                  color: (isVip ? const Color(0xFFFFB800) : AppTheme.primary).withValues(alpha: 0.4),
                  blurRadius: 10,
                  offset: const Offset(0, 3),
                ),
              ],
            ),
            child: Center(
              child: isVip
                  ? const Icon(Icons.workspace_premium_rounded, color: Colors.black, size: 34)
                  : Text(
                      initial,
                      style: GoogleFonts.outfit(
                        color: Colors.white,
                        fontSize: 26,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
            ),
          ),
          const SizedBox(width: 16),
          // User details
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        name,
                        style: GoogleFonts.outfit(
                          color: Colors.white,
                          fontSize: 19,
                          fontWeight: FontWeight.bold,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 3),
                Text(
                  email,
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 8),
                // Badges row
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: isVip
                            ? AppTheme.accentGold.withValues(alpha: 0.2)
                            : (isGuest ? Colors.grey.withValues(alpha: 0.2) : AppTheme.secondary.withValues(alpha: 0.2)),
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(
                          color: isVip
                              ? AppTheme.accentGold
                              : (isGuest ? Colors.grey : AppTheme.secondary),
                          width: 0.8,
                        ),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            isVip
                                ? Icons.workspace_premium_rounded
                                : (isGuest ? Icons.person_outline : Icons.verified_user_rounded),
                            size: 13,
                            color: isVip
                                ? AppTheme.accentGold
                                : (isGuest ? Colors.grey : AppTheme.secondary),
                          ),
                          const SizedBox(width: 4),
                          Text(
                            isVip ? 'VIP SUBSCRIBER' : (isGuest ? 'GUEST' : 'FREE USER'),
                            style: TextStyle(
                              color: isVip
                                  ? AppTheme.accentGold
                                  : (isGuest ? Colors.grey.shade300 : AppTheme.secondary),
                              fontSize: 10,
                              fontWeight: FontWeight.bold,
                              letterSpacing: 0.5,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSubscriptionSection(UserSubscription? sub, bool isVip) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              'VIP Subscription',
              style: GoogleFonts.outfit(
                color: Colors.white,
                fontSize: 18,
                fontWeight: FontWeight.bold,
              ),
            ),
            if (sub != null)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: AppTheme.surfaceLight,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  'ID: #${sub.id}',
                  style: const TextStyle(color: AppTheme.textMuted, fontSize: 11),
                ),
              ),
          ],
        ),
        const SizedBox(height: 12),

        // Dynamic State Rendering
        if (isVip && sub != null)
          _buildActiveSubscriptionCard(sub)
        else if (sub?.isPendingApproval == true)
          _buildPendingApprovalCard(sub!)
        else if (sub?.isPaymentLinkSent == true)
          _buildPaymentLinkSentCard(sub!)
        else if (sub?.isPaymentSubmitted == true)
          _buildPaymentSubmittedCard(sub!)
        else ...[
          if (sub?.isExpired == true)
            _buildExpiredBanner(sub!),
          _buildAvailablePlansList(),
        ],
      ],
    );
  }

  // 1. ACTIVE VIP CARD
  Widget _buildActiveSubscriptionCard(UserSubscription sub) {
    final expStr = sub.expiresAt != null
        ? '${sub.expiresAt!.day}/${sub.expiresAt!.month}/${sub.expiresAt!.year}'
        : 'Lifetime';

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            const Color(0xFF2C2208),
            AppTheme.surface,
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppTheme.accentGold.withValues(alpha: 0.7), width: 1.2),
        boxShadow: [
          BoxShadow(
            color: AppTheme.accentGold.withValues(alpha: 0.15),
            blurRadius: 14,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  const Icon(Icons.workspace_premium_rounded, color: AppTheme.accentGold, size: 24),
                  const SizedBox(width: 8),
                  Text(
                    sub.planName,
                    style: GoogleFonts.outfit(
                      color: Colors.white,
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ],
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [Color(0xFFFFB800), Color(0xFFFF8C00)],
                  ),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: const Text(
                  'ACTIVE VIP',
                  style: TextStyle(
                    color: Colors.black,
                    fontSize: 11,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          const Divider(color: AppTheme.cardBorder, height: 1),
          const SizedBox(height: 12),
          Row(
            children: [
              const Icon(Icons.event_available_rounded, size: 16, color: AppTheme.secondary),
              const SizedBox(width: 6),
              Text(
                'Expires on: $expStr',
                style: const TextStyle(color: Colors.white70, fontSize: 13),
              ),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: AppTheme.secondary.withValues(alpha: 0.2),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  '${sub.daysLeft} Days Remaining',
                  style: const TextStyle(
                    color: AppTheme.secondary,
                    fontSize: 11,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          const Text(
            'Enjoy unlimited 4K Ultra HD Movies, Web Series, and VIP Live TV Channels with high-speed streaming!',
            style: TextStyle(color: AppTheme.textSecondary, fontSize: 12),
          ),
          const SizedBox(height: 16),
          // Cancel Subscription Action
          Align(
            alignment: Alignment.centerRight,
            child: OutlinedButton.icon(
              onPressed: _handleCancelSubscription,
              icon: const Icon(Icons.cancel_outlined, size: 16, color: Colors.redAccent),
              label: const Text(
                'Cancel Subscription',
                style: TextStyle(color: Colors.redAccent, fontSize: 12, fontWeight: FontWeight.bold),
              ),
              style: OutlinedButton.styleFrom(
                side: const BorderSide(color: Colors.redAccent, width: 0.8),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              ),
            ),
          ),
        ],
      ),
    );
  }

  // 2. PENDING APPROVAL CARD
  Widget _buildPendingApprovalCard(UserSubscription sub) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppTheme.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.amber.shade700, width: 1.2),
        boxShadow: [
          BoxShadow(
            color: Colors.amber.withValues(alpha: 0.1),
            blurRadius: 10,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.amber.withValues(alpha: 0.2),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.hourglass_top_rounded, color: Colors.amber, size: 22),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Wait for Approval',
                      style: TextStyle(
                        color: Colors.amber,
                        fontSize: 16,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    Text(
                      'Plan: ${sub.planName} • ₹${sub.amount.toStringAsFixed(0)}',
                      style: const TextStyle(color: Colors.white70, fontSize: 13),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppTheme.surfaceLight,
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Text(
              'Your plan request has been submitted to the Admin Panel. The admin will review it and send your payment link here shortly. Please check back soon or click Refresh.',
              style: TextStyle(color: AppTheme.textSecondary, fontSize: 12, height: 1.4),
            ),
          ),
          const SizedBox(height: 14),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              TextButton.icon(
                onPressed: _handleCancelSubscription,
                icon: const Icon(Icons.close_rounded, size: 16, color: AppTheme.textMuted),
                label: const Text('Cancel Request', style: TextStyle(color: AppTheme.textMuted, fontSize: 12)),
              ),
              ElevatedButton.icon(
                onPressed: _refreshStatus,
                icon: const Icon(Icons.refresh_rounded, size: 16, color: Colors.white),
                label: const Text('Check Status', style: TextStyle(color: Colors.white, fontSize: 12)),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppTheme.surfaceLight,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  // 3. PAYMENT LINK SENT CARD
  Widget _buildPaymentLinkSentCard(UserSubscription sub) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppTheme.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppTheme.secondary, width: 1.5),
        boxShadow: [
          BoxShadow(
            color: AppTheme.secondary.withValues(alpha: 0.15),
            blurRadius: 14,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: AppTheme.secondary.withValues(alpha: 0.2),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.payment_rounded, color: AppTheme.secondary, size: 22),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Payment Link Ready!',
                      style: TextStyle(
                        color: AppTheme.secondary,
                        fontSize: 16,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    Text(
                      'Plan: ${sub.planName} • ₹${sub.amount.toStringAsFixed(0)} (${sub.durationDays} Days)',
                      style: const TextStyle(color: Colors.white70, fontSize: 13),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),

          if (sub.paymentNotes.isNotEmpty) ...[
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: AppTheme.surfaceLight,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: AppTheme.cardBorder, width: 0.8),
              ),
              child: Text(
                'Note from Admin: ${sub.paymentNotes}',
                style: const TextStyle(color: AppTheme.accentGold, fontSize: 12),
              ),
            ),
            const SizedBox(height: 12),
          ],

          // Payment Link action button
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppTheme.surfaceLight,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppTheme.cardBorder),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Payment Link:',
                  style: TextStyle(color: AppTheme.textMuted, fontSize: 11, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 4),
                SelectableText(
                  sub.paymentLink.isNotEmpty ? sub.paymentLink : 'https://pay.pikashow.com/invoice/${sub.id}',
                  style: const TextStyle(
                    color: AppTheme.secondary,
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    ElevatedButton.icon(
                      onPressed: () {
                        final link = sub.paymentLink.isNotEmpty
                            ? sub.paymentLink
                            : 'https://pay.pikashow.com/invoice/${sub.id}';
                        Clipboard.setData(ClipboardData(text: link));
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(
                            content: Text('Payment link copied to clipboard! Complete payment and enter Transaction ID.'),
                            backgroundColor: Color(0xFF1B5E20),
                          ),
                        );
                      },
                      icon: const Icon(Icons.copy_rounded, size: 14, color: Colors.black),
                      label: const Text('Copy Payment Link', style: TextStyle(color: Colors.black, fontWeight: FontWeight.bold, fontSize: 12)),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppTheme.secondary,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),

          // Transaction ID Input
          const Text(
            'Step 2: Submit Transaction / UTR ID',
            style: TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 6),
          const Text(
            'After making payment, paste your payment reference/UTR number below for admin verification.',
            style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _txController,
            style: const TextStyle(color: Colors.white, fontSize: 14),
            decoration: InputDecoration(
              hintText: 'e.g. UPI/UTR 423987102938 or TXN987654',
              hintStyle: const TextStyle(color: AppTheme.textMuted, fontSize: 13),
              filled: true,
              fillColor: AppTheme.surfaceLight,
              errorText: _txError,
              contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppTheme.cardBorder),
              ),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppTheme.cardBorder),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(10),
                borderSide: const BorderSide(color: AppTheme.primary),
              ),
            ),
          ),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: _isSubmittingTx ? null : _handleSubmitTx,
              icon: _isSubmittingTx
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.check_circle_rounded, color: Colors.white, size: 18),
              label: Text(
                _isSubmittingTx ? 'Submitting...' : 'Submit Transaction ID',
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: AppTheme.primary,
                padding: const EdgeInsets.symmetric(vertical: 12),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                elevation: 4,
              ),
            ),
          ),
          const SizedBox(height: 8),
          Center(
            child: TextButton(
              onPressed: _handleCancelSubscription,
              child: const Text('Cancel Request', style: TextStyle(color: AppTheme.textMuted, fontSize: 12)),
            ),
          ),
        ],
      ),
    );
  }

  // 4. PAYMENT SUBMITTED CARD
  Widget _buildPaymentSubmittedCard(UserSubscription sub) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppTheme.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFAB47BC), width: 1.2),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFFAB47BC).withValues(alpha: 0.15),
            blurRadius: 10,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: const Color(0xFFAB47BC).withValues(alpha: 0.2),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.verified_outlined, color: Color(0xFFCE93D8), size: 22),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Payment Under Verification',
                      style: TextStyle(
                        color: Color(0xFFCE93D8),
                        fontSize: 16,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    Text(
                      'Plan: ${sub.planName} • ₹${sub.amount.toStringAsFixed(0)}',
                      style: const TextStyle(color: Colors.white70, fontSize: 13),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppTheme.surfaceLight,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Text(
                      'Submitted Transaction ID: ',
                      style: TextStyle(color: AppTheme.textMuted, fontSize: 12),
                    ),
                    Expanded(
                      child: Text(
                        sub.transactionId.isNotEmpty ? sub.transactionId : 'Recorded',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 13,
                          fontWeight: FontWeight.bold,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                const Text(
                  'The admin panel is reviewing your transaction ID. Your VIP subscription will be activated for the full duration as soon as verification completes!',
                  style: TextStyle(color: AppTheme.textSecondary, fontSize: 12, height: 1.4),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              TextButton.icon(
                onPressed: _handleCancelSubscription,
                icon: const Icon(Icons.cancel_outlined, size: 16, color: AppTheme.textMuted),
                label: const Text('Cancel Request', style: TextStyle(color: AppTheme.textMuted, fontSize: 12)),
              ),
              ElevatedButton.icon(
                onPressed: _refreshStatus,
                icon: const Icon(Icons.refresh_rounded, size: 16, color: Colors.white),
                label: const Text('Refresh Status', style: TextStyle(color: Colors.white, fontSize: 12)),
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppTheme.surfaceLight,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  // EXPIRED BANNER
  Widget _buildExpiredBanner(UserSubscription sub) {
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.red.shade900.withValues(alpha: 0.25),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.red.shade700, width: 0.8),
      ),
      child: Row(
        children: [
          const Icon(Icons.warning_amber_rounded, color: Colors.redAccent, size: 20),
          const SizedBox(width: 10),
          const Expanded(
            child: Text(
              'Your VIP subscription has expired. Please select a plan below to renew your access.',
              style: TextStyle(color: Colors.white70, fontSize: 12),
            ),
          ),
        ],
      ),
    );
  }

  // 5. AVAILABLE PLANS LIST
  Widget _buildAvailablePlansList() {
    final plans = widget.state.availablePlans;
    final isGuest = widget.state.isGuest;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (isGuest)
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  AppTheme.primary.withValues(alpha: 0.18),
                  AppTheme.surfaceLight,
                ],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: AppTheme.primary.withValues(alpha: 0.4)),
            ),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withValues(alpha: 0.25),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(Icons.lock_person_rounded, color: AppTheme.secondary, size: 20),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Guest Mode: Sign In to Subscribe',
                        style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                      ),
                      const SizedBox(height: 3),
                      const Text(
                        'Guest users cannot subscribe to plans. Tap any plan to log in and unlock VIP.',
                        style: TextStyle(color: AppTheme.textSecondary, fontSize: 11),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                TextButton(
                  onPressed: () {
                    Navigator.of(context).push(
                      MaterialPageRoute(builder: (context) => LoginScreen(state: widget.state)),
                    );
                  },
                  style: TextButton.styleFrom(
                    backgroundColor: AppTheme.primary,
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                  ),
                  child: const Text(
                    'Sign In',
                    style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12),
                  ),
                ),
              ],
            ),
          )
        else
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              color: AppTheme.surfaceLight,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppTheme.cardBorder),
            ),
            child: const Row(
              children: [
                Icon(Icons.stars_rounded, color: AppTheme.accentGold, size: 24),
                SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'Select a plan below to request VIP access. Admin will send your direct payment link upon review.',
                    style: TextStyle(color: AppTheme.textSecondary, fontSize: 12),
                  ),
                ),
              ],
            ),
          ),
        const SizedBox(height: 14),

        if (plans.isEmpty)
          const Center(
            child: Padding(
              padding: EdgeInsets.all(24.0),
              child: Text(
                'No subscription plans available right now. Please check back later.',
                style: TextStyle(color: AppTheme.textMuted),
              ),
            ),
          )
        else
          ...plans.map((p) => _buildPlanCard(p)),
      ],
    );
  }

  Widget _buildPlanCard(SubscriptionPlan plan) {
    final isPopular = plan.isFeatured;
    final isGuest = widget.state.isGuest;

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      decoration: BoxDecoration(
        color: AppTheme.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: isPopular ? AppTheme.accentGold : AppTheme.cardBorder,
          width: isPopular ? 1.5 : 1.0,
        ),
        boxShadow: [
          if (isPopular)
            BoxShadow(
              color: AppTheme.accentGold.withValues(alpha: 0.12),
              blurRadius: 14,
              offset: const Offset(0, 3),
            ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (isPopular)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 4),
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  colors: [Color(0xFFFFB800), Color(0xFFFF8C00)],
                ),
                borderRadius: BorderRadius.vertical(top: Radius.circular(14)),
              ),
              child: const Center(
                child: Text(
                  'MOST POPULAR CHOICE',
                  style: TextStyle(
                    color: Colors.black,
                    fontSize: 10,
                    fontWeight: FontWeight.w900,
                    letterSpacing: 1.0,
                  ),
                ),
              ),
            ),
          Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            plan.name,
                            style: GoogleFonts.outfit(
                              color: Colors.white,
                              fontSize: 18,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            plan.description,
                            style: const TextStyle(color: AppTheme.textMuted, fontSize: 12),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 10),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          '₹${plan.price.toStringAsFixed(0)}',
                          style: GoogleFonts.outfit(
                            color: isPopular ? AppTheme.accentGold : Colors.white,
                            fontSize: 22,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                        Text(
                          '/${plan.durationDays} Days',
                          style: const TextStyle(color: AppTheme.textMuted, fontSize: 11),
                        ),
                      ],
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                const Divider(color: AppTheme.cardBorder, height: 1),
                const SizedBox(height: 12),

                // Features list
                ...plan.features.map(
                  (f) => Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Icon(Icons.check_circle_rounded, color: AppTheme.secondary, size: 15),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            f,
                            style: const TextStyle(color: Colors.white70, fontSize: 12),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),

                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    onPressed: () => _handlePlanSelection(plan),
                    icon: Icon(
                      isGuest ? Icons.login_rounded : Icons.check_circle_outline_rounded,
                      size: 17,
                      color: isPopular && !isGuest ? Colors.black : Colors.white,
                    ),
                    label: Text(
                      isGuest
                          ? 'Sign In to Subscribe'
                          : 'Select ${plan.name}',
                      style: const TextStyle(
                        fontWeight: FontWeight.w900,
                        fontSize: 14,
                        letterSpacing: 0.5,
                      ),
                    ),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: isGuest
                          ? AppTheme.primary
                          : (isPopular ? AppTheme.accentGold : AppTheme.primary),
                      foregroundColor: isPopular && !isGuest ? Colors.black : Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 13),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      elevation: 4,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // 3. INFORMATION & LEGAL POLICIES SECTION
  Widget _buildPoliciesSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Information & Policies',
          style: GoogleFonts.outfit(
            color: Colors.white,
            fontSize: 18,
            fontWeight: FontWeight.bold,
          ),
        ),
        const SizedBox(height: 12),
        Material(
          color: AppTheme.surface,
          borderRadius: BorderRadius.circular(16),
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppTheme.cardBorder),
            ),
            child: Column(
              children: [
                _buildPolicyTile(
                  icon: Icons.info_outline_rounded,
                  title: 'About Us',
                  subtitle: 'Platform vision, high-speed streaming & tech',
                  onTap: () => _showPolicyModal(context, 'About Us', _aboutUsContent),
                ),
                const Divider(color: AppTheme.cardBorder, height: 1),
                _buildPolicyTile(
                  icon: Icons.description_outlined,
                  title: 'Terms and Conditions',
                  subtitle: 'Rules of service, VIP subscription & account policies',
                  onTap: () => _showPolicyModal(context, 'Terms and Conditions', _termsContent),
                ),
                const Divider(color: AppTheme.cardBorder, height: 1),
                _buildPolicyTile(
                  icon: Icons.shield_outlined,
                  title: 'Privacy Policy',
                  subtitle: 'How your data is encrypted, processed & safeguarded',
                  onTap: () => _showPolicyModal(context, 'Privacy Policy', _privacyContent),
                ),
                const Divider(color: AppTheme.cardBorder, height: 1),
                _buildPolicyTile(
                  icon: Icons.currency_exchange_rounded,
                  title: 'Refund Policy',
                  subtitle: 'Transparent cancellation & refund terms',
                  onTap: () => _showPolicyModal(context, 'Refund Policy', _refundContent),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildPolicyTile({
    required IconData icon,
    required String title,
    required String subtitle,
    required VoidCallback onTap,
  }) {
    return Material(
      color: Colors.transparent,
      child: ListTile(
        onTap: onTap,
        leading: Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: AppTheme.surfaceLight,
            borderRadius: BorderRadius.circular(8),
          ),
          child: Icon(icon, color: AppTheme.secondary, size: 20),
        ),
        title: Text(
          title,
          style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.w600),
        ),
        subtitle: Text(
          subtitle,
          style: const TextStyle(color: AppTheme.textMuted, fontSize: 11),
        ),
        trailing: const Icon(Icons.chevron_right_rounded, color: AppTheme.textMuted, size: 20),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      ),
    );
  }

  // 4. ACCOUNT ACTION
  Widget _buildAccountAction(bool isGuest) {
    if (isGuest) {
      return SizedBox(
        width: double.infinity,
        child: ElevatedButton.icon(
          onPressed: () {
            Navigator.of(context).pushAndRemoveUntil(
              MaterialPageRoute(builder: (context) => LoginScreen(state: widget.state)),
              (route) => false,
            );
          },
          icon: const Icon(Icons.login_rounded, color: Colors.white),
          label: const Text(
            'Sign In / Create Account',
            style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 15),
          ),
          style: ElevatedButton.styleFrom(
            backgroundColor: AppTheme.primary,
            padding: const EdgeInsets.symmetric(vertical: 14),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
      );
    }

    return SizedBox(
      width: double.infinity,
      child: OutlinedButton.icon(
        onPressed: _handleLogout,
        icon: const Icon(Icons.logout_rounded, color: Colors.redAccent, size: 20),
        label: const Text(
          'Sign Out from PikaShow',
          style: TextStyle(color: Colors.redAccent, fontWeight: FontWeight.bold, fontSize: 14),
        ),
        style: OutlinedButton.styleFrom(
          side: const BorderSide(color: Colors.redAccent, width: 1.0),
          padding: const EdgeInsets.symmetric(vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
      ),
    );
  }

  // Modal Viewer for Legal Policies
  void _showPolicyModal(BuildContext context, String title, String content) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppTheme.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return DraggableScrollableSheet(
          initialChildSize: 0.85,
          minChildSize: 0.5,
          maxChildSize: 0.95,
          expand: false,
          builder: (c, scrollController) {
            return Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
              child: Column(
                children: [
                  Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(
                      color: AppTheme.cardBorder,
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        title,
                        style: GoogleFonts.outfit(
                          color: Colors.white,
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      IconButton(
                        icon: const Icon(Icons.close_rounded, color: Colors.white70),
                        onPressed: () => Navigator.pop(ctx),
                      ),
                    ],
                  ),
                  const Divider(color: AppTheme.cardBorder),
                  Expanded(
                    child: ListView(
                      controller: scrollController,
                      physics: const BouncingScrollPhysics(),
                      children: [
                        const SizedBox(height: 8),
                        Text(
                          content,
                          style: const TextStyle(
                            color: AppTheme.textSecondary,
                            fontSize: 13,
                            height: 1.6,
                          ),
                        ),
                        const SizedBox(height: 32),
                      ],
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  // Rich Content for Policies
  static const String _aboutUsContent = '''
Welcome to PikaShow!

PikaShow is your premier all-in-one entertainment streaming hub, delivering high-definition movies, trending web series, and live television right to your Android device and Smart TV via Chromecast.

Key Highlights of PikaShow:
• Massive Library: Stream thousands of Hollywood hits, Bollywood blockbusters, and binge-worthy web series.
• Live Sports & Television: Access popular news, sports, and entertainment channels 24/7 with zero lag.
• Smart TV & Chromecast Integration: Cast your favorite titles to any Google Cast or Smart TV device effortlessly.
• VIP Exclusive Content: Upgrade to PikaShow VIP to enjoy 4K Ultra HD streaming, ad-free experience, and early access premieres.
• Secure Platform: All user authentication and payments are guarded by industry-grade encryption.

Version: 1.0.0+1
Support: support@pikashow.com
''';

  static const String _termsContent = '''
PikaShow Terms and Conditions

Last Updated: October 2026

1. Acceptance of Terms
By accessing or using the PikaShow application, you agree to comply with and be bound by these Terms and Conditions. If you disagree with any part of these terms, please do not use the application.

2. Account Responsibility
You are responsible for safeguarding your login credentials. You agree to notify us immediately of any unauthorized access to your account. PikaShow cannot be held liable for any loss resulting from unauthorized use of your credentials.

3. VIP Subscriptions and Activation
• Subscription plans grant access to VIP-designated movies, series, and live television for the specified duration (e.g. 30, 90, or 365 days).
• Upon selecting a plan, users must wait for admin verification and receive a direct payment link.
• After completing payment, users must submit their valid Transaction / UTR ID. The subscription activates upon admin verification.
• Subscriptions automatically expire at the end of the duration days. Users may cancel their active subscription or select a new plan at any time.

4. Content Usage & Intellectual Property
All media links, metadata, and branding accessible on PikaShow are provided for personal, non-commercial entertainment purposes only. Users are strictly prohibited from redistributing, capturing, or re-broadcasting content without explicit authorization.

5. Service Availability
We continuously strive for 99.9% uptime, but availability of third-party streaming sources and live TV feeds may vary based on regional network constraints.

6. Termination
We reserve the right to suspend or terminate accounts that engage in fraudulent payment submissions or abuse of service.
''';

  static const String _privacyContent = '''
PikaShow Privacy Policy

Last Updated: October 2026

Your privacy is paramount to us. This Privacy Policy details how PikaShow collects, stores, and protects your personal information:

1. Information We Collect
• Account Information: Name and email address provided during registration.
• Transaction References: Submitted Transaction IDs for VIP subscription verification.
• Device & Technical Data: Basic device model, OS version, and network latency metrics to optimize video playback.

2. How We Use Your Data
• To authenticate your profile and grant access to VIP streaming libraries.
• To verify payments and update your subscription expiry timeline.
• To enhance playback performance and resolve playback buffering.

3. Zero Selling Policy
We do NOT sell, rent, or trade your personal data to any third-party marketing companies.

4. Security Measures
All passwords are encrypted with SHA-256 and salted hashes before storage. All API communication uses secure TLS encryption and HMAC request signing.

5. Your Rights
You have the right to request deletion of your account and viewing history at any time through the app settings or by contacting support@pikashow.com.
''';

  static const String _refundContent = '''
PikaShow Refund Policy

Last Updated: October 2026

We value transparency and user satisfaction. Please review our refund guidelines regarding VIP subscriptions:

1. Eligibility for Refund
• If you made a payment and submitted a valid Transaction ID, but your VIP plan was not activated within 48 hours of verification, you are entitled to a full 100% refund.
• If technical playback failure prevents you from streaming VIP content on your verified device and our support team is unable to resolve it within 3 business days, a prorated refund will be provided.

2. Non-Refundable Scenarios
• Requests made after 7 days from the plan activation date.
• Change of mind after substantial VIP streaming activity.
• Account suspension resulting from violation of our Terms of Service (e.g. fraudulent transaction submissions).

3. Cancellation Policy
Users can cancel their VIP subscription at any time directly from the User Profile screen. Upon cancellation, your status returns to free and you may select any other plan of your choice.

4. How to Request a Refund
Contact our support team at support@pikashow.com with your registered email and Transaction ID. Approved refunds are processed back to the original payment source within 5–7 banking business days.
''';
}
