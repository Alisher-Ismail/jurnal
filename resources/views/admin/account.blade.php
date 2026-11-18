@extends('layouts.admin')

@section('title', __('Account'))

@section('content')
<div class="panel-grid">
    <div>
        <form class="card card-pad panel" method="post" action="{{ route('admin.account.update') }}">
            @csrf @method('PUT')
            <h2>{{ __('My account') }}</h2>
            <div class="form-grid">
                <div class="field"><label for="name">{{ __('Name') }}</label><input class="input" id="name" name="name" value="{{ old('name', $user->name) }}" required maxlength="255"></div>
                <div class="field"><label for="email">{{ __('Login (e-mail)') }}</label><input class="input" id="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255"></div>
                <div class="field"><label for="password">{{ __('New password') }} <span class="opt">{{ __('optional') }}</span></label><input class="input" type="password" id="password" name="password" autocomplete="new-password" minlength="8"></div>
                <div class="field"><label for="password_confirmation">{{ __('Repeat new password') }}</label><input class="input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password"></div>
                <div class="field full"><label for="current_password">{{ __('Current password') }}</label><input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password" required></div>
            </div>
            <button class="btn btn-primary" style="margin-top:18px"><x-icon name="check"/> {{ __('Save') }}</button>
        </form>

        @if ($user->isAdmin())
        <div class="card panel">
            <div style="padding:18px 20px 6px"><h2 style="margin:0">{{ __('Accounts') }}</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Login (e-mail)') }}</th><th>{{ __('Role') }}</th><th>{{ __('New password') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($users as $editor)
                        <tr>
                            <td>{{ $editor->name }}@if ($editor->is($user)) <span class="tag">{{ __('you') }}</span>@endif</td>
                            <td class="small">{{ $editor->email }}</td>
                            <td class="small">{{ $editor->isAdmin() ? __('Administrator') : __('Editor (review)') }}</td>
                            <td>
                                <form method="post" action="{{ route('admin.users.password', $editor) }}" style="display:flex;gap:6px">
                                    @csrf @method('PUT')
                                    <input class="input" type="password" name="password" minlength="8" required autocomplete="new-password" style="height:36px;padding:0 10px;min-width:140px" placeholder="••••••••">
                                    <button class="icon-btn" title="{{ __('Save') }}"><x-icon name="check"/></button>
                                </form>
                            </td>
                            <td>
                                @unless ($editor->is($user))
                                    <form class="inline" method="post" action="{{ route('admin.users.destroy', $editor) }}" data-confirm="{{ __('Delete this editor?') }}">
                                        @csrf @method('DELETE')
                                        <button class="icon-btn danger" title="{{ __('Delete') }}"><x-icon name="trash"/></button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>

    @if ($user->isAdmin())
    <form class="card card-pad panel" method="post" action="{{ route('admin.users.store') }}">
        @csrf
        <h2>{{ __('Add account') }}</h2>
        <div style="display:grid;gap:10px">
            <div class="field"><label>{{ __('Name') }}</label><input class="input" name="name" required maxlength="255"></div>
            <div class="field"><label>{{ __('Login (e-mail)') }}</label><input class="input" name="email" required maxlength="255"></div>
            <div class="field"><label>{{ __('Password') }}</label><input class="input" type="password" name="password" minlength="8" required autocomplete="new-password"></div>
            <div class="field"><label>{{ __('Role') }}</label>
                <select class="select" name="type">
                    <option value="editor">{{ __('Editor (review)') }} — {{ __('manages the journal: submissions, issues, articles, authors and pages; cannot confirm payments, manage accounts or open System') }}</option>
                    <option value="admin">{{ __('Administrator') }} — {{ __('everything') }}</option>
                </select>
            </div>
            <button class="btn btn-primary"><x-icon name="plus"/> {{ __('Add') }}</button>
        </div>
    </form>
    @endif
</div>
@endsection
