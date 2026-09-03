@extends('layouts.app')

@section('content')
    <style>
        dialog.wp-user-dialog { border:none; border-radius:8px; padding:0; width:min(440px,90vw); }
        dialog.wp-user-dialog::backdrop { background:rgba(15,23,42,.45); }
        dialog.wp-user-dialog .modal-body { padding:20px; }
        dialog.wp-user-dialog .modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:14px; }
    </style>
    @include('partials.admin-sidebar')
    <header class="panel topbar">
        <div><h1>User Management</h1><p class="muted">Create and review dashboard users.</p></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="secondary">Sign out</button></form>
    </header>

    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <section class="panel content" style="margin-top:16px;"><h2>Create User</h2><form method="POST" action="{{ route('users.store') }}" class="form-grid">@csrf<div><label for="name">Name</label><input id="name" name="name" required></div><div><label for="new-email">Email</label><input id="new-email" name="email" type="email" required></div><div><label for="new-password">Password</label><input id="new-password" name="password" type="password" minlength="12" required></div><div><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="12" required></div><div><label for="role">Role</label><select id="role" name="role"><option value="viewer">Viewer</option><option value="admin">Admin</option></select></div><div style="align-self:end;"><button type="submit">Create user</button></div></form></section>
    <section class="panel content" style="margin-top:14px;"><h2>Users</h2><div class="scroll"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Actions</th></tr></thead><tbody>@foreach($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->role }}</td><td><button type="button" class="secondary" data-open-user="user-dialog-{{ $user->id }}">Edit</button><dialog id="user-dialog-{{ $user->id }}" class="wp-user-dialog"><form method="POST" action="{{ route('users.update', $user) }}" class="modal-body">@csrf @method('PUT')<h2>Edit user</h2><div><label for="name-{{ $user->id }}">Name</label><input id="name-{{ $user->id }}" name="name" value="{{ $user->name }}" required></div><div><label for="email-{{ $user->id }}">Email</label><input id="email-{{ $user->id }}" name="email" type="email" value="{{ $user->email }}" required></div><div><label for="role-{{ $user->id }}">Role</label><select id="role-{{ $user->id }}" name="role"><option value="viewer" @selected($user->role === 'viewer')>Viewer</option><option value="admin" @selected($user->role === 'admin')>Admin</option></select></div><div><label for="password-{{ $user->id }}">New password</label><input id="password-{{ $user->id }}" name="password" type="password" minlength="12" placeholder="Leave blank to keep current password"></div><div><label for="password_confirmation-{{ $user->id }}">Confirm new password</label><input id="password_confirmation-{{ $user->id }}" name="password_confirmation" type="password" minlength="12"></div><div class="modal-actions"><button type="button" class="secondary" data-close-user>Cancel</button><button type="submit">Save changes</button></div></form></dialog></td></tr>@endforeach</tbody></table></div></section>
    <script>
        document.querySelectorAll('[data-open-user]').forEach((btn) => {
            btn.addEventListener('click', () => document.getElementById(btn.dataset.openUser)?.showModal());
        });
        document.querySelectorAll('[data-close-user]').forEach((btn) => {
            btn.addEventListener('click', () => btn.closest('dialog')?.close());
        });
    </script>
@endsection