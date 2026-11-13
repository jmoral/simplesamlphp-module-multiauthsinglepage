<?php

declare(strict_types=1);

namespace SimpleSAML\Module\multiauthsinglepage\Error;

use SimpleSAML\Error\ErrorCodes as BaseErrorCodes;
use SimpleSAML\Locale\Translate;
use SimpleSAML\Module\multiauthsinglepage\LoginThrottle;
use SimpleSAML\Module\multiauthsinglepage\RecaptchaVerifier;

/**
 * Registers this module's custom error codes (LoginThrottle::ERROR_CODE and
 * RecaptchaVerifier::ERROR_CODE) with SimpleSAMLphp's ErrorCodes title/description
 * maps.
 *
 * This matters beyond our own default template: any template rendering
 * `errorcodes['title'][errorcode]` generically -- including a theme's
 * overridden copy of multiauthonepage.twig that predates a given error code --
 * would otherwise get a Twig RuntimeError ("Key ... does not exist") instead
 * of a sensible message.
 */
class ErrorCodes extends BaseErrorCodes
{
    public function getCustomTitles(): array
    {
        return [
            LoginThrottle::ERROR_CODE => Translate::noop('Please wait a moment'),
            RecaptchaVerifier::ERROR_CODE => Translate::noop('We could not verify this attempt'),
        ];
    }


    public function getCustomDescriptions(): array
    {
        return [
            LoginThrottle::ERROR_CODE => Translate::noop(
                'We were not able to process this attempt right now. Please double-check that you are ' .
                'entering the correct username and password -- watch out for an accidental Caps Lock or ' .
                'the wrong keyboard layout -- and try again in a few seconds.',
            ),
            RecaptchaVerifier::ERROR_CODE => Translate::noop(
                'We could not confirm that this sign-in attempt was made by a person. Please sign in again.',
            ),
        ];
    }
}
