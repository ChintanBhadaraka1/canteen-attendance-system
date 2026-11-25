<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Models\ActivityLog;
use App\Models\AttendanceBatch;
use App\Models\AttendanceExtra;
use App\Models\GuestMeal;
use App\Models\GuestMealExtra;
use App\Models\MealPrice;
use App\Models\Menus;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection as Rows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Every write to attendance, guest plates, balances and payments. is_paid is an enum: always compare with '0' and '1'.
class BillingService
{
    /**
     * @param  array<int, int>  $extras  menu id => quantity
     */
    public function markAttendance(int $studentId, int $mealId, string $date, array $extras = []): StudentAttendance
    {
        $extras = array_filter($extras, fn ($quantity) => $quantity > 0);

        try {
            return $this->forStudent($studentId, function (User $student) use ($mealId, $date, $extras) {
                $meal = $this->lockMeal($mealId);
                $attendance = $this->mark($student, $meal, $date, $extras);

                ActivityLog::record('attendance.marked', $student->id,
                    "{$meal->name} on {$this->day($date)} marked for {$student->full_name} (₹" . ($attendance->amount + $attendance->extra_amount) . ')',
                    ['attendance_id' => $attendance->id]);

                return $attendance;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate(MealPrice::findOrFail($mealId));
        }
    }

    /**
     * Students who cannot be marked are skipped; any other error saves nobody.
     *
     * @param  int[]  $studentIds
     * @return array{batch: ?AttendanceBatch, marked: int, total: int, skipped: array<int, array{id: int, name: string, reason: string}>}
     */
    public function markMany(array $studentIds, int $mealId, string $date, int $expectedPrice): array
    {
        return $this->forStudents($studentIds, function (Collection $students) use ($mealId, $date, $expectedPrice) {
            $meal = $this->lockMeal($mealId);
            $price = $meal->priceOn($date);
            if ($price !== $expectedPrice) {
                throw new BillingException("The price of {$meal->name} is now ₹$price. Please check the total and save again.");
            }

            $ids = [];
            $skipped = [];
            foreach ($students as $student) {
                try {
                    $ids[] = $this->mark($student, $meal, $date)->id;
                } catch (BillingException $e) {
                    $skipped[] = ['id' => $student->id, 'name' => $student->full_name, 'reason' => $e->getMessage()];
                }
            }

            $marked = count($ids);
            $total = $marked * $price;
            $batch = null;
            if ($marked) {
                $batch = AttendanceBatch::create([
                    'meal_id' => $meal->id, 'meal_name' => $meal->name, 'date' => $date, 'count' => $marked,
                    'created_by' => auth()->id(), 'user_name' => auth()->user()->full_name ?? 'System',
                ]);
                StudentAttendance::whereKey($ids)->update(['batch_id' => $batch->id]);
                ActivityLog::record('attendance.bulk', null,
                    "{$meal->name} on {$this->day($date)} marked for $marked " . Str::plural('student', $marked) . ' (₹' . number_format($total) . ')',
                    ['batch_id' => $batch->id, 'attendance_ids' => $ids]);
            }

            return compact('batch', 'marked', 'total', 'skipped');
        });
    }

    public function undoBatch(int $batchId): AttendanceBatch
    {
        $studentIds = StudentAttendance::where('batch_id', $batchId)->pluck('student_id')->all();

        return $this->forStudents($studentIds, function (Collection $students) use ($batchId) {
            $batch = AttendanceBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            if ($batch->undone_at !== null) {
                throw new BillingException('This save was already undone.');
            }

            $entries = $batch->entries()->get();
            if ($entries->isEmpty()) {
                throw new BillingException('All these entries were already deleted.');
            }
            $paid = $entries->where('is_paid', '1')->map(fn ($entry) => $students[$entry->student_id]->full_name ?? 'A removed student');
            if ($paid->isNotEmpty()) {
                $more = $paid->count() - 3;
                throw new BillingException($paid->take(3)->implode(', ') . ($more > 0 ? " and $more more" : '')
                    . ($paid->count() === 1 ? ' has' : ' have') . ' already paid, so this save cannot be undone.');
            }

            foreach ($entries as $entry) {
                Payment::where('student_id', $entry->student_id)->decrement('amount', (int) $entry->amount + (int) $entry->extra_amount);
            }
            StudentAttendance::whereKey($entries->modelKeys())->delete();
            $batch->update(['undone_at' => now(), 'undone_by' => auth()->id()]);
            $count = $entries->count();
            ActivityLog::record('attendance.bulk_undone', null,
                "{$batch->meal_name} on {$this->day($batch->date)} undone for $count " . Str::plural('student', $count),
                ['batch_id' => $batch->id]);

            $batch->undone_count = $count;

            return $batch;
        });
    }

    public function deleteAttendance(int $attendanceId): StudentAttendance
    {
        $gone = new BillingException('This attendance entry was already deleted.');
        $studentId = (StudentAttendance::find($attendanceId) ?? throw $gone)->student_id
            ?? throw new BillingException('This entry has no student, so it cannot be deleted.');

        return $this->forStudent($studentId, function (User $student) use ($attendanceId, $gone) {
            $attendance = StudentAttendance::with('meal')->where('student_id', $student->id)->find($attendanceId) ?? throw $gone;

            if ($attendance->is_paid === '1') {
                throw new BillingException('This meal is already paid, so it cannot be deleted. Cancel the payment first.');
            }

            $total = (int) $attendance->amount + (int) $attendance->extra_amount;
            Payment::where('student_id', $student->id)->decrement('amount', $total);
            $attendance->delete();

            $meal = $attendance->meal->name ?? 'Removed meal';
            ActivityLog::record('attendance.deleted', $student->id,
                "$meal on {$this->day($attendance->date)} deleted for {$student->full_name} (₹$total)",
                ['attendance_id' => $attendance->id]);

            return $attendance;
        });
    }

    public function takePayment(int $paymentId, int $amount, string $type, string $month, int $expectedDue): PaymentHistory
    {
        $studentId = Payment::whereNotNull('student_id')->findOrFail($paymentId)->student_id;

        return $this->forStudent($studentId, function (User $student) use ($paymentId, $amount, $type, $month, $expectedDue) {
            $bill = Payment::findOrFail($paymentId);

            if ($bill->balance <= 0) {
                throw new BillingException('Nothing is due for this student.');
            }
            if ($bill->balance !== $expectedDue) {
                throw new BillingException('The bill has changed. Please check the amount and save again.');
            }

            $history = PaymentHistory::create([
                'student_id' => $student->id,
                'amount' => $amount,
                'payment_date' => today()->toDateString(),
                'type' => $type,
                'month_name' => $month,
                'before_amount' => $bill->amount,
                'before_pending' => $bill->pending_amount,
                'before_advance' => $bill->advance_amount,
                'created_by' => auth()->id(),
            ]);
            StudentAttendance::where('student_id', $student->id)->where('is_paid', '0')
                ->update(['is_paid' => '1', 'payment_history_id' => $history->id]);
            GuestMeal::where('student_id', $student->id)->where('is_paid', '0')->notCancelled()
                ->update(['is_paid' => '1', 'payment_history_id' => $history->id]);

            $left = $bill->balance - $amount;
            $bill->update(['amount' => 0, 'pending_amount' => max(0, $left), 'advance_amount' => max(0, -$left)]);

            ActivityLog::record('payment.saved', $student->id,
                "₹$amount by " . ($type === 'upi' ? 'UPI' : $type) . " from {$student->full_name}",
                ['payment_id' => $history->id, 'amount' => $amount]);

            return $history;
        });
    }

    public function cancelPayment(int $historyId, string $reason): PaymentHistory
    {
        $studentId = PaymentHistory::findOrFail($historyId)->student_id
            ?? throw new BillingException('This old payment cannot be cancelled.');

        return $this->forStudent($studentId, function (User $student) use ($historyId, $reason) {
            $history = PaymentHistory::findOrFail($historyId);

            if (! $history->isCancellable()) {
                throw new BillingException(match (true) {
                    $history->cancelled_at !== null => 'This payment was already cancelled.',
                    $history->before_amount === null => 'This old payment cannot be cancelled.',
                    default => 'Cancel the newer payment first.',
                });
            }

            // The amount is added to, so meals marked after the payment stay on the bill.
            $bill = Payment::where('student_id', $student->id)->firstOrFail();
            $bill->update([
                'amount' => $bill->amount + $history->before_amount,
                'pending_amount' => $history->before_pending,
                'advance_amount' => $history->before_advance,
            ]);
            StudentAttendance::where('payment_history_id', $history->id)->update(['is_paid' => '0', 'payment_history_id' => null]);
            GuestMeal::where('payment_history_id', $history->id)->update(['is_paid' => '0', 'payment_history_id' => null]);
            $history->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);

            ActivityLog::record('payment.cancelled', $student->id,
                "₹{$history->amount} from {$student->full_name} cancelled: $reason",
                ['payment_id' => $history->id, 'amount' => $history->amount]);

            return $history;
        });
    }

    /**
     * Plates on a student's card, never a second own meal.
     *
     * @param  array{meal_id: int, date: string, plates: int, expected_total: int, extras: array<int, int>, guest_name: ?string, token: string}  $data
     */
    public function addCardGuest(int $studentId, array $data): GuestMeal
    {
        return $this->once('card', $studentId, $data, fn () => $this->forStudent($studentId, function (User $student) use ($data) {
            if ($saved = $this->savedGuest('card', $student->id, $data['token'])) {
                return $saved;
            }
            $meal = $this->lockMeal($data['meal_id']);
            $this->refuse($student, $data['date'], 'guest plates');
            $guest = $this->createGuest($meal, $data, ['type' => 'card', 'student_id' => $student->id]);
            Payment::firstOrCreate(['student_id' => $student->id])->increment('amount', $guest->amount);

            ActivityLog::record('guest.card_added', $student->id,
                "{$guest->label()} on {$this->day($guest->date)} for {$student->full_name} (₹{$guest->amount})", ['guest_meal_id' => $guest->id]);

            return $guest;
        }));
    }

    /**
     * Plates paid at once by somebody who is not a student.
     *
     * @param  array  $data  as for addCardGuest, and method
     */
    public function addWalkIn(array $data): GuestMeal
    {
        return $this->once('walk_in', null, $data, fn () => DB::transaction(function () use ($data) {
            $meal = $this->lockMeal($data['meal_id']);
            if ($saved = $this->savedGuest('walk_in', null, $data['token'])) {
                return $saved;
            }
            $guest = $this->createGuest($meal, $data, ['type' => 'walk_in', 'method' => $data['method'], 'is_paid' => '1']);

            ActivityLog::record('guest.walk_in_added', null,
                "{$guest->label()} on {$this->day($guest->date)} to a walk-in guest (₹{$guest->amount})", ['guest_meal_id' => $guest->id]);

            return $guest;
        }, 3));
    }

    public function cancelGuest(int $guestMealId, string $reason): GuestMeal
    {
        // The type and the student of a record never change.
        $studentId = GuestMeal::findOrFail($guestMealId)->student_id;

        $work = function (?User $student = null) use ($guestMealId, $reason) {
            $guest = GuestMeal::whereKey($guestMealId)->lockForUpdate()->firstOrFail();
            if ($guest->cancelled_at !== null) {
                throw new BillingException('This was already cancelled.');
            }
            if ($guest->isCard() && $guest->is_paid === '1') {
                throw new BillingException('This plate is already paid. Cancel the payment first.');
            }
            $guest->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);
            if ($student !== null) {
                Payment::where('student_id', $student->id)->decrement('amount', $guest->amount);
            }

            ActivityLog::record('guest.cancelled', $student?->id,
                "{$guest->label()} on {$this->day($guest->date)} " . ($student ? "for {$student->full_name}" : 'to a walk-in guest') . " (₹{$guest->amount}) cancelled: $reason",
                ['guest_meal_id' => $guest->id]);

            return $guest;
        };

        return $studentId === null ? DB::transaction(fn () => $work(), 3) : $this->forStudent($studentId, $work);
    }

