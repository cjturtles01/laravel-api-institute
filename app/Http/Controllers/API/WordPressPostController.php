<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WordPressPost;
use Illuminate\Http\Request;
use App\Models\UsersModel;
use Illuminate\Support\Facades\DB;

class WordPressPostController extends Controller
{
    // READ ALL COURSES CATALOG
    public function course_catalog()
    {
        return WordPressPost::where('post_status', 'publish')
            ->where('post_type', 'post')
            ->get();
    }

    // READ SINGLE USER & COURSE INFO
    public function user_course_info($id)
    {
        // 1. Fetch user with only the needed fields
        $user = UsersModel::select('ID', 'user_login', 'user_nicename', 'user_email', 'display_name')
            ->findOrFail($id);

        $userId = $user->ID;

        // 2. Get LearnDash activity records for this user
        $activityRecords = DB::connection('wordpress')
            ->table('learndash_user_activity')
            ->where('user_id', $userId)
            ->get();

        // 3. Extract unique course (post) IDs and activity IDs
        $courseIds = $activityRecords->pluck('post_id')->unique()->values();
        $activityIds = $activityRecords->pluck('activity_id')->unique()->values();

        // 4. Fetch course titles from wpwz_posts table
        $courses = DB::connection('wordpress')
            ->table('posts')
            ->whereIn('ID', $courseIds)
            ->pluck('post_title', 'ID')
            ->map(function ($title, $id) {
                return [
                    'course_id' => $id,
                    'course_title' => $title,
                ];
            })
            ->values(); // reset index

        // 5. Fetch meta info for the matched activity IDs
        $activityMeta = DB::connection('wordpress')
            ->table('learndash_user_activity_meta')
            ->whereIn('activity_id', $activityIds)
            ->get();

        $data = json_decode($activityMeta, true); // decode as associative array
        // 5a. Filter meta rows with key == 'steps_completed'
        $stepsCompletedMeta = array_filter($data, function ($item) {
            return $item['activity_meta_key'] === 'steps_completed';
        });

        // 5b. Get related activity_ids from those meta rows
        $matchedActivityIds = array_column($stepsCompletedMeta, 'activity_id');

        // 5c. Match these with activity records to find post_ids
        $matchedActivityRecords = $activityRecords->whereIn('activity_id', $matchedActivityIds);

        // 5d. Extract post_ids from those matched activity records
        $completedCoursePostIds = $matchedActivityRecords->pluck('post_id')->unique()->values();

        // 5e. Fetch completed course titles
        $completedCourses = DB::connection('wordpress')
            ->table('posts')
            ->whereIn('ID', $completedCoursePostIds)
            ->pluck('post_title', 'ID')
            ->map(function ($title, $id) {
                return [
                    'course_id' => $id,
                    'course_title' => $title,
                ];
            })
            ->values();


        // 6. Build and return structured response
        return [
            'user_login'    => $user->user_login,
            'user_nicename' => $user->user_nicename,
            'user_email'    => $user->user_email,
            'display_name'  => $user->display_name,
            'courses_taken'          => $courses,
            'completed_courses' => $completedCourses,
            // 'learndash_activity'     => $activityRecords,
        ];
    }

    public function user_reg_info($id)
    {

        $meta = DB::connection('wordpress')
            ->table('usermeta')
            ->where('user_id', 1715)
            ->whereIn('meta_key', [
                'billing_first_name',
                'billing_last_name',
                'billing_email',
                'billing_company',
                'billing_country',
                'billing_billing_job_title',
                'billing_billing_professional_designation'
            ])
            ->pluck('meta_value', 'meta_key');

        return [

            'user_registration_info' => [
                'Firstname' => $meta['billing_first_name'] ?? null,
                'Lastname' => $meta['billing_last_name'] ?? null,
                'Email' => $meta['billing_email'] ?? null,
                'Country' => $meta['billing_country'] ?? null,
                'Company' => $meta['billing_company'] ?? null,
                'Title || Job Title' => $meta['billing_billing_job_title'] ?? null,
                'Professional Designation' => $meta['billing_billing_professional_designation'] ?? null,
            ]
        ];
    }


    // CREATE
    public function store(Request $request)
    {
        $data = $request->validate([
            'post_title' => 'required|string',
            'post_content' => 'nullable|string',
            'post_status' => 'required|string|in:publish,draft',
            'post_author' => 'required|integer',
        ]);

        $data['post_type'] = 'post';
        $data['post_date'] = now();

        $post = WordPressPost::create($data);

        return response()->json($post, 201);
    }

    // UPDATE
    public function update(Request $request, $id)
    {
        $post = WordPressPost::findOrFail($id);

        $data = $request->validate([
            'post_title' => 'sometimes|required|string',
            'post_content' => 'nullable|string',
            'post_status' => 'sometimes|required|string|in:publish,draft',
        ]);

        $post->update($data);

        return response()->json($post);
    }

    // DELETE
    public function destroy($id)
    {
        $post = WordPressPost::findOrFail($id);
        $post->delete();

        return response()->json(['message' => 'Post deleted']);
    }
}
