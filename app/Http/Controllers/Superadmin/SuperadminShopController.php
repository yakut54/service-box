<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperadminShopController extends Controller
{
    // GET /api/superadmin/shops
    public function index(Request $request)
    {
        $query = Shop::query()
            ->with('user:id,name,email,created_at')
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('domain', 'ilike', "%{$search}%");
            });
        }

        $shops = $query->paginate(25)->through(fn($s) => [
            'id'                     => $s->id,
            'name'                   => $s->name,
            'domain'                 => $s->domain,
            'created_at'             => $s->created_at,
            'user'                   => $s->user,
        ]);

        return response()->json($shops);
    }

    // GET /api/superadmin/shops/{id}
    public function show(string $id)
    {
        $shop = Shop::with('user:id,name,email,created_at')->findOrFail($id);

        return response()->json([
            'id'                   => $shop->id,
            'name'                 => $shop->name,
            'domain'               => $shop->domain,
            'created_at'           => $shop->created_at,
            'user'                 => $shop->user,
        ]);
    }

    // GET /api/superadmin/shops/{id}/cascade-debug
    public function cascadeDebug(string $id)
    {
        $shop = Shop::findOrFail($id);
        $s    = $shop->schema_name;

        $autoHiddenExists = DB::select("
            SELECT column_name FROM information_schema.columns
            WHERE table_schema = ? AND table_name = 'products' AND column_name = 'auto_hidden'
        ", [$s]);

        $masters = DB::select("SELECT id, name, is_active FROM \"{$s}\".masters ORDER BY name");

        $masterServices = DB::select("
            SELECT ms.master_id, ms.product_id, p.name as product_name, p.is_active, p.auto_hidden
            FROM \"{$s}\".master_services ms
            JOIN \"{$s}\".products p ON p.id = ms.product_id
            ORDER BY ms.master_id
        ");

        $productsNoMaster = DB::select("
            SELECT p.id, p.name, p.is_active
            FROM \"{$s}\".products p
            WHERE NOT EXISTS (
                SELECT 1 FROM \"{$s}\".master_services ms WHERE ms.product_id = p.id
            )
            AND p.is_active = TRUE
        ");

        return response()->json([
            'schema'              => $s,
            'auto_hidden_column'  => !empty($autoHiddenExists),
            'masters'             => $masters,
            'master_services'     => $masterServices,
            'products_no_master'  => $productsNoMaster,
        ]);
    }
}