    /**
     * Earlier dates are closed. Own meals and card plates both count.
     *
     * @return Rows<int, string> date by student id
     */
    public static function lastPaidDates(?int $studentId = null): Rows
    {
        $latest = fn ($query) => $query->where('is_paid', '1')->when($studentId, fn ($q) => $q->where('student_id', $studentId))
            ->groupBy('student_id')->pluck(DB::raw('max(date)'), 'student_id');

        $dates = $latest(StudentAttendance::whereNotNull('student_id'));
        foreach ($latest(GuestMeal::where('type', 'card')) as $id => $date) {
            $dates[$id] = max($dates[$id] ?? '', $date);
        }

        return $dates;
    }

    private function lastPaidDate(int $studentId): ?string
    {
        return self::lastPaidDates($studentId)[$studentId] ?? null;
    }

    // Two requests with one token can both pass the look-up; the unique key stops the second.
    private function once(string $type, ?int $studentId, array $data, Closure $save): GuestMeal
    {
        try {
            return $save();
        } catch (UniqueConstraintViolationException $e) {
            return $this->savedGuest($type, $studentId, $data['token']) ?? throw $e;
        }
    }

    private function savedGuest(string $type, ?int $studentId, string $token): ?GuestMeal
    {
        $saved = GuestMeal::where('token', $token)->first();
        if ($saved !== null && ($saved->type !== $type || $saved->student_id !== $studentId || $saved->created_by !== auth()->id())) {
            throw new BillingException('This was already saved. Reload the page and try again.');
        }

        return $saved;
    }

