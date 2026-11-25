<?php

namespace App\Services;

use App\Models\GuestMeal;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Monthly bills, worked back from the live balance. Reads only; whole rupees.
class StudentStatement
{
    /**
     * Fixed number of queries for any number of students.
     */
    public function for(array $studentIds, string $month): array
    {
        $from = "$month-01";
        $to = Carbon::parse($from)->endOfMonth()->toDateString();

        // One transaction, so every page is from the same moment.
        return DB::transaction(function () use ($studentIds, $month, $from, $to) {
            $students = User::withTrashed()->whereIn('id', $studentIds)->get()->keyBy('id');
            $balances = Payment::whereIn('student_id', $studentIds)->get()->keyBy('student_id');
            $names = MealPrice::pluck('name', 'id');

            $own = StudentAttendance::whereIn('student_id', $studentIds);
            $card = GuestMeal::notCancelled()->where('type', 'card')->whereIn('student_id', $studentIds);
            $paid = PaymentHistory::active()->whereIn('student_id', $studentIds);
            $later = fn ($query, string $date, string $amount) => (clone $query)->where($date, '>', $to)->groupBy('student_id')
                ->selectRaw("student_id, sum($amount) as total")->toBase()->pluck('total', 'student_id');
            $ownLater = $later($own, 'date', 'amount + extra_amount');
            $cardLater = $later($card, 'date', 'amount');
            $paidLater = $later($paid, 'payment_date', 'amount');
            // An active payment before phase 4 has no reliable amount.
            $unclear = (clone $paid)->whereNull('before_amount')->where('payment_date', '>=', $from)->distinct()->pluck('student_id')->flip();

            $own = (clone $own)->whereBetween('date', [$from, $to])->with('extras')->get()->groupBy('student_id');
            $card = (clone $card)->whereBetween('date', [$from, $to])->with('extras')->get()->groupBy('student_id');
            $paid = (clone $paid)->whereBetween('payment_date', [$from, $to])->orderBy('payment_date')->orderBy('id')->get()->groupBy('student_id');

            return array_map(function (int $id) use ($students, $month, $balances, $names, $ownLater, $cardLater, $paidLater, $unclear, $own, $card, $paid) {
                $rows = $own[$id] ?? collect();
                $guests = $card[$id] ?? collect();
                $payments = $paid[$id] ?? collect();
                $charged = (int) $rows->sum(fn ($row) => (int) $row->amount + (int) $row->extra_amount) + (int) $guests->sum('amount');
                $received = (int) $payments->sum('amount');
                $closing = ($balances[$id]->balance ?? 0) - (int) ($ownLater[$id] ?? 0) - (int) ($cardLater[$id] ?? 0) + (int) ($paidLater[$id] ?? 0);
                $student = $students[$id];

                return [
                    'id' => $id, 'name' => str($student->full_name)->squish()->value(), 'canteen_id' => $student->canteen_id, 'college' => $student->collage_name,
                    'month' => $month, 'available' => ! $unclear->has($id), 'opening' => $closing - $charged + $received,
                    'meals' => $this->meals($rows, $guests, $names), 'extras' => $this->extras($rows, $guests),
                    'payments' => $payments->map(fn ($row) => [
                        'date' => $row->payment_date, 'method' => $row->type === 'upi' ? 'UPI' : 'Cash', 'number' => str_pad($row->id, 6, '0', STR_PAD_LEFT), 'amount' => $row->amount,
                    ])->values()->all(),
                    'charged' => $charged, 'paid' => $received, 'closing' => $closing,
                ];
            }, $studentIds);
        });
    }

    /**
     * Bills of every student with a meal or payment in the month or something owed or in advance; by college, name, id.
     */
    public function all(string $month, string $college = '', bool $owing = false): array
    {
        $ids = Payment::whereHas('user', fn ($q) => $q->withTrashed()->where('role', 'user')->when($college !== '', fn ($q) => $q->where('collage_name', $college)))
            ->pluck('student_id')->all();

        return collect($this->for($ids, $month))->filter(fn ($bill) => $owing ? $bill['available'] && $bill['closing'] > 0
            : $bill['meals'] || $bill['extras'] || $bill['payments'] || $bill['closing'] !== 0 || ! $bill['available'])
            ->sortBy(fn ($bill) => [mb_strtolower($bill['college'] ?? ''), mb_strtolower($bill['name']), $bill['id']])->values()->all();
    }

    private function meals(Collection $rows, Collection $guests, Collection $names): array
    {
        $own = $rows->groupBy(fn ($row) => $names[$row->meal_id] ?? 'Removed meal')
            ->map(fn ($group, $name) => ['name' => (string) $name, 'count' => $group->count(), 'amount' => (int) $group->sum(fn ($row) => (int) $row->amount)]);
        $card = $guests->groupBy('meal_name')
            ->map(fn ($group, $name) => ['name' => "$name, guest", 'count' => (int) $group->sum('plates'), 'amount' => (int) $group->sum(fn ($row) => $row->plates * $row->price)]);

        return $own->sortKeys()->values()->concat($card->sortKeys()->values())->all();
    }

    private function extras(Collection $rows, Collection $guests): array
    {
        $lines = $rows->concat($guests)->flatMap->extras->groupBy('name')
            ->map(fn ($group, $name) => ['name' => (string) $name, 'count' => (int) $group->sum('quantity'), 'amount' => (int) $group->sum(fn ($row) => $row->quantity * $row->price)])
            ->sortKeys()->values();
        // Entries from before phase 6 kept no quantities.
        $old = (int) $rows->filter(fn ($row) => $row->extras->isEmpty())->sum(fn ($row) => (int) $row->extra_amount);

        return $lines->when($old !== 0, fn ($list) => $list->push(['name' => 'Extras', 'count' => null, 'amount' => $old]))->all();
    }

    // DejaVu Sans, the font of the bills, has no Indian letters: they would print as boxes.
    public static function printable(?string $text): bool
    {
        return preg_match('/^[\x{20}-\x{24F}\x{2010}-\x{2027}]*$/u', (string) $text) === 1;
    }
}
