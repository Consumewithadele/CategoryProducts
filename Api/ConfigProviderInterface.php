<?php

declare(strict_types=1);

namespace Trespass\CategoryProducts\Api;

/**
 * @api
 */
interface ConfigProviderInterface
{
    /**
     * @return bool
     */
    public function getEnabledShowCategoryProductExport(): bool;
}
