<?php

declare(strict_types=1);

namespace App\Interface;

/**
 * An exception whose failure is shown to the user: UserFacingExceptionListener
 * turns it into the editor's `{ error, state }` response, with the status set
 * by the exception's #[WithHttpStatus].
 */
interface UserFacingExceptionInterface extends \Throwable
{
    /** Full key in the exceptions translation domain. */
    public function getTranslationKey(): string;
}