    // Call with the meal locked. Refuses before the first write.
    private function createGuest(MealPrice $meal, array $data, array $fields): GuestMeal
    {
        $extras = array_filter($data['extras'], fn ($quantity) => $quantity > 0);
        $items = $this->extraItems($extras);
        $price = $meal->priceOn($data['date']);
        $extraAmount = $this->extraAmount($items, $extras);
        $amount = $data['plates'] * $price + $extraAmount;
        if ($amount !== $data['expected_total']) {
            throw new BillingException("The price has changed. The total is now ₹$amount. Please check and save again.");
        }

        $guest = GuestMeal::create($fields + [
            'meal_id' => $meal->id, 'meal_name' => $meal->name, 'date' => $data['date'], 'plates' => $data['plates'], 'price' => $price,
            'extra_amount' => $extraAmount, 'amount' => $amount, 'guest_name' => $data['guest_name'], 'token' => $data['token'],
            'created_by' => auth()->id(), 'user_name' => auth()->user()->full_name ?? 'System',
        ]);
        foreach ($items as $item) {
            GuestMealExtra::create([
                'guest_meal_id' => $guest->id, 'menu_id' => $item->id, 'name' => $item->name,
                'quantity' => $extras[$item->id], 'price' => $this->rupees($item->price),
            ]);
        }

        return $guest;
    }

