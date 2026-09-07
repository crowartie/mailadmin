<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Services\Mail\ImapSession;
use App\Services\Mail\MailStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function index(ImapSession $imap): JsonResponse
    {
        return response()->json((new MailStore($imap->client()))->folders());
    }

    public function store(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'parent' => ['nullable', 'string']]);
        $store = new MailStore($imap->client());
        $path = $store->createFolder($data['name'], $data['parent'] ?? null);

        return response()->json(['path' => $path, 'folders' => $store->folders()]);
    }

    public function update(Request $request, ImapSession $imap, string $folder): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $store = new MailStore($imap->client());
        $this->guardSystem($store, $folder);
        $path = $store->renameFolder($folder, $data['name']);

        return response()->json(['path' => $path, 'folders' => $store->folders()]);
    }

    public function destroy(ImapSession $imap, string $folder): JsonResponse
    {
        $store = new MailStore($imap->client());
        $this->guardSystem($store, $folder);
        $store->deleteFolder($folder);

        return response()->json(['folders' => $store->folders()]);
    }

    /** Очистить корзину или спам. */
    public function empty(ImapSession $imap, string $folder): JsonResponse
    {
        $store = new MailStore($imap->client());
        $role = collect($store->folders())->firstWhere('path', $folder)['role'] ?? 'custom';
        abort_unless(in_array($role, ['trash', 'spam']), 422, 'Очищать можно только корзину и спам');
        $store->emptyFolder($folder);

        return response()->json(['folders' => $store->folders()]);
    }

    private function guardSystem(MailStore $store, string $folder): void
    {
        $role = collect($store->folders())->firstWhere('path', $folder)['role'] ?? 'custom';
        abort_if($role !== 'custom', 422, 'Системную папку нельзя переименовать или удалить');
    }
}
