<?php

namespace App\Mail;

use App\Enums\ExpectedUserRange;
use App\Support\CountryOptions;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChurchAccessRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{
     *     church_name: string,
     *     street_address: string,
     *     city: string,
     *     postal_code: string,
     *     country: string,
     *     admin_name: string,
     *     admin_position: string,
     *     website?: ?string,
     *     phone: string,
     *     email: string,
     *     expected_users: string,
     *     required_languages: string,
     *     special_requests: string
     * }  $payload
     */
    public function __construct(public array $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Church access request').': '.$this->payload['church_name'],
            replyTo: [$this->payload['email']],
        );
    }

    public function content(): Content
    {
        $expected = ExpectedUserRange::tryFrom($this->payload['expected_users']);

        return new Content(
            markdown: 'mail.church-access-request',
            with: [
                'churchName' => $this->payload['church_name'],
                'streetAddress' => $this->payload['street_address'],
                'city' => $this->payload['city'],
                'postalCode' => $this->payload['postal_code'],
                'country' => CountryOptions::label($this->payload['country']),
                'adminName' => $this->payload['admin_name'],
                'adminPosition' => $this->payload['admin_position'],
                'website' => $this->payload['website'] ?? null,
                'phone' => $this->payload['phone'],
                'email' => $this->payload['email'],
                'expectedUsers' => $expected?->label() ?? $this->payload['expected_users'],
                'requiredLanguages' => $this->payload['required_languages'],
                'specialRequests' => $this->payload['special_requests'] ?: '—',
            ],
        );
    }
}
