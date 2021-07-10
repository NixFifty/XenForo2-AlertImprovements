<?php

namespace SV\AlertImprovements;

/**
 * NotifierExtender::extendNotifiers injects this into all notifiers for active add-ons
 */
trait NotifierPatchTrait
{
    protected function basicAlert(\XF\Entity\User $receiver, $senderId, $senderName, $contentType, $contentId, $action, array $extra = [], array $options = [])
    {
        if (!isset($options['autoRead']))
        {
            $options['autoRead'] = true;
        }

        /** @noinspection PhpUndefinedClassInspection */
        return parent::basicAlert($receiver, $senderId, $senderName, $contentType, $contentId, $action, $extra, $options);
    }
}