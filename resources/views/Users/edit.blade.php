@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier p-0">
        <div class="card p-0">
            <div class="card-header d-flex justify-content-between p-3">
                <h4>Edit Student</h4>
                <a href="{{ route('user.index') }}" class="text-decoration-none btn btn-danger btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
            <div class="card-body p-2">
                <form action="{{ route('user.update', $user->id) }}" enctype="multipart/form-data" id="user-update" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="row row-cols-2 px-4">
                        <div class="col">
                            <div class="mb-3">
                                <label for="name" class="form-label">First Name:<span class="text-danger">*</span> </label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                       id="name" name="name" placeholder="Enter Your First Name"
                                       value="{{ old('name', $user->name) }}">
                                @error('name')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        <div class="col">
                            <div class="mb-3">
                                <label for="middle_name" class="form-label">Middle Name: </label>
                                <input type="text" class="form-control @error('middle_name') is-invalid @enderror"
                                       id="middle_name" name="middle_name" placeholder="Enter Your Middle Name"
                                       value="{{ old('middle_name', $user->middle_name) }}">
                                @error('middle_name')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        <div class="col">
                            <div class="mb-3">
                                <label for="last_name" class="form-label">Last Name: </label>
                                <input type="text" class="form-control @error('last_name') is-invalid @enderror"
                                       id="last_name" name="last_name" placeholder="Enter Your Last Name"
                                       value="{{ old('last_name', $user->last_name) }}">
                                @error('last_name')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        <div class="col">
                            <div class="mb-3">
                                <label for="collage_name" class="form-label">Collage Name: </label>
                                <input type="text" class="form-control @error('collage_name') is-invalid @enderror"
                                       id="collage_name" name="collage_name" placeholder="Enter Collage name"
                                       value="{{ old('collage_name', $user->collage_name) }}">
                                @error('collage_name')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        {{-- Email: readonly but submitted --}}
                        <div class="col">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email: </label>
                                <input type="email" class="form-control @error('email') is-invalid @enderror"
                                       id="email" name="email" placeholder="sample@gmail.com"
                                       value="{{ old('email', $user->email) }}" readonly>
                                @error('email')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        {{-- Canteen Id: readonly & NOT submitted (no name attribute and label kept) --}}
                        <div class="col">
                            <div class="mb-3">
                                <label for="canteen_id" class="form-label">Canteen Id: </label>
                                <input type="text" class="form-control" id="canteen_id" name="canteen_id"
                                       value="{{ $user->canteen_id }}" readonly>
                            </div>
                        </div>

                        <div class="col">
                            <div class="mb-3">
                                <label for="phone_number" class="form-label">Phone Number: <span class="text-danger">*</span> </label>
                                <input type="text" class="form-control @error('phone_number') is-invalid @enderror"
                                       id="phone_number" name="phone_number" placeholder="Enter Phone Number"
                                       value="{{ old('phone_number', $user->phone_number) }}">
                                @error('phone_number')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="profile_pic" class="form-label">Profile Pic:</label>
                            <input class="form-control @error('profile_pic') is-invalid @enderror" type="file"
                                   id="profile_pic" name="profile_pic">
                            @error('profile_pic')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror

                            @if($user->profile_pic)
                                <div class="mt-2">
                                    <img src="{{ asset('/image/profile/' . $user->profile_pic) }}"
                                         alt="Profile Pic" style="max-height: 100px;">
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="row px-4">
                        <div class="col-12 text-center">
                            <button type="submit" class="btn btn-primary px-5 py-1">Update</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection


@push('scripts')
 
    {!! $validator->selector('#user-update') !!} 
      
@endpush