<script src="{{ asset('assets/js/app.js') }}"></script>
<script src="{{ asset('assets/js/jquery/jquery.js') }}"></script>
<script src="{{ asset('assets/js/Datatables/datatables.js') }}"></script>
<script src="{{ asset('assets/js/bootstrap/bootstrap.min.js') }}"></script>
<script src="{{ asset('assets/js/bootstrap/bootstrap.bundle.min.js') }}"></script>
{{-- <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script> --}}
<script type="text/javascript" src="{{ asset('vendor/jsvalidation/js/jsvalidation.js')}}"></script>

<script src="{{ asset('assets/sweetalert2/js/sweetalert2.min.js') }}"></script>

<script src="{{ asset('assets/daterangepicker/moment.min.js') }}"></script>
<script src="{{ asset('assets/daterangepicker/daterangepicker.js') }}"></script>
{{-- alert common function --}}
<script>
    const wrapper = document.getElementById('pageWrapper');
    const loaderDiv = document.getElementById('loaderOverlay');



    function startLoader() {
        wrapper.classList.add('loading');
        loaderDiv.classList.remove('d-none');
    }

    function stopLoader() {
        loaderDiv.classList.add('d-none');
        wrapper.classList.remove('loading');
    }

    function confirmDelete(moduleName, onConfirm) {
        Swal.fire({
            title: 'Are you sure?',
            text: `Do you really want to delete this ${moduleName}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: `Yes, delete ${moduleName}!`,
            reverseButtons: true,
            focusCancel: true
        }).then((result) => {
            if (result.isConfirmed) {
                onConfirm();
            }
        });
    }

    function showAlert(type, message) {
        const icon = (type === 'success') ? 'success' :
            (type === 'error') ? 'error' : 'info';

        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: icon,
            title: message,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            background: (type === 'success') ? '#d4edda' : '#f8d7da',
            color: (type === 'success') ? '#155724' : '#721c24',
            customClass: {
                popup: 'colored-toast'
            },
        });
    }

    @if (session('success'))
        showAlert('success', "{{ session('success') }}");
    @elseif (session('error'))
        showAlert('error', "{{ session('error') }}");
    @endif
</script>


@stack('scripts')

</body>

</html>
