class AppUser {
  final int id;
  final String name;
  final String email;
  final String? avatar;
  final String? token;
  final String status;
  final DateTime? createdAt;

  const AppUser({
    required this.id,
    required this.name,
    required this.email,
    this.avatar,
    this.token,
    this.status = 'active',
    this.createdAt,
  });

  factory AppUser.fromJson(Map<String, dynamic> json) {
    DateTime? parsedDate;
    if (json['created_at'] != null) {
      try {
        parsedDate = DateTime.parse(json['created_at'].toString());
      } catch (_) {}
    }

    int parsedId = 0;
    if (json['id'] is num) {
      parsedId = (json['id'] as num).toInt();
    } else if (json['id'] is String) {
      parsedId = int.tryParse(json['id'] as String) ?? 0;
    }

    return AppUser(
      id: parsedId,
      name: json['name'] as String? ?? 'User',
      email: json['email'] as String? ?? '',
      avatar: json['avatar'] as String?,
      token: json['token'] as String?,
      status: json['status'] as String? ?? 'active',
      createdAt: parsedDate,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'email': email,
      'avatar': avatar,
      'token': token,
      'status': status,
      'created_at': createdAt?.toIso8601String(),
    };
  }

  AppUser copyWith({
    int? id,
    String? name,
    String? email,
    String? avatar,
    String? token,
    String? status,
    DateTime? createdAt,
  }) {
    return AppUser(
      id: id ?? this.id,
      name: name ?? this.name,
      email: email ?? this.email,
      avatar: avatar ?? this.avatar,
      token: token ?? this.token,
      status: status ?? this.status,
      createdAt: createdAt ?? this.createdAt,
    );
  }
}
