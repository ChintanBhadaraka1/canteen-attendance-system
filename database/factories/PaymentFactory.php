<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentFactory>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $usedStudentIds = [];

        // Generate a unique student_id between 2 and 677
        do {
            $studentId = $this->faker->numberBetween(2, 677);
        } while (in_array($studentId, $usedStudentIds));

        $usedStudentIds[] = $studentId;

        return [
            'student_id' => $studentId,
            'amount' => $this->faker->numberBetween(100, 1000),
            'pending_amount' => $this->faker->numberBetween(0, 500),
            'advance_amount' => $this->faker->numberBetween(0, 500),
        ];
    }
}
