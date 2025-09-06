@php
    $totalAmount = $paymentData->amount + $paymentData->pending_amount - $paymentData->advance_amount;
@endphp
@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier ">
        <div class="row ">

            <div class="col-4">
                <div class="card p-0">
                    <div class="card-header d-flex justify-content-between p-3">
                        <h4>Name : {{ $paymentData->user->full_name }}</h4>
                    </div>
                    <div class="card-body p-2 text-center">
                        @if ($paymentData->user->profile_pic)
                            <img class="img-fluid rounded-circle"
                                src="{{ asset('image/profile') . '/' . $paymentData->user->profile_pic }}" width="200px"
                                height="150px" alt="profile">
                        @else
                            <img class="img-fluid rounded-circle" src="{{ asset('image/profile/defualt.png') }}"
                                width="200px" height="150px" alt="profile">
                        @endif
                        <div class="text-start px-2">
                            <p class="p-0">
                                Canteen Id : {{ $paymentData->user->canteen_id }}
                            </p>
                            {{-- <p class="p-0">
                                Middile Name : {{ $paymentData->user->middle_name }}
                            </p> --}}
                            {{-- <p class="p-0">
                                Last Name : {{ $paymentData->user->last_name }}
                            </p> --}}
                            <p class="p-0">
                                Email : {{ $paymentData->user->email }}
                            </p>
                            <p class="p-0">
                                Collage Name : {{ $paymentData->user->collage_name }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-8">
                <div class="row">

                    <div class="col-12">
                        <div class="card p-0">
                            <div class="card-header d-flex justify-content-between align-items-center p-3">
                                <h5 class="mb-0">Add Payment</h5>
                            </div>
                            <div class="card-body p-2">

                                <div class="row">
                                    <div class="col-10">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="card-title">Pending Total Amount Of Students</h5>
                                                <p class="card-text">
                                                <p class="p-0">
                                                    @if ($totalAmount > 0)
                                                        <span>Total amount = (all meal amount + pending amount) - advance
                                                            amount
                                                        </span>
                                                        {{ $totalAmount }}
                                                    @else
                                                        There is not any pending amount there
                                                    @endif
                                                </p>
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                                {{-- If some amount is there then only add payment  --}}
                                @if ($totalAmount > 0)
                                    <form action="{{ route('bill.update', $paymentData->id) }}" method="post" id="payment-form">
                                        <div class="row px-3">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="student_id" value="{{ $paymentData->student_id }}">
                                            <div class="col-6 mb-3">
                                                <label for="amount">Amount</label>
                                                <input type="number" name="amount" class="form-control">
                                            </div>
                                            <div class="col-6 mb-3">
                                                <label for="type">Payment Method</label>
                                                <select class="form-select" name="type" id="type">
                                                    <option selected>Select method</option>
                                                    <option value="cash">CASH</option>
                                                    <option value="upi">UPI </option>
                                                </select>
                                            </div>

                                            <div class="col-6 mb-3">
                                                <label for="month">Payment Month</label>
                                                <select class="form-select" name="month" id="month">
                                                    <option selected>Select Month</option>
                                                    <option value="January">January</option>
                                                    <option value="February">February</option>
                                                    <option value="March">March</option>
                                                    <option value="April">April</option>
                                                    <option value="May">May</option>
                                                    <option value="June">June</option>
                                                    <option value="July">July</option>
                                                    <option value="August">August</option>
                                                    <option value="September">September</option>
                                                    <option value="October">October</option>
                                                    <option value="November">November</option>
                                                    <option value="December">December</option>
                                                </select>

                                            </div>

                                            <div class="col-12 text-center mb-3">
                                                <button type="submit" class="btn btn-primary">Add Payment</button>
                                            </div>
                                        </div>
                                    </form>
                                @endif

                            </div>
                        </div>

                    </div>
                    @if (count($reciptsData) > 0)
                        <div class="col-12">
                            <div class="card p-0">
                                <div class="card-header d-flex justify-content-between align-items-center p-3">
                                    <h5 class="mb-0">Download Recipt</h5>
                                </div>
                                <div class="card-body p-2">
                                    <div class="row align-items-center">

                                        <div class="col-6 mb-3">
                                            <label for="recipt_month">Select Month</label>
                                            <select class="form-select" name="recipt_month" id="recipt_month">
                                                @foreach ($reciptsData as $recipt)
                                                    <option value="{{ $recipt->id }}">
                                                        {{ $recipt->month_name }}-{{ date('Y', strtotime($recipt->payment_date)) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-6 text-center">
                                            <button class="btn btn-md btn-primary"
                                                onclick="downloadRecipt()">Download</button>
                                        </div>

                                    </div>
                                </div>
                            </div>

                        </div>
                    @endif
                </div>
            </div>

        </div>

    </div>
@endsection


@push('scripts')
    {!! $validator->selector('#payment-form') !!} 

    <script>
        function downloadRecipt() {
            var selectedId = $('#recipt_month').val();

            $.ajax({
                url: '/bill/download', // Your route to handle the request
                type: 'POST',
                data: {
                    payment_id: selectedId
                },
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}' // Laravel CSRF token
                },
                xhrFields: {
                    responseType: 'blob' // Expecting a Blob (PDF)
                },
                success: function(blob) {
                    var link = document.createElement('a');
                    var url = window.URL.createObjectURL(blob);
                    link.href = url;
                    link.download = 'receipt.pdf';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    window.URL.revokeObjectURL(url);
                },
                error: function(xhr, status, error) {
                    alert('Failed to download receipt. Please try again.');
                    console.error(error);
                }
            });
        }
    </script>
@endpush
{{-- reciptsData --}}
