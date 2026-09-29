<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public static function user(Request $request): ?User
    {
        $token = $request->bearerToken();

        return $token ? User::where('api_token_hash', hash('sha256', $token))->where('is_active', true)->first() : null;
    }

    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'username' => Str::lower(trim((string) $request->input('username'))),
            'phone' => self::normalizePhone((string) $request->input('phone')),
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:40', 'regex:/^[\p{L}][\p{L}\p{N}_.-]{2,39}$/u', 'unique:users,username'],
            'phone' => ['required', 'regex:/^[0-9]{10,15}$/', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $user = User::create($data);

        return $this->issueToken($user->refresh(), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:80'], 'password' => ['required', 'string']]);
        $identifier = trim($data['identifier']);
        $user = preg_match('/^[+0-9 ()-]+$/', $identifier)
            ? User::where('phone', self::normalizePhone($identifier))->first()
            : User::where('username', Str::lower($identifier))->first();
        if (! $user || ! $user->is_active || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials or inactive account.'], 422);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->issueToken($user);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->accountData(self::user($request))]);
    }

    /**
     * Self-service profile edit. Deliberately narrower than updateUser: a
     * customer may change their own name, username, phone, and password,
     * never their role, discount, or active state. A password change must
     * confirm the current one — everything else does not, since it carries
     * no more risk than the rest of this endpoint already does under the
     * same bearer token.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = self::user($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($request->has('phone')) {
            $request->merge(['phone' => self::normalizePhone((string) $request->input('phone'))]);
        }
        if ($request->has('username')) {
            $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'username' => ['sometimes', 'required', 'string', 'max:40', 'regex:/^[\p{L}][\p{L}\p{N}_.-]{2,39}$/u', Rule::unique('users', 'username')->ignore($user->id)],
            'phone' => ['sometimes', 'required', 'regex:/^[0-9]{10,15}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'city' => ['sometimes', 'nullable', 'string', 'max:96'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'current_password' => ['required_with:password', 'string'],
            'password' => ['sometimes', 'confirmed', Password::min(8)],
        ]);

        if (isset($data['password']) && ! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->forceFill(collect($data)->only(['name', 'username', 'phone', 'city', 'address', 'password'])->all())->save();

        return response()->json(['data' => $this->accountData($user->refresh())]);
    }

    public function logout(Request $request): JsonResponse
    {
        self::user($request)?->forceFill(['api_token_hash' => null])->save();

        return response()->json(['message' => 'Signed out.']);
    }

    public function orders(Request $request): JsonResponse
    {
        $user = self::user($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $orders = Order::with('items')->where('user_id', $user->id)->latest()->limit(100)->get();

        return response()->json(['data' => $orders->map(fn (Order $order): array => array_merge((new OrderResource($order))->toArray($request), ['access_key' => $order->access_key]))]);
    }

    public function adminOrders(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => OrderResource::collection(Order::with('items')->latest()->limit(200)->get())]);
    }

    public function adminUsers(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => User::latest()->limit(200)->get(['id', 'name', 'username', 'phone', 'role', 'discount_percent', 'discount_expires_at', 'show_discount_countdown', 'is_active', 'last_login_at'])]);
    }

    public function createUser(Request $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->merge([
            'username' => Str::lower(trim((string) $request->input('username'))),
            'phone' => self::normalizePhone((string) $request->input('phone')),
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:40', 'regex:/^[\p{L}][\p{L}\p{N}_.-]{2,39}$/u', 'unique:users,username'],
            'phone' => ['required', 'regex:/^[0-9]{10,15}$/', 'unique:users,phone'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', 'in:customer,dealer'],
            'discount_percent' => ['required', 'integer', 'between:0,99'],
            'discount_expires_at' => ['nullable', 'date', 'after:now'],
            'show_discount_countdown' => ['sometimes', 'boolean'],
        ]);
        $user = User::create(collect($data)->only(['name', 'username', 'phone', 'password'])->all());
        $user->forceFill([
            'role' => $data['role'],
            'discount_percent' => $data['discount_percent'],
            'discount_expires_at' => $data['discount_expires_at'] ?? null,
            'show_discount_countdown' => $data['show_discount_countdown'] ?? false,
        ])->save();

        return response()->json(['data' => $user->refresh()->only(['id', 'name', 'username', 'phone', 'role', 'discount_percent', 'discount_expires_at', 'show_discount_countdown', 'is_active'])], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($request->has('username')) {
            $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        }
        if ($request->has('phone')) {
            $request->merge(['phone' => self::normalizePhone((string) $request->input('phone'))]);
        }
        $data = $request->validate([
            'username' => ['sometimes', 'required', 'string', 'max:40', 'regex:/^[\p{L}][\p{L}\p{N}_.-]{2,39}$/u', Rule::unique('users', 'username')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'regex:/^[0-9]{10,15}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'role' => ['sometimes', 'in:customer,dealer,admin'],
            'discount_percent' => ['sometimes', 'integer', 'between:0,99'],
            'discount_expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'show_discount_countdown' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($user->id === self::user($request)->id && (($data['role'] ?? 'admin') !== 'admin' || ($data['is_active'] ?? true) === false)) {
            return response()->json(['message' => 'You cannot remove your own admin access.'], 422);
        }
        $user->forceFill($data)->save();

        return response()->json(['data' => $user->only(['id', 'name', 'username', 'phone', 'role', 'discount_percent', 'discount_expires_at', 'show_discount_countdown', 'is_active'])]);
    }

    private function isAdmin(Request $request): bool
    {
        return self::user($request)?->role === 'admin';
    }

    private function issueToken(User $user, int $status = 200): JsonResponse
    {
        $token = Str::random(64);
        $user->forceFill(['api_token_hash' => hash('sha256', $token)])->save();

        return response()->json(['data' => $this->accountData($user), 'token' => $token], $status);
    }

    private function accountData(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return array_merge($user->only(['id', 'name', 'username', 'phone', 'role', 'discount_expires_at', 'show_discount_countdown', 'city', 'address', 'created_at']), [
            'discount_percent' => $user->activeDiscountPercent(),
        ]);
    }

    private static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00964')) {
            return substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '07')) {
            return '964'.substr($digits, 1);
        }

        return $digits;
    }
}