    private function forStudent(int $studentId, Closure $work): mixed
    {
        return DB::transaction(function () use ($studentId, $work) {
            // First query on purpose: an earlier read would fix an old MariaDB snapshot.
            $student = User::withTrashed()->whereKey($studentId)->lockForUpdate()->firstOrFail();

            return $work($student);
        }, 3);
    }

    /**
     * @param  Closure(Collection<int, User>): mixed  $work  receives the locked students by id
     */
    private function forStudents(array $studentIds, Closure $work): mixed
    {
        return DB::transaction(function () use ($studentIds, $work) {
            // First query, always in ID order, so two bulk saves cannot block each other.
            $students = User::withTrashed()->whereIn('id', $studentIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            return $work($students);
        }, 3);
    }

    private function lockMeal(int $mealId): MealPrice
    {
        $meal = MealPrice::whereKey($mealId)->sharedLock()->first()
            ?? throw new BillingException('This meal is no longer available. Please choose again.');
        if (! $meal->active) {
            throw new BillingException("{$meal->name} is turned off in Meal prices.");
        }

        return $meal;
    }

    // The one set of rules for a new entry. Call with the student and the meal locked.
    // Every refusal comes before the first write, so a bulk save can skip and go on.
    private function mark(User $student, MealPrice $meal, string $date, array $extras = []): StudentAttendance
    {
        $this->refuse($student, $date, 'attendance');
        if (StudentAttendance::where('student_id', $student->id)->where('meal_id', $meal->id)->where('date', $date)->exists()) {
            throw $this->duplicate($meal);
        }

        $items = $this->extraItems($extras);
        $amount = $meal->priceOn($date);
        $extraAmount = $this->extraAmount($items, $extras);

        $attendance = StudentAttendance::create([
            'student_id' => $student->id,
            'meal_id' => $meal->id,
            'amount' => $amount,
            'extra_amount' => $extraAmount,
            'date' => $date,
        ]);
        foreach ($items as $item) {
            AttendanceExtra::create([
                'attendance_id' => $attendance->id, 'menu_id' => $item->id, 'name' => $item->name,
                'quantity' => $extras[$item->id], 'price' => $this->rupees($item->price),
            ]);
        }
        Payment::firstOrCreate(['student_id' => $student->id])->increment('amount', $amount + $extraAmount);

        return $attendance;
    }

    private function refuse(User $student, string $date, string $what): void
    {
        if ($student->trashed() || $student->isAdmin()) {
            throw new BillingException('This student is no longer in the list.');
        }
        if ($student->left_on !== null) {
            throw new BillingException("{$student->full_name} has left, so $what cannot be added.");
        }
        if ($date < $this->lastPaidDate($student->id)) {
            throw new BillingException("This date is already paid, so $what cannot be added.");
        }
    }

    private function extraItems(array $extras): Collection
    {
        $items = $extras ? Menus::extras()->whereKey(array_keys($extras))->orderBy('id')->sharedLock()->get() : new Collection;
        if ($items->count() !== count($extras)) {
            throw new BillingException('An extra item is no longer available. Please choose again.');
        }

        return $items;
    }

    private function extraAmount(Collection $items, array $extras): int
    {
        return $items->sum(fn ($item) => $this->rupees($item->price) * $extras[$item->id]);
    }

    private function duplicate(MealPrice $meal): BillingException
    {
        return new BillingException("{$meal->name} is already marked for this date.");
    }

    private function rupees(?string $price): int
    {
        return (int) round((float) $price);
    }

    private function day(string $date): string
    {
        return Carbon::parse($date)->format('j F Y');
    }
}
