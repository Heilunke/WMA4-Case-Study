<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventService;
use App\Support\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /api/events - anyone logged in can view events, only the admin can add, edit or delete. */
class EventController extends Controller
{
    public function __construct(private EventService $events)
    {
    }

    /** GET /events - list of events with their ticket types and seats. Filters: q, category, date, from. */
    public function index(Request $request): JsonResponse
    {
        return $this->json(['events' => $this->events->catalog($request->query())]);
    }

    /** GET /events/{id} */
    public function show(string $id): JsonResponse
    {
        return $this->json(['event' => $this->events->find(Validator::id($id))]);
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
