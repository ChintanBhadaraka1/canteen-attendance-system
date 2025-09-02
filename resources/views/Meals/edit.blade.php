@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier p-0">
        <div class="card p-0">
            <div class="card-header d-flex justify-content-between p-3">
                <h4>Add New Meal </h4>
                <a href="{{ route('meal-price.index') }}" class="text-decoration-none btn btn-danger btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
            <div class="card-body p-2">
                <form action="{{ route('meal-price.update', $meal->id) }}" id="meal-update"
                    method="POST">
                    @csrf
                    @method('PUT')
                    <div class="row row-cols-2 px-4">
                        <div class="col">
                            <div class="mb-3">
                                <label for="name" class="form-label">Meal Name: </label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                    id="name" name="name" placeholder="Enter Meal Name"
                                    value="{{ old('name', $meal->name) }}">
                                @error('name')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        <div class="col">
                            <div class="mb-3">
                                <label for="price" class="form-label">Meal Price : </label>
                                <input type="text" class="form-control @error('price') is-invalid @enderror"
                                    id="price" name="price" placeholder="Enter Price For Item"
                                    value="{{ old('price', $meal->price) }}">
                                @error('price')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
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
 
    {!! $validator->selector('#meal-update') !!} 
      
@endpush