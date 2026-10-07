<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class NotebookWebController extends Controller
{
    /**
     * Render the Notebook AI workspace page.
     */
    public function index(Request $request): View
    {
        return view('notebook.index', [
            'meetingId' => $request->query('meeting_id'),
            'coursesId' => $request->query('courses_id', $request->query('course_id')),
            'initialAction' => $request->query('action'),
            'notebookId' => $request->query('notebook_id'),
            'hasDefaultApiKey' => ! empty(config('gemini.api_key')),
        ]);
    }
}
