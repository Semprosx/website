<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\Str;
use App\Models\Activity;
use App\Models\Enrollment;
use App\Models\Page;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;

class PageController extends Controller
{
    private Repository $cache;

    public function __construct(Repository $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Renders the homepage
     * @return Response
     */
    public function homepage(Request $request)
    {
        $nextEvents = Activity::query()
            ->whereAvailable()
            ->where('start_date', '>', now())
            ->orderBy('start_date')
            ->take(3)
            ->get();

        $enrollments = [];
        if ($request->user() && $nextEvents) {
            $enrollments = Enrollment::query()
                ->whereUserId($request->user()->id)
                ->where('activity_id', 'in', $nextEvents->pluck('id'))
                ->orderBy('created_at', 'asc')
                ->get()
                ->keyBy('activity_id');
        }

        // Return view
        return response()
            ->view('content.home', compact('nextEvents', 'enrollments'));
    }

    /**
     * Handles fallback routes
     * @return Response
     */
    public function fallback(Request $request)
    {
        return $this->render(null, trim($request->path(), '/\\'));
    }

    /**
     * Group overview page
     * @param string $group
     * @return JsonResponse
     * @throws InvalidArgumentException
     */
    public function group(string $group)
    {
        $pages = Page::where(compact('group'))->get();
        $lastModified = $pages->max('updated_at');
        $page = Page::where([
            'group' => null,
            'slug' => $group
        ])->first();

        return response()
            ->view('content.group', compact('pages', 'page'))
            ->setLastModified($lastModified)
            ->setMaxAge(now()->addHours(6)->diffInSeconds())
            ->setSharedMaxAge(now()->addHour()->diffInSeconds())
            ->setPublic();
    }

    /**
     * Group detail page
     * @param string $group
     * @param string $slug
     * @return App\Http\Controllers\Response
     * @throws HttpResponseException
     */
    public function groupPage(string $group, string $slug)
    {
        return $this->render($group, $slug);
    }

    /**
     * Renders a single page, if possible
     * @param string $slug
     * @return Response
     */
    protected function render(?string $group, string $slug)
    {
        $safeSlug = Str::slug($slug);
        $cacheKey = sprintf('pages-cache.%s.%s', $group ?? 'default', $safeSlug);

        // Check cache
        $page = $this->cache->get($cacheKey);
        if (!$this->cache->has($cacheKey)) {
            // Create instance
            $page = null;

            // Check database
            if (!$page) {
                $page = Page::where([
                    'group' => $group,
                    'slug' => $safeSlug
                ])->first();

                if (!$page || empty($page->html)) {
                    $page = null;
                }
            }

            // 404 if still no results
            if (!$page) {
                $page = 404;
            }

            // Store in cache
            $this->cache->put($cacheKey, $page, now()->addHour());
        }

        // Allow caching 404
        if (is_scalar($cacheKey) && $cacheKey === 404) {
            abort($cacheKey);
        }

        // Show view
        return response()
            ->view('content.page', compact('page'))
            ->setLastModified($page->updated_at)
            ->setMaxAge(now()->addHours(6)->diffInSeconds())
            ->setSharedMaxAge(now()->addHour()->diffInSeconds())
            ->setPublic();
    }
}
