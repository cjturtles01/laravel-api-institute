<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\FixedRecipientMail;

class EmailController extends Controller
{
    public function send(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $content = $request->input('message');

        try {
            // Send to fixed address
            Mail::to('matantei.loki20@gmail.com')->send(new FixedRecipientMail($content));

            return response()->json(['message' => 'Email sent successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to send email', 'details' => $e->getMessage()], 500);
        }
    }
}
