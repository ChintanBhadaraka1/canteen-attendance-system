<?php

namespace Database\Factories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentHistory>
 */
class PaymentHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $usedStudentMonth = []; // To track uniqueness of student_id+month_name

        // Generate a random date in past 6 months
        $paymentDate = $this->faker->dateTimeBetween('-6 months', 'now');
        $monthName = Carbon::instance($paymentDate)->format('F'); // Full month name

        // Generate student_id unique for month
        do {
            $studentId = $this->faker->numberBetween(2, 677);
            $key = $studentId . '_' . $monthName;
        } while (in_array($key, $usedStudentMonth));

        $usedStudentMonth[] = $key;

        return [
            'student_id' => $studentId,
            'amount' => $this->faker->numberBetween(10, 500),
            'payment_date' => $paymentDate->format('Y-m-d'),
            'type' => $this->faker->randomElement(['cash', 'upi']),
            'month_name' => $monthName,
        ];
    }
}
