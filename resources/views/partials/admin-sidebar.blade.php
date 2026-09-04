<aside class="panel admin-sidebar" aria-label="Administration">
    <h2>Administration</h2>
    <nav>
        <a class="{{ request()->routeIs('dashboard') ? 'is-active' : '' }}" href="{{ route('dashboard') }}">Dashboard</a>
        <a class="{{ request()->routeIs('wordpress-sites.*') ? 'is-active' : '' }}" href="{{ route('wordpress-sites.index') }}">WordPress Sites</a>
        <a class="{{ request()->routeIs('other-servers.*') ? 'is-active' : '' }}" href="{{ route('other-servers.index') }}">Other Servers</a>
        <a class="{{ request()->routeIs('users.*') ? 'is-active' : '' }}" href="{{ route('users.index') }}">User Management</a>
    </nav>
</aside>