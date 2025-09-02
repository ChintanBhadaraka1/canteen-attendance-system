@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier ">
        {{-- Month Count --}}
        <div class="row">
            @if(count($today_attedance_data))
                @foreach ($today_attedance_data as $attendanceCount)
                    <div class="col-4">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Today {{$attendanceCount['meal_name']}} </h5>
                                <p class="card-text">{{$attendanceCount['total']}}</p>
                            </div>
                        </div>
                    </div>
                    
                @endforeach
            @endif


        </div>
    </div>
@endsection
