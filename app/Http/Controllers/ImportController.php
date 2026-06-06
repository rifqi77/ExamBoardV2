<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserCredential;
use App\Services\CryptoSecrets;
use App\Services\StudentCredentials;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Roster import — port of /api/teacher/classes/import. The client parses
 * the .xlsx (ExcelJS) into { fileName, academicYear, classes: [{ name,
 * students: [{fullName, username, password}] }] }; this creates the
 * classes + users + credentials + class links, generating
 * usernames/passwords for blanks, and returns the created credentials.
 */
class ImportController extends Controller
{
    public function importClasses(Request $r)
    {
        $u = $r->attributes->get('authUser');
        $fileName = (string) $r->input('fileName', '(unknown)');
        $classesIn = (array) $r->input('classes', []);
        if (count($classesIn) === 0) {
            return response()->json(['error' => 'No classes in import.'], 400);
        }

        $academicYear = $this->parseAcademicYear($r->input('academicYear')) ?? $this->currentAcademicYear();
        $ownerId = $u->id;

        // Set of taken usernames (lowercased) for collision checks + the
        // username generator.
        $taken = [];
        foreach (User::pluck('username') as $name) {
            $taken[strtolower($name)] = true;
        }

        $classesCreated = 0;
        $classesUpdated = 0;
        $studentsCreated = 0;
        $skipped = [];
        $created = [];

        foreach ($classesIn as $ci) {
            $className = trim((string) ($ci['name'] ?? ''));
            if ($className === '') {
                $skipped[] = ['reason' => 'Class name missing', 'identifier' => '(unnamed sheet)'];
                continue;
            }

            $cls = DB::table('student_classes')
                ->where('name', $className)->where('academic_year', $academicYear)->where('created_by', $ownerId)
                ->first();
            if ($cls) {
                $classesUpdated++;
                $clsId = $cls->id;
                DB::table('student_classes')->where('id', $clsId)->update(['source_file_name' => $fileName]);
            } else {
                $clsId = (string) Str::uuid();
                DB::table('student_classes')->insert([
                    'id' => $clsId, 'name' => $className, 'academic_year' => $academicYear,
                    'source_file_name' => $fileName, 'created_by' => $ownerId, 'created_at' => now(),
                ]);
                $classesCreated++;
            }

            foreach ((array) ($ci['students'] ?? []) as $si) {
                $fullName = trim((string) ($si['fullName'] ?? ''));
                if ($fullName === '') {
                    $skipped[] = ['reason' => 'Full name missing', 'identifier' => '(blank row)'];
                    continue;
                }
                $username = trim((string) ($si['username'] ?? ''));
                if ($username === '') {
                    $username = StudentCredentials::generateUsernameFromName($fullName, $taken);
                }
                if (isset($taken[strtolower($username)])) {
                    $skipped[] = ['reason' => 'Username already exists', 'identifier' => $username];
                    continue;
                }
                if (! preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
                    $skipped[] = ['reason' => 'Invalid username format', 'identifier' => $username];
                    continue;
                }
                $provided = trim((string) ($si['password'] ?? ''));
                $generated = $provided === '';
                $password = $generated ? StudentCredentials::generatePasswordFromName($fullName) : $provided;
                if (strlen($password) < 6 || strlen($password) > 64) {
                    $skipped[] = ['reason' => 'Password length must be 6-64', 'identifier' => $username];
                    continue;
                }

                try {
                    DB::transaction(function () use ($username, $fullName, $password, $ownerId, $u, $clsId) {
                        $uid = (string) Str::uuid();
                        User::create([
                            'id' => $uid, 'username' => $username, 'full_name' => $fullName,
                            'role' => 'student', 'active' => true, 'created_by' => $ownerId,
                        ]);
                        UserCredential::create([
                            'user_id' => $uid,
                            'password_hash' => Hash::make($password),
                            'password_plain' => CryptoSecrets::encryptStudentPassword($password),
                            'password_set_by' => $u->id,
                            'password_set_at' => now(),
                            'failed_attempts' => 0,
                        ]);
                        DB::table('class_students')->insert([
                            'id' => (string) Str::uuid(), 'class_id' => $clsId,
                            'student_identifier' => $uid, 'student_name' => $fullName, 'created_at' => now(),
                        ]);
                    });
                    $taken[strtolower($username)] = true;
                    $studentsCreated++;
                    $created[] = [
                        'className' => $className, 'fullName' => $fullName, 'username' => $username,
                        'password' => $password, 'passwordWasGenerated' => $generated,
                    ];
                } catch (QueryException $e) {
                    $skipped[] = ['reason' => 'Username already exists', 'identifier' => $username];
                }
            }
        }

        return response()->json([
            'classesCreated' => $classesCreated,
            'classesUpdated' => $classesUpdated,
            'studentsCreated' => $studentsCreated,
            'studentsSkipped' => $skipped,
            'createdStudents' => $created,
        ]);
    }

    private function currentAcademicYear(): string
    {
        $y = (int) date('Y');
        $m = (int) date('n');
        return $m >= 7 ? ($y.'/'.($y + 1)) : (($y - 1).'/'.$y);
    }

    private function parseAcademicYear($s): ?string
    {
        if (! is_string($s)) {
            return null;
        }
        if (preg_match('/^(\d{4})\/(\d{4})$/', trim($s), $m) && (int) $m[2] === (int) $m[1] + 1) {
            return $m[1].'/'.$m[2];
        }
        return null;
    }
}
