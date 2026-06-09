<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Interfaces;

interface PatchPackagesResolverInterface
{
    /**
     * @param array $patches
     * @param array $repositoryState
     * @return string[]
     */
    public function resolve(array $patches, array $repositoryState);
}
