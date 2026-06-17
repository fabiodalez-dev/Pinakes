<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Push;

/**
 * Immutable, transport-agnostic push payload.
 *
 * Built once by the dispatcher from a notification event, then handed to whatever
 * PushProvider is active. Strings are already in the instance locale (spec
 * §Localization); any date is ISO-8601 UTC so the app formats locally.
 *
 * The `type` mirrors the push-preference taxonomy / in-app feed taxonomy
 * (loan_due, loan_overdue, reservation_ready, new_message, book_available) so the
 * app can route/group a notification identically whether it arrives by push or by
 * polling GET /me/notifications.
 */
final class PushPayload
{
    public string $type;
    public string $title;
    public string $body;
    public ?int $bookId;
    /** @var array<string,scalar|null> */
    public array $data;

    /**
     * @param array<string,scalar|null> $data Extra structured fields (e.g. loan id,
     *                                         due date) the app may use for deep-linking.
     */
    public function __construct(string $type, string $title, string $body, ?int $bookId = null, array $data = [])
    {
        $this->type   = $type;
        $this->title  = $title;
        $this->body   = $body;
        $this->bookId = $bookId;
        $this->data   = $data;
    }

    /**
     * Canonical wire form. The same JSON object is used as the WebPush message
     * body and (mapped) as the FCM `data` block, so the app decodes one shape.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'type'    => $this->type,
            'title'   => $this->title,
            'body'    => $this->body,
            'book_id' => $this->bookId,
            'data'    => (object) $this->data,
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function toJson(): string
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }
}
