<nav id="sidebar" class="sidebar js-sidebar">
    <div class="sidebar-content js-simplebar">
        <a class="sidebar-brand" href="{{ route('dashboard') }}">
            <span class="align-middle">CANTEEN ATTENDANCE </span>
        </a>

        <ul class="sidebar-nav">

            <li @class(['sidebar-item', 'active' => request()->is('dashboard')])>
                <a class="sidebar-link" href="{{ route('dashboard') }}">
                    <i class="align-middle" data-feather="slack"></i>
                    <span class="align-middle">Dashboard</span>
                </a>
            </li>

            <li @class(['sidebar-item', 'active' => request()->is('user*')])>
                <a class="sidebar-link" href="{{ route('user.index') }}">
                    <i class="bi bi-people-fill"></i>
                    <span class="align-middle">Students</span>
                </a>
            </li>

            <li @class(['sidebar-item', 'active' => request()->is('menus*')])>
                <a class="sidebar-link" href="{{ route('menus.index') }}">
                    <i class="bi bi-fork-knife"></i>
                    <span class="align-middle">Menu</span>
                </a>
            </li>

            <li @class(['sidebar-item', 'active' => request()->is('meal-price*')])>
                <a class="sidebar-link" href="{{ route('meal-price.index') }}">
                    <i class="bi bi-cash-stack"></i>
                    <span class="align-middle">Meal Price</span>
                </a>
            </li>

            
            <li @class(['sidebar-item', 'active' => request()->is('student-attendance*')])>
                <a class="sidebar-link" href="{{ route('student-attendance.index') }}">
                    <i class="bi bi-card-checklist"></i>
                    <span class="align-middle">Attendance</span>
                </a>
            </li>
            
            <li @class(['sidebar-item', 'active' => request()->is('bill*')])>
                <a class="sidebar-link" href="{{ route('bill.index') }}">
                    <i class="bi bi-receipt"></i>
                    <span class="align-middle">Bills</span>
                </a>
            </li>
            
            {{-- <li class="sidebar-header">Tools & Components</li> --}}

        </ul>

    </div>
</nav>
