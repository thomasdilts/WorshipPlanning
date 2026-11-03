<?php

namespace App\Http\Controllers;

use App\Enums\ExpectedUserRange;
use App\Http\Requests\StoreChurchAccessRequest;
use App\Mail\ChurchAccessRequestMail;
use App\Support\CountryOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class ChurchAccessRequestController extends Controller
{
    public function create(): View
    {
        return view('auth.church-access-request', [
            'countries' => CountryOptions::all(),
            'expectedUserRanges' => ExpectedUserRange::options(),
        ]);
    }

    public function store(StoreChurchAccessRequest $request): RedirectResponse
    {
        $to = config('worshipplanning.church_access_request_to')
            ?: config('mail.from.address');

        abort_unless(filled($to), 503, __('Access request email is not configured.'));

        Mail::to($to)->send(new ChurchAccessRequestMail($request->validated()));

        return redirect()
            ->route('partner.create')
            ->with('status', __('Thanks — we received your request and will be in touch soon.'));
    }
}
