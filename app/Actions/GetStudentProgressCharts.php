<?php

namespace App\Actions;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class GetStudentProgressCharts
{
    private const TOP_STUDENTS_LIMIT = 5;

    /**
     * @return array{
     *     distribution: array{none: int, started: int, developing: int, strong: int, nearly_complete: int, complete: int},
     *     top_students: array<int, array{student_id: int, student_name: string, room_name: ?string, percentage: float}>
     * }
     */
    public function __invoke(int $schoolId, int $yearId, ?array $roomIds = null): array
    {
        $distribution = [
            'none' => 0,
            'started' => 0,
            'developing' => 0,
            'strong' => 0,
            'nearly_complete' => 0,
            'complete' => 0,
        ];

        if ($roomIds === []) {
            return [
                'distribution' => $distribution,
                'top_students' => [],
            ];
        }

        $studentsQuery = DB::table('students')
            ->leftJoin('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'students.room_id')
                    ->on('rooms.school_id', '=', 'students.school_id')
                    ->on('rooms.year_id', '=', 'students.year_id');
            })
            ->where('students.school_id', $schoolId)
            ->where('students.year_id', $yearId)
            ->whereIn('students.status', ['active', 'paused']);

        if ($roomIds !== null) {
            $studentsQuery->whereIn('students.room_id', $roomIds);
        }

        $students = $studentsQuery
            ->orderBy('students.id')
            ->get([
                'students.id',
                'students.full_name',
                'rooms.name as room_name',
            ]);

        if ($students->isEmpty()) {
            return [
                'distribution' => $distribution,
                'top_students' => [],
            ];
        }

        $metadata = json_decode(
            file_get_contents(resource_path('data/quran-metadata.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $surahs = [];
        $nextSurahOffset = 0;

        foreach ($metadata['surahs'] as $surah) {
            $surahId = (int) $surah['id'];
            $ayahCount = (int) $surah['ayah_count'];
            $surahs[$surahId] = [
                'offset' => $nextSurahOffset,
                'ayah_count' => $ayahCount,
            ];
            $nextSurahOffset += $ayahCount;
        }

        $totalAyahs = (int) $metadata['meta']['total_ayahs'];
        $coveredAyahsByStudent = [];
        $currentStudentId = null;
        $mergedStart = null;
        $mergedEnd = null;
        $coveredAyahs = 0;

        $sessionsQuery = DB::table('memorization_sessions')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'memorization_sessions.student_id')
                    ->on('students.school_id', '=', 'memorization_sessions.school_id')
                    ->on('students.year_id', '=', 'memorization_sessions.year_id');
            })
            ->where('memorization_sessions.school_id', $schoolId)
            ->where('memorization_sessions.year_id', $yearId)
            ->whereIn('students.status', ['active', 'paused']);

        if ($roomIds !== null) {
            $sessionsQuery->whereIn('students.room_id', $roomIds);
        }

        $sessions = $sessionsQuery
            ->orderBy('memorization_sessions.student_id')
            ->orderBy('memorization_sessions.surah_start')
            ->orderBy('memorization_sessions.ayah_start')
            ->cursor([
                'memorization_sessions.student_id as student_id',
                'memorization_sessions.surah_start as surah_start',
                'memorization_sessions.ayah_start as ayah_start',
                'memorization_sessions.surah_end as surah_end',
                'memorization_sessions.ayah_end as ayah_end',
            ]);

        foreach ($sessions as $session) {
            $studentId = (int) $session->student_id;

            if ($currentStudentId !== $studentId) {
                $this->storeCoverage(
                    $currentStudentId,
                    $mergedStart,
                    $mergedEnd,
                    $coveredAyahs,
                    $coveredAyahsByStudent,
                );
                $currentStudentId = $studentId;
                $mergedStart = null;
                $mergedEnd = null;
                $coveredAyahs = 0;
            }

            $startSurah = $surahs[(int) $session->surah_start] ?? null;
            $endSurah = $surahs[(int) $session->surah_end] ?? null;
            $startAyah = (int) $session->ayah_start;
            $endAyah = (int) $session->ayah_end;

            if (
                $startSurah === null
                || $endSurah === null
                || $startAyah < 1
                || $startAyah > $startSurah['ayah_count']
                || $endAyah < 1
                || $endAyah > $endSurah['ayah_count']
            ) {
                continue;
            }

            $rangeStart = $startSurah['offset'] + $startAyah - 1;
            $rangeEnd = $endSurah['offset'] + $endAyah;

            if ($rangeStart > $rangeEnd) {
                continue;
            }

            if ($mergedStart === null || $rangeStart > $mergedEnd) {
                if ($mergedStart !== null) {
                    $coveredAyahs += $mergedEnd - $mergedStart;
                }

                $mergedStart = $rangeStart;
                $mergedEnd = $rangeEnd;

                continue;
            }

            $mergedEnd = max($mergedEnd, $rangeEnd);
        }

        $this->storeCoverage(
            $currentStudentId,
            $mergedStart,
            $mergedEnd,
            $coveredAyahs,
            $coveredAyahsByStudent,
        );

        $topStudents = [];

        foreach ($students as $student) {
            $studentId = (int) $student->id;
            $covered = min($totalAyahs, $coveredAyahsByStudent[$studentId] ?? 0);
            $percentage = $covered / $totalAyahs * 100;

            if ($covered > 0) {
                if ($percentage >= 100) {
                    $bucket = 'complete';
                } elseif ($percentage >= 75) {
                    $bucket = 'nearly_complete';
                } elseif ($percentage >= 50) {
                    $bucket = 'strong';
                } elseif ($percentage >= 25) {
                    $bucket = 'developing';
                } else {
                    $bucket = 'started';
                }

                $distribution[$bucket]++;
                $topStudents[] = [
                    'student_id' => $studentId,
                    'student_name' => $student->full_name,
                    'room_name' => $student->room_name,
                    'percentage' => round($percentage, 2),
                    'covered_ayahs' => $covered,
                ];

                continue;
            }

            $distribution['none']++;
        }

        usort($topStudents, fn (array $first, array $second) => $second['covered_ayahs'] <=> $first['covered_ayahs']
            ?: strcmp($first['student_name'], $second['student_name']));

        return [
            'distribution' => $distribution,
            'top_students' => array_map(
                static fn (array $student) => [
                    'student_id' => $student['student_id'],
                    'student_name' => $student['student_name'],
                    'room_name' => $student['room_name'],
                    'percentage' => $student['percentage'],
                ],
                array_slice($topStudents, 0, self::TOP_STUDENTS_LIMIT),
            ),
        ];
    }

    /**
     * @param  array<int, int>  $coverageByStudent
     */
    private function storeCoverage(
        ?int $studentId,
        ?int $rangeStart,
        ?int $rangeEnd,
        int $coveredAyahs,
        array &$coverageByStudent,
    ): void {
        if ($studentId === null) {
            return;
        }

        if ($rangeStart !== null && $rangeEnd !== null) {
            $coveredAyahs += $rangeEnd - $rangeStart;
        }

        $coverageByStudent[$studentId] = $coveredAyahs;
    }
}
