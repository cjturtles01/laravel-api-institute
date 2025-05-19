<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WordPressPost;
use Illuminate\Http\Request;
use App\Models\UsersModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WordPressPostController extends Controller
{
    // public function tester()
    // {
    //     $data = DB::connection('wordpress') // replace with your connection name
    //         ->table('learndash_user_activity')
    //         ->get();

    //     return $data;
    // }

    //Course Description
    public function course_description()
    {
        $results = DB::connection('wordpress')->select("
            SELECT 
                p.ID,
                p.post_title,
                REPLACE(
                    REPLACE(
                        SUBSTRING(
                            p.post_content,
                            LOCATE(
                                CASE 
                                    WHEN LOCATE('<h2>About this Course</h2>', p.post_content) > 0 
                                        THEN '<h2>About this Course</h2>'
                                    ELSE '<h2>About this Program</h2>'
                                END,
                                p.post_content
                            ) + LENGTH(
                                CASE 
                                    WHEN LOCATE('<h2>About this Course</h2>', p.post_content) > 0 
                                        THEN '<h2>About this Course</h2>'
                                    ELSE '<h2>About this Program</h2>'
                                END
                            ),
                            LOCATE('<a', p.post_content, 
                                LOCATE(
                                    CASE 
                                        WHEN LOCATE('<h2>About this Course</h2>', p.post_content) > 0 
                                            THEN '<h2>About this Course</h2>'
                                        ELSE '<h2>About this Program</h2>'
                                    END,
                                    p.post_content
                                )
                            ) - 
                            (LOCATE(
                                CASE 
                                    WHEN LOCATE('<h2>About this Course</h2>', p.post_content) > 0 
                                        THEN '<h2>About this Course</h2>'
                                    ELSE '<h2>About this Program</h2>'
                                END,
                                p.post_content
                            ) + LENGTH(
                                CASE 
                                    WHEN LOCATE('<h2>About this Course</h2>', p.post_content) > 0 
                                        THEN '<h2>About this Course</h2>'
                                    ELSE '<h2>About this Program</h2>'
                                END
                            ))
                        ),
                        '<p>', ''
                    ),
                    '</p>', ''
                ) AS course_description
            FROM 
                wpwz_posts p
            JOIN (
                SELECT DISTINCT post_title
                FROM wpwz_posts
                WHERE post_type = 'sfwd-courses'
                  AND post_status = 'publish'
            ) AS published_courses ON p.post_title = published_courses.post_title
            WHERE 
                (
                    p.post_content LIKE '%<h2>About this Course</h2>%<a%'
                    OR p.post_content LIKE '%<h2>About this Program</h2>%<a%'
                )
            GROUP BY p.post_title
        ");

        return response()->json($results);
    }

    //Number of users enrolled on courses
    public function enrolled_users()
    {
        // Run the query on the wordpress connection
        $results = DB::connection('wordpress')->select("
            SELECT 
                p.ID AS course_id,
                p.post_title,
                COUNT(DISTINCT a.user_id) AS enrolled_users
            FROM 
                wpwz_posts p
            LEFT JOIN 
                wpwz_learndash_user_activity a 
                ON p.ID = a.course_id 
                AND a.activity_type = 'course'
            /* INNER JOIN 
                wpwz_wc_customer_lookup c
                ON a.user_id = c.user_id */
            INNER JOIN 
                wpwz_users u 
                ON a.user_id = u.ID
            WHERE 
                p.post_type = 'sfwd-courses'
                AND p.post_status = 'publish'
            GROUP BY 
                p.ID, p.post_title
        ");

        // Return JSON with the key renamed to your preference
        $response = array_map(function ($item) {
            return [
                'course_id' => $item->course_id,
                'post_title' => $item->post_title,
                'unique_users || enrolled_users' => (int)$item->enrolled_users,
            ];
        }, $results);

        return response()->json($response);
    }

    // Each Course with User Info
    public function course_user_info($userId)
    {
        // Step 1: Get user details from wc_customer_lookup
        $userDetails = DB::connection('wordpress')
            ->table('wc_customer_lookup')
            ->where('user_id', $userId)
            ->get();

        // OR

        // $userDetails = DB::connection('wordpress')
        //     ->table('users')
        //     ->where('id', $userId)
        //     ->get();

        if (!$userDetails) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Step 2: Get course_ids from learndash_user_activity
        $courseIds = DB::connection('wordpress')
            ->table('learndash_user_activity')
            ->where('user_id', $userId)
            ->where('activity_type', 'course')
            ->pluck('course_id')
            ->unique()
            ->values();

        if ($courseIds->isEmpty()) {
            return response()->json(['message' => 'No courses found for this user.']);
        }

        // Step 3: Get post titles from posts table using course IDs
        $postTitles = DB::connection('wordpress')
            ->table('posts')
            ->whereIn('ID', $courseIds)
            ->pluck('post_title', 'ID');

        // Step 4: Merge course info with user data
        $result = [];

        foreach ($courseIds as $courseId) {
            $result[] = [
                'course_id'   => $courseId,
                'post_title'  => $postTitles[$courseId] ?? 'Unknown',
                'user_info'   => $userDetails
            ];
        }

        return response()->json($result);
    }

    // READ ALL COURSES CATALOG
    public function course_catalog()
    {
        $results = DB::connection('wordpress')->select("
        SELECT 
            ID,
            post_author,
            post_date,
            post_date_gmt,
            post_title,
            post_excerpt,
            post_status,
            comment_status,
            ping_status,
            post_password,
            post_name,
            to_ping,
            pinged,
            post_modified,
            post_modified_gmt,
            post_content_filtered,
            post_parent,
            guid,
            menu_order,
            post_type,
            post_mime_type,
            comment_count,
            REGEXP_REPLACE(
              REGEXP_REPLACE(post_content, '\\\\[.*?\\\\]', ''), 
              '<[^>]*>', ''
            ) AS post_content
        FROM 
            wpwz_posts
        WHERE 
            post_status = 'publish' 
            AND post_type = 'sfwd-courses'
    ");

        return response()->json($results);
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
            ->where('activity_type', "course")
            ->get();

        // 3. Extract unique course (post) IDs and activity IDs
        $courseIds = $activityRecords->pluck('post_id')->unique()->values();

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
        echo "courses" . $courses;

        // 1. Fetch ALL completed course activities for the user
        $completedActivities = DB::connection('wordpress')
            ->table('learndash_user_activity')
            ->where('user_id', $userId)
            ->where('activity_type', 'course')
            ->where('activity_status', 1) // ✅ This filters all completed
            ->get();

        // 2. Extract ALL post_ids from those activities
        $completedCoursePostIds = $completedActivities->pluck('post_id')->unique()->values();

        // 3. Fetch ALL matching course titles from wpwz_posts
        $completedCourseTitles = DB::connection('wordpress')
            ->table('posts')
            ->whereIn('ID', $completedCoursePostIds)
            ->pluck('post_title', 'ID')
            ->map(function ($title, $id) {
                return [
                    'course_id'    => $id,
                    'course_title' => $title,
                ];
            })
            ->values(); // convert associative map to indexed array

        // 6. Build and return structured response
        return [
            'user_login'    => $user->user_login,
            'user_nicename' => $user->user_nicename,
            'user_email'    => $user->user_email,
            'display_name'  => $user->display_name,
            'courses_taken' => $courses,
            'completed_courses' => $completedCourseTitles,
        ];
    }

    public function user_reg_info($id)
    {
        $users = DB::connection('wordpress')
            ->table('wc_customer_lookup')
            ->where('user_id', $id)
            ->get();

        return $users;

        //OR

        // $users = DB::connection('wordpress')
        //     ->table('users')
        //     ->where('id', $id)
        //     ->get();

        // return $users;
    }

    //USER PROGRESS PER COURSE

    public function user_progress_per_courses($userId)
    {
        $db = DB::connection('wordpress');

        $courseIds = $db->table('learndash_user_activity')
            ->where('user_id', $userId)
            ->where('activity_type', 'course')
            ->pluck('course_id')
            ->unique();

        $results = [];

        foreach ($courseIds as $courseId) {
            $courseTitle = $db->table('posts')
                ->where('ID', $courseId)
                ->value('post_title');

            // Fetch all parent lessons that have child topics/quizzes
            $excludedPostIds = $db->table('postmeta as cm')
                ->join('posts as cp', 'cp.ID', '=', 'cm.post_id')
                ->where('cm.meta_key', 'lesson_id')
                ->whereIn('cp.post_type', ['sfwd-topic', 'sfwd-quiz'])
                ->join('postmeta as parent', 'cm.meta_value', '=', 'parent.post_id')
                ->where('parent.meta_key', 'course_id')
                ->where('parent.meta_value', $courseId)
                ->pluck('parent.post_id');

            // Total count of valid content (lessons w/o children + topics + quizzes)
            $totalItems = $db->table('posts as p')
                ->join('postmeta as pm', 'p.ID', '=', 'pm.post_id')
                ->where('pm.meta_key', 'course_id')
                ->where('pm.meta_value', $courseId)
                ->where('p.post_status', 'publish')
                ->where(function ($query) use ($excludedPostIds) {
                    $query->whereIn('p.post_type', ['sfwd-topic', 'sfwd-quiz'])
                        ->orWhere(function ($q) use ($excludedPostIds) {
                            $q->where('p.post_type', 'sfwd-lessons')
                                ->whereNotIn('p.ID', $excludedPostIds);
                        });
                })
                ->groupBy('p.ID')
                ->select('p.ID')
                ->get()
                ->count();

            // Count of completed activity items
            $completedItems = $db->table('learndash_user_activity')
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->where('activity_status', 1)
                ->whereNotIn('activity_type', ['course', 'access'])
                ->whereNotIn('post_id', $excludedPostIds)
                ->count();

            $results[] = [
                'course_id' => $courseId,
                'course_title' => $courseTitle,
                'total_items' => $totalItems,
                'completed_items' => $completedItems,
                'progress_percent' => $totalItems > 0
                    ? round(($completedItems / $totalItems) * 100, 2)
                    : 0,
            ];
        }

        return response()->json($results);
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
