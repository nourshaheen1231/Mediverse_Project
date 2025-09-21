<?php

namespace Database\Seeders;

use App\Models\Clinic;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Doctor;
use Illuminate\Support\Facades\Hash;

class DoctorSeeder extends Seeder
{
    public function run()
    {
        $doctorUsers = User::where('role', 'doctor')->get();
        $clinics = Clinic::all();
        $clinicCount = $clinics->count();
        $index = 0;


        foreach ($doctorUsers as $user) {
            $clinicId = $clinics[$index % $clinicCount]->id;
            Doctor::create([
                'first_name'             => $user->first_name ?? 'Doctor',
                'last_name'              => $user->last_name ?? 'Unknown',
                'user_id'                => $user->id,
                'photo'                  => '/storage/images/doctors/profiles/' . $user->first_name . '.png',
                'clinic_id'              => $clinicId,
                'speciality'             => 'General',
                'professional_title'     => 'MD',
                'finalRate'              => rand(3, 5),
                'average_visit_duration' => '20 min',
                'visit_fee'              => rand(50, 150),
                'sign'                   => '/storage/images/doctors/signs/' . rand(1, 11) . '.jpeg',
                'experience'             => rand(1, 20),
                'treated'                => rand(0, 500),
                'status'                 => 'available',
                'booking_type' => ['manual', 'auto'][rand(0, 1)],
            ]);
            $index++;
        }
    }
}
