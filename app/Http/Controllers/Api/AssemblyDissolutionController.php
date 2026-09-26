<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class AssemblyDissolutionController extends Controller
{
    /**
     * @throws \JsonException
     */
    public function __invoke(): JsonResponse
    {
        $path = resource_path(
            'data/assembly_dissolution_2026.json'
        );

        if (!File::exists($path)) {
            return response()->json([
                'message' => 'Assembly dissolution data is unavailable.',
            ], 404);
        }

        $data = json_decode(
            File::get($path),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        return response()->json($data);
    }
}
