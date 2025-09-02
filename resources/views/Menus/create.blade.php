@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier p-0">
        <div class="card p-0">
            <div class="card-header d-flex justify-content-between p-3">
                <h4>Add Menu Item </h4>
                <a href="{{ route('menus.index') }}" class="text-decoration-none btn btn-danger btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
            <div class="card-body p-2">
                <form action="{{ route('menus.store') }}" enctype="multipart/form-data" id="menu-form" method="POST">
                    @csrf
                    <div class="row row-cols-2 px-4">
                        <div class="col">
                            <div class="mb-3">
                                <label for="name" class="form-label">Menu Name: <span class="text-danger">*</span> </label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                    id="name" name="name" placeholder="Enter Menu Name" value="{{ old('name') }}">
                                @error('name')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        <div class="col">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="switchForExtra" name="is_extra">
                                    <label class="form-check-label" for="switchForExtra">Is Extra Avilable</label>
                                </div>
                            </div>
                        </div>

                        <div class="col">
                            <div class="mb-3">
                                <label for="price" class="form-label">Price : </label>
                                <input type="text" class="form-control @error('price') is-invalid @enderror"
                                    id="price" name="price" placeholder="Enter Price For Item"
                                    value="{{ old('price') }}">
                                @error('price')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="images" class="form-label">Menu Image:</label>
                            <input class="form-control @error('images') is-invalid @enderror" type="file"
                                id="images" name="images">
                            @error('images')
                                <div class="invalid-feedback">
                                    {{ $message }}
                                </div>
                            @enderror
                        </div>
                    </div>

                    <div class="row px-4">
                        <div class="col-12 text-center">
                            <button type="submit" class="btn btn-primary px-5 py-1">Submit</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>
@endsection

@push('scripts')
 
    {!! $validator->selector('#menu-form') !!} 
      
@endpush