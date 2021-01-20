<?php

namespace SV\AlertImprovements\Job;

use XF\Db\AbstractAdapter;
use XF\Job\AbstractJob;

class UnviewedAlertCleanup extends AbstractJob
{
    protected $defaultData = [
        'cutOff' => 0,
        'recordedUsers' => null,
        'pruned' => false,
    ];

    /**
     * @inheritDoc
     */
    public function run($maxRunTime): \XF\Job\JobResult
    {
        $cutOff = $this->data['cutOff'] ?? 0;
        $cutOff = (int)$cutOff;
        if (!$cutOff)
        {
            return $this->complete();
        }

        $startTime = \microtime(true);
        $db = \XF::db();

        if ($this->data['recordedUsers'] === null)
        {
            $db->executeTransaction(function() use ($db, $cutOff){
                $statement = $db->query('
                INSERT IGNORE INTO xf_sv_user_alert_rebuild (user_id, rebuild_date)
                SELECT DISTINCT alerted_user_id, ?
                FROM xf_user_alert 
                WHERE view_date = 0 AND event_date < ? AND alerted_user_id <> 0
            ', [\XF::$time, $cutOff]);

                $this->data['recordedUsers'] = $statement->rowsAffected() > 0;
            }, AbstractAdapter::ALLOW_DEADLOCK_RERUN);
            $this->saveIncrementalData();
        }

        if (!$this->data['recordedUsers'])
        {
            return $this->complete();
        }

        if (microtime(true) - $startTime >= $maxRunTime)
        {
            return $this->resume();
        }

        /** @var \SV\AlertImprovements\XF\Repository\UserAlertPatch|\SV\AlertImprovements\XF\Repository\UserAlert $alertRepo */
        $alertRepo = \XF::app()->repository('XF:UserAlert');

        if (empty($this->data['pruned']))
        {
            $continue = $alertRepo->pruneUnviewedAlertsBatch($cutOff, $startTime, $maxRunTime);
            if ($continue)
            {
                $resume = $this->resume();
                $resume->continueDate = \XF::$time = 1;

                return $resume;
            }

            $this->data['pruned'] = true;
            $this->saveIncrementalData();
        }

        if ($this->data['recordedUsers'])
        {
            \XF::app()->jobManager()->enqueueUnique('svAlertTotalRebuild', 'SV\AlertImprovements:AlertTotalRebuild', [
                'pendingRebuilds' => true,
            ], false);
        }

        return $this->complete();
    }

    public function getStatusMessage(): string
    {
        return '';
    }

    public function canCancel(): bool
    {
        return false;
    }

    public function canTriggerByChoice(): bool
    {
        return false;
    }
}
