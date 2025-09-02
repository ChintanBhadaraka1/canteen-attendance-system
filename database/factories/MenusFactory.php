<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Menus>
 */
class MenusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'slug' => $this->faker->slug(),
            'price' => $this->faker->randomDigit(2, 1, 100),
            'is_extra' => $this->faker->randomElement([1, 0]),
            'images' => $this->faker->optional()->imageUrl(),
        ];
    }
}
