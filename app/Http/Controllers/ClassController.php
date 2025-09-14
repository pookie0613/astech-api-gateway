<?php

namespace App\Http\Controllers;

use App\Models\ClassModel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ClassController extends Controller
{
    public function index(): JsonResponse
    {
        $classes = ClassModel::with('course')->get();
        return response()->json($classes);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'class_name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'course_id' => 'required|exists:courses,id'
        ]);

        $class = ClassModel::create($request->all());
        return response()->json($class, 201);
    }

    public function show(ClassModel $class): JsonResponse
    {
        $class->load('course');
        return response()->json($class);
    }

    public function update(Request $request, ClassModel $class): JsonResponse
    {
        $request->validate([
            'class_name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'course_id' => 'required|exists:courses,id'
        ]);

        $class->update($request->all());
        return response()->json($class);
    }

    public function destroy(ClassModel $class): JsonResponse
    {
        $class->delete();
        return response()->json(null, 204);
    }
}
