<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use App\Contracts\MyDataGateway;
use App\Domain\Compliance\Data\MyDataResult;
use App\Models\Invoice;

/**
 * An AADE that answers however a test needs it to.
 *
 * The platform has no AADE credentials and will not have them for a while, so
 * every assertion about issuing, retrying and giving up has to be made against
 * something. This is that something — and it is the reason
 * {@see MyDataGateway} exists as an interface rather than as a class the job
 * news up.
 *
 * It records what it was asked to send, so a test can assert **the invoice
 * carried its number before the call**, which is MYD-4.2's whole point and is
 * invisible from the result.
 */
final class FakeMyDataGateway implements MyDataGateway
{
    /** @var list<array{invoice_id: int, number: int|null, status: string}> */
    public array $submissions = [];

    /** @var list<MyDataResult> */
    private array $answers = [];

    public function __construct(private readonly string $environment = 'dev') {}

    /** Answer with this, once per call, in order. The last one repeats. */
    public function willAnswer(MyDataResult ...$results): self
    {
        $this->answers = array_merge($this->answers, $results);

        return $this;
    }

    public function submit(Invoice $invoice): MyDataResult
    {
        $this->submissions[] = [
            'invoice_id' => (int) $invoice->getKey(),
            'number' => $invoice->number,
            'status' => $invoice->status->value,
        ];

        $index = count($this->submissions) - 1;

        return $this->answers[$index]
            ?? ($this->answers === [] ? MyDataResult::accepted('400000000000001') : end($this->answers));
    }

    public function environment(): string
    {
        return $this->environment;
    }
}
