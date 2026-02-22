<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Get list of products
     *
     * GET /api/admin/products
     */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->with('category:id,name,slug');

        if ($request->has('type')) {
            $query->ofType($request->type);
        }

        if ($request->has('active')) {
            $active = filter_var($request->active, FILTER_VALIDATE_BOOLEAN);
            if ($active) {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }

        $query->orderBy('sort_order')->orderBy('name');
        $query->withAvg(['reviews as rating' => fn($q) => $q->where('is_published', true)], 'rating')
              ->withCount(['reviews as review_count' => fn($q) => $q->where('is_published', true)]);

        $products = $query->get()->map(function ($product) {
            $product->loadDetails();
            // withAvg returns numeric as string from PostgreSQL — cast to float
            $product->rating = $product->rating !== null ? (float) $product->rating : null;
            return $product;
        });

        return response()->json([
            'data'  => $products,
            'count' => $products->count(),
        ]);
    }

    /**
     * Store a new product
     *
     * POST /api/admin/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->only([
            'type',
            'name',
            'description',
            'price',
            'compare_price',
            'currency',
            'image_url',
            'is_active',
            'category_id',
            'sort_order',
        ]));

        $this->storeProductDetails($product, $request);

        $product->load('category:id,name,slug');
        $product->loadDetails();

        return response()->json([
            'message' => 'Product created successfully',
            'data'    => $product,
        ], 201);
    }

    /**
     * Get single product
     *
     * GET /api/admin/products/{product}
     */
    public function show(string $product): JsonResponse
    {
        $product = Product::with('category:id,name,slug')
                          ->withAvg(['reviews as rating' => fn($q) => $q->where('is_published', true)], 'rating')
                          ->withCount(['reviews as review_count' => fn($q) => $q->where('is_published', true)])
                          ->findOrFail($product);
        $product->loadDetails();
        $product->rating = $product->rating !== null ? (float) $product->rating : null;

        return response()->json([
            'data' => $product,
        ]);
    }

    /**
     * Update product
     *
     * PUT /api/admin/products/{product}
     */
    public function update(UpdateProductRequest $request, string $product): JsonResponse
    {
        $product = Product::findOrFail($product);

        $product->update($request->only([
            'type',
            'name',
            'description',
            'price',
            'compare_price',
            'currency',
            'image_url',
            'is_active',
            'category_id',
            'sort_order',
        ]));

        $this->updateProductDetails($product, $request);

        $product->refresh()->load('category:id,name,slug')->loadDetails();

        return response()->json([
            'message' => 'Product updated successfully',
            'data'    => $product,
        ]);
    }

    /**
     * Delete product
     *
     * DELETE /api/admin/products/{product}
     */
    public function destroy(string $product): JsonResponse
    {
        $product = Product::findOrFail($product);
        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }

    protected function storeProductDetails(Product $product, Request $request): void
    {
        if ($product->type === 'physical' && $request->has('physical')) {
            $product->physical()->create($request->physical);
        }

        if ($product->type === 'digital' && $request->has('digital')) {
            $product->digital()->create($request->digital);
        }

        if ($product->type === 'service' && $request->has('service')) {
            $product->service()->create($request->service);
        }
    }

    protected function updateProductDetails(Product $product, Request $request): void
    {
        if ($request->has('physical') && $product->type === 'physical') {
            if ($product->physical) {
                $product->physical->update($request->physical);
            } else {
                $product->physical()->create($request->physical);
            }
        }

        if ($request->has('digital') && $product->type === 'digital') {
            if ($product->digital) {
                $product->digital->update($request->digital);
            } else {
                $product->digital()->create($request->digital);
            }
        }

        if ($request->has('service') && $product->type === 'service') {
            if ($product->service) {
                $product->service->update($request->service);
            } else {
                $product->service()->create($request->service);
            }
        }
    }
}
