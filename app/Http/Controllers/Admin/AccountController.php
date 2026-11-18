<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/** Change your own name, login and password, and manage other editors. */
class AccountController extends Controller
{
    public function edit(Request $request)
    {
        return view('admin.account', [
            'user' => $request->user(),
            'users' => User::orderBy('id')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => 'required|current_password',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $user->name = $data['name'];
        $user->email = $data['email'];
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return back()->with('success', __('Saved.'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'type' => ['nullable', Rule::in([User::ADMIN, User::EDITOR])],
        ]);

        User::forceCreate([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'type' => $data['type'] ?? User::EDITOR,
        ]);

        return back()->with('success', __('Saved.'));
    }

    public function password(Request $request, User $user)
    {
        $data = $request->validate(['password' => 'required|string|min:8']);
        $user->password = Hash::make($data['password']);
        $user->save();

        return back()->with('success', __('Password changed for :name.', ['name' => $user->name]));
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            return back()->with('error', __('You cannot delete your own account.'));
        }
        $user->delete();

        return back()->with('success', __('Deleted.'));
    }
}
