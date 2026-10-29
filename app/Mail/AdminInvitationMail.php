<?php

namespace App\Mail;

use App\Models\AdminInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $acceptUrl;

    public function __construct(
        public AdminInvitation $invitation,
        public string $plaintextToken,
    ) {
        $this->acceptUrl = url('/admin/invitations/accept/'.$this->plaintextToken);
    }

    public function envelope(): Envelope
    {
        $roleName = $this->invitation->role === 'super_admin' ? 'Super Administrator' : 'Administrator';

        return new Envelope(
            subject: "Invitation to join VinCheck UK as {$roleName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    protected function buildHtml(): string
    {
        $roleLabel = $this->invitation->role === 'super_admin' ? 'Super Administrator' : 'Administrator';
        $expires = $this->invitation->expires_at->toFormattedDateString();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VinCheck Administrator Invitation</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 40px 20px;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
        <tr>
            <td style="background-color: #000d2f; padding: 28px 36px; text-align: left;">
                <span style="display: inline-block; background-color: #bb001a; color: #ffffff; font-weight: bold; font-size: 14px; padding: 6px 12px; border-radius: 6px; letter-spacing: 1px;">VINCHECK</span>
                <h1 style="color: #ffffff; font-size: 20px; margin: 16px 0 4px 0; font-weight: 700;">Administrator Invitation</h1>
                <p style="color: rgba(255,255,255,0.7); font-size: 12px; margin: 0; text-transform: uppercase; letter-spacing: 0.5px;">Operational Console Access</p>
            </td>
        </tr>
        <tr>
            <td style="padding: 36px;">
                <p style="font-size: 15px; color: #1e293b; line-height: 1.6; margin-top: 0;">
                    Hello <strong>{$this->invitation->name}</strong>,
                </p>
                <p style="font-size: 14px; color: #475569; line-height: 1.6;">
                    You have been formally invited to join the VinCheck UK management console as a <strong>{$roleLabel}</strong>.
                </p>
                <p style="font-size: 14px; color: #475569; line-height: 1.6;">
                    To complete your registration, confirm your administrative account, and set your access credentials, please use the secure button below:
                </p>
                <div style="text-align: center; margin: 32px 0;">
                    <a href="{$this->acceptUrl}" style="background-color: #00205b; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-size: 14px; font-weight: 600; display: inline-block; box-shadow: 0 2px 6px rgba(0,32,91,0.25);">
                        Accept Invitation & Set Password
                    </a>
                </div>
                <div style="background-color: #f8fafc; border-left: 4px solid #0284c7; padding: 14px 18px; border-radius: 4px; margin: 24px 0;">
                    <p style="font-size: 12px; color: #0369a1; margin: 0; line-height: 1.5;">
                        <strong>Security Notice:</strong> This invitation is bound to your email address and will expire on <strong>{$expires}</strong> (7 days). If you did not expect this invitation, you may safely ignore this email.
                    </p>
                </div>
                <p style="font-size: 12px; color: #94a3b8; line-height: 1.5; margin-top: 24px;">
                    If the button does not work, copy and paste this URL into your browser:<br>
                    <a href="{$this->acceptUrl}" style="color: #0284c7; word-break: break-all;">{$this->acceptUrl}</a>
                </p>
            </td>
        </tr>
        <tr>
            <td style="background-color: #f1f5f9; padding: 18px 36px; text-align: center; font-size: 11px; color: #64748b;">
                &copy; VinCheck UK Operational Console &bull; Strictly Confidential &amp; Bound by Security Policies
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}
