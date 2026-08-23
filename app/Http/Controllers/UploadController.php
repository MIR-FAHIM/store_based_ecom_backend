<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    private function isAdminUser($user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        $userType = strtolower((string) ($user->user_type ?? ''));

        return in_array('admin', [$role, $userType], true);
    }

    private function isSellerUser($user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));
        $userType = strtolower((string) ($user->user_type ?? ''));

        return in_array('seller', [$role, $userType], true);
    }

    private function visibleUploadQuery(Request $request)
    {
        $user = $request->attributes->get('api_user');
        $query = Upload::query();

        if ($this->isAdminUser($user)) {
            return $query;
        }

        if ($this->isSellerUser($user)) {
            return $query->where('seller_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Upload an image/file to `all` folder and create Upload record.
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'user_id' => 'nullable|integer|exists:users,id',
            'seller_id' => 'nullable|integer|exists:users,id',
        ]);

        $authUser = $request->attributes->get('api_user');
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $mime = $file->getClientMimeType();
        $size = $file->getSize();

        $filename = time() . '_' . Str::random(8) . '.' . $extension;
        $path = 'all/' . $filename;

        Storage::disk('public')->putFileAs('all', $file, $filename);

        $userId = $request->input('user_id') ?: $authUser?->id;
        $user = $userId ? User::find($userId) : null;
        $sellerId = null;

        if ($this->isSellerUser($authUser)) {
            $sellerId = $authUser->id;
        } elseif ($this->isAdminUser($authUser) && $request->filled('seller_id')) {
            $sellerId = (int) $request->input('seller_id');
        }

        $upload = Upload::create([
            'file_original_name' => $originalName,
            'file_name' => $path,
            'user_id' => $user ? $user->id : null,
            'seller_id' => $sellerId,
            'file_size' => $size,
            'extension' => $extension,
            'type' => $mime,
            'external_link' => null,
        ]);

        return response()->json(['success' => true, 'data' => $upload], 201);
    }

    /**
     * List uploads (paginated).
     */
    public function listUploads(Request $request)
    {
        $uploads = $this->visibleUploadQuery($request)
            ->orderBy('id', 'desc')
            ->paginate((int) $request->get('per_page', 20));

        return response()->json(['success' => true, 'data' => $uploads]);
    }

    /**
     * Get a single upload by id.
     */
    public function getUpload(Request $request, $id)
    {
        $upload = $this->visibleUploadQuery($request)->findOrFail($id);

        return response()->json(['success' => true, 'data' => $upload]);
    }

    /**
     * Delete an upload (soft-delete) and remove stored file if present.
     */
    public function deleteUpload(Request $request, $id)
    {
        $upload = $this->visibleUploadQuery($request)->findOrFail($id);

        if ($upload->file_name && Storage::disk('public')->exists($upload->file_name)) {
            Storage::disk('public')->delete($upload->file_name);
        }

        $upload->delete();

        return response()->json(['success' => true, 'message' => 'Upload deleted']);
    }
}
