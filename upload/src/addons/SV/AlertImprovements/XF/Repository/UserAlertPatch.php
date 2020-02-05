<?php

namespace SV\AlertImprovements\XF\Repository;

use XF\Db\AbstractAdapter;
use XF\Db\AbstractStatement;

/**
 * Class UserAlert
 *
 * @package SV\AlertImprovements\XF\Repository
 */
class UserAlertPatch extends XFCP_UserAlertPatch
{
    protected $svBatchLimit = 100000;

    public function pruneReadAlerts($cutOff = null)
    {
        if ($cutOff === null)
        {
            $cutOff = \XF::$time - $this->options()->alertExpiryDays * 86400;
        }
        $limit = 100000;
        do
        {
            /** @var AbstractStatement $statement */
            $statement = $this->db()->executeTransaction(function (AbstractAdapter $db) use ($cutOff, $limit) {
                return $db->query("DELETE FROM xf_user_alert WHERE view_date > 0 AND view_date < ? LIMIT {$this->svBatchLimit}", $cutOff);
            }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
        }
        while ($statement && $statement->rowsAffected() >= $this->svBatchLimit);
    }

    public function pruneUnreadAlerts($cutOff = null)
    {
        if ($cutOff === null)
        {
            $cutOff = \XF::$time - 30 * 86400;
        }
        $limit = 100000;
        do
        {
            /** @var AbstractStatement $statement */
            $statement = $this->db()->executeTransaction(function (AbstractAdapter $db) use ($cutOff, $limit) {
                return $db->query("DELETE FROM xf_user_alert WHERE view_date = 0 AND event_date < ? LIMIT {$this->svBatchLimit}", $cutOff);
            }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
        }
        while ($statement && $statement->rowsAffected() >= $this->svBatchLimit);
    }
}