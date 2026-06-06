<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Learning objectives (curriculum map). Backs the AI topic picker and the
 * auto-fill topic ordering. Teachers manage their own rows; admin sees all.
 */
class LearningObjectivesController extends Controller
{
    /** The DB enum for curriculum + friendly labels. */
    public const CURRICULA = ['kurikulum_merdeka', 'as_a_level', 'ib', 'olympiad'];

    private function normalizeCurriculum($value): ?string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }
        $v = str_replace([' ', '-'], '_', $v);
        if (in_array($v, self::CURRICULA, true)) {
            return $v;
        }
        // Friendly aliases.
        $aliases = [
            'cambridge' => 'as_a_level', 'a_level' => 'as_a_level', 'as' => 'as_a_level', 'as_a' => 'as_a_level',
            'merdeka' => 'kurikulum_merdeka', 'kurmer' => 'kurikulum_merdeka',
            'olimpiade' => 'olympiad', 'olympiads' => 'olympiad',
            'ib_dp' => 'ib', 'international_baccalaureate' => 'ib',
        ];
        return $aliases[$v] ?? null;
    }

    private function scope($query, $user)
    {
        return $user->role === 'admin' ? $query : $query->where('uploaded_by', $user->id);
    }

    // GET /{role}/learning-objectives
    public function page(Request $request)
    {
        $u = $request->attributes->get('authUser');
        $rows = $this->scope(DB::table('learning_objectives'), $u)
            ->orderBy('curriculum')->orderBy('subject')->orderBy('sort_order')->orderBy('topic')
            ->get(['id', 'curriculum', 'subject', 'topic', 'subtopic', 'text', 'language']);

        $curricula = $this->scope(DB::table('learning_objectives'), $u)
            ->whereNotNull('curriculum')->where('curriculum', '!=', '')->distinct()->orderBy('curriculum')->pluck('curriculum')->all();

        return Inertia::render('Teacher/LearningObjectives', [
            'objectives' => $rows,
            'curricula' => $curricula,
            'basePath' => '/'.$u->role.'/learning-objectives',
        ]);
    }

    // POST /api/teacher/learning-objectives  — { rows: [...], sourceFileName? }
    public function upload(Request $request)
    {
        $u = $request->attributes->get('authUser');
        $rows = $request->input('rows');
        if (! is_array($rows) || count($rows) === 0) {
            return response()->json(['error' => 'Provide a non-empty "rows" array.'], 400);
        }
        if (count($rows) > 5000) {
            return response()->json(['error' => 'Too many rows (max 5000).'], 400);
        }
        $source = (string) $request->input('sourceFileName', 'upload');
        $imported = 0;
        $skipped = 0;
        $now = now();
        $batch = [];
        $order = 0;
        foreach ($rows as $r) {
            $topic = trim((string) ($r['topic'] ?? ''));
            $text = trim((string) ($r['text'] ?? ''));
            if ($topic === '' && $text === '') {
                $skipped++;
                continue;
            }
            $batch[] = [
                'id' => (string) Str::uuid(),
                'curriculum' => $this->normalizeCurriculum($r['curriculum'] ?? ($request->input('curriculum'))) ?? 'kurikulum_merdeka',
                'language' => trim((string) ($r['language'] ?? ($request->input('language') ?? ''))),
                'subject' => trim((string) ($r['subject'] ?? ($request->input('subject') ?? ''))),
                'topic' => $topic ?: '(untitled)',
                'subtopic' => trim((string) ($r['subtopic'] ?? '')) ?: null,
                'text' => $text,
                'sort_order' => $order++,
                'created_by' => $u->id,
                'created_by_name' => $u->full_name,
                'uploaded_by' => $u->id,
                'uploaded_by_name' => $u->full_name,
                'source_file_name' => $source,
                'created_at' => $now,
            ];
            $imported++;
        }
        if ($batch) {
            foreach (array_chunk($batch, 500) as $chunk) {
                DB::table('learning_objectives')->insert($chunk);
            }
        }
        return response()->json(['imported' => $imported, 'skipped' => $skipped]);
    }

    // POST /api/teacher/learning-objectives/{id}/delete
    public function destroy(Request $request, string $id)
    {
        $u = $request->attributes->get('authUser');
        $row = DB::table('learning_objectives')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['error' => 'Not found.'], 404);
        }
        if ($u->role !== 'admin' && $row->uploaded_by !== $u->id) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }
        DB::table('learning_objectives')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    // POST /api/teacher/learning-objectives/bulk-delete  — { ids: [] }
    public function bulkDelete(Request $request)
    {
        $u = $request->attributes->get('authUser');
        $ids = $request->input('ids', []);
        if (! is_array($ids) || count($ids) === 0) {
            return response()->json(['error' => 'ids required.'], 400);
        }
        $q = DB::table('learning_objectives')->whereIn('id', $ids);
        if ($u->role !== 'admin') {
            $q->where('uploaded_by', $u->id);
        }
        $deleted = $q->delete();
        return response()->json(['deleted' => $deleted]);
    }
}
