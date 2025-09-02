<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentHistory extends Model
{
    protected $fillable= [
        'student_id',
        'amount',
        'payment_date',
        'type',
        'month_name'
    ];
}
