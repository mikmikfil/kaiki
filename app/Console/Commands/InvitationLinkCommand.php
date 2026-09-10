<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/**
 * Print the link that lets somebody set their password (testing only).
 *
 * ## Why this exists
 *
 * Invitations and password resets are emails, and a machine set up for testing
 * has `MAIL_MAILER=log` — so the link an operator needs is at the bottom of an
 * eight-megabyte log file, quoted-printable encoded across three lines. Getting
 * a colleague signed in became: create the account, open the log, find the last
 * `To:` line, decode it by hand. That is a step people get wrong, in a week
 * where the point is to test the product rather than the log format.
 *
 * It mints a **fresh** token rather than digging the old one out. Same broker,
 * same expiry, same single-use — a link this prints is exactly the link the
 * email carries, and re-running it invalidates the previous one, which is what
 * `Password::createToken()` does for a reset anybody requests twice.
 *
 * ## It refuses to run in production, and that is the whole safety argument
 *
 * A command that prints a working credential for **any account by email
 * address** is an account-takeover tool if it is ever reachable on a live
 * machine. There is no flag to override this: an operator who has lost their
 * password uses the reset form, which mails them rather than telling whoever
 * ran the command.
 *
 * The address is also printed back, because the whole point is to hand the
 * link to a person, and handing the right link to the wrong person is the
 * mistake this is meant to prevent rather than cause.
 */
class InvitationLinkCommand extends Command
{
    protected $signature = 'kaiki:invitation-link
                            {email : The address of the person who needs to set a password}';

    protected $description = 'Print a password-set link for a user (local and testing environments only)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refused: this prints a working credential and must never run in production.');
            $this->line('Somebody who has lost their password uses the reset form, which mails them.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error("No account with the address {$email}.");
            $this->line('Check the address on the operator you created — the owner\'s, not the billing one.');

            return self::FAILURE;
        }

        $token = Password::broker()->createToken($user);

        $url = URL::signedRoute('filament.app.auth.password-reset.reset', [
            'token' => $token,
            'email' => $user->email,
        ]);

        $this->newLine();
        $this->line("  <options=bold>{$user->name}</> — {$user->email}");
        $this->newLine();
        $this->line("  {$url}");
        $this->newLine();
        // The address in the link comes from `APP_URL`. On a machine other
        // people are about to open, a link that says `127.0.0.1` is a link that
        // works for nobody but the person who printed it — worth saying here
        // rather than after somebody has emailed it around.
        $this->comment('  The host comes from APP_URL. If that is still 127.0.0.1, nobody else can open this.');
        $this->newLine();

        return self::SUCCESS;
    }
}
