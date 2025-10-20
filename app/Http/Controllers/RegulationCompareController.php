<?php

namespace App\Http\Controllers;

use App\Models\RegulationCompare;
use App\Models\ApiLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Spatie\PdfToText\Pdf;
use Ramsey\Uuid\Uuid;
use Illuminate\Support\Facades\DB;

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

        $oldUrl = $request->old_url;
        $newUrl = $request->new_url;

         // Must end with .pdf
        if (!str_ends_with(strtolower(parse_url($oldUrl, PHP_URL_PATH)), '.pdf') || !str_ends_with(strtolower(parse_url($newUrl, PHP_URL_PATH)), '.pdf')) {
            return response()->json([
                'message' => 'Both old_url and new_url must be PDF files (.pdf).',
            ], 422);
        }

         // Must come from jdih.kemenkoinfra.go.id
        $allowedDomain = 'jdih.kemenkoinfra.go.id';
        $oldHost = parse_url($oldUrl, PHP_URL_HOST);
        $newHost = parse_url($newUrl, PHP_URL_HOST);

        if ($oldHost !== $allowedDomain || $newHost !== $allowedDomain) {
            return response()->json([
                'message' => 'Both URLs must be from https://jdih.kemenkoinfra.go.id domain.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $existingCompare = RegulationCompare::where('old_url', $request->old_url)
                ->where('new_url', $request->new_url)
                ->first();

            if ($existingCompare) {
                return response()->json([
                    'message' => 'Comparison for these URL already exists',
                    'data' => [
                        'uuid' => $existingCompare->uuid,
                        'title' => $existingCompare->title,
                    ]
                ], 400);
            }

            $oldPath = storage_path('app/old.pdf');
            $newPath = storage_path('app/new.pdf');
            
            file_put_contents($oldPath, Http::get($request->old_url)->body());
            file_put_contents($newPath, Http::get($request->new_url)->body());
            
            $binaryPath = '/usr/local/bin/pdftotext';

            $oldText = (new Pdf($binaryPath))
                ->setPdf($oldPath)

                ->text();

            $newText = (new Pdf($binaryPath))
                ->setPdf($newPath)
                ->setOptions(['layout'])
                ->text();

            $oldText = Pdf::getText($oldPath, $binaryPath, ['layout']);
            $newText = Pdf::getText($newPath, $binaryPath, ['layout']);

            $oldText = $this->cleanText($oldText);
            $newText = $this->cleanText($newText);

            $oldText = $this->normalizeText($oldText);
            $newText = $this->normalizeText($newText);

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

            $uuid = Uuid::uuid4()->toString();

            $compare = RegulationCompare::create([
                'uuid' => $uuid,
                'title' => $request->title,
                'old_url' => $request->old_url,
                'new_url' => $request->new_url,
                'meta' => $meta,
                'summary' => $summary,
                'changes' => $changes,
            ]);

            $response = [
                'message' => 'Comparison created successfully',
                'data' => $compare,
            ];

            ApiLog::create([
                'endpoint' => $request->path(),
                'method' => $request->method(),
                'request_body' => $request->all(),
                'response_body' => $response,
                'http_status' => 201,
            ]);

            DB::commit();

            return response()->json($response, 201);

        } catch (\Exception $e) {

            DB::rollBack(); 

            return response()->json([
                'message' => 'Error processing comparison',
                'error' => $e->getMessage(),
            ], 500);

            ApiLog::create([
                'endpoint' => $request->path(),
                'method' => $request->method(),
                'request_body' => $request->all(),
                'response_body' => ['message' => 'Error processing comparison', 'error' => $e->getMessage()],
                'http_status' => 500,
            ]);
        }
    }

    public function show($uuid)
    {
        $compare = RegulationCompare::where('uuid', $uuid)->first();

        if (!$compare) {
            return response()->json(['message' => 'Data not found'], 404);
        }

        return response()->json([
            'id'      => $compare->id,
            'uuid'    => $compare->uuid,
            'title'   => $compare->title,
            'meta'    => $compare->meta,
            'summary' => $compare->summary,
            'changes' => $compare->changes,
        ]);
    }

    public function destroy($uuid)
    {
        $compare = RegulationCompare::where('uuid', $uuid)->first();

        if (!$compare) {
            return response()->json([
                'message' => 'Data not found'
            ], 404);
        }

        $compare->delete();

        return response()->json([
            'message' => 'Comparison deleted successfully',
            'uuid' => $uuid
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

    private function cleanText(string $text): string
    {
        $text = preg_replace('/\s*\.\w*\s*-\d+-\s*/', ' ', $text);
        $text = preg_replace('/Halaman\s+\d+/i', '', $text);
        $text = preg_replace('/jdih\.[a-z]+\.[a-z]+/i', '', $text);
        $text = preg_replace('/(\w+)-\s*\n\s*(\w+)/u', '$1$2', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        return trim($text);
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace('/(?<!\n)(Pasal\s+\d+[A-Za-z]?)/i', "\n\n$1", $text);
        $text = preg_replace('/(?<!\n)(ayat\s*\(\d+\))/i', "\n$1", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        return trim($text);
    }



}
