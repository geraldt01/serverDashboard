<aside class="panel admin-sidebar" aria-label="Administration">
    <h2>Administration</h2>
    <nav>
        <a class="{{ request()->routeIs('dashboard') ? 'is-active' : '' }}" href="{{ route('dashboard') }}"><span class="ic">&#9635;</span> Dashboard</a>
        <a class="{{ request()->routeIs('wordpress-sites.*') ? 'is-active' : '' }}" href="{{ route('wordpress-sites.index') }}"><span class="ic">&#9703;</span> WordPress Sites</a>
        <a class="{{ request()->routeIs('other-servers.*') ? 'is-active' : '' }}" href="{{ route('other-servers.index') }}"><span class="ic">&#9704;</span> Other Servers</a>
        <a class="{{ request()->routeIs('webpage-checks.*') ? 'is-active' : '' }}" href="{{ route('webpage-checks.index') }}"><span class="ic">&#9707;</span> Frontend Page Checks</a>
        <a class="{{ request()->routeIs('users.*') ? 'is-active' : '' }}" href="{{ route('users.index') }}"><span class="ic">&#9706;</span> User Management</a>
    </nav>
</aside>