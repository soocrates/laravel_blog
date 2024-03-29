<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Image;
use App\Models\Navigation;
use App\Models\Shared_attributes;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BlogController extends Controller
{
    protected $fileLocation = 'blogs';

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $blogs = Blog::latest()->paginate(20);

        return view('admin.blog.index', compact('blogs'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $tags = Tag::all();
        $navigations = Navigation::all();
        $categories = Category::all();

        return view('admin.blog.create', compact('categories', 'navigations', 'tags'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $inputData = $request->all();
            $inputData['model'] = 'Blog';
            $validator = Validator::make($inputData, [
                'title' => 'required|max:255',
                'publish_at' => 'required|date',

                'status' => 'required|in:Draft,Published',
                'post_body' => 'required',
                'model' => 'required',
                'keyword' => 'required|string',
                'seo_title' => 'required|string',
                'meta_desc' => 'required|string',
                'summary' => 'required|string',

                'featured_image' => 'required|image|mimes:jpg,jpeg,png,gif|max:4096|dimensions:min_width=100,min_height=100,max_width=5000,max_height=5000',
                'thumbnail_image' => 'required|image|mimes:jpg,jpeg,png,gif|max:1024|dimensions:min_width=50,min_height=50,max_width=1000,max_height=1000',
                'image_caption' => 'required|string',

                'nav_bar_id' => 'required|integer|exists:navigations,id',
                'category_id' => 'required|array',
                'category_id.*' => 'integer|exists:categories,id',
                'tag_id' => 'required|array',
                'tag_id.*' => 'integer|exists:tags,id',

            ]);
            $validatedData = $validator->validated();
            // dd($validatedData);

            // Create a new instance for shared attributes
            $sharedAttributesData = $request->only(['status', 'post_body', 'keyword', 'seo_title', 'meta_desc', 'summary']);
            $sharedAttributesData['reading_time'] = calculateReadingTime($request->input('post_body'));
            $sharedAttributesData['model'] = 'Blog';
            $sharedAttributes = Shared_attributes::create($sharedAttributesData);

            $imageData = $request->only(['image_caption']);
            $imageData['model'] = 'Blog';
            if ($request->hasFile('featured_image')) {
                $imageData['featured_image'] = Storage::disk('public')->put($this->fileLocation, $request->file('featured_image'));
            }

            if ($request->hasFile('thumbnail_image')) {
                $imageData['thumbnail_image'] = Storage::disk('public')->put($this->fileLocation, $request->file('thumbnail_image'));
            }
            $image = Image::create($imageData);

            // Create a new blog post instance without shared attributes fields
            $blogData = Arr::except($validatedData, ['keyword', 'status', 'seo_title', 'post_body', 'meta_desc', 'summary', 'image_caption', 'featured_image', 'thumnail_image', 'tag_id', 'category_id']);
            $blogData['shared_attributes_id'] = $sharedAttributes->id;
            $blogData['image_id'] = $image->id;
            $blog = Blog::create($blogData);

            if (isset($validatedData['category_id'])) {
                $blog->category()->sync($validatedData['category_id']);
            }
            if (isset($validatedData['tag_id'])) {
                $blog->tags()->sync($validatedData['tag_id']);
            }
            if (isset($validatedData['nav_bar_id']) && isset($validatedData['category_id'])) {
                $navigation = Navigation::find($validatedData['nav_bar_id']);
                $categories = Category::whereIn('id', $validatedData['category_id'])->get();

                foreach ($categories as $category) {
                    // Check if the entry already exists for the same navigation and category
                    $existingEntry = $navigation->categories()
                        ->where('category_id', $category->id)
                        ->first();

                    if ($existingEntry) {
                        // Entry exists, increment the count
                        $existingEntry->pivot->increment('count');
                    } else {
                        // Entry does not exist, create a new one with a count of 1
                        $navigation->categories()->attach($category->id, ['count' => 1]);
                    }
                }
            }
            DB::commit();

            return redirect()->route('blog.index')->with('success', 'Blog post created successfully.');
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback the transaction on error

            // Handle the error, e.g., return an error response or redirect with an error message
            return redirect()->route('blog.create')->withErrors(['error' => 'Failed to create blog post: '.$e->getMessage()]);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $blog = Blog::with('images', 'sharedAttributes')->find($id);
        $navigations = Navigation::all();

        return view('admin.blog.edit', compact('blog', 'navigations'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        DB::beginTransaction();
        try {
            $blog = Blog::with('sharedAttributes', 'images')->findOrFail($id);

            // Validate the request data
            $validatedData = $request->validate([
                'title' => 'required|max:255',
                'publish_at' => 'required|date',
                'status' => 'required|in:Draft,Published',
                'post_body' => 'required',
                'keyword' => 'required|string',
                'seo_title' => 'required|string',
                'meta_desc' => 'required|string',
                'summary' => 'required|string',
                'featured_image' => 'sometimes|image|mimes:jpg,jpeg,png,gif|max:4096',
                'thumbnail_image' => 'sometimes|image|mimes:jpg,jpeg,png,gif|max:1024',
                'image_caption' => 'required|string',
            ]);

            // Update shared attributes
            $sharedAttributesData = $request->only(['status', 'post_body', 'keyword', 'seo_title', 'meta_desc', 'summary']);
            $sharedAttributesData['reading_time'] = calculateReadingTime($request->input('post_body'));
            $blog->sharedAttributes()->update($sharedAttributesData);

            $imageData = $request->only(['image_caption']);
            if ($request->hasFile('featured_image')) {
                if ($blog->images->featured_image) {
                    Storage::disk('public')->delete($blog->images->featured_image);
                }
                $imageData['featured_image'] = Storage::disk('public')->put($this->fileLocation, $request->file('featured_image'));
            }

            if ($request->hasFile('thumbnail_image')) {
                if ($blog->images->thumbnail_image) {
                    Storage::disk('public')->delete($blog->images->thumbnail_image);
                }
                $imageData['thumbnail_image'] = Storage::disk('public')->put($this->fileLocation, $request->file('thumbnail_image'));
            }

            $blog->images->update($imageData);

            $blogData = Arr::except($validatedData, ['post_body', 'status', 'keyword', 'seo_title', 'meta_desc', 'summary', 'image_caption', 'featured_image', 'thumbnail_image']);
            $blog->update($blogData);

            DB::commit();
            $notification = [
                'message' => 'blog Item Edited',
                'alert-type' => 'success',
            ];

            return redirect()->route('blog.index')->with($notification);
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback the transaction on error

            $notification = [
                'message' => 'Failed to Delete Blog: '.$e->getMessage(),
                'alert-type' => 'error',
            ];

            // Handle the error, e.g., return an error response or redirect with an error message
            return redirect()->back()->with($notification);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::beginTransaction();
        try {
            $blog = Blog::with('images', 'sharedAttributes', 'category')->findOrFail($id);

            // Delete associated images from storage and the database
            if ($blog->images) {
                if (Storage::disk('public')->exists($blog->images->featured_image)) {
                    Storage::disk('public')->delete($blog->images->featured_image);
                }

                if (Storage::disk('public')->exists($blog->images->thumbnail_image)) {
                    Storage::disk('public')->delete($blog->images->thumbnail_image);
                }

                $blog->images->delete();
            }

            // Handle navigation categories count
            if ($blog->nav_bar_id) {
                $navigation = Navigation::find($blog->nav_bar_id);
                foreach ($blog->category as $category) {
                    $pivotEntry = $navigation->categories()->where('category_id', $category->id)->first();

                    if ($pivotEntry && $pivotEntry->pivot->count > 1) {
                        // If count is more than 1, decrement it
                        $pivotEntry->pivot->decrement('count');
                    } elseif ($pivotEntry) {
                        // If count is 1, remove the pivot entry
                        $navigation->categories()->detach($category->id);
                    }
                }
            }

            if ($blog->sharedAttributes) {
                $blog->sharedAttributes->delete();
            }
            $blog->category()->detach();
            $blog->tags()->detach();

            // Finally, delete the blog
            $blog->delete();

            DB::commit();
            $notification = [
                'message' => 'blog Item Edited',
                'alert-type' => 'success',
            ];

            return redirect()->route('blog.index')->with($notification);
        } catch (\Exception $e) {
            DB::rollBack(); // Rollback the transaction on error

            $notification = [
                'message' => 'Failed to create category: '.$e->getMessage(),
                'alert-type' => 'error',
            ];

            return redirect()->back()->with($notification);
        }
    }
}
