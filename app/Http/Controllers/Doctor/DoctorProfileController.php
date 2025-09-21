<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientReview;
use App\Models\Review;
use App\Models\Schedule;
use App\PaginationTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Stripe\Refund;
use Stripe\Stripe;
use App\Services\FirebaseService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class DoctorProfileController extends Controller
{
    use PaginationTrait;

    protected $firebaseService;
    public function __construct(FirebaseService $firebase_service)
    {
        $this->firebaseService = $firebase_service;
    }
    /////
    public function showDoctorWorkDates()
    {
        $user = Auth::user();

        //check the auth
        if (!$user) {
            return response()->json([
                'message' => 'unauthorized'
            ], 401);
        }

        if ($user->role != 'doctor') {
            return response()->json([
                'message' => 'you dont have permission'
            ], 401);
        }

        $doctor = Doctor::where('user_id', $user->id)->first();
        if(!$doctor) return response()->json(['message' => 'doctor not found'], 404);

        $schedules = Schedule::where('doctor_id', $doctor->id)->where('status', 'notAvailable')->get();
        $workingDays = $schedules->pluck('day');

        $startDate = Carbon::today();
        $endDate = Carbon::today()->addMonth(5);
        $period = CarbonPeriod::create($startDate, $endDate);

        $availableDates = collect();

        foreach ($period as $date) {
            if ($workingDays->contains($date->format('l'))) {
                $availableDates->push($date->toDateString());
            }
        }

        foreach ($availableDates as $key => $availableDate) {
            foreach ($schedules as $schedule) {
                $date = $availableDate;
                $startLeaveDate = $schedule->start_leave_date;
                $endLeaveDate = $schedule->end_leave_date;
                $startLeaveTime =  $schedule->start_leave_time;
                $endLeaveTime =  $schedule->end_leave_time;

                if ($date >= $startLeaveDate && $date <= $endLeaveDate) {
                    if ($schedule->Shift == 'morning shift:from 9 AM to 3 PM') {
                        $start = Carbon::createFromTime(9, 0, 0)->format('H:i:s');
                        $end = Carbon::createFromTime(15, 0, 0)->format('H:i:s');
                    } else {
                        $start = Carbon::createFromTime(15, 0, 0)->format('H:i:s');
                        $end = Carbon::createFromTime(21, 0, 0)->format('H:i:s');
                    }
                    if ($startLeaveTime == null && $endLeaveTime == null) {
                        $availableDates->forget($key);
                        continue;
                    }
                    if ($startLeaveTime == $start && $endLeaveTime == $end) {
                        $availableDates->forget($key);
                    }
                }
            }
        }

        return response()->json([
            'available_dates' => $availableDates->values()
        ], 200);
    }
    ////
    public function profile()
    {
        $auth = $this->auth();
        if ($auth) return $auth;
        $user = Auth::user();
        $doctor = Doctor::where('user_id', $user->id)->first();
        if (!$doctor) return response()->json(['message' => 'Doctor Not Found'], 404);
        $clinic = Clinic::where('id', $doctor->clinic_id)->first();
        if (!$clinic) return response()->json(['message' => 'Clinic Not Found'], 404);
        $workDays = Schedule::where('doctor_id', $doctor->id)->where('clinic_id', $clinic->id)->where('status', 'notAvailable')->get();
        if ($workDays->isEmpty()) {
            return response()->json(['message' => 'No schedule available yet'], 404);
        }
        $schedule = [];
        foreach ($workDays as $workDay) {
            $schedule[] = [
                'id' => $workDay->id,
                'day' => $workDay->day,
                'Shift' => $workDay->Shift,
            ];
        }
        $response = [
            'first_name' => $doctor->first_name,
            'last_name' => $doctor->last_name,
            'phone' => $user->phone,
            'email' => $user->email,
            'photo' => $doctor->photo,
            'clinic' => $clinic->name,
            'speciality' => $doctor->speciality,
            'professional_title' => $doctor->professional_title,
            'finalRate' => $doctor->finalRate,
            'average_visit_duration' => $doctor->average_visit_duration,
            'visit_fee' => $doctor->visit_fee,
            'experience' => $doctor->experience,
            'treated' => $doctor->treated,
            'booking_type' => $doctor->booking_type,
            'status' => $doctor->status,
            'sign' => $doctor->sign,
            'schedule' => $schedule
        ];
        return response()->json($response, 200);
    }
    /////
    public function editProfile(Request $request)
    {
        $auth = $this->auth();
        if ($auth) return $auth;
        $user = Auth::user();
        $validator = Validator::make($request->all(), [
            'first_name' => 'string|nullable',
            'last_name' => 'string|nullable',
            'email' => ['string', 'email', 'max:255', 'nullable', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['phone:SY', 'nullable', Rule::unique('users', 'phone')->ignore($user->id)],
            'old_password' => ['string', 'min:8', 'regex:/[0-9]/', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'nullable'],
            'password' => ['string', 'min:8', 'regex:/[0-9]/', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'confirmed', 'nullable'],
            'photo' => 'image',
            'speciality' => 'string|nullable',
            'professional_title' => 'string|nullable',
            'average_visit_duration' =>  ['nullable', Rule::in(['10 min', '15 min', '20 min', '30 min', '60 min'])],
            'visit_fee' => 'numeric|nullable',
            'experience' => 'integer|nullable',
            'sign' => 'image',
            'status' => 'string|nullable',
            'booking_type' => ['nullable', Rule::in(['manual', 'auto'])],
        ], [
            'phone.phone' => 'enter a valid syrian phone number',
            'phone.unique' => 'this phone has already been taken'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' =>  $validator->errors()->all()
            ], 400);
        }

        $doctor = Doctor::where('user_id', $user->id)->first();
        if (!$doctor) return response()->json(['message' => 'Doctor Not Found'], 404);
        if ($request->hasFile('photo')) {
            if ($doctor->photo) {
                $previousImagePath = public_path($doctor->photo);
                if (File::exists($previousImagePath)) {
                    File::delete($previousImagePath);
                }
            }
            $path1 = $request->photo->store('images/doctors/profiles', 'public');
            $doctor->photo = '/storage/' . $path1;
        }

        if ($request->hasFile('sign')) {
            if ($doctor->sign) {
                $previousImagePath = public_path($doctor->sign);
                if (File::exists($previousImagePath)) {
                    File::delete($previousImagePath);
                }
            }
            $path2 = $request->sign->store('images/doctors/signs', 'public');
            $doctor->sign = '/storage/' . $path2;
        }


        if ($request->filled('password')) {
            if (! $request->filled('old_password')) {
                return response()->json(['message' => 'you have to enter old_password to change password'], 422);
            }
            if (! Hash::check($request->old_password, $user->password)) {
                return response()->json(['message' => 'old password is wrong'], 422);
            }
            if ($request->old_password == $request->password) {
                return response()->json(['message' => 'The new password you entered is the same as the old one'], 422);
            }
        }
        $user = $doctor->user()->first();
        $user->update($request->all());
        $user->save();
        $doctor->update($request->except(['photo', 'sign']));
        $doctor->save();
        return response()->json(['message' => 'Updated successfully'], 200);
    }
    /////
    public function schedule(Request $request)
    {
        $auth = $this->auth();
        if ($auth) return $auth;
        $user = Auth::user();
        $validator = Validator::make($request->all(), [
            'RosterDays' => 'required|array|min:1',
            'RosterDays.*.day' => ['required', 'string', Rule::in(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Saturday'])],
            'RosterDays.*.Shift' => ['required', 'string', Rule::in('morning shift:from 9 AM to 3 PM', 'evening shift:from 3 PM to 9 PM')],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->all()], 422);
        }
        $doctor = Doctor::where('user_id', $user->id)->first();
        if (!$doctor) return response()->json(['message' => 'Doctor Not Found'], 404);
        //Schedule::where('doctor_id', $doctor->id)->delete();
        foreach ($request->RosterDays as $RosterDay) {
            $day = $RosterDay['day'];
            $Shift = $RosterDay['Shift'];
            $isTaken = Schedule::where('clinic_id', $doctor->clinic_id)
                ->where('day', $day)
                ->where('Shift', $Shift)
                ->where('status', 'notAvailable')
                //->where('doctor_id', '!=', $doctor->id)
                ->exists();
            if ($isTaken) {
                return response()->json(['message' => 'The shift you select is not available'], 404);
            }
            $sameDay = Schedule::where('clinic_id', $doctor->clinic_id)
                ->where('day', $day)
                ->where('doctor_id', $doctor->id)
                ->exists();
            if ($sameDay) {
                return response()->json(['message' => 'You already have a shift at this day'], 404);
            }
            Schedule::create([
                'clinic_id' => $doctor->clinic_id,
                'doctor_id' => $doctor->id,
                'day' => $day,
                'Shift' => $Shift,
                'status' => 'notAvailable',
            ]);
        }
        $doctor->status = 'available';
        $doctor->save();
        return response()->json(['message' => 'Shifts processed successfully'], 201);
    }
    /////
    public function availableWorkDays()
    {
        if ($auth = $this->auth()) {
            return $auth;
        }

        $user = Auth::user();
        $doctor = Doctor::where('user_id', $user->id)->first();

        if (!$doctor) {
            return response()->json(['message' => 'Doctor not found.'], 404);
        }

        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Saturday'];
        $shifts = [
            'morning shift:from 9 AM to 3 PM',
            'evening shift:from 3 PM to 9 PM',
        ];

        $availableSchedule = [];

        foreach ($days as $day) {
            $availableShifts = [];

            foreach ($shifts as $shift) {
                $isTaken = Schedule::where('clinic_id', $doctor->clinic_id)
                    ->where('day', $day)
                    ->where('Shift', $shift)
                    ->where('status', 'notAvailable')
                    //->where('doctor_id', '!=', $doctor->id)
                    ->exists();

                $sameDay = Schedule::where('clinic_id', $doctor->clinic_id)
                    ->where('day', $day)
                    ->where('doctor_id', $doctor->id)
                    ->exists();

                if (!$isTaken && !$sameDay) {
                    $availableShifts[] = $shift;
                }
            }

            if (!empty($availableShifts)) {
                $availableSchedule[] = [
                    'day' => $day,
                    'available_shifts' => $availableShifts
                ];
            }
        }

        return response()->json($availableSchedule, 200);
    }
    /////
    public function deleteFromSchedule(Request $request)
    {
        if ($auth = $this->auth()) {
            return $auth;
        }
        $validator = Validator::make($request->all(), [
            'schedule_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->all()], 422);
        }
        $schedule = Schedule::where('id', $request->schedule_id)->where('status', 'notAvailable')->first();
        if (!$schedule) {
            return response()->json(['message' => 'Schedule Not Found'], 404);
        }
        $schedule->status = 'available';
        $schedule->save();
        $appointments = Appointment::with(['patient.user', 'schedule.doctor'])
            ->where('schedule_id', $request->schedule_id)
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

                    $clinic = Clinic::where('id', $appointment->doctor->clinic_id)->first();
                    if (!$clinic) return response()->json(['messsage' => 'clinic not found'], 404);

                    $clinic->money -= $appointment->paid_price;
                    $clinic->save();
                } catch (\Exception $e) {
                    Log::error("Stripe refund failed for appointment ID {$appointment->id}: " . $e->getMessage());
                }
            }

            $appointment->status = 'cancelled';
            $appointment->save();
        }

        $patients = $appointments->pluck('patient.user')->all();


        foreach ($patients as $patient) {
            if ($patient->user->fcm_token) {
                foreach ($appointments as $appointment) {
                    if ($appointment->patient->id == $patient->id) {
                        $this->firebaseService->sendNotification($patient->user->fcm_token, 'sorry, your appointment canceled, the doctor will not be available ',  'date ' . $appointment->reservation_date,);
                    }
                }
            }
        }
        return response()->json([
            'message' => 'Schedule successfully deleted. Appointments canceled and refunds processed (if applicable).',
        ], 200);
    }
    /////
    public function showDoctorReviews(Request $request)
    {

        if ($auth = $this->auth()) {
            return $auth;
        }
        $user = Auth::user();

        $doctor = Doctor::where('user_id', $user->id)->first();

        if (!$doctor) return response()->json(['message' => 'Doctor Not Found'], 404);

        $reviews = PatientReview::with(['review', 'patient'])->where('doctor_id', $doctor->id);

        $response = $this->paginateResponse($request, $reviews, 'Reviews', function ($patientReview) {

            if (!$patientReview->review) {
                return null;
            }
            return [
                'patient_id' => $patientReview->patient_id,
                'patient_first_name' => $patientReview->patient->first_name,
                'patient_last_name' => $patientReview->patient->last_name,
                'rate' => $patientReview->review->rate,
                'comment' => $patientReview->review->comment,
            ];
        });

        $response['data'] = array_filter($response['data']);


        return response()->json($response, 200);
    }
    /////
    public function auth()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'message' => 'unauthorized'
            ], 401);
        }
        if ($user->role != 'doctor') {
            return response()->json([
                'message' => 'you dont have permission'
            ], 401);
        }
    }
}
