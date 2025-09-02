@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier ">
        <div class="card p-0">
            <div class="card-header d-flex justify-content-between p-3">
                <h4>Students</h4>
                <a href="{{ route('user.create') }}" class="text-decoration-none btn btn-primary btn-sm">
                    <i class="bi bi-person-fill-add"></i>
                    Add
                </a>
            </div>
            <div class="card-body p-2">
                <table id="users-table" class="table table-striped">
                    <thead>
                        <th class="text-start">Id</th>
                        <th class="text-start">Name</th>
                        <th class="text-start">Email</th>
                        <th class="text-start">Canteen ID</th>
                        <th class="text-start">Collage</th>
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
            $('#users-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('user.list') }}',
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
                        data: 'email',
                        name: 'email'
                    },
                    {
                        data: 'canteen_id',
                        name: 'canteen_id'
                    },
                    {
                        data: 'collage_name',
                        name: 'collage_name'
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

            // $('#users-table').on('click', '.delete-user', function() {
            //     var userId = $(this).val();

            //     if (confirm('Are you sure you want to delete this user?')) {
            //         $.ajax({
            //             url: '/user/' + userId, // Adjust URL if needed
            //             type: 'DELETE',
            //             headers: {
            //                 'X-CSRF-TOKEN': '{{ csrf_token() }}'
            //             },
            //             success: function(response) {
            //                 alert('User deleted successfully.');
            //                 $('#users-table').DataTable().ajax.reload();
            //             },
            //             error: function(xhr) {
            //                 alert('Error deleting user.');
            //             }
            //         });
            //     }
            // });


            $('#users-table').on('click', '.delete-user', function() {
                var userId = $(this).val();
                var moduleName = 'User'; // or dynamically fetch from data attribute

                confirmDelete(moduleName, function() {
                    startLoader();

                    $.ajax({
                        url: '/user/' + userId,
                        type: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            showAlert('success', `${moduleName} deleted!`);
                            $('#users-table').DataTable().ajax.reload();
                        },
                        error: function() {
                            showAlert('error',
                                `Failed to delete ${moduleName}. Please try again.`);
                        },
                        complete: function() {
                            stopLoader();
                        }
                    });
                });
            });


        });
    </script>
@endpush
