<?php

namespace SV\AlertImprovements;


use XF\AddOn\AddOn;
use XF\Entity\AddOn as AddOnEntity;

class Listener
{
    /** @noinspection PhpUnusedParameterInspection */
    public static function appSetup(\XF\App $app)
    {
        /** @var \SV\AlertImprovements\Repository\NotifierExtender $repo */
        $repo = \XF::repository('SV\AlertImprovements:NotifierExtender');
        $repo->extendNotifiers(false);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public static function addonPostRebuild(AddOn $addOn, AddOnEntity $installedAddOn, array $json)
    {
        /** @var \SV\AlertImprovements\Repository\NotifierExtender $repo */
        $repo = \XF::repository('SV\AlertImprovements:NotifierExtender');
        $repo->extendNotifiers(true);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public static function addonPostInstall(AddOn $addOn, AddOnEntity $installedAddOn, array $json, array &$stateChanges)
    {
        /** @var \SV\AlertImprovements\Repository\NotifierExtender $repo */
        $repo = \XF::repository('SV\AlertImprovements:NotifierExtender');
        $repo->extendNotifiers(true);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public static function addonPostUpgrade(AddOn $addOn, AddOnEntity $installedAddOn, array $json, array &$stateChanges)
    {
        /** @var \SV\AlertImprovements\Repository\NotifierExtender $repo */
        $repo = \XF::repository('SV\AlertImprovements:NotifierExtender');
        $repo->extendNotifiers(true);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public static function addonPostUninstall(AddOn $addOn, $addOnId, array $json)
    {
        /** @var \SV\AlertImprovements\Repository\NotifierExtender $repo */
        $repo = \XF::repository('SV\AlertImprovements:NotifierExtender');
        $repo->extendNotifiers(true);
    }
}