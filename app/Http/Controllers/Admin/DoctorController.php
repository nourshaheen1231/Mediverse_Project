<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientReview;
use App\Models\Review;
use App\Models\User;
use App\PaginationTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    use PaginationTrait; 

    public function showDoctors(Request $request) {
        $auth = $this->auth();
        if ($auth) return $auth;

        $query = Doctor::with('user');

        $paginatedData = $this->paginateResponse($request, $query, 'Doctors');

        if (isset($paginatedData['data'])) {
            $paginatedData['data'] = collect($paginatedData['data'])->map(function($doctor) {
                return [
                    'id' => $doctor->id,
                    'first_name' => $doctor->first_name,
                    'last_name' => $doctor->last_name,
                    'clinic_id' => $doctor->clinic_id,
                    'photo' => $doctor->photo,
                    'speciality' => $doctor->speciality,
                    'finalRate' => $doctor->finalRate,
                    'visit_fee' => $doctor->visit_fee,
                    'treated' => $doctor->treated,
                    'professional_title' => $doctor->professional_title,
                    'average_visit_duration' => $doctor->average_visit_duration,
                    'experience' => $doctor->experience,
                    'status' => $doctor->status,
                    'phone' => optional($doctor->user)->phone,
                    'email' => optional($doctor->user)->email,
                ];
            })->toArray();
        }

        return response()->json($paginatedData, 200);
    }   


    public function showDoctorDetails(Request $request) {
        $auth = $this->auth();
        if($auth) return $auth;

        $doctor = Doctor::with('user')->where('id', $request->doctor_id)->first();
        if(!$doctor) return response()->json(['message'=> 'Not Found'], 404);

        $response = [
                'id' => $doctor->id,
                'first_name' => $doctor->first_name,
                'last_name' => $doctor->last_name,
                'clinic_id' => $doctor->clinic_id,
                'photo' => $doctor->photo,
                'speciality' => $doctor->speciality,
                'finalRate' => $doctor->finalRate,
                'visit_fee' => $doctor->visit_fee,
                'treated' => $doctor->treated,
                'professional_title' => $doctor->professional_title,
                'average_visit_duration' => $doctor->average_visit_duration,
                'experience' => $doctor->experience,
                'treated' => $doctor->treated,
                'status' => $doctor->status,
                'phone' =>  optional($doctor->user)->phone,
                'email' => optional($doctor->user)->email,
            ];

        return response()->json($response, 200);
    }

    public function addDoctor(Request $request)  {
        $auth = $this->auth();
        if($auth) return $auth;
        
        $validator = Validator::make($request->all(), [
            'clinic_id' => 'required|numeric',
            'first_name' => 'string|required',
            'last_name' => 'string|required',
            'email' => 'string|email|max:255|required|unique:users',
            'phone' => 'phone:SY|unique:users',
            'password' => ['required', 'string', 'min:8', 'regex:/[0-9]/', 'regex:/[a-z]/', 'regex:/[A-Z]/',],
            'average_visit_duration' =>  ['required', Rule::in(['10 min', '15 min', '20 min', '30 min', '60 min'])],
            'visit_fee' => 'required|numeric',
        ], [
            'phone.phone' => 'enter a valid syrian phone number' ,
            'phone.unique' => 'this phone has already been taken'
        ]);

        if ($validator->fails()) {
            return response()->json([
               'message' =>  $validator->errors()->all()
            ], 400);
        }

        $clinic = Clinic::where('id',$request->clinic_id)->first();
        if(!$clinic) return response()->json(['message' => 'clinic not found'], 404);

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->get('password')),
            'role' => 'doctor',
        ]);

        $user->save();

        $doctor = Doctor::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'user_id' => $user->id,
            'clinic_id' => $clinic->id,
            'average_visit_duration' => $request->average_visit_duration,
            'visit_fee' => $request->visit_fee,
        ]);

        $clinic->numOfDoctors += 1;
        $clinic->save();

        return response()->json([
            'message' => 'created',
            'data' => $doctor,
        ],201);

    }

    public function removeDoctor(Request $request) {
        $auth = $this->auth();
        if($auth) return $auth;

        $doctor = Doctor::where('id', $request->doctor_id)->first();
        if(!$doctor) return response()->json(['message'=> 'doctor not found'], 404);
        $clinic = Clinic::where('id', $doctor->clinic_id)->first();
        $user = User::where('id',$doctor->user_id)->first();

        $doctor->delete();
        $user->delete();

        $clinic->numOfDoctors -= 1;
        $clinic->save();

        return response()->json(['message' => 'deleted successfully'], 200);
    }

    public function showDoctorReviews(Request $request) {
        $auth = $this->auth();
        if ($auth) return $auth;

        $doctor = Doctor::find($request->doctor_id);
        if (!$doctor) {
            return response()->json(['message' => 'doctor not found'], 404);
        }

        $query = PatientReview::with('review')
        ->where('doctor_id', $request->doctor_id);

        $paginatedData = $this->paginateResponse($request, $query, 'Doctor Reviews');

        // if (isset($paginatedData['data'])) {
        //     $paginatedData['data'] = collect($paginatedData['data'])->map(function($patientReview) {
        //         return [
        //             'patient_id' => $patientReview->patient_id,
        //             'review_id' => $patientReview->review->id ?? null,
        //             'rate' => $patientReview->review->rate ?? null,
        //             'comment' => $patientReview->review->comment ?? null,
        //         ];
        //     })->toArray();
        // }

        return response()->json($paginatedData, 200);
    }


    public function getReviewById(Request $request) {
        $auth = $this->auth();
        if($auth) return $auth;

        $review = PatientReview::with('review')->where('review_id', $request->review_id)->first();
        if(!$review) return response()->json(['message' => 'review not found'], 404);

        return response()->json([
            'patient_id' => $review->patient_id,
            'doctor_id' => $review->doctor_id,
            'review_id' => $review->review_id,
            'rate' => $review->review->rate,
            'comment' => $review->review->comment,
        ]);

    }

    public function deleteReview(Request $request) {
        $auth = $this->auth();
        if($auth) return $auth;

        $patient_review = PatientReview::with('review')->where('review_id', $request->review_id)->first();
        if(!$patient_review) return response()->json(['message' => 'review not found'], 404);

        $review = $patient_review->review;

        $patient_review->delete();
        $review->delete();

        return response()->json(['message' => 'comment deleted successfully'], 200);

    }

    public function auth() {
        $user = Auth::user();
        if (!$user) {
            return response()->json([
                'message' => 'unauthorized'
            ], 401);
        }
        if ($user->role != 'admin') {
            return response()->json(['message' => 'You do not have permission in this page'], 400);
        }
    }
}
