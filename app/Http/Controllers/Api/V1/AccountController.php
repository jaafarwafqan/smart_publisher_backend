<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = $request
            ->user()
            ->socialAccounts()
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Accounts loaded successfully.',
            'data' => AccountResource::collection($accounts),
        ]);
    }
}
