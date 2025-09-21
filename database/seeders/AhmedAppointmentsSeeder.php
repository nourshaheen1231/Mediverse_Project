<?php

namespace Database\Seeders;

use App\Models\Doctor;
use App\Models\Schedule;
use App\Models\Appointment;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AhmedAppointmentsSeeder extends Seeder
{
    public function run(): void
    {
        $doctor = Doctor::where('first_name', 'Ahmed')->first();
        if (!$doctor) {
            $this->command->warn('Doctor Ahmed not found.');
            return;
        }

        $patients = Patient::all();
        $schedules = Schedule::where('doctor_id', $doctor->id)
            ->where('status', 'notAvailable')
            ->get();

        if ($patients->isEmpty() || $schedules->isEmpty()) {
            $this->command->warn('Make sure patients and schedules exist.');
            return;
        }

        $statusOptions = ['visited', 'cancelled', 'pending'];
        $appointmentTypeOptions = ['visit', 'vaccination'];
        $paymentStatusOptions = ['pending', 'paid', 'cancelled'];

        $daysInSeptember = Carbon::create(2025, 9, 1)->daysInMonth;

        for ($day = 1; $day <= $daysInSeptember; $day++) {
            $date = Carbon::create(2025, 9, $day)->toDateString();
            $dayName = Carbon::create(2025, 9, $day)->format('l');

            $dailySchedules = $schedules->where('day', $dayName);

            foreach ($dailySchedules as $schedule) {
                $shiftTimes = $this->getShiftTimeRange($schedule->Shift);
                $startHour = $shiftTimes['start'];
                $endHour = $shiftTimes['end'];

                for ($i = 0; $i < 12; $i++) {
                    $hour = rand($startHour, $endHour - 1);
                    $minute = rand(0, 1) ? '00' : '30';
                    $timeSelected = sprintf('%02d:%s:00', $hour, $minute);

                    $status = $statusOptions[array_rand($statusOptions)];
                    $appointmentType = $appointmentTypeOptions[array_rand($appointmentTypeOptions)];

                    $eligiblePatients = ($appointmentType === 'vaccination')
                        ? $patients->whereNotNull('parent_id')
                        : $patients;

                    if ($eligiblePatients->isEmpty()) {
                        continue;
                    }

                    $selectedPatient = $eligiblePatients->random();

                    $expectedPrice = ($appointmentType === 'visit')
                        ? rand(50, 150)
                        : rand(100, 300);

                    if ($status === 'visited') {
                        $payment_status = 'paid';
                        $paidPrice = $expectedPrice;
                    } elseif ($status === 'cancelled') {
                        $payment_status = 'cancelled';
                        $paidPrice = 0;
                    } else {
                        $payment_status = $paymentStatusOptions[array_rand($paymentStatusOptions)];
                        $paidPrice = ($payment_status === 'paid') ? $expectedPrice : 0;
                    }

                    $createdAt = Carbon::now()->subDays(rand(0, 30));
                    $updatedAt = (clone $createdAt)->addMinutes(rand(0, 1440));

                    Appointment::create([
                        'patient_id'       => $selectedPatient->id,
                        'schedule_id'      => $schedule->id,
                        'timeSelected'     => $timeSelected,
                        'reservation_date' => $date,
                        'status'           => $status,
                        'expected_price'   => $expectedPrice,
                        'paid_price'       => $paidPrice,
                        'payment_status'   => $payment_status,
                        'reminder_offset'  => 12,
                        'reminder_sent'    => (bool) rand(0, 1),
                        'appointment_type' => $appointmentType,
                        'queue_number'     => null,
                        'is_referral'      => false,
                        'referring_doctor' => null,
                        'parent_id'        => null,
                        'created_at'       => $createdAt,
                        'updated_at'       => $updatedAt,
                    ]);
                }
            }
        }
    }

    private function getShiftTimeRange(string $shift): array
    {
        if (str_contains($shift, 'morning')) {
            return ['start' => 9, 'end' => 15];
        } else {
            return ['start' => 15, 'end' => 21];
        }
    }
}