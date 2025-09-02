@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier ">
        <div class="card p-0">
            <div class="card-header d-flex justify-content-between p-3">
                <h4>Menus</h4>
                <a href="{{ route('menus.create') }}" class="text-decoration-none btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i>
                    Add
                </a>
            </div>
            <div class="card-body p-2">
                <table id="menus-table" class="table table-striped">
                    <thead>
                        <th class="text-start">Sr no</th>
                        <th class="text-start">Name</th>
                        <th class="text-start">Is extra</th>
                        <th class="text-start">Action</th>
                    </thead>
                </table>
            </div>
        </div>

    </div>
@endsection
@push('scripts')
    <script>
        $(document).ready(function() {
            $('#menus-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('menus.list') }}',
                    type: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                },
                columns: [{
                        data: 'id',
                        name: 'id'
                    },
                    {
                        data: 'name',
                        name: 'name'
                    },
                    {
                        data: 'is_extra',
                        name: 'is_extra'
                    },
                    {
                        data: 'action',
                        name: 'action',
                        orderable: false,
                        searchable: false
                    }
                ],
                order: [
                    [0, 'desc']
                ]
            });

            // $('#menus-table').on('click', '.delete-menu', function() {
            //     var userId = $(this).val();

            //     if (confirm('Are you sure you want to delete this Menu?')) {
            //         $.ajax({
            //             url: '/menus/' + userId, // Adjust URL if needed
            //             type: 'DELETE',
            //             headers: {
            //                 'X-CSRF-TOKEN': '{{ csrf_token() }}'
            //             },
            //             success: function(response) {
            //                 alert('Menu deleted successfully.');
            //                 $('#menus-table').DataTable().ajax.reload();
            //             },
            //             error: function(xhr) {
            //                 alert('Error deleting Menu.');
            //             }
            //         });
            //     }
            // });

            $('#menus-table').on('click', '.delete-menu', function() {
                var menuId = $(this).val();
                var moduleName = 'Menu'; // or dynamically fetch from data attribute

                confirmDelete(moduleName, function() {
                    $.ajax({
                        url: '/menus/' + menuId,
                        type: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            showAlert('success', `${moduleName} deleted!`);
                            $('#menus-table').DataTable().ajax.reload();
                        },
                        error: function() {
                            showAlert('error', `Failed to delete ${moduleName}. Please try again.`);
                        }
                    });
                });
            });


        });
    </script>
@endpush
