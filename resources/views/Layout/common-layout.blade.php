@include('Layout.header')

<div class="wrapper" id="pageWrapper">
    {{-- sidebar --}}
    @include('Layout.sidebar')

    <div class="main">
        {{-- navigation bar --}}
        @include('Layout.navbar')

        {{-- dynamic content --}}
        <main class="content p-3">
            @yield('main-content')
        </main>

        {{-- footer --}}
        @include('Layout.footer')

    </div>




</div>

<div id="loaderOverlay" class="loader-overlay d-none">
    <div class="spinner-border text-primary" role="status" style="width:4rem; height:4rem;">
        <span class="visually-hidden">Loading...</span>
    </div>
</div>
@include('Layout.end-page')
