<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return User::orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'employee_no' => 'required|string|unique:users,employee_no',
            'email' => 'nullable|email',
            'role' => 'required|in:owner,cashier',
            'password' => 'required|string|min:4',
        ]);
        $data['password'] = Hash::make($data['password']);
        $data['is_active'] = true;

        $user = User::create($data);
        AuditLog::create([
            'user_name' => $request->user()->name,
            'action' => 'user_change',
            'target' => "{$user->employee_no} ({$user->name})",
            'details' => "Created {$user->role} account.",
        ]);

        return response()->json($user, 201);
    }

    public function toggleActive(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot deactivate your own account.'], 422);
        }

        $user->update(['is_active' => !$user->is_active]);
        $user->tokens()->delete();

        AuditLog::create([
            'user_name' => $request->user()->name,
            'action' => 'user_change',
            'target' => "{$user->employee_no} ({$user->name})",
            'details' => $user->is_active ? 'Activated account.' : 'Deactivated account.',
        ]);

        return response()->json($user);
    }

    public function resetPassword(Request $request, int $id)
    {
        $request->validate(['password' => 'required|string|min:4']);
        $user = User::findOrFail($id);
        $user->update(['password' => Hash::make($request->password)]);

        AuditLog::create([
            'user_name' => $request->user()->name,
            'action' => 'user_change',
            'target' => "{$user->employee_no} ({$user->name})",
            'details' => 'Reset user password.',
        ]);

        return response()->json(['message' => 'Password reset successfully.']);
    }
}