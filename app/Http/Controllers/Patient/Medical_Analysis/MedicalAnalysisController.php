<?php

namespace App\Http\Controllers\Patient\Medical_Analysis;

use App\Http\Controllers\Controller;
use App\Models\Analyse;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use App\PaginationTrait;

class MedicalAnalysisController extends Controller
{
    use PaginationTrait;

    public function showAnalysis(Request $request)
    {
        $user = Auth::user();

        // Check the auth
        if (!$user) {
            return response()->json([
                'message' => 'unauthorized'
            ], 401);
        }

        if ($user->role != 'patient') {
            return response()->json([
                'message' => 'you dont have permission'
            ], 401);
        }

        if ($request->has('child_id')) {
            $patient = Patient::where('id', $request->child_id)->first();
        } else {
            $patient = Patient::where('user_id', $user->id)->first();
        }

        if (!$patient) return response()->json(['message' => 'Patient Not Found'], 404);

        $analysis = Analyse::where('patient_id', $patient->id)
            ->select(
                'id',
                'name',
                'description',
                'result_file',
                'result_photo',
                'status'
            );

        $response = $this->paginateResponse($request, $analysis, 'Analysis', function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'result_file' => $item->result_file,
                'result_photo' => $item->result_photo,
                'status' => $item->status,
            ];
        });

        return response()->json($response, 200);
    }


    public function filteringAnalysis(Request $request)
    {
        $user = Auth::user();

        // Check the auth
        if (!$user) {
            return response()->json([
                'message' => 'unauthorized'
            ], 401);
        }

        if ($user->role != 'patient') {
            return response()->json([
                'message' => 'you dont have permission'
            ], 401);
        }

        if ($request->has('child_id')) {
            $patient = Patient::where('id', $request->child_id)->first();
        } else {
            $patient = Patient::where('user_id', $user->id)->first();
        }

        if (!$patient) return response()->json(['message' => 'Patient Not Found'], 404);

        $analysis = Analyse::where('patient_id', $patient->id)
            ->where('status', $request->status)
            ->select(
                'id',
                'name',
                'description',
                'result_file',
                'result_photo',
                'status'
            );

        $response = $this->paginateResponse($request, $analysis, 'Analysis', function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'result_file' => $item->result_file,
                'result_photo' => $item->result_photo,
                'status' => $item->status,
            ];
        });

        return response()->json($response, 200);
    }



    public function addAnalysis(Request $request)
    {
        $user = Auth::user(); // 

        //check the auth
        if (!$user) {
            return response()->json([
                'message' => 'unauthorized'
            ], 401);
        }

        if ($user->role != 'patient') {
            return response()->json([
                'message' => 'you dont have permission'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|required',
            'description' => 'string',
            'result_file' => 'file|required_without:result_photo',
            'result_photo' => 'image|required_without:result_file',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' =>  $validator->errors()->all()
            ], 422);
        }

        if ($request->has('child_id')) {
            $patient = Patient::where('id', $request->child_id)->first();
        } else {
            $patient = Patient::where('user_id', $user->id)->first();
        }
        if (!$patient) return response()->json(['message' => 'Patient Not Found'], 404);

        $analyse = Analyse::create([
            'patient_id' => $patient->id,
            'name' => $request->name,
            'description' => $request->description,
            'status' => 'finished',
        ]);

        if ($request->hasFile('result_file')) {
            $file_path = $request->result_file->store('files/patients/analysis', 'public');
            $result_file = '/storage/' . $file_path;

            $analyse->update([
                'result_file' => $result_file
            ]);
            $analyse->save();
        }

        if ($request->hasFile('result_photo')) {
            $photo_path = $request->result_photo->store('files/patients/analysis', 'public');
            $result_photo = '/storage/' . $photo_path;

            $analyse->update([
                'result_photo' => $result_photo
            ]);
            $analyse->save();
        }

        return response()->json($analyse, 201);
    }

    public function deleteAnalysis(Request $request)
    {
        $user = Auth::user(); // 

        //check the auth
        if (!$user) {
            return response()->json([
                'message' => 'unauthorized'
            ], 401);
        }

        if ($user->role != 'patient') {
            return response()->json([
                'message' => 'you dont have permission'
            ], 401);
        }

        $analyse = Analyse::where('id', $request->analyse_id)->first();

        if (!$analyse) {
            return response()->json([
                'message' => 'Not Found'
            ], 404);
        }

        if ($analyse->result_photo) {
            $image_path = public_path($analyse->result_photo);
            if (File::exists($image_path)) File::delete($image_path);
        }
        if ($analyse->result_file) {
            $file_path = public_path($analyse->result_file);
            if (File::exists($file_path)) File::delete($file_path);
        }

        $analyse->delete();

        return response()->json(['message' => 'deleted successfully'], 200);
    }
}
