<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::post('/auth/register', function (Request $request) {
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'min:8', 'confirmed'],
    ]);

    $user = User::create([
        'name' => $validated['name'],
        'email' => $validated['email'],
        'role' => User::ROLE_STUDENT,
        'password' => $validated['password'],
    ]);

    $token = $user->createToken('spa')->plainTextToken;

    return response()->json([
        'user' => $user,
        'token' => $token,
    ], 201);
});

Route::post('/auth/login', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
    ]);

    $user = User::where('email', $validated['email'])->first();

    if (!$user || !Hash::check($validated['password'], $user->password)) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    $token = $user->createToken('spa')->plainTextToken;

    return response()->json([
        'user' => $user,
        'token' => $token,
    ]);
});

$canManageRooms = static function (User $user): bool {
    return in_array($user->role, [User::ROLE_ADMIN, User::ROLE_STAFF_MASTER_EXAMINER, 'faculty'], true);
};

Route::middleware('auth:sanctum')->group(function () use ($canManageRooms) {
    Route::get('/auth/me', function (Request $request) {
        return response()->json($request->user());
    });

    Route::post('/auth/logout', function (Request $request) {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    });

    Route::get('/rooms', function (Request $request) use ($canManageRooms) {
        $user = $request->user();

        if ($canManageRooms($user)) {
            $rooms = Room::query()
                ->where('created_by', $user->id)
                ->with('creator:id,name')
                ->withCount('members')
                ->latest()
                ->get();
        } else {
            $rooms = $user->rooms()
                ->with('creator:id,name')
                ->withCount('members')
                ->orderByDesc('rooms.created_at')
                ->get();
        }

        return response()->json(['rooms' => $rooms]);
    });

    Route::get('/rooms/{room}', function (Request $request, Room $room) use ($canManageRooms) {
        $user = $request->user();

        if ($canManageRooms($user)) {
            if ((int) $room->created_by !== (int) $user->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } else {
            $isMember = $user->rooms()->where('rooms.id', $room->id)->exists();
            if (!$isMember) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $members = $room->members()
            ->select('users.id', 'users.name', 'users.email', 'users.role')
            ->orderBy('users.name')
            ->get();

        $roomData = [
            'id' => $room->id,
            'name' => $room->name,
            'code' => $room->code,
            'created_by' => $room->created_by,
            'created_at' => $room->created_at,
            'updated_at' => $room->updated_at,
            'members_count' => $members->count(),
            'members' => $members,
            // Placeholder until exam assignment tables are connected.
            'assigned_exams' => [],
        ];

        return response()->json(['room' => $roomData]);
    });

    Route::patch('/rooms/{room}', function (Request $request, Room $room) use ($canManageRooms) {
        $user = $request->user();

        if (!$canManageRooms($user) || (int) $room->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $room->update([
            'name' => $validated['name'],
        ]);

        $room->load('creator:id,name')->loadCount('members');

        return response()->json([
            'message' => 'Room updated',
            'room' => $room,
        ]);
    });

    Route::delete('/rooms/{room}', function (Request $request, Room $room) use ($canManageRooms) {
        $user = $request->user();

        if (!$canManageRooms($user) || (int) $room->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $room->delete();

        return response()->json(['message' => 'Room deleted']);
    });

    Route::post('/rooms', function (Request $request) use ($canManageRooms) {
        $user = $request->user();
        if (!$canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        do {
            $code = Str::upper(Str::random(6));
        } while (Room::where('code', $code)->exists());

        $room = Room::create([
            'name' => $validated['name'],
            'code' => $code,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Room created',
            'room' => $room,
        ], 201);
    });

    Route::post('/rooms/join', function (Request $request) {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $room = Room::where('code', Str::upper(trim($validated['code'])))->first();
        if (!$room) {
            return response()->json(['message' => 'Room code not found'], 404);
        }

        $request->user()->rooms()->syncWithoutDetaching([$room->id]);

        return response()->json([
            'message' => 'Joined room successfully',
            'room' => $room,
        ]);
    });

    Route::delete('/rooms/{room}/leave', function (Request $request, Room $room) use ($canManageRooms) {
        $user = $request->user();

        if ($canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $detached = $user->rooms()->detach($room->id);
        if ($detached === 0) {
            return response()->json(['message' => 'You are not enrolled in this room'], 404);
        }

        return response()->json(['message' => 'Left room successfully']);
    });
});
