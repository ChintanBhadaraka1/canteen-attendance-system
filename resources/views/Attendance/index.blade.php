@extends('Layout.common-layout')

@section('main-content')
    <div class="container">
        <div class="card p-0">
            <div class="card-header d-flex justify-content-between align-items-center p-3">
                <h4 class="mb-0">Student Attendances</h4>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                        data-bs-target="#historyDownloadModal">
                        <i class="bi bi-list-check"></i> History
                    </button>
                    <select id="meal-select" class="form-select" aria-label="Select Meal">
                        <option value="" selected>Select Meal</option>
                        @if (count($meals) > 0)
                            @foreach ($meals as $meal)
                                <option value="{{ $meal->id }}">{{ $meal->name }}</option>
                            @endforeach
                        @endif
                    </select>
                </div>
            </div>
            <div class="card-body p-2">
                <table id="student-table" class="table table-striped" style="width:100%;">
                    <thead>
                        <tr>
                            <th class="text-start">Canteen Id</th>
                            <th class="text-start">Student Name</th>
                            <th class="text-start">Collage Name</th>
                            <th class="text-start">Action</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>

        <!-- Modal -->
        <div class="modal fade" id="historyDownloadModal" tabindex="-1" aria-labelledby="historyDownloadModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header text-center">
                        <h1 class="modal-title fs-5" id="historyDownloadModalLabel">Download Attendance Sheet</h1>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="historyDaterange">Select Date</label>
                            <input type="text" id="historyDaterange" class="form-control" name="dates[]">
                        </div>
                    </div>
                    <div class="modal-footer ">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"
                            id="historyModelClose">Close</button>
                        <button type="button" class="btn btn-primary" id="downloadBtnHistory">Download History</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(document).ready(function() {
            var table = $('#student-table').DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: '{{ route('student-attendance.list') }}',
                    type: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    data: function(d) {
                        // Pass the selected meal id or null if none selected
                        d.meal_id = $('#meal-select').val() || null;
                    }
                },
                columns: [{
                        data: 'canteen_id',
                        name: 'canteen_id'
                    },
                    {
                        data: 'name',
                        name: 'name'
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

            // Reload dataTable on meal selection change
            $('#meal-select').on('change', function() {
                startLoader();
                table.ajax.reload();
                stopLoader();
            });

            // $('#student-table').on('click', '.delete-user', function() {
            //     var userId = $(this).val();

            //     if (confirm('Are you sure you want to delete this user?')) {
            //         $.ajax({
            //             url: '/user/' + userId,
            //             type: 'DELETE',
            //             headers: {
            //                 'X-CSRF-TOKEN': '{{ csrf_token() }}'
            //             },
            //             success: function(response) {
            //                 alert('User deleted successfully.');
            //                 table.ajax.reload();
            //             },
            //             error: function(xhr) {
            //                 alert('Error deleting user.');
            //             }
            //         });
            //     }
            // });

            $('#historyDaterange').daterangepicker({
                ranges: {
                    'Today': [moment(), moment()],
                    'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                    'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                    'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1,
                        'month').endOf('month')],
                    'Past 3 Months': [moment().subtract(3, 'months').startOf('month'), moment().endOf(
                        'month')]
                },
                maxDate: moment(),
                startDate: moment().subtract(29, 'days'),
                endDate: moment(),
                locale: {
                    format: 'YYYY-MM-DD'
                }
            }, function(start, end, label) {
                console.log('New date range selected: ' + start.format('YYYY-MM-DD') + ' to ' + end.format(
                        'YYYY-MM-DD') +
                    ' (predefined range: ' + label + ')');
            });

            $('#downloadBtnHistory').on('click', function() {
                var dateRange = $('#historyDaterange').val();

                startLoader();

                $.ajax({
                    url: "{{ route('student-attendance.download') }}",
                    type: 'POST',
                    data: {
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
                            if (matches != null && matches[1]) filename = matches[1].replace(
                                /['"]/g, '');
                        }

                        var link = document.createElement('a');
                        var url = window.URL.createObjectURL(blob);
                        link.href = url;
                        link.download = filename || 'attendance.csv';
                        document.body.appendChild(link);
                        link.click();

                        $('#historyModelClose').click();
                        setTimeout(function() {
                            window.URL.revokeObjectURL(url);
                            document.body.removeChild(link);
                        }, 100);
                    },
                    error: function(xhr) {
                        alert('Error occurred while downloading: ' + xhr.responseText);
                        $('#historyModelClose').click();
                    },
                    complete: function() {
                        stopLoader();
                    }
                });
            });

        });

        function addTodayAttedance(studentId, mealId) {
            startLoader();

            $.ajax({
                url: '{{ route('student-attendance.store') }}', // route to your controller method
                method: 'POST',
                data: {
                    user_id: studentId,
                    meal_id: mealId,
                    is_direct: true,
                    include_extra: "no"
                },
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                success: function(response) {
                    showAlert('success', `Attendance added successfully!`);
                    $('#student-table').DataTable().ajax.reload(null, false);
                },
                error: function(xhr) {
                    showAlert('error', `Failed to add attendance: `+ xhr.responseText);

                },
                complete: function() {
                    stopLoader();
                }
            });
        }
    </script>
@endpush
