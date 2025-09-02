<div class="btn-group rounded-pill" role="group" aria-label="actions">
    <a href="{{route('user.show',$id)}}" type="button" class="btn btn-sm btn-primary ">
        <i class="bi bi-eye-fill"></i>
    </a>
    <a href="{{route('user.edit',$id)}}" type="button" class="btn btn-sm btn-warning">
        <i class="bi bi-pencil-square"></i>
    </a>
    <button type="button" class="btn btn-sm btn-danger delete-user" value="{{$id}}">
        <i class="bi bi-trash2-fill"></i>
    </button>
</div>
