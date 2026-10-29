<?php

namespace App\Http\Controllers;

use App\Mail\SupportMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SupportMessageController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:254'],
            'subject' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $recipient = config('mail.support_address');

        if (! is_string($recipient) || trim($recipient) === '') {
            Log::error('Support message could not be sent: support inbox is not configured.');

            return back()->withErrors([
                'support' => 'Support is temporarily unavailable. Please try again later.',
            ])->withInput();
        }

        try {
            Mail::to($recipient)->send(
                new SupportMessage(
                    senderName: $data['name'],
                    senderEmail: $data['email'],
                    supportSubject: $data['subject'],
                    messageBody: $data['message'],
                ),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors([
                'support' => 'We could not send your message. Please try again later.',
            ])->withInput();
        }

        return back()->with('success', true);
    }
}
