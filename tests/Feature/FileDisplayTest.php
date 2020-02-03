<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\File;
use App\Models\FileCategory;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests the following cases
 *
 * As anonymous:
 * - all file routes (302 to login)
 *
 * As non-member user:
 * - all file routes (403)
 *
 * As member;
 * - File category list (200)
 * - Category detail on existing category (200)
 * - Category detail on non-existing category (404)
 * - File detail on existing file (200)
 * - File detail on non-existing file (404)
 * - Download on existing file (200)
 * - Download on existing file with missing attachment (404)
 * - Download on non-existing file (404)
 */
class FileDisplayTest extends TestCase
{
    /**
     * @var \App\Models\FileCategory
     */
    protected static $category;

    /**
     * @var \App\Models\File
     */
    protected static $file;

    /**
     * Create a file in a new category before the item starts
     * @return void
     * @before
     */
    public function createFileAndCategoryBefore(): void
    {
        // Make sure we have a router
        $this->ensureApplicationExists();

        // Create test category
        $name = sprintf('AA test %s', now()->toIso8601String());
        static::$category = factory(FileCategory::class, 1)
            ->create(['title' => $name])
            ->first();

        // Create test files in our test category
        static::$file = factory(File::class, 5)
            ->create(['category_id' => static::$category->id])
            ->first();

        // Reload items, just to be sure
        static::$category->refresh();
        static::$file->refresh();
    }

    /**
     * Delete the test items after testing
     * @return void
     * @after
     */
    public function deleteFileAndCategoryAfter(): void
    {
        // Skip if missing
        if (!static::$category || !static::$category->exists) {
            return;
        }

        // Ensure an app is available
        $this->ensureApplicationExists();

        // Delete files in category
        static::$category->files()->delete();

        // Delete category too
        static::$category->delete();
    }

    /**
     * Ensures there are some files and categories to work with
     * @return void
     */
    public function seedBefore(): void
    {
        $this->seed('FileSeeder');
    }

    /**
     * Test viewing as logged out user
     * @param string $route
     * @param bool $notFound
     * @return void
     * @dataProvider provideTestRoutes
     */
    public function testViewListAsAnonymous(string $route, bool $notFound)
    {
        // Request the index
        $response = $this->get($route);

        // Handle not found routes
        if ($notFound) {
            $response->assertNotFound();
            return;
        }

        // Expect a response redirecting to login
        $response->assertRedirect(route('login'));
    }

    /**
     * A basic feature test example.
     * @param string $route
     * @param bool $notFound
     * @return void
     * @dataProvider provideTestRoutes
     */
    public function testViewListAsGuest(string $route, bool $notFound)
    {
        // Get a guest user
        $user = $this->getGuestUser();

        // Request the index
        $response = $this->actingAs($user)
                    ->get($route);

        // Expect 404 on not found resources
        if ($notFound) {
            $response->assertNotFound();
            return;
        }

        // Expect 403 on private routes
        $response->assertForbidden();
    }

    /**
     * Test if we're seeing our first category when looking at the file index
     * @return void
     */
    public function testViewIndex()
    {
        $routes = $this->getTestRoutes();
        if (!array_key_exists('home', $routes)) {
            $this->markTestIncomplete('Cannot find [home] key in route list');
        }

        // Get a guest user
        $user = $this->getMemberUser();

        // Request the index
        $response = $this->actingAs($user)
                    ->get($routes['home']);

        // Expect an OK response
        $response->assertOk();

        // Check if we're seeing the first item (they're sorted A-Z)
        $firstModel = $this->getCategoryModel();
        $response->assertSeeText($firstModel->title);
    }

    /**
     * Test if we're seeing the right files when looking at an existing category.
     * @return void
     */
    public function testViewExistingCategory()
    {
        $routes = $this->getTestRoutes();
        if (!array_key_exists('category', $routes)) {
            $this->markTestIncomplete('Cannot find [category] key in route list');
        }

        // Get a guest user
        $user = $this->getMemberUser();

        // Request the index
        $response = $this->actingAs($user)
            ->get($routes['category']);

        // Expect an OK response
        $response->assertOk();

        $fileTitles = $this->getCategoryModel()->files()->take(5)->pluck('title');
        // Get the first 5 files of this category
        \assert($fileTitles instanceof Collection);

        // Can't check if there are no titles
        if (empty($fileTitles)) {
            return;
        }

        // Check if we're getting the files in the same order we're expecting them.
        $response->assertSeeTextInOrder($fileTitles->toArray());
    }

    /**
     * Test if we're getting a 404 when requesting a non-existing category
     * @return void
     */
    public function testViewNonExistingCategory()
    {
        $routes = $this->getTestRoutes();
        if (!array_key_exists('category-missing', $routes)) {
            $this->markTestIncomplete('Cannot find [category-missing] key in route list');
        }

        // Get a guest user
        $user = $this->getMemberUser();

        // Request the index
        $response = $this->actingAs($user)
            ->get($routes['category-missing']);

        // Expect an OK response
        $response->assertNotFound();
    }


    /**
     * Provide translated list of test routes
     * @return array
     */
    public function provideTestRoutes(): array
    {
        // Get all available routes and a list for the result
        $routes = $this->getTestRoutes();
        $result = [];

        // Check all routes for a possible rename
        foreach ($routes as $name => $route) {
            // Prep data
            $data = [$route, Str::endsWith($name, '-missing')];

            // Check for modifiers
            if (preg_match('/^([a-z]+)\-([a-z]+)$/i', $name, $matches)) {
                // Rename it to "<name> (<modifier>)"
                $result["{$matches[1]} ({$matches[2]})"] = $data;
                continue;
            }

            // Otherwise, just use the name
            $result[$name] = $data;
        }

        // Return the list
        return $result;
    }

    /**
     * Provides routes as a predictable list
     * @return array<string>
     */
    public function getTestRoutes(): array
    {
        // Make sure we have a router
        $this->ensureApplicationExists();

        // Return test cases
        return [
            // Homepage
            'home' => route('files.index'),

            // Categories
            'category' => route('files.category', [
                'category' => $this->getCategoryModel()
            ]),
            'category-missing' => route('files.category', [
                'category' => sprintf('test-category-%d', time())
            ]),

            // Files
            'file' => route('files.show', [
                'file' => $this->getFileModel()
            ]),
            'file-missing' => route('files.show', [
                'file' => sprintf('test-file-%d', time())
            ]),
        ];
    }

    /**
     * Returns most recent category
     * @return FileCategory|null
     */
    private function getCategoryModel(): ?FileCategory
    {
        // Return local category if set
        if (static::$category && static::$category->exists) {
            return static::$category;
        }

        // Make sure we have a database connection
        $this->ensureApplicationExists();

        // Return first auto-sorted category with files
        $withFiles = FileCategory::has('files')->first();
        if ($withFiles) {
            return $withFiles;
        }

        // Return first auto-sorted category
        return FileCategory::first();
    }

    /**
     * Returns most recent file
     * @return File|null
     */
    private function getFileModel(): File
    {
        // Return local file if set
        if (static::$file && static::$file->exists) {
            return static::$file;
        }

        // Make sure we have a database connection
        $this->ensureApplicationExists();

        // Return most recent file
        return $this->getCategoryModel()->files()->latest()->firstOrFail();
    }
}
