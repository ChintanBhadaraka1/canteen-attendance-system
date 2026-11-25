<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    protected $table = 'staff';

    // Written only by SalaryService.
    protected $guarded = [];

    public function job()
    {
        return $this->belongsTo(MenuOption::class, 'job_id');
    }

    public function periods()
    {
        return $this->hasMany(StaffPeriod::class)->orderBy('joined_on');
    }

    public function rates()
    {
        return $this->hasMany(StaffSalaryRate::class)->orderBy('from_month');
    }

    public function entries()
    {
        return $this->hasMany(StaffEntry::class)->orderBy('id');
    }

    public function isWorking(): bool
    {
        return $this->periods->last()->left_on === null;
    }
}
