@extends('layouts.app')

@section('content')
    @include('partials.admin-sidebar')
    <header class="panel topbar">
        <div><h1>User Management</h1><p class="muted">Create and review dashboard users.</p></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="secondary">Sign out</button></form>
    </header>

    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <section class="panel content" style="margin-top:16px;"><h2>Create User</h2><form method="POST" action="{{ route('users.store') }}" class="form-grid">@csrf<div><label for="name">Name</label><input id="name" name="name" required></div><div><label for="new-email">Email</label><input id="new-email" name="email" type="email" required></div><div><label for="new-password">Password</label><input id="new-password" name="password" type="password" minlength="12" required></div><div><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="12" required></div><div><label for="role">Role</label><select id="role" name="role"><option value="viewer">Viewer</option><option value="admin">Admin</option></select></div><div style="align-self:end;"><button type="submit">Create user</button></div></form></section>
    <section class="panel content" style="margin-top:14px;"><h2>Users</h2><div class="scroll"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead><tbody>@foreach($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->role }}</td></tr>@endforeach</tbody></table></div></section>
@endsection