<?php

namespace App\Exceptions;

use RuntimeException;

class BranchLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $limit = null,
        public readonly ?int $used = null,
    ) {
        parent::__construct($message);
    }

    public static function limitReached(int $limit, int $used): self
    {
        return new self(
            "This client has reached its branch limit of {$limit}. "
            .'Ask your Super Admin to raise the limit before adding another branch.',
            $limit,
            $used
        );
    }

    public static function limitBelowUsage(int $newLimit, int $used): self
    {
        return new self(
            "The limit cannot be set to {$newLimit} because this client already has {$used} branches. "
            .'Delete a branch first, or set a limit of at least '.$used.'.',
            $newLimit,
            $used
        );
    }

    public static function businessInactive(): self
    {
        return new self('This client account is not active, so branches cannot be added.');
    }
}
