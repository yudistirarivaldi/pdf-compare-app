<?php

namespace App\Http\Controllers;

use App\Models\RegulationCompare;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Spatie\PdfToText\Pdf;

class RegulationCompareController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'old_url' => 'required|url',
            'new_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $oldPath = storage_path('app/old.pdf');
            $newPath = storage_path('app/new.pdf');

            file_put_contents($oldPath, Http::get($request->old_url)->body());
            file_put_contents($newPath, Http::get($request->new_url)->body());

            $oldText = Pdf::getText($oldPath);
            $newText = Pdf::getText($newPath);

            $oldPasal = $this->splitByPasal($oldText);
            $newPasal = $this->splitByPasal($newText);

            $changes = [];
            $added = $removed = $modified = 0;

            foreach ($newPasal as $key => $newContent) {
                if (!isset($oldPasal[$key])) {
                    $changes[] = [
                        'pasal' => $key,
                        'status' => 'added',
                        'old_text' => null,
                        'new_text' => $newContent,
                    ];
                    $added++;
                } elseif (trim($oldPasal[$key]) !== trim($newContent)) {
                    $changes[] = [
                        'pasal' => $key,
                        'status' => 'modified',
                        'old_text' => $oldPasal[$key],
                        'new_text' => $newContent,
                    ];
                    $modified++;
                }
            }

            foreach ($oldPasal as $key => $oldContent) {
                if (!isset($newPasal[$key])) {
                    $changes[] = [
                        'pasal' => $key,
                        'status' => 'removed',
                        'old_text' => $oldContent,
                        'new_text' => null,
                    ];
                    $removed++;
                }
            }

            $meta = [
                'old_title' => basename($request->old_url),
                'new_title' => basename($request->new_url),
            ];

            $summary = [
                'added' => $added,
                'removed' => $removed,
                'modified' => $modified,
            ];

            $compare = RegulationCompare::create([
                'title' => $request->title,
                'old_url' => $request->old_url,
                'new_url' => $request->new_url,
                'meta' => $meta,
                'summary' => $summary,
                'changes' => $changes,
            ]);

            return response()->json([
                'message' => 'Comparison created successfully',
                'data' => $compare,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error processing comparison',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $compare = RegulationCompare::find($id);

        if (!$compare) {
            return response()->json(['message' => 'Data not found'], 404);
        }

        return response()->json([
            'title'   => $compare->title,
            'meta'    => $compare->meta,
            'summary' => $compare->summary,
            'changes' => $compare->changes,
        ]);
    }

    public function destroy($id)
    {
        $compare = RegulationCompare::find($id);

        if (!$compare) {
            return response()->json([
                'message' => 'Data not found'
            ], 404);
        }

        $compare->delete();

        return response()->json([
            'message' => 'Comparison deleted successfully',
            'id' => $id
        ], 200);
    }

    private function splitByPasal(string $text): array
    {
        $pattern = '/(?=Pasal\s+\d+[A-Za-z]?(?:\s+ayat\s*\(\d+\))?)/i';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);

        $result = [];
        foreach ($parts as $part) {
            if (preg_match('/Pasal\s+\d+[A-Za-z]?(?:\s+ayat\s*\(\d+\))?/i', $part, $match)) {
                $result[trim($match[0])] = trim(str_replace($match[0], '', $part));
            }
        }

        return $result;
    }
}
