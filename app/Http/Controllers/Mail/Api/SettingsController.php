<?php

namespace App\Http\Controllers\Mail\Api;

use App\Http\Controllers\Controller;
use App\Models\Vmail\Mailbox;
use App\Models\Webmail\Label;
use App\Models\Webmail\Setting;
use App\Services\Mail\ImapSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /** Палитры схемы «Стекло» — те же 20, что в resources/js/mail/glass-palettes.json. */
    public const GLASS_PALETTES = ['sky', 'iris', 'graphite', 'copper', 'plum', 'petrol', 'carbon', 'midnight', 'obsidian', 'espresso', 'sage', 'eucalyptus', 'olive', 'pine', 'porcelain', 'terracotta', 'mulberry', 'cocoa', 'atlantic', 'slate'];

    public function show(ImapSession $imap): JsonResponse
    {
        return response()->json(Setting::for($imap->user()));
    }

    public function update(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'signature' => ['nullable', 'string', 'max:1200000'],   // с картинками (data:, до 400 КБ каждая)
            'signature_reply' => ['nullable', 'boolean'],
            'theme' => ['nullable', 'in:light,dark,system'],
            'scheme' => ['nullable', 'in:brand,classic,glass'],
            'glass_palette' => ['nullable', 'in:' . implode(',', self::GLASS_PALETTES)],
            'glass_motion' => ['nullable', 'in:expressive,calm,off'],
            'glass_wallpaper' => ['nullable', 'in:auto,none,architecture,arches,petals,linen,contours,orbit'],
            'glass_wallpaper_strength' => ['nullable', 'integer', 'min:0', 'max:30'],
            'glass_density' => ['nullable', 'integer', 'min:68', 'max:95'],
            'density' => ['nullable', 'in:roomy,normal,compact'],
            'reply_all' => ['nullable', 'boolean'],
            'notify_browser' => ['nullable', 'boolean'],
            'ask_rule_on_move' => ['nullable', 'boolean'],
            'row_actions' => ['nullable', 'boolean'],
            'after_remove' => ['nullable', 'in:list,next,prev'],
            'open_first' => ['nullable', 'boolean'],
            'undo_seconds' => ['nullable', 'integer', 'min:0', 'max:30'],
            'quick_replies' => ['nullable', 'array', 'max:8'],
            'quick_replies.*' => ['string', 'max:200'],
            'shortcuts' => ['nullable', 'boolean'],
            'ui_simple' => ['nullable', 'boolean'],
            'preview' => ['nullable', 'boolean'],
            'unread_highlight' => ['nullable', 'boolean'],
            'unread_color' => ['nullable', 'regex:/^(#[0-9a-fA-F]{6})?$/'],
            'show_images' => ['nullable', 'in:ask,always'],
        ]);

        return response()->json(Setting::save_($imap->user(), $data));
    }


    // ── Метки ──────────────────────────────────────────────────────────

    public function labels(ImapSession $imap): JsonResponse
    {
        return response()->json(Label::where('user', $imap->user())->orderBy('sort')->orderBy('id')->get(['id', 'name', 'color']));
    }

    public function storeLabel(Request $request, ImapSession $imap): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        abort_if(Label::where('user', $imap->user())->count() >= 30, 422, 'Слишком много меток');
        abort_if(Label::where('user', $imap->user())->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->exists(), 422, 'Метка с таким именем уже есть');
        $label = Label::create(['user' => $imap->user(), 'name' => $data['name'], 'color' => $data['color'] ?? '#2F6FEB']);

        return $this->labels($imap);
    }

    public function updateLabel(Request $request, ImapSession $imap, int $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        abort_if(Label::where('user', $imap->user())->where('id', '!=', $id)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->exists(), 422, 'Метка с таким именем уже есть');
        Label::where('user', $imap->user())->findOrFail($id)->update(array_filter($data));

        return $this->labels($imap);
    }

    public function destroyLabel(ImapSession $imap, int $id): JsonResponse
    {
        Label::where('user', $imap->user())->findOrFail($id)->delete();

        return $this->labels($imap);
    }
}
