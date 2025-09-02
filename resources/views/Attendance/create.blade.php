@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier p-0">
        <div class="card p-0">
            <div class="card-header d-flex justify-content-between p-3">
                <h4>Add Attedance : {{ $user->full_name }} </h4>
                <a href="{{ route('student-attendance.index') }}" class="text-decoration-none btn btn-danger btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
            <div class="card-body p-2 ">

                @if (count($meal_data) > 0)

                    <form action="{{ route('student-attendance.store') }}" enctype="multipart/form-data" id="user-form"
                        method="POST">
                        @csrf

                        <input type="hidden" value="{{ $user->id }}" name="user_id">

                        <!-- Meals Selection -->
                        <div class="mb-4">
                            <h5>Select Meal</h5>
                            <div class="row row-cols-1 row-cols-md-3 g-3 px-4">
                                @foreach ($meal_data as $meal)
                                    <div class="col">
                                        <input type="radio" class="btn-check  @error('meal_id') is-invalid @enderror"
                                            name="meal_id" id="meal-{{ $meal->slug }}" value="{{ $meal->id }}"
                                            autocomplete="off" hidden>
                                        <label
                                            class="card h-100 btn btn-outline-primary d-flex flex-column justify-content-center align-items-center p-3"
                                            for="meal-{{ $meal->slug }}" style="cursor: pointer; user-select: none;">
                                            <div class="card-body text-center">
                                                <h5 class="card-title">{{ $meal->name }}</h5>
                                                <p class="card-text fs-5 fw-semibold text-primary">
                                                    ${{ number_format($meal->price, 2) }}</p>
                                            </div>
                                        </label>

                                    </div>
                                @endforeach


                            </div>
                        </div>


                        <!-- Extra Items Toggle Radio -->
                        @if (count($extra_items) > 0)
                            <div class="mb-4 px-4">
                                <h5>Include Extra Items?</h5>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="include_extra" id="includeExtraYes"
                                        value="yes">
                                    <label class="form-check-label" for="includeExtraYes">Yes</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="include_extra" id="includeExtraNo"
                                        value="no" checked>
                                    <label class="form-check-label" for="includeExtraNo">No</label>
                                </div>
                            </div>

                            <!-- Extra Items Inputs (Initially Hidden) -->

                            <div class="row g-3 px-4 mb-3" id="extraItems" style="display: none;">
                                @foreach ($extra_items as $extra)
                                    <div class="col-auto" style="max-width: 200px;">
                                        <label for="{{ $extra->slug }}">{{ $extra->name }}</label>
                                        <div class="input-group">
                                            <button class="btn btn-outline-secondary decrement-btn" type="button"
                                                data-target="{{ $extra->slug }}">&minus;</button>
                                            <input type="text" id="{{ $extra->slug }}"
                                                name="extra_items[{{ $extra->slug }}]" value="0"
                                                class="form-control text-center" autocomplete="off" style="max-width: 60px;"
                                                readonly>
                                            <button class="btn btn-outline-secondary increment-btn" type="button"
                                                data-target="{{ $extra->slug }}">&plus;</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <input type="hidden" name="include_extra" id="includeExtraNo" value="no">
                        @endif

                        <!-- Submit Button -->
                        <div class="row px-4 mb-2">
                            <div class="col-12 text-center">
                                <button type="submit" class="btn btn-primary px-5 py-1">Submit</button>
                            </div>
                        </div>
                    </form>
                @else
                <div class="row text-center justify-content-center mb-2">

                    <div class="col-6">
                        <input type="radio" class="btn-check " name="meal_id"
                             autocomplete="off" hidden>
                        <label
                            class="card h-100 btn btn-outline-primary d-flex flex-column justify-content-center align-items-center p-3"
                             style="cursor: pointer; user-select: none;">
                            <div class="card-body text-center">
                                <h5 class="card-title">Today Attendance Is Already Done</h5>
                                
                            </div>
                        </label>

                    </div>
                </div>
                @endif
            </div>
        </div>

    </div>
@endsection




@push('scripts')
    <script>
        $(document).ready(function() {
            // Show/hide extra items section based on radio button
            $('input[name="include_extra"]').change(function() {
                if ($(this).val() === 'yes') {
                    $('#extraItems').slideDown();
                } else {
                    $('#extraItems').slideUp();
                    // Reset all extras to zero when hiding
                    $('#extraItems input[type="text"]').val('0');
                }
            });

            // Decrement button logic
            $('.decrement-btn').on('click', function() {
                var inputId = $(this).data('target');
                var $input = $('#' + inputId);
                var val = parseInt($input.val()) || 0;
                if (val > 0) {
                    $input.val(val - 1);
                }
            });

            // Increment button logic
            $('.increment-btn').on('click', function() {
                var inputId = $(this).data('target');
                var $input = $('#' + inputId);
                var val = parseInt($input.val()) || 0;
                $input.val(val + 1);
            });
        });

        @error('meal_id')
            showAlert('error', '{{ $message }}');
        @enderror
    </script>
@endpush
