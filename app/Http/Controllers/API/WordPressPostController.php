<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WordPressPost;
use App\Models\UsersModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function top_completed_courses()
    {
        try {
            $db = DB::connection('wordpress');
            $prefix = $db->getTablePrefix();

            // Get the course descriptions first
            $descriptionQuery = "
        SELECT 
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
            {$prefix}posts p
        JOIN (
            SELECT DISTINCT post_title
            FROM {$prefix}posts
            WHERE post_type = 'sfwd-courses'
              AND post_status = 'publish'
        ) AS published_courses ON p.post_title = published_courses.post_title
        WHERE 
            (
                p.post_content LIKE '%<h2>About this Course</h2>%<a%'
                OR p.post_content LIKE '%<h2>About this Program</h2>%<a%'
            )
        GROUP BY p.post_title;
    ";

            $descriptionResults = $db->select($descriptionQuery);

            // Create a mapping of course title to cleaned description
            $descriptionMap = [];
            foreach ($descriptionResults as $descRow) {
                $descriptionMap[$descRow->post_title] = trim(preg_replace('/[\t\n\r]+/', '', $descRow->course_description));
            }

            // Main query for enrollment and completion
            $results = $db->select("
        SELECT 
            p.ID AS course_id,
            p.post_title,
            p.post_name,
            COUNT(DISTINCT a.user_id) AS enrolled_users,
            COUNT(DISTINCT CASE WHEN a.activity_status = 1 THEN a.user_id END) AS completed_users
        FROM 
            {$prefix}posts p
        JOIN 
            {$prefix}learndash_user_activity a
            ON p.ID = a.course_id
        WHERE 
            a.activity_type = 'course'
            AND p.post_type = 'sfwd-courses'
            AND p.post_status = 'publish'
        GROUP BY 
            p.ID, p.post_title, p.post_name
        ORDER BY 
            completed_users DESC
    ");

            if (empty($results)) {
                Log::info("No course enrollments found.");
                return response()->json([
                    'status' => 'success',
                    'message' => 'No course enrollments found.',
                    'data' => []
                ], 200);
            }

            // Format the final output
            $formatted = array_map(function ($item) use ($descriptionMap) {
                return [
                    'course_id' => (int)$item->course_id,
                    'title' => $item->post_title,
                    'post_name' => $item->post_name,
                    'enrolled_users' => (int)$item->enrolled_users,
                    'completed_users' => (int)$item->completed_users,
                    // 'course_description' => $descriptionMap[$item->post_title] ?? 'No description available.'
                ];
            }, $results);

            return response()->json([
                'status' => 'success',
                'total_courses' => count($formatted),
                'data' => $formatted
            ], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Database query error in top_courses_by_enrollment: " . $e->getMessage());
            return response()->json([
                'error' => 'Database query error.',
                'exception_message' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error("Unexpected error in top_courses_by_enrollment: " . $e->getMessage());
            return response()->json([
                'error' => 'An unexpected error occurred.',
                'exception_message' => $e->getMessage()
            ], 500);
        }
    }


    //SIGN-UP USER INFORMATION
    public function sign_up_user_info($submission_id)
    {
        // Validate that submission_id is a positive integer
        if (!is_numeric($submission_id) || intval($submission_id) <= 0) {
            Log::warning('Invalid Submission ID received in sign_up_user_info', ['submission_id' => $submission_id]);
            return response()->json([
                'error' => 'Invalid Submission ID. It must be a positive number.',
                'user_id_received' => $submission_id
            ], 400);
        }

        try {
            // Fetch fields from the database
            $fields = DB::connection('wordpress')
                ->table('rm_submission_fields')
                ->where('submission_id', $submission_id)
                ->get();

            if ($fields->isEmpty()) {
                Log::info("No submission data found for ID: {$submission_id}");
                return response()->json([
                    'error' => 'No submission data found for the given ID.'
                ], 404);
            }

            // Map field_id to field names
            $mapping = [
                14 => 'email',
                19 => 'firstname',
                20 => 'lastname',
                17 => 'company_name',
                25 => 'country',
                21 => 'job_title',
                22 => 'industry',
                23 => 'professional_designation',
                30 => 'learner_category',
            ];

            $result = [];

            foreach ($fields as $field) {
                if (isset($mapping[$field->field_id])) {
                    $key = $mapping[$field->field_id];
                    $result[$key] = $field->value;
                }
            }

            if (empty($result)) {
                Log::notice("Submission found for ID {$submission_id} but no known fields matched.");
                return response()->json([
                    'error' => 'Submission found, but no known fields were matched.'
                ], 422);
            }

            Log::info("Successfully retrieved submission data for ID: {$submission_id}", $result);

            return response()->json([
                'submission_id' => (int) $submission_id,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error("Error retrieving submission data for ID {$submission_id}: " . $e->getMessage());
            return response()->json([
                'error' => 'Server error occurred while fetching submission data.',
                'details' => $e->getMessage()
            ], 500);
        }
    }



    //Course Description
    public function course_description()
    {
        try {
            $prefix = DB::connection('wordpress')->getTablePrefix(); // Dynamically get prefix

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
                {$prefix}posts p
            JOIN (
                SELECT DISTINCT post_title
                FROM {$prefix}posts
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

            if (empty($results)) {
                Log::info('No course descriptions found.');
                return response()->json([
                    'status' => 'success',
                    'message' => 'No course descriptions found in the content.',
                    'courses' => []
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'count' => count($results),
                'courses' => $results
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error in course_description API: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Server error occurred while retrieving course descriptions.',
                'details' => $e->getMessage()
            ], 500);
        }
    }


    //Number of users enrolled on courses
    public function enrolled_users()
    {
        try {
            $prefix = DB::connection('wordpress')->getTablePrefix(); // e.g., 'wpwx_'

            $results = DB::connection('wordpress')->select("
            SELECT 
                p.ID AS course_id,
                p.post_title,
                p.post_name,
                COUNT(DISTINCT a.user_id) AS enrolled_users
            FROM 
                {$prefix}posts p
            LEFT JOIN 
                {$prefix}learndash_user_activity a 
                ON p.ID = a.course_id 
                AND a.activity_type = 'course'
            INNER JOIN 
                {$prefix}users u 
                ON a.user_id = u.ID
            WHERE 
                p.post_type = 'sfwd-courses'
                AND p.post_status = 'publish'
            GROUP BY 
                p.ID, p.post_title, p.post_name
        ");

            if (empty($results)) {
                Log::info('No enrolled users found for any course.');
                return response()->json([
                    'status' => 'success',
                    'message' => 'No enrolled users found for any course.',
                    'data' => []
                ], 200);
            }

            $formatted = array_map(function ($item) {
                return [
                    'course_id' => (int)$item->course_id,
                    'title' => $item->post_title,
                    'post_name' => $item->post_name,
                    'enrolled_users' => (int)$item->enrolled_users,
                ];
            }, $results);

            return response()->json([
                'status' => 'success',
                'total_courses' => count($formatted),
                'data' => $formatted
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error fetching enrolled users: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while fetching enrolled user data.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    // Each Course with User Info
    public function course_user_info($userId)
    {

        // Validate userId input
        if (!is_numeric($userId) || intval($userId) <= 0) {
            Log::warning('Invalid User ID received in course_user_info', ['user_id' => $userId]);
            return response()->json([
                'error' => 'Invalid User ID provided.',
                'user_id_received' => $userId
            ], 400);
        }

        try {

            // Step 1: Get user details from wc_customer_lookup
            $userDetails = DB::connection('wordpress')
                ->table('wc_customer_lookup')
                ->where('user_id', $userId)
                ->get();

            if ($userDetails->isEmpty()) {
                Log::info('User not found in wc_customer_lookup', ['user_id' => $userId]);
                return response()->json([
                    'error' => 'User not found in wc_customer_lookup.',
                    'user_id' => $userId
                ], 404);
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
                Log::info('No courses found for user.', ['user_id' => $userId]);
                return response()->json([
                    'message' => 'No courses found for this user.',
                    'user_id' => $userId
                ], 200);
            }

            // Step 3: Get post titles from posts table using course IDs
            $postTitles = DB::connection('wordpress')
                ->table('posts')
                ->whereIn('ID', $courseIds)
                ->pluck('post_title', 'ID');

            if ($postTitles->isEmpty()) {
                Log::error('No matching post titles found for course IDs.', [
                    'user_id' => $userId,
                    'course_ids' => $courseIds
                ]);
                return response()->json([
                    'error' => 'No matching post titles found for course IDs.',
                    'course_ids' => $courseIds
                ], 404);
            }

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
        } catch (\Exception $e) {
            Log::error('Exception in course_user_info', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred while retrieving user course info.',
                'exception_message' => $e->getMessage()
            ], 500);
        }
    }


    // READ ALL COURSES CATALOG
    public function course_catalog()
    {
        try {
            $prefix = DB::connection('wordpress')->getTablePrefix();

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
                    REGEXP_REPLACE(post_content, '\\\\[[^\\\\]]*\\\\]', ''), 
                    '<[^>]*>', ''
                ) AS post_content
            FROM 
                {$prefix}posts
            WHERE 
                post_status = 'publish' 
                AND post_type = 'sfwd-courses'
        ");

            if (empty($results)) {
                Log::info('course_catalog(): No published courses found.');
                return response()->json([
                    'status' => 'success',
                    'message' => 'No published courses found.',
                    'data' => [],
                    'result_count' => 0
                ], 200);
            }

            Log::info('course_catalog(): Retrieved ' . count($results) . ' courses.');

            return response()->json([
                'status' => 'success',
                'result_count' => count($results),
                'data' => $results
            ], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Database query error in course_catalog(): ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Database query error.',
                'exception_message' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in course_catalog(): ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred while retrieving the course catalog.',
                'exception_message' => $e->getMessage()
            ], 500);
        }
    }



    // READ SINGLE USER & COURSE INFO
    public function user_course_info($id)
    {

        // Validate userId input
        if (!is_numeric($id) || intval($id) <= 0) {
            Log::warning('Invalid ID received in user_course_info', ['id' => $id]);
            return response()->json([
                'error' => 'Invalid ID provided.',
                'user_id_received' => $id
            ], 400);
        }

        try {
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

            if ($activityRecords->isEmpty()) {
                Log::warning("No course activity found for user_id: {$userId}");
                return response()->json([
                    'error' => 'No course activity records found for this user.'
                ], 404);
            }

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

            // 5. Fetch ALL completed course activities for the user
            $completedActivities = DB::connection('wordpress')
                ->table('learndash_user_activity')
                ->where('user_id', $userId)
                ->where('activity_type', 'course')
                ->where('activity_status', 1) // ✅ This filters all completed
                ->get();

            // 6. Extract ALL post_ids from those activities
            $completedCoursePostIds = $completedActivities->pluck('post_id')->unique()->values();

            // 7. Fetch ALL matching course titles from wpwz_posts
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

            // 8. Build and return structured response
            return [
                'user_login'    => $user->user_login,
                'user_nicename' => $user->user_nicename,
                'user_email'    => $user->user_email,
                'display_name'  => $user->display_name,
                'courses_taken' => $courses,
                'completed_courses' => $completedCourseTitles,
            ];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error("User not found: ID {$id}", ['exception' => $e]);
            return response()->json([
                'error' => 'User not found.',
                'exception_message' => $e->getMessage()
            ], 404);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Query error in user_course_info: user ID {$id}", ['exception' => $e]);
            return response()->json([
                'error' => 'Database query error.',
                'exception_message' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error("Unexpected error in user_course_info for user ID {$id}", ['exception' => $e]);
            return response()->json([
                'error' => 'An unexpected error occurred.',
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }


    public function user_reg_info($id)
    {

        // Validate userId input
        if (!is_numeric($id) || intval($id) <= 0) {
            Log::warning('Invalid ID received in user_reg_info', ['id' => $id]);
            return response()->json([
                'error' => 'Invalid ID provided.',
                'id' => $id
            ], 400);
        }



        try {
            $users = DB::connection('wordpress')
                ->table('wc_customer_lookup')
                ->where('user_id', $id)
                ->get();

            if ($users->isEmpty()) {
                Log::warning("User registration info not found for user_id: {$id}");
                return response()->json(['error' => 'User not found.'], 404);
            }

            return $users;

            // OR
            // $users = DB::connection('wordpress')
            //     ->table('users')
            //     ->where('id', $id)
            //     ->get();
            // return $users;

        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Database query error for user_reg_info with user_id: {$id}. Error: " . $e->getMessage());
            return response()->json([
                'error' => 'Database query error.',
                'exception_message' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error("Unexpected error in user_reg_info with user_id: {$id}. Error: " . $e->getMessage());
            return response()->json([
                'error' => 'An unexpected error occurred.',
                'exception_message' => $e->getMessage()
            ], 500);
        }
    }


    //USER PROGRESS PER COURSE

    public function user_progress_per_courses($userId)
    {
        // Validate userId input
        if (!is_numeric($userId) || intval($userId) <= 0) {
            Log::warning('Invalid User ID received in user_progress_per_courses', ['userId' => $userId]);
            return response()->json([
                'error' => 'Invalid user ID provided.',
                'user_id_received' => $userId
            ], 400);
        }

        try {
            $db = DB::connection('wordpress');
            $prefix = DB::connection('wordpress')->getTablePrefix(); // Dynamically get prefix

            // STEP 1: Get course IDs
            $courseIds = $db->table('learndash_user_activity')
                ->where('user_id', $userId)
                ->where('activity_type', 'course')
                ->pluck('course_id')
                ->unique();

            if ($courseIds->isEmpty()) {
                return response()->json([
                    'message' => 'No course activity found for the user.',
                    'user_id' => $userId
                ], 404);
            }

            // STEP 2: Get course descriptions
            $descriptions = $db->select("
        SELECT 
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
        FROM {$prefix}posts p
        JOIN (
            SELECT DISTINCT post_title
            FROM {$prefix}posts
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

            $descriptionMap = [];
            foreach ($descriptions as $desc) {
                $descriptionMap[$desc->post_title] = $desc->course_description;
            }

            // STEP 3: Get activity data for all courses
            $activityData = $db->select("
        SELECT 
            a.course_id,
            a.activity_status,
            a.activity_updated,
            FROM_UNIXTIME(a.activity_updated) AS last_updated_date,
            stats.total_completions,
            stats.avg_completion_time_seconds,
            stats.avg_completion_time_hours
        FROM {$prefix}learndash_user_activity a
        LEFT JOIN (
            SELECT 
                course_id,
                COUNT(user_id) AS total_completions,
                AVG(activity_completed - activity_started) AS avg_completion_time_seconds,
                ROUND(AVG((activity_completed - activity_started) / 3600), 2) AS avg_completion_time_hours
            FROM {$prefix}learndash_user_activity
            WHERE 
                activity_type = 'course'
                AND activity_status = 1
                AND activity_started IS NOT NULL
                AND activity_completed IS NOT NULL
                AND activity_completed > activity_started
            GROUP BY course_id
        ) AS stats ON a.course_id = stats.course_id
        WHERE 
            a.user_id = ?
            AND a.activity_type = 'course'
    ", [$userId]);

            // Map activity data by course_id
            $activityMap = [];
            foreach ($activityData as $activity) {
                $activityMap[$activity->course_id] = $activity;
            }

            $results = [];

            foreach ($courseIds as $courseId) {
                $course = $db->table('posts')
                    ->where('ID', $courseId)
                    ->select('post_title', 'post_name')
                    ->first();

                $courseTitle = $course->post_title ?? 'Unknown';
                $courseSlug = $course->post_name ?? 'unknown-slug';
                $courseLink = "https://staging-institute.iixglobal.com/courses/$courseSlug/";

                $excludedPostIds = $db->table('postmeta as cm')
                    ->join('posts as cp', 'cp.ID', '=', 'cm.post_id')
                    ->where('cm.meta_key', 'lesson_id')
                    ->whereIn('cp.post_type', ['sfwd-topic', 'sfwd-quiz'])
                    ->join('postmeta as parent', 'cm.meta_value', '=', 'parent.post_id')
                    ->where('parent.meta_key', 'course_id')
                    ->where('parent.meta_value', $courseId)
                    ->pluck('parent.post_id');

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

                $completedItems = $db->table('learndash_user_activity')
                    ->where('user_id', $userId)
                    ->where('course_id', $courseId)
                    ->where('activity_status', 1)
                    ->whereNotIn('activity_type', ['course', 'access'])
                    ->whereNotIn('post_id', $excludedPostIds)
                    ->count();

                $activity = $activityMap[$courseId] ?? null;

                $results[] = [
                    'course_name' => $courseSlug,
                    'course_title' => $courseTitle,
                    'course_link' => $courseLink,
                    'progress_percent' => $totalItems > 0 ? round(($completedItems / $totalItems) * 100, 2) : 0,
                    'course_description' => isset($descriptionMap[$courseTitle])
                        ? trim(preg_replace('/[\t\n\r]+/', '', $descriptionMap[$courseTitle]))
                        : 'No description available.',
                    'activity_updated' => $activity ? $activity->activity_updated : null,
                    'last_updated_date' => $activity ? $activity->last_updated_date : null,
                    'total_completions' => $activity ? $activity->total_completions : 0,
                    'avg_completion_time_seconds' => $activity ? $activity->avg_completion_time_seconds : 0,
                    'avg_completion_time_hours' => $activity ? $activity->avg_completion_time_hours : 0,
                ];
            }

            return response()->json($results);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('DB error in user_progress_per_courses: ' . $e->getMessage(), [
                'user_id' => $userId,
            ]);
            return response()->json([
                'error' => 'Database query error.',
                'message' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in user_progress_per_courses: ' . $e->getMessage(), [
                'user_id' => $userId,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Unexpected server error.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    // #################################################################################################################################

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
