@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier ">
        <div class="card p-0">
            <div class="card-header d-flex justify-content-between p-3">
                <h4>Student Bills</h4>
                {{-- <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#historyDownloadModal">
                  <i class="bi bi-list-check"></i>  History
                </button> --}}
                {{-- <a href="{{ route('user.create') }}" class="text-decoration-none btn btn-primary btn-sm">
                    
                    History
                </a> --}}
            </div>
            <div class="card-body p-2">
                <table id="bill-table" class="table table-striped">
                    <thead>
                        <th class="text-start">Sr No</th>
                        <th class="text-start">Student Name</th>
                        <th class="text-start">Cantten Id</th>
                        <th class="text-start">Amount </th>

                        <th class="text-start">Action</th>
                    </thead>
                </table>
            </div>
        </div>

        <!-- Button trigger modal -->
    </div>
@endsection
@push('scripts')
    <script>
        $(document).ready(function() {
            $('#bill-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('bill.list') }}',
                    type: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                },
                columns: [{
                        data: 'sr_no',
                        name: 'sr_no'
                    },
                    {
                        data: 'name',
                        name: 'name'
                    },
                    {
                        data: 'canteen_id',
                        name: 'canteen_id'
                    },
                    {
                        data: 'amount',
                        name: 'amount'
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

            $('#bill-table').on('click', '.delete-user', function() {
                var userId = $(this).val();

                if (confirm('Are you sure you want to delete this user?')) {
                    $.ajax({
                        url: '/user/' + userId, // Adjust URL if needed
                        type: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            alert('User deleted successfully.');
                            $('#bill-table').DataTable().ajax.reload();
                        },
                        error: function(xhr) {
                            alert('Error deleting user.');
                        }
                    });
                }
            });

        });
        var options = {};


    </script>
@endpush
