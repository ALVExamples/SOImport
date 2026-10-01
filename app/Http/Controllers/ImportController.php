<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChunkRequest;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Jobs\ImportLeadsJob;
use App\Models\Import;
use App\Services\Import\ChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function page(): View
    {
        return view('imports');
    }

    public function index(): AnonymousResourceCollection
    {
        return ImportResource::collection(Import::latest('id')->limit(10)->get());
    }

    public function show(Import $import): ImportResource
    {
        return new ImportResource($import);
    }

    public function chunk(StoreChunkRequest $request, ChunkedUploadService $upload): Response
    {
        $upload->storeChunk(
            $request->validated('upload_id'),
            (int) $request->validated('index'),
            $request->file('chunk'),
        );

        return response()->noContent();
    }

    public function store(StoreImportRequest $request, ChunkedUploadService $upload): JsonResponse
    {
        $path = $upload->assemble($request->validated('upload_id'), (int) $request->validated('chunks'));

        $import = Import::create([
            'original_name' => $request->validated('name'),
            'path' => $path,
        ]);

        ImportLeadsJob::dispatch($import->id);

        return (new ImportResource($import))->response()->setStatusCode(201);
    }
}
