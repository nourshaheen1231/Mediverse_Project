<?php

namespace App;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Schedule;
use App\Models\User;
use App\Notifications\AppointmentCancelled;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Stripe\Refund;
use Stripe\Stripe;
use Illuminate\Support\Facades\Validator;

trait CancelAppointmentsTrait
{

    protected $firebaseService;

    public function __construct(FirebaseService $firebase_service)
    {
        $this->firebaseService = $firebase_service;
    }

    public function editDoctorSchedule(Request $request, $doctor)
    {
        $validator = Validator::make($request->all(), [
            'start_leave_date' => 'required|date_format:d-m-Y',
            'end_leave_date' => 'required|date_format:d-m-Y|after_or_equal:start_leave_date',
            'start_leave_time' => 'required|date_format:H:i',
            'end_leave_time' => 'required|date_format:H:i|after_or_equal:start_leave_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->all()
            ], 400);
        }

        // Parsing dates and times after validation
        $start_leave_date = Carbon::createFromFormat('d-m-Y', $request->start_leave_date)->format('Y-m-d');
        $end_leave_date = Carbon::createFromFormat('d-m-Y', $request->end_leave_date)->format('Y-m-d');
        $start_leave_time = Carbon::createFromFormat('H:i', $request->start_leave_time)->format('H:i');
        $end_leave_time = Carbon::createFromFormat('H:i', $request->end_leave_time)->format('H:i');

        $date = Carbon::createFromFormat('d-m-Y', $request->start_leave_date);
        $day = $date->format('l');
        $schedule = Schedule::where('doctor_id', $doctor)->where('day', $day)->first();

        if (!$schedule) {
            return response()->json(['message' => 'Schedule not found'], 404);
        }

        $schedule->update([
            'start_leave_date' => $start_leave_date,
            'end_leave_date' => $end_leave_date,
            'start_leave_time' => $start_leave_time,
            'end_leave_time' => $end_leave_time,
        ]);
        $schedule->save();

        $appointments = Appointment::with(['patient.user', 'schedule.doctor'])->whereBetween('reservation_date', [$start_leave_date, $end_leave_date])
            ->whereBetween('timeSelected', [$start_leave_time, $end_leave_time])
            ->where('status', 'pending')
            ->get();

        Stripe::setApiKey(env('STRIPE_SECRET'));

        foreach ($appointments as $appointment) {
            if ($appointment->payment_status == 'paid' && $appointment->payment_intent_id) {
                try {
                    Refund::create([
                        'payment_intent' => $appointment->payment_intent_id,
                    ]);

                    $patient = $appointment->patient;
                    $patient->wallet += $appointment->paid_price;
                    $patient->save();

                    $clinic = Clinic::where('id', $appointment->schedule->doctor->clinic_id)->first();
                    if (!$clinic) return response()->json(['messsage' => 'clinic not found'], 404);

                    $clinic->money -= $appointment->paid_price;
                    $clinic->save();
                } catch (\Exception $e) {
                    Log::error("Stripe refund failed for appointment ID {$appointment->id}: " . $e->getMessage());
                }
            }

            $appointment->payment_status = 'cancelled';
            $appointment->status = 'cancelled';
            $appointment->save();
        }

        $patients = $appointments->pluck('patient')->all();

        foreach ($patients as $patient) {
            if ($patient->parent_id != null) {
                $patient = Patient::where('id', $patient->parent_id)->first();
                $patient = User::where('id', $patient->user_id)->first();
            } else {
                $patient = $patient->user;
            }
            if ($patient->fcm_token) {
                foreach ($appointments as $appointment) {
                    if ($appointment->patient->id == $patient->id) {
                        $this->firebaseService->sendNotification($patient->fcm_token, 'sorry, your appointment canceled, doctor' . ' ' . $appointment->schedule->doctor->first_name . ' ' . $appointment->schedule->doctor->last_name . ' ' . 'will not be available ',  'date ' . $appointment->reservation_date,);
                        $patient->user->notify(new AppointmentCancelled($appointment));
                    }
                }
            }
        }

        return response()->json([
            'message' => 'Schedule successfully updated. Appointments canceled and refunds processed (if applicable).',
            'data' => $schedule,
        ], 200);
    }

    /////
    public function cancelAnAppointment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reservation_id' => 'required|integer|exists:appointments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->all()
            ], 400);
        }

        $reservation = Appointment::with(['patient.user', 'schedule.doctor'])->where('id', $request->reservation_id)->first();
        if (!$reservation) return response()->json(['message' => 'Reservaion Not Found'], 404);

        $patient = $reservation->patient;

        // Stripe::setApiKey(env('STRIPE_SECRET'));

        if ($reservation->payment_status == 'paid') {
            try {
                // Refund::create([
                //     'payment_intent' => $reservation->payment_intent_id,
                // ]);

                $patient->wallet += $reservation->paid_price;
                $patient->save();

                $reservation->paid_price = 0;
                $reservation->save();

                $clinic = Clinic::where('id', $reservation->doctor->clinic_id)->first();
                if (!$clinic) return response()->json(['messsage' => 'clinic not found'], 404);

                $clinic->money -= $reservation->paid_price;
                $clinic->save();
            } catch (\Exception $e) {
                Log::error("Stripe refund failed for reservation ID {$reservation->id}: " . $e->getMessage());
            }
        }

        $reservation->update([
            'status' => 'cancelled',
            'payment_status' => 'cancelled',
        ]);
        $reservation->save();

        $patient = $reservation->patient;
        if ($patient->parent_id != null) {
            $patient = Patient::where('id', $patient->parent_id)->first();
            $patient = User::where('id', $patient->user_id)->first();
        } else {
            $patient = $patient->user;
        }
        if ($patient->fcm_token) {
            $this->firebaseService->sendNotification($patient->fcm_token, 'sorry, your appointment canceled, doctor' . ' ' . $reservation->schedule->doctor->first_name . ' ' . $reservation->schedule->doctor->last_name . ' ' . 'will not be available ',  'date ' . $reservation->reservation_date,);
            $patient->notify(new AppointmentCancelled($reservation));
        }

        return response()->json(['message' => 'reservation canceled successfully'], 200);
    }
}
