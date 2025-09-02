@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier ">
        <div class="row ">
            <div class="col-12 col-md-4 col-lg-4">
                <div class="card p-0">
                    <div class="card-header d-flex justify-content-between p-3">
                        <h4>Name : {{ $user->name }}</h4>
                        <a href="{{ route('student-attendance.index') }}" class="text-decoration-none btn btn-danger btn-sm">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>

                    </div>
                    <div class="card-body p-2 text-center">
                        @if ($user->profile_pic != null)
                            <img class="img-fluid rounded-circle"
                                src="{{ asset('image/profile') . '/' . $user->profile_pic }}" width="200px" height="150px"
                                alt="profile">
                        @else
                            <img class="img-fluid rounded-circle" src="{{ asset('image/profile/defualt.png') }}"
                                width="200px" height="150px" alt="profile">
                        @endif

                        <div class="text-start px-2">
                            <p class="p-0">
                                Canteen Id : {{ $user->canteen_id }}
                            </p>
                            <p class="p-0">
                                Middile Name : {{ $user->middle_name }}
                            </p>
                            <p class="p-0">
                                Last Name : {{ $user->last_name }}
                            </p>
                            <p class="p-0">
                                Email : {{ $user->email }}
                            </p>
                            <p class="p-0">
                                Collage Name : {{ $user->collage_name }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-8 col-lg-8">
                <div class="card p-0">
                    <div class="card-header d-flex justify-content-between align-items-center px-3 pt-1 pb-0">
                        <h5 class="mb-0">Student Attendance</h5>
                        <div class="mb-3 d-flex justify-content-between align-items-center">
                            <div>
                                <label for="historyDaterange">Select Date:</label>
                                <input type="text" id="historyDaterange" class="form-control" name="dates[]">
                            </div>
                            <button type="button" class="btn btn-sm btn-primary mx-2" id="downloadBtn">
                                <i class="bi bi-file-earmark-arrow-down"></i>
                            </button>
                        </div>

                    </div>
                    <div class="card-body p-2 text-center">
                        <table id="specific-student-attedance-table" class="table table-striped responsive-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th scope="col">Sr</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Meal Name</th>
                                    <th scope="col">Amount</th>
                                    <th scope="col">Extra Amount</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>

            </div>

        </div>

    </div>
@endsection

@push('scripts')
    <script>
        $('#historyDaterange').daterangepicker({
            ranges: {
                'Today': [moment(), moment()],
                'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf(
                    'month')],
                'Past 3 Months': [moment().subtract(3, 'months').startOf('month'), moment().endOf('month')]
            },

            // Set max date as today (disable future dates)
            maxDate: moment(),

            // Optional: set start and end dates by default
            startDate: moment().subtract(29, 'days'),
            endDate: moment(),

            // Other options as needed
            locale: {
                format: 'YYYY-MM-DD'
            }

        }, function(start, end, label) {
            console.log('New date range selected: ' + start.format('YYYY-MM-DD') + ' to ' + end.format(
                'YYYY-MM-DD') + ' (predefined range: ' + label + ')');
        });

        var userId = {{ $user->id ?? 'null' }};
        var dateRange = $('#historyDaterange').val();


        var table = $('#specific-student-attedance-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route('student-attendance.specific-user') }}',
                type: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                data: function(d) {
                   d.user_id= userId,
                   d.dates= dateRange
                }
            },
            columns: [{
                    data: 'id',
                    name: 'id'
                },
                {
                    data: 'date',
                    name: 'date'
                },
                {
                    data: 'meal_name',
                    name: 'meal_name'
                },
                {
                    data: 'amount',
                    name: 'amount'
                },
                {
                    data: 'extra_amount',
                    name: 'extra_amount'
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

        $('#downloadBtn').on('click', function() {
            var dateRange = $('#historyDaterange').val();

            $.ajax({
                url: "{{ route('student-attendance.user-download') }}",
                type: 'POST',
                data: {
                    user_id: userId,
                    dates: dateRange
                },
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                xhrFields: {
                    responseType: 'blob' // Important for file download
                },
                success: function(blob, status, xhr) {
                    var filename = "";
                    var disposition = xhr.getResponseHeader('Content-Disposition');
                    if (disposition && disposition.indexOf('attachment') !== -1) {
                        var filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                        var matches = filenameRegex.exec(disposition);
                        if (matches != null && matches[1]) filename = matches[1].replace(/['"]/g, '');
                    }

                    var link = document.createElement('a');
                    var url = window.URL.createObjectURL(blob);
                    link.href = url;
                    link.download = filename || 'attendance.csv';
                    document.body.appendChild(link);
                    link.click();

                    setTimeout(function() {
                        window.URL.revokeObjectURL(url);
                        document.body.removeChild(link);
                    }, 100);
                },
                error: function(xhr) {
                    alert('Error occurred while downloading: ' + xhr.responseText);
                }
            });
        });

        $('#historyDaterange').on('change', function() {
                startLoader();
                dateRange = $('#historyDaterange').val();
                table.ajax.reload();
                stopLoader();
        });

        $('.delete-attendance').on('click', function() {
            var attedanceId = $(this).data('id');
            var moduleName = "Attedance";

            confirmDelete(moduleName, function() {
                startLoader();

                $.ajax({
                    url: '/student-attendance/delete',
                    type: 'POST',
                    data: {
                        'id': attedanceId
                    },
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    success: function(response) {
                        showAlert('success', `${moduleName} deleted!`);
                        // Optionally remove the card from UI without reloading page
                        // $(this).closest('.col').remove(); // won't work here due to 'this' context
                        location.reload(); // Or reload to reflect changes as a simple approach
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
    </script>
@endpush
