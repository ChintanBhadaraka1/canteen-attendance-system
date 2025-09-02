@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier ">
        <div class="row ">
            <div class="col-4">
                <div class="card p-0">
                    <div class="card-header d-flex justify-content-between p-3">
                        <h4>Name : {{ $user->name }}</h4>
                        <a href="{{ route('user.index') }}" class="text-decoration-none btn btn-danger btn-sm">
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
                            <p>
                                Canteen Id : {{ $user->canteen_id }}
                            </p>
                            <p>
                                Middile Name : {{ $user->middle_name }}
                            </p>
                            <p>
                                Last Name : {{ $user->last_name }}
                            </p>
                            <p>
                                Email : {{ $user->email }}
                            </p>
                            <p>
                                Collage Name : {{ $user->collage_name }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-7">
                <div class="card p-1">
                    <div class="card-header d-flex justify-content-between p-2">
                        <h4>Student Data</h4>
                    </div>
                    <div class="card-body p-2">
                        <p>Current Month Details</p>
                        <div class="row">
                            @foreach ($month_attendance_data as $attendance)
                                <div class="col-4">
                                    <div class="card">
                                        <div class="card-body">
                                            <h5 class="card-title">This Month {{ $attendance['meal_name'] }}</h5>
                                            <p class="card-text">{{ $attendance['total'] }}</p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <p>Payment Details</p>
                        <div class="row">
                            @foreach ($payment_lable as $key => $paymentLable)
                                <div class="col-4">
                                    <div class="card">
                                        <div class="card-body">
                                            <h5 class="card-title">{{ $paymentLable }}</h5>
                                            <p class="card-text">{{ $payment_data[$key] }}</p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>




    </div>
@endsection
