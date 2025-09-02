@extends('Layout.common-layout')

@section('main-content')
    <div class="contanier ">
        <div class="card p-0">
            <div class="card-header d-flex justify-content-between p-3">
                <h4>Meal Prices</h4>
                <a href="{{ route('meal-price.create') }}" class="text-decoration-none btn btn-primary btn-sm">
                    <i class="bi bi-plus-circle"></i>
                    Add
                </a>
            </div>
        </div>
        <div class="row row-cols-3">
            @foreach ($meal_data as $meal)
                <div class="col">
                    <div class="card p-0">
                        <div class="card-header d-flex justify-content-between p-3">
                            <h4>{{ $meal->name }}</h4>
                            <div>
                                <a href="{{ route('meal-price.edit', $meal->id) }}"
                                    class="text-decoration-none btn btn-warning btn-sm">
                                    <i class="bi bi-pencil-square"></i>
                                    Edit
                                </a>
                                <a href="javascript:void(0);" class="text-decoration-none btn btn-danger btn-sm delete-menu"
                                    data-id="{{ $meal->id }}" data-module="Menu">
                                    <i class="bi bi-trash2-fill"></i> Delete
                                </a>
                            </div>
                        </div>
                        <div class="card-body p-3 py-1">
                            <h3>Price : {{ $meal->price }}</h3>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

    </div>
@endsection

@push('scripts')
    <script>
        $(document).ready(function() {
            $('.delete-menu').on('click', function() {
                var menuId = $(this).data('id');
                var moduleName = $(this).data('module') || 'Item';

                confirmDelete(moduleName, function() {
                    startLoader();

                    $.ajax({
                        url: '/meal-price/' + menuId,
                        type: 'DELETE',
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
        });
    </script>
@endpush
