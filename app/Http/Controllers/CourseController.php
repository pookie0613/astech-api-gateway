<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CourseController extends Controller
{
    public function index(): JsonResponse
    {
        $courses = Course::all();
        return response()->json($courses);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'course_name' => 'required|string|max:255|unique:courses'
        ]);

        $course = Course::create($request->all());
        return response()->json($course, 201);
    }

    public function show(Course $course): JsonResponse
    {
        return response()->json($course);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $request->validate([
            'course_name' => 'required|string|max:255|unique:courses,course_name,' . $course->id
        ]);

        $course->update($request->all());
        return response()->json($course);
    }

    public function destroy(Course $course): JsonResponse
    {
        $course->delete();
        return response()->json(null, 204);
    }
}
