<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventService;
use App\Services\SnapshotService;
use App\Support\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /api/events — list (any user) and create / update / delete (Administrator). */
class EventController extends Controller
{
    public function __construct(private EventService $events)
    {
    }

    public function index(SnapshotService $snapshots): JsonResponse
    {
        return $this->json(['events' => $snapshots->forUser($this->user())['events']]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->json(['event_ID' => $this->events->save(null, $request->json()->all(), $this->user()->user_ID)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->json(['event_ID' => $this->events->save(Validator::id($id), $request->json()->all(), $this->user()->user_ID)]);
    }

    public function destroy(string $id): Response
    {
        $this->events->delete(Validator::id($id));
        return response()->noContent();
    }
}
