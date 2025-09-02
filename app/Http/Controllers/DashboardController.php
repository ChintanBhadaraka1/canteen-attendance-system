<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MealPrice;
use App\Models\StudentAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(){
        $carbonNow = Carbon::now()->toDateTimeString();

        // Current system time using native PHP
        $systemNow = date('Y-m-d H:i:s');

        // Current SQLite time from the database (using SQL query)
        $sqliteNow = DB::selectOne('SELECT datetime("now") as now')->now;

        
        $data = [];
        $data['carbonTime'] = $carbonNow;
        $data['systemTime'] = $systemNow;
        $data['dbTime']= $sqliteNow;

        $todayDate = Carbon::now()->format('Y-m-d');
        

        $mealNames = MealPrice::select('id', 'name')->get();

        $studentAttendanceCount = StudentAttendance::select('meal_id', DB::raw('count(*) as total'))
            ->where('date', $todayDate)
            ->groupBy('meal_id')
            ->get();


        // Convert $studentAttendanceCount to a keyed collection for quick lookups by meal_id
        $attendanceByMealId = $studentAttendanceCount->keyBy('meal_id');

        // Map through mealNames and combine with attendance counts
        $combined = $mealNames->map(function ($meal) use ($attendanceByMealId) {
            return [
                'meal_id' => $meal->id,
                'meal_name' => $meal->name,
                'total' => $attendanceByMealId->has($meal->id) ? $attendanceByMealId->get($meal->id)->total : 0,
            ];
        });

        // If you want it as an array:
        $monthAttedanceData = $combined;
        $data['today_attedance_data'] = $monthAttedanceData;
        return view('dashboard', $data);
    }
}
