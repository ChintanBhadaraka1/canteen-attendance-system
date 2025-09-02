<div class="btn-group rounded-pill" role="group" aria-label="actions">

    @if ($meal_id != null)
        @if (!$already_there)
            <button type="button" class="btn btn-sm btn-success "
                onclick="addTodayAttedance('{{ $id }}','{{ $meal_id }}')">
                <i class="bi bi-check2-square"></i>
            </button>
        @else
        @endif
    @else
        <a href="{{ route('student-attendance.create', $id) }}" type="button" class="btn btn-sm btn-primary ">
            <i class="bi bi-file-earmark-plus"></i>
        </a>
        <a href="{{ route('student-attendance.show', $id) }}" type="button" class="btn btn-sm btn-warning">
            <i class="bi bi-journal-medical"></i>
        </a>
    @endif
</div>
