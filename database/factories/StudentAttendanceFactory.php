<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StudentAttendance>
 */
class StudentAttendanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extraMealCount = $this->faker->numberBetween(1, 5);
        // Generate unique extra meal IDs as string separated by commas
        $extraMealIds = implode(',', $this->faker->randomElements(range(1, 900), $extraMealCount));

        return [
            'student_id' => $this->faker->numberBetween(2, 677),
            'meal_id' => $this->faker->numberBetween(1, 3),
            'amount' => $this->faker->randomFloat(2, 10, 100), // approximate price value
            'extra_amount' => $this->faker->randomFloat(2, 5, 50),
            'extra_meal_id' => $extraMealIds,
            'date' => $this->faker->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
        ];
    }
}
